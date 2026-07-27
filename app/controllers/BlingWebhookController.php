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
        // $raw     = file_get_contents('php://input');
        // $payload = json_decode($raw, true);

        $raw = file_get_contents('php://input');

        // ── Validação de assinatura ANTES de tudo ──
        // Fail-closed: assinatura inválida → 401 e para. Não loga
        // payload (é potencialmente forjado — não polui a tabela),
        // não processa nada.
        if (!$this->assinaturaValida($raw)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'invalid signature']);
            return;
        }

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

    /**
     * Valida a assinatura HMAC do webhook (X-Bling-Signature-256).
     * A chave é o client_secret do app Bling — o mesmo da integração.
     *
     * CRÍTICO: o HMAC é calculado sobre o corpo BRUTO ($raw), nunca
     * sobre o payload re-serializado. json_encode reordena chaves e
     * altera espaços → a assinatura nunca bateria (rejeitaria tudo).
     */
    private function assinaturaValida(string $rawBody): bool
    {
        $header = $_SERVER['HTTP_X_BLING_SIGNATURE_256'] ?? '';
        if ($header === '') {
            return false; // sem assinatura = rejeita (fail-closed)
        }

        // client_secret do Bling — mesma credencial da integração.
        // Ajuste a fonte se o seu BlingAuthService expõe de outro jeito.
        $secret = (new BlingAuthService())->getClientSecret();
        if (empty($secret)) {
            // Sem secret configurado: NÃO libere por engano. Loga e nega.
            error_log('[BlingWebhook] client_secret ausente — não é possível validar assinatura.');
            return false;
        }

        // Bling manda "sha256=<hex>"; alguns provedores mandam só o hex.
        // Normaliza tirando o prefixo se houver.
        $recebida = str_starts_with($header, 'sha256=')
            ? substr($header, 7)
            : $header;

        $calculada = hash_hmac('sha256', $rawBody, $secret);

        // Comparação timing-safe — nunca use === para comparar hashes
        return hash_equals($calculada, $recebida);
    }
}