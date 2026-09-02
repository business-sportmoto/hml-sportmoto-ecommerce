#!/usr/bin/env php
<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// cli/bling-vinculos.php — vinculação diária de catálogo
//
// Preenche produtos.bling_id e produto_skus.bling_id casando o
// código do Bling com sku_legado / sku do site.
//
// ── Por que separado do bling-sync.php ─────────────────
// Produto SEM bling_id nunca recebe saldo: o sincronizarEstoque()
// só monta o mapa a partir de linhas com bling_id preenchido, e o
// webhook de estoque de produto desconhecido é logado como
// 'ignorado'. Ou seja, produto sem vínculo fica congelado no
// número que veio da importação e vende para sempre.
//
// Mas vincular é caro e não muda de minuto a minuto — é tarefa de
// catálogo, não de saldo. Daí 1x/dia, fora do pulso de estoque.
//
// ── Por que vincularTudo() e não resolverVinculos() ────
// vincularTudo() LISTA o catálogo do Bling paginado (100/página):
// ~60 chamadas para 6k produtos, e casa localmente.
// resolverVinculos() pergunta "existe o produto X?" uma vez por
// item: 6.000 chamadas para o mesmo resultado. Duas ordens de
// grandeza de diferença, mesmo trabalho.
//
// Ploi Scheduler (uma vez por dia, madrugada):
//   20 4 * * * php /caminho/cli/bling-vinculos.php >> storage/logs/bling-vinculos.log 2>&1
// ════════════════════════════════════════════════════════

require_once __DIR__ . '/../bootstrap-cli.php';

$ts = static fn(): string => date('Y-m-d H:i:s');

try {
    $r = (new BlingVinculoService())->vincularTudo();
    echo "{$ts()} | vinculos | paginas={$r['paginas']} "
       . "bling_produtos={$r['produtos_bling']} bling_variacoes={$r['variacoes_bling']} "
       . "vinculados_produtos={$r['vinculados_produtos']} vinculados_skus={$r['vinculados_skus']}\n";
} catch (\Throwable $e) {
    echo "{$ts()} | vinculos ERRO | {$e->getMessage()}\n";
    exit(1);
}

// ── Cobertura: quantos produtos VENDÁVEIS ficaram sem vínculo ──
// Este número é o que importa depois da vinculação. Produto ativo
// sem bling_id está à venda com saldo que ninguém mais atualiza.
try {
    $db = Database::getInstance()->getConnection();

    $orfaos = (int)$db->query(
        "SELECT COUNT(*) FROM produtos
         WHERE ativo = 1 AND deleted_at IS NULL AND bling_id IS NULL"
    )->fetchColumn();

    $orfaosSku = (int)$db->query(
        "SELECT COUNT(*) FROM produto_skus ps
         JOIN produtos p ON p.id = ps.produto_id
         WHERE ps.ativo = 1 AND p.ativo = 1 AND p.deleted_at IS NULL
           AND ps.bling_id IS NULL"
    )->fetchColumn();

    echo "{$ts()} | cobertura | produtos_ativos_sem_vinculo={$orfaos} skus_ativos_sem_vinculo={$orfaosSku}\n";

    if ($orfaos > 0 || $orfaosSku > 0) {
        LogService::warning('Produtos ativos sem vínculo no Bling', [
            'produtos_sem_vinculo' => $orfaos,
            'skus_sem_vinculo'     => $orfaosSku,
        ], 'bling');
        exit(1);   // saída não-zero: o scheduler acusa e o log fica marcado
    }
} catch (\Throwable $e) {
    echo "{$ts()} | cobertura ERRO | {$e->getMessage()}\n";
    exit(1);
}

exit(0);
