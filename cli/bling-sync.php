#!/usr/bin/env php
<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// cli/bling-sync.php — pulso de sincronização do Bling
//
// Três tarefas, try/catch ISOLADOS: falha de uma não impede
// as outras. Todas respeitam o mesmo teto de 3 req/s, que é
// estático no BlingApiClient e compartilhado no processo.
//
//   1. Fila de PEDIDOS   → site → Bling  (baixa o estoque)
//   2. Espelho de ESTOQUE → Bling → site  (reflete o saldo)
//   3. Fila de CONTATOS   → rede de segurança de clientes
//
// ── Por que ESTA cadência ──────────────────────────────
// O Bling é o dono do estoque. O webhook 'stock.updated' já
// dá o tempo real; este cron é RECONCILIAÇÃO — cobre webhook
// perdido, downtime e ajuste manual feito dentro do Bling.
//
// Custo por execução com ~6k produtos vinculados:
//   sincronizarEstoque() → lotes de 50 → ~120 chamadas
// De hora em hora: ~2.900 chamadas/dia. Confortável.
//
// NÃO usar sincronizarTudo() aqui: ele resolve vínculo com
// 1 chamada + 120ms de sleep POR produto sem bling_id, sem
// limite, em toda execução — produto que não existe no Bling
// nunca resolve e cobra esse pedágio para sempre. Foi o que
// derrubou este cron em julho/2026. Vínculo é tarefa do
// bling-vinculos.php, 1x/dia, por listagem em lote.
//
// Ploi Scheduler (de hora em hora):
//   0 * * * * php /caminho/cli/bling-sync.php >> storage/logs/bling-sync.log 2>&1
// ════════════════════════════════════════════════════════

require_once __DIR__ . '/../bootstrap-cli.php';

$ts     = static fn(): string => date('Y-m-d H:i:s');
$falhou = false;

// ── 1. Pedidos pendentes → Bling ──────────────────────
// PRIMEIRO de propósito: é o que faz o estoque baixar. Rodar
// antes do espelho significa que a baixa provocada por estes
// pedidos já pode aparecer no passo 2 da mesma execução.
try {
    $r = (new BlingOrderService())->processarFila(50);
    echo "{$ts()} | pedidos-fila | total={$r['total']} ok={$r['ok']} falhas={$r['falhas']}\n";
    foreach ($r['detalhes'] as $d) {
        echo "  x pedido {$d['pedido_id']}: {$d['msg']}\n";
    }
    if ($r['falhas'] > 0) $falhou = true;
} catch (\Throwable $e) {
    echo "{$ts()} | pedidos-fila ERRO | {$e->getMessage()}\n";
    $falhou = true;
}

// ── 2. Espelho de estoque (Bling → site) ──────────────
try {
    $r = (new BlingEstoqueService())->sincronizarEstoque();
    if (!empty($r['erro'])) {
        // Ex.: nenhum depósito padrão configurado. Sem isto o passo
        // não faz nada e retornaria "0 atualizados" parecendo sucesso.
        echo "{$ts()} | estoque ERRO | {$r['erro']}\n";
        $falhou = true;
    } else {
        echo "{$ts()} | estoque | total={$r['total']} atualizados={$r['atualizados']} erros={$r['erros']}\n";
        if ($r['erros'] > 0) $falhou = true;
    }
} catch (\Throwable $e) {
    echo "{$ts()} | estoque ERRO | {$e->getMessage()}\n";
    $falhou = true;
}

// ── 3. Clientes (rede de segurança) ───────────────────
// Clientes novos cujo gatilho de criação falhou.
try {
    $r = (new BlingContatoService())->processarFila(30);
    echo "{$ts()} | contatos-fila | ok={$r['ok']} falhas={$r['falhas']}\n";
} catch (\Throwable $e) {
    echo "{$ts()} | contatos-fila ERRO | {$e->getMessage()}\n";
    $falhou = true;
}

exit($falhou ? 1 : 0);
