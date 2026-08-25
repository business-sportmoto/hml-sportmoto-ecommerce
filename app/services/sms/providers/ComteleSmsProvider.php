<?php
/**
 * app/services/sms/providers/ComteleSmsProvider.php
 *
 * Adapter para o SMS Gateway da Comtele (API v2).
 *
 * Contrato usado aqui (confira contra o painel da sua conta antes de ir
 * para produção — endpoints de gateway mudam sem aviso):
 *
 *   POST https://sms.comtele.com.br/api/v2/send
 *   Header:  auth-key: <COMTELE_API_KEY>
 *            Content-Type: application/json
 *   Body:    {"Sender":"...", "Receivers":"5551999998888", "Content":"..."}
 *   Resposta:{"Success":true, "Object":"<id>", "Message":null}
 *
 * `Sender` é opcional e só vale se o remetente estiver homologado na
 * conta; sem homologação a Comtele usa o remetente padrão dela.
 *
 * CREDENCIAIS (.env):
 *   COMTELE_API_KEY   — obrigatória
 *   COMTELE_SENDER    — opcional
 *   COMTELE_TIMEOUT   — opcional (segundos, padrão 12)
 */
class ComteleSmsProvider implements SmsProviderInterface
{
    private const ENDPOINT = 'https://sms.comtele.com.br/api/v2/send';

    private string $apiKey;
    private string $sender;
    private int    $timeout;

    public function __construct(string $apiKey, string $sender = '', int $timeout = 12)
    {
        $this->apiKey  = trim($apiKey);
        $this->sender  = trim($sender);
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

        $body = [
            'Receivers' => $numero,
            'Content'   => $mensagem,
        ];
        if ($this->sender !== '') {
            $body['Sender'] = $this->sender;
        }

        $json = json_encode($body, JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'auth-key: ' . $this->apiKey,
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

        $dados = json_decode((string) $resp, true);
        if (!is_array($dados)) {
            return SmsSendResult::fail(
                'resposta não-JSON (HTTP ' . $http . ')',
                $http >= 500,
                $http >= 400 && $http < 500,
                ['http' => $http, 'body' => mb_substr((string) $resp, 0, 300)]
            );
        }

        if ($http >= 200 && $http < 300 && !empty($dados['Success'])) {
            $id = $dados['Object'] ?? null;
            return SmsSendResult::ok(
                is_scalar($id) ? (string) $id : null,
                ['http' => $http]
            );
        }

        // 401/403 = chave errada, 402 = sem saldo: reenviar não resolve.
        // 429 e 5xx passam, porque uma nova tentativa pode funcionar.
        $permanente = in_array($http, [400, 401, 402, 403, 404], true);

        return SmsSendResult::fail(
            (string) ($dados['Message'] ?? 'falha no envio') . ' (HTTP ' . $http . ')',
            !$permanente,
            $permanente,
            ['http' => $http]
        );
    }
}
