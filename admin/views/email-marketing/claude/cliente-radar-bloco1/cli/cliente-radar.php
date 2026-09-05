<?php
/**
 * cli/cliente-radar.php
 *
 * Worker DIÁRIO do radar de clientes. Roda as sondas (aniversário,
 * inatividade, saldo expirando) e emite eventos no stream; o fluxo-worker
 * pega os eventos no ciclo seguinte e dispara as jornadas.
 *
 * Cron sugerido (www-data), 1×/dia de manhã, DEPOIS do fluxo-worker acordar:
 *   30 8 * * *  /usr/local/lsws/lsphp82/bin/php /caminho/cli/cliente-radar.php >> storage/logs/cliente-radar.log 2>&1
 *
 * Flags:
 *   --verbose   imprime o resultado de cada sonda
 *   --dry-run   NÃO emite nada — só conta quantos SERIAM emitidos
 *               (útil na primeira execução para dimensionar o volume)
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Só roda em CLI.\n"); exit(1); }

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

$opts    = getopt('', ['verbose', 'dry-run']);
$verbose = array_key_exists('verbose', $opts);
$dryRun  = array_key_exists('dry-run', $opts);

$log = function (string $msg) use ($verbose) {
    if ($verbose) echo '[' . date('H:i:s') . "] $msg\n";
};

// ── Lock: um radar por vez (padrão dos outros workers) ───────────────────────
$lockDir  = $ROOT . '/storage/locks';
if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
$lockFile = $lockDir . '/cliente-radar.lock';
$fp = fopen($lockFile, 'c');
if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
    $log('outro radar em execução — saindo');
    exit(0);
}

$log('radar iniciado' . ($dryRun ? ' (DRY-RUN)' : ''));

try {
    if ($dryRun) {
        // Conta sem emitir: reusa as queries via um contador simples
        contarDryRun($log);
    } else {
        $radar = new ClienteRadarService();
        $stats = $radar->varrer();
        $log(sprintf(
            'aniversário=%d · inatividade=%d · saldo_expirando=%d · emissões purgadas=%d',
            $stats['aniversario'], $stats['inatividade'],
            $stats['saldo_expirando'], $stats['purgados']
        ));
        $total = $stats['aniversario'] + $stats['inatividade'] + $stats['saldo_expirando'];
        if ($total > 0 && class_exists('LogService')) {
            try { LogService::info('cliente-radar', $stats); } catch (Throwable $x) {}
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    if (class_exists('LogService')) {
        try { LogService::error('cliente-radar worker', ['erro' => $e->getMessage()]); } catch (Throwable $x) {}
    }
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}

$log('radar encerrado');
exit(0);

/**
 * Dry-run: conta candidatos sem emitir nada nem gravar anti-repetição.
 * Aproximação (não desconta quem já foi emitido) — serve para dimensionar.
 */
function contarDryRun(callable $log): void
{
    $db = Database::getInstance()->getConnection();

    $aniv = (int)$db->query(
        "SELECT COUNT(*) FROM clientes c JOIN usuarios u ON u.id=c.usuario_id
         WHERE c.nascimento IS NOT NULL AND u.deleted_at IS NULL
           AND MONTH(c.nascimento)=" . (int)date('n') . " AND DAY(c.nascimento)=" . (int)date('j')
    )->fetchColumn();

    $limite30 = date('Y-m-d H:i:s', time() - 30 * 86400);
    $inativos = (int)$db->prepare(
        "SELECT COUNT(*) FROM clientes c JOIN usuarios u ON u.id=c.usuario_id
         WHERE u.ultimo_login IS NOT NULL AND u.deleted_at IS NULL AND u.ultimo_login <= ?"
    )->execute([$limite30]) ? 0 : 0;
    $st = $db->prepare(
        "SELECT COUNT(*) FROM clientes c JOIN usuarios u ON u.id=c.usuario_id
         WHERE u.ultimo_login IS NOT NULL AND u.deleted_at IS NULL AND u.ultimo_login <= ?"
    );
    $st->execute([$limite30]);
    $inativos = (int)$st->fetchColumn();

    $limiteSaldo = date('Y-m-d H:i:s', time() + 7 * 86400);
    $st = $db->prepare(
        "SELECT COUNT(*) FROM cliente_saldo_transacoes t JOIN clientes c ON c.id=t.cliente_id
         WHERE t.expira_em IS NOT NULL AND t.expirado=0
           AND t.tipo IN ('credito_devolucao','credito_manual','credito_promo')
           AND t.expira_em > NOW() AND t.expira_em <= ? AND c.saldo_disponivel >= 0.01"
    );
    $st->execute([$limiteSaldo]);
    $saldo = (int)$st->fetchColumn();

    $log("DRY-RUN — aniversariantes hoje: $aniv · inativos (30d+): $inativos · créditos expirando (7d): $saldo");
    $log('(dry-run não desconta emissões já feitas nem quebra inatividade por limiar)');
}
