<?php
/**
 * cli/vida-util-worker.php
 *
 * Dicas de cuidado por vida útil. Roda DIÁRIO (não precisa ser de minuto —
 * os prazos são de meses).
 *
 * Cron (crontab -u www-data -e) — todo dia às 09:00, horário civilizado:
 *   0 9 * * * cd /home/homo-v2.sportmoto.com.br/public_html && /usr/local/lsws/lsphp82/bin/php cli/vida-util-worker.php --verbose >> storage/logs/vida-util-worker.log 2>&1
 *
 * Flags:
 *   --verbose        loga cada etapa
 *   --so-agendar     só varre o histórico (não envia nada)
 *   --so-disparar    só envia o que já venceu
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Só roda em CLI.\n"); exit(1); }
$argvSafe    = $argv ?? [];
$verbose     = in_array('--verbose', $argvSafe, true);
$soAgendar   = in_array('--so-agendar', $argvSafe, true);
$soDisparar  = in_array('--so-disparar', $argvSafe, true);

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
$lockFile = $ROOT . '/storage/locks/vida-util-worker.lock';
@mkdir(dirname($lockFile), 0775, true);
$fp = @fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    if ($verbose) echo "Outro vida-util-worker já está rodando.\n";
    if ($fp) fclose($fp);
    exit(0);
}
register_shutdown_function(function () use (&$fp, $lockFile) {
    if (is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); }
    @unlink($lockFile);
});

$log('vida-util-worker iniciado (pid ' . getmypid() . ')');

try {
    $svc = new VidaUtilService();

    // ── FASE A: agendamento (histórico de status → agenda) ──
    if (!$soDisparar) {
        $log('--- FASE A: varredura do histórico de status ---');
        $a = $svc->agendar(500);
        $log(sprintf('  lidos=%d pedidos_entregues=%d agendadas=%d canceladas=%d',
            $a['lidos'], $a['pedidos'], $a['agendadas'], $a['canceladas']));
    }

    // ── FASE B: disparo das dicas vencidas ──
    if (!$soAgendar) {
        $log('--- FASE B: disparo das dicas vencidas ---');
        $d = $svc->disparar(200);
        $log(sprintf('  devidas=%d enviadas=%d agrupadas=%d adiadas=%d sem_permissao=%d',
            $d['devidas'], $d['enviadas'], $d['agrupadas'], $d['adiadas'], $d['sem_permissao']));
    }

} catch (Throwable $e) {
    $log('ERRO: ' . $e->getMessage());
    if (class_exists('LogService')) {
        try { LogService::error('vida-util-worker: ' . $e->getMessage(), ['trace' => $e->getFile() . ':' . $e->getLine()]); }
        catch (Throwable $x) {}
    }
}

$log('encerrado');
exit(0);
