#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * cli/pedidos-expirar.php
 *
 * Cancela pedidos que passaram do prazo de pagamento.
 *
 * ────────────────────────────────────────────────────────────────────
 * POR QUE OS PRAZOS NÃO SÃO RELÓGIOS FIXOS
 *
 * O prazo de cada pedido é o que a ADQUIRENTE emitiu, não um número nosso.
 * Medido nos pedidos reais deste banco:
 *
 *     janela do Pix (pix_expira_em − criado_em)
 *       60 min → 13 pedidos
 *       30 min →  5 pedidos
 *       16 min →  1 pedido
 *
 * A maioria dos QRs vale 60 minutos. Cancelar num relógio fixo de 30 min
 * mataria pedido cujo código ainda é pagável por mais meia hora — o cliente
 * paga, o dinheiro entra e não há pedido do outro lado. É o pior desfecho
 * possível, e o único que este script existe para evitar.
 *
 * Por isso: `pix_expira_em` e `boleto_vencimento` mandam quando existem, e os
 * prazos configurados abaixo são o PISO para quando a adquirente não informou.
 *
 * ────────────────────────────────────────────────────────────────────
 * DUAS GUARDAS QUE VALEM MAIS QUE O PRAZO
 *
 *   1. RECONSULTA antes de cancelar. Se um webhook se perdeu, o pedido está
 *      pago e nós achamos que não. Cancelar aí destrói uma venda. Nenhum
 *      efeito sem confirmar na fonte — o mesmo princípio do
 *      SafraPayWebhookProcessor.
 *
 *   2. CANCELA A COBRANÇA na adquirente, não só no banco. Senão o Pix continua
 *      pagável no app do banco depois de o pedido morrer.
 *
 * Pedido retido pelo antifraude (`em_analise`) nunca é tocado: ali o pagamento
 * passou e quem segura é outro processo.
 *
 * ────────────────────────────────────────────────────────────────────
 * USO
 *   php cli/pedidos-expirar.php            → aplica (avisa o cliente)
 *   php cli/pedidos-expirar.php --dry-run  → só mostra o que faria
 *   php cli/pedidos-expirar.php --limite=50
 *
 *   Limpeza do passivo antigo, uma vez:
 *   php cli/pedidos-expirar.php --sem-confirmacao --silencioso
 *     --sem-confirmacao  cancela também o que não dá para confirmar
 *                        (gateway desativado, sem adapter)
 *     --silencioso       não manda e-mail — ninguém quer aviso sobre
 *                        pedido de meses atrás
 *
 * CRON (a cada 10 min):
 *   *\/10 * * * * cd /caminho && php cli/pedidos-expirar.php >> storage/logs/pedidos-expirar.log 2>&1
 */

require_once dirname(__DIR__) . '/bootstrap-cli.php';

// ── Prazos (PISO — só valem quando a adquirente não informou o dela) ──
const PRAZO_PIX_MIN     = 30;
const PRAZO_CARTAO_MIN  = 60;
const PRAZO_BOLETO_DIAS = 3;

/**
 * Folga depois do vencimento informado pela adquirente.
 *
 * Pix: minutos, para cobrir relógio dessincronizado e a latência entre o
 * pagamento e o webhook.
 *
 * Boleto: DIAS, e é a folga que mais importa — boleto pago no último dia
 * compensa em 1 a 3 dias úteis. Cancelar no vencimento mataria pedido já pago
 * e ainda não confirmado.
 */
const FOLGA_PIX_MIN     = 10;
const FOLGA_BOLETO_DIAS = 3;

$dryRun = in_array('--dry-run', $argv, true);

/**
 * Cancela também o que NÃO DÁ PARA CONFIRMAR por falta de adapter.
 *
 * É o caso da Malga: gateway desativado, sem adapter no código, 39 transações
 * antigas. A reconsulta nunca vai responder por elas, então sem esta opção
 * esses pedidos ficariam pendentes para sempre — exatamente o problema que
 * este script existe para resolver.
 *
 * Fica atrás de uma flag, e não no comportamento padrão, de propósito: o cron
 * diário jamais deve cancelar o que não conseguiu confirmar. Isto é para a
 * limpeza única do passivo, feita por uma pessoa que sabe o que está fazendo.
 */
$semConfirmacao = in_array('--sem-confirmacao', $argv, true);

/**
 * Cancela sem avisar o cliente por e-mail.
 *
 * O status `cancelado` tem `notifica_cliente = 1`, e isso está certo para o
 * cron diário: quem acabou de perder o prazo do Pix precisa saber. Mas mandar
 * hoje um "seu pedido foi cancelado" sobre um pedido de maio é ruído — o
 * cliente já esqueceu, e o aviso levanta uma dúvida em vez de resolver uma.
 *
 * Usar na limpeza do passivo. O evento continua no histórico do pedido; o que
 * não sai é o e-mail.
 */
$silencioso = in_array('--silencioso', $argv, true);

$limite = 200;
foreach ($argv as $a) {
    if (preg_match('/^--limite=(\d+)$/', $a, $m)) $limite = max(1, (int) $m[1]);
}

$db = Database::getInstance()->getConnection();

// ── Quem passou do prazo ─────────────────────────────────────────────
//
// O prazo é calculado no SQL para a seleção não depender do fuso do PHP bater
// com o do banco — a mesma razão do `idade_seg` em CartaoSalvo::refsDoCartao.
$sql = "
    SELECT p.id, p.codigo, p.cliente_id, p.forma_pagamento, p.total,
           p.status_pedido, p.status_pagamento, p.criado_em,
           p.pix_expira_em, p.boleto_vencimento,
           CASE p.forma_pagamento
             WHEN 'pix' THEN
               COALESCE(DATE_ADD(p.pix_expira_em, INTERVAL " . FOLGA_PIX_MIN . " MINUTE),
                        DATE_ADD(p.criado_em,     INTERVAL " . PRAZO_PIX_MIN . " MINUTE))
             WHEN 'boleto' THEN
               COALESCE(DATE_ADD(p.boleto_vencimento, INTERVAL " . FOLGA_BOLETO_DIAS . " DAY),
                        DATE_ADD(p.criado_em,         INTERVAL " . PRAZO_BOLETO_DIAS . " DAY))
             ELSE
               DATE_ADD(p.criado_em, INTERVAL " . PRAZO_CARTAO_MIN . " MINUTE)
           END AS vence_em
      FROM pedidos p
     WHERE p.status_pagamento IN ('pendente', 'aguardando', 'recusado', 'erro', 'falhou')
       -- `em_analise` fora: ali o pagamento passou e quem segura a mercadoria
       -- é o antifraude. Cancelar seria desfazer a decisão dele.
       AND p.status_pedido NOT IN ('cancelado', 'em_analise')
    HAVING vence_em < NOW()
     ORDER BY p.criado_em ASC
     LIMIT {$limite}
";

$pedidos = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$modo = [];
if ($dryRun)         $modo[] = 'DRY RUN';
if ($semConfirmacao) $modo[] = 'sem confirmação';
if ($silencioso)     $modo[] = 'silencioso';

printf("%s — %d pedido(s) fora do prazo%s\n\n",
    date('d/m/Y H:i'), count($pedidos),
    $modo ? '  [' . implode(' · ', $modo) . ']' : '');

if (!$pedidos) {
    echo "Nada a fazer.\n";
    exit(0);
}

$svc = new AdminPedidoService();

$cancelados = 0;
$salvos     = 0;   // reconsulta achou pago
$erros      = 0;

foreach ($pedidos as $p) {
    $id     = (int) $p['id'];
    $codigo = (string) $p['codigo'];
    $metodo = (string) $p['forma_pagamento'];

    printf("▸ #%-12s %-7s criado %s  venceu %s\n",
        $codigo, $metodo,
        date('d/m H:i', strtotime((string) $p['criado_em'])),
        date('d/m H:i', strtotime((string) $p['vence_em'])));

    // ── 1. RECONSULTA ────────────────────────────────────────────────
    // Antes de qualquer efeito, pergunta à adquirente. Um webhook perdido
    // deixaria um pedido PAGO com status pendente aqui — e cancelá-lo seria
    // destruir a venda e o dinheiro que já entrou.
    $pago = reconsultarPago($db, $codigo);

    // Adquirente sem adapter: a resposta nunca virá. Ou o operador assume o
    // risco com --sem-confirmacao, ou o pedido fica de fora.
    if ($pago === 'sem_adapter') {
        if (!$semConfirmacao) {
            printf("   adquirente sem adapter — fora (use --sem-confirmacao)\n");
            $erros++;
            continue;
        }
        printf("   adquirente sem adapter — cancelando por decisão do operador\n");
        $pago = false;
        $semConfirmar = true;
    } else {
        $semConfirmar = false;
    }

    if ($pago === true) {
        printf("   PAGO na adquirente — não cancela. Marcando para revisão.\n");
        LogService::warning('Pedido vencido estava PAGO na adquirente', [
            'pedido_id' => $id, 'codigo' => $codigo, 'metodo' => $metodo,
        ], 'pagamento');
        $salvos++;
        continue;
    }

    if ($pago === null) {
        // Não deu para confirmar (adquirente fora, sem transação registrada).
        // Na dúvida NÃO cancela: o pedido fica para a próxima execução. Um
        // pedido vencido a mais é barato; um pedido pago cancelado, não.
        printf("   não foi possível confirmar — adiado para a próxima execução\n");
        $erros++;
        continue;
    }

    if ($dryRun) {
        printf("   [dry-run] cancelaria\n");
        $cancelados++;
        continue;
    }

    // ── 2. DERRUBA A COBRANÇA NA ADQUIRENTE ──────────────────────────
    // Sem isto o Pix continua pagável no app do banco depois de o pedido
    // morrer — dinheiro entrando sem pedido.
    cancelarNaAdquirente($db, $codigo);

    // ── 3. CANCELA O PEDIDO ──────────────────────────────────────────
    try {
        // A observação diz se houve confirmação. Quem for auditar precisa
        // distinguir "a adquirente disse que não foi pago" de "ninguém
        // conseguiu perguntar".
        $obs = 'Cancelado automaticamente: prazo de pagamento expirado (' . $metodo . ').';
        if ($semConfirmar) {
            $obs .= ' Sem confirmação da adquirente — gateway sem integração ativa.';
        }

        // null = obedece a configuração do status (o cron diário avisa).
        // false = suprime o e-mail (limpeza do passivo).
        $r = $svc->mudarStatus($id, 'cancelado', $obs, 0, $silencioso ? false : null);

        if (!empty($r['ok'])) {
            $db->prepare("UPDATE pedidos SET status_pagamento = 'falhou' WHERE id = ?")
               ->execute([$id]);
            printf("   cancelado\n");
            $cancelados++;
        } else {
            printf("   FALHOU: %s\n", $r['msg'] ?? '?');
            $erros++;
        }
    } catch (\Throwable $e) {
        printf("   ERRO: %s\n", $e->getMessage());
        LogService::exception($e, 'error', 'pagamento', [
            'acao' => 'expirar_pedido', 'pedido_id' => $id,
        ]);
        $erros++;
    }
}

printf("\n──────────────────────────────────────────\n");
printf("cancelados: %d | pagos (preservados): %d | adiados/erro: %d\n",
    $cancelados, $salvos, $erros);

if ($erros > 0 && !$semConfirmacao) {
    printf("\nAlguns não puderam ser confirmados. Se forem de gateway desativado,\n");
    printf("rode uma vez com --sem-confirmacao para limpar o passivo.\n");
}

if ($cancelados > 0 && !$dryRun) {
    LogService::audit('Pedidos expirados cancelados automaticamente', [
        'quantidade' => $cancelados,
        'preservados_por_estarem_pagos' => $salvos,
    ]);
}

exit(0);


// ═══════════════════════════════════════════════════════════════════
//  AUXILIARES
// ═══════════════════════════════════════════════════════════════════

/**
 * O pedido está pago na adquirente?
 *
 * @return true          pago — não pode ser cancelado
 * @return false         não pago — seguro cancelar
 * @return null          adquirente não respondeu AGORA; tentar de novo depois
 * @return 'sem_adapter' adquirente sem integração; nunca vai responder
 *
 * A diferença entre `null` e `'sem_adapter'` é o que separa um problema
 * temporário de um permanente. Tratar os dois igual deixaria os pedidos de um
 * gateway desativado pendentes para sempre.
 */
function reconsultarPago(PDO $db, string $orderIdLoja): bool|string|null
{
    try {
        $st = $db->prepare(
            "SELECT t.charge_id, t.gateway_id, g.codigo AS gateway_codigo
               FROM pgto_transacoes t
          LEFT JOIN pgto_gateways g ON g.id = t.gateway_id
              WHERE t.order_id_loja = ?
           ORDER BY t.id DESC
              LIMIT 1"
        );
        $st->execute([$orderIdLoja]);
        $tx = $st->fetch(PDO::FETCH_ASSOC);

        // Sem transação registrada, não houve cobrança nenhuma: o pedido
        // nasceu e o cliente nunca chegou a pagar. Seguro cancelar.
        if (!$tx || empty($tx['charge_id'])) return false;

        $adapter = AdquirenteFactory::paraTransacao($tx);
        if ($adapter === null) return 'sem_adapter';

        $c = $adapter->consultar((string) $tx['charge_id']);

        if ($c->porta === PagamentoClassificacao::APROVADO) return true;

        // Porta de erro técnico/indisponível = a adquirente não respondeu.
        // Isso é "não sei", não "não pago".
        if (in_array($c->porta, [
                PagamentoClassificacao::ERRO_TECNICO,
                PagamentoClassificacao::INDISPONIVEL,
                PagamentoClassificacao::INCERTO,
            ], true)) {
            return null;
        }

        return false;

    } catch (\Throwable $e) {
        LogService::exception($e, 'warning', 'pagamento', [
            'acao' => 'reconsultar_para_expirar', 'order_id_loja' => $orderIdLoja,
        ]);
        return null;
    }
}

/** Derruba a cobrança em aberto. Falhar aqui não impede o cancelamento. */
function cancelarNaAdquirente(PDO $db, string $orderIdLoja): void
{
    try {
        $st = $db->prepare(
            "SELECT t.charge_id, t.gateway_id, g.codigo AS gateway_codigo
               FROM pgto_transacoes t
          LEFT JOIN pgto_gateways g ON g.id = t.gateway_id
              WHERE t.order_id_loja = ?
                AND t.status IN ('pendente', 'aguardando')
           ORDER BY t.id DESC
              LIMIT 1"
        );
        $st->execute([$orderIdLoja]);
        $tx = $st->fetch(PDO::FETCH_ASSOC);

        if (!$tx || empty($tx['charge_id'])) return;

        AdquirenteFactory::paraTransacao($tx)?->cancelar((string) $tx['charge_id']);

    } catch (\Throwable $e) {
        // Cobrança órfã é menos grave do que pedido eternamente pendente,
        // mas fica no log: Pix pagável depois do cancelamento vira dinheiro
        // que entra sem pedido.
        LogService::exception($e, 'error', 'pagamento', [
            'acao' => 'cancelar_cobranca_expirada', 'order_id_loja' => $orderIdLoja,
        ]);
    }
}
