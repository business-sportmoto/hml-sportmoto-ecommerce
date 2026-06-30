<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/CouponController.php
//
// Endpoints AJAX consumidos por carrinho e checkout.
// Controller apenas recebe, delega e formata resposta.
// Nenhuma lógica financeira aqui.
// ════════════════════════════════════════════════════════

class CouponController extends Controller {

    private CouponService $service;
    private Cart          $cartModel;

    public function __construct() {
        // parent::__construct();
        $this->service   = new CouponService();
        $this->cartModel = new Cart();
    }

    // ── POST /cupom/aplicar ──────────────────────────────
    public function aplicar(): void {
        $this->verifyCsrf();

        $codigo     = strtoupper(trim($_POST['codigo'] ?? ''));
        $origem     = SecurityHelper::sanitizeString($_POST['origem'] ?? 'carrinho');
        $clienteId  = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;

        if (empty($codigo)) {
            $this->json(['ok' => false, 'msg' => 'Informe o código do cupom.']);
        }

        // Sanitiza origem
        if (!in_array($origem, ['carrinho','checkout','api'], true)) $origem = 'carrinho';

        // Carrega itens do carrinho com dados necessários para validação
        $itens    = $this->cartModel->getItensParaCupom($clienteId);
        $totais   = $this->cartModel->calcularTotais($itens);
        $subtotal = (float)($totais['subtotal'] ?? 0);
        $frete    = (float)(Session::get('checkout_state')['frete']['valor'] ?? 0);

        $resultado = $this->service->validar(
            $codigo, $itens, $subtotal, $frete, $clienteId,
            ['origem' => $origem]
        );

        if (!$resultado['ok']) {
            $this->json(['ok' => false, 'msg' => $resultado['msg'], 'codigo_erro' => $resultado['codigo_erro'] ?? null]);
        }

        // Salva cupom aplicado no estado do checkout
        $state = new CheckoutState();
        $state->setCupom(
            $resultado['cupom']['codigo'],
            $resultado['desconto'] + $resultado['frete_desconto'],
            $resultado['regra']
        );

        // Guarda dados completos do cupom em sessão (para re-validação)
        Session::set('cupom_aplicado', [
            'codigo'          => $resultado['cupom']['codigo'],
            'cupom_id'        => $resultado['cupom']['id'],
            'desconto'        => $resultado['desconto'],
            'frete_desconto'  => $resultado['frete_desconto'],
            'tipo'            => $resultado['regra'],
            'aplicado_em'     => time(),
        ]);

        $this->json([
            'ok'             => true,
            'msg'            => $resultado['msg'],
            'cupom'          => $resultado['cupom'],
            'desconto'       => $resultado['desconto'],
            'desconto_fmt'   => PriceHelper::format($resultado['desconto']),
            'frete_desconto' => $resultado['frete_desconto'],
            'frete_desc_fmt' => $resultado['frete_desconto'] > 0 ? 'GRÁTIS' : null,
            'total_desconto_fmt' => PriceHelper::format($resultado['desconto'] + $resultado['frete_desconto']),
            'itens'          => $resultado['itens'],
        ]);
    }

    // ── POST /cupom/remover ──────────────────────────────
    public function remover(): void {
        $this->verifyCsrf();

        Session::remove('cupom_aplicado');
        $state = new CheckoutState();
        $state->removerCupom();

        $this->json(['ok' => true, 'msg' => 'Cupom removido.']);
    }

    // ── POST /cupom/revalidar ────────────────────────────
    // Chamado automaticamente quando o carrinho é alterado
    public function revalidar(): void {
        $this->verifyCsrf();

        $cupomSession = Session::get('cupom_aplicado');
        if (!$cupomSession) {
            $this->json(['ok' => true, 'valido' => false, 'msg' => '']);
        }

        $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
        $itens     = $this->cartModel->getItensParaCupom($clienteId);
        $totais    = $this->cartModel->calcularTotais($itens);
        $subtotal  = (float)($totais['subtotal'] ?? 0);
        $frete     = (float)(Session::get('checkout_state')['frete']['valor'] ?? 0);

        $resultado = $this->service->validar(
            $cupomSession['codigo'], $itens, $subtotal, $frete, $clienteId,
            ['origem' => 'revalidar']
        );

        if (!$resultado['ok']) {
            // Cupom ficou inválido — remove da sessão e notifica
            Session::remove('cupom_aplicado');
            $state = new CheckoutState();
            $state->removerCupom();

            $this->json([
                'ok'     => true,
                'valido' => false,
                'msg'    => 'O carrinho foi alterado e o cupom não é mais válido: ' . $resultado['msg'],
            ]);
        }

        // Atualiza desconto na sessão
        Session::set('cupom_aplicado', array_merge($cupomSession, [
            'desconto'       => $resultado['desconto'],
            'frete_desconto' => $resultado['frete_desconto'],
        ]));

        $this->json([
            'ok'             => true,
            'valido'         => true,
            'desconto'       => $resultado['desconto'],
            'desconto_fmt'   => PriceHelper::format($resultado['desconto']),
            'frete_desconto' => $resultado['frete_desconto'],
        ]);
    }
}


// ════════════════════════════════════════════════════════
// MÉTODO A ADICIONAR em app/models/Cart.php
// ════════════════════════════════════════════════════════
/*
public function getItensParaCupom(?int $clienteId): array {
    $itens = $this->getItensComVariacoes($clienteId ?? 0);
    // Normaliza para o formato esperado pelo CouponService
    return array_map(function ($item) {
        return [
            'id'           => $item['item_id'] ?? $item['id'],
            'produto_id'   => (int)$item['produto_id'],
            'preco'        => (float)($item['valor_unitario'] ?? $item['preco'] ?? 0),
            'qtd'          => (int)$item['quantidade'],
            'categoria_id' => (int)($item['categoria_id'] ?? 0),
            'marca_id'     => (int)($item['marca_id']     ?? 0),
            'em_promocao'  => !empty($item['preco_promo']) && $item['preco_promo'] < $item['preco'],
        ];
    }, $itens);
}
*/