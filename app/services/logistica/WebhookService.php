<?php
/**
 * WebhookService — notificações de saída para integrações.
 *
 * Cada evento é enviado por POST com o corpo em JSON e assinado por HMAC-SHA256
 * (header X-Logistica-Signature), permitindo ao parceiro verificar autenticidade.
 * Envio best-effort (timeout curto), sempre logado; nunca derruba o fluxo.
 *
 * assinar()/cabecalhos() são PUROS — testáveis sem rede.
 */
class WebhookService
{
    /** Assinatura HMAC-SHA256 (hex) do corpo com o segredo da integração. */
    public static function assinar(string $payloadJson, string $secret): string
    {
        return hash_hmac('sha256', $payloadJson, $secret);
    }

    /** Cabeçalhos de entrega (assinatura + timestamp + evento). */
    public static function cabecalhos(string $payloadJson, string $secret, string $evento): array
    {
        $ts = (string)time();
        return [
            'Content-Type: application/json',
            'X-Logistica-Event: ' . $evento,
            'X-Logistica-Timestamp: ' . $ts,
            'X-Logistica-Signature: sha256=' . self::assinar($ts . '.' . $payloadJson, $secret),
        ];
    }

    /** Monta o corpo padrão do evento. */
    public static function corpo(string $evento, array $dados): string
    {
        return json_encode([
            'evento'    => $evento,
            'enviado_em' => date('c'),
            'dados'     => $dados,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Envia o webhook (best-effort). Retorna ok/status. */
    public static function enviar(string $url, string $secret, string $evento, array $dados): array
    {
        $url = trim($url);
        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            return ['ok' => false, 'erro' => 'URL de webhook inválida.'];
        }
        $body = self::corpo($evento, $dados);
        $headers = self::cabecalhos($body, $secret, $evento);

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $resp = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            $status = 0; $err = $e->getMessage(); $resp = null;
        }

        $ok = $status >= 200 && $status < 300;
        if (class_exists('LogService')) {
            $ctx = ['evento' => $evento, 'url' => $url, 'status' => $status];
            $ok ? LogService::info('Webhook enviado', $ctx) : LogService::warning('Webhook falhou', $ctx + ['erro' => $err ?? '']);
        }
        return ['ok' => $ok, 'status' => $status, 'erro' => $ok ? null : ($err ?: 'HTTP ' . $status)];
    }

    /** Conveniência: dispara para uma chave que tenha webhook configurado. */
    public static function notificar(array $webhookConfig, string $evento, array $dados): array
    {
        $url = $webhookConfig['url'] ?? '';
        if (!$url) return ['ok' => false, 'erro' => 'Integração sem webhook configurado.'];
        return self::enviar($url, (string)($webhookConfig['secret'] ?? ''), $evento, $dados);
    }
}
