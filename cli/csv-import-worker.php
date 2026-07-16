<?php
/**
 * cli/csv-import-worker.php
 *
 * Worker CLI para processar importações CSV em background.
 * Bootstrap idêntico ao email-worker.php (espelha o index.php).
 *
 * Uso:
 *   php cli/csv-import-worker.php
 *   php cli/csv-import-worker.php --verbose
 *
 * Cron sugerido (a cada minuto):
 *   * * * * * flock -n /home/ploi/hml.sportmoto.com.br/tmp/sm-csv-worker.lock php /home/ploi/hml.sportmoto.com.br/cli/csv-import-worker.php >> /home/ploi/hml.sportmoto.com.br/storage/logs/csv-worker.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script só roda em CLI.\n");
    exit(1);
}

$verbose = in_array('--verbose', $argv ?? [], true);

// ============================================================================
// BOOTSTRAP — espelha o index.php
// ============================================================================
define('CSV_IMPORT_WORKER_CLI', true);

$ROOT = dirname(__DIR__);
chdir($ROOT);
ini_set('expose_php', 0);

require_once $ROOT . '/config/defines.php';
require_once $ROOT . '/config/config.php';
require_once $ROOT . '/config/database.php';

if (is_file($ROOT . '/vendor/autoload.php')) {
    require_once $ROOT . '/vendor/autoload.php';
}

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

if (!class_exists('Database')) {
    fwrite(STDERR, "[FATAL] Classe Database não disponível.\n");
    exit(1);
}

try {
    Database::getInstance()->getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] Falha ao conectar: " . $e->getMessage() . "\n");
    exit(1);
}

// ============================================================================
// LOCK
// ============================================================================
$lockFile = $ROOT . '/storage/locks/csv-worker.lock';
@mkdir(dirname($lockFile), 0775, true);
$fp = @fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    if ($verbose) echo "Outro CSV worker já está rodando.\n";
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
$maxRun  = 600; // 10 min por execução

$log = function ($m) use ($verbose) {
    if ($verbose) echo '[' . date('H:i:s') . "] $m\n";
    if (class_exists('LogService')) {
        try { LogService::info('csv_worker: ' . $m); } catch (Throwable $e) {}
    }
};

$log("CSV worker iniciado (pid " . getmypid() . ")");

$model = new EmailImport();
$liberados = $model->liberarLocksVencidos(1800);
if ($liberados > 0) $log("liberados $liberados jobs com lock vencido");

$svc = new CsvImportService();

// Processa até 3 jobs por execução, ou até estourar tempo
$jobsProcessados = 0;
$maxJobs = 3;

while ($jobsProcessados < $maxJobs && (time() - $started) < $maxRun) {
    $job = $model->reservarProximo();
    if (!$job) {
        $log("nenhum job em fila");
        break;
    }

    $log("processando job #{$job['id']} (arquivo: {$job['arquivo']})");

    try {
        $svc->processar($job, function ($stats, $pct) use ($log) {
            $log("  progresso {$pct}% - inseridos={$stats['inseridos']} dup={$stats['duplicados']} inv={$stats['invalidos']}");
        });
        $log("  job #{$job['id']} concluído");

        // Gera relatório de erros, se houver
        $jobFinal = $model->find((int)$job['id']);
        if ($jobFinal && (int)$jobFinal['invalidos'] > 0) {
            try {
                $svc->gerarRelatorioErros((int)$job['id']);
                $log("  relatório de erros gerado");
            } catch (Throwable $e) {
                $log("  erro ao gerar relatório: " . $e->getMessage());
            }
        }

        if (class_exists('LogService')) {
            LogService::audit('email_csv_concluido', [
                'importacao_id' => $job['id'],
                'inseridos' => $jobFinal['inseridos'] ?? 0,
                'invalidos' => $jobFinal['invalidos'] ?? 0,
            ]);
        }

    } catch (Throwable $e) {
        $log("  ERRO no job #{$job['id']}: " . $e->getMessage());
        $model->atualizar((int)$job['id'], [
            'status' => 'erro',
            'concluido_em' => date('Y-m-d H:i:s'),
        ]);
        if (class_exists('LogService')) {
            LogService::error('csv_worker job #' . $job['id'] . ': ' . $e->getMessage());
        }
    }

    $jobsProcessados++;
}

$log("worker encerrado (" . (time() - $started) . "s, $jobsProcessados jobs)");
exit(0);
