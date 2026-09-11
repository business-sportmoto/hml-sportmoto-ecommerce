#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * cli/analise-prazo.php
 *
 * Avisa os admins (sino) dos pedidos retidos na análise de risco há mais de
 * 48 horas — o prazo que a tela de pedido realizado promete ao cliente.
 *
 * Um aviso por pedido; volta ao topo a cada 24 h a mais sem decisão. A regra
 * inteira mora em AnalisePrazoService — este arquivo só roda e reporta.
 *
 * USO
 *   php cli/analise-prazo.php            → avisa
 *   php cli/analise-prazo.php --simular  → só mostra o que faria
 *
 * CRON (a cada 30 min):
 *   *\/30 * * * * cd /caminho && php cli/analise-prazo.php >> storage/logs/analise-prazo.log 2>&1
 */

require_once dirname(__DIR__) . '/bootstrap-cli.php';

$simular = in_array('--simular', $argv, true);

try {
    $r = (new AnalisePrazoService())->alertar($simular);

    printf(
        "[%s]%s atrasados=%d novos=%d reabertos=%d mantidos=%d sem_destinatario=%d\n",
        date('Y-m-d H:i:s'), $simular ? ' SIMULAÇÃO' : '',
        $r['atrasados'], $r['novos'], $r['reabertos'], $r['mantidos'], $r['sem_destinatario']
    );
    foreach ($r['pedidos'] as $p) {
        printf("  #%s  retido há %dh (desde %s)  → %s\n", $p['codigo'], $p['horas'], $p['retido_em'], $p['acao']);
    }

    // Sem ninguém para receber, o alerta não existe — isso é falha, não sossego.
    if ($r['sem_destinatario'] > 0) {
        LogService::error('Alerta de análise atrasada sem destinatário', [
            'pedidos' => $r['sem_destinatario'],
        ], 'pagamento');
    }
} catch (\Throwable $e) {
    LogService::exception($e, 'critical', 'pagamento', ['origem' => 'cli/analise-prazo.php']);
    echo '[ERRO] ' . $e->getMessage() . "\n";
    exit(1);
}
