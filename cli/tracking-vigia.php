<?php
declare(strict_types=1);

/**
 * cli/tracking-vigia.php
 *
 * Vigia do pipeline de conversões. Duas checagens, duas cadências:
 *
 *   1. Fila (padrão)      — a fila está escoando? Roda de minutos em minutos.
 *   2. Reconciliação      — todo pedido aprovado virou Purchase? Roda 1x/dia.
 *
 * POR QUE AS DUAS: a checagem da fila só enxerga eventos que NASCERAM.
 * Se o gatilho do Purchase não disparar, não existe linha para ficar
 * pendente — a fila fica verde e a venda nunca é reportada. Só a
 * reconciliação contra a tabela `pedidos` pega esse caso.
 *
 * Uso:
 *   php cli/tracking-vigia.php                          # checa a fila
 *   php cli/tracking-vigia.php --reconciliar            # reconcilia ontem
 *   php cli/tracking-vigia.php --reconciliar=2026-09-07 # dia específico
 *   php cli/tracking-vigia.php ... --silencioso         # só age, não imprime
 *
 * Cron sugerido:
 *   Fila:          [asterisco]/10 * * * *  php cli/tracking-vigia.php --silencioso
 *   Reconciliação: 0 6 * * *                php cli/tracking-vigia.php --reconciliar --silencioso
 *
 * As notificações têm janela de silêncio própria (TrackingAlertaService),
 * então rodar de 10 em 10 minutos não vira spam no sino.
 *
 * Somente leitura sobre o tracking: nada é reprocessado nem corrigido
 * aqui — o vigia observa e avisa.
 */

require __DIR__ . '/../bootstrap-cli.php';

$args       = $argv ?? [];
$silencioso = in_array('--silencioso', $args, true);

// --reconciliar ou --reconciliar=AAAA-MM-DD
$dia          = null;
$reconciliar  = false;
foreach ($args as $a) {
    if ($a === '--reconciliar') {
        $reconciliar = true;
    } elseif (str_starts_with($a, '--reconciliar=')) {
        $reconciliar = true;
        $dia = substr($a, strlen('--reconciliar='));
    }
}

/** Imprime só quando não está silencioso (no cron, o silêncio é a norma). */
$diz = static function (string $linha) use ($silencioso): void {
    if (!$silencioso) { echo $linha . "\n"; }
};

try {
    if ($reconciliar) {

        $rec = TrackingHealthService::reconciliar($dia);

        if ($rec['erro']) {
            $diz('[ERRO] nao foi possivel reconciliar ' . $rec['dia']);
            exit(1);
        }

        $diz("Reconciliacao de {$rec['dia']}");
        $diz(sprintf('  pedidos aprovados: %d', $rec['aprovados']));
        $diz(sprintf('  Purchase enviado:  %d', $rec['enviados']));
        $diz(sprintf('  sem consentimento: %d  (conforme, nao e perda)', $rec['pulados']));
        $diz(sprintf('  ainda na fila:     %d', $rec['na_fila']));
        $diz(sprintf('  falhados:          %d', $rec['falhados']));
        $diz(sprintf('  sem evento algum:  %d', $rec['ausentes']));
        $diz(sprintf('  DIVERGENCIA:       %d', $rec['divergencia']));

        if (!empty($rec['exemplos'])) {
            $diz('  exemplos: ' . implode(', ', $rec['exemplos']));
        }

        // Dia inteiro sem casar costuma ser event_id legado (id numerico),
        // nao venda perdida. Dizer isso evita uma cacada ao fantasma.
        if ($rec['aprovados'] > 0 && $rec['ausentes'] === $rec['aprovados']) {
            $diz('  AVISO: 100% ausente. Se este dia e anterior a correcao do');
            $diz('         event_id (codigo em vez de id), o descasamento e esperado.');
        }

        $a = TrackingAlertaService::verificarReconciliacao($rec);
        $diz('  alertas: ' . (empty($a['disparados']) ? 'nenhum' : implode(', ', $a['disparados'])));
        if (!empty($a['silenciados'])) {
            $diz('  silenciados (janela): ' . implode(', ', $a['silenciados']));
        }

        exit($rec['divergencia'] > 0 ? 2 : 0);
    }

    // ── Checagem da fila ──────────────────────────────────────────
    $r = TrackingHealthService::resumo();

    if ($r['erro']) {
        $diz('[ERRO] nao foi possivel ler o estado da fila');
        TrackingAlertaService::verificarFila();
        exit(1);
    }

    $diz(sprintf(
        'Fila: %s | enviados24h=%d pulados24h=%d pendentes=%d atrasados=%d presos=%d falhados=%d dead=%d',
        $r['ok'] ? 'OK' : 'ALERTA',
        $r['enviados_24h'], $r['pulados_24h'], $r['pendentes'],
        $r['atrasados'], $r['presos'], $r['falhados'], $r['dead_abertos']
    ));

    $a = TrackingAlertaService::verificarFila();
    $diz('  alertas: ' . (empty($a['disparados']) ? 'nenhum' : implode(', ', $a['disparados'])));
    if (!empty($a['silenciados'])) {
        $diz('  silenciados (janela): ' . implode(', ', $a['silenciados']));
    }

    exit($r['ok'] ? 0 : 2);

} catch (\Throwable $e) {
    LogService::exception($e, 'critical', 'tracking', [
        'origem' => 'cli/tracking-vigia.php',
    ]);
    echo '[ERRO] ' . $e->getMessage() . "\n";
    exit(1);
}
