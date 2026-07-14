<?php
/**
 * app/controllers/TrackController.php
 *
 * Endpoint público para eventos client-side (banner click/visto).
 * Só aceita os tipos da whitelist TrackingService::TIPOS_CLIENT_SIDE —
 * todo o resto é registrado server-side nos controllers.
 *
 * Rota:
 *   POST /track   { tipo, entidade_tipo?, entidade_id?, ctx? }
 *
 * Segurança:
 *   - Whitelist estrita de tipos (client não inventa evento)
 *   - Rate limit: 60 eventos/min por visitante (em sessão, sem tabela extra)
 *   - Resposta sempre 200 vazia — endpoint não vaza informação
 */
class TrackController extends Controller
{
    private const RATE_MAX = 60;   // eventos por janela
    private const RATE_JANELA = 60; // segundos

    public function registrar(): void
    {
        // Sempre responde 204 rapidinho, aconteça o que acontecer
        try {
            if (!$this->rateOk()) { $this->fim(); return; }

            $tipo = trim((string)($_POST['tipo'] ?? ''));
            if (!in_array($tipo, TrackingService::TIPOS_CLIENT_SIDE, true)) {
                $this->fim(); return;
            }

            $etipo = trim((string)($_POST['entidade_tipo'] ?? '')) ?: null;
            $eid   = isset($_POST['entidade_id']) && $_POST['entidade_id'] !== ''
                   ? (int)$_POST['entidade_id'] : null;

            // Contexto: só chaves simples, valores curtos (anti-abuso)
            $ctx = [];
            if (!empty($_POST['ctx']) && is_array($_POST['ctx'])) {
                foreach ($_POST['ctx'] as $k => $v) {
                    if (!is_scalar($v)) continue;
                    $k = mb_substr(preg_replace('/[^a-z0-9_]/i', '', (string)$k), 0, 30);
                    if ($k === '') continue;
                    $ctx[$k] = mb_substr((string)$v, 0, 200);
                    if (count($ctx) >= 8) break;
                }
            }

            TrackingService::registrar($tipo, $etipo, $eid, $ctx);
        } catch (Throwable $e) {
            // silêncio — tracking não reclama
        }
        $this->fim();
    }

    /** Rate limit simples por sessão (sem tabela, sem custo). */
    private function rateOk(): bool
    {
        $agora = time();
        $rl = $_SESSION['_trk_rl'] ?? ['ini' => $agora, 'n' => 0];
        if (($agora - $rl['ini']) > self::RATE_JANELA) {
            $rl = ['ini' => $agora, 'n' => 0];
        }
        $rl['n']++;
        $_SESSION['_trk_rl'] = $rl;
        return $rl['n'] <= self::RATE_MAX;
    }

    private function fim(): void
    {
        http_response_code(204);
        // corpo vazio
    }
}
