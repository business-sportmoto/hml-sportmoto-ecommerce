<?php
declare(strict_types=1);

/**
 * app/services/SyscarClient.php
 *
 * Fala com o webhook de recebimento do Syscar. Só I/O — regra e estado ficam
 * no EstoquePonteService.
 *
 * ── O que este client NÃO repete do admin.loja ───────────────────────
 *
 *  · Token fixo no código e na query string. Query string fica no log de
 *    acesso de qualquer proxy. Aqui vem do ambiente, em cabeçalho.
 *  · Verificação TLS desligada (SSL_VERIFYPEER/VERIFYHOST em 0). Aqui fica
 *    ligada: a chamada carrega credencial.
 *  · "Deu certo até prova em contrário". Lá, falha de rede devolvia o texto
 *    do curl_error(), que não era JSON, não tinha a chave `erro` e por isso
 *    virava sucesso — a baixa sumia e ninguém sabia. Aqui só é sucesso o que
 *    volta reconhecido: HTTP 2xx e JSON sem `erro`. Timeout, corpo vazio,
 *    HTML e texto solto são FALHA.
 *
 * Configuração (nomes, nunca valores — os valores vivem no .env / Vault):
 *    SYSCAR_WEBHOOK_URL
 *    SYSCAR_WEBHOOK_TOKEN
 * Com fallback para `configuracoes` (chaves estoque_syscar_url / _token),
 * para o painel poder operar sem deploy.
 */
class SyscarClient
{
    private const TIMEOUT          = 15;
    private const CONNECT_TIMEOUT  = 5;

    private string $url;
    private string $token;

    public function __construct(?string $url = null, ?string $token = null)
    {
        $this->url   = $url   ?? $this->doAmbiente('SYSCAR_WEBHOOK_URL',   'estoque_syscar_url');
        $this->token = $token ?? $this->doAmbiente('SYSCAR_WEBHOOK_TOKEN', 'estoque_syscar_token');
    }

    /** true quando dá para enviar — a tela mostra isso antes de alguém ligar a perna. */
    public function configurado(): bool
    {
        return $this->url !== '' && $this->token !== '';
    }

    /**
     * Envia um movimento de estoque ao Syscar.
     *
     * O payload segue o contrato que o Syscar já recebe hoje do admin.loja:
     * id (SKU), data, cliente, tipo, movimento COM SINAL, local, valor e
     * protocolo. O `protocolo` é o que permite ao Syscar deduplicar do lado
     * dele — e é a mesma chave que este lado usa.
     *
     * @return array{ok:bool, msg:string, http:int, bruto:mixed}
     */
    public function enviarMovimento(array $mov): array
    {
        if (!$this->configurado()) {
            return ['ok' => false, 'msg' => 'Syscar não configurado (URL/token ausentes).',
                    'http' => 0, 'bruto' => null];
        }

        $quantidade = (float)$mov['quantidade'];
        $payload = [
            'id'        => (string)$mov['sku_codigo'],
            'data'      => date('Y-m-d H:i:s'),
            'tipo'      => $this->descricao($mov),
            // Saída vai negativa; entrada e balanço, positivos.
            'movimento' => $mov['operacao'] === 'S' ? -abs($quantidade) : abs($quantidade),
            'local'     => 1,
            'valor'     => (float)($mov['valor'] ?? 0),
            'protocolo' => (string)$mov['protocolo'],
        ];

        return $this->post($payload);
    }

    private function descricao(array $mov): string
    {
        $base = $mov['operacao'] === 'E' ? 'Estorno E-commerce' : 'E-commerce';
        if (!empty($mov['canal_nome'])) $base .= '|' . $mov['canal_nome'];
        if (!empty($mov['pedido_bling_id'])) $base .= '|' . $mov['pedido_bling_id'];
        return mb_substr($base, 0, 100);
    }

    /**
     * POST com TLS verificado e timeout curto. Nada de ob_start(): o
     * admin.loja usa CURLOPT_RETURNTRANSFER false e captura por buffer de
     * saída, e quando o curl falha ele retorna antes do ob_end_clean() —
     * deixando o buffer aberto.
     */
    private function post(array $payload): array
    {
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Sportmoto-Token: ' . $this->token,
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $corpo = curl_exec($ch);
        $erro  = curl_error($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($corpo === false || $erro !== '') {
            return ['ok' => false, 'msg' => 'Falha de conexão: ' . ($erro ?: 'sem resposta'),
                    'http' => $http, 'bruto' => null];
        }

        if ($http < 200 || $http >= 300) {
            return ['ok' => false, 'msg' => 'HTTP ' . $http,
                    'http' => $http, 'bruto' => mb_substr((string)$corpo, 0, 500)];
        }

        $json = json_decode((string)$corpo, true);

        // Corpo que não é JSON não é sucesso. É exatamente o caso que o
        // admin.loja classificava como "insert" e fazia a baixa sumir.
        if (!is_array($json)) {
            return ['ok' => false, 'msg' => 'Resposta não é JSON.',
                    'http' => $http, 'bruto' => mb_substr((string)$corpo, 0, 500)];
        }

        if (!empty($json['erro'])) {
            return ['ok' => false, 'msg' => (string)$json['erro'],
                    'http' => $http, 'bruto' => $json];
        }

        return ['ok' => true, 'msg' => 'ok', 'http' => $http, 'bruto' => $json];
    }

    /** Ambiente primeiro; `configuracoes` como alternativa operável. */
    private function doAmbiente(string $envVar, string $chaveConfig): string
    {
        $v = getenv($envVar);
        if ($v !== false && trim((string)$v) !== '') return trim((string)$v);
        if (!empty($_ENV[$envVar])) return trim((string)$_ENV[$envVar]);

        try {
            $stmt = Database::getInstance()->getConnection()->prepare(
                "SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1"
            );
            $stmt->execute([$chaveConfig]);
            $v = $stmt->fetchColumn();
            return $v !== false ? trim((string)$v) : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
