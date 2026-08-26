#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * cli/teste-pagamento-safra.php
 *
 * Ferramenta de teste da integração Safra Pay ponta a ponta: cria um pedido
 * de teste, cobra na Safra e deixa o webhook fechar o ciclo.
 *
 * USO (rodar no servidor de homologação):
 *   php cli/teste-pagamento-safra.php cartao        # aprova na hora
 *   php cli/teste-pagamento-safra.php cartao-negado # recusa (R$ 3,33)
 *   php cli/teste-pagamento-safra.php pix           # gera QR e aguarda
 *   php cli/teste-pagamento-safra.php status <codigo|chargeId>
 *   php cli/teste-pagamento-safra.php limpar        # remove os pedidos de teste
 *
 * SEGURANÇA: recusa rodar fora de homologação. Um pedido de teste no banco
 * de produção suja relatório, comissão e faturamento.
 *
 * Os pedidos criados usam o prefixo SAFRATESTE- no código, para que `limpar`
 * saiba exatamente o que remover e nada além disso.
 */

require __DIR__ . '/../bootstrap-cli.php';

const PREFIXO      = 'SAFRATESTE-';
const CLIENTE_TESTE = 2;   // cliente existente usado só como dono do pedido

$acao = $argv[1] ?? 'ajuda';
$arg  = $argv[2] ?? null;

// ── Trava de ambiente ───────────────────────────────────────────────────────
$ambiente = strtolower((string) getenv('SAFRAPAY_AMBIENTE') ?: 'hml');
if ($ambiente !== 'hml' && $acao !== 'status') {
    fwrite(STDERR, "RECUSADO: SAFRAPAY_AMBIENTE={$ambiente}. Este script só roda em homologação.\n");
    exit(1);
}

$db = Database::getInstance()->getConnection();

switch ($acao) {
    case 'cartao':        cobrarCartao($db, false); break;
    case 'cartao-negado': cobrarCartao($db, true);  break;
    case 'pix':           cobrarPix($db);           break;
    case 'status':        verStatus($db, (string) $arg); break;
    case 'limpar':        limpar($db);              break;
    default:              ajuda();
}

// ════════════════════════════════════════════════════════════════════════════

function ajuda(): void
{
    echo <<<TXT

    Teste da integração Safra Pay
    ─────────────────────────────────────────────────────────────
      cartao          Cria pedido + cobra no cartão (aprova na hora)
      cartao-negado   Mesmo fluxo com R$ 3,33 (cenário de recusa)
      pix             Cria pedido + gera QR (aguarda pagamento)
      status <ref>    Mostra pedido, transação e webhooks recebidos
      limpar          Remove os pedidos de teste deste script

    TXT;
}

/** Cria pedido + transação e devolve [pedidoId, codigo]. */
function criarPedido(PDO $db, int $centavos, string $metodo): array
{
    $codigo = PREFIXO . date('His') . rand(10, 99);
    $total  = $centavos / 100;

    $db->prepare(
        "INSERT INTO pedidos (codigo, cliente_id, forma_pagamento, subtotal, total,
                              status_pagamento, status_pedido, criado_em)
         VALUES (?, ?, ?, ?, ?, 'pendente', 'aguardando_pagamento', NOW())"
    )->execute([$codigo, CLIENTE_TESTE, $metodo, $total, $total]);

    $pedidoId = (int) $db->lastInsertId();
    echo "  pedido #{$pedidoId} criado — codigo {$codigo} — R$ " . number_format($total, 2, ',', '.') . "\n";

    return [$pedidoId, $codigo];
}

/**
 * Reserva a linha da transação ANTES de chamar a Safra.
 *
 * CORRIDA REAL: a Safra dispara ChargeCreated assim que cria a cobrança. Se a
 * linha só existisse depois da resposta, o webhook poderia chegar primeiro,
 * não achar transação local, registrar "sem transacao local" e marcar o
 * evento como processado — e o pedido nunca seria atualizado.
 *
 * O processor localiza por charge_id OU por order_id_loja, então a linha nasce
 * com order_id_loja preenchido e charge_id nulo, e recebe o charge_id depois.
 * A MESMA ORDEM vale para o checkout de verdade.
 */
function reservarTransacao(PDO $db, int $pedidoId, string $codigo, int $centavos, string $metodo): int
{
    $gw = (int) $db->query("SELECT id FROM pgto_gateways WHERE codigo = 'safrapay' LIMIT 1")->fetchColumn();
    if ($gw === 0) {
        echo "  ERRO: safrapay nao esta em pgto_gateways. Rode migration-pagamentos.sql.\n";
        return 0;
    }

    $db->prepare(
        "INSERT INTO pgto_transacoes (gateway_id, charge_id, pedido_id, order_id_loja, cliente_id,
                                      valor_centavos, moeda, metodo, parcelas, status, criado_em)
         VALUES (?, NULL, ?, ?, ?, ?, 'BRL', ?, 1, 'pendente', NOW())"
    )->execute([$gw, $pedidoId, $codigo, CLIENTE_TESTE, $centavos, $metodo]);

    $id = (int) $db->lastInsertId();
    echo "  transacao #{$id} reservada (ANTES de chamar a Safra)\n";
    return $id;
}

function gravarChargeId(PDO $db, int $transacaoId, ?string $chargeId): void
{
    if ($transacaoId === 0 || !$chargeId) return;
    $db->prepare("UPDATE pgto_transacoes SET charge_id = ? WHERE id = ?")->execute([$chargeId, $transacaoId]);
    echo "  charge {$chargeId} gravado na transacao #{$transacaoId}\n";
}

function clienteTeste(): array
{
    return [
        'nome'      => 'Cliente Teste Safra',
        'email'     => 'business@sportmoto.com.br',
        'documento' => '12345678909',
        'telefone'  => '51994214617',
        'endereco'  => [
            'logradouro' => 'Rua Exemplo', 'numero' => '100', 'bairro' => 'Centro',
            'cidade'     => 'Porto Alegre', 'uf' => 'RS', 'cep' => '90000000',
        ],
    ];
}

function cobrarCartao(PDO $db, bool $negar): void
{
    // R$ 3,33 no Elo/Amex é o cenário de recusa documentado pela Safra.
    $centavos = $negar ? 333 : 4990;
    $cartao   = $negar
        ? ['numero' => '6277800000002390', 'bandeira' => 'elo']         // recusa
        : ['numero' => '5502093769921690', 'bandeira' => 'mastercard']; // aprova

    echo "\n▸ CARTÃO " . ($negar ? '(cenário de recusa)' : '(cenário de aprovação)') . "\n";
    [$pedidoId, $codigo] = criarPedido($db, $centavos, 'cartao_credito');
    $txId = reservarTransacao($db, $pedidoId, $codigo, $centavos, 'cartao');

    $r = (new SafraPayAdapter())->autorizarCartao([
        'order_id_loja'    => $codigo,
        'tentativa_ref'    => $codigo . '-t1',
        'valor_centavos'   => $centavos,
        'parcelas'         => 1,
        'session_id'       => 'teste-' . $codigo,
        'ip_cliente'       => '203.0.113.10',
        'descricao_fatura' => 'SportMoto',
        'cliente'          => clienteTeste(),
        'cartao'           => $cartao + [
            'cvv' => '123', 'titular' => 'CLIENTE TESTE',
            'validade_mes' => 12, 'validade_ano' => '2030',
        ],
    ]);

    echo "  resultado: porta={$r->porta} classe={$r->classeErro}\n";
    echo "  ABECS={$r->codigoAdquirente} MAC=" . ($r->merchantAdviceCode ?? '-') . "\n";
    echo "  mensagem ao cliente: \"{$r->mensagemCliente}\"\n";

    gravarChargeId($db, $txId, $r->chargeId);

    echo "\n  O webhook ChargeUpdated deve chegar em segundos.\n";
    echo "  Confira com:  php cli/teste-pagamento-safra.php status {$codigo}\n\n";
}

function cobrarPix(PDO $db): void
{
    echo "\n▸ PIX\n";
    $centavos = 1500;
    [$pedidoId, $codigo] = criarPedido($db, $centavos, 'pix');
    $txId = reservarTransacao($db, $pedidoId, $codigo, $centavos, 'pix');

    $r = (new SafraPayAdapter())->criarPix([
        'order_id_loja'  => $codigo,
        'tentativa_ref'  => $codigo . '-t1',
        'valor_centavos' => $centavos,
        'ip_cliente'     => '203.0.113.10',
        'cliente'        => clienteTeste(),
    ]);

    echo "  resultado: porta={$r->porta} classe={$r->classeErro}\n";
    gravarChargeId($db, $txId, $r->chargeId);

    if ($r->pixQrCode) {
        echo "\n  ── COPIA E COLA ──\n  {$r->pixQrCode}\n";
        echo "\n  ATENCAO: QR de homologacao NAO e pago por app de banco real.\n";
        echo "  Para confirmar, use o simulador do portal ou peca ao suporte Safra.\n";
    }
    echo "\n  Confira com:  php cli/teste-pagamento-safra.php status {$codigo}\n\n";
}

function verStatus(PDO $db, string $ref): void
{
    if ($ref === '') { echo "  informe o codigo do pedido ou o chargeId\n"; return; }

    echo "\n▸ STATUS de {$ref}\n\n";

    $st = $db->prepare(
        "SELECT p.id, p.codigo, p.total, p.status_pagamento, p.status_pedido, p.pago_em
           FROM pedidos p
          WHERE p.codigo = ?
          LIMIT 1"
    );
    $st->execute([$ref]);
    $pedido = $st->fetch(PDO::FETCH_ASSOC);

    $st = $db->prepare(
        "SELECT * FROM pgto_transacoes
          WHERE order_id_loja = ? OR charge_id = ?
          ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$ref, $ref]);
    $tx = $st->fetch(PDO::FETCH_ASSOC);

    if (!$pedido && $tx && !empty($tx['pedido_id'])) {
        $st = $db->prepare("SELECT id,codigo,total,status_pagamento,status_pedido,pago_em FROM pedidos WHERE id=?");
        $st->execute([$tx['pedido_id']]);
        $pedido = $st->fetch(PDO::FETCH_ASSOC);
    }

    if ($pedido) {
        printf("  PEDIDO   #%s  %s  R$ %s\n", $pedido['id'], $pedido['codigo'], number_format((float)$pedido['total'], 2, ',', '.'));
        printf("           status_pagamento = %s\n", $pedido['status_pagamento']);
        printf("           status_pedido    = %s\n", $pedido['status_pedido']);
        printf("           pago_em          = %s\n", $pedido['pago_em'] ?? '-');
    } else {
        echo "  PEDIDO   nao encontrado\n";
    }

    if ($tx) {
        printf("\n  TRANSACAO charge=%s status=%s pago_em=%s\n",
            $tx['charge_id'], $tx['status'], $tx['pago_em'] ?? '-');
    }

    $chargeId = $tx['charge_id'] ?? $ref;
    $st = $db->prepare(
        "SELECT w.id, w.tipo, w.assinatura_valida, w.processado, w.erro, w.recebido_em
           FROM pgto_webhook_log w
           JOIN pgto_gateways g ON g.id = w.gateway_id
          WHERE g.codigo = 'safrapay' AND w.charge_id = ?
          ORDER BY w.id"
    );
    $st->execute([$chargeId]);
    $logs = $st->fetchAll(PDO::FETCH_ASSOC);

    echo "\n  WEBHOOKS recebidos: " . count($logs) . "\n";
    foreach ($logs as $w) {
        printf("    #%-4s %-22s auth=%s processado=%s  %s\n",
            $w['id'], $w['tipo'],
            $w['assinatura_valida'] ? 'ok ' : 'NAO',
            $w['processado'] ? 'sim' : 'nao',
            $w['recebido_em']);
        if (!empty($w['erro'])) printf("          erro: %s\n", mb_substr($w['erro'], 0, 120));
    }

    // Estado real na Safra — desempata quando o webhook não chegou.
    if ($chargeId && $chargeId !== $ref || $tx) {
        try {
            $c = (new SafraPayAdapter())->consultar((string) $chargeId);
            printf("\n  NA SAFRA porta=%s classe=%s ABECS=%s\n", $c->porta, $c->classeErro, $c->codigoAdquirente ?? '-');
        } catch (\Throwable $e) {
            echo "\n  NA SAFRA consulta falhou: " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
}

function limpar(PDO $db): void
{
    $st = $db->prepare("SELECT id, codigo FROM pedidos WHERE codigo LIKE ?");
    $st->execute([PREFIXO . '%']);
    $pedidos = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$pedidos) { echo "  nenhum pedido de teste encontrado\n"; return; }

    foreach ($pedidos as $p) {
        $db->prepare("DELETE FROM pgto_transacoes WHERE pedido_id = ?")->execute([$p['id']]);
        $db->prepare("DELETE FROM pgto_tentativas WHERE pedido_id = ? OR order_id_loja = ?")
           ->execute([$p['id'], $p['codigo']]);
        $db->prepare("DELETE FROM pedidos WHERE id = ?")->execute([$p['id']]);
        echo "  removido pedido #{$p['id']} ({$p['codigo']})\n";
    }
    echo "  total: " . count($pedidos) . "\n";
    echo "  (os registros em pgto_webhook_log ficam — sao a trilha de auditoria)\n";
}
