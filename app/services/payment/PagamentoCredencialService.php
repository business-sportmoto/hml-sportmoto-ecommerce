<?php
declare(strict_types=1);

/**
 * app/services/payment/PagamentoCredencialService.php
 *
 * Resolve as credenciais de uma adquirente: banco primeiro, .env depois.
 *
 * POR QUE O BANCO, E O QUE ISSO REALMENTE MUDA:
 *   Guardar no banco permite configurar a adquirente pelo painel, sem acesso
 *   ao servidor, e trocar de ambiente sem deploy. É o que a tabela
 *   `pgto_gateways` já previa desde a Malga.
 *
 *   O que NÃO muda: continua sendo preciso um segredo no .env. Os valores vão
 *   cifrados no banco (AES-256-GCM) e a chave mestra fica fora dele — se o
 *   banco vazar sozinho, num dump ou num backup, as credenciais não vão junto.
 *   Guardar em texto puro trocaria "credencial no .env" por "credencial em
 *   todo backup que alguém já copiou", o que é pior, não melhor.
 *
 * ORDEM DE RESOLUÇÃO, e o motivo dela:
 *   1. coluna do banco, decifrada
 *   2. .env
 *   Nessa ordem porque o banco é o lugar administrável; o .env vira a rede de
 *   segurança para quando a linha ainda não foi preenchida. Assim a migração
 *   é gradual: nada quebra enquanto o banco estiver vazio.
 *
 * MAPA pgto_gateways → Mercado Pago:
 *   sandbox            1 = conta de teste, 0 = produção
 *   api_key            access token   (cifrado)
 *   front_api_key      public key     (cifrado — não é segredo, mas segue o
 *                                      mesmo caminho para não ter exceção)
 *   client_id          não usado pelo MP; deixe vazio
 *   merchant_id        não usado pelo MP; deixe vazio
 *   webhook_secret     segredo da assinatura x-signature (cifrado)
 *   webhook_endpoint   URL cadastrada no painel, só para referência
 *   config_extra       {"pix_expira_min":30,"boleto_dias":3,"tres_ds":"never"}
 */
class PagamentoCredencialService
{
    /** Cache por request: o motor resolve a mesma adquirente várias vezes. */
    private static array $cache = [];

    /**
     * @return array{
     *   encontrado:bool, sandbox:bool, access_token:string, public_key:string,
     *   client_id:string, merchant_id:string, webhook_secret:string,
     *   webhook_endpoint:string, config:array, origem:array
     * }
     */
    public static function para(string $codigo): array
    {
        $codigo = strtolower(trim($codigo));
        if (isset(self::$cache[$codigo])) return self::$cache[$codigo];

        $linha = self::linha($codigo);
        $env   = self::prefixoEnv($codigo);

        // ── Ambiente: o banco manda, mas divergencia nao passa calada ──
        //
        // Existem DUAS fontes (.env e a coluna `sandbox`) e elas podem
        // discordar. Ja aconteceu: o .env dizia sandbox, o banco dizia
        // producao, e o adapter operou se declarando producao com credencial
        // de teste. No sentido inverso o estrago e pior — cobrar cartao real
        // achando que esta testando.
        //
        // O banco continua vencendo (e o lugar administravel), mas a
        // discordancia vira log de alerta em vez de sumir.
        $envAmbiente = strtolower(self::env($env . 'AMBIENTE'));
        $envSandbox  = in_array($envAmbiente, ['sandbox', 'teste', 'test', 'homologacao'], true);

        $sandbox = $linha !== null ? (bool) $linha['sandbox'] : $envSandbox;

        if ($linha !== null && $envAmbiente !== '' && $envSandbox !== $sandbox) {
            LogService::warning('Ambiente da adquirente diverge entre .env e banco', [
                'adquirente' => $codigo,
                'env'        => $envSandbox ? 'sandbox' : 'producao',
                'banco'      => $sandbox ? 'sandbox' : 'producao',
                'valendo'    => $sandbox ? 'sandbox' : 'producao',
            ], 'pagamento');
        }

        // Em sandbox as chaves de teste vêm primeiro; o par de produção
        // continua no .env sem atrapalhar.
        $sufixo = $sandbox ? $env . 'TEST_' : $env;

        // DUAS CONVENÇÕES DE NOME, porque as duas aparecem no .env real:
        // `CIELO_TEST_MERCHANT_KEY` e `SANDBOX_CIELO_MERCHANT_KEY`. Aceitar
        // só uma faz a chave existir no arquivo e o sistema jurar que não
        // está configurado — erro que custa tempo e não deixa rastro.
        $sufixoAlt = $sandbox ? 'SANDBOX_' . $env : $env;

        /** Nomes a procurar, na ordem: teste primeiro quando em sandbox. */
        $nomes = static function (string $campo) use ($sufixo, $sufixoAlt, $env): array {
            return array_values(array_unique([
                $sufixo . $campo, $sufixoAlt . $campo, $env . $campo,
            ]));
        };

        $origem = [];

        // A Cielo não chama de token: o segredo dela é o MerchantKey, e é
        // esse o nome que está no .env. Sem o alias, a coluna `api_key`
        // ficaria vazia e o adapter se declararia "não configurado" com a
        // credencial ali do lado.
        $token = self::resolver(
            $linha['api_key'] ?? null,
            [...$nomes('ACCESS_TOKEN'), ...$nomes('MERCHANT_KEY')],
            $origem, 'access_token'
        );
        $pk = self::resolver(
            $linha['front_api_key'] ?? null,
            $nomes('PUBLIC_KEY'),
            $origem, 'public_key'
        );
        $segredo = self::resolver(
            $linha['webhook_secret'] ?? null,
            [$env . 'WEBHOOK_SECRET'],
            $origem, 'webhook_secret'
        );

        $config = [];
        if (!empty($linha['config_extra'])) {
            $j = json_decode((string) $linha['config_extra'], true);
            if (is_array($j)) $config = $j;
        }

        return self::$cache[$codigo] = [
            'encontrado'       => $token !== '',
            'sandbox'          => $sandbox,
            'access_token'     => $token,
            'public_key'       => $pk,
            'client_id'        => self::resolver($linha['client_id'] ?? null, [$env . 'CLIENTE_ID'], $origem, 'client_id'),
            'merchant_id'      => self::resolver($linha['merchant_id'] ?? null, $nomes('MERCHANT_ID'), $origem, 'merchant_id'),
            'webhook_secret'   => $segredo,
            'webhook_endpoint' => (string) ($linha['webhook_endpoint'] ?? ''),
            'config'           => $config,
            'origem'           => $origem,
        ];
    }

    /** Grava credenciais cifradas. Usado pelo CLI e pelo painel. */
    public static function salvar(string $codigo, array $valores): void
    {
        $db = Database::getInstance()->getConnection();

        $campos = ['api_key', 'front_api_key', 'webhook_secret', 'client_id', 'merchant_id'];
        $set    = [];
        $args   = [];

        foreach ($campos as $c) {
            if (!array_key_exists($c, $valores)) continue;
            $v = (string) $valores[$c];
            $set[]  = "{$c} = ?";
            // Vazio fica vazio: cifrar string vazia só cria lixo indecifrável.
            $args[] = $v === '' ? '' : PagamentoCriptoService::cifrar($v);
        }

        foreach (['sandbox', 'ativo'] as $c) {
            if (!array_key_exists($c, $valores)) continue;
            $set[]  = "{$c} = ?";
            $args[] = (int) (bool) $valores[$c];
        }

        foreach (['webhook_endpoint', 'nome'] as $c) {
            if (!array_key_exists($c, $valores)) continue;
            $set[]  = "{$c} = ?";
            $args[] = (string) $valores[$c];
        }

        if (array_key_exists('config_extra', $valores)) {
            $set[]  = 'config_extra = ?';
            $args[] = json_encode($valores['config_extra'], JSON_UNESCAPED_UNICODE);
        }

        if (!$set) return;

        $set[]  = 'atualizado_em = NOW()';
        $args[] = $codigo;

        $db->prepare('UPDATE pgto_gateways SET ' . implode(', ', $set) . ' WHERE codigo = ?')
           ->execute($args);

        unset(self::$cache[strtolower($codigo)]);

        LogService::audit('Credenciais de adquirente atualizadas', [
            'adquirente' => $codigo,
            // Nunca o valor: só quais campos mudaram.
            'campos'     => array_values(array_intersect(array_keys($valores), array_merge($campos, ['sandbox', 'ativo']))),
            'por'        => class_exists('AuthHelper') ? AuthHelper::usuarioId() : null,
        ]);
    }

    public static function limparCache(): void
    {
        self::$cache = [];
    }

    // =========================================================================

    private static function linha(string $codigo): ?array
    {
        try {
            $st = Database::getInstance()->getConnection()->prepare(
                'SELECT sandbox, client_id, api_key, front_api_key, merchant_id,
                        webhook_secret, webhook_endpoint, config_extra
                   FROM pgto_gateways WHERE codigo = ? LIMIT 1'
            );
            $st->execute([$codigo]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            // Banco fora não pode impedir o .env de funcionar.
            LogService::exception($e, 'warning', 'pagamento',
                ['acao' => 'ler_credencial', 'adquirente' => $codigo]);
            return null;
        }
    }

    /**
     * Valor do banco (decifrado) ou o primeiro .env que tiver conteúdo.
     *
     * Aceita valor em texto puro no banco: instalações antigas gravavam assim,
     * e falhar nelas seria trocar um problema de segurança por um apagão.
     * O `decifrar()` devolve null quando o blob não é dele, e aí o texto vale.
     */
    private static function resolver(?string $doBanco, array $chavesEnv, array &$origem, string $rotulo): string
    {
        $doBanco = (string) $doBanco;

        if ($doBanco !== '') {
            $claro = PagamentoCriptoService::decifrar($doBanco);
            $origem[$rotulo] = $claro !== null ? 'banco (cifrado)' : 'banco (texto puro)';
            return $claro ?? $doBanco;
        }

        foreach ($chavesEnv as $k) {
            $v = self::env($k);
            if ($v !== '') {
                $origem[$rotulo] = '.env ' . $k;
                return $v;
            }
        }

        $origem[$rotulo] = 'ausente';
        return '';
    }

    /** mercadopago → MP_ ; safrapay → SAFRAPAY_ */
    private static function prefixoEnv(string $codigo): string
    {
        return match ($codigo) {
            'mercadopago' => 'MP_',
            'safrapay'    => 'SAFRAPAY_',
            'cielo'       => 'CIELO_',
            default       => strtoupper($codigo) . '_',
        };
    }

    private static function env(string $chave): string
    {
        $v = getenv($chave);
        if ($v !== false && $v !== '') return (string) $v;
        if (!empty($_ENV[$chave]))    return (string) $_ENV[$chave];
        if (!empty($_SERVER[$chave])) return (string) $_SERVER[$chave];
        if (defined($chave) && is_string(constant($chave))) return (string) constant($chave);
        return '';
    }
}
