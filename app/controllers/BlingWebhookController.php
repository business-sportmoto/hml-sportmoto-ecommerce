<?php
declare(strict_types=1);
 
// ════════════════════════════════════════════════════════
// app/controllers/BlingWebhookController.php
// Endpoint público que recebe callbacks do Bling.
// URL: POST /webhook/bling
// Configurar essa URL no painel do Bling → Preferências → Webhooks
// ════════════════════════════════════════════════════════
class BlingWebhookController extends Controller
{
    // ── POST /webhook/bling ───────────────────────────────
    public function receive(): void
    {
        // Lê o payload bruto
        $raw     = file_get_contents('php://input');
        $payload = json_decode($raw, true);
 
        // Responde 200 imediatamente (Bling espera < 5s)
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
 
        // Registra no log antes de processar
        try {
            $db = Database::getInstance()->getConnection();
            $db->prepare(
                "INSERT INTO bling_sync_log (tipo, direcao, status, payload)
                 VALUES ('webhook', 'webhook', 'pendente', ?)"
            )->execute([json_encode($payload)]);
            $logId = (int)$db->lastInsertId();
        } catch (\Throwable) {
            exit;
        }
 
        // Flush response para o Bling antes de processar
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
 
        // Processa o evento
        try {
            $evento = strtolower($payload['evento'] ?? $payload['event'] ?? '');
            $dados  = $payload['dados']  ?? $payload['data']  ?? [];
 
            match(true) {
                // Mudança de situação do pedido
                // ex: "pedido.situacao", "pedido", "pedido.alterado"
                str_contains($evento, 'pedido') ||
                str_contains($evento, 'order')                   => (new BlingOrderService())->processarAtualizacaoStatus($dados),
 
                // NF-e autorizada
                // ex: "notafiscal.autorizada", "nfe.autorizada"
                str_contains($evento, 'notafiscal') ||
                str_contains($evento, 'nfe')                      => (new BlingOrderService())->processarNfe($dados),
 
                // Estoque alterado
                // ex: "stock.created", "stock.updated", "estoque.alterado"
                str_contains($evento, 'stock') ||
                str_contains($evento, 'estoque')                  => $this->processarEstoqueWebhook($dados),
 
                default => null,
            };
 
            $db->prepare(
                "UPDATE bling_sync_log SET status = 'ok' WHERE id = ?"
            )->execute([$logId]);
 
        } catch (\Throwable $e) {
            try {
                $db->prepare(
                    "UPDATE bling_sync_log SET status = 'erro', msg_erro = ? WHERE id = ?"
                )->execute([$e->getMessage(), $logId]);
            } catch (\Throwable) {}
        }
 
        exit;
    }
 
    private function processarEstoqueWebhook(array $dados): void
    {
        $blingProdutoId = (string)($dados['produto']['id'] ?? '');
        $operacao       = strtoupper(trim($dados['operacao']       ?? 'B')); // E, S, B
        $quantidade     = max(0, (int)($dados['quantidade']        ?? 0));
        $saldoFisico    = max(0, (int)($dados['saldoFisicoTotal']  ?? 0));
 
        if (!$blingProdutoId) return;
 
        (new BlingEstoqueService())->sincronizarPorBlingId(
            $blingProdutoId,
            $operacao,
            $quantidade,
            $saldoFisico
        );
    }
}