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
        // ── NÍVEL 1: produto COM variação (produto_skus) ──
        $stmt = $this->db->prepare(
            "SELECT ps.id AS sku_id, ps.produto_id
             FROM produto_skus ps
             WHERE ps.bling_id = ?
             LIMIT 1"
        );
        $stmt->execute([$blingProdutoId]);
        $sku = $stmt->fetch();

        // Cache miss — resolve via API contra produto_skus.sku
        if (!$sku) {
            $sku = $this->resolverSkuPorBlingProdutoId($blingProdutoId);
        }

        if ($sku) {
            $this->aplicarMovimento(
                (int)$sku['sku_id'],
                (int)$sku['produto_id'],
                $operacao,
                $quantidade,
                $saldoFisico
            );
            return true;
        }

        // ── NÍVEL 2: produto SEM variação (produtos.bling_id) ──
        // Mesmo fix do cron (Braço 3): produto sem linha em produto_skus,
        // estoque a nível de produto, ledger com sku_id = NULL.
        $produtoId = $this->resolverProdutoSemVariacao($blingProdutoId);

        if ($produtoId) {
            // sku_id = NULL → movimento a nível de produto
            $this->aplicarMovimento(
                null,
                $produtoId,
                $operacao,
                $quantidade,
                $saldoFisico
            );
            return true;
        }

        // ── NÍVEL 3: não existe no site → loga e ignora ──
        // Decisão de produto: webhook NÃO cria produto. Só sincroniza
        // estoque do que já foi importado. Produto no Bling mas ausente
        // do site é caso legítimo de ignorar (não é erro de vínculo).
        $this->db->prepare(
            "INSERT INTO bling_sync_log
             (tipo, direcao, referencia_id, status, msg_erro)
             VALUES ('estoque', 'webhook', ?, 'ignorado', ?)"
        )->execute([
            $blingProdutoId,
            "Produto Bling ID {$blingProdutoId} não existe no site (nem SKU nem produto). Ignorado — webhook não importa produtos.",
        ]);
        return false;
    }

    /**
     * Resolve um produto SEM variação pelo ID do Bling.
     * Primeiro pelo cache (produtos.bling_id), depois via API
     * (/produtos/{id}.codigo contra produtos.sku_legado).
     * Persiste o vínculo. Retorna produto_id ou null.
     */
    private function resolverProdutoSemVariacao(string $blingProdutoId): ?int
    {
        // 1. Cache — já vinculado em produtos.bling_id
        $stmt = $this->db->prepare(
            "SELECT id FROM produtos WHERE bling_id = ? LIMIT 1"
        );
        $stmt->execute([$blingProdutoId]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;

        // 2. Resolve via API pelo código → produtos.sku_legado
        try {
            $produto = $this->api->get("/produtos/{$blingProdutoId}");
            $codigo  = trim($produto['codigo'] ?? '');
            if (!$codigo) return null;

            $stmt = $this->db->prepare(
                "SELECT id FROM produtos
                 WHERE sku_legado = ?
                   AND NOT EXISTS (SELECT 1 FROM produto_skus ps WHERE ps.produto_id = produtos.id)
                 LIMIT 1"
            );
            $stmt->execute([$codigo]);
            $id = $stmt->fetchColumn();
            if (!$id) return null;

            // Persiste o vínculo no pai
            $this->db->prepare(
                "UPDATE produtos SET bling_id = ? WHERE id = ?"
            )->execute([$blingProdutoId, (int)$id]);

            return (int)$id;
        } catch (\Throwable) {
            return null;
        }
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
        ?int   $skuId,
        int    $produtoId,
        string $operacao,
        int    $quantidade,
        int    $saldoFisico
    ): void {
        $idempotencyKey = 'bling_mov_' . $operacao . '_'
                        . ($skuId ?? 'p' . $produtoId) . '_' . date('YmdHis');

        $opcoes = [
            'sku_id'          => $skuId,   // NULL = produto sem variação
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
        $idDeposito = $this->getDepositoPadrao();
        if (!$idDeposito) {
            return ['total' => 0, 'atualizados' => 0, 'erros' => 0,
                    'erro' => 'Nenhum depósito Bling configurado. Sincronize os depósitos primeiro.'];
        }

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
                $blingIds = array_column($batch, 'bling_id');
                $itens = $this->api->getComArray(
                    "/estoques/saldos/{$idDeposito}",
                    ['idsProdutos' => $blingIds]
                );
                foreach ($itens as $e) {
                    $blingProdutoId = (string)($e['produto']['id'] ?? $e['produtoId'] ?? '');
                    $saldo          = (float)($e['saldoFisicoTotal'] ?? $e['saldoFisico'] ?? 0);
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

                // $estoque = $this->api->get("/estoques/{$blingId}");
                // $saldo   = (float)($estoque['saldoVirtualTotal'] ?? $estoque['saldo'] ?? 0);
                $saldo = (float)$this->saldoDoBling($blingId, $idDeposito);
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
        $idDeposito = $this->getDepositoPadrao();
        if (!$idDeposito) {
            return ['ok' => false, 'msg' => 'Nenhum depósito Bling configurado. Sincronize os depósitos primeiro.'];
        }

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

        // $estoque = $this->api->get("/estoques/saldos/{$blingId}");
        $saldo = $this->saldoDoBling($blingId, $idDeposito);
        $this->corrigirSaldoAbsoluto(null, $produtoId, (float)$saldo);
        return ['ok' => true, 'bling_id' => $blingId, 'msg' => "Estoque sincronizado: {$saldo} unidade(s)."];
    }
    
    /**
     * Ressincroniza UM produto com o Bling — com ou sem variação.
     *
     * Substitui o antigo EstoqueService::recalcular(), que derivava saldo
     * do estoque_log local. Isso deixou de fazer sentido quando o Bling
     * virou dono do estoque: as baixas acontecem LÁ, e o ledger local só
     * tem os espelhamentos. Recalcular pelo log local devolvia um número
     * inventado — e o botão gravava esse número.
     *
     * Aqui a pergunta certa é "qual é o saldo no Bling AGORA?".
     *
     * Uma chamada em lote cobre todos os SKUs do produto.
     *
     * @return array{ok:bool, msg:string, atualizados?:int, sem_vinculo?:array}
     */
    public function sincronizarProduto(int $produtoId): array
    {
        $idDeposito = $this->getDepositoPadrao();
        if (!$idDeposito) {
            return ['ok' => false, 'msg' => 'Nenhum depósito Bling configurado. Sincronize os depósitos primeiro.'];
        }

        $stmt = $this->db->prepare(
            "SELECT id, nome, bling_id, sku_legado, tem_variacao
             FROM produtos WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$produtoId]);
        $prod = $stmt->fetch();
        if (!$prod) return ['ok' => false, 'msg' => 'Produto não encontrado.'];

        // Monta o mapa bling_id => sku_id (NULL = nível produto), igual ao
        // cron, mas escopado a este produto.
        $mapa = [];
        $semVinculo = [];

        $stmtSkus = $this->db->prepare(
            "SELECT id, sku, bling_id FROM produto_skus
             WHERE produto_id = ? AND ativo = 1"
        );
        $stmtSkus->execute([$produtoId]);
        $skus = $stmtSkus->fetchAll();

        if ($skus) {
            foreach ($skus as $s) {
                if (!empty($s['bling_id'])) {
                    $mapa[(string)$s['bling_id']] = (int)$s['id'];
                } else {
                    $semVinculo[] = (string)$s['sku'];
                }
            }
        } else {
            // Produto sem variação: o saldo vive no nível do produto
            if (!empty($prod['bling_id'])) {
                $mapa[(string)$prod['bling_id']] = null;
            } else {
                $semVinculo[] = (string)($prod['sku_legado'] ?? $prod['nome']);
            }
        }

        if (!$mapa) {
            return ['ok' => false,
                    'sem_vinculo' => $semVinculo,
                    'msg' => 'Nenhum vínculo com o Bling. Use "Vincular produtos" nas configurações '
                           . 'da integração — sem vínculo este produto nunca recebe saldo nem dá baixa.'];
        }

        $blingIds = array_keys($mapa);
        $atualizados = 0;

        foreach (array_chunk($blingIds, self::BATCH_SIZE) as $batch) {
            $itens = $this->api->getComArray(
                "/estoques/saldos/{$idDeposito}",
                ['idsProdutos' => $batch]
            );
            foreach ($itens as $e) {
                $bid = (string)($e['produto']['id'] ?? $e['produtoId'] ?? '');
                if (!array_key_exists($bid, $mapa)) continue;
                $saldo = (float)($e['saldoFisicoTotal'] ?? $e['saldoFisico'] ?? 0);
                $this->corrigirSaldoAbsoluto($mapa[$bid], $produtoId, $saldo);
                $atualizados++;
            }
        }

        $msg = "{$atualizados} saldo(s) atualizado(s) a partir do Bling.";
        if ($semVinculo) {
            $msg .= ' Atenção: ' . count($semVinculo) . ' sem vínculo ficaram de fora ('
                  . implode(', ', array_slice($semVinculo, 0, 5)) . ').';
        }

        return ['ok' => true, 'atualizados' => $atualizados,
                'sem_vinculo' => $semVinculo, 'msg' => $msg];
    }

    /** ID do depósito padrão (o sync usa este). Null se não sincronizou. */
    private function getDepositoPadrao(): ?int
    {
        $id = $this->db->query(
            "SELECT bling_deposito_id FROM bling_depositos
             WHERE ativo = 1 ORDER BY padrao DESC, id ASC LIMIT 1"
        )->fetchColumn();
        return $id ? (int)$id : null;
    }

    /**
     * Saldo de UM produto no Bling via /estoques/saldos/{idDeposito}.
     * Lê saldoFisicoTotal (soma de todos os depósitos). Parsing
     * defensivo — a resposta pode variar de shape.
     */
    private function saldoDoBling(string $blingProdutoId, int $idDeposito): int
    {
        $itens = $this->api->getComArray(
            "/estoques/saldos/{$idDeposito}",
            ['idsProdutos' => [$blingProdutoId]]
        );
        foreach ($itens as $e) {
            $pid = (string)($e['produto']['id'] ?? $e['produtoId'] ?? '');
            if ($pid === '' || $pid === $blingProdutoId) {
                return (int)($e['saldoFisicoTotal'] ?? $e['saldoFisico'] ?? 0);
            }
        }
        return 0;
    }

    /**
     * Estoque dos produtos que JÁ têm bling_id — em LOTE.
     * NÃO resolve vínculo (isso é o cron diário). Roda a cada 15min
     * e faz só chamadas em lote: N produtos = N/BATCH_SIZE calls.
     */
    public function sincronizarEstoque(): array
    {
        $idDeposito = $this->getDepositoPadrao();
        if (!$idDeposito) {
            return ['total'=>0,'atualizados'=>0,'erros'=>0,
                    'erro'=>'Nenhum depósito Bling configurado.'];
        }

        // Mapa bling_id => {sku_id, produto_id}: SKUs E produtos simples
        // que já têm vínculo. sku_id NULL = produto sem variação.
        $mapa = [];

        foreach ($this->db->query(
            "SELECT ps.id AS sku_id, ps.produto_id, ps.bling_id
             FROM produto_skus ps
             WHERE ps.ativo = 1 AND ps.bling_id IS NOT NULL"
        )->fetchAll() as $s) {
            $mapa[(string)$s['bling_id']] = [
                'sku_id' => (int)$s['sku_id'], 'produto_id' => (int)$s['produto_id']];
        }

        foreach ($this->db->query(
            "SELECT p.id AS produto_id, p.bling_id
             FROM produtos p
             WHERE p.ativo = 1 AND p.deleted_at IS NULL AND p.bling_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM produto_skus ps WHERE ps.produto_id = p.id)"
        )->fetchAll() as $p) {
            $mapa[(string)$p['bling_id']] = [
                'sku_id' => null, 'produto_id' => (int)$p['produto_id']];
        }

        $blingIds = array_keys($mapa);
        $total = count($blingIds);
        $atualizados = 0; $erros = 0;

        foreach (array_chunk($blingIds, self::BATCH_SIZE) as $batch) {
            try {
                $itens = $this->api->getComArray(
                    "/estoques/saldos/{$idDeposito}",
                    ['idsProdutos' => $batch]
                );
                foreach ($itens as $e) {
                    $bid = (string)($e['produto']['id'] ?? $e['produtoId'] ?? '');
                    if (!isset($mapa[$bid])) continue;
                    $saldo = (float)($e['saldoFisicoTotal'] ?? $e['saldoFisico'] ?? 0);
                    $this->corrigirSaldoAbsoluto(
                        $mapa[$bid]['sku_id'], $mapa[$bid]['produto_id'], $saldo);
                    $atualizados++;
                }
            } catch (\Throwable) {
                $erros++;
            }
        }

        return compact('total', 'atualizados', 'erros');
    }

    /**
     * Resolve bling_id de produtos/SKUs que ainda não têm — via
     * GET por código (caro, 1 call cada). RODA 1x/DIA. O LIMIT é o
     * airbag do rate limit: nunca passa de $limite×2 calls por dia.
     */
    public function resolverVinculos(int $limite = 300): array
    {
        $resolvidos = 0; $falhas = 0;

        // Produtos-pai / simples sem vínculo
        $prods = $this->db->prepare(
            "SELECT p.id, p.sku_legado
             FROM produtos p
             WHERE p.ativo = 1 AND p.deleted_at IS NULL
               AND p.bling_id IS NULL
               AND p.sku_legado IS NOT NULL AND p.sku_legado <> ''
             ORDER BY p.id LIMIT ?"
        );
        $prods->execute([$limite]);
        foreach ($prods->fetchAll() as $p) {
            $this->resolverBlingIdProduto((string)$p['sku_legado'], (int)$p['id'])
                ? $resolvidos++ : $falhas++;
            usleep(150000);
        }

        // SKUs sem vínculo
        $skus = $this->db->prepare(
            "SELECT ps.id, ps.sku, ps.produto_id
             FROM produto_skus ps
             WHERE ps.ativo = 1 AND ps.bling_id IS NULL
               AND ps.sku IS NOT NULL AND ps.sku <> ''
             ORDER BY ps.id LIMIT ?"
        );
        $skus->execute([$limite]);
        foreach ($skus->fetchAll() as $s) {
            $this->resolverBlingId((string)$s['sku'], (int)$s['produto_id'])
                ? $resolvidos++ : $falhas++;
            usleep(150000);
        }

        return compact('resolvidos', 'falhas');
    }
}