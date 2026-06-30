<?php
declare(strict_types=1);

/**
 * PaymentGatewayFactory
 *
 * Ponto único de seleção do gateway ativo. O resto do código nunca
 * instancia adapters diretamente — chama o factory.
 *
 * Configuração (em defines.php / config.php):
 *
 *   define('PAYMENT_GATEWAY', 'malga');  // 'malga' | 'fake'
 *
 * Se a constante não estiver definida, tenta resolver pela tabela
 * pgto_gateways (procura o primeiro com ativo=1).
 *
 * Fallback final: 'fake' (pra dev local não quebrar).
 *
 * Cache de instância: o adapter é instanciado apenas uma vez por request.
 */
class PaymentGatewayFactory
{
    private static ?PaymentGatewayInterface $instancia = null;

    /**
     * Retorna o gateway ativo (singleton por request).
     */
    public static function current(): PaymentGatewayInterface
    {
        if (self::$instancia !== null) {
            return self::$instancia;
        }
        return self::$instancia = self::resolver();
    }

    /**
     * Força um adapter específico. Útil em testes.
     */
    public static function set(PaymentGatewayInterface $gateway): void
    {
        self::$instancia = $gateway;
    }

    /**
     * Limpa o cache (útil em workers de longa duração ou testes).
     */
    public static function reset(): void
    {
        self::$instancia = null;
    }

    /**
     * Cria um adapter específico pelo código (sem cache).
     */
    public static function byCodigo(string $codigo): PaymentGatewayInterface
    {
        switch (strtolower($codigo)) {
            case 'malga':
                return new MalgaGatewayAdapter();
            case 'fake':
                return new FakeGatewayAdapter();
            default:
                throw new InvalidArgumentException("Gateway desconhecido: '{$codigo}'");
        }
    }

    // -----------------------------------------------------------------
    private static function resolver(): PaymentGatewayInterface
    {
        // 1) Modo fake legado — mantém compat com config.php existente
        if (defined('CHECKOUT_FAKE_MODE') && CHECKOUT_FAKE_MODE === true) {
            return new FakeGatewayAdapter();
        }

        // 2) Constante explícita
        if (defined('PAYMENT_GATEWAY') && PAYMENT_GATEWAY) {
            return self::byCodigo((string) PAYMENT_GATEWAY);
        }

        // 3) Tabela pgto_gateways: primeiro com ativo=1
        try {
            $pdo = Database::getInstance()->getConnection();
            $codigo = $pdo->query(
                "SELECT codigo FROM pgto_gateways WHERE ativo = 1 ORDER BY id LIMIT 1"
            )->fetchColumn();
            if ($codigo) {
                return self::byCodigo((string) $codigo);
            }
        } catch (\Throwable $e) {
            if (class_exists('LogService')) {
                LogService::warning('[PaymentGatewayFactory] Falha ao consultar pgto_gateways: ' . $e->getMessage());
            }
        }

        // 4) Fallback de segurança
        if (class_exists('LogService')) {
            LogService::warning('[PaymentGatewayFactory] Nenhum gateway configurado, usando fake');
        }
        return new FakeGatewayAdapter();
    }
}
