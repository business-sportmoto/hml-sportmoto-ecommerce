#!/usr/bin/env php
<?php
// cli/processar-imagens.php
//   */5 * * * *  php /caminho/cli/processar-imagens.php >> /var/log/img-queue.log 2>&1

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap-cli.php';   // mesmo do bling-sync

$limite = isset($argv[1]) ? max(1, (int)$argv[1]) : 30;

try {
    $r = (new TrayImportService())->processarFilaImagens($limite);
    echo date('Y-m-d H:i:s') . " | imagens OK | "
       . "processadas=" . ($r['processadas'] ?? 0) . " "
       . "erros=" . ($r['erros'] ?? 0) . "\n";
    exit(($r['erros'] ?? 0) > 0 ? 1 : 0);
} catch (\Throwable $e) {
    echo date('Y-m-d H:i:s') . " | imagens ERRO | " . $e->getMessage() . "\n";
    exit(1);
}