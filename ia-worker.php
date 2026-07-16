<?php
/**
 * ia-worker.php — worker da Central de Marketing IA (Fase 1: texto).
 *
 * Roda via cron a cada minuto com loop interno (~55s), no padrão dos demais
 * workers do projeto (email-worker/csv-import-worker):
 *
 *   * * * * * /usr/local/lsws/lsphp82/bin/php /home/homo-v2.sportmoto.com.br/public_html/ia-worker.php --loop=55 --verbose >> /home/homo-v2.sportmoto.com.br/public_html/storage/logs/ia-worker.log 2>&1
 *
 * Opções:
 *   --loop=55    duração do ciclo em segundos (5–300)
 *   --lote=3     gerações reivindicadas por vez (1–10)
 *   --verbose    imprime cada passo no stdout
 *
 * Lock interno via flock (arquivo em storage/locks) — instância única,
 * sem flock externo no crontab.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}


/* ------------------------------------------------------------------ */
/* Bootstrap                                                            */
/* AJUSTE: espelhe exatamente o cabeçalho de includes do email-worker.php */
/* (cadeia padrão do projeto: defines -> config -> database -> autoload) */
/* ------------------------------------------------------------------ */
require __DIR__ . '/config/defines.php';
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/database.php';
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

// Autoloader
spl_autoload_register(function (string $class): void {
    $paths = [
        ROOT_PATH . '/core/',
        ROOT_PATH . '/app/controllers/',
        ROOT_PATH . '/app/models/',
        ROOT_PATH . '/app/helpers/',
        ROOT_PATH . '/app/services/',
        ROOT_PATH . '/app/services/payment/',
        ROOT_PATH . '/app/services/email/',
        ROOT_PATH . '/app/services/email/providers/', 
        ROOT_PATH . '/app/services/ia/',
        ROOT_PATH . '/app/services/ia/providers/',        
    ];
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
    // Descomente para debug temporário:
    // throw new RuntimeException("Classe '{$class}' não encontrada em: " . implode(', ', $paths));
});

/* ------------------------------------------------------------------ */
/* Opções                                                               */
/* ------------------------------------------------------------------ */
$opts         = getopt('', ['loop::', 'lote::', 'verbose']);
$loopSegundos = isset($opts['loop']) ? max(5, min(300, (int) $opts['loop'])) : 55;
$tamanhoLote  = isset($opts['lote']) ? max(1, min(10, (int) $opts['lote'])) : 3;
$verbose      = array_key_exists('verbose', $opts);

$log = function (string $msg) use ($verbose): void {
    if ($verbose) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
};

/* ------------------------------------------------------------------ */
/* Lock de instância única                                              */
/* ------------------------------------------------------------------ */
$dirLock = __DIR__ . '/storage/locks';
if (!is_dir($dirLock) && !mkdir($dirLock, 0750, true) && !is_dir($dirLock)) {
    fwrite(STDERR, "Não foi possível criar {$dirLock}\n");
    exit(1);
}

$lock = fopen($dirLock . '/ia-worker.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log('Outra instância do ia-worker já está em execução — saindo.');
    exit(0);
}
ftruncate($lock, 0);
fwrite($lock, (string) getmypid());

/* ------------------------------------------------------------------ */
/* Ciclo                                                                */
/* ------------------------------------------------------------------ */
$inicio      = time();
$processadas = 0;

try {
    $geracoes = new IAGeracao();
    $servico  = new IAGeracaoService();
    $orq      = new IAOrchestrator();

    // Watchdog: devolve à fila jobs presos de execuções anteriores
    $geracoes->recuperarPresos(10);

    $log("Ciclo iniciado (loop={$loopSegundos}s, lote={$tamanhoLote}).");

    while ((time() - $inicio) < $loopSegundos) {
        // Varredura assíncrona: consulta predictions aguardando o provedor
        // (cobre webhook perdido e é O caminho em dev, sem URL pública).
        $pendentes = $geracoes->listarAguardando(5, 20);
        foreach ($pendentes as $p) {
            try {
                $adapter = $orq->adapterPorCodigo((string) $p['provedor_codigo']);
                if (!$adapter instanceof ReplicateAdapter) {
                    continue;
                }
                $remoto    = $adapter->consultarPrediction((string) $p['external_id']);
                $desfecho  = $servico->processarRetornoProvedor($p, $remoto, $adapter);
                if ($desfecho !== 'pendente') {
                    $log("Varredura: geração #{$p['id']} → {$desfecho}.");
                    $processadas++;
                }
            } catch (Throwable $e) {
                LogService::error('ia_worker_varredura_erro', [
                    'geracao_id' => (int) $p['id'],
                    'erro'       => $e->getMessage(),
                ]);
            }
        }

        $lote = $geracoes->reivindicarLote($tamanhoLote);

        if (empty($lote)) {
            usleep(2000000); // fila vazia — respira 2s
            continue;
        }

        foreach ($lote as $g) {
            try {
                $log("Processando geração #{$g['id']} ({$g['tipo_nome']})…");

                $tipo = [
                    'instrucoes_sistema' => $g['instrucoes_sistema'] ?? null,
                    'max_tokens'         => $g['max_tokens'] ?? null,
                    'modelo_id'          => $g['tipo_modelo_id'] ?? null,
                    'nome'               => $g['tipo_nome'] ?? '',
                ];

                $r = (($g['capacidade'] ?? 'texto') === 'imagem')
                    ? $orq->executarImagem($g, $tipo)
                    : $orq->executarTexto($g, $tipo);

                if ($r->aguardando) {
                    $servico->aguardar($g, $r);
                    $log("Geração #{$g['id']} aceita pelo provedor ({$r->modeloCodigo}) — ref {$r->externalId}.");
                } elseif ($r->ok) {
                    $servico->concluir($g, $r);
                    $log("Geração #{$g['id']} concluída via {$r->modeloCodigo} em {$r->tempoMs}ms" .
                         ($r->custoRealUsd !== null ? ' (US$ ' . number_format($r->custoRealUsd, 6, '.', '') . ')' : '') . '.');
                    $processadas++;
                } else {
                    $servico->falhar($g, $r);
                    $log("Geração #{$g['id']} FALHOU: [{$r->erroCodigo}] {$r->erro}");
                    $processadas++;
                }
            } catch (Throwable $e) {
                LogService::error('ia_worker_excecao_job', [
                    'geracao_id' => (int) $g['id'],
                    'erro'       => $e->getMessage(),
                ]);
                $geracoes->marcarFalha((int) $g['id'], '[worker] ' . $e->getMessage(), 0);
                $log("Geração #{$g['id']} abortada por exceção: " . $e->getMessage());
            }
        }
    }
} catch (Throwable $e) {
    LogService::error('ia_worker_excecao_fatal', ['erro' => $e->getMessage()]);
    fwrite(STDERR, '[fatal] ' . $e->getMessage() . PHP_EOL);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

$log("Ciclo encerrado — {$processadas} geração(ões) processada(s).");
exit(0);
