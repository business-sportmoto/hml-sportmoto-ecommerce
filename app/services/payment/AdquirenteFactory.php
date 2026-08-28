<?php
declare(strict_types=1);

/**
 * app/services/payment/AdquirenteFactory.php
 *
 * Resolve o adapter de uma adquirente pelo código.
 *
 * POR QUE ISTO EXISTE E O PaymentGatewayFactory NÃO BASTA:
 *   Aquele resolve UM gateway global (constante PAYMENT_GATEWAY ou o primeiro
 *   ativo na tabela) — fazia sentido quando havia só a Malga. Com várias
 *   adquirentes, "qual gateway usar" deixa de ser pergunta global: estornar
 *   ou consultar uma cobrança tem que falar com quem PROCESSOU aquela
 *   cobrança, não com quem está ativo agora.
 *
 *   Mandar um estorno para a adquirente errada devolve "não encontrado" —
 *   e o dinheiro do cliente não volta.
 *
 * O PaymentGatewayFactory continua vivo para o código legado da Malga.
 * Este é o caminho novo, e é o único que o motor e o painel usam.
 */
class AdquirenteFactory
{
    /** @var array<string, AdquirenteInterface> cache por request */
    private static array $cache = [];

    /** Sobrescrita para testes. */
    private static ?\Closure $resolvedor = null;

    /**
     * Adapter pelo código em pgto_gateways.codigo.
     * Devolve null quando não há adapter implementado — o chamador decide.
     */
    public static function porCodigo(string $codigo): ?AdquirenteInterface
    {
        $codigo = strtolower(trim($codigo));
        if ($codigo === '') return null;

        if (self::$resolvedor !== null) {
            return (self::$resolvedor)($codigo);
        }

        if (isset(self::$cache[$codigo])) {
            return self::$cache[$codigo];
        }

        $adapter = match ($codigo) {
            'safrapay'    => new SafraPayAdapter(),
            'mercadopago' => new MercadoPagoAdapter(),
            'fake'     => new FakeAdquirenteAdapter('fake'),
            default    => null,
        };

        if ($adapter !== null) self::$cache[$codigo] = $adapter;
        return $adapter;
    }

    /**
     * Adapter da adquirente que processou ESTA transação.
     *
     * @param array $transacao linha de pgto_transacoes já com gateway_codigo
     *                         (o PaymentListService faz o JOIN)
     */
    public static function paraTransacao(array $transacao): ?AdquirenteInterface
    {
        $codigo = (string) ($transacao['gateway_codigo'] ?? '');

        // Sem o JOIN, resolve pelo id. Evita depender de quem montou a query.
        if ($codigo === '' && !empty($transacao['gateway_id'])) {
            $st = Database::getInstance()->getConnection()
                ->prepare("SELECT codigo FROM pgto_gateways WHERE id = ? LIMIT 1");
            $st->execute([(int) $transacao['gateway_id']]);
            $codigo = (string) ($st->fetchColumn() ?: '');
        }

        return self::porCodigo($codigo);
    }

    /** Códigos com adapter implementado. */
    public static function disponiveis(): array
    {
        return array_keys(PagamentoAdquirente::SUPORTADAS);
    }

    public static function usarResolvedor(?\Closure $r): void
    {
        self::$resolvedor = $r;
        self::$cache = [];
    }

    public static function limparCache(): void
    {
        self::$cache = [];
    }
}
