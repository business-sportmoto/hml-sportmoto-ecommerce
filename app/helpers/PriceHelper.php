<?php
// app/helpers/PriceHelper.php
// Formatação de moeda, cálculo de desconto e parcelamento.

class PriceHelper {

    /**
     * Formata um valor decimal para moeda brasileira.
     * Ex: 1234.5 → "R$ 1.234,50"
     */
    public static function format(float $value): string {
        $symbol = ConfigHelper::get('moeda_simbolo', 'R$');
        return $symbol . ' ' . number_format($value, 2, ',', '.');
    }

    /**
     * Retorna o preço vigente (promocional se ativo, normal caso contrário).
     */
    public static function currentPrice(array $product): float {
        if (!empty($product['preco_promo']) && $product['preco_promo'] > 0) {
            // Verifica se a promoção está dentro do período
            $now   = time();
            $inicio = !empty($product['promo_inicio']) ? strtotime($product['promo_inicio']) : 0;
            $fim    = !empty($product['promo_fim'])    ? strtotime($product['promo_fim'])    : PHP_INT_MAX;

            if ($now >= $inicio && $now <= $fim) {
                return (float) $product['preco_promo'];
            }
        }
        return (float) $product['preco'];
    }

    /**
     * Calcula percentual de desconto entre preço normal e promocional.
     */
    public static function discountPercent(float $normal, float $promo): int {
        if ($normal <= 0 || $promo >= $normal) return 0;
        return (int) round((($normal - $promo) / $normal) * 100);
    }

    /**
     * Calcula o valor de cada parcela e retorna array de opções.
     * Respeita configurações de parcelas mínimas e juros.
     */
    public static function installments(float $total): array {
        $maxParcelas   = (int) ConfigHelper::get('parcelas_max', 12);
        $minValor      = (float) ConfigHelper::get('parcelas_min_valor', 30.00);
        $juros         = (float) ConfigHelper::get('parcelas_juros', 0);
        $result        = [];

        for ($n = 1; $n <= $maxParcelas; $n++) {
            if ($juros > 0 && $n > 1) {
                // Juros compostos
                $valorParcela = $total * ($juros / 100) / (1 - pow(1 + $juros / 100, -$n));
            } else {
                $valorParcela = $total / $n;
            }

            if ($valorParcela < $minValor && $n > 1) break;

            $result[] = [
                'parcelas'      => $n,
                'valor_parcela' => $valorParcela,
                'total'         => $valorParcela * $n,
                'tem_juros'     => $juros > 0 && $n > 1,
                'label'         => $n === 1
                    ? self::format($valorParcela) . ' à vista'
                    : "{$n}x de " . self::format($valorParcela) . ($juros > 0 ? '' : ' sem juros'),
            ];
        }

        return $result;
    }
}


