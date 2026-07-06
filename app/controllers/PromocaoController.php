<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/PromocaoController.php
// Endpoints públicos de promoções consumidos pelo front.
// ════════════════════════════════════════════════════════

class PromocaoController extends Controller {

    private PromocaoService $service;
    private Cart            $cart;

    public function __construct() {
        $this->service = new PromocaoService();
        $this->cart    = new Cart();
    }

    /**
     * GET /promocoes/preview
     * Retorna cards de preview das promoções ativas para o carrinho.
     * Chamado via AJAX sempre que o carrinho muda (item adicionado,
     * quantidade alterada, etc).
     *
     * Rate limit leve: previne poll agressivo sem bloquear uso normal.
     */
    public function preview(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (SecurityHelper::rateLimitExceeded('promo_preview_' . md5($ip), 30, 60)) {
            $this->json(['ok' => true, 'cards' => []]);
            return; // defense in depth: garante interrupção mesmo se json() não der exit
        }

        $clienteId = Session::isClienteLogado()
            ? (int)Session::get('cliente_id') : null;

        $itens = $this->cart->getItensParaCupom($clienteId);
        $frete = (float)(Session::get('checkout_state')['frete']['valor'] ?? 0);

        // Subtotal é recalculado DENTRO do previewCarrinho() a partir dos
        // itens enriquecidos (preco × qtd). O calcularTotais() do Cart usa
        // keys diferentes (valor_unitario × quantidade) e retornava 0 aqui.
        // Passamos 0.0 — o valor é ignorado e substituído internamente.
        $subtotal = 0.0;

        // Verifica se é primeira compra (para promoções com essa restrição)
        $primeiraCompra = false;
        if ($clienteId) {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT COUNT(*) FROM pedidos
                 WHERE cliente_id = ? AND status_pagamento = 'aprovado'"
            );
            $st->execute([$clienteId]);
            $primeiraCompra = (int)$st->fetchColumn() === 0;
        }

        $contexto = [
            'primeira_compra' => $primeiraCompra,
            'score'           => (int)(Session::get('cliente_score') ?? 0),
            'tem_cupom'       => !empty(Session::get('cupom_aplicado')),
        ];

        $cards = $this->service->previewCarrinho(
            $itens, $subtotal, $frete, $clienteId, $contexto
        );

        $this->json([
            'ok'    => true,
            'cards' => $cards,
            'total' => count($cards),
        ]);
    }
}