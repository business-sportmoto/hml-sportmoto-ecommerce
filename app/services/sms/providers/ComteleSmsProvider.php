<?php
/**
 * app/services/sms/providers/ComteleSmsProvider.php
 *
 * Adapter para a API de SMS da Comtele.
 *
 * Contrato (developers.comtele.com.br — verificado contra a API real):
 *
 *   POST https://api.comtele.com.br/messages/sms/send
 *   Header:  x-api-key: <COMTELE_API_KEY>
 *            Content-Type: application/json
 *   Body:    {
 *              "receivers": ["5551999998888"],   // ARRAY, não string
 *              "contactGroups": [],
 *              "message": "...",
 *              "route": 17,                      // obrigatório
 *              "tag": "...", "custom": "..."
 *            }
 *   Sucesso: {"hasError":false,"message":null,"totalRecords":1,"errors":[],"object":null}
 *   Erro:    {"hasError":true,"message":"<motivo>","errors":[...]}
 *
 * ATENÇÃO — existe um endpoint ANTIGO (sms.comtele.com.br/api/v2/send, header
 * `auth-key`) que ainda responde. Ele NÃO aceita as chaves emitidas hoje:
 * devolve 401 "A chave de acesso informada é inválida", o que faz parecer
 * problema de credencial quando é de endpoint. Se voltar 401, confira a URL
 * antes de desconfiar da chave.
 *
 * ROTA: cada conta tem as suas (GET https://api.comtele.com.br/routes).
 * Rota de marketing está sujeita a filtragem e regras de opt-out — código de
 * login precisa de rota transacional/premium, senão o SMS atrasa ou não chega.
 *
 * CREDENCIAIS (.env):
 *   COMTELE_API_KEY   — obrigatória
 *   COMTELE_ROUTE     — id da rota (padrão 17)
 *   COMTELE_TIMEOUT   — segundos (padrão 12)
 */
class ComteleSmsProvider implements SmsProviderInterface
{
    private const ENDPOINT = 'https://api.comtele.com.br/messages/sms/send';

    /** Rota transacional. Ver GET /routes para as da conta. */
    private const ROTA_PADRAO = 17;

    private string $apiKey;
    private int    $rota;
    private int    $timeout;

    public function __construct(string $apiKey, int $rota = 0, int $timeout = 12)
    {
        $this->apiKey = trim($apiKey);
        $this->rota   = $rota > 0 ? $rota : self::ROTA_PADRAO;
        // Timeout curto de propósito: isto roda DENTRO do pedido de login.
        // Um gateway lento não pode segurar a tela do cliente.
        $this->timeout = max(3, min($timeout, 30));
    }

    public function nome(): string
    {
        return 'comtele';
    }

    public function configurado(): bool
    {
        return $this->apiKey !== '';
    }

    public function send(string $numero, string $mensagem): SmsSendResult
    {
        if (!$this->configurado()) {
            return SmsSendResult::fail('COMTELE_API_KEY ausente', false, true);
        }

        $json = json_encode([
            'receivers'     => [$numero],
            'contactGroups' => [],
            'message'       => $mensagem,
            'route'         => $this->rota,
            // Aparecem nos relatórios da Comtele: permitem separar o
            // tráfego de autenticação do resto sem abrir cada mensagem.
            'tag'           => 'auth-2fa',
            'custom'        => 'sportmoto',
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $resp    = curl_exec($ch);
        $http    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        // Falha de rede/DNS/timeout — sempre temporária.
        if ($resp === false) {
            return SmsSendResult::fail('rede: ' . ($curlErr ?: 'sem resposta'), true, false);
        }

        $dados      = json_decode((string) $resp, true);
        $permanente = $http >= 400 && $http < 500;

        // Nem toda resposta é JSON (o endpoint antigo devolve text/plain, e o
        // novo já devolveu 500 com um blob opaco). O texto costuma ser a
        // explicação exata — é mensagem do gateway, não dado do cliente.
        if (!is_array($dados)) {
            $texto = trim(preg_replace('/\s+/', ' ', strip_tags((string) $resp)) ?? '');
            $texto = mb_substr($texto, 0, 200);

            return SmsSendResult::fail(
                ($texto !== '' ? $texto : 'resposta vazia') . ' (HTTP ' . $http . ')',
                !$permanente,
                $permanente,
                ['http' => $http, 'body' => $texto]
            );
        }

        // Sucesso exige HTTP 2xx E hasError === false. A Comtele responde 200
        // com hasError=true em erro de validação, então checar só o status
        // daria mensagem por entregue sem ter saído.
        if ($http >= 200 && $http < 300 && empty($dados['hasError'])) {
            // NÃO validar por `totalRecords`: a Comtele devolve 0 mesmo no
            // sucesso ("A mensagem foi enviada para processamento com
            // sucesso e logo será entregue."). Tratá-lo como contador fazia
            // o adapter reportar falha DEPOIS de a mensagem já ter saído —
            // o pior dos dois mundos, porque o cliente recebe o SMS e a tela
            // diz que não deu certo.
            //
            // `hasError` é o único sinal confiável. Note que o envio é
            // assíncrono: o "ok" aqui significa aceito para processamento,
            // não entregue no aparelho.
            return SmsSendResult::ok(null, [
                'http'      => $http,
                'aceite'    => mb_substr((string) ($dados['message'] ?? ''), 0, 160),
            ]);
        }

        $motivo = (string) ($dados['message'] ?? '');
        if ($motivo === '' && !empty($dados['errors']) && is_array($dados['errors'])) {
            $motivo = implode('; ', array_map(
                static fn($e): string => is_scalar($e) ? (string) $e : json_encode($e, JSON_UNESCAPED_UNICODE),
                array_slice($dados['errors'], 0, 3)
            ));
        }

        return SmsSendResult::fail(
            mb_substr($motivo !== '' ? $motivo : 'falha no envio', 0, 200) . ' (HTTP ' . $http . ')',
            !$permanente,
            $permanente,
            ['http' => $http]
        );
    }
}
