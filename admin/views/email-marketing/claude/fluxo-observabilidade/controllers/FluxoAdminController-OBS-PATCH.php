<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  OBSERVABILIDADE — patch do FluxoAdminController + rotas
 * ═══════════════════════════════════════════════════════════════════════════
 *  2 métodos novos (aditivos — cole dentro da classe) + 3 rotas.
 */

/* ─────────────────────────────────────────────────────────────────────────────
   ROTAS — admin/config/routes.php (formato AdminRouter, sem /admin):

   AdminRouter::get('/fluxos/atividade',   'FluxoAdminController@atividade');
   AdminRouter::get('/fluxos/atividade/dados', 'FluxoAdminController@atividadeDados');
   AdminRouter::get('/fluxos/{id}/stats',  'FluxoAdminController@stats');

   ATENÇÃO À ORDEM: '/fluxos/atividade' precisa vir ANTES de '/fluxos/{id}'
   na tabela de rotas, senão "atividade" casa como {id}.
────────────────────────────────────────────────────────────────────────────── */


/* ── Copie os DOIS métodos abaixo para dentro do seu FluxoAdminController.
      (A classe wrapper existe só para este arquivo lintar.) ────────────────── */
class FluxoAdminController_PATCH_Observabilidade
{
    /** @var FluxoAdminService */
    private $svc;
    private $params = [];
    private function json($d) {}
    private function render($v, $d, $l) {}

/* ── MÉTODO 1: stats por nó (os balões do canvas) ─────────────────────────── */

    /** GET /admin/fluxos/{id}/stats — contadores por nó da versão publicada. */
    public function stats(): void
    {
        $id = (int)($this->params['id'] ?? $_GET['id'] ?? 0);
        $fluxo = $this->svc->carregar($id);
        if (!$fluxo) { $this->json(['ok' => false, 'erros' => ['Fluxo não encontrado.']]); return; }

        $versao = (int)$fluxo['versao_publicada'];
        if ($versao < 1) {
            // Rascunho nunca rodou — o canvas simplesmente não mostra números
            $this->json(['ok' => true, 'versao' => 0, 'nos' => []]);
            return;
        }

        $db = Database::getInstance()->getConnection();
        $this->json([
            'ok'     => true,
            'versao' => $versao,
            'nos'    => FluxoLogService::statsPorNo($db, $id, $versao),
        ]);
    }


/* ── MÉTODO 2: timeline geral (tela + dados paginados) ────────────────────── */

    /** GET /admin/fluxos/atividade — a tela. */
    public function atividade(): void
    {
        $db = Database::getInstance()->getConnection();

        // Fluxos para o filtro
        $fluxos = [];
        try {
            $st = $db->query("SELECT id, nome, status FROM fluxo_v2 ORDER BY nome ASC");
            $fluxos = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {}

        $this->render('admin/fluxos/atividade', [
            'titulo' => 'Atividade das automações',
            'fluxos' => $fluxos,
            'kpis'   => FluxoLogService::kpis($db),
        ], 'admin');
    }

    /** GET /admin/fluxos/atividade/dados — JSON paginado por cursor. */
    public function atividadeDados(): void
    {
        $db = Database::getInstance()->getConnection();

        $filtros = [
            'fluxo_id'   => (int)($_GET['fluxo_id'] ?? 0),
            'cliente_id' => (int)($_GET['cliente_id'] ?? 0),
            'so_erros'   => !empty($_GET['so_erros']),
        ];
        $antesDe = (int)($_GET['antes_de'] ?? 0);

        $itens = FluxoLogService::atividade($db, $filtros, 50, $antesDe);

        $this->json([
            'ok'      => true,
            'itens'   => $itens,
            'kpis'    => $antesDe === 0 ? FluxoLogService::kpis($db) : null,
            'proximo' => $itens ? (int)end($itens)['id'] : 0,
        ]);
    }
}
