<?php
/**
 * app/services/sms/SmsProviderInterface.php
 *
 * Contrato comum a todos os gateways de SMS, no mesmo espírito do
 * EmailProviderInterface: o adapter só sabe falar com a API do provedor.
 * Normalização de telefone, deduplicação, log e política de mensagem são
 * responsabilidade do SmsService — trocar de gateway não deve reabrir
 * nada disso.
 *
 * Regra de ouro das implementações: NUNCA lançar exceção para fora.
 * Falha de SMS não pode derrubar um login. Devolva SmsSendResult::fail().
 */
interface SmsProviderInterface
{
    /**
     * Envia uma mensagem de texto para um número em formato E.164 sem o
     * "+" (ex.: 5551999998888). O SmsService garante esse formato.
     *
     * @param string $numero   E.164 sem "+", já validado.
     * @param string $mensagem Texto puro, já reduzido a GSM-7.
     */
    public function send(string $numero, string $mensagem): SmsSendResult;

    /**
     * Nome curto do provedor para a coluna `via` do canal_log
     * (ex.: 'comtele', 'log').
     */
    public function nome(): string;

    /**
     * O adapter tem credenciais suficientes para tentar um envio?
     * Usado pela tela de 2FA para não oferecer um canal que vai falhar.
     */
    public function configurado(): bool;
}
