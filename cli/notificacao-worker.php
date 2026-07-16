<?php
/**
 * cli/notificacao-worker.php
 *
 * Materializa broadcasts pendentes (fan-out em batches).
 * Roda via cron a cada minuto.
 *
 * Cron (crontab -u www-data -e):
 *   * * * * * cd /home/ploi/hml.sportmoto.com.br && php cli/notificacao-worker.php --verbose >> storage/logs/notificacao-worker.log 2>&1
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
    $paths = [
        $ROOT . '/core/',
        $ROOT . '/app/controllers/',
        $ROOT . '/app/models/',
        $ROOT . '/app/helpers/',
        $ROOT . '/app/services/',
        $ROOT . '/app/services/email/',
        $ROOT . '/app/services/email/providers/',
    ];
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) { require_once $file; return; }
    }
});

try {
    Database::getInstance()->getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] Banco: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Lock ─────────────────────────────────────────────────────────────────────
$lockFile = $ROOT . '/storage/locks/notificacao-worker.lock';
@mkdir(dirname($lockFile), 0775, true);
$fp = @fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    if ($verbose) echo "Outro notificacao-worker já está rodando.\n";
    if ($fp) fclose($fp);
    exit(0);
}
register_shutdown_function(function () use (&$fp, $lockFile) {
    if (is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); }
    @unlink($lockFile);
});

// ── Execução ─────────────────────────────────────────────────────────────────
$log = function (string $m) use ($verbose): void {
    if ($verbose) echo '[' . date('H:i:s') . "] $m\n";
};

$log("notificacao-worker iniciado (pid " . getmypid() . ")");

try {
    $stats = NotificacaoService::processarFanout(120);
    $log(sprintf(
        "broadcasts=%d filhas_criadas=%d erros=%d",
        $stats['processadas'], $stats['filhas_criadas'], $stats['erros']
    ));
    if ($stats['processadas'] === 0 && $verbose) {
        $log("nenhum broadcast pendente");
    }
} catch (Throwable $e) {
    $log("ERRO: " . $e->getMessage());
    if (class_exists('LogService')) {
        try { LogService::error('notificacao-worker: ' . $e->getMessage()); } catch (Throwable $x) {}
    }
}

$log("worker encerrado");
exit(0);
