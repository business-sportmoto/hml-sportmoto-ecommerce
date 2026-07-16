<?php
declare(strict_types=1);

/**
 * webhook-retry-worker.php
 *
 * Worker que reprocessa eventos de webhook que foram persistidos em
 * pgto_webhook_log mas não conseguiram ser aplicados no domínio.
 *
 * Por que existe? O WebhookController responde 200 mesmo em erro de
 * processamento de domínio (DB momentaneamente travado, deadlock, etc.).
 * Esse worker varre periodicamente os "presos" e tenta de novo.
 *
 * Usa o mesmo padrão dos workers de email-marketing do SportMoto:
 *   - Lock interno via flock() em arquivo de PID
 *   - --verbose redireciona log pra storage/logs/
 *   - Limite de tentativas pra não ficar tentando indefinidamente
 *
 * Crontab (na conta www-data):
 *   * * * * * /usr/local/lsws/lsphp82/bin/php /home/homo-v2.sportmoto.com.br/public_html/workers/webhook-retry-worker.php --verbose >> /home/homo-v2.sportmoto.com.br/public_html/storage/logs/webhook-retry-worker.log 2>&1
 */

// ---------------------------------------------------------------------
// Bootstrap — caminho relativo pra funcionar tanto local quanto no servidor
// ---------------------------------------------------------------------
$basePath = realpath(__DIR__ . '/config');
if (!$basePath) {
    fwrite(STDERR, "ERRO: não consegui resolver \n".$basePath);
    exit(2);
}

// Se o framework tem bootstrap único:
if (file_exists($basePath . '/bootstrap.php')) {
    require_once $basePath . '/bootstrap.php';
} else {
    // Bootstrap manual mínimo (ajustar conforme o que carregar Database,
    // Session, LogService no SportMoto):
    foreach (['defines.php', 'config.php', 'database.php'] as $arq) {
        if (file_exists($basePath . '/' . $arq)) {
            require_once $basePath . '/' . $arq;
        }
    }
}

// ---------------------------------------------------------------------
// CLI flags
// ---------------------------------------------------------------------
$verbose      = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);
$dryRun       = in_array('--dry-run', $argv, true);
$maxBatch     = 50;                  // máximo de logs reprocessados por execução
$maxTentativas= 6;                   // após X tentativas, marca como morto
$idadeMaxHoras= 24 * 7;              // não retentativa eventos com mais de 7 dias

foreach ($argv as $a) {
    if (preg_match('/^--max-batch=(\d+)$/', $a, $m)) $maxBatch = (int) $m[1];
    if (preg_match('/^--max-tentativas=(\d+)$/', $a, $m)) $maxTentativas = (int) $m[1];
}

// ---------------------------------------------------------------------
// Lock interno (mesmo padrão dos workers do projeto)
// ---------------------------------------------------------------------
$lockFile = sys_get_temp_dir() . '/sportmoto-webhook-retry-worker.lock';
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_v('outro processo já está rodando, saindo');
    exit(0);
}
register_shutdown_function(function() use ($lockHandle, $lockFile) {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    @unlink($lockFile);
});

// ---------------------------------------------------------------------
// Loop principal
// ---------------------------------------------------------------------
function log_v(string $msg): void
{
    global $verbose;
    if (!$verbose) return;
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
}

$inicio = microtime(true);
log_v("worker iniciado (max_batch={$maxBatch}, max_tentativas={$maxTentativas}, dry_run=" . ($dryRun ? 'sim' : 'não') . ')');

try {
    $db = Database::getInstance()->getConnection();
} catch (\Throwable $e) {
    fwrite(STDERR, "Falha ao conectar no banco: " . $e->getMessage() . PHP_EOL);
    exit(2);
}

// Busca eventos não processados, ordenados por chegada (FIFO)
$stmt = $db->prepare(
    "SELECT id, event_id, tipo, charge_id, tentativas, recebido_em
       FROM pgto_webhook_log
      WHERE processado = 0
        AND assinatura_valida = 1
        AND tentativas < :max_tent
        AND recebido_em > DATE_SUB(NOW(), INTERVAL :max_idade HOUR)
      ORDER BY recebido_em ASC
      LIMIT :lim"
);
$stmt->bindValue(':max_tent', $maxTentativas, PDO::PARAM_INT);
$stmt->bindValue(':max_idade', $idadeMaxHoras, PDO::PARAM_INT);
$stmt->bindValue(':lim', $maxBatch, PDO::PARAM_INT);
$stmt->execute();
$pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pendentes)) {
    log_v('nenhum webhook pendente, saindo');
    exit(0);
}

log_v("encontrados " . count($pendentes) . " logs pendentes");

if ($dryRun) {
    foreach ($pendentes as $p) {
        log_v(" [dry] log #{$p['id']} tipo={$p['tipo']} tentativas={$p['tentativas']}");
    }
    exit(0);
}

$processor = new MalgaWebhookProcessor();
$totalOk = 0;
$totalFail = 0;

foreach ($pendentes as $p) {
    $logId = (int) $p['id'];
    try {
        $r = $processor->processarPorLogId($logId);
        if ($r['ok']) {
            $totalOk++;
            log_v("  ok  #{$logId} ({$p['tipo']}): {$r['motivo']}");
        } else {
            $totalFail++;
            log_v("  err #{$logId} ({$p['tipo']}): {$r['motivo']}");
        }
    } catch (\Throwable $e) {
        $totalFail++;
        log_v("  exc #{$logId}: " . $e->getMessage());
        if (class_exists('LogService')) {
            LogService::error('[webhook-retry-worker] exceção: ' . $e->getMessage(), [
                'log_id' => $logId,
                'file'   => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }
}

$duracao = round(microtime(true) - $inicio, 2);
log_v("fim: ok={$totalOk}, fail={$totalFail}, duracao={$duracao}s");

if (class_exists('LogService') && method_exists('LogService', 'audit')) {
    LogService::audit('webhook_retry_worker_run', [
        'processados' => count($pendentes),
        'ok'          => $totalOk,
        'fail'        => $totalFail,
        'duracao_s'   => $duracao,
    ]);
}

exit(0);
