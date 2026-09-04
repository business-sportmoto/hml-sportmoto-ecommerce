#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * cli/ia-agentes-worker.php — os agentes de BI fora da tela.
 *
 * Dois modos (spec §16 e §17):
 *
 *   AGENDADO  — uma análise por agente por dia, com a pergunta padrão
 *               ("resumo executivo"). Dedup por dia: rodar de novo não
 *               gasta. O overview lê a última rodada como "Resumo
 *               Executivo de Hoje" sem chamar IA.
 *
 *   EVENTO    — só quando o SISTEMA (BiService::alertas) já detectou um
 *               alerta crítico: ruptura ≤ 7 dias, queda de faturamento
 *               ≥ 20%. Um disparo por alerta por dia. Sem alerta, sem
 *               chamada — é o que evita a IA virar custo fixo.
 *
 * Prioridade ALTA em qualquer dos dois avisa todos os admins pelo sino
 * (NotificacaoService::criarBroadcast).
 *
 * Cron (crontab -u www-data -e) — escalonado por agente, como a spec:
 *   0 6 * * *    cd /caminho && php cli/ia-agentes-worker.php --agente=agente_financeiro >> storage/logs/ia-agentes.log 2>&1
 *   0 7 * * *    cd /caminho && php cli/ia-agentes-worker.php --agente=agente_estoque    >> storage/logs/ia-agentes.log 2>&1
 *   0 8 * * *    cd /caminho && php cli/ia-agentes-worker.php --agente=agente_analytics  >> storage/logs/ia-agentes.log 2>&1
 *   0,30 * * * * cd /caminho && php cli/ia-agentes-worker.php --modo=evento             >> storage/logs/ia-agentes.log 2>&1
 *   (a cada 30 min escrito como "0,30" porque "asterisco-barra" fecharia este comentário)
 *
 * Flags:
 *   --agente=CODIGO   roda a análise agendada de um agente
 *   --todos           roda a agendada dos três (para teste manual)
 *   --modo=evento     varre os alertas críticos
 *   --forcar          ignora a dedup diária do agendado
 *   --simular         resolve tudo (agente, pré-carga, dedup, limites) mas NÃO
 *                     chama o modelo — sai como "sem modelo". Para conferir o
 *                     cron sem gastar, e para os testes.
 *   --verbose         imprime o que está fazendo
 *
 * Sai com 0 quando não há o que fazer (já rodou hoje, sem alerta, sem
 * provedor configurado) — cron não precisa alarmar por isso — e 1 só em
 * erro de execução.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Só roda em CLI.\n"); exit(1); }

require_once __DIR__ . '/../bootstrap-cli.php';

$opts    = getopt('', ['agente::', 'todos', 'modo::', 'forcar', 'simular', 'verbose']);
$verbose = array_key_exists('verbose', $opts);
$forcar  = array_key_exists('forcar', $opts);
$simular = array_key_exists('simular', $opts);
$modo    = (string) ($opts['modo'] ?? 'agendado');

$log = function (string $m) use ($verbose): void {
    if ($verbose) echo '[' . date('H:i:s') . "] {$m}\n";
};

// ── Lock por modo: agendado e evento podem coexistir; dois agendados não.
$lockFile = ROOT_PATH . '/storage/locks/ia-agentes-' . preg_replace('/[^a-z]/', '', $modo) . '.lock';
@mkdir(dirname($lockFile), 0775, true);
$fp = @fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    $log('Outro ia-agentes-worker (' . $modo . ') já está rodando.');
    if ($fp) fclose($fp);
    exit(0);
}

// --simular: um orquestrador sem candidatos. Tudo antes do modelo roda de
// verdade (whitelist, pré-carga, dedup, teto) e a chamada vira 'sem_modelos'.
$svc = $simular
    ? new IAAgenteService(new class extends IAOrchestrator {
          public function modelosDaCapacidade(string $c, ?int $o): array { return []; }
      })
    : new IAAgenteService();
if ($simular) $log('MODO SIMULAÇÃO: nenhuma chamada ao modelo será feita.');
$saida = 0;

try {
    if ($modo === 'evento') {
        $r = $svc->rodarPorEvento();
        if (!$r['ok']) {
            $log('Falha: ' . ($r['msg'] ?? '?'));
            $saida = 1;
        } else {
            $log(count($r['disparados']) . ' alerta(s) crítico(s) analisado(s), ' . $r['ignorados'] . ' ignorado(s) (não crítico ou já tratado hoje).');
            foreach ($r['disparados'] as $d) {
                $log(sprintf('  %s → %s: %s%s', $d['alerta'], $d['agente'],
                    $d['ok'] ? 'prioridade ' . ($d['prioridade'] ?? '—') : 'FALHOU — ' . ($d['msg'] ?? '?'),
                    !empty($d['notificado']) ? ' · admins avisados' : ''));
            }
        }
    } else {
        $agentes = array_key_exists('todos', $opts)
            ? IAAgenteGateway::AGENTES
            : [preg_replace('/[^a-z_]/', '', (string) ($opts['agente'] ?? ''))];

        if ($agentes === ['']) {
            fwrite(STDERR, "Informe --agente=agente_financeiro|agente_estoque|agente_analytics, --todos ou --modo=evento.\n");
            exit(1);
        }

        foreach ($agentes as $agente) {
            $r = $svc->rodarAgendado($agente, $forcar);
            if (!empty($r['pulado'])) { $log("{$agente}: já rodou hoje (use --forcar)."); continue; }
            if (!$r['ok']) {
                $log("{$agente}: FALHOU — " . ($r['msg'] ?? '?'));
                // Sem provedor/teto não é erro do worker: sai limpo, o
                // diagnóstico (cli/bi-diagnostico.php) diz o que falta.
                if (!in_array($r['erro'] ?? '', ['sem_modelos', 'chave_invalida'], true)) $saida = 1;
                continue;
            }
            $log(sprintf('%s: ok · prioridade %s · %s · US$ %s · %d rodada(s)%s',
                $agente, $r['prioridade'] ?? '—', $r['procedencia']['modelo'] ?? '?',
                number_format((float) ($r['procedencia']['custo_usd'] ?? 0), 4, '.', ''),
                (int) ($r['procedencia']['rodadas'] ?? 0),
                !empty($r['notificado']) ? ' · admins avisados' : ''));
        }
    }
} catch (\Throwable $e) {
    LogService::exception($e, 'error', 'ia', ['worker' => 'ia-agentes', 'modo' => $modo]);
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
    $saida = 1;
}

flock($fp, LOCK_UN);
fclose($fp);
exit($saida);
