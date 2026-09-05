<?php
/**
 * admin/controllers/EmailCsvImportAdminController.php
 *
 * Controller do fluxo completo de importação CSV:
 *  - upload + análise
 *  - mapeamento + opções
 *  - enfileiramento
 *  - acompanhamento de progresso (AJAX polling)
 *  - cancelamento
 *  - download do relatório de erros
 */
class EmailCsvImportAdminController extends Controller
{
    /** @var CsvImportService */
    private $svc;
    /** @var EmailImport */
    private $model;

    public function __construct()
    {
        // parent::__construct();
        $this->requirePermission();
        $this->svc = new CsvImportService();
        $this->model = new EmailImport();
    }

    private function requirePermission(): void
    {
        // A cascata mora no AuthHelper agora — ver o porquê lá.
        AuthHelper::requirePermissaoOuNivel('email_marketing', 'super', 'gerente');
    }

    /** Listagem de todas as importações. */
    public function index()
    {
        $filtros = ['status' => $_GET['status'] ?? ''];
        $pagina  = max(1, (int)($_GET['pagina'] ?? 1));
        $resultado = $this->model->listar($filtros, $pagina, 30);

        $this->render('email-marketing/csv/index', [
            'resultado' => $resultado,
            'filtros'   => $filtros,
            'titulo'    => 'Importações CSV',
        ], 'admin');
    }

    /** Tela inicial de upload (passo 1). */
    public function novo()
    {
        $listas = (new EmailList())->all(true);
        $this->render('email-marketing/csv/novo', [
            'listas' => $listas,
            'titulo' => 'Nova importação CSV',
        ], 'admin');
    }

    /** Recebe upload e devolve preview + headers para mapeamento. */
    public function upload()
    {
        $this->verifyCsrf();
        try {
            $r = $this->svc->receberUpload(
                $_FILES['arquivo'] ?? [],
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
            );
            if (class_exists('LogService')) {
                LogService::audit('email_csv_upload', ['importacao_id' => $r['importacao_id']]);
            }
            return $this->json(['ok' => true] + $r);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    /** Confirma mapeamento e opções (passo 2). Enfileira para o worker. */
    public function confirmar()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['importacao_id'] ?? 0);
        $mapeamento = $_POST['mapeamento'] ?? [];
        $opcoes = [
            'origem'                  => $_POST['origem'] ?? 'importacao',
            'base_legal'              => $_POST['base_legal'] ?? 'consentimento',
            'lista_id'                => !empty($_POST['lista_id']) ? (int)$_POST['lista_id'] : null,
            'criar_lista'             => !empty($_POST['criar_lista']) ? 1 : 0,
            'nome_nova_lista'         => trim((string)($_POST['nome_nova_lista'] ?? '')),
            'atualizar_existentes'    => !empty($_POST['atualizar_existentes']) ? 1 : 0,
            'ignorar_suprimidos'      => !empty($_POST['ignorar_suprimidos']) ? 1 : 0,
            'registrar_consentimento' => !empty($_POST['registrar_consentimento']) ? 1 : 0,
        ];

        // converte mapeamento "" para null
        $map = [];
        foreach ($mapeamento as $k => $v) {
            if ($v === '' || $v === null) continue;
            $map[$k] = (int)$v;
        }

        try {
            $this->svc->confirmarConfiguracao($id, $map, $opcoes);
            if (class_exists('LogService')) {
                LogService::audit('email_csv_confirmar', ['importacao_id' => $id, 'opcoes' => $opcoes]);
            }
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    /** Tela de acompanhamento de uma importação específica. */
    public function detalhes($id)
    {
        $id = (int)$id;
        $imp = $this->model->find($id);
        if (!$imp) {
            header('Location: ' . BASE_URL . '/admin/email-marketing/csv');
            exit;
        }

        $pagina = max(1, (int)($_GET['pagina_erros'] ?? 1));
        $erros = $this->model->erros($id, $pagina, 100);

        $this->render('email-marketing/csv/detalhes', [
            'imp' => $imp,
            'erros' => $erros,
            'titulo' => 'Importação #' . $id,
        ], 'admin');
    }

    /** Endpoint AJAX de progresso (polling). */
    public function progresso($id)
    {
        // CSRF NÃO é necessário pra GET de polling
        $id = (int)$id;
        $r = $this->model->progresso($id);
        if (!$r) return $this->json(['ok' => false, 'erro' => 'Importação não encontrada']);
        return $this->json(['ok' => true, 'progresso' => $r]);
    }

    public function cancelar()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        try {
            $ok = $this->model->cancelar($id);
            if (class_exists('LogService')) {
                LogService::audit('email_csv_cancelar', ['importacao_id' => $id]);
            }
            return $this->json(['ok' => $ok]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function baixarErros($id)
    {
        $id = (int)$id;
        $imp = $this->model->find($id);
        if (!$imp) {
            header('HTTP/1.1 404 Not Found');
            echo 'Importação não encontrada';
            return;
        }

        $path = $imp['erros_arquivo_path'];
        if (!$path || !is_file($path)) {
            try {
                $path = $this->svc->gerarRelatorioErros($id);
            } catch (Throwable $e) {
                header('HTTP/1.1 500 Internal Server Error');
                echo 'Erro ao gerar relatório: ' . htmlspecialchars($e->getMessage());
                return;
            }
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="erros_importacao_' . $id . '.csv"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
