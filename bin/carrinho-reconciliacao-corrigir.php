<?php
declare(strict_types=1);

/**
 * bin/carrinho-reconciliacao-corrigir.php
 *
 * Reavalia os carrinhos já marcados como RECUPERADOS com a regra correta.
 *
 * Até 05/09/2026 a reconciliação marcava recuperado qualquer carrinho cujo
 * cliente fizesse um pedido aprovado depois do abandono — mesmo de produtos
 * completamente diferentes. E, quando havia vários pedidos elegíveis, gravava
 * um qualquer. Ver [[Bugs resolvidos]] e §3.8 de [[Carrinho abandonado]].
 *
 * A correção no `reconciliarRecuperados()` só vale dali para frente: o `WHERE`
 * pula quem já está `recuperado`. Este script é a limpeza do passado.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * NÃO ALTERA NADA SEM `--aplicar`.
 *
 *   php bin/carrinho-reconciliacao-corrigir.php              simula e mostra
 *   php bin/carrinho-reconciliacao-corrigir.php --aplicar    grava
 *   php bin/carrinho-reconciliacao-corrigir.php --limite=50
 *   php bin/carrinho-reconciliacao-corrigir.php --apenas=17,19 --aplicar
 *
 * Isto mexe em indicador de faturamento. Rode a simulação, leia a saída, e só
 * então aplique — de preferência com dump do banco na mão.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * O QUE FAZ COM CADA REGISTRO
 *
 *   pedido correto        → não toca
 *   pedido trocado        → corrige `pedido_recuperado_id` e `valor_recuperado`
 *                           para o PRIMEIRO pedido aprovado, depois do abandono,
 *                           que contenha algum produto do carrinho
 *   sem pedido que sirva  → volta para `abandonado` e limpa os dois campos:
 *                           a recuperação nunca aconteceu
 *
 * Voltar para `abandonado` não faz o carrinho ser cobrado de novo: a emissão de
 * evento tem janela de frescor de 6h e a régua de cupom tem teto de 6 dias.
 * Registro antigo volta para a lista como o que sempre foi — um abandono.
 *
 * Toda mudança deixa evento na trilha, então dá para auditar depois.
 * Idempotente: rodar duas vezes não muda nada na segunda.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Só roda em CLI.\n"); exit(1); }

$argv    = $argv ?? [];
$aplicar = in_array('--aplicar', $argv, true);
$limite  = 500;
$apenas  = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--limite=')) $limite = max(1, (int)substr($a, 9));
    // Corrigir um punhado por vez é o jeito prudente de mexer em indicador de
    // faturamento: aplica em dois, confere na tela, e só então solta o resto.
    if (str_starts_with($a, '--apenas=')) {
        $apenas = array_values(array_filter(array_map('intval', explode(',', substr($a, 9)))));
    }
}

$ROOT = dirname(__DIR__);
chdir($ROOT);

require_once $ROOT . '/config/defines.php';
require_once $ROOT . '/config/config.php';
require_once $ROOT . '/config/database.php';
if (is_file($ROOT . '/vendor/autoload.php')) require_once $ROOT . '/vendor/autoload.php';

spl_autoload_register(function (string $class) use ($ROOT): void {
    foreach (['/core/', '/app/models/', '/app/helpers/', '/app/services/'] as $p) {
        $f = $ROOT . $p . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

$db = Database::getInstance()->getConnection();

// ── Os já marcados como recuperados ─────────────────────────────────────────
$filtroApenas = $apenas
    ? ' AND cr.id IN (' . implode(',', array_map('intval', $apenas)) . ')'
    : '';

$st = $db->prepare(
    "SELECT cr.id, cr.carrinho_id, cr.cliente_id, cr.abandonado_em,
            cr.pedido_recuperado_id, cr.valor_recuperado
     FROM carrinho_recuperacao cr
     WHERE cr.status = 'recuperado'{$filtroApenas}
     ORDER BY cr.id
     LIMIT {$limite}"
);
$st->execute();
$linhas = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$linhas) { echo "Nenhum carrinho marcado como recuperado.\n"; exit(0); }

// O pedido CERTO para um carrinho: o primeiro aprovado, depois do abandono,
// que contenha algum produto dele. Mesma regra do reconciliarRecuperados().
$stCerto = $db->prepare(
    "SELECT p.id, p.total, p.codigo, p.criado_em
     FROM pedidos p
     JOIN pedido_itens pi ON pi.pedido_id = p.id
     WHERE p.cliente_id       = :cli
       AND p.criado_em        > :desde
       AND p.status_pagamento = 'aprovado'
       AND EXISTS (SELECT 1 FROM carrinho_itens ci
                   WHERE ci.carrinho_id = :car
                     AND ci.produto_id  = pi.produto_id)
     ORDER BY p.criado_em ASC, p.id ASC
     LIMIT 1"
);

$corretos = [];
$trocar   = [];
$reverter = [];

foreach ($linhas as $r) {
    // Sem cliente não há como reavaliar — deixa como está e reporta
    if (empty($r['cliente_id'])) { $corretos[] = $r; continue; }

    $stCerto->execute([
        ':cli'   => (int)$r['cliente_id'],
        ':desde' => (string)$r['abandonado_em'],
        ':car'   => (int)$r['carrinho_id'],
    ]);
    $certo = $stCerto->fetch(PDO::FETCH_ASSOC);

    if (!$certo) {
        $reverter[] = $r;
    } elseif ((int)$certo['id'] !== (int)$r['pedido_recuperado_id']
           || (string)$certo['total'] !== (string)$r['valor_recuperado']) {
        $r['certo'] = $certo;
        $trocar[]   = $r;
    } else {
        $corretos[] = $r;
    }
}

// ── Relatório ───────────────────────────────────────────────────────────────
$moeda = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');

echo str_repeat('═', 74) . "\n";
echo ($aplicar ? 'APLICANDO' : 'SIMULAÇÃO (nada será alterado)') . "\n";
echo str_repeat('═', 74) . "\n\n";

printf("Marcados como recuperados: %d\n", count($linhas));
printf("  já corretos ............ %d\n", count($corretos));
printf("  pedido trocado ......... %d\n", count($trocar));
printf("  sem pedido que sirva ... %d  ← recuperação que nunca houve\n\n", count($reverter));

if ($trocar) {
    echo "── PEDIDO TROCADO ──\n";
    foreach ($trocar as $r) {
        printf("  rec#%-5s  %s → %s   (pedido %s → %s, %s)\n",
            $r['id'],
            $moeda($r['valor_recuperado']), $moeda($r['certo']['total']),
            $r['pedido_recuperado_id'] ?: '—', $r['certo']['id'],
            date('d/m/Y', strtotime((string)$r['certo']['criado_em'])));
    }
    echo "\n";
}

if ($reverter) {
    echo "── VOLTAM A SER ABANDONADOS ──\n";
    foreach ($reverter as $r) {
        printf("  rec#%-5s  tirando %s do faturamento recuperado (pedido %s não tinha produto do carrinho)\n",
            $r['id'], $moeda($r['valor_recuperado']), $r['pedido_recuperado_id'] ?: '—');
    }
    echo "\n";
}

$antes  = array_sum(array_map(fn($r) => (float)$r['valor_recuperado'], $linhas));
$depois = array_sum(array_map(fn($r) => (float)$r['valor_recuperado'], $corretos))
        + array_sum(array_map(fn($r) => (float)$r['certo']['total'], $trocar));

echo "── FATURAMENTO CREDITADO À RECUPERAÇÃO ──\n";
printf("  antes:  %s\n", $moeda($antes));
printf("  depois: %s   (%s%s)\n\n", $moeda($depois),
    $depois >= $antes ? '+' : '−', $moeda(abs($depois - $antes)));

if (!$trocar && !$reverter) { echo "Nada a corrigir.\n"; exit(0); }

if (!$aplicar) {
    echo "Nada foi alterado. Para gravar:\n";
    echo "  php bin/carrinho-reconciliacao-corrigir.php --aplicar\n";
    exit(0);
}

// ── Aplicação ───────────────────────────────────────────────────────────────
// Transação: meia correção deixaria o indicador pior do que estava.
$db->beginTransaction();
try {
    $upTrocar = $db->prepare(
        "UPDATE carrinho_recuperacao
         SET pedido_recuperado_id = :ped, valor_recuperado = :val, ultima_acao_em = NOW()
         WHERE id = :id AND status = 'recuperado'"
    );
    $upReverter = $db->prepare(
        "UPDATE carrinho_recuperacao
         SET status = 'abandonado', pedido_recuperado_id = NULL,
             valor_recuperado = NULL, ultima_acao_em = NOW()
         WHERE id = :id AND status = 'recuperado'"
    );
    // admin_id NULL: quem corrigiu foi uma manutenção, não uma pessoa
    $evento = $db->prepare(
        "INSERT INTO carrinho_recuperacao_eventos (recuperacao_id, tipo, descricao, meta, admin_id)
         VALUES (:r, :t, :d, :m, NULL)"
    );

    foreach ($trocar as $r) {
        $upTrocar->execute([
            ':ped' => (int)$r['certo']['id'],
            ':val' => $r['certo']['total'],
            ':id'  => (int)$r['id'],
        ]);
        $evento->execute([
            ':r' => (int)$r['id'],
            ':t' => 'status_alterado',
            ':d' => 'Reconciliação corrigida — pedido ' . ($r['pedido_recuperado_id'] ?: '—')
                  . ' → ' . $r['certo']['id'],
            ':m' => json_encode([
                'correcao'    => 'reconciliacao_3_8',
                'pedido_de'   => (int)$r['pedido_recuperado_id'],
                'pedido_para' => (int)$r['certo']['id'],
                'valor_de'    => (float)$r['valor_recuperado'],
                'valor_para'  => (float)$r['certo']['total'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    foreach ($reverter as $r) {
        $upReverter->execute([':id' => (int)$r['id']]);
        $evento->execute([
            ':r' => (int)$r['id'],
            ':t' => 'status_alterado',
            ':d' => 'Reconciliação corrigida — recuperado → abandonado '
                  . '(o pedido não continha produto do carrinho)',
            ':m' => json_encode([
                'correcao'  => 'reconciliacao_3_8',
                'de'        => 'recuperado',
                'para'      => 'abandonado',
                'pedido_de' => (int)$r['pedido_recuperado_id'],
                'valor_de'  => (float)$r['valor_recuperado'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    $db->commit();

} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "FALHA — nada foi alterado: " . $e->getMessage() . "\n");
    exit(1);
}

printf("Aplicado: %d pedido(s) corrigido(s), %d registro(s) devolvido(s) a abandonado.\n",
    count($trocar), count($reverter));
echo "Cada mudança deixou evento na trilha do carrinho.\n";
exit(0);
