#!/usr/bin/env php
<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// cli/bling-sync.php — pulso de sincronização do Bling
//
// Substitui o antigo bling-sync-estoque.php: um cron cobre
// estoque E fila de clientes, em sequência com try/catch
// ISOLADOS (falha de um não impede o outro).
//
// Ploi Scheduler (a cada 15 min):
//   */15 * * * * php /cli/bling-sync.php >> /var/log/bling-sync.log 2>&1
//  /home/ploi/hml.sportmoto.com.br/storage/logs/bling-sync.log 2>&1
// ════════════════════════════════════════════════════════

// AJUSTE o path conforme a pasta deste arquivo:
//   cli/ na raiz  → __DIR__ . '/../bootstrap-cli.php'
require_once __DIR__ . '/../bootstrap-cli.php';

$ts = static fn(): string => date('Y-m-d H:i:s');
$falhou = false;

// ── 1. Estoque (produtos) ─────────────────────────────
try {
    $r = (new BlingEstoqueService())->sincronizarTudo();
    echo "{$ts()} | estoque OK | total={$r['total']} "
       . "atualizados={$r['atualizados']} erros={$r['erros']}\n";
} catch (\Throwable $e) {
    // NÃO aborta: a fila de clientes é independente e precisa rodar
    echo "{$ts()} | estoque ERRO | {$e->getMessage()}\n";
    $falhou = true;
}

// ── 2. Clientes (fila de contatos) ────────────────────
try {
    $r = (new BlingContatoService())->processarFila(50);
    echo "{$ts()} | clientes OK | total={$r['total']} "
       . "ok={$r['ok']} falhas={$r['falhas']}\n";
    foreach ($r['detalhes'] as $d) {
        echo "  x cliente {$d['cliente_id']}: {$d['msg']}\n";
    }
    if ($r['falhas'] > 0) {
        $falhou = true;
    }
} catch (\Throwable $e) {
    echo "{$ts()} | clientes ERRO | {$e->getMessage()}\n";
    $falhou = true;
}

exit($falhou ? 1 : 0);