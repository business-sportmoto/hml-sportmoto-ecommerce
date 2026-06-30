<?php

// * cron/bling-sync-estoque.php
// * Executa a cada 15 minutos via crontab:
//  *   */15 * * * * php /var/www/cron/bling-sync-estoque.php >> /var/log/bling-sync.log 2>&1
 
define('CRON_MODE', true);
require_once __DIR__ . '/../bootstrap.php'; // ajuste o path conforme seu projeto

try {
    $result = (new BlingEstoqueService())->sincronizarTudo();
    echo date('Y-m-d H:i:s') . " | Bling sync OK | "
       . "total={$result['total']} "
       . "atualizados={$result['atualizados']} "
       . "erros={$result['erros']}\n";
} catch (\Throwable $e) {
    echo date('Y-m-d H:i:s') . " | Bling sync ERRO | " . $e->getMessage() . "\n";
    exit(1);
}
