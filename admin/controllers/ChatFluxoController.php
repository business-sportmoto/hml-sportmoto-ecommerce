<?php
/**
 * admin/controllers/ChatFluxoController.php
 *
 * Construtor visual de fluxos conversacionais (Drawflow).
 *
 * Rotas:
 *   GET  /admin/chat/fluxos                 → listagem
 *   GET  /admin/chat/fluxos/atividade       → timeline de execuções
 *   GET  /admin/chat/fluxos/atividade/dados → JSON paginado
 *   GET  /admin/chat/fluxos/{id}            → editor
 *   GET  /admin/chat/fluxos/{id}/stats      → contadores por nó (balões do canvas)
 *   POST /admin/chat/fluxos/criar
 *   POST /admin/chat/fluxos/{id}/salvar
 *   POST /admin/chat/fluxos/{id}/publicar
 *   POST /admin/chat/fluxos/{id}/status
 *   POST /admin/chat/fluxos/{id}/duplicar
 *   POST /admin/chat/fluxos/{id}/excluir
 *
 * Permissão: um fluxo publicado fala com clientes em nome da loja e gera custo
 * por mensagem → super/gerente.
 */
class ChatFluxoController extends Controller
{
    private ChatFluxoAdminService $svc;

    public function __construct()
    {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->svc = new ChatFluxoAdminService();
    }

    // =========================================================================
    // LISTAGEM
    // =========================================================================

    public function index(): void
    {
        $this->render('chat/fluxos', [
            'titulo' => 'Chat — Fluxos',
            'fluxos' => $this->svc->listar(),
            'kpis'   => $this->svc->kpis(),
        ], 'admin');
    }

    // =========================================================================
    // EDITOR
    // =========================================================================

    public function editor($id): void
    {
        $id    = SecurityHelper::sanitizeInt($id);
        $fluxo = $this->svc->carregar($id);
        if (!$fluxo) { http_response_code(404); echo 'Fluxo não encontrado.'; return; }

        $contatos = new ChatContatoService();

        $this->render('chat/fluxo-editor', [
            'titulo'    => 'Fluxo — ' . $fluxo['nome'],
            'fluxo'     => $fluxo,
            'catalogo'  => ChatNoRegistry::catalogo(),
            'tags'      => $contatos->listarTags(),
            'templates' => (new ChatTemplateService())->aprovados(),
            'fluxos'    => $this->outrosFluxos($id),
            'agentes'   => (new ChatConversaService())->agentesDisponiveis(),
            'campos'    => $contatos->chavesDeCampoConhecidas(),
            'produtos'  => $this->svc->produtosDoGrafo($fluxo['grafo']),
        ], 'admin');
    }

    /** Destinos possíveis do nó ir_para_fluxo (nunca ele mesmo). */
    private function outrosFluxos(int $exceto): array
    {
        return array_values(array_filter(
            $this->svc->listarPublicados(),
            fn($f) => (int)$f['id'] !== $exceto
        ));
    }

    public function stats($id): void
    {
        $id    = SecurityHelper::sanitizeInt($id);
        $fluxo = $this->svc->carregar($id);
        if (!$fluxo) { $this->json(['ok' => false, 'erro' => 'Fluxo não encontrado.'], 404); return; }

        $versao = (int)$fluxo['versao_publicada'];
        if ($versao < 1) {
            // Rascunho nunca rodou — o canvas simplesmente não mostra números
            $this->json(['ok' => true, 'versao' => 0, 'nos' => []]);
            return;
        }

        $this->json([
            'ok'     => true,
            'versao' => $versao,
            'nos'    => (new ChatFluxoMotor())->statsPorNo($id, $versao),
        ]);
    }

    // =========================================================================
    // ESCRITA
    // =========================================================================

    public function criar(): void
    {
        $this->verifyCsrf();
        $nome = trim((string)($_POST['nome'] ?? ''));
        if ($nome === '') { $this->json(['ok' => false, 'erro' => 'Informe o nome do fluxo.']); return; }

        $id = $this->svc->criar($nome, trim((string)($_POST['descricao'] ?? '')) ?: null, AuthHelper::usuarioId());
        $this->json(['ok' => true, 'id' => $id]);
    }

    public function salvar($id): void
    {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($id);

        $grafo = json_decode((string)($_POST['grafo_json'] ?? ''), true);
        if (!is_array($grafo)) {
            $this->json(['ok' => false, 'erros' => ['JSON do grafo inválido.']]); return;
        }

        $meta = [];
        if (isset($_POST['nome']) && trim((string)$_POST['nome']) !== '') {
            $meta['nome'] = mb_substr(trim((string)$_POST['nome']), 0, 120);
        }
        if (isset($_POST['config_json'])) {
            $cfg = json_decode((string)$_POST['config_json'], true);
            $meta['config'] = is_array($cfg) ? $cfg : [];
        }

        $this->json($this->svc->salvarRascunho($id, $grafo, $meta ?: null));
    }

    public function publicar($id): void
    {
        $this->verifyCsrf();
        $this->json($this->svc->publicar(SecurityHelper::sanitizeInt($id)));
    }

    public function status($id): void
    {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($id);
        $ok = $this->svc->mudarStatus($id, (string)($_POST['status'] ?? ''));
        $this->json(['ok' => $ok, 'erro' => $ok ? null : 'Não foi possível mudar o status. Publique o fluxo primeiro.']);
    }

    public function duplicar($id): void
    {
        $this->verifyCsrf();
        $novo = $this->svc->duplicar(SecurityHelper::sanitizeInt($id), AuthHelper::usuarioId());
        $this->json($novo ? ['ok' => true, 'id' => $novo] : ['ok' => false, 'erro' => 'Falha ao duplicar.']);
    }

    public function excluir($id): void
    {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super');   // apagar fluxo apaga o histórico junto
        $this->json(['ok' => $this->svc->excluir(SecurityHelper::sanitizeInt($id))]);
    }

    // =========================================================================
    // ATIVIDADE
    // =========================================================================

    public function atividade(): void
    {
        $this->render('chat/fluxo-atividade', [
            'titulo' => 'Chat — Atividade dos fluxos',
            'fluxos' => $this->svc->listar(true),
            'kpis'   => $this->svc->kpis(),
        ], 'admin');
    }

    public function atividadeDados(): void
    {
        $filtros = [
            'fluxo_id'   => (int)($_GET['fluxo_id'] ?? 0),
            'contato_id' => (int)($_GET['contato_id'] ?? 0),
            'so_erros'   => !empty($_GET['so_erros']),
        ];
        $antesDe = (int)($_GET['antes_de'] ?? 0);
        $itens   = $this->svc->atividade($filtros, 50, $antesDe);

        $this->json([
            'ok'      => true,
            'itens'   => $itens,
            'kpis'    => $antesDe === 0 ? $this->svc->kpis() : null,
            'proximo' => $itens ? (int)end($itens)['id'] : 0,
        ]);
    }
}
