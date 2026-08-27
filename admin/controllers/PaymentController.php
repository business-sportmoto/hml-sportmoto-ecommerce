<?php
declare(strict_types=1);

/**
 * Admin/PaymentController
 *
 * Painel administrativo do módulo de pagamentos. Concentra:
 *   - Dashboard com KPIs e alertas
 *   - Listagem de transações (filtros + paginação)
 *   - Detalhe de transação (drill-down)
 *   - Estorno manual (total ou parcial)
 *   - Listagem de webhooks recebidos
 *   - Detalhe de webhook
 *   - Reprocessamento manual de webhook com erro
 *
 * Padrão de auth seguindo o resto do projeto:
 *   - AuthHelper::requirePermission('financeiro') em ações sensíveis (estorno, reprocess)
 *   - AuthHelper::requireAdmin nas demais
 *   - verifyCsrf() em todo POST
 *   - LogService::audit em qualquer ação que muda estado
 *
 * Rotas sugeridas (a registrar no router):
 *   GET  /admin/payment                       → index()
 *   GET  /admin/payment/transacoes            → transacoes()
 *   GET  /admin/payment/transacoes/:id        → detalheTransacao($id)
 *   POST /admin/payment/transacoes/:id/consultar   → consultarTransacao($id)
 *   POST /admin/payment/transacoes/:id/estornar   → estornar($id)
 *   GET  /admin/payment/webhooks              → webhooks()
 *   GET  /admin/payment/webhooks/:id          → detalheWebhook($id)
 *   POST /admin/payment/webhooks/:id/reprocessar → reprocessarWebhook($id)
 */
class PaymentController extends Controller
{
    // =================================================================
    // INDEX (Dashboard)
    // =================================================================
    public function index(): void
    {
        AuthHelper::requireAdmin();

        $diasJanela = $this->intGet('janela', 30);
        $diasJanela = max(7, min(90, $diasJanela)); // 7-90 dias

        $service = new PaymentDashboardService();
        $dash    = $service->coletar($diasJanela);

        $this->render('payment/index', [
            'dash'   => $dash,
            'titulo' => 'Pagamentos — Painel',
        ], 'admin');
    }

    // =================================================================
    // LISTAGEM DE TRANSAÇÕES
    // =================================================================
    public function transacoes(): void
    {
        AuthHelper::requireAdmin();

        // Coleta filtros do GET com fallback seguro
        $filtros = [
            'status'         => $this->strGet('status'),
            'metodo'         => $this->strGet('metodo'),
            'gateway_codigo' => $this->strGet('gateway'),
            'provedor_real'  => $this->strGet('provedor'),
            'order_id_loja'  => $this->strGet('order_id'),
            'charge_id'      => $this->strGet('charge_id'),
            'pedido_id'      => $this->intGet('pedido_id', 0) ?: null,
            'data_de'        => $this->strGet('data_de'),
            'data_ate'       => $this->strGet('data_ate'),
            'valor_min'      => $this->strGet('valor_min'),
            'valor_max'      => $this->strGet('valor_max'),
            'busca'          => $this->strGet('busca'),
        ];
        $pagina    = $this->intGet('pagina', 1);
        $porPagina = $this->intGet('por_pagina', 25);

        $listSvc = new PaymentListService();
        $resultado = $listSvc->listarTransacoes($filtros, $pagina, $porPagina);

        // Provedores conhecidos pro select
        $provedores = $this->provedoresDistintos();

        $this->render('payment/transacoes', [
            'resultado'  => $resultado,
            'filtros'    => $filtros,
            'provedores' => $provedores,
            'titulo'     => 'Transações',
        ], 'admin');
    }

    public function detalheTransacao(int $id): void
    {
        AuthHelper::requireAdmin();

        $listSvc = new PaymentListService();
        $tx = $listSvc->detalheTransacao($id);
        if (!$tx) {
            $this->renderError('Transação não encontrada.', 'admin');
            return;
        }

        $this->render('payment/transacao-detalhe', [
            'tx'     => $tx,
            'titulo' => 'Transação #' . $id,
        ], 'admin');
    }

    /**
     * POST /admin/payment/transacoes/:id/estornar
     *
     * Body: { valor?: float (em reais), motivo: string, _csrf: ... }
     * Sem valor = estorno total. Com valor = estorno parcial.
     */
    public function estornar(int $id): void
    {
        AuthHelper::requirePermission('financeiro');
        $this->verifyCsrf();

        // Carrega a transação
        $listSvc = new PaymentListService();
        $tx = $listSvc->detalheTransacao($id);
        if (!$tx) {
            $this->json(['ok' => false, 'msg' => 'Transação não encontrada.']);
        }
        if (empty($tx['charge_id'])) {
            $this->json(['ok' => false, 'msg' => 'Transação sem charge_id no gateway (não pode ser estornada).']);
        }
        if (in_array($tx['status'], ['estornado', 'cancelado', 'chargeback'], true)) {
            $this->json(['ok' => false, 'msg' => 'Transação já está em status final de cancelamento.']);
        }
        if ($tx['status'] !== 'aprovado') {
            $this->json(['ok' => false, 'msg' => 'Só é possível estornar transações aprovadas. Status atual: ' . $tx['status']]);
        }

        $valorReais = (string) ($_POST['valor'] ?? '');
        $motivo     = trim((string) ($_POST['motivo'] ?? ''));

        if ($motivo === '' || mb_strlen($motivo) < 5) {
            $this->json(['ok' => false, 'msg' => 'Informe um motivo (mínimo 5 caracteres) para o estorno.']);
        }

        $valorCentavos = null;
        if ($valorReais !== '') {
            $valorReaisFloat = (float) str_replace(',', '.', $valorReais);
            if ($valorReaisFloat <= 0) {
                $this->json(['ok' => false, 'msg' => 'Valor de estorno inválido.']);
            }
            $valorCentavos = (int) round($valorReaisFloat * 100);
            if ($valorCentavos > (int) $tx['valor_centavos']) {
                $this->json(['ok' => false, 'msg' => 'Valor do estorno não pode ser maior que o valor da transação.']);
            }
        }

        // ── Executa na adquirente QUE PROCESSOU esta cobrança ────────
        //
        // Antes ia por PaymentService, que resolve um gateway global. Com
        // várias adquirentes isso manda o estorno para a errada — que
        // responde "não encontrado" e o dinheiro do cliente não volta.
        $adq = AdquirenteFactory::paraTransacao($tx);
        if (!$adq) {
            $this->json(['ok' => false, 'msg' =>
                'Não há adapter implementado para a adquirente "' . ($tx['gateway_codigo'] ?? '?') . '".']);
        }
        if (!$adq->configurado()) {
            $this->json(['ok' => false, 'msg' =>
                'A adquirente "' . $adq->codigo() . '" está sem credenciais configuradas.']);
        }

        try {
            $c = $adq->cancelar((string) $tx['charge_id'], $valorCentavos);
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'pagamento', [
                'acao' => 'estorno_manual', 'transacao_id' => $id,
            ]);
            $this->json(['ok' => false, 'msg' => 'Erro ao processar estorno: ' . $e->getMessage()]);
        }

        // Pendente NÃO é falha: cancelamento pós-liquidação (D+N) entra em
        // PendingCancel e a adquirente confirma depois, por webhook. Tratar
        // como erro faria o operador pedir estorno de novo.
        if (!$c->sucesso()) {
            $this->json([
                'ok'  => false,
                'msg' => 'A adquirente recusou o estorno: ' . ($c->mensagemAdquirente ?? $c->mensagemCliente),
            ]);
        }

        LogService::audit('pagamento_estornado_manual', [
            'transacao_id'   => $id,
            'charge_id'      => $tx['charge_id'],
            'adquirente'     => $adq->codigo(),
            'valor_centavos' => $valorCentavos,
            'parcial'        => $valorCentavos !== null,
            'motivo'         => mb_substr($motivo, 0, 500),
            'por'            => AuthHelper::usuarioId(),
        ]);

        $pendente = $c->cancelamentoPendente;
        $this->json([
            'ok'       => true,
            'pendente' => $pendente,
            'msg'      => $pendente
                ? 'Estorno solicitado. A adquirente está processando — a confirmação chega por webhook.'
                : 'Estorno concluído.',
            'status'   => $pendente ? 'estorno_pendente' : 'estornado',
        ]);
    }

    // =================================================================
    // LISTAGEM DE WEBHOOKS
    // =================================================================
    public function webhooks(): void
    {
        AuthHelper::requireAdmin();

        $filtros = [
            'tipo'              => $this->strGet('tipo'),
            'processado'        => $this->strGet('processado'),
            'assinatura_valida' => $this->strGet('assinatura'),
            'charge_id'         => $this->strGet('charge_id'),
            'event_id'          => $this->strGet('event_id'),
            'data_de'           => $this->strGet('data_de'),
            'data_ate'          => $this->strGet('data_ate'),
        ];
        $pagina    = $this->intGet('pagina', 1);
        $porPagina = $this->intGet('por_pagina', 25);

        $listSvc = new PaymentListService();
        $resultado = $listSvc->listarWebhooks($filtros, $pagina, $porPagina);

        // Tipos distintos pro select
        $tipos = [];
        try {
            $stmt = Database::getInstance()->getConnection()->query(
                "SELECT DISTINCT tipo FROM pgto_webhook_log
                  WHERE recebido_em >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  ORDER BY tipo"
            );
            $tipos = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) { /* ignora */ }

        $this->render('payment/webhooks', [
            'resultado' => $resultado,
            'filtros'   => $filtros,
            'tipos'     => $tipos,
            'titulo'    => 'Webhooks',
        ], 'admin');
    }

    public function detalheWebhook(int $id): void
    {
        AuthHelper::requireAdmin();

        $listSvc = new PaymentListService();
        $log = $listSvc->detalheWebhook($id);
        if (!$log) {
            $this->renderError('Webhook não encontrado.', 'admin');
            return;
        }

        $this->render('payment/webhook-detalhe', [
            'log'    => $log,
            'titulo' => 'Webhook #' . $id,
        ], 'admin');
    }

    /**
     * POST /admin/payment/webhooks/:id/reprocessar
     */
    /**
     * POST /admin/payment/transacoes/:id/consultar
     *
     * Consulta o status atual da cobrança diretamente no gateway e
     * atualiza pgto_transacoes. Útil quando um webhook falhou ou atrasou
     * e o status local ficou desatualizado.
     */
    public function consultarTransacao(int $id): void
    {
        AuthHelper::requireAdmin();
        $this->verifyCsrf();

        $listSvc = new PaymentListService();
        $tx = $listSvc->detalheTransacao($id);

        if (!$tx) {
            $this->json(['ok' => false, 'msg' => 'Transação não encontrada.']);
        }
        if (empty($tx['charge_id'])) {
            $this->json(['ok' => false, 'msg' => 'Transação sem charge_id — não foi criada no gateway.']);
        }

        // Consulta na adquirente que processou esta cobrança.
        $adq = AdquirenteFactory::paraTransacao($tx);
        if (!$adq) {
            $this->json(['ok' => false, 'msg' =>
                'Não há adapter implementado para a adquirente "' . ($tx['gateway_codigo'] ?? '?') . '".']);
        }

        try {
            $c = $adq->consultar((string) $tx['charge_id']);
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'pagamento', [
                'acao' => 'consulta_manual', 'transacao_id' => $id,
            ]);
            $this->json(['ok' => false, 'msg' => 'Erro ao consultar a adquirente: ' . $e->getMessage()]);
        }

        // Falha de transporte NÃO é status. Um 403 de WAF ou um timeout
        // significa "não sei" — atualizar o status a partir disso marcaria
        // pagamento legítimo como recusado.
        if (in_array($c->porta, [
            PagamentoClassificacao::ERRO_TECNICO,
            PagamentoClassificacao::INDISPONIVEL,
            PagamentoClassificacao::INCERTO,
        ], true)) {
            $this->json([
                'ok'  => false,
                'msg' => 'Não foi possível falar com a adquirente ('
                       . $c->classeErro . ', HTTP ' . ($c->httpStatus ?? 0) . '). '
                       . 'O status local foi preservado.',
            ]);
        }

        $statusNovo = match ($c->porta) {
            PagamentoClassificacao::APROVADO => 'aprovado',
            PagamentoClassificacao::PENDENTE => $c->classeErro === 'cancelamento_pendente'
                                                ? 'estorno_pendente' : 'aguardando',
            default => $c->classeErro === 'cancelado' ? 'estornado' : 'recusado',
        };

        LogService::audit('transacao_consultada_manual', [
            'transacao_id' => $id,
            'charge_id'    => $tx['charge_id'],
            'adquirente'   => $adq->codigo(),
            'status_antes' => $tx['status'],
            'status_agora' => $statusNovo,
            'abecs'        => $c->codigoAdquirente,
            'por'          => AuthHelper::usuarioId(),
        ]);

        $mudou = $statusNovo !== $tx['status'];

        $this->json([
            'ok'           => true,
            'status'       => $statusNovo,
            'status_antes' => $tx['status'],
            'mudou'        => $mudou,
            'abecs'        => $c->codigoAdquirente,
            'msg'          => $mudou
                ? "Status atualizado: {$tx['status']} → {$statusNovo}"
                : "Status confirmado pela adquirente: {$statusNovo} (sem mudança)",
        ]);
    }

    public function reprocessarWebhook(int $id): void
    {
        AuthHelper::requirePermission('financeiro');
        $this->verifyCsrf();

        try {
            $db = Database::getInstance()->getConnection();

            // ── Processor pela adquirente do evento ──────────────────
            // Estava fixo no MalgaWebhookProcessor. Com a Safra gravando na
            // mesma tabela, reprocessar um evento dela passaria pelo parser
            // errado — os payloads não têm nem o mesmo formato (PascalCase
            // com status inteiro na Safra, camelCase na Malga).
            $st = $db->prepare(
                "SELECT g.codigo FROM pgto_webhook_log w
                   JOIN pgto_gateways g ON g.id = w.gateway_id
                  WHERE w.id = ? LIMIT 1"
            );
            $st->execute([$id]);
            $codigo = (string) ($st->fetchColumn() ?: '');

            $processor = match ($codigo) {
                'safrapay' => new SafraPayWebhookProcessor(),
                'malga'    => new MalgaWebhookProcessor(),
                default    => null,
            };

            if (!$processor) {
                $this->json(['ok' => false, 'msg' =>
                    'Não há processor para a adquirente "' . ($codigo ?: 'desconhecida') . '".']);
            }

            // Reset do flag pra forçar reprocessamento
            $db->prepare(
                "UPDATE pgto_webhook_log
                    SET processado = 0, erro = NULL
                  WHERE id = :id"
            )->execute([':id' => $id]);

            $resultado = $processor->processarPorLogId($id);

            LogService::audit('webhook_reprocessado_manual', [
                'log_id'     => $id,
                'adquirente' => $codigo,
                'por'        => AuthHelper::usuarioId(),
                'ok'         => $resultado['ok'],
                'motivo'     => $resultado['motivo'],
            ]);

            $this->json([
                'ok'     => $resultado['ok'],
                'msg'    => $resultado['ok']
                    ? 'Reprocessado com sucesso: ' . $resultado['motivo']
                    : 'Falha ao reprocessar: ' . $resultado['motivo'],
                'motivo' => $resultado['motivo'],
            ]);
        } catch (\Throwable $e) {
            error_log('[Admin/Pagto.reprocessarWebhook] ' . $e->getMessage());
            $this->json(['ok' => false, 'msg' => 'Erro inesperado ao reprocessar: ' . $e->getMessage()]);
        }
    }

    // =================================================================
    // PRIVADOS
    // =================================================================

    private function provedoresDistintos(): array
    {
        try {
            $stmt = Database::getInstance()->getConnection()->query(
                "SELECT DISTINCT provedor_real
                   FROM pgto_transacoes
                  WHERE provedor_real IS NOT NULL
                    AND criado_em >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                  ORDER BY provedor_real"
            );
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) { return []; }
    }

    private function strGet(string $key, string $default = ''): string
    {
        return isset($_GET[$key]) ? trim((string) $_GET[$key]) : $default;
    }

    private function intGet(string $key, int $default = 0): int
    {
        return isset($_GET[$key]) ? (int) $_GET[$key] : $default;
    }
}