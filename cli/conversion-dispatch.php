<?php
declare(strict_types=1);

/**
 * cli/conversion-dispatch.php
 *
 * Runner do cron. Chama o dispatcher pra processar um lote.
 * Roda via bootstrap CLI (mesma infra dos outros crons do projeto).
 *
 * Cron sugerido (a cada minuto):
 *   * * * * * cd /caminho/projeto && php cli/conversion-dispatch.php >> storage/logs/conversion.log 2>&1
 *
 * NUNCA LIGAR EM PRODUÇÃO até a política de privacidade estar
 * publicada. Em homologação, com META_TEST_EVENT_CODE setado,
 * os eventos aparecem no "Test Events" do Events Manager sem
 * afetar dados reais.
 */

require_once __DIR__ . '/../bootstrap-cli.php'; // ajustar ao seu bootstrap CLI

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
    error_log('[conversion-dispatch] ' . $e->getMessage());
    echo '[ERRO] ' . $e->getMessage() . "\n";
    exit(1);
}