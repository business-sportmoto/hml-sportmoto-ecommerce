<?php
/**
 * cli/fluxo-worker.php
 *
 * Worker do motor de automação v2 — 2 fases:
 *   A) Detecta triggers (eventos novos do stream → inicia execuções)
 *   B) Processa execuções prontas (caminha no grafo)
 *
 * Cron (crontab -u www-data -e):
 *   * * * * * cd /home/ploi/hml.sportmoto.com.br && php cli/fluxo-worker.php --verbose >> storage/logs/fluxo-worker.log 2>&1
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
              '/app/services/','/app/services/email/','/app/services/email/providers/',
              // Nó agente_ia (Fase C): IAAgenteService, gateway e orquestrador
              '/app/services/ia/','/app/services/ia/providers/'] as $p) {
        $f = $ROOT . $p . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

// Classes de nó vivem dentro do registry — carga explícita
require_once $ROOT . '/app/services/FluxoNoRegistry.php';

$log = function (string $m) use ($verbose): void {
    if ($verbose) echo '[' . date('H:i:s') . "] $m\n";
};

// ── Lock ─────────────────────────────────────────────────────────────────────
$lockFile = $ROOT . '/storage/locks/fluxo-worker.lock';
@mkdir(dirname($lockFile), 0775, true);
$fp = @fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    if ($verbose) echo "Outro fluxo-worker já está rodando.\n";
    if ($fp) fclose($fp);
    exit(0);
}
register_shutdown_function(function () use (&$fp, $lockFile) {
    if (is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); }
    @unlink($lockFile);
});

$log("fluxo-worker iniciado (pid " . getmypid() . ")");

try {
    // ── FASE A0: o BI publica no stream (Fase C dos agentes de IA) ──
    // Alertas, metas em risco e o relógio (agenda_NNh) viram eventos que os
    // fluxos publicados escutam. Só publica o que alguém escuta; alertas e
    // metas no máximo a cada 15 min. Falha aqui nunca segura os fluxos.
    $log("--- FASE A0: eventos do BI ---");
    try {
        $sBi = (new BiEventoService())->publicar();
        $log(sprintf("  ouvintes=%d agenda=%d alertas=%d metas=%d%s",
            count($sBi['ouvintes']), $sBi['agenda'], $sBi['alertas'], $sBi['metas'],
            $sBi['pulado'] ? " (pulado: {$sBi['pulado']})" : ''));
    } catch (Throwable $e) {
        $log("  BI indisponível: " . $e->getMessage());
        if (class_exists('LogService')) {
            try { LogService::warning('fluxo-worker: BiEventoService: ' . $e->getMessage(), [], 'ia'); } catch (Throwable $x) {}
        }
    }

    // ── FASE A: triggers ──
    $log("--- FASE A: detecção de triggers ---");
    $trig = new FluxoTriggerService();
    $sa = $trig->detectar(2000);
    $log(sprintf("  eventos_lidos=%d execucoes_iniciadas=%d",
        $sa['eventos_lidos'], $sa['execucoes_iniciadas']));

    // ── FASE A2: resolução de esperas por evento (Fase 3A) ──
    $motor = new FluxoMotor();
    $log("--- FASE A2: resolução de esperas por evento ---");
    $resolvidas = $motor->resolverEsperasEvento(300);
    $log("  esperas_resolvidas=$resolvidas");

    // ── FASE A3: ponte de engajamento de email (Fase 3B) ──
    $log("--- FASE A3: ponte de engajamento de email ---");
    $bridge = new EmailEngajamentoBridge();
    $sBridge = $bridge->sincronizar(1000);
    $log(sprintf("  emails_lidos=%d stream=%d sem_cliente=%d",
        $sBridge['lidos'], $sBridge['stream'], $sBridge['sem_cliente']));

    // ── FASE B: execuções ──
    $log("--- FASE B: processamento de execuções ---");
    $sb = $motor->processarExecucoes(200, 150);
    $log(sprintf("  processadas=%d concluidas=%d dormindo=%d sairam=%d erros=%d",
        $sb['processadas'], $sb['concluidas'] ?? 0, $sb['dormindo'] ?? 0,
        $sb['sairam'] ?? 0, $sb['erros'] ?? 0));

} catch (Throwable $e) {
    $log("ERRO: " . $e->getMessage());
    if (class_exists('LogService')) {
        try { LogService::error('fluxo-worker: ' . $e->getMessage()); } catch (Throwable $x) {}
    }
}

// ── Purge diário do log de observabilidade (retenção configurável) ──
    if (class_exists('FluxoLogService')) {
        $apagados = FluxoLogService::purgarSeDevido(Database::getInstance()->getConnection());
        if ($apagados > 0) $log("log de passos: purge de {$apagados} linhas antigas");
    }


$log("worker encerrado");
exit(0);
