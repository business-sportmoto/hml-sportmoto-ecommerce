<?php
/**
 * app/services/sms/providers/LogSmsProvider.php
 *
 * Driver de desenvolvimento: não fala com gateway nenhum, apenas grava
 * que o envio "aconteceu". Permite exercitar o fluxo de 2FA por SMS
 * inteiro — escolher o canal, receber o código, validar, logar — sem
 * contrato ativo e sem queimar crédito a cada teste local.
 *
 * ATENÇÃO: este driver grava o CÓDIGO EM CLARO no log, porque em dev é
 * exatamente assim que você lê o código para colar na tela. É por isso
 * que o SmsService só o instancia quando APP_ENV === 'development'.
 * Em produção ele nunca entra no caminho.
 */
class LogSmsProvider implements SmsProviderInterface
{
    public function nome(): string
    {
        return 'log';
    }

    /** Sempre pronto: é justamente o driver de quem não tem gateway. */
    public function configurado(): bool
    {
        return true;
    }

    public function send(string $numero, string $mensagem): SmsSendResult
    {
        $id = 'dev-' . bin2hex(random_bytes(6));

        if (class_exists('LogService')) {
            LogService::debug('SMS simulado (driver de desenvolvimento)', [
                'numero'   => $numero,
                'mensagem' => $mensagem,   // contém o código: só em dev
                'msg_id'   => $id,
            ], 'sms');
        }

        // Rede de segurança: se o banco de logs estiver fora, o código
        // ainda aparece no log de arquivo do PHP.
        error_log('[SMS:dev] ' . $numero . ' :: ' . $mensagem);

        return SmsSendResult::ok($id, ['driver' => 'log']);
    }
}
