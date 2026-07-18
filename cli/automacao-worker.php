<?php
/**
 * cli/automacao-worker.php
 *
 * Worker de automações de email.
 * Roda em duas fases por execução:
 *   Fase 1 — DetecÇão: varre eventos e enfileira
 *   Fase 2 — Despacho: processa itens prontos na fila
 *
 * Cron sugerido (a cada 5 minutos):
 *   *\/5 * * * * php /home/ploi/hml.sportmoto.com.br/cli/automacao-worker.php >> /home/ploi/hml.sportmoto.com.br/storage/logs/automacao-worker.log 2>&1
 * crontab -u www-data -e
 * /usr/local/lsws/lsphp82/bin/php /home/homo-v2.sportmoto.com.br/public_html/cli/automacao-worker.php --verbose 2>&1
 * Uso manual:
 *   /usr/local/lsws/lsphp82/bin/php /home/homo-v2.sportmoto.com.br/public_html/cli/automacao-worker.php --verbose
 *   /usr/local/lsws/lsphp82/bin/php /home/homo-v2.sportmoto.com.br/public_html/cli/automacao-worker.php --apenas-detectar
 *   /usr/local/lsws/lsphp82/bin/php /home/homo-v2.sportmoto.com.br/public_html/cli/automacao-worker.php --apenas-despachar
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script só roda em CLI.\n");
    exit(1);
}

define('AUTOMACAO_WORKER_CLI', true);
$verbose         = in_array('--verbose', $argv ?? [], true);
$apenasDetectar  = in_array('--apenas-detectar', $argv ?? [], true);
$apenasDespachar = in_array('--apenas-despachar', $argv ?? [], true);

// ============================================================================
// BOOTSTRAP — espelha o index.php
// ============================================================================
$ROOT = dirname(__DIR__);
chdir($ROOT);
ini_set('expose_php', 0);

require_once $ROOT . '/config/defines.php';
require_once $ROOT . '/config/config.php';
require_once $ROOT . '/config/database.php';
if (is_file($ROOT . '/vendor/autoload.php')) require_once $ROOT . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    $paths = [
        ROOT_PATH . '/core/',
        ROOT_PATH . '/app/controllers/',
        ROOT_PATH . '/app/models/',
        ROOT_PATH . '/app/helpers/',
        ROOT_PATH . '/app/services/',
        ROOT_PATH . '/app/services/email/',
        ROOT_PATH . '/app/services/email/providers/',
    ];
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) { require_once $file; return; }
    }
});

try {
    Database::getInstance()->getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] Falha ao conectar: " . $e->getMessage() . "\n");
    exit(1);
}

// ============================================================================
// LOCK
// ============================================================================
$lockFile = $ROOT . '/storage/locks/automacao-worker.lock';
@mkdir(dirname($lockFile), 0775, true);
$fp = @fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    if ($verbose) echo "Outro automacao-worker já está rodando.\n";
    if ($fp) fclose($fp);
    exit(0);
}
ftruncate($fp, 0);
fwrite($fp, (string)getmypid());
fflush($fp);

register_shutdown_function(function () use (&$fp, $lockFile) {
    if (is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); }
    @unlink($lockFile);
});

// ============================================================================
// EXECUÇÃO
// ============================================================================
$started = time();
$maxRun  = 240; // 4 min — cron roda a cada 5min

$log = function (string $m) use ($verbose): void {
    if ($verbose) echo '[' . date('H:i:s') . "] $m\n";
    if (class_exists('LogService')) {
        // try { LogService::info('automacao_worker: ' . $m); } catch (Throwable $e) {}
    }
};

$log("worker iniciado (pid " . getmypid() . ")");

// ----------------------------------------------------------------------------
// FASE 1 — DETECÇÃO
// ----------------------------------------------------------------------------
if (!$apenasDespachar) {
    $log("--- FASE 1: detecção ---");
    try {
        $svc = new AutomacaoService();
        $r   = $svc->detectarTodos();
        foreach ($r as $tipo => $n) {
            if ($n > 0 || $verbose) $log("  $tipo: $n enfileirados");
        }
    } catch (Throwable $e) {
        $log("ERRO na detecção: " . $e->getMessage());
        // LogService::error("ERRO na detecção: " . $e->getMessage());
        LogService::exception($e, 'error', 'worker');
    }
}

// ----------------------------------------------------------------------------
// FASE 2 — DESPACHO
// ----------------------------------------------------------------------------
if (!$apenasDetectar && (time() - $started) < $maxRun) {
    $log("--- FASE 2: despacho ---");
    try {
        $dispatch = new AutomacaoDispatchService();
        $model    = new AutomacaoModel();
        $prontos  = $model->buscarProntos(100);

        $enviados = 0; $suprimidos = 0; $erros = 0;

        // LogService::error("BuscaProntos: ", $prontos);

        foreach ($prontos as $item) {
            if ((time() - $started) >= $maxRun) {
                $log("  tempo limite atingido, parando");
                break;
            }
            $res = $dispatch->processar($item);
            // LogService::error("ERRO no processar()->: " . $res);
            switch ($res) {
                case 'enviado':   $enviados++;   break;
                case 'suprimido': $suprimidos++; break;
                case 'erro':      $erros++;      break;
            }
        }

        $log("  enviados=$enviados suprimidos=$suprimidos erros=$erros");
    } catch (Throwable $e) {
        $log("ERRO no despacho: " . $e->getMessage());
    }
}

$log("worker encerrado (" . (time() - $started) . "s)");
exit(0);
