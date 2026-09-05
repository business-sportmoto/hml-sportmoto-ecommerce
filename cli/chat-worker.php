<?php
/**
 * cli/chat-worker.php
 *
 * Worker do módulo Chat. Cuida do que é temporal — a conversa em si acontece
 * de forma síncrona no webhook, não aqui.
 *
 * FASES:
 *   A) Timeouts        — sessões que esperavam resposta e o prazo estourou
 *   B) Sessões prontas — nós "esperar" que acordaram
 *   C) Campanhas       — consome a fila respeitando ritmo_por_minuto
 *   D) Sino            — cliente esperando resposta, canal falhando
 *   E) Cupom           — carrinho parado há N horas de quem veio do direct
 *   F) Eventos da loja — fila do site que virou hora de iniciar fluxo
 *   G) Limpeza         — log de webhook antigo (1x por hora)
 *
 * Cron (crontab -u www-data -e):
 *   * * * * * cd /caminho/do/projeto && php cli/chat-worker.php --verbose >> storage/logs/chat-worker.log 2>&1
 *
 * Flags:
 *   --verbose   imprime o que está fazendo
 *   --once      roda uma passada e sai (padrão)
 *   --duracao=N segundos de execução contínua (para rodar como serviço)
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Só roda em CLI.\n"); exit(1); }

$argv    = $argv ?? [];
$verbose = in_array('--verbose', $argv, true);

$duracao = 0;
foreach ($argv as $a) {
    if (str_starts_with($a, '--duracao=')) $duracao = max(0, (int)substr($a, 10));
}

$ROOT = dirname(__DIR__);
chdir($ROOT);

require_once $ROOT . '/config/defines.php';
require_once $ROOT . '/config/config.php';
require_once $ROOT . '/config/database.php';
if (is_file($ROOT . '/vendor/autoload.php')) require_once $ROOT . '/vendor/autoload.php';

spl_autoload_register(function (string $class) use ($ROOT): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $class)) return;
    foreach (['/core/', '/app/controllers/', '/app/models/', '/app/helpers/',
              '/app/services/', '/app/services/email/', '/app/services/email/providers/',
              '/app/services/ia/', '/app/services/ia/providers/'] as $p) {
        $f = $ROOT . $p . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

// As classes de nó vivem dentro do registry — o autoloader não as acha
require_once $ROOT . '/app/services/ChatNoRegistry.php';

$log = function (string $m) use ($verbose): void {
    if ($verbose) echo '[' . date('H:i:s') . "] $m\n";
};

// ── Lock: duas instâncias processariam a mesma sessão ────────────────────────
$lockFile = $ROOT . '/storage/locks/chat-worker.lock';
@mkdir(dirname($lockFile), 0775, true);
$fp = @fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    if ($verbose) echo "Outro chat-worker já está rodando.\n";
    if ($fp) fclose($fp);
    exit(0);
}

$inicio = time();
$rodada = 0;

do {
    $rodada++;
    $t0 = microtime(true);

    try {
        // ── A. Timeouts de espera por resposta ──
        $motor = new ChatFluxoMotor();
        $n = $motor->resolverTimeouts(200);
        if ($n > 0) $log("timeouts resolvidos: $n");

        // ── B. Sessões prontas (esperas que acordaram) ──
        $stats = $motor->processarProntas(150, 60);
        if (($stats['processadas'] ?? 0) > 0) {
            $log('sessões: ' . json_encode($stats, JSON_UNESCAPED_UNICODE));
        }

        // ── C. Campanhas ──
        $camp = new ChatCampanhaService();
        foreach ($camp->pendentes() as $c) {
            $id = (int)$c['id'];

            // Uma campanha 'agendada' cuja hora chegou precisa materializar a
            // fila antes de consumir — iniciar() faz isso e muda para 'enviando'
            $atual = $camp->obter($id);
            if ($atual && $atual['status'] === 'agendada') {
                $r = $camp->iniciar($id);
                $log("campanha $id iniciada: " . json_encode($r, JSON_UNESCAPED_UNICODE));
                if (empty($r['ok'])) continue;
            }

            // O worker roda a cada minuto, então o lote de um ciclo é o ritmo
            // por minuto. Teto de 200 protege contra config exagerada.
            $lote = max(1, min(200, (int)$c['ritmo_por_minuto']));
            $res  = $camp->processarLote($id, $lote);

            if (($res['enviados'] + $res['falhas'] + $res['pulados']) > 0) {
                $log("campanha $id: {$res['enviados']} enviados, "
                   . "{$res['falhas']} falhas, {$res['pulados']} pulados"
                   . ($res['fim'] ? ' [concluída]' : ''));
            }
        }

        // ── D. Sino: o que ninguém vê acontecer ──
        // Cliente esperando e canal falhando não geram evento nenhum — são
        // ausências. Só um worker olhando o relógio percebe.
        $notif = new ChatNotificacaoService();

        $esperando = $notif->semResposta();
        if ($esperando > 0) $log("sem resposta: $esperando conversa(s) avisada(s)");

        if ($notif->falhasDeEnvio()) $log('falhas de envio: gestores avisados');

        // ── E. Cupom para quem veio do direct e não fechou ──
        // REDE DE SEGURANÇA, não o caminho principal: quem recupera carrinho
        // hoje é o fluxo (fase F). Esta régua cobre o que o fluxo não alcança
        // — carrinho SEM cliente_id, que o produtor de eventos descarta no
        // `JOIN clientes`. Ela pula todo carrinho que o fluxo já assumiu, então
        // ninguém recebe cupom em dobro.
        $cupons = (new ChatCupomCarrinhoService())->enviarPendentes(30);
        if ($cupons > 0) $log("cupons de carrinho enviados: $cupons");

        // ── F. Eventos da loja ──
        // A loja enfileira (carrinho abandonado, pedido...) e a espera até o
        // disparo mora na fila, não num bloco `esperar`: sessão dormindo horas
        // seria morta pelo encerrarSessoesAbertas() do próximo fluxo que o
        // contato acionasse. Ver [[evento-loja-como-porta-unica]].
        $ev = (new ChatEventoLojaService())->processarPendentes(50);
        if (array_sum($ev) > 0) {
            $log('eventos da loja: ' . json_encode($ev, JSON_UNESCAPED_UNICODE));
        }

        // ── G. Limpeza (1x por hora) ──
        if ((int)date('i') === 3) {
            $apagados = (new ChatWebhookService())->limparLogAntigo(15);
            if ($apagados > 0) $log("log de webhook: $apagados linha(s) antiga(s) removida(s)");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] chat-worker: ' . $e->getMessage()
                     . ' em ' . $e->getFile() . ':' . $e->getLine() . "\n");
        if (class_exists('LogService')) {
            try { LogService::error('chat-worker: ' . $e->getMessage(), [], 'chat'); }
            catch (Throwable $x) {}
        }
    }

    $log(sprintf('rodada %d em %.2fs', $rodada, microtime(true) - $t0));

    if ($duracao > 0) {
        $restante = $duracao - (time() - $inicio);
        if ($restante > 0) sleep(min(5, $restante));
    }
} while ($duracao > 0 && (time() - $inicio) < $duracao);

flock($fp, LOCK_UN);
fclose($fp);
exit(0);
