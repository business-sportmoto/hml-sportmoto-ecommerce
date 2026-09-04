<?php
/**
 * bin/carrinhos-abandonados-processar.php
 *
 * Ploi Scheduler -> Command: php /home/ploi/SITE/bin/carrinhos-abandonados-processar.php
 *                   Frequency: Every 30 minutes
 *
 * O cabecalho era um bloco HTML ANTES do <?php: no CLI isso era impresso como
 * saida e sujava o log do agendador a cada 30 minutos.
 *
 * Este cron NAO carrega o motor de fluxos. `emitirEventosDeAbandono()` apenas
 * enfileira; quem processa e o `cli/chat-worker.php`, que tem o autoloader
 * completo. Por isso a lista de diretorios abaixo nao precisa de
 * `app/services/ia/`.
 */

 
if (PHP_SAPI !== 'cli') { exit(1); }
 
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

 
$inicio = microtime(true);
 
try {
    $svc = new CarrinhoRecuperacaoService();
 
    $novos       = $svc->detectarAbandonados();
    $recuperados = $svc->reconciliarRecuperados();
    $liberados = $svc->liberarCapturasExpiradas();

    // Enfileira os recem-detectados para o motor de fluxos. So INSERT --
    // nada aqui inicia fluxo nem toca em API externa.
    $rota        = $svc->emitirEventosDeAbandono(200);
    $eventos     = $rota['enfileirados'];
    $humanos     = $rota['para_humano'];

    $sugestoes   = $svc->contarSugestaoPerdidos();
 
    echo (sprintf(
        '[carrinhos-cron] ok liberados=%d novos=%d recuperados=%d eventos=%d humanos=%d sugerir_perdido=%d dur=%dms',
        $liberados, $novos, $recuperados, $eventos, $humanos, $sugestoes,
        (int)round((microtime(true) - $inicio) * 1000)
    ));
    exit(0);
 
} catch (\Throwable $e) {
    echo ('[carrinhos-cron] FALHA: ' . $e->getMessage());
    exit(1);
}
