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


// Rede de segurança: clientes novos cujo gatilho de criação falhou
try {
    $r = (new BlingContatoService())->processarFila(30);
    echo "{$ts()} | contatos-fila | ok={$r['ok']} falhas={$r['falhas']}\n";
} catch (\Throwable $e) {
    echo "{$ts()} | contatos-fila ERRO | {$e->getMessage()}\n";
    $falhou = true;
}

exit($falhou ? 1 : 0);