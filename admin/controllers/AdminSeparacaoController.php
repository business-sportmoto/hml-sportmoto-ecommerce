<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/AdminSeparacaoController.php
//
// Painel de separacao / checkout de expedicao.
// ════════════════════════════════════════════════════════

class AdminSeparacaoController extends Controller
{
    private SeparacaoService $separacao;
    private AdminPedidoService $pedidos;

    public function __construct()
    {
        AuthHelper::requireAdmin();
        // Quem separa e expedicao: vendedor entra, editor nao.
        AuthHelper::requireAdminLevel('super', 'gerente', 'vendedor', 'estoque');
        $this->separacao = new SeparacaoService();
        $this->pedidos   = new AdminPedidoService();
    }

    // ── GET /admin/pedidos/checkout ───────────────────────
    public function index(): void
    {
        $busca = SecurityHelper::sanitizeString($_GET['busca'] ?? '');
        $this->render('pedidos/checkout', [
            'page_title' => 'Checkout de expedição',
            'fila'       => $this->separacao->fila(['busca' => $busca]),
            'busca'      => $busca,
        ], 'admin');
    }

    // ── GET /admin/pedidos/checkout/imprimir?ids=1,2,3 ────
    //
    // Pagina propria (sem o layout do painel): sai direto na termica 80mm.
    // A troca de status acontece aqui, no ato de imprimir, e nao num POST
    // separado — se o operador fechar a janela, o pedido ja saiu da fila e
    // nao vai ser separado duas vezes.
    public function imprimir(): void
    {
        $ids = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))));
        if (!$ids) {
            $this->render('pedidos/checkout-imprimir', [
                'page_title' => 'Impressão',
                'pedidos' => [], 'erro' => 'Nenhum pedido informado.',
            ], 'impressao');
            return;
        }

        $pedidos = $this->separacao->paraImpressao($ids);
        $mov     = $this->separacao->marcarEmSeparacao($ids, (int) AuthHelper::usuarioId());

        $this->render('pedidos/checkout-imprimir', [
            'page_title' => 'Separação — ' . count($pedidos) . ' pedido(s)',
            'pedidos'    => $pedidos,
            'movidos'    => $mov['movidos'] ?? [],
            'ignorados'  => $mov['ignorados'] ?? [],
            'erro'       => null,
        ], 'impressao');
    }


    // ── GET /admin/pedidos/checkout/estacao ───────────────
    //
    // Estacao de bipagem: uma tela so, o operador bipa e o pedido aparece.
    // Layout proprio (sem sidebar) porque isso fica aberto o dia inteiro numa
    // maquina de bancada — cada pixel de menu e espaco que nao vira pedido.
    public function estacao(): void
    {
        $this->render('pedidos/checkout-estacao', [
            'page_title' => 'Estação de bipagem',
            'metodos'    => $this->separacao->metodosDeEnvio(),
        ], 'admin');
    }

    // ── POST /admin/pedidos/checkout/estacao/buscar ───────
    public function estacaoBuscar(): void
    {
        $this->verifyCsrf();
        $this->json($this->separacao->buscarPorCodigo(
            SecurityHelper::sanitizeString($_POST['codigo'] ?? '')
        ));
    }

    // ── GET /admin/pedidos/checkout/{id} ──────────────────
    public function conferir(int $id): void
    {
        $pedido = $this->separacao->paraConferencia($id);
        if (!$pedido) $this->notFound();

        $this->render('pedidos/checkout-pedido', [
            'page_title' => 'Conferência do pedido ' . $pedido['codigo'],
            'pedido'     => $pedido,
            'etiquetaOk' => $this->separacao->podeGerarEtiqueta($id),
        ], 'admin');
    }


    // ── GET /admin/pedidos/checkout/{id}/nf ───────────────
    //
    // NF simplificada (romaneio) na mesma termica da etiqueta de separacao.
    // Nao e documento fiscal: e o comprovante que vai dentro da caixa.
    public function imprimirNf(int $id): void
    {
        $pedido = $this->separacao->paraConferencia($id);
        if (!$pedido) $this->notFound();

        $this->render('pedidos/checkout-nf', [
            'page_title' => 'NF simplificada — pedido ' . $pedido['codigo'],
            'pedido'     => $pedido,
            'loja'       => [
                'nome'     => ConfigHelper::get('site_nome', 'Loja'),
                'cnpj'     => ConfigHelper::get('site_cnpj', ''),
                'telefone' => ConfigHelper::get('site_telefone', ''),
                'email'    => ConfigHelper::get('site_email', ''),
            ],
        ], 'impressao');
    }

    // ── POST /admin/pedidos/checkout/bipar ────────────────
    public function bipar(): void
    {
        $this->verifyCsrf();
        $pedidoId = (int) ($_POST['pedido_id'] ?? 0);
        $codigo   = SecurityHelper::sanitizeString($_POST['codigo'] ?? '');
        $this->json($this->separacao->resolverCodigo($pedidoId, $codigo));
    }

    // ── POST /admin/pedidos/checkout/{id}/etiqueta ────────
    //
    // Mesma emissao da tela de pedidos, com a trava da NF-e por cima.
    public function gerarEtiqueta(int $id): void
    {
        $this->verifyCsrf();

        $pode = $this->separacao->podeGerarEtiqueta($id);
        if (empty($pode['ok'])) $this->json($pode);

        $this->json($this->pedidos->gerarEtiqueta($id, [
            'transportadora_id' => (int) ($_POST['transportadora_id'] ?? 0),
            'servico_codigo'    => SecurityHelper::sanitizeString($_POST['servico_codigo'] ?? ''),
            'servico_nome'      => SecurityHelper::sanitizeString($_POST['servico_nome'] ?? ''),
        ], AuthHelper::usuarioId()));
    }
}
