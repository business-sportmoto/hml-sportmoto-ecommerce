<?php
declare(strict_types=1);

/**
 * app/services/BlingEstoqueService.php
 *
 * Sincroniza saldo de estoque do Bling → site.
 * Executado via cron (a cada 15 minutos) ou sob demanda no painel.
 */
class BlingEstoqueService
{
    private BlingApiClient $api;
    private PDO            $db;
    private EstoqueService $estoque;

    const BATCH_SIZE = 50; // produtos por requisição

    public function __construct()
    {
        $this->api     = new BlingApiClient();
        $this->db      = Database::getInstance()->getConnection();
        $this->estoque = new EstoqueService();
    }

    // ════════════════════════════════════════════════════
    // SYNC VIA BLING PRODUTO ID (webhook)
    // ════════════════════════════════════════════════════

    /**
     * Recebe o ID interno do produto no Bling (data.produto.id do webhook),
     * a operação ('E'=entrada, 'S'=saída, 'B'=balanço) e os valores,
     * e aplica o movimento correto no EstoqueService.
     *
     * E → entrada()   — preserva tipo 'entrada_manual' no log
     * S → saida()     — preserva tipo 'saida_manual' no log
     * B → corrigir()  — ajusta para saldo absoluto
     */
    public function sincronizarPorBlingId(
        string $blingProdutoId,
        string $operacao,
        int    $quantidade,
        int    $saldoFisico
    ): bool {
        // 1. Tenta pelo cache (bling_id já foi resolvido antes)
        $stmt = $this->db->prepare(
            "SELECT ps.id AS sku_id, ps.produto_id
             FROM produto_skus ps
             WHERE ps.bling_id = ?
             LIMIT 1"
        );
        $stmt->execute([$blingProdutoId]);
        $sku = $stmt->fetch();

        // 2. Cache miss — resolve via API e persiste
        if (!$sku) {
            $sku = $this->resolverSkuPorBlingProdutoId($blingProdutoId);
        }

        if (!$sku) {
            $this->db->prepare(
                "INSERT INTO bling_sync_log
                 (tipo, direcao, referencia_id, status, msg_erro)
                 VALUES ('estoque', 'webhook', ?, 'erro', ?)"
            )->execute([
                $blingProdutoId,
                "Produto Bling ID {$blingProdutoId} não encontrado em produto_skus.",
            ]);
            return false;
        }

        $this->aplicarMovimento(
            (int)$sku['sku_id'],
            (int)$sku['produto_id'],
            $operacao,
            $quantidade,
            $saldoFisico
        );
        return true;
    }

    /**
     * Resolve o produto_skus pelo ID interno do Bling,
     * chamando GET /produtos/{id} para obter o .codigo.
     */
    private function resolverSkuPorBlingProdutoId(string $blingProdutoId): ?array
    {
        try {
            $produto = $this->api->get("/produtos/{$blingProdutoId}");
            $codigo  = trim($produto['codigo'] ?? '');
            if (!$codigo) return null;

            $stmt = $this->db->prepare(
                "SELECT ps.id AS sku_id, ps.produto_id
                 FROM produto_skus ps
                 WHERE ps.sku = ?
                 LIMIT 1"
            );
            $stmt->execute([$codigo]);
            $sku = $stmt->fetch();
            if (!$sku) return null;

            // Persiste o bling_id para não chamar a API de novo
            $this->db->prepare(
                "UPDATE produto_skus SET bling_id = ? WHERE id = ?"
            )->execute([$blingProdutoId, $sku['sku_id']]);

            return $sku;

        } catch (\Throwable) {
            return null;
        }
    }

    // ════════════════════════════════════════════════════
    // APLICA MOVIMENTO CONFORME OPERAÇÃO DO BLING
    // E = entrada · S = saída · B = balanço (corrigir)
    // ════════════════════════════════════════════════════

    private function aplicarMovimento(
        int    $skuId,
        int    $produtoId,
        string $operacao,
        int    $quantidade,
        int    $saldoFisico
    ): void {
        // idempotency_key inclui segundos para operações rápidas
        // não colide com o cron (que usa YmdHi — minutos)
        $idempotencyKey = 'bling_mov_' . $operacao . '_' . $skuId . '_' . date('YmdHis');

        $opcoes = [
            'sku_id'          => $skuId,
            'referencia_tipo' => 'bling_sync',
            'idempotency_key' => $idempotencyKey,
        ];

        match (strtoupper($operacao)) {
            // Entrada física (compra, devolução de cliente, transferência)
            'E' => $this->estoque->entrada(
                        $produtoId,
                        max(1, $quantidade),
                        'entrada_int',
                        'api_bling',
                        $opcoes
                    ),

            // Saída física (venda, descarte, transferência)
            'S' => $this->estoque->saida(
                        $produtoId,
                        max(1, $quantidade),
                        'saida_int',
                        'api_bling',
                        $opcoes
                    ),

            // Balanço — usa saldo absoluto para corrigir divergência
            default => $this->estoque->corrigir(
                        $produtoId,
                        max(0, $saldoFisico),
                        'Balanço via Bling',
                        $opcoes,
                        'api_bling'
                    ),
        };
    }
    // Chama: php artisan bling:sync-estoque
    // Ou pelo painel admin
    // ════════════════════════════════════════════════════

    public function sincronizarTudo(): array
    {
        $atualizados = 0;
        $erros       = 0;

        // ═══ BRAÇO 1 — vínculo do PAI para TODOS os produtos ═══
        // Preenche produtos.bling_id em todo produto ativo (com ou
        // sem variação). É rastreabilidade/vínculo — NÃO mexe em
        // estoque. Produto com variação tem o pai preenchido aqui,
        // mas o estoque dele continua vindo dos SKUs (Braço 2).
        $resolvidosPai = $this->resolverVinculoPais();

        // ═══ BRAÇO 2 — estoque de produto COM variação (por SKU) ═══
        $skus = $this->db->query(
            "SELECT ps.id AS sku_id, ps.sku, ps.bling_id, ps.produto_id
             FROM produto_skus ps
             JOIN produtos p ON p.id = ps.produto_id
             WHERE ps.ativo = 1"
        )->fetchAll();

        $total      = count($skus);
        $comBlingId = array_filter($skus, fn($s) => !empty($s['bling_id']));
        $semBlingId = array_filter($skus, fn($s) =>  empty($s['bling_id']));

        foreach (array_chunk($comBlingId, self::BATCH_SIZE) as $batch) {
            try {
                $ids      = implode(',', array_column($batch, 'bling_id'));
                $estoques = $this->api->get('/estoques', ['idsProdutos' => $ids]);
                foreach ($estoques as $e) {
                    $blingProdutoId = (string)($e['produto']['id'] ?? '');
                    $saldo          = (float)($e['saldoVirtualTotal'] ?? $e['saldo'] ?? 0);
                    foreach ($batch as $sku) {
                        if ($sku['bling_id'] === $blingProdutoId) {
                            $this->corrigirSaldoAbsoluto((int)$sku['sku_id'], (int)$sku['produto_id'], $saldo);
                            $atualizados++;
                        }
                    }
                }
            } catch (\Throwable) {
                $erros++;
            }
        }

        foreach ($semBlingId as $sku) {
            try {
                $blingId = $this->resolverBlingId($sku['sku'], $sku['produto_id']);
                if ($blingId) {
                    $estoque = $this->api->get("/estoques/{$blingId}");
                    $saldo   = (float)($estoque['saldoVirtualTotal'] ?? $estoque['saldo'] ?? 0);
                    $this->corrigirSaldoAbsoluto((int)$sku['sku_id'], (int)$sku['produto_id'], $saldo);
                    $atualizados++;
                }
            } catch (\Throwable) {
                $erros++;
            }
        }

        // ═══ BRAÇO 3 — estoque de produto SEM variação (nível produto) ═══
        // Estes não têm linha em produto_skus. Estoque em
        // produtos.estoque_total, ledger com sku_id = NULL.
        $simples = $this->db->query(
            "SELECT p.id AS produto_id, p.bling_id, p.sku_legado
             FROM produtos p
             WHERE p.ativo = 1
               AND p.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM produto_skus ps WHERE ps.produto_id = p.id)"
        )->fetchAll();

        $total += count($simples);

        foreach ($simples as $prod) {
            try {
                // Braço 1 já pode ter preenchido; senão resolve agora
                $blingId = $prod['bling_id']
                    ?: $this->resolverBlingIdProduto((string)($prod['sku_legado'] ?? ''), (int)$prod['produto_id']);

                if (!$blingId) { $erros++; continue; }

                $estoque = $this->api->get("/estoques/{$blingId}");
                $saldo   = (float)($estoque['saldoVirtualTotal'] ?? $estoque['saldo'] ?? 0);
                $this->corrigirSaldoAbsoluto(null, (int)$prod['produto_id'], $saldo);
                $atualizados++;
            } catch (\Throwable) {
                $erros++;
            }
        }

        return compact('total', 'atualizados', 'erros') + ['vinculos_pai' => $resolvidosPai];
    }

    // ════════════════════════════════════════════════════
    // SYNC DE UM SKU ESPECÍFICO
    // ════════════════════════════════════════════════════

    public function sincronizarSku(string $skuCode): bool
    {
        $stmt = $this->db->prepare(
            "SELECT ps.id, ps.produto_id, ps.bling_id
             FROM produto_skus ps WHERE ps.sku = ? LIMIT 1"
        );
        $stmt->execute([$skuCode]);
        $sku = $stmt->fetch();
        if (!$sku) return false;

        $blingId = $sku['bling_id'] ?: $this->resolverBlingId($skuCode, $sku['produto_id']);
        if (!$blingId) return false;

        $estoque = $this->api->get("/estoques/{$blingId}");
        $saldo   = (float)($estoque['saldoVirtualTotal'] ?? $estoque['saldo'] ?? 0);
        $this->corrigirSaldoAbsoluto((int)$sku['id'], (int)$sku['produto_id'], $saldo);

        return true;
    }

    // ════════════════════════════════════════════════════
    // RESOLVE O bling_id DE UM SKU PELO CÓDIGO
    // ════════════════════════════════════════════════════

    public function resolverBlingId(string $skuCode, int $produtoId): ?string
    {
        try {
            $result = $this->api->get('/produtos', ['codigo' => $skuCode]);
            if (empty($result[0]['id'])) return null;

            $blingId = (string)$result[0]['id'];

            // Persiste o bling_id para não precisar buscar de novo
            $this->db->prepare(
                "UPDATE produto_skus SET bling_id = ? WHERE sku = ? AND produto_id = ?"
            )->execute([$blingId, $skuCode, $produtoId]);

            return $blingId;
        } catch (\Throwable) {
            return null;
        }
    }

    // ════════════════════════════════════════════════════
    // ATUALIZA O ESTOQUE LOCAL — via EstoqueService (ledger)
    // ════════════════════════════════════════════════════

    /**
     * Usado exclusivamente pelo cron sincronizarTudo().
     * O cron puxa saldo absoluto da API — não tem tipo de operação,
     * apenas "o estoque agora é X". Por isso usa corrigir().
     * Movimentos individuais (webhook) usam aplicarMovimento().
     */
    private function corrigirSaldoAbsoluto(?int $skuId, int $produtoId, float $saldo): void
    {
        $saldoBling = max(0, (int)$saldo);
        $this->estoque->corrigir($produtoId, $saldoBling, 'Sincronização Bling (cron)', [
            'sku_id'          => $skuId,
            'referencia_tipo' => 'bling_sync',
            'idempotency_key' => 'bling_cron_' . ($skuId ?? 'p' . $produtoId) . '_' . date('YmdHi'),
        ]);
    }

    /**
     * Resolve o bling_id de um produto SEM variação e grava em
     * produtos.bling_id (o resolverBlingId original grava em
     * produto_skus, que não existe para produto simples).
     */
    public function resolverBlingIdProduto(string $codigo, int $produtoId): ?string
    {
        if ($codigo === '') return null;
        try {
            $result = $this->api->get('/produtos', ['codigo' => $codigo]);
            if (empty($result[0]['id'])) return null;

            $blingId = (string)$result[0]['id'];
            $this->db->prepare("UPDATE produtos SET bling_id = ? WHERE id = ?")
                     ->execute([$blingId, $produtoId]);
            return $blingId;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Preenche produtos.bling_id em TODO produto ativo que ainda
     * não tem vínculo, buscando por sku_legado (o "SKU / Código"
     * do produto-pai). Rastreabilidade — NÃO lê nem grava estoque.
     * Roda para pai com e sem variação.
     *
     * @return int quantos vínculos foram resolvidos nesta execução
     */
    private function resolverVinculoPais(): int
    {
        $pais = $this->db->query(
            "SELECT p.id, p.sku_legado
             FROM produtos p
             WHERE p.ativo = 1
               AND p.deleted_at IS NULL
               AND p.bling_id IS NULL
               AND p.sku_legado IS NOT NULL
               AND p.sku_legado <> ''"
        )->fetchAll();

        $resolvidos = 0;
        foreach ($pais as $p) {
            $blingId = $this->resolverBlingIdProduto((string)$p['sku_legado'], (int)$p['id']);
            if ($blingId) { $resolvidos++; }
            usleep(120000); // respiro anti rate-limit (0,12s)
        }
        return $resolvidos;
    }

    /**
     * Sincroniza o estoque de UM produto sem variação e devolve o
     * resultado com o saldo — usado pelo botão individual do admin.
     */
    public function sincronizarProdutoSimples(int $produtoId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, bling_id, sku_legado FROM produtos WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$produtoId]);
        $p = $stmt->fetch();
        if (!$p) return ['ok' => false, 'msg' => 'Produto não encontrado.'];

        $blingId = $p['bling_id']
            ?: $this->resolverBlingIdProduto((string)($p['sku_legado'] ?? ''), $produtoId);
        if (!$blingId) {
            return ['ok' => false, 'msg' => "Sem vínculo no Bling para \"{$p['sku_legado']}\"."];
        }

        $estoque = $this->api->get("/estoques/saldos/{$blingId}");
        $saldo   = (int)($estoque['saldoVirtualTotal'] ?? $estoque['saldo'] ?? 0);
        $this->corrigirSaldoAbsoluto(null, $produtoId, (float)$saldo);

        return ['ok' => true, 'bling_id' => $blingId,
                'msg' => "Estoque sincronizado: {$saldo} unidade(s)."];
    }
}