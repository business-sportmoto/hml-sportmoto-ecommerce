<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/IAIntencaoController.php
// ════════════════════════════════════════════════════════

/**
 * Intenção de compra por cliente — Fase 3.
 *
 * A tela é de LEITURA e conferência: mostra o perfil inferido, os sinais que o
 * geraram e quem decidiu (contagem ou IA). O cálculo em lote é do cron
 * (`cli/ia-intencao.php`); aqui só dá para recalcular um cliente, para
 * conferir uma hipótese sem esperar a madrugada.
 *
 * Perfilar navegação é tratamento de dado pessoal: a tela mostra os sinais
 * exatos que levaram ao rótulo, para a decisão ser auditável em vez de
 * mágica.
 */
class IAIntencaoController extends Controller
{
    private const PERMISSAO = 'marketing_ia';

    private IAIntencaoService $svc;

    public function __construct()
    {
        $this->exigirPermissao(self::PERMISSAO);
        $this->svc = new IAIntencaoService();
    }

    // ── GET /admin/ia/intencao ────────────────────────────
    public function index(): void
    {
        $this->render('ia/intencao/index', [
            'perfis'    => $this->svc->listar(100),
            'segmentos' => $this->svc->segmentos(),
            'elegiveis' => count($this->svc->elegiveis(500)),
            'csrf'      => SecurityHelper::generateCsrf(),
        ], 'admin');
    }

    // ── GET /admin/ia/intencao/detalhe?cliente_id= ────────
    public function detalhe(): void
    {
        $id = (int) ($_GET['cliente_id'] ?? 0);
        $p  = $this->svc->perfil($id);
        if ($p === null) {
            $this->json(['ok' => false, 'msg' => 'Sem perfil calculado para este cliente.']);
        }

        $this->json(['ok' => true, 'perfil' => [
            'cliente_id'   => (int) $p['cliente_id'],
            'cliente_nome' => (string) ($p['cliente_nome'] ?? ''),
            'segmento'     => (string) $p['segmento'],
            'resumo'       => (string) ($p['resumo'] ?? ''),
            'confianca'    => (string) $p['confianca'],
            'origem'       => (string) $p['origem'],
            'eventos'      => (int) $p['eventos'],
            'calculado_em' => (string) $p['calculado_em'],
            'sinais'       => $p['sinais'],
        ]]);
    }

    // ── POST /admin/ia/intencao/recalcular ────────────────
    public function recalcular(): void
    {
        $this->verifyCsrf();

        $id = (int) ($_POST['cliente_id'] ?? 0);
        if ($id <= 0) {
            $this->json(['ok' => false, 'msg' => 'Informe o cliente.']);
        }

        // Aqui HÁ admin logado — diferente do lote do cron, que roda sem
        // ninguém e grava autoria nula.
        $r = $this->svc->recalcular($id, AuthHelper::usuarioId() ?: null);

        $this->json(empty($r['ok'])
            ? ['ok' => false, 'msg' => 'Não foi possível calcular: ' . ($r['msg'] ?? 'sinal insuficiente') . '.']
            : ['ok' => true, 'segmento' => $r['segmento'], 'origem' => $r['origem'],
               'msg' => 'Perfil recalculado por ' . ($r['origem'] === 'ia' ? 'interpretação da IA' : 'contagem') . '.']);
    }

    /* ------------------------------------------------------------------ */

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
               . '<p style="font:16px system-ui;padding:2rem">Você não tem permissão para ver o perfil de intenção dos clientes.</p>';
        }
        exit;
    }
}
