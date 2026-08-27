<?php
declare(strict_types=1);

/**
 * cli/teste-clearsale.php
 *
 * Valida a integração da ClearSale contra o ambiente configurado no .env.
 *
 * Mesmo roteiro que usei na Safra: autenticar, mandar um caso real, e corrigir
 * o contrato pelo que a API responder — não pelo que o manual promete.
 *
 *   php cli/teste-clearsale.php auth        só autentica
 *   php cli/teste-clearsale.php pedido      envia um pedido de teste
 *   php cli/teste-clearsale.php fingerprint mostra o snippet do navegador
 *
 * Nenhuma credencial é impressa.
 */

require __DIR__ . '/../bootstrap-cli.php';

$cmd = $argv[1] ?? 'pedido';
$cs  = new ClearSaleService();

printf("ambiente: %s | configurado: %s\n\n", $cs->ambiente(), $cs->configurado() ? 'sim' : 'NAO');

if (!$cs->configurado() && $cmd !== 'fingerprint') {
    fwrite(STDERR, "Faltam CLEARSALE_LOGIN / CLEARSALE_PASSWORD no .env.\n");
    exit(1);
}

switch ($cmd) {

    case 'auth':
        // O token e privado: so confirmamos que veio e o tamanho.
        $ref = new ReflectionMethod(ClearSaleService::class, 'token');
        $ref->setAccessible(true);
        try {
            $t = (string) $ref->invoke($cs);
            printf("autenticou: token de %d caracteres\n", strlen($t));
        } catch (Throwable $e) {
            fwrite(STDERR, 'falhou: ' . $e->getMessage() . "\n");
            exit(1);
        }
        break;

    case 'fingerprint':
        printf("app key configurada: %s\n", ClearSaleFingerprint::ativo() ? 'sim' : 'NAO');
        echo "\n--- snippet que o checkout-layout renderiza ---\n";
        echo ClearSaleFingerprint::script(), "\n";
        break;

    case 'pedido':
    default:
        $codigo = 'CS-TESTE-' . date('YmdHis');

        $pedido = [
            'codigo'         => $codigo,
            'session_id'     => bin2hex(random_bytes(16)),
            'cliente_id'     => 2,
            'valor_centavos' => 25900,
            'frete_centavos' => 2900,
            'parcelas'       => 3,
            'metodo'         => 'cartao_credito',
            'ip'             => '189.4.20.11',
            'cliente' => [
                'nome'      => 'Joao da Silva Teste',
                'email'     => 'qa@sportmoto.com.br',
                'documento' => '19100000000',
                'telefone'  => '(11) 98888-7777',
                'endereco'  => [
                    'logradouro' => 'Avenida Paulista',
                    'numero'     => '1000',
                    'bairro'     => 'Bela Vista',
                    'cidade'     => 'Sao Paulo',
                    'uf'         => 'SP',
                    'cep'        => '01310-100',
                ],
            ],
            'itens' => [
                ['sku' => 'CAP-001', 'nome' => 'Capacete integral', 'valor_centavos' => 23000, 'quantidade' => 1],
            ],
            'cartao' => [
                'bin'      => '411111',
                'ultimos4' => '1111',
                'validade' => '12/2030',
                'titular'  => 'JOAO S TESTE',
            ],
        ];

        // Mostra o corpo exato que sai — util quando a API recusa e nao diz por que.
        $ref = new ReflectionMethod(ClearSaleService::class, 'montarPedido');
        $ref->setAccessible(true);
        echo "--- corpo enviado ---\n";
        echo json_encode($ref->invoke($cs, $pedido), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n\n";

        $t0 = microtime(true);
        $r  = $cs->analisar($pedido);
        $ms = (int) ((microtime(true) - $t0) * 1000);

        echo "--- resposta ---\n";
        printf("  pedido        %s\n", $codigo);
        printf("  latencia      %d ms\n", $ms);
        printf("  codigo status %s\n", $r['codigo_status'] ?? '-');
        printf("  status        %s\n", $r['status']);
        printf("  risco         %s\n", $r['risco']);
        printf("  score         %s\n", $r['score'] === null ? '(nulo)' : (string) $r['score']);
        printf("  packageID     %s\n", $r['analise_id'] ?? '-');
        printf("  motivo        %s\n", $r['motivo'] ?? '-');
        echo "\n  bruto: ", json_encode($r['bruto'], JSON_UNESCAPED_UNICODE), "\n";

        if ($r['status'] === 'erro') exit(1);
        break;
}
