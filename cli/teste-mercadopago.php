<?php
declare(strict_types=1);

/**
 * cli/teste-mercadopago.php
 *
 * Diagnóstico e validação da integração com o Mercado Pago.
 *
 *   php cli/teste-mercadopago.php diagnostico
 *
 * POR QUE O DIAGNÓSTICO EXISTE E É UM PORTÃO:
 *   Olhar a chave não diz em qual conta você está, e a diferença é entre um
 *   teste grátis e uma cobrança de verdade no cartão de alguém.
 *
 *   Há DUAS formas de estar em ambiente seguro, e elas não se parecem:
 *     1. Credenciais de teste da própria aplicação — prefixo TEST-. Aqui
 *        /users/me devolve a SUA conta real, com o seu e-mail: o e-mail não
 *        prova nada, o prefixo é que prova.
 *     2. Credenciais de um usuário de teste — prefixo APP_USR-, idêntico ao
 *        de produção. Aqui o prefixo não prova nada, o e-mail @testuser.com
 *        é que prova.
 *
 *   Checar só um dos dois rejeita metade das configurações válidas. Este
 *   comando checa os dois e sai com código de erro quando aponta para conta
 *   real, para que nenhum script de teste rode por engano em produção.
 *
 * Nenhuma credencial é impressa — só tamanho, prefixo e identidade da conta.
 */

require __DIR__ . '/../bootstrap-cli.php';

$cmd = $argv[1] ?? 'diagnostico';

$COMANDOS = ['diagnostico', 'corpo', 'cartao', 'pix', 'boleto', 'migrar-banco', 'webhook-secret'];

if (!in_array($cmd, $COMANDOS, true)) {
    fwrite(STDERR, "Comando desconhecido: {$cmd}\n  " . implode(' | ', $COMANDOS) . "\n");
    exit(1);
}

if ($cmd === 'migrar-banco')   { migrarParaBanco(); exit(0); }
if ($cmd === 'webhook-secret') { salvarWebhookSecret((string) ($argv[2] ?? '')); exit(0); }

if ($cmd !== 'diagnostico') {
    exercitar($cmd);
    exit(0);
}

// ── 1. O que está no .env ───────────────────────────────────────────────
$chaves = [
    'MP_AMBIENTE'          => false,
    'MP_PUBLIC_KEY'        => true,
    'MP_ACCESS_TOKEN'      => true,
    'MP_TEST_PUBLIC_KEY'   => true,
    'MP_TEST_ACCESS_TOKEN' => true,
];

echo "=== credenciais no .env ===\n";
$valores = [];
foreach ($chaves as $k => $segredo) {
    $v = (string) (getenv($k) ?: ($_ENV[$k] ?? ''));
    $valores[$k] = $v;

    if ($v === '') {
        printf("  %-22s ausente\n", $k);
        continue;
    }
    printf("  %-22s %s\n", $k, $segredo
        ? sprintf('%d chars%s', strlen($v), prefixo($v))
        : $v);
}

// ── 2. Qual conjunto vale ───────────────────────────────────────────────
$ambiente = strtolower($valores['MP_AMBIENTE'] ?: 'producao');
$sandbox  = in_array($ambiente, ['sandbox', 'teste', 'test', 'homologacao'], true);
$usouTest = $sandbox && $valores['MP_TEST_ACCESS_TOKEN'] !== '';
$token    = $usouTest ? $valores['MP_TEST_ACCESS_TOKEN'] : $valores['MP_ACCESS_TOKEN'];

printf("\n  ambiente pedido: %s\n", $ambiente);
printf("  token em uso:    %s\n", $usouTest ? 'MP_TEST_ACCESS_TOKEN' : 'MP_ACCESS_TOKEN');

if ($token === '') {
    fwrite(STDERR, "\nSem token. Preencha MP_ACCESS_TOKEN ou MP_TEST_ACCESS_TOKEN.\n");
    exit(1);
}

// ── 3. De quem é este token? ────────────────────────────────────────────
echo "\n=== identidade da conta (a pergunta que importa) ===\n";
$me = mpGet('/users/me', $token);

if ($me['http'] !== 200) {
    printf("  HTTP %d — token nao autenticou\n  %s\n", $me['http'], mb_substr($me['raw'], 0, 200));
    exit(1);
}

$email        = (string) ($me['body']['email'] ?? '');
$tokenDeTeste = str_starts_with($token, 'TEST-');
$contaDeTeste = str_contains($email, 'testuser');
$eTeste       = $tokenDeTeste || $contaDeTeste;

printf("  id       %s\n", $me['body']['id'] ?? '-');
printf("  nickname %s\n", $me['body']['nickname'] ?? '-');
printf("  site     %s\n", $me['body']['site_id'] ?? '-');
printf("  email    %s\n", preg_replace('/(.{3}).*(@.*)/', '$1***$2', $email));

// ── 4. Métodos habilitados ──────────────────────────────────────────────
$pm = mpGet('/v1/payment_methods', $token);
if ($pm['http'] === 200 && is_array($pm['body'])) {
    $grupos = [];
    foreach ($pm['body'] as $m) {
        $grupos[(string) ($m['payment_type_id'] ?? '?')][] = (string) ($m['id'] ?? '');
    }
    echo "\n=== metodos habilitados nesta conta ===\n";
    foreach (['credit_card' => 'cartao de credito', 'bank_transfer' => 'pix', 'ticket' => 'boleto'] as $tipo => $rotulo) {
        printf("  %-18s %s\n", $rotulo,
            isset($grupos[$tipo]) ? implode(', ', $grupos[$tipo]) : 'NAO habilitado');
    }
}

// ── 5. O portão ─────────────────────────────────────────────────────────
echo "\n";
if ($eTeste) {
    printf("  >>> AMBIENTE DE TESTE (%s). Seguro cobrar.\n",
        $tokenDeTeste ? 'credenciais de teste da aplicacao' : 'usuario de teste');
    exit(0);
}

echo "  >>> CONTA REAL. Cobrar aqui move dinheiro de verdade.\n\n"
   . "  Para testar sem risco, no painel do Mercado Pago:\n"
   . "    Suas integracoes > sua aplicacao > Credenciais de teste\n"
   . "  e acrescente ao .env SEM apagar as de producao:\n\n"
   . "    MP_AMBIENTE=sandbox\n"
   . "    MP_TEST_PUBLIC_KEY=TEST-...\n"
   . "    MP_TEST_ACCESS_TOKEN=TEST-...\n\n"
   . "  Este comando passa quando o token comecar com TEST- ou quando a\n"
   . "  conta for um usuario de teste (@testuser.com).\n";
exit(2);

// =============================================================================

function prefixo(string $v): string
{
    foreach (['TEST-', 'APP_USR-'] as $p) {
        if (str_starts_with($v, $p)) return ' [' . rtrim($p, '-') . ']';
    }
    return '';
}

/** @return array{http:int, body:mixed, raw:string} */
function mpGet(string $recurso, string $token): array
{
    $ch = curl_init('https://api.mercadopago.com' . $recurso);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $raw  = (string) curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http' => $http, 'body' => json_decode($raw, true), 'raw' => $raw];
}

/**
 * Exercita o adapter de verdade.
 *
 * `corpo` nao envia nada: intercepta a camada HTTP e imprime o JSON exato que
 * sairia. E o unico jeito de revisar o payload enquanto a criacao de pagamento
 * estiver barrada por credencial — e continua util depois, porque quando o MP
 * recusa por campo faltando, ver o corpo resolve em segundos.
 */
function exercitar(string $cmd): void
{
    $d = [
        'order_id_loja'    => 'MP-' . date('YmdHis'),
        'tentativa_ref'    => 't' . random_int(1000, 9999),
        'valor_centavos'   => 25990,
        'parcelas'         => 3,
        'descricao_fatura' => 'SportMoto capacete',
        'bandeira'         => 'visa',
        'cliente' => [
            'nome'      => 'Joao da Silva Souza',
            'email'     => 'test_user_comprador@testuser.com',
            'documento' => '191.191.191-00',
            'endereco'  => [
                'cep' => '01310-100', 'logradouro' => 'Avenida Paulista', 'numero' => '1000',
                'bairro' => 'Bela Vista', 'cidade' => 'Sao Paulo', 'uf' => 'SP',
            ],
        ],
        // Cartao de teste do MP. O TITULAR decide o desfecho: APRO aprova,
        // FUND saldo insuficiente, SECU CVV invalido, EXPI vencido,
        // OTHE recusa generica, CONT deixa pendente.
        'cartao' => [
            'numero' => '4235647728025682', 'cvv' => '123',
            'validade' => '11/2030', 'titular' => 'APRO',
        ],
    ];

    if ($cmd === 'corpo') {
        $espiao = new class extends MercadoPagoAdapter {
            public array $capturado = [];
            protected function http(string $m, string $r, ?array $c, array $e = [], bool $a = true): array
            {
                $this->capturado[] = ['metodo' => $m, 'recurso' => $r, 'corpo' => $c, 'extra' => $e];
                return ['http' => 200, 'erro' => null, 'raw' => '{}',
                        'body' => ['id' => 1, 'status' => 'approved', 'status_detail' => 'accredited']];
            }
        };
        $d['token_temporario'] = 'TOKEN_QUE_VEM_DO_NAVEGADOR';
        $espiao->autorizarCartao($d);
        $espiao->criarPix($d);
        $espiao->criarBoleto($d);

        foreach ($espiao->capturado as $i => $c) {
            printf("=== %s  %s %s\n", ['CARTAO', 'PIX', 'BOLETO'][$i], $c['metodo'], $c['recurso']);
            printf("  %s\n", implode(' | ', $c['extra']));
            echo json_encode($c['corpo'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n\n";
        }
        return;
    }

    $mp = new MercadoPagoAdapter();
    printf("ambiente: %s | configurado: %s\n\n", $mp->ambiente(), $mp->configurado() ? 'sim' : 'NAO');

    $c = match ($cmd) {
        'cartao' => $mp->autorizarCartao($d),
        'pix'    => $mp->criarPix($d),
        'boleto' => $mp->criarBoleto($d),
    };

    printf("  pedido        %s\n", $d['order_id_loja']);
    printf("  porta         %s\n", $c->porta);
    printf("  classe        %s\n", $c->classeErro);
    printf("  http          %s\n", $c->httpStatus ?? '-');
    printf("  charge_id     %s\n", $c->chargeId ?? '-');
    printf("  latencia      %d ms\n", $c->duracaoMs);
    printf("  ao cliente    %s\n", $c->mensagemCliente);
    printf("  da adquirente %s\n", $c->mensagemAdquirente ?? '-');
    printf("  retenta em outra? %s | exige consulta? %s\n",
        $c->podeCairParaOutra ? 'SIM' : 'nao', $c->exigeConsulta ? 'SIM' : 'nao');

    if ($c->pixQrCode) {
        printf("\n  QR (copia e cola) %s...\n", mb_substr($c->pixQrCode, 0, 60));
        printf("  expira em         %s\n", $c->pixExpiraEm ?? '-');
    }
    if ($c->boletoLinhaDigitavel || $c->boletoUrl) {
        printf("\n  linha digitavel   %s\n", $c->boletoLinhaDigitavel ?? '-');
        printf("  url               %s\n", mb_substr((string) $c->boletoUrl, 0, 80));
        printf("  vencimento        %s\n", $c->boletoVencimento ?? '-');
    }

    if (in_array($c->porta, [PagamentoClassificacao::ERRO_TECNICO,
                             PagamentoClassificacao::INDISPONIVEL], true)) {
        exit(1);
    }
}

/**
 * Move as credenciais do .env para o banco, cifradas.
 *
 * Le do .env e grava em pgto_gateways. Assim o segredo nunca passa pela linha
 * de comando (onde ficaria no histórico do shell) nem por um INSERT escrito à
 * mão (onde ficaria no log de queries do MySQL).
 */
function migrarParaBanco(): void
{
    if (!PagamentoCriptoService::disponivel()) {
        fwrite(STDERR, "PGTO_CRYPTO_KEY ausente no .env.\n"
            . "  Gere com: php -r \"echo bin2hex(random_bytes(32));\"\n");
        exit(1);
    }

    $amb     = strtolower((string) (getenv('MP_AMBIENTE') ?: 'producao'));
    $sandbox = in_array($amb, ['sandbox', 'teste', 'test', 'homologacao'], true);
    $sufixo  = $sandbox ? 'MP_TEST_' : 'MP_';

    $token = (string) (getenv($sufixo . 'ACCESS_TOKEN') ?: getenv('MP_ACCESS_TOKEN'));
    $pk    = (string) (getenv($sufixo . 'PUBLIC_KEY')   ?: getenv('MP_PUBLIC_KEY'));

    if ($token === '') {
        fwrite(STDERR, "Sem access token no .env para o ambiente '{$amb}'.\n");
        exit(1);
    }

    PagamentoCredencialService::salvar('mercadopago', [
        'api_key'       => $token,
        'front_api_key' => $pk,
        'sandbox'       => $sandbox,
        'config_extra'  => [
            'pix_expira_min' => (int) (getenv('MP_PIX_EXPIRA_MIN') ?: 30),
            'boleto_dias'    => (int) (getenv('MP_BOLETO_DIAS') ?: 3),
            'tres_ds'        => (string) (getenv('MP_3DS') ?: 'never'),
        ],
    ]);

    printf("gravado em pgto_gateways (ambiente %s)\n", $sandbox ? 'sandbox' : 'producao');
    printf("  api_key        %s\n", PagamentoCriptoService::last4($token));
    printf("  front_api_key  %s\n", PagamentoCriptoService::last4($pk));

    PagamentoCredencialService::limparCache();
    $c = PagamentoCredencialService::para('mercadopago');
    echo "\norigem apos a gravacao:\n";
    foreach ($c['origem'] as $k => $v) printf("  %-16s %s\n", $k, $v);

    echo "\nConfira com um pagamento real antes de limpar o .env:\n"
       . "  php cli/teste-mercadopago.php pix\n";
}

/** Grava o segredo da assinatura do webhook, cifrado. */
function salvarWebhookSecret(string $segredo): void
{
    if ($segredo === '') {
        fwrite(STDERR, "Uso: php cli/teste-mercadopago.php webhook-secret <segredo>\n");
        exit(1);
    }
    PagamentoCredencialService::salvar('mercadopago', ['webhook_secret' => $segredo]);
    printf("segredo gravado: %s\n", PagamentoCriptoService::last4($segredo));
}
