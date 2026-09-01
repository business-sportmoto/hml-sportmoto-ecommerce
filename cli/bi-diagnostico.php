#!/usr/bin/env php
<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// cli/bi-diagnostico.php — por que o painel de BI está vazio?
//
// Subir os arquivos PHP é METADE do deploy. O BI depende de
// objetos de banco (uma coluna, uma tabela e 24 views) que só
// existem depois de rodar os três .sql. Sem eles o painel abre
// vazio ou estoura — e nada na tela explica o porquê.
//
// Este script diz, no ambiente, exatamente o que falta.
//
//   php cli/bi-diagnostico.php
//
// Só LÊ. Não cria, não altera, não apaga nada.
// ════════════════════════════════════════════════════════

require_once __DIR__ . '/../bootstrap-cli.php';

$db = Database::getInstance()->getConnection();
$sc = $db->query('SELECT DATABASE()')->fetchColumn();

$problemas = [];
$avisos    = [];

function linha(string $status, string $texto, string $extra = ''): void {
    $icone = ['ok' => '  [ok]   ', 'falta' => '  [FALTA]', 'aviso' => '  [aviso]'][$status];
    echo $icone . ' ' . $texto . ($extra !== '' ? "  — {$extra}" : '') . PHP_EOL;
}

function existeColuna(PDO $db, string $tabela, string $coluna): bool {
    $st = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $st->execute([$tabela, $coluna]);
    return (bool)$st->fetchColumn();
}

function existeObjeto(PDO $db, string $nome): bool {
    $st = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $st->execute([$nome]);
    return (bool)$st->fetchColumn();
}

echo PHP_EOL . "══ DIAGNÓSTICO DO BI ══  banco: {$sc}" . PHP_EOL . PHP_EOL;

// ────────────────────────────────────────────────────────
echo "FASE 0 — verdade única (sql/bi-fase0.sql)" . PHP_EOL;

$f0 = [
    ['coluna', 'pedido_status', 'classe_bi', 'sem ela o BI não sabe o que é venda'],
    ['objeto', 'bi_dim_data',   null,        'calendário; sem ele as séries não têm espinha'],
    ['objeto', 'bi_dim_status', null,        'dimensão de status'],
    ['objeto', 'bi_pedido_geografia', null,  'geografia dos pedidos'],
];
foreach ($f0 as [$tipo, $a, $b, $motivo]) {
    $ok = $tipo === 'coluna' ? existeColuna($db, $a, $b) : existeObjeto($db, $a);
    linha($ok ? 'ok' : 'falta', $tipo === 'coluna' ? "{$a}.{$b}" : $a, $ok ? '' : $motivo);
    if (!$ok) $problemas[] = 'sql/bi-fase0.sql';
}

// ────────────────────────────────────────────────────────
echo PHP_EOL . "FASE 1 — instrumentação (sql/bi-fase1.sql)" . PHP_EOL;

$f1 = [
    ['coluna', 'produtos',     'preco_custo'],
    ['coluna', 'pedido_itens', 'custo_unitario'],
    ['coluna', 'pedidos',      'canal'],
    ['coluna', 'pedidos',      'utm_source'],
    ['coluna', 'pedidos',      'motivo_cancelamento_id'],
    ['objeto', 'motivos_cancelamento', null],
    ['objeto', 'bi_metas',              null],
];
foreach ($f1 as [$tipo, $a, $b]) {
    $ok = $tipo === 'coluna' ? existeColuna($db, $a, $b) : existeObjeto($db, $a);
    linha($ok ? 'ok' : 'falta', $tipo === 'coluna' ? "{$a}.{$b}" : $a);
    if (!$ok) $problemas[] = 'sql/bi-fase1.sql';
}

// ────────────────────────────────────────────────────────
echo PHP_EOL . "FASE 2 — camada semântica (sql/bi-fase2.sql)" . PHP_EOL;

$views = [
    'bi_dim_categoria','bi_dim_marca','bi_dim_produto','bi_dim_sku','bi_dim_cliente',
    'bi_dim_vendedor','bi_dim_cupom','bi_dim_transportadora','bi_dim_motivo',
    'bi_fato_pedido','bi_fato_item','bi_fato_pagamento','bi_fato_devolucao',
    'bi_fato_frete','bi_fato_estoque_saldo','bi_fato_estoque_mov','bi_fato_carrinho',
    'bi_fato_funil','bi_fato_meta','bi_fato_cupom','bi_cobertura_custo','bi_saude_dados',
];
$faltando = array_values(array_filter($views, fn($v) => !existeObjeto($db, $v)));
if ($faltando) {
    linha('falta', count($faltando) . ' de ' . count($views) . ' views ausentes',
          implode(', ', array_slice($faltando, 0, 6)) . (count($faltando) > 6 ? '…' : ''));
    $problemas[] = 'sql/bi-fase2.sql';
} else {
    linha('ok', 'as ' . count($views) . ' views existem');
}

// ────────────────────────────────────────────────────────
// Se a base não está de pé, não adianta olhar dado.
if ($problemas) {
    $unicos = array_values(array_unique($problemas));
    echo PHP_EOL . str_repeat('─', 62) . PHP_EOL;
    echo "É POR ISSO QUE A TELA ESTÁ VAZIA." . PHP_EOL . PHP_EOL;
    echo "Os arquivos PHP subiram, mas os objetos de banco não existem" . PHP_EOL;
    echo "neste ambiente. Rode, NESTA ORDEM e com o charset explícito:" . PHP_EOL . PHP_EOL;
    foreach (['sql/bi-fase0.sql','sql/bi-fase1.sql','sql/bi-fase2.sql'] as $f) {
        $marca = in_array($f, $unicos, true) ? '  <<< pendente' : '';
        echo "  mysql --default-character-set=utf8mb4 -u USER -p {$sc} < {$f}{$marca}" . PHP_EOL;
    }
    echo PHP_EOL . "Os três são idempotentes: rodar de novo não duplica nada." . PHP_EOL;
    echo "O --default-character-set=utf8mb4 NÃO é opcional (acento e colação)." . PHP_EOL;
    exit(1);
}

// ────────────────────────────────────────────────────────
echo PHP_EOL . "O QUE JÁ APARECE (lido do que a loja sempre teve)" . PHP_EOL;

$pedidos = (int)$db->query("SELECT COUNT(*) FROM bi_fato_pedido")->fetchColumn();
$vendas  = (int)$db->query("SELECT COUNT(*) FROM bi_fato_pedido WHERE venda_valida=1")->fetchColumn();
$receita = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM bi_fato_pedido WHERE venda_valida=1")->fetchColumn();

linha($pedidos > 0 ? 'ok' : 'aviso', "pedidos na base: {$pedidos}");
linha($vendas  > 0 ? 'ok' : 'aviso',
      "contando como venda: {$vendas}  ·  R$ " . number_format($receita, 2, ',', '.'));

if ($pedidos > 0 && $vendas === 0) {
    $avisos[] = "Há pedidos, mas NENHUM conta como venda. Provável causa: os status "
              . "deste ambiente não são os mesmos do desenvolvimento e ficaram todos "
              . "em 'pre_venda' (o default seguro). Confira em "
              . "/admin/configuracoes/status-pedidos — cada status tem o campo "
              . "\"Como o BI deve contar este status\".";
}

$semClasse = $db->query(
    "SELECT slug FROM pedido_status
      WHERE classe_bi = 'pre_venda' AND slug NOT LIKE 'aguardando%' AND slug <> 'em_analise'"
)->fetchAll(PDO::FETCH_COLUMN);
if ($semClasse) {
    linha('aviso', count($semClasse) . " status ainda como 'pre_venda'", implode(', ', $semClasse));
    $avisos[] = "Status em 'pre_venda' NÃO entram no faturamento. É o default de "
              . "propósito — classifique cada um em /admin/configuracoes/status-pedidos.";
}

// ────────────────────────────────────────────────────────
echo PHP_EOL . "O QUE SÓ PREENCHE DAQUI PRA FRENTE" . PHP_EOL;

$daquiPraFrente = [
    ['custo dos produtos',   "SELECT COUNT(*) FROM produtos WHERE preco_custo > 0",
     'cadastre no formulário do produto/SKU — destrava margem, lucro e ABC por lucro'],
    ['custo congelado nas vendas', "SELECT COUNT(*) FROM pedido_itens WHERE custo_unitario IS NOT NULL",
     'preenche sozinho a cada venda nova, depois que houver custo cadastrado'],
    ['metas cadastradas',    "SELECT COUNT(*) FROM bi_metas",
     'cadastre em /admin/bi/metas'],
    ['pedidos com UTM',      "SELECT COUNT(*) FROM pedidos WHERE utm_source IS NOT NULL",
     'preenche sozinho quando houver campanha com utm na URL'],
    ['motivo de cancelamento', "SELECT COUNT(*) FROM pedidos WHERE motivo_cancelamento_id IS NOT NULL",
     'preenche ao cancelar um pedido pelo admin'],
];
foreach ($daquiPraFrente as [$rotulo, $sql, $comoEncher]) {
    $n = (int)$db->query($sql)->fetchColumn();
    linha($n > 0 ? 'ok' : 'aviso', str_pad($rotulo, 30) . $n, $n > 0 ? '' : $comoEncher);
}

// ────────────────────────────────────────────────────────
echo PHP_EOL . "COBERTURA (bi_saude_dados)" . PHP_EOL;
foreach ($db->query("SELECT * FROM bi_saude_dados") as $s) {
    $pct = (float)($s['pct'] ?? 0);
    linha($pct >= 50 ? 'ok' : 'aviso',
          str_pad($s['indicador'], 26) . str_pad($pct . '%', 8)
          . "({$s['preenchido']} de {$s['total']})");
}

// ────────────────────────────────────────────────────────
echo PHP_EOL . str_repeat('─', 62) . PHP_EOL;
echo "BANCO OK — o painel tem o que precisar para renderizar." . PHP_EOL;
if ($avisos) {
    echo PHP_EOL . "Atenção:" . PHP_EOL;
    foreach ($avisos as $a) echo PHP_EOL . '  · ' . wordwrap($a, 68, PHP_EOL . '    ') . PHP_EOL;
}
echo PHP_EOL . "Se mesmo assim a tela estiver vazia, o problema é de aplicação:" . PHP_EOL;
echo "  1. acesse /admin/power-bi por GET (link do topo), não por POST" . PHP_EOL;
echo "  2. o painel exige cargo super ou gerente" . PHP_EOL;
echo "  3. confira storage/logs/ do dia" . PHP_EOL . PHP_EOL;
