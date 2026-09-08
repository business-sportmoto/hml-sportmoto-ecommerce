<?php
declare(strict_types=1);

/**
 * cli/conversion-dispatch.php
 *
 * Runner do cron. Chama o dispatcher pra processar um lote.
 * Roda via bootstrap CLI (mesma infra dos outros crons do projeto).
 *
 * Cron (a cada minuto):
 *   * * * * * cd /caminho/do/site && php cli/conversion-dispatch.php >> storage/logs/conversion.log 2>&1
 *
 * ATIVO EM PRODUÇÃO (Meta). O bloqueio antigo — "não ligar até a
 * política de privacidade estar publicada" — foi atendido; a política
 * está no ar. O gate de LGPD que continua valendo é o do dispatcher:
 * adapter que exige marketing + consent_marketing=0 → skipped.
 *
 * Em homologação, com META_TEST_EVENT_CODE setado, os eventos vão pro
 * "Test Events" do Events Manager sem afetar dados reais. Essa variável
 * NÃO pode existir no .env de produção.
 */

require_once __DIR__ . '/../bootstrap-cli.php';

try {
    $dispatcher = new ConversionDispatcher();
    $resumo = $dispatcher->processarLote();

    // Log simples (o >> do cron acumula no arquivo)
    echo sprintf(
        "[%s] enviados=%d pulados=%d retry=%d dead=%d %s\n",
        date('Y-m-d H:i:s'),
        $resumo['ok'] ?? 0,
        $resumo['skip'] ?? 0,
        $resumo['retry'] ?? 0,
        $resumo['dead'] ?? 0,
        $resumo['msg'] ?? ''
    );
} catch (\Throwable $e) {
    // critical: se o runner morre, a fila inteira para de escoar e
    // nenhum evento chega na Meta até alguém perceber. O echo abaixo
    // continua indo pro log do cron; o LogService é o que torna a
    // falha visível de dentro do sistema (dashboard/alerta).
    LogService::exception($e, 'critical', 'tracking', [
        'origem' => 'cli/conversion-dispatch.php',
    ]);
    echo '[ERRO] ' . $e->getMessage() . "\n";
    exit(1);
}