<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/IAPromptController.php
// ════════════════════════════════════════════════════════

/**
 * Biblioteca de prompts da Central de IA.
 *
 * Mesmo desenho do catálogo de agentes: página com a lista, formulário
 * servido por Ajax dentro do adminDrawer, POST em JSON. As regras moram no
 * IAPromptService; a leitura de imagem, no IALeituraImagemService.
 *
 * Permissão marketing_ia no construtor — nenhum método fica sem guarda.
 */
class IAPromptController extends Controller
{
    private const PERMISSAO = 'marketing_ia';

    private IAPromptTemplate $modelo;
    private IAPromptService  $svc;

    public function __construct()
    {
        $this->exigirPermissao(self::PERMISSAO);
        $this->modelo = new IAPromptTemplate();
        $this->svc    = new IAPromptService($this->modelo);
    }

    // ── GET /admin/ia/prompts ─────────────────────────────
    public function index(): void
    {
        $filtros = [
            'natureza'   => (string) ($_GET['natureza'] ?? ''),
            'capacidade' => (string) ($_GET['capacidade'] ?? ''),
            'origem'     => (string) ($_GET['origem'] ?? ''),
            'busca'      => trim((string) ($_GET['busca'] ?? '')),
        ];

        $this->render('ia/prompts/index', [
            'itens'   => $this->modelo->listarBiblioteca($filtros),
            'resumo'  => $this->modelo->resumo(),
            'filtros' => $filtros,
            'csrf'    => SecurityHelper::generateCsrf(),
        ], 'admin');
    }

    // ── GET /admin/ia/prompts/form?id= | ?geracao_id=&variante= ──
    public function form(): void
    {
        $id        = (int) ($_GET['id'] ?? 0);
        $geracaoId = (int) ($_GET['geracao_id'] ?? 0);

        if ($id > 0) {
            $p = $this->modelo->buscar($id);
            if ($p === null) {
                $this->json(['ok' => false, 'msg' => 'Prompt não encontrado.']);
                return;
            }
            $titulo = 'Editar prompt';
        } elseif ($geracaoId > 0) {
            // O conteúdo sai da leitura GRAVADA — o navegador só indica qual.
            $p = $this->svc->prefillDeLeitura($geracaoId, (string) ($_GET['variante'] ?? 'imagem'));
            if ($p === null) {
                $this->json(['ok' => false, 'msg' => 'Leitura de imagem não encontrada, ou sem prompt desta variante.']);
                return;
            }
            $titulo = 'Salvar prompt lido da imagem';
        } else {
            $p      = null;
            $titulo = 'Novo prompt';
        }

        $html = $this->partial('_form', [
            'p'     => $p,
            'tipos' => $this->svc->tiposElegiveis(),
        ]);
        $this->json(['ok' => true, 'titulo' => $titulo, 'html' => $html]);
    }

    // ── POST /admin/ia/prompts/salvar ─────────────────────
    public function salvar(): void
    {
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();
        $this->json($this->svc->salvar($_POST, AuthHelper::usuarioId()));
    }

    // ── POST /admin/ia/prompts/alternar ───────────────────
    public function alternar(): void
    {
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();
        $this->json($this->svc->alternar((int) ($_POST['id'] ?? 0)));
    }

    // ── POST /admin/ia/prompts/padrao ─────────────────────
    public function padrao(): void
    {
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();
        $this->json($this->svc->definirPadrao((int) ($_POST['id'] ?? 0)));
    }

    // ── POST /admin/ia/prompts/duplicar ───────────────────
    public function duplicar(): void
    {
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();
        $this->json($this->svc->duplicar((int) ($_POST['id'] ?? 0), AuthHelper::usuarioId()));
    }

    // ── POST /admin/ia/prompts/excluir ────────────────────
    public function excluir(): void
    {
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();
        $this->json($this->svc->excluir((int) ($_POST['id'] ?? 0)));
    }

    // ── POST /admin/ia/prompts/ler-imagem ─────────────────
    // multipart com `imagem`, ou `produto_id` para a foto do produto.
    public function lerImagem(): void
    {
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $usuarioId = AuthHelper::usuarioId();
        $leitor    = new IALeituraImagemService();
        $temUpload = !empty($_FILES['imagem'])
                  && (int) ($_FILES['imagem']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        try {
            if ($temUpload) {
                $r = $leitor->lerUpload($_FILES['imagem'], $usuarioId);
            } elseif ((int) ($_POST['produto_id'] ?? 0) > 0) {
                $r = $leitor->lerFotoDoProduto((int) $_POST['produto_id'], $usuarioId);
            } else {
                $this->json(['ok' => false, 'msg' => 'Envie uma imagem ou escolha um produto.']);
                return;
            }
            $this->json(['ok' => true] + $r);
        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'ia', ['acao' => 'ler_imagem']);
            $this->json(['ok' => false, 'msg' => 'Erro ao ler a imagem. O erro foi registrado.']);
        }
    }

    /* ------------------------------------------------------------------ */

    private function partial(string $arquivo, array $dados = []): string
    {
        $arquivo = basename($arquivo);
        $caminho = __DIR__ . '/../views/ia/prompts/' . $arquivo . '.php';
        if (!is_file($caminho)) {
            LogService::error('ia_partial_inexistente', ['arquivo' => $arquivo]);
            return '';
        }
        extract($dados, EXTR_SKIP);
        ob_start();
        include $caminho;
        return (string) ob_get_clean();
    }

    private function exigirPost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->json(['ok' => false, 'msg' => 'Método não permitido.']);
            return false;
        }
        return true;
    }

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
               . '<p style="font:16px system-ui;padding:2rem">Você não tem permissão para acessar a Central de IA.</p>';
        }
        exit;
    }
}
