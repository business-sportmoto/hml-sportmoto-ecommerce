<?php
/**
 * Worker de rastreio — cli/logistica-rastreio-worker.php
 *
 * O que faz (idempotente, seguro para rodar em paralelo com lock):
 *   1) abre rastreios para etiquetas emitidas/postadas que ainda nao tem um;
 *   2) atualiza os rastreios nao-finais cuja cadencia ja venceu
 *      (RastreioService::intervaloPorStatus).
 *
 * Lock: arquivo em storage/logs com verificacao de idade (PHP puro, sem flock
 * externo). Se um lock recente existe, sai sem processar.
 *
 * AJUSTE: o require do bootstrap abaixo assume o encadeamento do projeto
 * (config.php -> defines/database/autoload). Se o seu entrypoint for outro
 * (ex.: app/bootstrap.php), troque a linha indicada.
 */

// Crontab (a cada 10 min basta; a cadencia real e adaptativa por status):
//   */10 * * * * cd /home/ploi/hml.sportmoto.com.br && php cli/logistica-rastreio-worker.php --verbose  >> storage/logs/logistica-rastreio-worker.log 2>&1

declare(strict_types=1);

$RAIZ = dirname(__DIR__);
$verbose = in_array('--verbose', $argv ?? [], true);
$log = static function (string $m) use ($verbose) {
    if ($verbose) echo '[' . date('Y-m-d H:i:s') . "] $m\n";
};

/* ---------- lock (PHP puro, por idade de arquivo) ---------- */
$lockDir = $RAIZ . '/storage/logs';
if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
$lockFile = $lockDir . '/logistica-rastreio-worker.lock';
$maxRuntime = 240; // s — se o lock for mais novo que isso, assume que já roda

if (is_file($lockFile) && (time() - (int)@filemtime($lockFile)) < $maxRuntime) {
    $log('Outro worker em execução (lock recente). Saindo.');
    exit(0);
}
@file_put_contents($lockFile, (string)getmypid());
register_shutdown_function(static function () use ($lockFile) { @unlink($lockFile); });

/* ---------- bootstrap do app ---------- */
$bootstrap = $RAIZ . '/config/config.php'; // <-- AJUSTE se necessário
if (!is_file($bootstrap)) {
    fwrite(STDERR, "Bootstrap não encontrado em $bootstrap. Ajuste o caminho no worker.\n");
    exit(1);
}
require_once $bootstrap;

if (!class_exists('RastreioService')) {
    fwrite(STDERR, "RastreioService indisponível após o bootstrap (verifique o autoloader).\n");
    exit(1);
}

/* ---------- execução ---------- */
$t0 = microtime(true);
try {
    $svc = new RastreioService();

    $abertos = $svc->abrirPendentesDeEtiquetas(100);
    $log("Rastreios abertos a partir de etiquetas: $abertos");

    $res = $svc->atualizarPendentes(50);
    $log(sprintf(
        'Atualização: %d processados, %d atualizados, %d com novidade.',
        $res['processados'] ?? 0, $res['atualizados'] ?? 0, $res['com_novidade'] ?? 0
    ));

    // Preço/dados reais da postagem (Correios) para etiquetas já postadas.
    $precos = ['consultadas' => 0, 'atualizadas' => 0, 'pendentes' => 0];
    if (class_exists('EtiquetaService')) {
        $precos = (new EtiquetaService())->atualizarPrecosPostagem(50);
        $log(sprintf(
            'Postagem: %d consultadas, %d com preço atualizado, %d ainda não postadas.',
            $precos['consultadas'] ?? 0, $precos['atualizadas'] ?? 0, $precos['pendentes'] ?? 0
        ));
    }

    if (class_exists('LogService')) {
        LogService::info('Worker de rastreio executado', [
            'abertos'      => $abertos,
            'processados'  => $res['processados'] ?? 0,
            'atualizados'  => $res['atualizados'] ?? 0,
            'com_novidade' => $res['com_novidade'] ?? 0,
            'preco_consultadas' => $precos['consultadas'] ?? 0,
            'preco_atualizadas' => $precos['atualizadas'] ?? 0,
            'duracao_ms'   => (int)round((microtime(true) - $t0) * 1000),
        ], 'logistica');
    }
    $log(sprintf('Concluído em %d ms.', (int)round((microtime(true) - $t0) * 1000)));
} catch (\Throwable $e) {
    fwrite(STDERR, 'Erro no worker de rastreio: ' . $e->getMessage() . "\n");
    if (class_exists('LogService')) {
        LogService::error('Falha no worker de rastreio', ['erro' => $e->getMessage()]);
    }
    exit(1);
}
exit(0);