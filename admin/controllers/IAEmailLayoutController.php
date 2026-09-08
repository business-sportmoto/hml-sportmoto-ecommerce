<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/IAEmailLayoutController.php
// ════════════════════════════════════════════════════════

/**
 * Gerador de layout de e-mail — Fase 1 da integração Central de IA × e-mail.
 *
 * A tela vive na CENTRAL, não no módulo de e-mail, para manter a regra do
 * projeto: só a Central fala com IA. O módulo de e-mail recebe um template
 * pronto pela porta que já existe (EmailTemplate::save), sem saber que houve
 * IA no meio.
 *
 * Fluxo: briefing → gerar → revisar no preview → salvar como RASCUNHO.
 * Ativar continua sendo ato humano em /admin/email-marketing/templates.
 *
 * Mesmo desenho dos outros controllers da Central: guard próprio via
 * IAPermissaoService (Ajax recebe JSON, navegação recebe HTML), CSRF pelo
 * SecurityHelper, POST em JSON.
 */
class IAEmailLayoutController extends Controller
{
    private const PERMISSAO = 'marketing_ia';

    private IAEmailLayoutService $svc;

    public function __construct()
    {
        $this->exigirPermissao(self::PERMISSAO);
        $this->svc = new IAEmailLayoutService();
    }

    // ── GET /admin/ia/email-layout ────────────────────────
    public function index(): void
    {
        $this->render('ia/email-layout/index', [
            'modelos' => $this->svc->modelosDisponiveis(),
            'csrf'    => SecurityHelper::generateCsrf(),
        ], 'admin');
    }

    // ── POST /admin/ia/email-layout/gerar ─────────────────
    public function gerar(): void
    {
        $this->verifyCsrf();

        $briefing = SecurityHelper::sanitizeString($_POST['briefing'] ?? '');

        // Os quatro campos de briefing declarados no tipo. Curtos de
        // propósito: são temperos do prompt, não o prompt.
        $opcoes = [];
        foreach (['objetivo', 'publico', 'tom', 'paleta'] as $campo) {
            $v = SecurityHelper::sanitizeString($_POST[$campo] ?? '');
            if ($v !== '') { $opcoes[$campo] = mb_substr($v, 0, 200); }
        }

        // Vem do navegador; o service revalida contra o catálogo.
        $modeloId = !empty($_POST['modelo_id']) ? (int) $_POST['modelo_id'] : null;

        try {
            $r = $this->svc->gerarLayout($briefing, $opcoes, $modeloId);
            LogService::info('ia_email_layout_gerado', [
                'geracao_id' => $r['_ia']['geracao_id'] ?? null,
                'bytes'      => $r['bytes'] ?? null,
            ]);
            $this->json($r);
        } catch (\RuntimeException $e) {
            // Mensagem do service já é exibível (teto de gasto, marcador
            // desbalanceado, HTML curto demais).
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'app', ['acao' => 'ia_email_layout_gerar']);
            $this->json(['ok' => false, 'msg' => 'Falha ao gerar o layout. O erro foi registrado.']);
        }
    }

    // ── POST /admin/ia/email-layout/salvar ────────────────
    public function salvar(): void
    {
        $this->verifyCsrf();

        // O HTML NÃO passa por sanitizeString: é markup por natureza, e
        // escapá-lo aqui destruiria o template. Quem o limpa é o
        // sanitizeHtml() do EmailTemplateService, dentro do service — o
        // mesmo portão que todo template do módulo atravessa.
        $html = (string) ($_POST['html'] ?? '');

        $r = $this->svc->salvarComoTemplate(
            SecurityHelper::sanitizeString($_POST['nome']      ?? ''),
            $html,
            SecurityHelper::sanitizeString($_POST['assunto']   ?? ''),
            SecurityHelper::sanitizeString($_POST['preheader'] ?? ''),
            (int) ($_POST['geracao_id'] ?? 0),
            SecurityHelper::sanitizeString($_POST['briefing']  ?? '')
        );

        if (!empty($r['ok'])) {
            $r['url'] = BASE_URL . '/admin/email-marketing/templates/' . (int) $r['template_id'] . '/editar';
        }

        $this->json($r);
    }

    /* ------------------------------------------------------------------ */

    /** Mesma guarda da Central: granular primeiro, cargo depois, Ajax ≠ navegação. */
    private function exigirPermissao(string $permissao): void
    {
        AuthHelper::requireAdmin();
        if ((new IAPermissaoService())->pode($permissao)) {
            return;
        }
        http_response_code(403);
        if (AuthHelper::isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Sem permissão para esta ação.'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Sem permissão</title>'
               . '<p style="font:16px system-ui;padding:2rem">Você não tem permissão para gerar layouts de e-mail.</p>';
        }
        exit;
    }
}
