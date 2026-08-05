<?php
/**
 * FreteVitrineController — frete na página de produto (público, sem admin).
 *
 * GET/POST /frete/produto?cep=&produto_id=&subtotal_atual=
 *   -> cota 1 unidade do produto (com cache + fallback) e devolve o CTA de
 *      frete grátis. subtotal_atual (opcional) = subtotal atual do carrinho,
 *      para o CTA "adicione e ganhe frete grátis".
 */
class FreteVitrineController extends Controller
{
    private FreteVitrineService $frete;

    public function __construct()
    {
        $this->frete = new FreteVitrineService();
    }

    public function produto(): void
    {
        $cep = preg_replace('/\D+/', '', (string)($_POST['cep'] ?? $_GET['cep'] ?? '')) ?? '';
        $produtoId = (int)($_GET['produto_id'] ?? $_POST['produto_id'] ?? 0);
        $subtotalAtual = (float)($_POST['subtotal_atual'] ?? $_GET['subtotal_atual'] ?? 0);

        if (strlen($cep) !== 8) { $this->json(['ok' => false, 'erro' => 'CEP inválido.']); return; }
        if ($produtoId <= 0)    { $this->json(['ok' => false, 'erro' => 'Produto inválido.']); return; }

        $produto = $this->carregarProduto($produtoId);
        if (!$produto) { $this->json(['ok' => false, 'erro' => 'Produto não encontrado.']); return; }

        $item = $this->itemDoProduto($produto); 

        $res = $this->frete->cotar([
            'cep_destino'      => $cep,
            'itens'            => [$item],
            'valor_mercadoria' => $item['valor'],
            'produto_id'       => $produtoId,
            'cta'              => ['subtotal_atual' => $subtotalAtual, 'preco_produto' => $item['valor']],            
        ]);

        $res['debug']=$subtotalAtual;

        $this->json($res);
    }

    /* ---------------- helpers ---------------- */

    private function carregarProduto(int $id): ?array
    {
        if (!class_exists('Product')) return null;
        try {
            $p = (new Product())->find($id);
            return is_array($p) && $p ? $p : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Mapeia o produto para o formato de item que o motor de frete espera. */
    private function itemDoProduto(array $p): array
    {
        $preco = (float)($p['preco_final'] ?? $p['preco_venda'] ?? $p['preco'] ?? $p['valor'] ?? 0);
        $pesoG = (int)round((float)($p['peso_kg'] ?? 0) * 1000);
        if ($pesoG <= 0) $pesoG = (int)($p['peso_g'] ?? 500); // fallback de peso

        return [
            'produto_id'     => (int)($p['id'] ?? 0),
            'quantidade'     => 1,
            'valor'          => $preco,
            'peso_g'         => $pesoG,
            'altura_cm'      => (float)($p['altura_cm'] ?? 0),
            'largura_cm'     => (float)($p['largura_cm'] ?? 0),
            'comprimento_cm' => (float)($p['comprimento_cm'] ?? 0),
            'categoria_id'   => $p['categoria_id'] ?? null,
            'marca_id'       => $p['marca_id'] ?? null,
        ];
    }
}
