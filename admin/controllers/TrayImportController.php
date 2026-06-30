<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/TrayImportController.php
// ════════════════════════════════════════════════════════

class TrayImportController extends Controller {

    private TrayImportService        $service;
    private TrayClienteImportService $serviceClientes;
    private TrayPedidoImportService  $servicePedidos;

    public function __construct() {
        // parent::__construct();
        AuthHelper::requireAdmin();
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->service         = new TrayImportService();
        $this->serviceClientes = new TrayClienteImportService();
        $this->servicePedidos  = new TrayPedidoImportService();
    }

    /** GET /admin/importar */
    public function index(): void {
        // Jobs recentes
        $db   = Database::getInstance()->getConnection();
        $jobs = $db->query(
            "SELECT * FROM import_jobs ORDER BY criado_em DESC LIMIT 10"
        )->fetchAll();

        $fila = $this->service->getStatusFila();

        $this->render('importar/index', compact('jobs', 'fila'), 'admin');
    }

    /** POST /admin/importar/upload */
    public function upload(): void {
        $this->verifyCsrf();

        try {
            $tipo = SecurityHelper::sanitizeString($_POST['tipo'] ?? '');
            if (!in_array($tipo, ['produtos', 'variacoes', 'clientes', 'pedidos'])) {
                $this->json(['ok' => false, 'msg' => 'Tipo inválido.']);
                throw new Exception('Tipo inválido.');
            }

            $adminId = (int)Session::get('admin_id');

            if ($tipo === 'pedidos') {
                if (empty($_FILES['csv_pedidos']['tmp_name']) || empty($_FILES['csv_produtos']['tmp_name'])) {
                    // $this->json(['ok' => false, 'msg' => 'Envie os dois arquivos: pedidos e produtos vendidos.']);

                    throw new Exception('Envie os dois arquivos: pedidos e produtos vendidos.');
                }
                $this->json($this->servicePedidos->criarJob(
                    $_FILES['csv_pedidos'],
                    $_FILES['csv_produtos'],
                    $adminId
                ));
            }

            if (empty($_FILES['csv']['tmp_name'])) {
                // $this->json(['ok' => false, 'msg' => 'Nenhum arquivo enviado.']);
                throw new Exception('Nenhum arquivo enviado.');
            }
            $result = $tipo === 'clientes'
                ? $this->serviceClientes->criarJob($_FILES['csv'], $adminId)
                : $this->service->criarJob($_FILES['csv'], $tipo, $adminId);
            $this->json($result);
        } catch (\Throwable $e) {
            LogService::error('Falha ao executar automação', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);
        }
    }

    /** GET /admin/importar/preview?job_id=&tipo= */
    public function preview(): void {
        $jobId = (int)($_GET['job_id'] ?? 0);
        $tipo  = SecurityHelper::sanitizeString($_GET['tipo'] ?? '');
        $result = match($tipo) {
            'clientes' => $this->serviceClientes->preview($jobId),
            'pedidos'  => $this->servicePedidos->preview($jobId),
            default    => $this->service->preview($jobId),
        };
        $this->json($result);
    }

    /** POST /admin/importar/chunk */
    public function chunk(): void {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $tipo  = SecurityHelper::sanitizeString($_POST['tipo'] ?? '');
        if (!$jobId) $this->json(['ok' => false, 'msg' => 'job_id inválido.']);
        $result = match($tipo) {
            'clientes' => $this->serviceClientes->processarChunk($jobId),
            'pedidos'  => $this->servicePedidos->processarChunk($jobId),
            default    => $this->service->processarChunk($jobId),
        };
        $this->json($result);
    }

    /** GET /admin/importar/status?job_id= */
    public function status(): void {
        $jobId = (int)($_GET['job_id'] ?? 0);
        $job   = $this->service->getJob($jobId);
        if (!$job) $this->json(['ok' => false, 'msg' => 'Job não encontrado.']);
        $this->json(['ok' => true, 'job' => $job]);
    }

    /** POST /admin/importar/processar-imagens */
    public function processarImagens(): void {
        $limite = min(100, (int)($_POST['limite'] ?? 30));
        $this->json($this->service->processarFilaImagens($limite));
    }
}