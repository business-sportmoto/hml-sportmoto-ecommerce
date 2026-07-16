<?php
/**
 * IAProviderBase — base dos adapters de provedor.
 *
 * Centraliza o cliente HTTP (cURL) com as lições dos gateways:
 *  - corpo vazio / 204 nunca passa por json_decode;
 *  - erro de rede vira status 0 com mensagem do cURL;
 *  - timeout por chamada (o do modelo prevalece sobre o do provedor).
 *
 * Capacidades não implementadas devolvem falha retryable — o orquestrador
 * registra "pulado" e segue para o próximo modelo.
 */
abstract class IAProviderBase
{
    protected string $apiKey;
    protected string $baseUrl;
    protected array $configExtra;

    public function __construct(string $apiKey, string $baseUrl, array $configExtra = [])
    {
        $this->apiKey      = $apiKey;
        $this->baseUrl     = rtrim($baseUrl, '/');
        $this->configExtra = $configExtra;
    }

    /** Identificador do provedor (openai, replicate...). */
    abstract public function codigo(): string;

    /** Chamada leve para validar chave/conectividade. */
    abstract public function testarConexao(): IAResultado;

    /**
     * Geração de texto. $job:
     *  prompt (string), instrucoes (?string), max_tokens (?int),
     *  modelo_codigo (string), timeout_s (int), params (array)
     */
    public function gerarTexto(array $job): IAResultado
    {
        return IAResultado::falha('nao_suportado', 'Capacidade texto não suportada por ' . $this->codigo() . '.', true);
    }

    /**
     * Geração de imagem. $job:
     *  prompt (string), proporcao ('1:1'|'3:2'|'2:3'), modelo_codigo (string),
     *  timeout_s (int), params (array)
     * Pode devolver sucessoImagem() (síncrono) ou pendente() (assíncrono).
     */
    public function gerarImagem(array $job): IAResultado
    {
        return IAResultado::falha('nao_suportado', 'Capacidade imagem não suportada por ' . $this->codigo() . '.', true);
    }

    /* ------------------------------------------------------------------ */
    /* Download binário (URLs de entrega — expiram; baixar IMEDIATAMENTE)  */
    /* ------------------------------------------------------------------ */

    /**
     * GET binário sem cabeçalhos de auth (URLs de entrega são assinadas).
     * Retorna ['ok'=>bool, 'binario'=>?string, 'mime'=>?string, 'extensao'=>?string, 'erro'=>?string]
     */
    protected function httpBinario(string $url, int $timeoutS = 60): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(10, $timeoutS),
        ]);

        $binario = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $mime    = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $erro    = curl_error($ch);
        curl_close($ch);

        if ($binario === false || $status !== 200 || $binario === '') {
            return ['ok' => false, 'binario' => null, 'mime' => null, 'extensao' => null,
                    'erro' => $erro !== '' ? $erro : ('HTTP ' . $status)];
        }

        $mime = strtolower(trim(explode(';', $mime)[0] ?? ''));
        $mapa = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

        return [
            'ok'       => true,
            'binario'  => $binario,
            'mime'     => $mime !== '' ? $mime : 'image/png',
            'extensao' => $mapa[$mime] ?? 'png',
            'erro'     => null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* HTTP                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Requisição JSON. Retorna:
     *  ['status'=>int, 'corpo'=>?array, 'corpo_bruto'=>string, 'erro'=>?string, 'tempo_ms'=>int]
     */
    protected function httpJson(string $metodo, string $caminho, ?array $payload = null, int $timeoutS = 60): array
    {
        $url    = (stripos($caminho, 'http') === 0) ? $caminho : $this->baseUrl . $caminho;
        $inicio = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($metodo),
            CURLOPT_HTTPHEADER     => $this->cabecalhos(),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(5, $timeoutS),
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $corpoBruto = curl_exec($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erroCurl   = curl_error($ch);
        curl_close($ch);

        $tempoMs = (int) round((microtime(true) - $inicio) * 1000);

        if ($corpoBruto === false) {
            return ['status' => 0, 'corpo' => null, 'corpo_bruto' => '', 'erro' => $erroCurl ?: 'falha de rede', 'tempo_ms' => $tempoMs];
        }

        $corpo = null;
        if (is_string($corpoBruto) && trim($corpoBruto) !== '') {
            $dec = json_decode($corpoBruto, true);
            if (is_array($dec)) {
                $corpo = $dec;
            }
        }

        return ['status' => $status, 'corpo' => $corpo, 'corpo_bruto' => (string) $corpoBruto, 'erro' => null, 'tempo_ms' => $tempoMs];
    }

    /** Cabeçalhos padrão — Bearer serve para OpenAI e Replicate. */
    protected function cabecalhos(): array
    {
        return [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /** Extrai mensagem de erro comum de respostas {error:{message,code|type}}. */
    protected function extrairErro(?array $corpo, int $status): array
    {
        $codigo   = 'http_' . $status;
        $mensagem = 'HTTP ' . $status;

        if (is_array($corpo)) {
            $erro = $corpo['error'] ?? $corpo['detail'] ?? null;
            if (is_array($erro)) {
                $mensagem = (string) ($erro['message'] ?? $mensagem);
                $codigo   = (string) ($erro['code'] ?? $erro['type'] ?? $codigo); // cast — pode vir int
            } elseif (is_string($erro) && $erro !== '') {
                $mensagem = $erro;
            }
        }

        return [$codigo, $mensagem];
    }
}
