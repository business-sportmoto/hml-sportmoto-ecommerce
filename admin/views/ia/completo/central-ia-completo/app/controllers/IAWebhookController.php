<?php
/**
 * IAWebhookController — endpoint PÚBLICO de webhooks da Central de Marketing IA.
 *
 * Rota (router público da loja, fora do admin — sem sessão, sem CSRF):
 *   Router::post('/webhooks/ia/replicate', 'IAWebhookController@replicate');
 *
 * Segurança:
 *  - Assinatura svix validada quando houver secret em
 *    ia_provedores(replicate).config_extra JSON: {"webhook_secret":"whsec_..."}
 *    (pegue em replicate.com → Account → Webhooks → Signing secret).
 *  - Sem secret configurado: aceita com LogService::warning — a varredura do
 *    worker é a rede de segurança e o processamento é idempotente de qualquer
 *    forma (guard por status em IAGeracaoService::processarRetornoProvedor).
 *  - Janela anti-replay de 5 minutos no timestamp.
 *
 * O webhook é OTIMIZAÇÃO de latência: se nunca for configurado, o módulo
 * funciona 100% pela varredura (é o modo do Laragon/dev).
 */
class IAWebhookController extends Controller
{
    private const TOLERANCIA_SEGUNDOS = 300;

    public function replicate(): void
    {
        $corpoBruto = (string) file_get_contents('php://input');

        $payload = json_decode($corpoBruto, true);
        if (!is_array($payload) || empty($payload['id'])) {
            $this->responder(400, 'payload invalido');
            return;
        }

        $secret = $this->secretDoProvedor('replicate');

        if ($secret !== null && $secret !== '') {
            $okAssinatura = self::validarAssinaturaSvix(
                $secret,
                (string) ($_SERVER['HTTP_WEBHOOK_ID'] ?? ''),
                (string) ($_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? ''),
                $corpoBruto,
                (string) ($_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '')
            );
            if (!$okAssinatura) {
                LogService::warning('ia_webhook_assinatura_invalida', [
                    'provedor' => 'replicate',
                    'ref'      => (string) $payload['id'],
                ]);
                $this->responder(401, 'assinatura invalida');
                return;
            }
        } else {
            LogService::warning('ia_webhook_sem_secret', ['provedor' => 'replicate']);
        }

        $geracao = (new IAGeracao())->buscarPorExternalId((string) $payload['id']);
        if ($geracao === null) {
            // Prediction que não é nossa (ou geração já limpa) — 200 para o provedor não reenviar.
            $this->responder(200, 'ignorado');
            return;
        }

        $adapter = (new IAOrchestrator())->adapterPorCodigo('replicate');
        if (!$adapter instanceof ReplicateAdapter) {
            LogService::error('ia_webhook_sem_adapter', ['ref' => (string) $payload['id']]);
            $this->responder(200, 'sem adapter'); // varredura resolve
            return;
        }

        try {
            $desfecho = (new IAGeracaoService())->processarRetornoProvedor($geracao, $payload, $adapter);
            LogService::info('ia_webhook_processado', [
                'geracao_id' => (int) $geracao['id'],
                'ref'        => (string) $payload['id'],
                'desfecho'   => $desfecho,
            ]);
        } catch (Throwable $e) {
            LogService::error('ia_webhook_erro', [
                'geracao_id' => (int) $geracao['id'],
                'erro'       => $e->getMessage(),
            ]);
            // 200 mesmo assim: reprocesso fica com a varredura (idempotente).
        }

        $this->responder(200, 'ok');
    }

    /* ------------------------------------------------------------------ */
    /* Assinatura svix (padrão usado pelo Replicate)                       */
    /* ------------------------------------------------------------------ */

    /**
     * signed_content = "{id}.{timestamp}.{body}", HMAC-SHA256 com a parte
     * base64 do secret (após "whsec_"), comparado contra cada "v1,<b64>" do
     * cabeçalho. Público e estático para ser testável no harness.
     */
    public static function validarAssinaturaSvix(string $secret, string $id, string $timestamp, string $corpo, string $cabecalhoAssinaturas): bool
    {
        if ($id === '' || $timestamp === '' || $cabecalhoAssinaturas === '') {
            return false;
        }

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TOLERANCIA_SEGUNDOS) {
            return false; // fora da janela anti-replay
        }

        $chaveB64 = (strpos($secret, 'whsec_') === 0) ? substr($secret, 6) : $secret;
        $chave    = base64_decode($chaveB64, true);
        if ($chave === false || $chave === '') {
            return false;
        }

        $esperada = base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $corpo, $chave, true));

        foreach (explode(' ', trim($cabecalhoAssinaturas)) as $item) {
            $partes = explode(',', $item, 2);
            if (count($partes) === 2 && $partes[0] === 'v1' && hash_equals($esperada, $partes[1])) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------ */
    /* Internos                                                            */
    /* ------------------------------------------------------------------ */

    private function secretDoProvedor(string $codigo): ?string
    {
        try {
            $stmt = Database::getInstance()->getConnection()->prepare(
                'SELECT config_extra FROM ia_provedores WHERE codigo = :c LIMIT 1'
            );
            $stmt->execute([':c' => $codigo]);
            $json = $stmt->fetchColumn();
            if (!is_string($json) || trim($json) === '') {
                return null;
            }
            $cfg = json_decode($json, true);
            return is_array($cfg) && !empty($cfg['webhook_secret']) ? (string) $cfg['webhook_secret'] : null;
        } catch (Throwable $e) {
            LogService::error('ia_webhook_secret_erro', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /** Resposta curta em texto puro — provedor só precisa do status HTTP. */
    private function responder(int $status, string $texto): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $texto;
    }
}
