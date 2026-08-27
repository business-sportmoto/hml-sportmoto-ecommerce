<?php
declare(strict_types=1);

/**
 * cli/clearsale-worker.php
 *
 * Busca os pareceres pendentes da ClearSale e resolve os pedidos retidos.
 *
 * POR QUE ESTE WORKER EXISTE:
 *   A análise da ClearSale é assíncrona. O envio, no checkout, sempre responde
 *   `NVO` — recebido, sem julgamento. O veredito nasce minutos depois, do lado
 *   deles, e não volta sozinho: ou se consulta o endpoint de status, ou nunca
 *   se fica sabendo. Sem este worker, TODO pedido que chega ao antifraude fica
 *   retido para sempre.
 *
 * A DIVISÃO DE TRABALHO — automação libera, humano destrói:
 *   Parecer aprovado  → libera o pedido sozinho. É exatamente para isso que a
 *                       ClearSale foi contratada; segurar aqui seria pagar por
 *                       uma análise e ignorá-la.
 *   Parecer reprovado → NÃO recusa sozinho. Recusar significa estornar dinheiro
 *                       já capturado, com custo e sem volta. O worker carimba o
 *                       veredito, avisa os admins e deixa o pedido na fila para
 *                       alguém decidir com o parecer na mão.
 *
 *   O erro caro é assimétrico: liberar um pedido bom por engano custa nada;
 *   estornar um pedido bom por engano custa taxa, cliente e reputação.
 *
 *   php cli/clearsale-worker.php [--limite=50] [--verbose] [--seco]
 *
 * Cron sugerido (a cada 5 min — o SLA da ClearSale é de minutos, não segundos):
 *   a cada 5 minutos:
 *   cd /caminho && php cli/clearsale-worker.php >> storage/logs/clearsale-worker.log 2>&1
 */

require __DIR__ . '/../bootstrap-cli.php';

/** Não persegue parecer indefinidamente: passou disso, é caso para humano. */
const DIAS_LIMITE = 7;

/** Espaçamento entre consultas do mesmo pedido. */
const MINUTOS_ENTRE_CONSULTAS = 5;

$opts    = getopt('', ['limite::', 'verbose', 'seco']);
$limite  = max(1, min((int) ($opts['limite'] ?? 50), 500));
$verbose = isset($opts['verbose']);
$seco    = isset($opts['seco']);

$db          = Database::getInstance()->getConnection();
$cs          = new ClearSaleService();
$notificador = new PagamentoNotificador($db);
$penalidade  = new ScorePenalidadeService($db);

if (!$cs->configurado()) {
    fwrite(STDERR, "ClearSale sem credenciais no .env — nada a fazer.\n");
    exit(1);
}

$st = $db->prepare(
    "SELECT id, pedido_id, order_id_loja, codigo_status, consultas, modo
       FROM pgto_antifraude
      WHERE provedor = 'clearsale'
        AND codigo_status IN ('NVO','AMA')
        AND criado_em >= (NOW() - INTERVAL :dias DAY)
        AND (consultado_em IS NULL OR consultado_em <= (NOW() - INTERVAL :min MINUTE))
      ORDER BY criado_em ASC
      LIMIT :lim"
);
$st->bindValue(':dias', DIAS_LIMITE, PDO::PARAM_INT);
$st->bindValue(':min',  MINUTOS_ENTRE_CONSULTAS, PDO::PARAM_INT);
$st->bindValue(':lim',  $limite, PDO::PARAM_INT);
$st->execute();
$pendentes = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$pendentes) {
    if ($verbose) echo "nenhum parecer pendente\n";
    exit(0);
}

$contagem = ['aguardando' => 0, 'liberado' => 0, 'reprovado' => 0, 'erro' => 0];

foreach ($pendentes as $p) {
    $codigo = (string) $p['order_id_loja'];
    $r      = $cs->consultarStatus($codigo);

    if ($r['status'] === 'erro') {
        $contagem['erro']++;
        // Marca a tentativa mesmo assim: sem isso, uma ClearSale fora do ar
        // faria o worker reconsultar os mesmos pedidos em toda rodada.
        if (!$seco) marcarConsulta($db, (int) $p['id'], null, null);
        if ($verbose) printf("  %-28s ERRO  %s\n", $codigo, $r['motivo']);
        continue;
    }

    $novo = (string) $r['codigo_status'];

    if (!$seco) {
        marcarConsulta($db, (int) $p['id'], $novo, $r['score']);
    }

    // Ainda na fila deles. Só atualiza o carimbo e espera a próxima rodada.
    if (ClearSaleService::aguardandoParecer($novo)) {
        $contagem['aguardando']++;
        if ($verbose) printf("  %-28s %-4s aguardando (score %s)\n",
            $codigo, $novo, $r['score'] ?? '-');
        continue;
    }

    if (!$seco) {
        aplicarVeredito($db, $cs, $notificador, $penalidade, $p, $r);
    }

    if ($r['status'] === 'aprovado') {
        $contagem['liberado']++;
        if ($verbose) printf("  %-28s %-4s LIBERADO\n", $codigo, $novo);
    } else {
        $contagem['reprovado']++;
        if ($verbose) printf("  %-28s %-4s para decisao humana (%s)\n",
            $codigo, $novo, $r['status']);
    }
}

printf("%s: %d aguardando | %d liberados | %d para decisao humana | %d erros\n",
    $seco ? 'simulacao' : 'clearsale-worker',
    $contagem['aguardando'], $contagem['liberado'],
    $contagem['reprovado'], $contagem['erro']);

// =============================================================================

function marcarConsulta(PDO $db, int $id, ?string $codigo, ?float $score): void
{
    // COALESCE preserva o código antigo quando a consulta falhou — perder o
    // 'AMA' aqui tiraria o pedido da varredura e ele nunca mais seria buscado.
    $db->prepare(
        "UPDATE pgto_antifraude
            SET codigo_status = COALESCE(?, codigo_status),
                score         = COALESCE(?, score),
                consultas     = consultas + 1,
                consultado_em = NOW(),
                respondido_em = COALESCE(respondido_em, NOW()),
                atualizado_em = NOW()
          WHERE id = ?"
    )->execute([$codigo, $score, $id]);
}

/**
 * Grava o veredito e move o pedido — ou não move, quando mover custa dinheiro.
 */
function aplicarVeredito(
    PDO $db, ClearSaleService $cs, PagamentoNotificador $notificador,
    ScorePenalidadeService $penalidade, array $p, array $r
): void {
    $pedidoId = (int) ($p['pedido_id'] ?? 0);
    $codigo   = (string) $p['order_id_loja'];

    $db->prepare(
        "UPDATE pgto_antifraude
            SET status = ?, recomendacao = ?, motivo = ?, response_json = ?, atualizado_em = NOW()
          WHERE id = ?"
    )->execute([
        $r['status'] === 'aprovado' ? 'aprovado' : ($r['status'] === 'fraude' ? 'fraude' : 'reprovado'),
        $r['status'],
        mb_substr('Parecer da ClearSale: ' . ($r['motivo'] ?? ''), 0, 255),
        json_encode($r['bruto'], JSON_UNESCAPED_UNICODE),
        (int) $p['id'],
    ]);

    $ctx = contextoPedido($db, $pedidoId, $codigo);

    // ── Aprovado: libera ─────────────────────────────────────────────
    if ($r['status'] === 'aprovado') {
        // Pré-captura: o valor está só reservado, capturar ainda não existe no
        // adapter da Safra. Liberar sem capturar entregaria mercadoria sem
        // receber — então este caso continua sendo do humano.
        if ((string) ($p['modo'] ?? 'pos_captura') === 'pre_captura') {
            LogService::warning('ClearSale aprovou pedido em pré-captura — captura manual necessária', [
                'pedido_id' => $pedidoId, 'codigo' => $codigo,
            ], 'pagamento');
            return;
        }

        if ($pedidoId > 0) {
            moverPedido($db, $pedidoId, 'pagamento_aprovado',
                'Liberado pelo parecer da ClearSale: ' . ($r['codigo_status'] ?? ''));
        }

        LogService::audit('Pedido liberado pelo parecer da ClearSale', [
            'pedido_id' => $pedidoId, 'codigo' => $codigo,
            'status'    => $r['codigo_status'], 'score' => $r['score'],
        ]);

        $notificador->pedidoAprovado($ctx);
        return;
    }

    // ── Fraude confirmada: zera o score do cliente ───────────────────
    if ($r['status'] === 'fraude' && !empty($ctx['cliente_id'])) {
        $penalidade->marcarFraudeConfirmada(
            (int) $ctx['cliente_id'],
            'ClearSale ' . ($r['codigo_status'] ?? 'FRD')
        );
    }

    // ── Reprovado: NÃO estorna sozinho ───────────────────────────────
    // Fica na fila com o parecer carimbado. Quem clicar em recusar dispara o
    // estorno pelo caminho que já existe, com registro de quem decidiu.
    LogService::audit('ClearSale reprovou — pedido mantido na fila de análise', [
        'pedido_id' => $pedidoId, 'codigo' => $codigo,
        'status'    => $r['codigo_status'], 'score' => $r['score'],
    ]);

    $notificador->pedidoEmAnalise($ctx, [
        'motivo' => 'ClearSale reprovou (' . ($r['codigo_status'] ?? '')
                  . '). Aguarda decisão — recusar exige estorno.',
    ]);
}

function moverPedido(PDO $db, int $pedidoId, string $status, string $obs): void
{
    $db->prepare("UPDATE pedidos SET status_pedido = ?, atualizado_em = NOW() WHERE id = ?")
       ->execute([$status, $pedidoId]);

    try {
        $db->prepare(
            "INSERT INTO pedido_historico (pedido_id, status_novo, observacao, admin_id, criado_em)
             VALUES (?,?,?,NULL,NOW())"
        )->execute([$pedidoId, $status, mb_substr($obs, 0, 255)]);
    } catch (Throwable $e) {
        // Histórico é registro, não o fato. Não desfaz a liberação.
        LogService::exception($e, 'warning', 'pagamento',
            ['acao' => 'historico_clearsale', 'pedido_id' => $pedidoId]);
    }
}

/** Dados mínimos para a notificação fazer sentido no sino. */
function contextoPedido(PDO $db, int $pedidoId, string $codigo): array
{
    $ctx = ['order_id_loja' => $codigo, 'pedido_id' => $pedidoId];

    if ($pedidoId <= 0) return $ctx;

    $st = $db->prepare(
        "SELECT cliente_id, total, forma_pagamento, parcelas FROM pedidos WHERE id = ? LIMIT 1"
    );
    $st->execute([$pedidoId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return $ctx;

    return $ctx + [
        'cliente_id'     => (int) $p['cliente_id'],
        'valor_centavos' => (int) round(((float) $p['total']) * 100),
        'metodo'         => (string) ($p['forma_pagamento'] ?? ''),
        'parcelas'       => (int) ($p['parcelas'] ?? 1),
    ];
}
