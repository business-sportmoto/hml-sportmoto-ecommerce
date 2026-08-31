<?php
declare(strict_types=1);

/**
 * app/controllers/ChatWebhookController.php
 *
 * Endpoint público do webhook da WhatsApp Cloud API.
 *
 * Rotas:
 *   GET  /webhooks/whatsapp  → handshake de verificação (a Meta chama 1x no cadastro)
 *   POST /webhooks/whatsapp  → recebimento de mensagens e status
 *
 * Sem auth e sem CSRF — quem chama é a Meta. A autenticidade vem da assinatura
 * HMAC no header X-Hub-Signature-256, conferida contra o corpo BRUTO.
 *
 * POLÍTICA DE RESPOSTA — 200 em praticamente tudo:
 *   A Meta reenvia o evento em qualquer resposta fora da faixa 2xx, com backoff
 *   crescente, por até 7 dias. Um bug nosso devolvendo 500 viraria uma
 *   tempestade de retentativas e mensagem repetida no WhatsApp do cliente.
 *   Erro interno é registrado em chat_webhook_log, não devolvido como status.
 *   A única exceção é assinatura inválida → 403, que é o comportamento correto
 *   para uma chamada que não é da Meta.
 */
class ChatWebhookController extends Controller
{
    /**
     * GET /webhooks/whatsapp
     *
     * Handshake: a Meta manda hub.mode=subscribe + hub.verify_token e espera
     * o hub.challenge de volta como texto puro. Qualquer coisa diferente disso
     * (JSON, HTML, espaço extra) faz a verificação falhar no painel.
     */
    public function verificar(): void
    {
        $modo      = (string)($_GET['hub_mode']         ?? $_GET['hub.mode']         ?? '');
        $token     = (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
        $challenge = (string)($_GET['hub_challenge']    ?? $_GET['hub.challenge']    ?? '');

        $esperado = ChatMetaClient::verifyToken();

        if ($modo === 'subscribe' && $esperado !== '' && hash_equals($esperado, $token)) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(200);
            echo $challenge;
            exit;
        }

        $this->log('warning', 'chat: verificação de webhook recusada', [
            'modo'          => $modo,
            'token_bate'    => $esperado !== '' && hash_equals($esperado, $token),
            'token_definido' => $esperado !== '',
            'ip'            => $this->ip(),
        ]);

        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

    /**
     * POST /webhooks/whatsapp
     */
    public function receber(): void
    {
        // Corpo BRUTO, antes de qualquer parsing: o HMAC é sobre estes bytes.
        // Reserializar o JSON muda a saída e invalida a assinatura.
        $corpo = file_get_contents('php://input') ?: '';

        $assinatura = $_SERVER['HTTP_X_HUB_SIGNATURE_256']
                   ?? $_SERVER['HTTP_X_HUB_SIGNATURE']
                   ?? null;

        try {
            $r = (new ChatWebhookService())->processar($corpo, $assinatura, $this->ip());

            if (!$r['ok'] && ($r['detalhe'] ?? '') === 'assinatura inválida') {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'erro' => 'assinatura inválida']);
                exit;
            }

            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'processadas' => $r['processadas'] ?? 0]);
            exit;
        } catch (Throwable $e) {
            // 200 de propósito — ver a política no cabeçalho do arquivo
            $this->log('error', 'chat: exceção no webhook', [
                'erro'    => $e->getMessage(),
                'arquivo' => $e->getFile() . ':' . $e->getLine(),
            ]);

            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'erro' => 'registrado']);
            exit;
        }
    }

    private function ip(): string
    {
        if (class_exists('SecurityHelper') && method_exists('SecurityHelper', 'clientIp')) {
            try { return SecurityHelper::clientIp(); } catch (Throwable $e) {}
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private function log(string $nivel, string $msg, array $ctx = []): void
    {
        if (!class_exists('LogService')) return;
        try { LogService::$nivel($msg, $ctx, 'chat'); } catch (Throwable $e) {}
    }
}
