<?php
/**
 * Base compartilhada por todas as transportadoras.
 *
 * Concentra o que é comum a qualquer integração:
 *  - guarda a linha de log_transportadoras e a config (credenciais);
 *  - normaliza status crus da transportadora -> estados internos;
 *  - registra toda ida-e-volta em log_comunicacoes (auditoria).
 *
 * Cada adapter concreto só implementa a conversa HTTP específica.
 */
abstract class TransportadoraBase implements TransportadoraInterface
{
    /** @var array Linha de log_transportadoras (id, slug, cep_origem, ...). */
    protected array $transportadora;

    /** @var array Config decodificada (credenciais/parâmetros do adapter). */
    protected array $config;

    public function __construct(array $transportadora)
    {
        $this->transportadora = $transportadora;
        $cfg = $transportadora['config'] ?? null;
        $this->config = is_array($cfg) ? $cfg : (json_decode((string)$cfg, true) ?: []);
    }

    public function slug(): string
    {
        return (string)($this->transportadora['slug'] ?? 'desconhecida');
    }

    protected function transportadoraId(): ?int
    {
        return isset($this->transportadora['id']) ? (int)$this->transportadora['id'] : null;
    }

    protected function cepOrigem(): string
    {
        return (string)($this->transportadora['cep_origem'] ?? '');
    }

    /**
     * Mapa canônico status cru -> estado interno. Cada transportadora pode
     * sobrescrever mapearStatus() para casos específicos, mas o dicionário
     * base já cobre os termos mais comuns do mercado brasileiro.
     */
    protected const MAPA_STATUS = [
        'postado'            => 'postado',
        'objeto postado'     => 'postado',
        'coletado'           => 'postado',
        'em transito'        => 'em_transito',
        'em trânsito'        => 'em_transito',
        'em transferencia'   => 'em_transito',
        'encaminhado'        => 'em_transito',
        'saiu para entrega'  => 'saiu_entrega',
        'em rota de entrega' => 'saiu_entrega',
        'entregue'           => 'entregue',
        'entrega efetuada'   => 'entregue',
        'devolvido'          => 'devolucao',
        'em devolucao'       => 'devolucao',
        'devolução'          => 'devolucao',
        'tentativa'          => 'ocorrencia',
        'destinatario ausente' => 'ocorrencia',
        'endereco incorreto' => 'ocorrencia',
        'avaria'             => 'ocorrencia',
        'extraviado'         => 'ocorrencia',
    ];

    /** Normaliza um status textual da transportadora para um estado interno. */
    public function mapearStatus(string $statusCru): string
    {
        $chave = mb_strtolower(trim($statusCru));
        // remove acentos para casar tanto "trânsito" quanto "transito"
        $semAcento = $this->semAcentos($chave);
        foreach (self::MAPA_STATUS as $termo => $interno) {
            if ($chave === $termo || $semAcento === $this->semAcentos($termo)) {
                return $interno;
            }
        }
        // heurística de fallback por palavra-chave
        if (str_contains($semAcento, 'entreg'))  return 'entregue';
        if (str_contains($semAcento, 'entrega')) return 'saiu_entrega';
        if (str_contains($semAcento, 'transit')) return 'em_transito';
        if (str_contains($semAcento, 'postad'))  return 'postado';
        if (str_contains($semAcento, 'devolv'))  return 'devolucao';
        return 'ocorrencia';
    }

    protected function semAcentos(string $s): string
    {
        return strtr($s, [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i',
            'ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c',
        ]);
    }

    /**
     * Aplica a margem comercial (desconto/acréscimo) configurada na
     * transportadora sobre o custo real. Retorna o valor a exibir.
     * As REGRAS de frete (Fase 3) atuam depois disto, no CotacaoService.
     */
    protected function aplicarMargem(float $custo): float
    {
        $tipo = $this->transportadora['margem_tipo'] ?? 'nenhum';
        $pct  = (float)($this->transportadora['margem_percentual'] ?? 0);
        $val  = (float)($this->transportadora['margem_valor'] ?? 0);
        $sinal = $tipo === 'desconto' ? -1 : ($tipo === 'acrescimo' ? 1 : 0);
        if ($sinal === 0) return $custo;
        $ajuste = ($custo * $pct / 100) + $val;
        return max(0, round($custo + ($sinal * $ajuste), 2));
    }

    /**
     * Registra a comunicação em log_comunicacoes. Nunca deixa o log
     * derrubar o fluxo principal (try/catch total — padrão do projeto).
     */
    protected function logComunicacao(
        string $tipo,
        array $requisicao,
        array $resposta,
        bool $sucesso,
        ?int $statusHttp = null,
        ?int $duracaoMs = null,
        ?int $referenciaId = null
    ): void {
        try {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare(
                "INSERT INTO log_comunicacoes
                    (transportadora_id, tipo, referencia_id, requisicao, resposta, status_http, sucesso, duracao_ms)
                 VALUES (:tid, :tipo, :ref, :req, :res, :http, :ok, :dur)"
            );
            $stmt->execute([
                ':tid'  => $this->transportadoraId(),
                ':tipo' => $tipo,
                ':ref'  => $referenciaId,
                ':req'  => json_encode($this->redigir($requisicao), JSON_UNESCAPED_UNICODE),
                ':res'  => json_encode($resposta, JSON_UNESCAPED_UNICODE),
                ':http' => $statusHttp,
                ':ok'   => $sucesso ? 1 : 0,
                ':dur'  => $duracaoMs,
            ]);
        } catch (\Throwable $e) {
            // Silencioso por design; LogService (arquivo) é a rede de segurança.
            if (class_exists('LogService')) {
                LogService::warning('Falha ao gravar log_comunicacoes -> '.$tipo, ['erro' => $e->getMessage()]);
            }
        }
    }

    /** Remove credenciais antes de persistir a requisição no log. */
    protected function redigir(array $dados): array
    {
        $sensiveis = ['token', 'password', 'senha', 'secret', 'authorization', 'api_key', 'chave'];
        array_walk_recursive($dados, function (&$v, $k) use ($sensiveis) {
            if (is_string($k) && in_array(mb_strtolower($k), $sensiveis, true)) {
                $v = '***';
            }
        });
        return $dados;
    }

    /**
     * Requisição HTTP compartilhada pelos adapters reais.
     * Não lança: devolve sempre um array normalizado, para o adapter
     * decidir o ['ok'=>...]. Mede latência (ms) para o log de comunicação.
     *
     * @param array|string|null $corpo array => JSON; string => enviado cru; null => sem corpo
     * @return array{status:int, body:string, json:?array, ms:int, erro:?string}
     */
    protected function requisicaoHttp(
        string $metodo,
        string $url,
        $corpo = null,
        array $headers = [],
        int $timeout = 25
    ): array {
        $t0 = microtime(true);
        $ch = curl_init();

        $payload = null;
        if ($corpo !== null) {
            $payload = is_array($corpo) ? json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $corpo;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($metodo),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $payload,
        ]);

        $body = curl_exec($ch);
        $erro = $body === false ? curl_error($ch) : null;
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ms = (int)round((microtime(true) - $t0) * 1000);
        $json = null;
        if (is_string($body) && $body !== '') {
            $dec = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json = $dec;
            }
        }

        return [
            'status' => $http,
            'body'   => is_string($body) ? $body : '',
            'json'   => $json,
            'ms'     => $ms,
            'erro'   => $erro,
        ];
    }
}
