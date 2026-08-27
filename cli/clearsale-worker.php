<?php
declare(strict_types=1);

/**
 * cli/clearsale-worker.php
 *
 * Busca os pareceres pendentes da ClearSale e resolve os pedidos retidos.
 *
 * POR QUE ESTE WORKER EXISTE:
 *   A análise da ClearSale é assíncrona. O envio, no checkout, sempre responde
 *   `NVO` — recebido, sem julgamento. O veredito nasce minutos depois, do lado
 *   deles, e não volta sozinho: ou se consulta o endpoint de status, ou nunca
 *   se fica sabendo.
 *
 *   A notificação (WebhookController::clearsale) chega antes e acelera isso,
 *   mas ela não é garantia: não tem assinatura, não tem reentrega confiável e
 *   depende do nosso servidor estar de pé no instante certo. Este cron é a
 *   rede embaixo — se a notificação falhar, o parecer ainda chega aqui.
 *
 * A regra de o que fazer com cada parecer mora no ClearSaleParecerService,
 * compartilhada com a notificação. Aqui só tem varredura e relatório.
 *
 *   php cli/clearsale-worker.php [--limite=50] [--verbose] [--seco]
 *
 * Cron sugerido — a cada 5 minutos:
 *   cd /caminho && php cli/clearsale-worker.php >> storage/logs/clearsale-worker.log 2>&1
 */

require __DIR__ . '/../bootstrap-cli.php';

$opts    = getopt('', ['limite::', 'verbose', 'seco']);
$limite  = max(1, min((int) ($opts['limite'] ?? 50), 500));
$verbose = isset($opts['verbose']);
$seco    = isset($opts['seco']);

$svc = new ClearSaleParecerService();

if (!$svc->configurado()) {
    fwrite(STDERR, "ClearSale sem credenciais no .env — nada a fazer.\n");
    exit(1);
}

$pendentes = $svc->pendentes($limite);

if (!$pendentes) {
    if ($verbose) echo "nenhum parecer pendente\n";
    exit(0);
}

$contagem = ['aguardando' => 0, 'liberado' => 0, 'decisao_humana' => 0, 'erro' => 0];

foreach ($pendentes as $p) {
    $r = $svc->processar($p, $seco);
    $contagem[$r['acao']]++;

    if (!$verbose) continue;

    printf("  %-28s %-4s %-16s %s\n",
        $p['order_id_loja'],
        $r['codigo_status'] ?? '-',
        $r['acao'],
        $r['acao'] === 'erro' ? (string) $r['motivo'] : ('score ' . ($r['score'] ?? '-'))
    );
}

printf("%s: %d aguardando | %d liberados | %d para decisao humana | %d erros\n",
    $seco ? 'simulacao' : 'clearsale-worker',
    $contagem['aguardando'], $contagem['liberado'],
    $contagem['decisao_humana'], $contagem['erro']);
