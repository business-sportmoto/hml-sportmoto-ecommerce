<!-- /* ════════════════════════════════════════════════════════
   bin/carrinhos-abandonados-processar.php  (ARQUIVO SEPARADO)
   Ploi Scheduler → Command: php /home/ploi/SITE/bin/carrinhos-abandonados-processar.php
                    Frequency: Every 30 minutes
   ════════════════════════════════════════════════════════
  -->
<!-- #!/usr/bin/env php -->
<?php
declare(strict_types=1);
 
if (PHP_SAPI !== 'cli') { exit(1); }
 
require __DIR__ . '/../app/bootstrap.php'; // AJUSTAR entrypoint
 
$inicio = microtime(true);
 
try {
    $svc = new CarrinhoRecuperacaoService();
 
    $novos       = $svc->detectarAbandonados();
    $recuperados = $svc->reconciliarRecuperados();
    $sugestoes   = $svc->contarSugestaoPerdidos();
 
    error_log(sprintf(
        '[carrinhos-cron] ok novos=%d recuperados=%d sugerir_perdido=%d dur=%dms',
        $novos, $recuperados, $sugestoes,
        (int)round((microtime(true) - $inicio) * 1000)
    ));
    exit(0);
 
} catch (\Throwable $e) {
    error_log('[carrinhos-cron] FALHA: ' . $e->getMessage());
    exit(1);
}
