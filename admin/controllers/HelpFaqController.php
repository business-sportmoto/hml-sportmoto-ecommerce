<?php

class HelpFaqController extends Controller {

    private HelpFaqCategoria $categoriaModel;
    private HelpFaq          $faqModel;

    public function __construct() {
        AuthHelper::requirePermission('help_faq');
        $this->categoriaModel = new HelpFaqCategoria();
        $this->faqModel       = new HelpFaq();
    }

    // =====================================================================
    // CATEGORIAS — páginas
    // =====================================================================

    // GET /admin/help-faq
    public function index(): void {
        $categorias = $this->categoriaModel->getAll();
        $this->render('help_faq/index', ['categorias' => $categorias], 'admin');
    }

    // GET /admin/help-faq/categoria/nova  (fallback sem drawer)
    public function novaCategoria(): void {

        $icones = self::listaIcones();

        $this->render('help_faq/categoria_form', [
            'categoria' => null,
            'modo'      => 'criar',
            'icones'    => $icones,
        ], 'admin');
    }

    // GET /admin/help-faq/categoria/editar/:id  (fallback sem drawer)
    public function editarCategoria(int $id): void {
        $categoria = $this->categoriaModel->getById($id);
        if (!$categoria) { $this->notFound(); return; }

        $icones = self::listaIcones();
        $this->render('help_faq/categoria_form', [
            'categoria' => $categoria,
            'modo'      => 'editar',
            'icones'    => $icones,
        ], 'admin');
    }

    // =====================================================================
    // CATEGORIAS — AJAX / drawer
    // =====================================================================

    // GET /admin/help-faq/categoria/form[?id=X]
    public function formCategoria(): void {
        try {
            $id        = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            $categoria = $id > 0 ? $this->categoriaModel->getById($id) : null;

            if ($id > 0 && !$categoria) {
                $this->json(['ok' => false, 'msg' => 'Categoria não encontrada.']);
                return;
            }

            $modo = 'editar';
            $icones = self::listaIcones();
            $iconeAtual  = $categoria ? $categoria['icone'] : 'bi-question-circle';
            $categoriaId = 0;

            ob_start();
            include ADMIN_PATH . '/views/help_faq/categoria_form.php';
            $html = ob_get_clean();

            $this->json(['ok' => true, 'html' => $html]);

        } catch (Exception $e) {
            LogService::error('help_form_categoria', ['erro' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro interno ao carregar formulário.']);
        }
    }

    // POST /admin/help-faq/categoria/salvar
    public function salvarCategoria(): void {
        $this->verifyCsrf();
        $isAjax = $this->isAjax();

        $nome      = trim($_POST['nome']      ?? '');
        $icone     = trim($_POST['icone']     ?? 'bi-question-circle');
        $descricao = trim($_POST['descricao'] ?? '');
        $ordem     = (int)($_POST['ordem']    ?? 0);
        $ativo     = isset($_POST['ativo'])   ? 1 : 0;
        $id        = (int)($_POST['id']       ?? 0);

        if (empty($nome)) {
            if ($isAjax) {
                $this->json(['ok' => false, 'msg' => 'O nome da categoria é obrigatório.']);
                return;
            }
            $_SESSION['flash_error'] = 'O nome da categoria é obrigatório.';
            header('Location: /admin/help-faq/categoria/' . ($id ? "editar/$id" : 'nova'));
            exit;
        }

        try {
            if ($id > 0) {
                $cat = $this->categoriaModel->getById($id);
                if (!$cat) {
                    if ($isAjax) { $this->json(['ok' => false, 'msg' => 'Categoria não encontrada.']); return; }
                    header('Location: /admin/help-faq'); exit;
                }

                $slug = $cat['nome'] !== $nome
                    ? $this->categoriaModel->generateSlug($nome)
                    : $cat['slug'];

                $this->categoriaModel->update($id, compact('nome', 'slug', 'icone', 'descricao', 'ordem', 'ativo'));
                LogService::audit('help_categoria_update', ['id' => $id, 'nome' => $nome]);

                $cat = $this->categoriaModel->getById($id);
                if ($isAjax) {
                    $this->json(['ok' => true, 'msg' => 'Categoria atualizada!', 'categoria' => $cat]);
                    return;
                }
                $_SESSION['flash_success'] = 'Categoria atualizada com sucesso.';

            } else {
                $slug  = $this->categoriaModel->generateSlug($nome);
                $newId = $this->categoriaModel->create(compact('nome', 'slug', 'icone', 'descricao', 'ordem', 'ativo'));
                LogService::audit('help_categoria_create', ['id' => $newId, 'nome' => $nome]);

                $cat = $this->categoriaModel->getById($newId);
                if ($isAjax) {
                    $this->json(['ok' => true, 'msg' => 'Categoria criada!', 'categoria' => $cat]);
                    return;
                }
                $_SESSION['flash_success'] = 'Categoria criada com sucesso.';
            }

        } catch (Exception $e) {
            LogService::error('help_categoria_save', ['id' => $id, 'erro' => $e->getMessage()]);
            if ($isAjax) { $this->json(['ok' => false, 'msg' => 'Erro ao salvar categoria.']); return; }
            $_SESSION['flash_error'] = 'Erro ao salvar categoria. Tente novamente.';
        }

        header('Location: /admin/help-faq');
        exit;
    }

    // POST /admin/help-faq/categoria/excluir
    public function excluirCategoria(): void {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);

        try {
            $cat = $this->categoriaModel->getById($id);
            if (!$cat) {
                $this->json(['ok' => false, 'msg' => 'Categoria não encontrada.']);
                return;
            }

            $this->categoriaModel->delete($id);
            LogService::audit('help_categoria_delete', ['id' => $id, 'nome' => $cat['nome']]);
            $this->json(['ok' => true, 'msg' => 'Categoria excluída.']);

        } catch (Exception $e) {
            LogService::error('help_categoria_delete', ['id' => $id, 'erro' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro ao excluir. Verifique se há perguntas vinculadas.']);
        }
    }

    // =====================================================================
    // PERGUNTAS — páginas
    // =====================================================================

    // GET /admin/help-faq/perguntas[?categoria_id=X]
    public function perguntas(): void {
        $categoriaId = isset($_GET['categoria_id']) ? (int)$_GET['categoria_id'] : null;
        $perguntas   = $this->faqModel->getAllAdmin($categoriaId);
        $categorias  = $this->categoriaModel->getAll();

        $this->render('help_faq/perguntas', [
            'perguntas'        => $perguntas,
            'categorias'       => $categorias,
            'filtro_categoria' => $categoriaId,
        ], 'admin');
    }

    // GET /admin/help-faq/pergunta/nova  (fallback sem drawer)
    public function novaPergunta(): void {
        $categorias = $this->categoriaModel->getAll();
         $icones = self::listaIcones();
        $this->render('help_faq/pergunta_form', [
            'pergunta'   => null,
            'categorias' => $categorias,
            'modo'       => 'criar',
            'icones'     => $icones,
        ], 'admin');
    }

    // GET /admin/help-faq/pergunta/editar/:id  (fallback sem drawer)
    public function editarPergunta(int $id): void {
        $pergunta   = $this->faqModel->getById($id);
        $categorias = $this->categoriaModel->getAll();
        if (!$pergunta) { $this->notFound(); return; }
        $icones = self::listaIcones();

        $this->render('help_faq/pergunta_form', [
            'pergunta'   => $pergunta,
            'categorias' => $categorias,
            'modo'       => 'editar',
            'icones'     => $icones,
        ], 'admin');
    }

    // =====================================================================
    // PERGUNTAS — AJAX / drawer
    // =====================================================================

    // GET /admin/help-faq/pergunta/form[?id=X&categoria_id=Y]
    public function formPergunta(): void {
        try {
            $id          = isset($_GET['id'])           ? (int)$_GET['id']           : 0;
            $categoriaId = isset($_GET['categoria_id']) ? (int)$_GET['categoria_id'] : 0;
            $pergunta    = $id > 0 ? $this->faqModel->getById($id) : null;
            $categorias  = $this->categoriaModel->getAll();
            $modo = 'editar';

            if ($id > 0 && !$pergunta) {
                $this->json(['ok' => false, 'msg' => 'Pergunta não encontrada.']);
                return;
            }

            $ajax = true;

            ob_start();
            include ADMIN_PATH.'/views/help_faq/pergunta_form.php';
            $html = ob_get_clean();

            $this->json(['ok' => true, 'html' => $html]);

        } catch (Exception $e) {
            LogService::error('help_form_pergunta', ['erro' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro interno ao carregar formulário.']);
        }
    }

    // POST /admin/help-faq/pergunta/salvar
    public function salvarPergunta(): void {
        $this->verifyCsrf();
        $isAjax = $this->isAjax();

        $id          = (int)($_POST['id']           ?? 0);
        $categoriaId = (int)($_POST['categoria_id'] ?? 0);
        $pergunta    = trim($_POST['pergunta']       ?? '');
        $resposta    = trim($_POST['resposta']       ?? '');
        $ordem       = (int)($_POST['ordem']         ?? 0);
        $ativo       = isset($_POST['ativo'])        ? 1 : 0;

        if (empty($pergunta) || empty($resposta) || $categoriaId === 0) {
            $msg = 'Preencha todos os campos obrigatórios.';
            if ($isAjax) { $this->json(['ok' => false, 'msg' => $msg]); return; }
            $_SESSION['flash_error'] = $msg;
            header('Location: /admin/help-faq/pergunta/' . ($id ? "editar/$id" : 'nova'));
            exit;
        }

        try {
            $data = [
                'categoria_id' => $categoriaId,
                'pergunta'     => $pergunta,
                'resposta'     => $resposta,
                'ordem'        => $ordem,
                'ativo'        => $ativo,
            ];

            if ($id > 0) {
                $this->faqModel->update($id, $data);
                LogService::audit('help_pergunta_update', ['id' => $id]);
            } else {
                $id = $this->faqModel->create($data);
                LogService::audit('help_pergunta_create', ['id' => $id, 'categoria_id' => $categoriaId]);
            }

            if ($isAjax) {
                $p   = $this->faqModel->getById($id);
                $cat = $this->categoriaModel->getById((int)$p['categoria_id']);
                $this->json([
                    'ok'       => true,
                    'msg'      => isset($_POST['id']) ? 'Pergunta atualizada!' : 'Pergunta criada!',
                    'pergunta' => [
                        'id'               => (int)$p['id'],
                        'categoria_id'     => (int)$p['categoria_id'],
                        'categoria_nome'   => $cat['nome'] ?? '',
                        'pergunta'         => $p['pergunta'],
                        'resposta_preview' => mb_substr(strip_tags($p['resposta']), 0, 90),
                        'ordem'            => (int)$p['ordem'],
                        'ativo'            => (int)$p['ativo'],
                    ],
                ]);
                return;
            }

            $_SESSION['flash_success'] = 'Pergunta salva com sucesso.';

        } catch (Exception $e) {
            LogService::error('help_pergunta_save', ['id' => $id, 'erro' => $e->getMessage()]);
            if ($isAjax) { $this->json(['ok' => false, 'msg' => 'Erro ao salvar pergunta.']); return; }
            $_SESSION['flash_error'] = 'Erro ao salvar pergunta.';
        }

        header('Location: /admin/help-faq/perguntas');
        exit;
    }

    // POST /admin/help-faq/pergunta/excluir
    public function excluirPergunta(): void {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);

        try {
            $perg = $this->faqModel->getById($id);
            if (!$perg) {
                $this->json(['ok' => false, 'msg' => 'Pergunta não encontrada.']);
                return;
            }

            $this->faqModel->delete($id);
            LogService::audit('help_pergunta_delete', ['id' => $id]);
            $this->json(['ok' => true, 'msg' => 'Pergunta excluída.']);

        } catch (Exception $e) {
            LogService::error('help_pergunta_delete', ['id' => $id, 'erro' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro ao excluir.']);
        }
    }

    // =====================================================================
    // Helpers privados
    // =====================================================================

    private function isAjax(): bool {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    private function notFound(): void {
        http_response_code(404);
        $this->render('errors/404', [], 'admin');
    }

    private static function listaIcones(): array {
        return [
            'truck'            => 'Entrega',
            'card'      => 'Pagamento',
            'undo'             => 'Troca',
            'engine'             => 'Peças',
            'person-circle'    => 'Conta',
            'shield-check'     => 'Garantia',
            'question-circle'  => 'Dúvida',
            'package'         => 'Produto',
            'bucket-check'        => 'Compra',
            'contact-support-2'        => 'Contato',
            'business-messages'        => 'Chat',
            'star'             => 'Avaliação',
            'shield'             => 'Segurança',
            'edit-location-alt'          => 'Endereço',
            'build-circle'=> 'Técnico',
            'docs'        => 'Documentos',
            'assignment-late'        => 'Urgente',
            'headphones'          => 'Suporte',
        ];
    }
}