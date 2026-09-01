<?php
declare(strict_types=1);

/**
 * admin/controllers/AdminAnaliseController.php
 *
 * Fila de pedidos retidos pelo antifraude, aguardando decisão humana.
 *
 * PERMISSÃO: super, gerente — aprovar aqui libera mercadoria de um pedido que
 * o sistema marcou como risco. Mesma régua das Formas de pagamento.
 *
 * O QUE ESTA TELA PRECISA MOSTRAR, e por quê:
 *   Um pedido em análise sem contexto obriga o operador a decidir no escuro.
 *   Então cada linha carrega POR QUE foi retido (a regra que decidiu), o
 *   histórico do cliente (score, pedidos, devoluções, chargebacks) e o
 *   parecer da ClearSale quando houve consulta.
 *
 * A DIFERENÇA QUE MAIS IMPORTA — pré ou pós-captura:
 *   Recusar um pedido já capturado exige ESTORNO: o dinheiro saiu da conta do
 *   cliente e precisa voltar, com custo. Recusar um pedido só autorizado é
 *   cancelamento simples e gratuito. A tela deixa isso explícito antes do
 *   operador clicar, porque são consequências financeiras diferentes.
 */
class AdminAnaliseController extends Controller
{
    private const STATUS_FILA = 'em_analise';

    private PDO $db;

    public function __construct()
    {
        AuthHelper::requireAdmin();
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->db = Database::getInstance()->getConnection();
    }

    /** GET /admin/pagamentos/analise */
    public function index(): void
    {
        $pagina = max(1, (int) ($_GET['pagina'] ?? 1));

        $st = $this->db->prepare("SELECT COUNT(*) FROM pedidos WHERE status_pedido = ?");
        $st->execute([self::STATUS_FILA]);
        $total = (int) $st->fetchColumn();

        $pag = new PaginationHelper($total, $pagina, ADMIN_URL . '/pagamentos/analise', 25);

        $st = $this->db->prepare(
            "SELECT p.id, p.codigo, p.total, p.criado_em, p.cliente_id, p.forma_pagamento,
                    u.nome  AS cliente_nome,
                    u.email AS cliente_email,
                    s.score_total, s.tier, s.penalidade_pontos,
                    s.total_chargebacks, s.total_devolucoes, s.total_pedidos,
                    s.fraude_confirmada
               FROM pedidos p
          LEFT JOIN clientes c        ON c.id = p.cliente_id
          LEFT JOIN usuarios u        ON u.id = c.usuario_id
          LEFT JOIN clientes_score s  ON s.cliente_id = p.cliente_id
              WHERE p.status_pedido = :st
           ORDER BY p.criado_em ASC
              LIMIT :lim OFFSET :off"
        );
        $st->bindValue(':st',  self::STATUS_FILA);
        $st->bindValue(':lim', $pag->getPerPage(), PDO::PARAM_INT);
        $st->bindValue(':off', $pag->offset(), PDO::PARAM_INT);
        $st->execute();
        $pedidos = $st->fetchAll(PDO::FETCH_ASSOC);

        // Contexto do antifraude por pedido. Uma query só, em vez de N.
        $codigos = array_column($pedidos, 'codigo');
        $af = $this->antifraudePorPedido($codigos);
        foreach ($pedidos as &$p) {
            $p['antifraude'] = $af[$p['codigo']] ?? null;
        }
        unset($p);

        SeoHelper::setTitle('Análise de pedidos');
        $this->render('pagamentos/analise', array_merge($pag->toArray(), [
            'pedidos' => $pedidos,
            'total'   => $total,
        ]), 'admin');
    }

    /** GET /admin/pagamentos/analise/{id} — detalhe para decidir */
    public function detalhe(int $id): void
    {
        $pedido = $this->carregarPedido($id);
        if (!$pedido) {
            $this->render('errors/404', [], 'admin');
            return;
        }

        SeoHelper::setTitle('Análise — pedido ' . $pedido['codigo']);
        $this->render('pagamentos/analise-detalhe', [
            'pedido'     => $pedido,
            'antifraude' => $this->antifraudePorPedido([$pedido['codigo']])[$pedido['codigo']] ?? null,
            'tentativas' => $this->tentativas($pedido['codigo']),
            'transacao'  => $this->transacao($pedido['codigo']),
        ], 'admin');
    }

    /**
     * POST /admin/pagamentos/analise/aprovar
     * Libera o pedido. O dinheiro já está capturado (modo pós-captura) ou
     * ainda precisa ser capturado (modo pré-captura).
     */
    public function aprovar(): void
    {
        $this->verifyCsrf();

        $id     = (int) ($_POST['pedido_id'] ?? 0);
        $motivo = trim((string) ($_POST['motivo'] ?? ''));

        if (mb_strlen($motivo) < 5) {
            $this->json(['ok' => false, 'msg' => 'Descreva o motivo da liberação (mínimo 5 caracteres).']);
        }

        $pedido = $this->carregarPedido($id);
        if (!$pedido) $this->json(['ok' => false, 'msg' => 'Pedido não encontrado.']);

        // Idempotência: dois operadores abrindo a fila ao mesmo tempo é o
        // caso normal, não a exceção.
        if ($pedido['status_pedido'] !== self::STATUS_FILA) {
            $this->json(['ok' => false, 'msg' =>
                'Este pedido já saiu da fila (status atual: ' . $pedido['status_pedido'] . ').']);
        }

        $af   = $this->antifraudePorPedido([$pedido['codigo']])[$pedido['codigo']] ?? null;
        $modo = (string) ($af['modo'] ?? 'pos_captura');

        // Pré-captura: o valor está apenas reservado. Aprovar aqui significa
        // capturar de verdade — ainda não implementado no adapter da Safra.
        if ($modo === 'pre_captura') {
            $this->json(['ok' => false, 'msg' =>
                'Este pedido está em pré-autorização e a captura ainda não está implementada. '
                . 'Capture pelo painel da adquirente antes de liberar aqui.']);
        }

        $this->mudarStatus($id, 'pagamento_aprovado',
            'Liberado na análise de risco: ' . $motivo);

        $this->registrarDecisao($pedido['codigo'], 'aprovado', $motivo);

        LogService::audit('Pedido liberado na análise de risco', [
            'pedido_id' => $id,
            'codigo'    => $pedido['codigo'],
            'regra'     => $af['regra_aplicada'] ?? null,
            'por'       => AuthHelper::usuarioId(),
            'motivo'    => mb_substr($motivo, 0, 255),
        ]);

        $this->json(['ok' => true, 'msg' => 'Pedido liberado.']);
    }

    /**
     * POST /admin/pagamentos/analise/recusar
     * Recusa e devolve o dinheiro quando já foi capturado.
     */
    public function recusar(): void
    {
        $this->verifyCsrf();

        $id     = (int) ($_POST['pedido_id'] ?? 0);
        $motivo = trim((string) ($_POST['motivo'] ?? ''));
        $fraude = !empty($_POST['fraude_confirmada']);

        if (mb_strlen($motivo) < 5) {
            $this->json(['ok' => false, 'msg' => 'Descreva o motivo da recusa (mínimo 5 caracteres).']);
        }

        $pedido = $this->carregarPedido($id);
        if (!$pedido) $this->json(['ok' => false, 'msg' => 'Pedido não encontrado.']);

        if ($pedido['status_pedido'] !== self::STATUS_FILA) {
            $this->json(['ok' => false, 'msg' =>
                'Este pedido já saiu da fila (status atual: ' . $pedido['status_pedido'] . ').']);
        }

        $tx = $this->transacao($pedido['codigo']);

        // ── Devolver o dinheiro ──────────────────────────────────────
        // Se houve captura, recusar SEM estornar deixaria o cliente pagando
        // por um pedido cancelado. O estorno vem antes da mudança de status:
        // se falhar, o pedido continua na fila e alguém tenta de novo.
        $aviso = null;
        if ($tx && !empty($tx['charge_id']) && $tx['status'] === 'aprovado') {
            $adq = AdquirenteFactory::paraTransacao($tx);

            if (!$adq || !$adq->configurado()) {
                $this->json(['ok' => false, 'msg' =>
                    'Não foi possível estornar: adquirente "' . ($tx['gateway_codigo'] ?? '?')
                    . '" indisponível. O pedido segue na fila.']);
            }

            // antifraude=true marca a recusa como antifraude na adquirente,
            // em vez de cancelamento comum — preserva o motivo real no
            // histórico dela, que é o que conta em disputa de chargeback.
            $c = $adq->cancelar((string) $tx['charge_id'], null, true);

            if (!$c->sucesso()) {
                $this->json(['ok' => false, 'msg' =>
                    'Estorno recusado pela adquirente: '
                    . ($c->mensagemAdquirente ?? $c->mensagemCliente)
                    . '. O pedido segue na fila.']);
            }

            if ($c->cancelamentoPendente) {
                $aviso = 'O estorno foi aceito e está sendo processado pela adquirente. '
                       . 'A confirmação chega por webhook.';
            }

            $this->db->prepare(
                "UPDATE pgto_transacoes SET status = ?, atualizado_em = NOW() WHERE id = ?"
            )->execute([$c->cancelamentoPendente ? 'estorno_pendente' : 'estornado', $tx['id']]);
        }

        $this->mudarStatus($id, 'cancelado', 'Recusado na análise de risco: ' . $motivo);

        // Fraude confirmada zera o score e liga a trava permanente.
        if ($fraude && !empty($pedido['cliente_id'])) {
            (new ScorePenalidadeService($this->db))
                ->marcarFraudeConfirmada((int) $pedido['cliente_id'], 'Análise manual: ' . $motivo);
        }

        $this->registrarDecisao($pedido['codigo'], $fraude ? 'fraude' : 'reprovado', $motivo);

        LogService::audit('Pedido recusado na análise de risco', [
            'pedido_id'        => $id,
            'codigo'           => $pedido['codigo'],
            'fraude_confirmada'=> $fraude,
            'estornou'         => (bool) $tx,
            'por'              => AuthHelper::usuarioId(),
            'motivo'           => mb_substr($motivo, 0, 255),
        ]);

        $this->json(['ok' => true, 'msg' => 'Pedido recusado.' . ($aviso ? ' ' . $aviso : '')]);
    }

    // =========================================================================

    private function carregarPedido(int $id): ?array
    {
        $st = $this->db->prepare(
            "SELECT p.*, u.nome AS cliente_nome, u.email AS cliente_email,
                    s.score_total, s.tier, s.penalidade_pontos, s.total_chargebacks,
                    s.total_devolucoes, s.total_pedidos, s.total_pedidos_concluidos,
                    s.fraude_confirmada, s.dias_conta
               FROM pedidos p
          LEFT JOIN clientes c       ON c.id = p.cliente_id
          LEFT JOIN usuarios u       ON u.id = c.usuario_id
          LEFT JOIN clientes_score s ON s.cliente_id = p.cliente_id
              WHERE p.id = ? LIMIT 1"
        );
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string, array> indexado por order_id_loja */
    private function antifraudePorPedido(array $codigos): array
    {
        if (!$codigos) return [];

        $ph = implode(',', array_fill(0, count($codigos), '?'));
        $st = $this->db->prepare(
            "SELECT a.* FROM pgto_antifraude a
               JOIN (SELECT order_id_loja, MAX(id) AS ultimo
                       FROM pgto_antifraude
                      WHERE order_id_loja IN ({$ph})
                   GROUP BY order_id_loja) u
                 ON u.ultimo = a.id"
        );
        $st->execute($codigos);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['order_id_loja']] = $r;
        }
        return $out;
    }

    private function tentativas(string $orderIdLoja): array
    {
        $st = $this->db->prepare(
            "SELECT sequencia, adquirente_codigo, metodo, parcelas, bandeira,
                    resultado, classe_erro, codigo_adquirente, mensagem_cliente,
                    duracao_ms, criado_em
               FROM pgto_tentativas
              WHERE order_id_loja = ?
           ORDER BY id"
        );
        $st->execute([$orderIdLoja]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function transacao(string $orderIdLoja): ?array
    {
        $st = $this->db->prepare(
            "SELECT t.*, g.codigo AS gateway_codigo, g.nome AS gateway_nome
               FROM pgto_transacoes t
          LEFT JOIN pgto_gateways g ON g.id = t.gateway_id
              WHERE t.order_id_loja = ?
           ORDER BY t.id DESC LIMIT 1"
        );
        $st->execute([$orderIdLoja]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Fecha o registro de antifraude com a decisão humana. */
    private function registrarDecisao(string $orderIdLoja, string $status, string $motivo): void
    {
        try {
            $this->db->prepare(
                "UPDATE pgto_antifraude
                    SET status = ?, motivo = ?, respondido_em = NOW()
                  WHERE order_id_loja = ?
               ORDER BY id DESC LIMIT 1"
            )->execute([$status, mb_substr('Decisão manual: ' . $motivo, 0, 255), $orderIdLoja]);
        } catch (\Throwable $e) {
            LogService::exception($e, 'warning', 'pagamento', ['acao' => 'registrar_decisao_analise']);
        }
    }

    /** Histórico e notificação pelo caminho oficial do projeto. */
    private function mudarStatus(int $pedidoId, string $slug, string $observacao): void
    {
        $pagoEm = $slug === 'pagamento_aprovado' ? date('Y-m-d H:i:s') : null;
        $statusPgto = $slug === 'pagamento_aprovado' ? 'aprovado' : 'estornado';

        $this->db->prepare(
            "UPDATE pedidos
                SET status_pedido = :sp, status_pagamento = :spg,
                    pago_em = COALESCE(:pago, pago_em)
              WHERE id = :id"
        )->execute([':sp' => $slug, ':spg' => $statusPgto, ':pago' => $pagoEm, ':id' => $pedidoId]);

        try {
            // Recusa na análise de risco já sabe o próprio motivo — não
            // faz sentido pedir ao admin. Sem isto, todo cancelamento
            // por antifraude cairia em "motivo não informado" e o
            // relatório perderia a maior causa isolada de cancelamento.
            $motivoId = $slug !== 'pagamento_aprovado'
                ? (new AdminPedido())->motivoCancelamentoIdPorSlug('pagamento_recusado')
                : null;

            (new AdminPedidoService())->mudarStatus(
                $pedidoId, $slug, $observacao, 0, true, $motivoId, $observacao
            );
        } catch (\Throwable $e) {
            LogService::exception($e, 'warning', 'pagamento', [
                'pedido_id' => $pedidoId, 'acao' => 'mudarStatus_analise',
            ]);
        }
    }
}
