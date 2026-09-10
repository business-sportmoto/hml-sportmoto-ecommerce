<?php
declare(strict_types=1);

/**
 * cli/google-ads-testar.php
 *
 * Diagnóstico do destino Google Ads (Data Manager API).
 *
 * POR QUE EXISTE: o GoogleAdsAdapter tem pontos marcados 🔶 — nomes de
 * campo que MUDAM entre versões da Data Manager API. Não dá para
 * resolver isso lendo código: quem sabe o nome certo é a API. Este
 * script pergunta a ela.
 *
 * Uso:
 *   php cli/google-ads-testar.php              # só diagnostica (NÃO envia)
 *   php cli/google-ads-testar.php --payload    # mostra o JSON que seria enviado
 *   php cli/google-ads-testar.php --enviar     # ENVIA um evento de teste real
 *
 * O modo padrão não toca na rede além de pedir o token. Só --enviar
 * faz POST, e ele usa um transactionId marcado como teste.
 *
 * Como usar o resultado: um 400 da Data Manager API nomeia o campo
 * inválido. Cada nome que ela reclamar é um 🔶 a corrigir no adapter.
 */

require __DIR__ . '/../bootstrap-cli.php';

$args    = $argv ?? [];
$enviar  = in_array('--enviar', $args, true);
$verPay  = in_array('--payload', $args, true) || $enviar;

$linha = static fn(string $s = '') => print($s . "\n");
$ok    = static fn(bool $b) => $b ? 'OK' : 'FALTA';

$linha('== 1. Configuração ==');

$vars = [
    'GOOGLE_SA_KEY_PATH'          => getenv('GOOGLE_SA_KEY_PATH') ?: '',
    'GOOGLE_ADS_CUSTOMER_ID'      => getenv('GOOGLE_ADS_CUSTOMER_ID') ?: '',
    'GOOGLE_CONVERSION_ACTION_ID' => getenv('GOOGLE_CONVERSION_ACTION_ID') ?: '',
];
foreach ($vars as $nome => $valor) {
    printf("  %-30s %s\n", $nome, $valor === '' ? 'FALTA' : 'OK');
}

$chave = $vars['GOOGLE_SA_KEY_PATH'];
if ($chave !== '') {
    printf("  %-30s %s\n", 'arquivo da service account',
        is_readable($chave) ? 'legível' : 'NÃO ENCONTRADO/ILEGÍVEL: ' . $chave);
}

$adapter = new GoogleAdsAdapter();
$linha('');
printf("  %-30s %s\n", 'adapter estaConfigurado()', $adapter->estaConfigurado() ? 'SIM' : 'NÃO');

if (!$adapter->estaConfigurado()) {
    $linha('');
    $linha('Sem configuração completa não dá para continuar. Faltando:');
    $linha('  1. Service account no Google Cloud + Data Manager API habilitada');
    $linha('  2. Essa service account com acesso NA CONTA do Google Ads');
    $linha('     (é o passo que quase todo mundo esquece)');
    $linha('  3. Conversion action criada no Google Ads (pegue o ID)');
    $linha('  4. As três variáveis acima no .env');
    exit(1);
}

$linha('');
$linha('== 2. Autenticação (JWT -> access token) ==');

$auth  = new GoogleAdsAuthService();
$token = $auth->getAccessToken();

if (!$token) {
    $linha('  FALHOU ao obter access token.');
    $linha('  Causas comuns: API não habilitada no projeto, JSON da service');
    $linha('  account inválido, ou relógio do servidor fora de hora (o JWT');
    $linha('  tem janela de validade curta).');
    $linha('  Detalhe do erro: ver logs canal "tracking".');
    exit(1);
}
printf("  token obtido (%d chars, termina em ...%s)\n",
    strlen($token), substr($token, -6));

// ── Evento sintético, no mesmo formato que o dispatcher entrega ──
$db = Database::getInstance()->getConnection();
$ev = $db->query(
    "SELECT * FROM tracking_events
      WHERE event_name = 'Purchase' ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($ev) {
    $ev['payload'] = json_decode($ev['payload'] ?? '[]', true) ?: [];
    $linha('');
    $linha('== 3. Evento base: Purchase real do ledger (event_id ' . $ev['event_id'] . ') ==');
} else {
    $ev = [
        'event_name'      => 'Purchase',
        'event_time'      => date('Y-m-d H:i:s'),
        'cliente_id'      => null,
        'visitante_token' => null,
        'payload'         => [
            'value' => 1.00, 'currency' => 'BRL',
            'order_id' => 'TESTE-' . date('YmdHis'),
            '_context' => ['user_agent' => 'cli-teste'],
        ],
    ];
    $linha('');
    $linha('== 3. Evento base: SINTÉTICO (nenhum Purchase no ledger) ==');
}

if ($verPay) {
    $m = new ReflectionMethod(GoogleAdsAdapter::class, 'montarPayload');
    $m->setAccessible(true);
    $linha('');
    $linha('== 4. Payload que seria enviado ==');
    $linha(json_encode($m->invoke($adapter, $ev),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

if (!$enviar) {
    $linha('');
    $linha('Nada foi enviado. Use --enviar para fazer o POST real e ver a');
    $linha('resposta da API — é ela que diz quais nomes de campo estão errados.');
    exit(0);
}

$linha('');
$linha('== 5. ENVIO REAL ==');
$res = $adapter->enviar($ev);

printf("  sucesso:      %s\n", $res->sucesso ? 'SIM' : 'não');
printf("  http_status:  %s\n", $res->httpStatus ?? '-');
printf("  reenviar:     %s\n", $res->reenviar ? 'sim (falha temporária)' : 'não');
if ($res->erro) {
    $linha('  resposta da API:');
    $linha('  ' . $res->erro);
    $linha('');
    $linha('  >>> Cada campo citado acima é um 🔶 do GoogleAdsAdapter.');
}

if ($res->sucesso) {
    $linha('');
    $linha('  Enviado. A conversão pode levar horas para aparecer no Google Ads.');
    $linha('  Um 200 aqui significa ACEITO, não "processado com sucesso" —');
    $linha('  erros assíncronos aparecem só no relatório de diagnóstico.');
}

exit($res->sucesso ? 0 : 2);
