<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/CustoService.php
// ════════════════════════════════════════════════════════

/**
 * Resolve o custo de aquisição de um item no momento da venda.
 *
 * REGRA CENTRAL: custo desconhecido é NULL, nunca 0.
 *
 * Zero significaria "este item deu 100% de margem" — um número que o
 * BI somaria alegremente e ninguém questionaria até virar decisão de
 * preço. NULL propaga: o item fica fora do cálculo de margem e entra
 * na conta de cobertura (`bi_cobertura_custo`), que diz sobre quanto
 * da receita a margem pode de fato ser afirmada.
 *
 * Precedência: produto_skus.custo → produtos.preco_custo → NULL.
 * O SKU vence porque variações têm custos diferentes (um capacete P e
 * um XL não custam o mesmo); o produto é o piso para os 3 de 12
 * produtos que não têm nenhuma linha de SKU.
 */
final class CustoService
{
    /**
     * Resolve custo para vários itens em UMA ida ao banco.
     *
     * O checkout percorre os itens dentro da transação do pedido —
     * uma query por item seguraria locks por mais tempo sem motivo.
     *
     * @param array $itens lista de ['produto_id' => int, 'sku_id' => ?int]
     * @return array chave "produtoId:skuId" => ['custo'=>?float,'origem'=>?string]
     */
    public static function resolverLote(array $itens): array
    {
        if (empty($itens)) return [];

        $skuIds = [];
        $prodIds = [];
        foreach ($itens as $it) {
            $sku  = !empty($it['sku_id'])     ? (int)$it['sku_id']     : null;
            $prod = !empty($it['produto_id']) ? (int)$it['produto_id'] : null;
            if ($sku)  $skuIds[$sku]   = true;
            if ($prod) $prodIds[$prod] = true;
        }

        $db = Database::getInstance()->getConnection();

        $custoSku  = self::buscar($db, 'produto_skus', 'custo',       array_keys($skuIds));
        $custoProd = self::buscar($db, 'produtos',     'preco_custo', array_keys($prodIds));

        $out = [];
        foreach ($itens as $it) {
            $sku  = !empty($it['sku_id'])     ? (int)$it['sku_id']     : null;
            $prod = !empty($it['produto_id']) ? (int)$it['produto_id'] : null;

            $custo  = null;
            $origem = null;

            if ($sku !== null && isset($custoSku[$sku])) {
                $custo  = $custoSku[$sku];
                $origem = 'sku';
            } elseif ($prod !== null && isset($custoProd[$prod])) {
                $custo  = $custoProd[$prod];
                $origem = 'produto';
            }

            $out[self::chave($prod, $sku)] = ['custo' => $custo, 'origem' => $origem];
        }

        return $out;
    }

    /**
     * Versão de um item só. Para o admin (pedido manual), onde o
     * volume não justifica o lote.
     */
    public static function resolver(?int $produtoId, ?int $skuId): array
    {
        $r = self::resolverLote([['produto_id' => $produtoId, 'sku_id' => $skuId]]);
        return $r[self::chave($produtoId, $skuId)] ?? ['custo' => null, 'origem' => null];
    }

    public static function chave(?int $produtoId, ?int $skuId): string
    {
        return ((int)$produtoId) . ':' . ((int)$skuId);
    }

    /**
     * Busca a coluna de custo para um conjunto de ids.
     *
     * Descarta <= 0: no banco existem custos zerados que são "nunca
     * preenchido", não "custo zero de verdade". Tratá-los como custo
     * real produziria 100% de margem — exatamente o erro que este
     * service existe para impedir.
     *
     * @return array<int,float> id => custo
     */
    private static function buscar(PDO $db, string $tabela, string $coluna, array $ids): array
    {
        if (empty($ids)) return [];

        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT id, {$coluna} AS custo FROM {$tabela}
              WHERE id IN ({$ph}) AND {$coluna} IS NOT NULL AND {$coluna} > 0"
        );
        $stmt->execute(array_map('intval', $ids));

        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(int)$r['id']] = (float)$r['custo'];
        }
        return $map;
    }
}
