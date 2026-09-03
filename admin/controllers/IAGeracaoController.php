<?php
/**
 * IAGeracaoController — Central de Marketing IA · Geração e Histórico (Fase 1).
 *
 * Rotas sugeridas:
 *   GET  /admin/ia/gerar                        -> gerar            (?produto_id=)
 *   GET  /admin/ia/gerar/produto-busca          -> produtoBusca     (?q=)
 *   GET  /admin/ia/gerar/produto-painel         -> produtoPainel    (?produto_id=)
 *   POST /admin/ia/gerar/preview                -> preview
 *   POST /admin/ia/gerar/enfileirar             -> enfileirar
 *   GET  /admin/ia/gerar/status                 -> status           (?uuids=a,b,c)
 *   GET  /admin/ia/historico                    -> historico
 *   GET  /admin/ia/historico/linhas             -> historicoLinhas  (filtros + ?pagina=)
 *   GET  /admin/ia/historico/detalhe            -> historicoDetalhe (?id=)
 *   POST /admin/ia/historico/aprovacao          -> aprovacao        (id + acao)
 *   POST /admin/ia/historico/refazer            -> refazer          (id [+ prompt])
 *
 * Permissões: marketing_ia (módulo); marketing_ia_aprovar (curadoria).
 */
class IAGeracaoController extends Controller
{
    /* ------------------------------------------------------------------ */
    /* Tela de geração                                                     */
    /* ------------------------------------------------------------------ */

    public function gerar()
    {
        $this->exigirPermissao('marketing_ia');

        $produtoId = (int) ($_GET['produto_id'] ?? 0);

        $this->render('ia/gerar/index', [
            'produto_id_inicial' => $produtoId,
            'csrf'               => $this->tokenCsrf(),
        ], 'admin');
    }

    /** Autocomplete de produtos (nome ou id). */
    public function produtoBusca()
    {
        $this->exigirPermissao('marketing_ia');

        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            $this->json(['ok' => true, 'itens' => []]);
            return;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                'SELECT p.id, p.nome, p.preco, p.preco_promo, p.estoque_total, m.nome AS marca
                   FROM produtos p
              LEFT JOIN marcas m ON m.id = p.marca_id
                  WHERE p.deleted_at IS NULL AND p.ativo = 1
                    AND (p.nome LIKE :q OR p.id = :qid)
               ORDER BY p.vendidos DESC, p.nome ASC
                  LIMIT 8'
            );
            $stmt->execute([':q' => '%' . $q . '%', ':qid' => (int) $q]);
            $itens = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_busca_produto_erro', ['erro' => $e->getMessage()]);
            $itens = [];
        }

        $this->json(['ok' => true, 'itens' => $itens]);
    }

    /** Painel do produto + formulário de geração (partial via AJAX). */
    public function produtoPainel()
    {
        $this->exigirPermissao('marketing_ia');

        $produtoId = (int) ($_GET['produto_id'] ?? 0);
        $contexto  = ($produtoId > 0) ? (new IAPromptBuilder())->montarContexto($produtoId) : null;

        if ($contexto === null) {
            $this->json(['ok' => false, 'msg' => 'Produto não encontrado ou removido.']);
            return;
        }

        $html = $this->partial('gerar/_produto_painel', [
            'ctx'     => $contexto,
            'tipos'   => (new IATipoConteudo())->listarAtivos(),
            // O select de proporção segue o que o modelo primário aceita —
            // oferecer uma opção que o modelo recusa é convite para HTTP 422.
            'proporcoes' => (new IAModelo())->proporcoesDaCapacidade('imagem'),
            // Layouts do compositor (2C) — o select só aparece no tipo banner.
            'layouts'    => (new IAComposicaoService())->listarLayouts(),
            'angulos' => (new IAPromptTemplate())->listarAngulos(),
            'imagem'  => (new IARecorteService())->imagemDoProduto($produtoId),
            'csrf'    => $this->tokenCsrf(),
        ]);

        $this->json(['ok' => true, 'html' => $html]);
    }

    /** Remoção de fundo da foto do produto — cache-first (Fase 2B). */
    public function recorteGerar()
    {
        $this->exigirPermissao('marketing_ia');
        $this->verifyCsrf();

        $resultado = (new IARecorteService())->obterRecorte(
            (int) ($_POST['produto_id'] ?? 0),
            $this->usuarioAtualId(),
            !empty($_POST['imagem_id']) ? (int) $_POST['imagem_id'] : null
        );

        $this->json($resultado);
    }

    /** Pré-visualização do prompt (para o usuário editar antes de enviar). */
    public function preview()
    {
        $this->exigirPermissao('marketing_ia');
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $produtoId = (int) ($_POST['produto_id'] ?? 0);
        $tipoId    = (int) ($_POST['tipo_conteudo_id'] ?? 0);
        $angulo    = trim((string) ($_POST['angulo'] ?? ''));

        $tipo = (new IATipoConteudo())->buscar($tipoId);
        if ($tipo === null) {
            $this->json(['ok' => false, 'msg' => 'Selecione um tipo de conteúdo.']);
            return;
        }

        $builder  = new IAPromptBuilder();
        $contexto = $builder->montarContexto($produtoId);
        if ($contexto === null) {
            $this->json(['ok' => false, 'msg' => 'Produto não encontrado.']);
            return;
        }

        $template = ($angulo !== '' && $tipo['capacidade'] === 'texto')
            ? (new IAPromptTemplate())->buscarPorAngulo($angulo, $tipoId)
            : null;
        $briefing = $this->lerBriefing();

        $prompt = ($tipo['capacidade'] === 'imagem')
            ? $builder->montarPromptImagem($contexto, $tipo, $briefing)
            : $builder->montarPrompt($contexto, $tipo, $template, $briefing);

        $this->json(['ok' => true, 'prompt' => $prompt]);
    }

    /** Enfileira 1/3/5 gerações. */
    public function enfileirar()
    {
        $this->exigirPermissao('marketing_ia');
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $usuarioId = $this->usuarioAtualId();
        if ($usuarioId <= 0) {
            $this->json(['ok' => false, 'msg' => 'Sessão expirada — faça login novamente.']);
            return;
        }

        $resultado = (new IAGeracaoService())->enfileirar([
            'usuario_id'       => $usuarioId,
            'produto_id'       => (int) ($_POST['produto_id'] ?? 0),
            'tipo_conteudo_id' => (int) ($_POST['tipo_conteudo_id'] ?? 0),
            'angulo'           => trim((string) ($_POST['angulo'] ?? '')),
            'briefing'         => $this->lerBriefing(),
            'prompt_custom'    => trim((string) ($_POST['prompt_custom'] ?? '')),
            'variacoes'        => (int) ($_POST['variacoes'] ?? 1),
            'proporcao'        => trim((string) ($_POST['proporcao'] ?? '1:1')),
            'usar_referencia'  => !empty($_POST['usar_referencia']),
            // Banner (2C): o pipeline de composição lê estes três.
            'layout'           => trim((string) ($_POST['layout'] ?? '')),
            'banner_headline'  => trim((string) ($_POST['banner_headline'] ?? '')),
            'banner_subtitulo' => trim((string) ($_POST['banner_subtitulo'] ?? '')),
        ]);

        $this->json($resultado);
    }

    /** Polling de status em lote. */
    public function status()
    {
        $this->exigirPermissao('marketing_ia');

        $uuids = explode(',', (string) ($_GET['uuids'] ?? ''));
        $itens = (new IAGeracaoService())->statusLote($uuids);

        $this->json(['ok' => true, 'itens' => $itens]);
    }

    /**
     * Serve um arquivo gerado (imagem) SOMENTE autenticado — o storage é
     * negado ao público. ?id=  (+ &download=1 para forçar download).
     */
    public function arquivo()
    {
        $this->exigirPermissao('marketing_ia');

        $id  = (int) ($_GET['id'] ?? 0);
        $arq = ($id > 0) ? (new IAGeracao())->arquivoPorId($id) : null;

        if ($arq === null || $arq['tipo'] !== 'imagem') {
            http_response_code(404);
            exit('Arquivo não encontrado.');
        }

        // Trava de path: o caminho gravado precisa viver dentro do storage do módulo.
        $base = defined('IA_STORAGE_PATH')
            ? rtrim(IA_STORAGE_PATH, '/')
            : rtrim(dirname(__DIR__, 2), '/') . '/storage/ia'; // AJUSTE: mesmo base do config
        $realBase = realpath($base);
        $real     = realpath((string) $arq['caminho']);

        if ($real === false || $realBase === false || strpos($real, $realBase) !== 0 || !is_file($real)) {
            LogService::warning('ia_arquivo_fora_do_storage', ['arquivo_id' => $id]);
            http_response_code(404);
            exit('Arquivo não encontrado.');
        }

        header('Content-Type: ' . ($arq['mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($real));
        header('Cache-Control: private, max-age=86400');
        if (!empty($_GET['download'])) {
            header('Content-Disposition: attachment; filename="' . basename($real) . '"');
        }
        readfile($real);
        exit;
    }

    /** Publica a arte final como banner do site — nasce INATIVO (2C). */
    public function bannerPublicar()
    {
        $this->exigirPermissao('marketing_ia_aprovar');
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $usuarioId = $this->usuarioAtualId();
        if ($usuarioId <= 0) {
            $this->json(['ok' => false, 'msg' => 'Sessão expirada — faça login novamente.']);
            return;
        }

        $this->json((new IAComposicaoService())->publicarBanner(
            (int) ($_POST['geracao_id'] ?? 0),
            $usuarioId,
            [
                'zona_id' => (int) ($_POST['zona_id'] ?? 0),
                'titulo'  => trim((string) ($_POST['titulo'] ?? '')),
                'link'    => trim((string) ($_POST['link'] ?? '')),
            ]
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Histórico                                                           */
    /* ------------------------------------------------------------------ */

    public function historico()
    {
        $this->exigirPermissao('marketing_ia');

        $modelo   = new IAGeracao();
        $filtros  = $this->lerFiltros();
        $pagina   = max(1, (int) ($_GET['pagina'] ?? 1));
        $lista    = $modelo->listar($filtros, $pagina);

        $this->render('ia/historico/index', [
            'linhas'      => $lista['linhas'],
            'total'       => $lista['total'],
            'pagina'      => $pagina,
            'por_pagina'  => 25,
            'filtros'     => $filtros,
            'tipos'       => (new IATipoConteudo())->listarAtivos(),
            'kpis'        => $modelo->kpis(),
            'gasto_hoje'  => (new IACustoService())->gastoGlobalHoje(),
            'pct_diario'  => (new IACustoService())->percentualDiarioGlobal(),
            'csrf'        => $this->tokenCsrf(),
        ], 'admin');
    }

    public function historicoLinhas()
    {
        $this->exigirPermissao('marketing_ia');

        $modelo  = new IAGeracao();
        $filtros = $this->lerFiltros();
        $pagina  = max(1, (int) ($_GET['pagina'] ?? 1));
        $lista   = $modelo->listar($filtros, $pagina);

        $html = $this->partial('historico/_linhas', ['linhas' => $lista['linhas']]);

        $this->json([
            'ok'      => true,
            'html'    => $html,
            'total'   => $lista['total'],
            'pagina'  => $pagina,
            'paginas' => max(1, (int) ceil($lista['total'] / 25)),
        ]);
    }

    public function historicoDetalhe()
    {
        $this->exigirPermissao('marketing_ia');

        $id = (int) ($_GET['id'] ?? 0);
        $g  = ($id > 0) ? (new IAGeracao())->buscarPorId($id) : null;

        if ($g === null) {
            $this->json(['ok' => false, 'msg' => 'Geração não encontrada.']);
            return;
        }

        $html = $this->partial('historico/_detalhe', [
            'g'          => $g,
            'roteamento' => (new IAGeracao())->roteamentoDe($id),
            'arquivo_id' => (new IAGeracao())->arquivoPrincipalDe($id),
            'csrf'       => $this->tokenCsrf(),
        ]);

        $this->json(['ok' => true, 'titulo' => 'Geração #' . $id, 'html' => $html]);
    }

    /** Curadoria: aprovado | reprovado | arquivado | pendente. */
    public function aprovacao()
    {
        $this->exigirPermissao('marketing_ia_aprovar');
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $id   = (int) ($_POST['id'] ?? 0);
        $acao = (string) ($_POST['acao'] ?? '');

        if ($id <= 0 || !in_array($acao, ['aprovado', 'reprovado', 'arquivado', 'pendente'], true)) {
            $this->json(['ok' => false, 'msg' => 'Ação inválida.']);
            return;
        }

        if (!(new IAGeracao())->definirAprovacao($id, $acao)) {
            $this->json(['ok' => false, 'msg' => 'Erro ao atualizar a curadoria.']);
            return;
        }

        LogService::audit('ia_geracao_aprovacao', ['geracao_id' => $id, 'aprovacao' => $acao]);
        $this->json(['ok' => true, 'msg' => 'Curadoria atualizada.', 'aprovacao' => $acao]);
    }

    /** Refazer com ajustes (nova geração ligada pela origem). */
    public function refazer()
    {
        $this->exigirPermissao('marketing_ia');
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $usuarioId = $this->usuarioAtualId();
        if ($usuarioId <= 0) {
            $this->json(['ok' => false, 'msg' => 'Sessão expirada — faça login novamente.']);
            return;
        }

        $id     = (int) ($_POST['id'] ?? 0);
        $prompt = isset($_POST['prompt_custom']) ? (string) $_POST['prompt_custom'] : null;

        $this->json((new IAGeracaoService())->refazer($id, $usuarioId, $prompt));
    }

    /* ------------------------------------------------------------------ */
    /* Auxiliares                                                          */
    /* ------------------------------------------------------------------ */

    private function lerBriefing(): array
    {
        return [
            'objetivo' => trim((string) ($_POST['briefing_objetivo'] ?? '')),
            'publico'  => trim((string) ($_POST['briefing_publico'] ?? '')),
            'tom'      => trim((string) ($_POST['briefing_tom'] ?? '')),
            'condicao' => trim((string) ($_POST['briefing_condicao'] ?? '')),
        ];
    }

    private function lerFiltros(): array
    {
        $data = fn(string $chave) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET[$chave] ?? '')) ? $_GET[$chave] : '';

        return [
            'status'           => in_array($_GET['status'] ?? '', ['na_fila', 'processando', 'aguardando_provedor', 'concluida', 'falhou', 'cancelada'], true) ? $_GET['status'] : '',
            'aprovacao'        => in_array($_GET['aprovacao'] ?? '', ['pendente', 'aprovado', 'reprovado', 'arquivado'], true) ? $_GET['aprovacao'] : '',
            'tipo_conteudo_id' => (int) ($_GET['tipo_conteudo_id'] ?? 0),
            'busca'            => trim((string) ($_GET['busca'] ?? '')),
            'data_ini'         => $data('data_ini'),
            'data_fim'         => $data('data_fim'),
        ];
    }

    /** Renderiza partial de app/views/ia/ (subpastas permitidas, sem traversal). */
    private function partial(string $caminho, array $dados = []): string
    {
        $caminho = str_replace(['..', "\0"], '', $caminho);

        // Controller pode viver em admin/controllers/ (árvore do admin) ou
        // app/controllers/ — tenta as views ao lado e, depois, em app/views.
        $candidatos = [
            __DIR__ . '/../views/ia/' . $caminho . '.php',
            dirname(__DIR__, 2) . '/app/views/ia/' . $caminho . '.php',
        ];

        $completo = null;
        foreach ($candidatos as $c) {
            if (is_file($c)) { $completo = $c; break; }
        }

        if ($completo === null) {
            LogService::error('ia_partial_inexistente', ['arquivo' => $caminho]);
            return '';
        }

        extract($dados, EXTR_SKIP);
        ob_start();
        include $completo;
        return (string) ob_get_clean();
    }

    /**
     * Guard de permissão do módulo.
     *
     * Faz o que o AuthHelper::requirePermission() faz, com duas diferenças:
     * a decisão de acesso passa pelo IAPermissaoService (permissão granular
     * primeiro, cargo de cobertura depois), e a negação distingue Ajax de
     * navegação como o requireAdminLevel — a Central é toda Ajax, e um 403
     * em HTML chegava aos $.post como markup onde o JS esperava JSON: o
     * usuário via "Falha de comunicação" no lugar do motivo real.
     *
     * A resposta de navegação é montada aqui de propósito: no painel a base
     * de views é admin/views, onde não existem errors/403 nem o layout
     * minimal que o AuthHelper chama — por lá a negação vira RuntimeException.
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
            echo json_encode(['ok' => false, 'msg' => 'Sem permissão para esta ação.'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Sem permissão</title>'
               . '<p style="font:16px system-ui;padding:2rem">Você não tem permissão para acessar a Central de IA.</p>';
        }
        exit;
    }

    private function exigirPost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->json(['ok' => false, 'msg' => 'Método não permitido.']);
            return false;
        }
        return true;
    }

    /**
     * ID do usuário logado para ia_geracoes.usuario_id.
     *
     * Resolve pelo AuthHelper, nunca pela chave crua da sessão: o login do
     * admin grava 'admin_user_id', e 'usuario_id' só passa a existir depois
     * que alguém chama usuarioId(). Lendo a chave direto, a primeira geração
     * de uma sessão nova caía em "Sessão expirada" sem motivo.
     */
    private function usuarioAtualId(): int
    {
        return AuthHelper::usuarioId();
    }

    /**
     * Token CSRF para os formulários — o mesmo do resto do painel
     * (SecurityHelper grava em CSRF_TOKEN_NAME, que é o que verifyCsrf lê).
     */
    private function tokenCsrf(): string
    {
        return SecurityHelper::generateCsrf();
    }
}
