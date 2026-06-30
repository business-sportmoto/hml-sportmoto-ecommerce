<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/CustomerCarrinhoController.php
// ════════════════════════════════════════════════════════

class CustomerCarrinhoController extends Controller {

    private CartCompartilhado $model;
    private Cart              $cart;
    private Customer          $customerModel;

    public function __construct() {
        // parent::__construct();
        AuthHelper::requireCustomer();
        $this->model         = new CartCompartilhado();
        $this->cart          = new Cart();
        $this->customerModel = new Customer();
    }

    // ── GET /minha-conta/carrinhos-compartilhados ─────────
    public function index(): void {
        $perfil    = $this->customerModel->getFullProfile($this->clienteId());
        $usuarioId = (int)$perfil['usuario_id'];
        $lista     = $this->model->getByCliente($usuarioId);

        $this->render('customer/carrinhos-compartilhados/index', [
            'perfil' => $perfil,
            'lista'  => $lista,
        ], 'customer');
    }

    // ── GET /minha-conta/carrinhos-compartilhados/{token} ─
    public function show(string $token): void {
        $token     = SecurityHelper::sanitizeString($token);
        $perfil    = $this->customerModel->getFullProfile($this->clienteId());
        $usuarioId = (int)$perfil['usuario_id'];

        $compartilhado = $this->model->findByToken($token, $usuarioId);
        if (!$compartilhado) {
            Session::flash('error', 'Carrinho compartilhado não encontrado.');
            $this->redirect(BASE_URL . '/minha-conta/carrinhos-compartilhados');
        }

        $log   = $this->model->getLog($token, $usuarioId);
        $itens = $this->model->getItensSnapshot($token);

        $this->render('customer/carrinhos-compartilhados/show', [
            'perfil'        => $perfil,
            'compartilhado' => $compartilhado,
            'log'           => $log,
            'itens'         => $itens,
        ], 'customer');
    }

    private function clienteId(): int {
        return (int)Session::getClienteId();
    }
}