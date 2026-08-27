<?php
declare(strict_types=1);

/**
 * app/services/payment/antifraude/ClearSaleParecerService.php
 *
 * Busca o parecer da ClearSale e resolve o pedido retido.
 *
 * Existe porque DOIS caminhos precisam da mesma regra: o cron
 * (cli/clearsale-worker.php) e a notificação (WebhookController::clearsale).
 * Duplicar "quando liberar um pedido" em dois lugares é como as duas metades
 * acabam discordando — e a metade errada é a que libera mercadoria.
 *
 * A REGRA, num parágrafo:
 *   Parecer aprovado libera o pedido sozinho — foi para isso que a ClearSale
 *   foi contratada. Parecer reprovado NÃO recusa sozinho: recusar significa
 *   estornar dinheiro já capturado, com custo e sem volta. O erro caro é
 *   assimétrico, então a automação só age na direção barata.
 */
class ClearSaleParecerService
{
    /** Não persegue parecer indefinidamente: passou disso, é caso para humano. */
    public const DIAS_LIMITE = 7;

    /** Espaçamento entre consultas do mesmo pedido, no cron. */
    public const MINUTOS_ENTRE_CONSULTAS = 5;

    /** Piso entre consultas disparadas por notificação. Ver pendentePorCodigo(). */
    public const PISO_WEBHOOK_SEG = 30;

    private PDO $db;
    private ClearSaleService $cs;
    private PagamentoNotificador $notificador;
    private ScorePenalidadeService $penalidade;

    public function __construct(
        ?PDO $db = null,
        ?ClearSaleService $cs = null,
        ?PagamentoNotificador $notificador = null,
        ?ScorePenalidadeService $penalidade = null
    ) {
        $this->db          = $db ?? Database::getInstance()->getConnection();
        $this->cs          = $cs ?? new ClearSaleService();
        $this->notificador = $notificador ?? new PagamentoNotificador($this->db);
        $this->penalidade  = $penalidade  ?? new ScorePenalidadeService($this->db);
    }

    public function configurado(): bool
    {
        return $this->cs->configurado();
    }

    /** Análises que ainda devem parecer e já podem ser reconsultadas. */
    public function pendentes(int $limite = 50): array
    {
        $st = $this->db->prepare(
            "SELECT id, pedido_id, order_id_loja, codigo_status, consultas, modo
               FROM pgto_antifraude
              WHERE provedor = 'clearsale'
                AND codigo_status IN ('NVO','AMA')
                AND criado_em >= (NOW() - INTERVAL :dias DAY)
                AND (consultado_em IS NULL OR consultado_em <= (NOW() - INTERVAL :min MINUTE))
              ORDER BY criado_em ASC
              LIMIT :lim"
        );
        $st->bindValue(':dias', self::DIAS_LIMITE, PDO::PARAM_INT);
        $st->bindValue(':min',  self::MINUTOS_ENTRE_CONSULTAS, PDO::PARAM_INT);
        $st->bindValue(':lim',  max(1, min($limite, 500)), PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Análise pendente pelo código do pedido, quase ignorando o espaçamento.
     *
     * É o que a notificação usa: se a ClearSale avisou que mudou, esperar os
     * 5 minutos do cron não faz sentido.
     *
     * O piso de PISO_WEBHOOK_SEG existe por um motivo só: o endpoint de
     * notificação não tem autenticação de verdade, e quem tivesse a URL
     * poderia repeti-la em laço para nos fazer martelar a API da ClearSale.
     * Trinta segundos não atrapalham notificação legítima nenhuma — elas não
     * chegam duas vezes no mesmo segundo para o mesmo pedido.
     */
    public function pendentePorCodigo(string $codigoPedido): ?array
    {
        $st = $this->db->prepare(
            "SELECT id, pedido_id, order_id_loja, codigo_status, consultas, modo
               FROM pgto_antifraude
              WHERE provedor = 'clearsale'
                AND order_id_loja = :cod
                AND codigo_status IN ('NVO','AMA')
                AND (consultado_em IS NULL OR consultado_em <= (NOW() - INTERVAL :seg SECOND))
              ORDER BY id DESC
              LIMIT 1"
        );
        $st->bindValue(':cod', $codigoPedido);
        $st->bindValue(':seg', self::PISO_WEBHOOK_SEG, PDO::PARAM_INT);
        $st->execute();

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Devolve o pedido para a próxima varredura do cron, agora.
     *
     * A notificação chama isto ANTES de processar. Se o processamento falhar
     * — rede caindo, PHP morrendo no meio —, o cron pega em seguida. É o que
     * permite responder 200 à ClearSale sem prometer o que não se cumpriu.
     */
    public function agendarConsultaImediata(int $id): void
    {
        $this->db->prepare(
            "UPDATE pgto_antifraude SET consultado_em = NULL, atualizado_em = NOW() WHERE id = ?"
        )->execute([$id]);
    }

    /**
     * Consulta o parecer e age.
     *
     * @return array{acao:string, codigo_status:?string, score:?float, motivo:?string}
     *         acao: aguardando | liberado | decisao_humana | erro
     */
    public function processar(array $linha, bool $seco = false): array
    {
        $codigo = (string) $linha['order_id_loja'];
        $r      = $this->cs->consultarStatus($codigo);

        if ($r['status'] === 'erro') {
            // Marca a tentativa mesmo assim: sem isso, uma ClearSale fora do
            // ar faria o cron reconsultar os mesmos pedidos em toda rodada.
            if (!$seco) $this->marcarConsulta((int) $linha['id'], null, null);
            return ['acao' => 'erro', 'codigo_status' => null,
                    'score' => null, 'motivo' => $r['motivo']];
        }

        $novo = (string) $r['codigo_status'];

        if (!$seco) {
            $this->marcarConsulta((int) $linha['id'], $novo, $r['score']);
        }

        if (ClearSaleService::aguardandoParecer($novo)) {
            return ['acao' => 'aguardando', 'codigo_status' => $novo,
                    'score' => $r['score'], 'motivo' => null];
        }

        if (!$seco) {
            $this->aplicarVeredito($linha, $r);
        }

        return [
            'acao'          => $r['status'] === 'aprovado' ? 'liberado' : 'decisao_humana',
            'codigo_status' => $novo,
            'score'         => $r['score'],
            'motivo'        => $r['motivo'],
        ];
    }

    // =========================================================================

    private function marcarConsulta(int $id, ?string $codigo, ?float $score): void
    {
        // COALESCE preserva o código antigo quando a consulta falhou — perder
        // o 'AMA' aqui tiraria o pedido da varredura para sempre.
        $this->db->prepare(
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

    /** Grava o veredito e move o pedido — ou não move, quando mover custa dinheiro. */
    private function aplicarVeredito(array $linha, array $r): void
    {
        $pedidoId = (int) ($linha['pedido_id'] ?? 0);
        $codigo   = (string) $linha['order_id_loja'];

        $this->db->prepare(
            "UPDATE pgto_antifraude
                SET status = ?, recomendacao = ?, motivo = ?, response_json = ?, atualizado_em = NOW()
              WHERE id = ?"
        )->execute([
            match ($r['status']) {
                'aprovado' => 'aprovado',
                'fraude'   => 'fraude',
                default    => 'reprovado',
            },
            $r['status'],
            mb_substr('Parecer da ClearSale: ' . ($r['motivo'] ?? ''), 0, 255),
            json_encode($r['bruto'], JSON_UNESCAPED_UNICODE),
            (int) $linha['id'],
        ]);

        $ctx = $this->contextoPedido($pedidoId, $codigo);

        // ── Aprovado: libera ─────────────────────────────────────────
        if ($r['status'] === 'aprovado') {
            // Pré-captura: o valor está só reservado e capturar ainda não
            // existe no adapter da Safra. Liberar sem capturar entregaria
            // mercadoria sem receber — este caso continua sendo do humano.
            if ((string) ($linha['modo'] ?? 'pos_captura') === 'pre_captura') {
                LogService::warning(
                    'ClearSale aprovou pedido em pré-captura — captura manual necessária',
                    ['pedido_id' => $pedidoId, 'codigo' => $codigo], 'pagamento'
                );
                return;
            }

            if ($pedidoId > 0) {
                $this->moverPedido($pedidoId, 'pagamento_aprovado',
                    'Liberado pelo parecer da ClearSale: ' . ($r['codigo_status'] ?? ''));
            }

            LogService::audit('Pedido liberado pelo parecer da ClearSale', [
                'pedido_id' => $pedidoId, 'codigo' => $codigo,
                'status'    => $r['codigo_status'], 'score' => $r['score'],
            ]);

            $this->notificador->pedidoAprovado($ctx);
            return;
        }

        // ── Fraude confirmada: zera o score do cliente ───────────────
        if ($r['status'] === 'fraude' && !empty($ctx['cliente_id'])) {
            $this->penalidade->marcarFraudeConfirmada(
                (int) $ctx['cliente_id'], 'ClearSale ' . ($r['codigo_status'] ?? 'FRD')
            );
        }

        // ── Reprovado: NÃO estorna sozinho ──────────────────────────
        LogService::audit('ClearSale reprovou — pedido mantido na fila de análise', [
            'pedido_id' => $pedidoId, 'codigo' => $codigo,
            'status'    => $r['codigo_status'], 'score' => $r['score'],
        ]);

        $this->notificador->pedidoEmAnalise($ctx, [
            'motivo' => 'ClearSale reprovou (' . ($r['codigo_status'] ?? '')
                      . '). Aguarda decisão — recusar exige estorno.',
        ]);
    }

    private function moverPedido(int $pedidoId, string $status, string $obs): void
    {
        $this->db->prepare("UPDATE pedidos SET status_pedido = ?, atualizado_em = NOW() WHERE id = ?")
                 ->execute([$status, $pedidoId]);

        try {
            $this->db->prepare(
                "INSERT INTO pedido_historico (pedido_id, status_novo, observacao, admin_id, criado_em)
                 VALUES (?,?,?,NULL,NOW())"
            )->execute([$pedidoId, $status, mb_substr($obs, 0, 255)]);
        } catch (\Throwable $e) {
            // Histórico é registro, não o fato. Não desfaz a liberação.
            LogService::exception($e, 'warning', 'pagamento',
                ['acao' => 'historico_clearsale', 'pedido_id' => $pedidoId]);
        }
    }

    /** Dados mínimos para a notificação fazer sentido no sino. */
    private function contextoPedido(int $pedidoId, string $codigo): array
    {
        $ctx = ['order_id_loja' => $codigo, 'pedido_id' => $pedidoId];
        if ($pedidoId <= 0) return $ctx;

        $st = $this->db->prepare(
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
}
