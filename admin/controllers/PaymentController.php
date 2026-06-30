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

        // Executa
        try {
            $paymentSvc = new PaymentService();
            $resultado = $paymentSvc->estornar($tx['charge_id'], $valorCentavos);
        } catch (\Throwable $e) {
            error_log('[Admin/Pagto.estornar] ' . $e->getMessage());
            $this->json(['ok' => false, 'msg' => 'Erro ao processar estorno no gateway: ' . $e->getMessage()]);
        }

        if (!$resultado->ok) {
            $this->json([
                'ok'  => false,
                'msg' => 'O gateway recusou o estorno: ' . ($resultado->errorMessage ?? 'erro desconhecido'),
            ]);
        }

        // Audit
        if (class_exists('LogService') && method_exists('LogService', 'audit')) {
            LogService::audit('pagamento_estornado_manual', [
                'transacao_id'   => $id,
                'charge_id'      => $tx['charge_id'],
                'valor_centavos' => $valorCentavos,
                'motivo'         => mb_substr($motivo, 0, 500),
                'admin_id'       => (int) Session::get('admin_id'),
            ]);
        }

        $this->json([
            'ok'      => true,
            'msg'     => 'Estorno solicitado com sucesso. O status final pode demorar alguns minutos para atualizar.',
            'status'  => $resultado->status,
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

        try {
            $paymentSvc = new PaymentService();
            $resultado  = $paymentSvc->consultarStatus($tx['charge_id']);
        } catch (\Throwable $e) {
            error_log('[Admin/Pagto.consultarTransacao] ' . $e->getMessage());
            $this->json(['ok' => false, 'msg' => 'Erro ao consultar o gateway: ' . $e->getMessage()]);
        }

        if (!$resultado->ok) {
            $this->json([
                'ok'  => false,
                'msg' => 'Gateway retornou erro: ' . ($resultado->errorMessage ?? 'desconhecido'),
            ]);
        }

        if (class_exists('LogService') && method_exists('LogService', 'audit')) {
            LogService::audit('transacao_consultada_manual', [
                'transacao_id' => $id,
                'charge_id'    => $tx['charge_id'],
                'status_antes' => $tx['status'],
                'status_agora' => $resultado->status,
                'admin_id'     => (int) Session::get('admin_id'),
            ]);
        }

        $mudou = $resultado->status !== $tx['status'];

        $this->json([
            'ok'          => true,
            'status'      => $resultado->status,
            'status_antes'=> $tx['status'],
            'mudou'       => $mudou,
            'msg'         => $mudou
                ? "Status atualizado: {$tx['status']} → {$resultado->status}"
                : "Status confirmado pelo gateway: {$resultado->status} (sem mudança)",
        ]);
    }

    public function reprocessarWebhook(int $id): void
    {
        AuthHelper::requirePermission('financeiro');
        $this->verifyCsrf();

        try {
            $processor = new MalgaWebhookProcessor();

            // Reset do flag pra forçar reprocessamento
            $db = Database::getInstance()->getConnection();
            $db->prepare(
                "UPDATE pgto_webhook_log
                    SET processado = 0, erro = NULL
                  WHERE id = :id"
            )->execute([':id' => $id]);

            $resultado = $processor->processarPorLogId($id);

            if (class_exists('LogService') && method_exists('LogService', 'audit')) {
                LogService::audit('webhook_reprocessado_manual', [
                    'log_id'   => $id,
                    'admin_id' => (int) Session::get('admin_id'),
                    'ok'       => $resultado['ok'],
                    'motivo'   => $resultado['motivo'],
                ]);
            }

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