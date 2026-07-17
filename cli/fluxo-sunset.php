<?php
/**
 * cli/fluxo-sunset.php
 *
 * Sunset policy — suprime do marketing quem recebe e nunca abre.
 * Roda semanal (não precisa ser no worker de minuto).
 *
 * Cron (crontab -u www-data -e) — toda segunda 04:00:
 *   0 4 * * 1 cd /home/homo-v2.sportmoto.com.br/public_html && /usr/local/lsws/lsphp82/bin/php cli/fluxo-sunset.php --verbose >> storage/logs/fluxo-sunset.log 2>&1
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Só roda em CLI.\n"); exit(1); }
$verbose = in_array('--verbose', $argv ?? [], true);

$ROOT = dirname(__DIR__);
chdir($ROOT);
require_once $ROOT . '/config/defines.php';
require_once $ROOT . '/config/config.php';
require_once $ROOT . '/config/database.php';
if (is_file($ROOT . '/vendor/autoload.php')) require_once $ROOT . '/vendor/autoload.php';

spl_autoload_register(function (string $class) use ($ROOT): void {
    foreach (['/core/','/app/controllers/','/app/models/','/app/helpers/',
              '/app/services/','/app/services/email/','/app/services/email/providers/'] as $p) {
        $f = $ROOT . $p . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

$log = function (string $m) use ($verbose): void {
    if ($verbose) echo '[' . date('H:i:s') . "] $m\n";
};

// ── Lock ─────────────────────────────────────────────────────────────────────
$lockFile = $ROOT . '/storage/locks/fluxo-sunset.lock';
@mkdir(dirname($lockFile), 0775, true);
$fp = @fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    if ($verbose) echo "Outro fluxo-sunset já está rodando.\n";
    if ($fp) fclose($fp);
    exit(0);
}
register_shutdown_function(function () use (&$fp, $lockFile) {
    if (is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); }
    @unlink($lockFile);
});

$log("fluxo-sunset iniciado (pid " . getmypid() . ")");
try {
    $svc = new FluxoSunsetService();
    $s = $svc->processar(2000);
    $log(sprintf("avaliados=%d suprimidos=%d ja_marcados=%d sem_cliente=%d",
        $s['avaliados'], $s['suprimidos'], $s['ja_marcados'], $s['sem_cliente']));
} catch (Throwable $e) {
    $log("ERRO: " . $e->getMessage());
    if (class_exists('LogService')) {
        try { LogService::error('fluxo-sunset: ' . $e->getMessage()); } catch (Throwable $x) {}
    }
}
$log("encerrado");
exit(0);
