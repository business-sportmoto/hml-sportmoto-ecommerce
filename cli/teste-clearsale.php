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
 *   php cli/teste-clearsale.php todos --seco    valida o payload de todos os pedidos, sem enviar
 *   php cli/teste-clearsale.php todos           envia todos (independe de score) — não grava nada
 *   php cli/teste-clearsale.php parecer-todos   consulta o parecer dos enviados no último `todos`
 *       --limite=N  --desde-id=N  (opcionais em `todos`)
 *
 *   `todos` recusa rodar em produção sem --sim-producao: lá cada envio é uma
 *   consulta cobrada e vira pedido real do lado da ClearSale.
 *
 * Nenhuma credencial é impressa.
 */

require __DIR__ . '/../bootstrap-cli.php';

$cmd = $argv[1] ?? 'pedido';
$cs  = new ClearSaleService();

printf("ambiente: %s | configurado: %s\n\n", $cs->ambiente(), $cs->configurado() ? 'sim' : 'NAO');

if (!$cs->configurado() && $cmd !== 'fingerprint' && !in_array('--seco', $argv, true)) {
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

    case 'todos':
        // Todos os pedidos, independente do score — o que o checkout NÃO faz
        // (lá, cliente com score bom pula a consulta). Serve para validar a
        // integração com dados reais. NÃO grava em pedidos nem em
        // pgto_antifraude: o resultado vai só para o relatório em storage/logs.
        $seco   = in_array('--seco', $argv, true);
        $limite = 0;
        $desde  = 0;
        foreach ($argv as $a) {
            if (preg_match('/^--limite=(\d+)$/', $a, $m))   $limite = (int) $m[1];
            if (preg_match('/^--desde-id=(\d+)$/', $a, $m)) $desde  = (int) $m[1];
        }
        if (!$seco && $cs->ambiente() === 'prod' && !in_array('--sim-producao', $argv, true)) {
            fwrite(STDERR, "Ambiente PRODUCAO: cada envio e uma consulta cobrada e vira pedido real la.\n"
                         . "Rode com --seco, ou confirme com --sim-producao.\n");
            exit(1);
        }

        $db = Database::getInstance()->getConnection();
        $st = $db->prepare("SELECT id, codigo FROM pedidos WHERE id > ? ORDER BY id"
                         . ($limite > 0 ? " LIMIT {$limite}" : ''));
        $st->execute([$desde]);
        $lista = $st->fetchAll(PDO::FETCH_ASSOC);

        $montador = new ClearSalePedidoMontador($db);
        $rel      = ['ambiente' => $cs->ambiente(), 'seco' => $seco, 'inicio' => date('c'), 'pedidos' => []];
        $cont     = ['ok' => 0, 'dados' => 0, 'erro' => 0];

        printf("%s %d pedido(s) — ambiente %s\n\n", $seco ? 'validando' : 'enviando', count($lista), $cs->ambiente());

        foreach ($lista as $p) {
            $payload   = $montador->montar((int) $p['id']);
            $problemas = ClearSalePedidoMontador::problemas($payload);
            $linha     = ['pedido_id' => (int) $p['id'], 'codigo' => (string) $p['codigo'], 'problemas' => $problemas];

            if ($problemas) {
                $cont['dados']++;
                printf("  x #%-5s %-14s dados: %s\n", $p['id'], $p['codigo'], implode('; ', $problemas));
                $rel['pedidos'][] = $linha;
                continue;
            }
            if ($seco) {
                $cont['ok']++;
                printf("  ok #%-5s %-14s payload completo\n", $p['id'], $p['codigo']);
                $rel['pedidos'][] = $linha;
                continue;
            }

            $t0 = microtime(true);
            $r  = $cs->analisar($payload);
            $ms = (int) ((microtime(true) - $t0) * 1000);

            $linha += [
                'status'        => $r['status'],
                'codigo_status' => $r['codigo_status'],
                'score'         => $r['score'],
                'analise_id'    => $r['analise_id'],
                'motivo'        => $r['motivo'],
                'ms'            => $ms,
            ];

            if ($r['status'] === 'erro') {
                $cont['erro']++;
                printf("  x #%-5s %-14s erro: %s\n", $p['id'], $p['codigo'], mb_substr((string) $r['motivo'], 0, 160));
            } else {
                $cont['ok']++;
                printf("  ok #%-5s %-14s %-4s score=%s (%d ms)\n", $p['id'], $p['codigo'],
                    $r['codigo_status'] ?? '-', $r['score'] === null ? '-' : (string) $r['score'], $ms);
            }
            $rel['pedidos'][] = $linha;
            usleep(150000);   // não martelar a homologação deles
        }

        $rel['fim']    = date('c');
        $rel['totais'] = $cont;
        $arq = dirname(__DIR__) . '/storage/logs/clearsale-teste-' . date('Ymd-His') . ($seco ? '-seco' : '') . '.json';
        file_put_contents($arq, json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        printf("\n%s: %d · dados incompletos: %d · erro na API: %d\n",
            $seco ? 'completos' : 'aceitos', $cont['ok'], $cont['dados'], $cont['erro']);
        echo "relatorio: {$arq}\n";
        echo "Nada foi gravado em pedidos nem em pgto_antifraude.\n";
        break;

    case 'parecer-todos':
        // O envio só devolve NVO (recebido). O parecer — e o score — vem daqui.
        $arqs = array_values(array_filter(
            glob(dirname(__DIR__) . '/storage/logs/clearsale-teste-*.json') ?: [],
            static fn($f) => !str_ends_with($f, '-seco.json')
        ));
        rsort($arqs);
        if (!$arqs) {
            fwrite(STDERR, "Nenhum envio encontrado. Rode `todos` antes.\n");
            exit(1);
        }
        $rel      = json_decode((string) file_get_contents($arqs[0]), true) ?: [];
        $enviados = array_values(array_filter($rel['pedidos'] ?? [],
            static fn($l) => empty($l['problemas']) && ($l['status'] ?? 'erro') !== 'erro'));

        printf("parecer de %d pedido(s) enviados em %s (%s)\n\n",
            count($enviados), $rel['inicio'] ?? '?', basename($arqs[0]));

        $dist = [];
        foreach ($enviados as $l) {
            $r = $cs->consultarStatus((string) $l['codigo']);
            $k = $r['codigo_status'] ?? 'erro';
            $dist[$k] = ($dist[$k] ?? 0) + 1;
            printf("  #%-5s %-14s %-4s %-10s score=%s%s\n", $l['pedido_id'], $l['codigo'],
                $r['codigo_status'] ?? '-', $r['status'], $r['score'] === null ? '-' : (string) $r['score'],
                $r['status'] === 'erro' ? '  ' . mb_substr((string) $r['motivo'], 0, 120) : '');
            usleep(150000);
        }

        echo "\ndistribuicao: ";
        foreach ($dist as $k => $n) echo "{$k}={$n}  ";
        echo "\n";
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
