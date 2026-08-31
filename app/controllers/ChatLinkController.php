<?php
declare(strict_types=1);

/**
 * app/controllers/ChatLinkController.php
 *
 * Redirect dos links enviados pelas automações do Instagram.
 *
 * Rota: GET /ir/{token}
 *
 * Existe para medir o CTR: sem um salto nosso no meio, não há como saber
 * quantos dos que receberam a mensagem realmente clicaram. Só o PRIMEIRO
 * clique de cada link conta — reclique da mesma pessoa não é alcance novo.
 *
 * Sem auth e sem CSRF: é um link público, aberto pelo cliente no navegador.
 */
class ChatLinkController extends Controller
{
    public function ir(string $token = ''): void
    {
        $token = trim($token);

        // O token é base64url de 9 bytes → 12 chars. Validar o formato antes
        // de tocar o banco evita usar o endpoint como oráculo de enumeração.
        if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{8,24}$/', $token)) {
            $this->semDestino();
            return;
        }

        $destino = null;
        try {
            $destino = (new ChatIgAutomacaoService())->registrarClique($token);
        } catch (Throwable $e) {
            if (class_exists('LogService')) {
                try { LogService::error('chat: falha no redirect de link', ['erro' => $e->getMessage()], 'chat'); }
                catch (Throwable $x) {}
            }
        }

        if (!$destino || !preg_match('#^https?://#i', $destino)) {
            $this->semDestino();
            return;
        }

        // 302 e não 301: um 301 fica em cache no navegador e os cliques
        // seguintes nunca mais chegam aqui — o CTR congelaria no primeiro dia.
        header('Location: ' . $destino, true, 302);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Referrer-Policy: no-referrer-when-downgrade');
        exit;
    }

    /** Link inválido ou expirado: manda para a loja em vez de mostrar erro. */
    private function semDestino(): void
    {
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/'), true, 302);
        header('Cache-Control: no-store');
        exit;
    }
}
