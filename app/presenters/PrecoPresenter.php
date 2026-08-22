<?php
// app/presenters/PrecoPresenter.php
// Normalização de dinheiro para o app.
//
// Regra inegociável: valor monetário sai da API como STRING DECIMAL ("329.90"),
// nunca como float JSON. Float em JavaScript acumula erro de ponto flutuante e
// isso aparece justamente onde dói — no parcelamento e no total do carrinho.
// O app usa Intl.NumberFormat('pt-BR') para exibir e centavos inteiros para
// qualquer aritmética.

final class PrecoPresenter
{
    /** Valor monetário como string decimal de 2 casas. Null continua null. */
    public static function dec(int|float|string|null $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        return number_format((float)$valor, 2, '.', '');
    }

    /** O mesmo valor em centavos — para a aritmética do app e do gateway. */
    public static function centavos(int|float|string|null $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        return (int)round((float)$valor * 100);
    }

    /**
     * Bloco de preço do card e da vitrine.
     *
     * Diferença deliberada em relação a views/partials/product-card.php:3-5:
     * o card da web decide a promoção só por `preco_promo < preco`, ignorando
     * promo_inicio/promo_fim. Aqui usamos PriceHelper::currentPrice(), que
     * respeita a janela — é o que as colunas existem para fazer, e é o mesmo
     * critério do filtro `em_promocao` em Product::buildFilters().
     */
    public static function bloco(array $produto): array
    {
        $precoCheio = (float)($produto['preco'] ?? 0);
        $precoAtual = PriceHelper::currentPrice($produto);
        $temPromo   = $precoAtual < $precoCheio && $precoCheio > 0;

        // preco_min/preco_max vêm das subqueries de produto_skus (apenas SKUs
        // ativos e com estoque). Só há faixa real quando diferem entre si.
        $min      = isset($produto['preco_min']) ? (float)$produto['preco_min'] : null;
        $max      = isset($produto['preco_max']) ? (float)$produto['preco_max'] : null;
        $temFaixa = $min !== null && $max !== null && $min > 0 && $min != $max;

        // Produto com variação parcela sobre o menor preço; produto simples,
        // sobre o preço vigente. Mesma escolha do card da web (:16).
        $baseParcela = $temFaixa ? $min : $precoAtual;

        return [
            'de'            => $temPromo ? self::dec($precoCheio) : null,
            'por'           => self::dec($precoAtual),
            'por_centavos'  => self::centavos($precoAtual),
            'desconto_pct'  => $temPromo ? PriceHelper::discountPercent($precoCheio, $precoAtual) : 0,
            'tem_promo'     => $temPromo,
            'min'           => $temFaixa ? self::dec($min) : null,
            'max'           => $temFaixa ? self::dec($max) : null,
            'tem_faixa'     => $temFaixa,
            'parcelamento'  => self::parcelamento($baseParcela),
        ];
    }

    /**
     * Melhor parcelamento disponível (o maior número de parcelas que respeita
     * o valor mínimo). É o que o card mostra: "10x de R$ 32,99 sem juros".
     */
    public static function parcelamento(float $total): ?array
    {
        if ($total <= 0) {
            return null;
        }

        $opcoes = PriceHelper::installments($total);
        if (!$opcoes) {
            return null;
        }

        $melhor = end($opcoes);

        return [
            'vezes'     => (int)$melhor['parcelas'],
            'valor'     => self::dec($melhor['valor_parcela']),
            'sem_juros' => !$melhor['tem_juros'],
            'texto'     => $melhor['label'],
        ];
    }

    /** Todas as opções de parcelamento — para o select do checkout. */
    public static function opcoesParcelamento(float $total): array
    {
        if ($total <= 0) {
            return [];
        }

        return array_map(static fn(array $o) => [
            'vezes'     => (int)$o['parcelas'],
            'valor'     => self::dec($o['valor_parcela']),
            'total'     => self::dec($o['total']),
            'sem_juros' => !$o['tem_juros'],
            'texto'     => $o['label'],
        ], PriceHelper::installments($total));
    }
}
