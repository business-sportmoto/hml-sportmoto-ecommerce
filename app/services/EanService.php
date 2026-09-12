<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════════════════
// app/services/EanService.php
//
// O código de barras do produto (GTIN/EAN) — validação e dono.
//
// ── POR QUE VALIDAR DE VERDADE ─────────────────────────────────────
//
// EAN não é texto livre: o último dígito é conferência dos anteriores
// (padrão GS1). Um dígito trocado ao copiar da etiqueta passa despercebido
// no cadastro e só aparece semanas depois, quando o Google Shopping recusa a
// oferta ou o marketplace casa o produto errado. Conferir custa dez linhas
// aqui e evita esse retrabalho.
//
// Tamanhos aceitos: EAN-8, UPC-A (12), EAN-13 e GTIN-14 (caixa fechada).
//
// ── ONDE O EAN MORA ────────────────────────────────────────────────
//
// Dois lugares, espelhando o modelo do Bling:
//
//   produto simples  → produtos.ean
//   com variação     → produto_skus.ean (um por variação)
//
// Por isso a checagem de duplicado olha as DUAS tabelas: o índice único do
// banco protege cada uma isoladamente, e um mesmo código nas duas passaria.
// ════════════════════════════════════════════════════════════════════

final class EanService
{
    public const TAMANHOS = [8, 12, 13, 14];

    /** Só os dígitos. Vazio vira null — nunca string vazia (o índice é único). */
    public static function normalizar(?string $bruto): ?string
    {
        $so = preg_replace('/\D+/', '', (string) $bruto) ?? '';
        return $so === '' ? null : $so;
    }

    /** Tamanho aceito e dígito verificador conferido. */
    public static function valido(string $ean): bool
    {
        if (!in_array(strlen($ean), self::TAMANHOS, true)) return false;
        return self::digitoVerificador(substr($ean, 0, -1)) === (int) substr($ean, -1);
    }

    /**
     * Dígito verificador GS1: da direita para a esquerda, peso 3 e 1
     * alternados; o dígito é o que falta para o próximo múltiplo de 10.
     */
    public static function digitoVerificador(string $semDigito): int
    {
        $soma = 0;
        $peso = 3;
        for ($i = strlen($semDigito) - 1; $i >= 0; $i--) {
            $soma += ((int) $semDigito[$i]) * $peso;
            $peso  = $peso === 3 ? 1 : 3;
        }
        return (10 - ($soma % 10)) % 10;
    }

    /**
     * O que há de errado com o que a pessoa digitou.
     *
     * @return string|null null = pode gravar (inclusive vazio, que é NULL)
     */
    public static function problema(?string $bruto): ?string
    {
        $bruto = trim((string) $bruto);
        if ($bruto === '') return null;

        $ean = self::normalizar($bruto);
        if ($ean === null) return 'O EAN deve conter apenas números.';

        if (!in_array(strlen($ean), self::TAMANHOS, true)) {
            return 'EAN com ' . strlen($ean) . ' dígitos. Use 8, 12, 13 (o mais comum) ou 14.';
        }

        if (!self::valido($ean)) {
            $certo = self::digitoVerificador(substr($ean, 0, -1));
            return 'Dígito verificador não confere — confira a digitação. '
                 . 'Para ' . substr($ean, 0, -1) . ' o último dígito seria ' . $certo . '.';
        }

        return null;
    }

    /**
     * Quem já usa este EAN — nas duas tabelas.
     *
     * @param  int|null $ignorarProdutoId  o produto que está sendo salvo
     * @param  int|null $ignorarSkuId      a variação que está sendo salva
     * @return array{onde:string, produto_id:int, nome:string}|null
     */
    public static function dono(
        string $ean,
        ?int   $ignorarProdutoId = null,
        ?int   $ignorarSkuId     = null,
        ?PDO   $db               = null
    ): ?array {
        $db  = $db ?? Database::getInstance()->getConnection();

        $st = $db->prepare(
            "SELECT id, nome FROM produtos
              WHERE ean = ? AND deleted_at IS NULL AND id <> ? LIMIT 1"
        );
        $st->execute([$ean, (int) $ignorarProdutoId]);
        if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
            return ['onde' => 'produto', 'produto_id' => (int) $p['id'], 'nome' => (string) $p['nome']];
        }

        $st = $db->prepare(
            "SELECT s.id, s.sku, p.id AS produto_id, p.nome
               FROM produto_skus s
               JOIN produtos p ON p.id = s.produto_id AND p.deleted_at IS NULL
              WHERE s.ean = ? AND s.id <> ? LIMIT 1"
        );
        $st->execute([$ean, (int) $ignorarSkuId]);
        if ($s = $st->fetch(PDO::FETCH_ASSOC)) {
            return [
                'onde'       => 'variação ' . $s['sku'],
                'produto_id' => (int) $s['produto_id'],
                'nome'       => (string) $s['nome'],
            ];
        }

        return null;
    }

    /** Mensagem pronta para a tela, quando o EAN já é de outro. */
    public static function mensagemDuplicado(string $ean, array $dono): string
    {
        return "O EAN {$ean} já está em \"{$dono['nome']}\" (#{$dono['produto_id']}, {$dono['onde']}). "
             . 'Código de barras não se repete entre produtos.';
    }
}
