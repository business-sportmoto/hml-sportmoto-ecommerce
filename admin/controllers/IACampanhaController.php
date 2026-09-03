<?php
/**
 * IACampanhaController — telas e endpoints das campanhas (Fase 3 · Bloco B).
 * Fino de propósito: toda a regra vive no IACampanhaService (3A).
 */
class IACampanhaController extends Controller
{
    /* ── Páginas ─────────────────────────────────────────── */

    public function index()
    {
        $this->exigirPermissao('marketing_ia');
        $this->render('ia/campanhas/index', ['csrf' => $this->tokenCsrf()], 'admin');
    }

    public function nova()
    {
        $this->exigirPermissao('marketing_ia');

        $this->render('ia/campanhas/nova', [
            'csrf'        => $this->tokenCsrf(),
            'campanha_id' => (int) ($_GET['id'] ?? 0),
            'tipos'       => (new IATipoConteudo())->listarAtivos(),
            'layouts'     => (new IAComposicaoService())->listarLayouts(),
            'categorias'  => $this->opcoes('categorias'),
            'marcas'      => $this->opcoes('marcas'),
        ], 'admin');
    }

    public function detalhe()
    {
        $this->exigirPermissao('marketing_ia');

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0 || (new IACampanhaService())->buscar($id) === null) {
            $this->render('ia/campanhas/index', ['csrf' => $this->tokenCsrf(), 'erro' => 'Campanha não encontrada.'], 'admin');
            return;
        }
        $this->render('ia/campanhas/detalhe', ['csrf' => $this->tokenCsrf(), 'campanha_id' => $id], 'admin');
    }

    /* ── Leitura (JSON) ──────────────────────────────────── */

    public function listar()
    {
        $this->exigirPermissao('marketing_ia');
        $this->json(['ok' => true, 'itens' => (new IACampanhaService())->listar()]);
    }

    /** Hidratação do wizard/detalhe: campanha + produtos + tipos + contadores. */
    public function dados()
    {
        $this->exigirPermissao('marketing_ia');

        $id  = (int) ($_GET['id'] ?? 0);
        $svc = new IACampanhaService();
        $c   = $svc->buscar($id);
        if ($c === null) {
            $this->json(['ok' => false, 'msg' => 'Campanha não encontrada.']);
            return;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $produtos = $db->query(
                "SELECT p.id, p.nome FROM ia_campanha_produtos cp
             INNER JOIN produtos p ON p.id = cp.produto_id
                  WHERE cp.campanha_id = {$id} ORDER BY p.nome"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $tipos = $db->query(
                "SELECT tipo_conteudo_id, config FROM ia_campanha_tipos WHERE campanha_id = {$id}"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $produtos = [];
            $tipos = [];
        }

        $c['briefing'] = json_decode((string) ($c['briefing'] ?? ''), true) ?: [];
        foreach ($tipos as &$t) {
            $t['tipo_conteudo_id'] = (int) $t['tipo_conteudo_id'];
            $t['config'] = json_decode((string) ($t['config'] ?? ''), true) ?: [];
        }

        $this->json(['ok' => true, 'campanha' => $c, 'produtos' => $produtos,
                     'tipos' => $tipos, 'contadores' => $svc->contadores($id)]);
    }

    public function estimativa()
    {
        $this->exigirPermissao('marketing_ia');
        $this->json((new IACampanhaService())->estimativa((int) ($_GET['id'] ?? 0)));
    }

    /** Atalho aprovado: todos os produtos ativos da categoria/marca (até 60). */
    public function produtosPorFiltro()
    {
        $this->exigirPermissao('marketing_ia');

        $categoriaId = (int) ($_GET['categoria_id'] ?? 0);
        $marcaId     = (int) ($_GET['marca_id'] ?? 0);
        if ($categoriaId <= 0 && $marcaId <= 0) {
            $this->json(['ok' => false, 'msg' => 'Escolha uma categoria ou marca.']);
            return;
        }

        try {
            $db    = Database::getInstance()->getConnection();
            $campo = $categoriaId > 0 ? 'categoria_id' : 'marca_id';
            $valor = $categoriaId > 0 ? $categoriaId : $marcaId;
            $stmt  = $db->prepare(
                "SELECT id, nome FROM produtos
                  WHERE {$campo} = :v AND ativo = 1 AND deleted_at IS NULL
               ORDER BY nome LIMIT 60"
            );
            $stmt->execute([':v' => $valor]);
            $this->json(['ok' => true, 'itens' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
        } catch (Throwable $e) {
            LogService::error('ia_camp_filtro_erro', ['erro' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro ao buscar produtos.']);
        }
    }

    /** Grade produto × tipo renderizada (polling do detalhe). */
    public function grade()
    {
        $this->exigirPermissao('marketing_ia');

        $id  = (int) ($_GET['id'] ?? 0);
        $svc = new IACampanhaService();
        if ($svc->buscar($id) === null) {
            $this->json(['ok' => false, 'msg' => 'Campanha não encontrada.']);
            return;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $produtos = $db->query(
                "SELECT p.id, p.nome FROM ia_campanha_produtos cp
             INNER JOIN produtos p ON p.id = cp.produto_id
                  WHERE cp.campanha_id = {$id} ORDER BY p.nome"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $tipos = $db->query(
                "SELECT ct.tipo_conteudo_id AS id, t.nome
                   FROM ia_campanha_tipos ct INNER JOIN ia_tipos_conteudo t ON t.id = ct.tipo_conteudo_id
                  WHERE ct.campanha_id = {$id} ORDER BY t.ordem"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Última geração de cada par (retries: a mais recente representa o par)
            $mapa = [];
            $ger = $db->query(
                "SELECT g.id, g.produto_id, g.tipo_conteudo_id, g.status, g.aprovacao, g.capacidade
                   FROM ia_geracoes g
                  WHERE g.campanha_id = {$id} AND g.status <> 'cancelada'
               ORDER BY g.id ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($ger as $g) {
                $mapa[$g['produto_id'] . '_' . $g['tipo_conteudo_id']] = $g;
            }
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'msg' => 'Erro ao montar a grade.']);
            return;
        }

        $this->json(['ok' => true, 'html' => $this->partial('campanhas/_grade', [
            'produtos' => $produtos, 'tipos' => $tipos, 'mapa' => $mapa,
        ]), 'contadores' => $svc->contadores($id)]);
    }

    /* ── Escrita (JSON, CSRF) ────────────────────────────── */

    public function criar()
    {
        $this->exigirPermissao('marketing_ia');
        $this->verifyCsrf();
        $this->json((new IACampanhaService())->criar($this->dadosDoPost(), $this->usuarioAtualId()));
    }

    public function atualizar()
    {
        $this->exigirPermissao('marketing_ia');
        $this->verifyCsrf();
        $this->json((new IACampanhaService())->atualizar((int) ($_POST['id'] ?? 0), $this->dadosDoPost()));
    }

    public function produtos()
    {
        $this->exigirPermissao('marketing_ia');
        $this->verifyCsrf();

        $ids = $_POST['produto_ids'] ?? [];
        if (is_string($ids)) {
            $ids = array_filter(array_map('trim', explode(',', $ids)));
        }
        $this->json((new IACampanhaService())->definirProdutos((int) ($_POST['id'] ?? 0), (array) $ids));
    }

    public function tipos()
    {
        $this->exigirPermissao('marketing_ia');
        $this->verifyCsrf();

        $itens = json_decode((string) ($_POST['tipos'] ?? '[]'), true);
        if (!is_array($itens)) {
            $this->json(['ok' => false, 'msg' => 'Formato de tipos inválido.']);
            return;
        }
        $this->json((new IACampanhaService())->definirTipos((int) ($_POST['id'] ?? 0), $itens));
    }

    public function iniciar()      { $this->acao(fn ($s, $id) => $s->iniciar($id, $this->usuarioAtualId())); }
    public function pausar()       { $this->acao(fn ($s, $id) => $s->pausar($id)); }
    public function retomar()      { $this->acao(fn ($s, $id) => $s->retomar($id)); }
    public function cancelar()     { $this->acao(fn ($s, $id) => $s->cancelar($id)); }
    public function arquivar()     { $this->acao(fn ($s, $id) => $s->arquivar($id)); }
    public function refazerFalhas(){ $this->acao(fn ($s, $id) => $s->refazerFalhas($id, $this->usuarioAtualId())); }

    /** Ação em massa: aprova a curadoria de tudo que concluiu. */
    public function aprovarConcluidas()
    {
        $this->exigirPermissao('marketing_ia');
        $this->verifyCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        try {
            $stmt = Database::getInstance()->getConnection()->prepare(
                "UPDATE ia_geracoes SET aprovacao = 'aprovado'
                  WHERE campanha_id = :c AND status = 'concluida' AND aprovacao = 'pendente'"
            );
            $stmt->execute([':c' => $id]);
            $n = $stmt->rowCount();
            LogService::audit('ia_campanha_aprovar_concluidas', ['campanha_id' => $id, 'aprovadas' => $n]);
            $this->json(['ok' => true, 'aprovadas' => $n, 'msg' => "{$n} geração(ões) aprovada(s)."]);
        } catch (Throwable $e) {
            LogService::error('ia_camp_aprovar_erro', ['erro' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro ao aprovar em massa.']);
        }
    }

    /* ── Internos ────────────────────────────────────────── */

    /**
     * Guard de permissao do modulo — ver IAGeracaoController::exigirPermissao.
     * Decisao no IAPermissaoService; negacao distingue Ajax de navegacao.
     */
    private function exigirPermissao(string $permissao): void
    {
        AuthHelper::requireAdmin();
        if ((new IAPermissaoService())->pode($permissao)) {
            return;
        }

        http_response_code(403);
        if (AuthHelper::isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Sem permissao para esta acao.'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Sem permissao</title>'
               . '<p style="font:16px system-ui;padding:2rem">Voce nao tem permissao para acessar a Central de IA.</p>';
        }
        exit;
    }

    /** Token CSRF do painel (SecurityHelper grava em CSRF_TOKEN_NAME). */
    private function tokenCsrf(): string
    {
        return SecurityHelper::generateCsrf();
    }

    private function acao(callable $fn): void
    {
        $this->exigirPermissao('marketing_ia');
        $this->verifyCsrf();
        $this->json($fn(new IACampanhaService(), (int) ($_POST['id'] ?? 0)));
    }

    private function dadosDoPost(): array
    {
        return [
            'nome'              => trim((string) ($_POST['nome'] ?? '')),
            'orcamento_max_usd' => $_POST['orcamento_max_usd'] ?? '',
            'briefing'          => [
                'objetivo' => trim((string) ($_POST['briefing_objetivo'] ?? '')),
                'publico'  => trim((string) ($_POST['briefing_publico'] ?? '')),
                'tom'      => trim((string) ($_POST['briefing_tom'] ?? '')),
                'condicao' => trim((string) ($_POST['briefing_condicao'] ?? '')),
            ],
        ];
    }

    private function opcoes(string $tabela): array
    {
        try {
            return Database::getInstance()->getConnection()
                ->query("SELECT id, nome FROM {$tabela} ORDER BY nome")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** ID do usuário logado — AJUSTE: alinhe com a chave de sessão do projeto. */
    /** Ver IAGeracaoController::usuarioAtualId — mesma razao. */
    private function usuarioAtualId(): int
    {
        return AuthHelper::usuarioId();
    }

    /** Views ao lado do controller (árvore do admin) ou em app/views. */
    private function partial(string $caminho, array $dados = []): string
    {
        $caminho = str_replace(['..', "\0"], '', $caminho);
        $candidatos = [
            __DIR__ . '/../views/ia/' . $caminho . '.php',
            dirname(__DIR__, 2) . '/app/views/ia/' . $caminho . '.php',
        ];
        foreach ($candidatos as $c) {
            if (is_file($c)) {
                extract($dados, EXTR_SKIP);
                ob_start();
                include $c;
                return (string) ob_get_clean();
            }
        }
        return '';
    }
}
