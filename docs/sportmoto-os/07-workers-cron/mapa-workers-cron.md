# Motor de automação v2

* * * * * cd /home/homo-v2.sportmoto.com.br/public_html && /usr/local/lsws/lsphp82/bin/php cli/fluxo-worker.php --verbose >> storage/logs/fluxo-worker.log 2>&1


# Importações CSV
* Worker CLI para processar importações CSV em background.
* Bootstrap idêntico ao email-worker.php (espelha o index.php).
*
* Uso:
*   php cli/csv-import-worker.php
*   php cli/csv-import-worker.php --verbose
*
* Cron sugerido (a cada minuto):
*   * * * * * flock -n /home/ploi/hml.sportmoto.com.br/tmp/sm-csv-worker.lock php /home/ploi/hml.sportmoto.com.br/cli/csv-import-worker.php >> /home/ploi/hml.sportmoto.com.br/storage/logs/csv-worker.log 2>&1


# CLI de envio de email marketing
 * Worker CLI de envio de email marketing — SportMoto.
 * Bootstrap alinhado ao index.php (mesmos defines/config/database/autoload),
 * mas SEM iniciar sessão, despachar rotas ou compartilhar views.
 *
 * Uso:
 *   php cli/email-worker.php
 *   php cli/email-worker.php --verbose
 *
 * Cron sugerido (1x por minuto, com flock externo de defesa em profundidade):
 *   * * * * * flock -n /tmp/sm-email-worker.lock php /caminho/cli/email-worker.php >> /caminho/storage/logs/email-worker.log 2>&1