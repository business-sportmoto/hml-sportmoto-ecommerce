<?php
declare(strict_types=1);

/**
 * EstoqueService — controle de estoque baseado em log.
 *
 * Princípios:
 *  - O log é a fonte da verdade.
 *  - O saldo em estoque_saldo é um cache consistente, nunca calculado na leitura.
 *  - Toda movimentação é atômica (transação) e idempotente (idempotency_key).
 *  - Nunca permite saldo negativo (salvo override explícito).
 */
class EstoqueService {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ────────────────────────────────────────────────────────
    // API PÚBLICA
    // ────────────────────────────────────────────────────────

    /**
     * Entrada de estoque (compra, devolução, ajuste positivo).
     */
    public function entrada(
        int     $produtoId,
        int     $quantidade,
        string  $tipo       = 'entrada_manual',
        string  $origem     = 'admin',
        array   $opcoes     = []
    ): array {
        if ($quantidade <= 0) {
            throw new \InvalidArgumentException('Quantidade deve ser maior que zero.');
        }
        return $this->mover($produtoId, $quantidade, 'entrada', $tipo, $origem, $opcoes);
    }

    /**
     * Saída de estoque (venda, descarte, ajuste negativo).
     */
    public function saida(
        int     $produtoId,
        int     $quantidade,
        string  $tipo       = 'saida_manual',
        string  $origem     = 'admin',
        array   $opcoes     = []
    ): array {
        if ($quantidade <= 0) {
            throw new \InvalidArgumentException('Quantidade deve ser maior que zero.');
        }
        return $this->mover($produtoId, $quantidade, 'saida', $tipo, $origem, $opcoes);
    }

    /**
     * Reserva estoque (carrinho/checkout — não sai do saldo, mas bloqueia).
     */
    public function reservar(
        int    $produtoId,
        int    $quantidade,
        array  $opcoes = []
    ): array {
        if ($quantidade <= 0) {
            throw new \InvalidArgumentException('Quantidade deve ser maior que zero.');
        }
        return $this->mover($produtoId, $quantidade, 'reserva', 'reserva', 'checkout', $opcoes);
    }

    /**
     * Libera reserva (cancelamento, timeout de carrinho).
     */
    public function liberarReserva(
        int    $produtoId,
        int    $quantidade,
        array  $opcoes = []
    ): array {
        return $this->mover($produtoId, $quantidade, 'reserva_cancelada', 'reserva_cancelada', 'sistema', $opcoes);
    }

    /**
     * Define o saldo absoluto (inventário/correção).
     * Calcula a diferença e registra entrada ou saída.
     */
    public function corrigir(
        int    $produtoId,
        int    $novoSaldo,
        string $observacao = '',
        array  $opcoes     = [],
        string $origem = 'admin'
    ): array {
        $saldoAtual = $this->getSaldo($produtoId, $opcoes['sku_id'] ?? null);
        $diferenca  = $novoSaldo - $saldoAtual;

        if ($diferenca === 0) {
            return ['ok' => true, 'msg' => 'Saldo já está correto.', 'saldo' => $saldoAtual];
        }

        $tipo       = 'correcao';
        $direcao    = $diferenca > 0 ? 'entrada' : 'saida';
        $quantidade = abs($diferenca);

        $opcoes['observacao']    = $observacao ?: "Correção: {$saldoAtual} → {$novoSaldo}";
        $opcoes['allow_negative'] = true; // correção pode ajustar para baixo mesmo sem saldo

        return $this->mover($produtoId, $quantidade, $direcao, $tipo, $origem, $opcoes);
    }

    // vindo de integrações

    /**
     * Entrada de estoque (compra, devolução, ajuste positivo).
     */
    public function entrada_int(
        int     $produtoId,
        int     $quantidade,
        string  $tipo       = 'entrada_manual',
        string  $origem     = 'admin',
        array   $opcoes     = []
    ): array {
        if ($quantidade <= 0) {
            throw new \InvalidArgumentException('Quantidade deve ser maior que zero.');
        }
        return $this->mover($produtoId, $quantidade, 'entrada', $tipo, $origem, $opcoes);
    }

    public function saida_int(
        int     $produtoId,
        int     $quantidade,
        string  $tipo       = 'saida_manual',
        string  $origem     = 'admin',
        array   $opcoes     = []
    ): array {
        if ($quantidade <= 0) {
            throw new \InvalidArgumentException('Quantidade deve ser maior que zero.');
        }
        return $this->mover($produtoId, $quantidade, 'saida', $tipo, $origem, $opcoes);
    }

    /**
     * Recalcula o saldo a partir do log (auditoria).
     * Não altera o saldo atual — apenas retorna o valor calculado.
     */
    public function recalcular(int $produtoId, ?int $skuId = null): array {
        $stmt = $this->db->prepare(
            "SELECT
                SUM(CASE
                    WHEN tipo IN ('entrada_manual','entrada_nf','entrada_devolucao','correcao')
                         AND quantidade > 0 THEN quantidade
                    WHEN tipo IN ('saida_venda','saida_manual','saida_ajuste','correcao')
                         AND quantidade < 0 THEN quantidade
                    WHEN tipo IN ('entrada_manual','entrada_nf','entrada_devolucao')
                         THEN quantidade
                    WHEN tipo IN ('saida_venda','saida_manual','saida_ajuste')
                         THEN -quantidade
                    ELSE 0
                END) AS saldo_calculado
             FROM estoque_log
             WHERE produto_id = ?
               AND (sku_id = ? OR (sku_id IS NULL AND ? IS NULL))
               AND tipo NOT IN ('reserva','reserva_cancelada')"
        );
        $stmt->execute([$produtoId, $skuId, $skuId]);
        $saldoCalculado = (int)$stmt->fetchColumn();

        $saldoAtual = $this->getSaldo($produtoId, $skuId);
        $divergencia = $saldoCalculado !== $saldoAtual;

        return [
            'produto_id'      => $produtoId,
            'sku_id'          => $skuId,
            'saldo_atual'     => $saldoAtual,
            'saldo_calculado' => $saldoCalculado,
            'divergencia'     => $divergencia,
            'diferenca'       => $saldoCalculado - $saldoAtual,
        ];
    }

    /**
     * Corrige divergências encontradas no recalculo.
     */
    public function sincronizarDivergencia(int $produtoId, ?int $skuId = null): array {
        $recalculo = $this->recalcular($produtoId, $skuId);

        if (!$recalculo['divergencia']) {
            return ['ok' => true, 'msg' => 'Sem divergência.'];
        }

        // Gera log de tipo 'recalculo' sem alterar o saldo em cascata
        $this->db->prepare(
            "UPDATE estoque_saldo SET saldo = ?
             WHERE produto_id = ? AND (sku_id = ? OR (sku_id IS NULL AND ? IS NULL))"
        )->execute([
            $recalculo['saldo_calculado'],
            $produtoId, $skuId, $skuId,
        ]);

        LogService::warning("Divergência de estoque corrigida: produto {$produtoId}, SKU {$skuId}: "
            . "{$recalculo['saldo_atual']} → {$recalculo['saldo_calculado']}");

        return [
            'ok'         => true,
            'corrigido'  => true,
            'saldo_novo' => $recalculo['saldo_calculado'],
        ];
    }

    // ────────────────────────────────────────────────────────
    // CONSULTAS
    // ────────────────────────────────────────────────────────

    /**
     * Retorna o saldo atual de um produto/SKU.
     */
    public function getSaldo(int $produtoId, ?int $skuId = null): int {
        $stmt = $this->db->prepare(
            "SELECT saldo FROM estoque_saldo
             WHERE produto_id = ?
               AND (sku_id = ? OR (sku_id IS NULL AND ? IS NULL))
             LIMIT 1"
        );
        $stmt->execute([$produtoId, $skuId, $skuId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * Retorna o estoque disponível (saldo - reservado).
     */
    public function getDisponivel(int $produtoId, ?int $skuId = null): int {
        $stmt = $this->db->prepare(
            "SELECT GREATEST(saldo - reservado, 0) AS disponivel
             FROM estoque_saldo
             WHERE produto_id = ?
               AND (sku_id = ? OR (sku_id IS NULL AND ? IS NULL))
             LIMIT 1"
        );
        $stmt->execute([$produtoId, $skuId, $skuId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }
    /**
     * Retorna o estoque do produto ou variação 
     */
    public function getDisponivelNivelVar(array $pro_data): array {
        if($pro_data['tem_variacao']){
            $stmt = $this->db->prepare(
                "SELECT
                    ps.id, ps.sku,
                    COALESCE(es.saldo,     0) AS saldo,
                    COALESCE(es.reservado, 0) AS reservado,
                    GREATEST(COALESCE(es.saldo,0) - COALESCE(es.reservado,0), 0) AS disponivel
                FROM produto_skus ps
                LEFT JOIN estoque_saldo es
                        ON es.sku_id = ps.id AND es.produto_id = ps.produto_id
                WHERE ps.produto_id = ? AND ps.ativo = 1
                ORDER BY ps.id ASC"
            );
            $stmt->execute([$pro_data['id']]);
            $estoqueSkus   = $stmt->fetchAll();
            $saldoTotal    = array_sum(array_column($estoqueSkus, 'saldo'));
            $reservadoTotal= array_sum(array_column($estoqueSkus, 'reservado'));
            $disponivelTotal = max(0, $saldoTotal - $reservadoTotal);

            return [
                'estoqueSkus' => $estoqueSkus,
                'saldoTotal' => $saldoTotal,
                'reservadoTotal' => $reservadoTotal,
                'disponivelTotal' => $disponivelTotal,
            ];
        }else{
            $saldoTotal     = $this->getSaldo((int)$pro_data['id']);
            $disponivelTotal= $this->getDisponivel((int)$pro_data['id']);
            $reservadoTotal = $saldoTotal - $disponivelTotal;
            return [
                'estoqueSkus' => [],
                'saldoTotal' => $saldoTotal, 
                'reservadoTotal' => $reservadoTotal,
                'disponivelTotal' => $disponivelTotal,
            ];
        }
    }

    /**
     * Histórico de movimentações com filtros.
     */
    public function getHistorico(
        int    $produtoId,
        ?int   $skuId  = null,
        int    $limit  = 50,
        int    $offset = 0,
        array  $filtros = []
    ): array {
        $where  = "el.produto_id = ?";
        $params = [$produtoId];

        if ($skuId !== null) {
            $where   .= " AND el.sku_id = ?";
            $params[] = $skuId;
        }
        if (!empty($filtros['tipo'])) {
            $where   .= " AND el.tipo = ?";
            $params[] = $filtros['tipo'];
        }
        if (!empty($filtros['origem'])) {
            $where   .= " AND el.origem = ?";
            $params[] = $filtros['origem'];
        }
        if (!empty($filtros['data_inicio'])) {
            $where   .= " AND el.criado_em >= ?";
            $params[] = $filtros['data_inicio'];
        }
        if (!empty($filtros['data_fim'])) {
            $where   .= " AND el.criado_em <= ?";
            $params[] = $filtros['data_fim'];
        }

        $stmt = $this->db->prepare(
            "SELECT el.*,
                    u.nome AS usuario_nome
             FROM estoque_log el
             LEFT JOIN usuarios u ON u.id = el.usuario_id
             WHERE {$where}
             ORDER BY el.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$limit, $offset]));
        return $stmt->fetchAll();
    }

    /**
     * Total de movimentações para paginação.
     */
    public function countHistorico(int $produtoId, ?int $skuId = null): int {
        $where  = "produto_id = ?";
        $params = [$produtoId];
        if ($skuId !== null) {
            $where   .= " AND sku_id = ?";
            $params[] = $skuId;
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM estoque_log WHERE {$where}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // ────────────────────────────────────────────────────────
    // CORE PRIVADO
    // ────────────────────────────────────────────────────────

    /**
     * Núcleo de movimentação — atômico, idempotente, consistente.
     *
     * Opções disponíveis:
     *  sku_id           int    — SKU específico
     *  referencia_tipo  string — 'pedido', 'nf', 'ajuste'
     *  referencia_id    int    — ID da referência
     *  idempotency_key  string — chave única para prevenir duplicidade
     *  usuario_id       int    — admin que executou
     *  payload          array  — dados brutos da origem
     *  observacao       string — nota livre
     *  allow_negative   bool   — permite saldo negativo (default: false)
     */
    private function mover(
        int    $produtoId,
        int    $quantidade,
        string $direcao,  // 'entrada' | 'saida' | 'reserva' | 'reserva_cancelada'
        string $tipo,
        string $origem,
        array  $opcoes = []
    ): array {
        $skuId          = $opcoes['sku_id']          ?? null;
        $referencaTipo  = $opcoes['referencia_tipo'] ?? null;
        $referencaId    = $opcoes['referencia_id']   ?? null;
        $idempotencyKey = $opcoes['idempotency_key'] ?? null;
        $usuarioId      = $opcoes['usuario_id']      ?? Session::get('admin_id');
        $payload        = $opcoes['payload']         ?? null;
        $observacao     = $opcoes['observacao']      ?? null;
        $allowNegative  = $opcoes['allow_negative']  ?? false;

        // ── Idempotência: rejeita eventos duplicados ──────
        if ($idempotencyKey) {
            $stmt = $this->db->prepare(
                "SELECT id, saldo_posterior FROM estoque_log
                 WHERE idempotency_key = ? LIMIT 1"
            );
            $stmt->execute([$idempotencyKey]);
            $existing = $stmt->fetch();

            if ($existing) {
                return [
                    'ok'              => true,
                    'idempotente'     => true,
                    'log_id'          => $existing['id'],
                    'saldo_posterior' => $existing['saldo_posterior'],
                    'msg'             => 'Evento já processado anteriormente.',
                ];
            }
        }

        try {
            $this->db->beginTransaction();

            // ── Lock pessimista na linha de saldo ────────
            $stmt = $this->db->prepare(
                "SELECT saldo, reservado FROM estoque_saldo
                 WHERE produto_id = ?
                   AND (sku_id = ? OR (sku_id IS NULL AND ? IS NULL))
                 FOR UPDATE"
            );
            $stmt->execute([$produtoId, $skuId, $skuId]);
            $saldoRow = $stmt->fetch();

            // Se não existe linha de saldo, cria com zero
            if (!$saldoRow) {
                $this->db->prepare(
                    "INSERT INTO estoque_saldo (produto_id, sku_id, saldo, reservado)
                     VALUES (?, ?, 0, 0)"
                )->execute([$produtoId, $skuId]);

                // Re-lock
                $stmt->execute([$produtoId, $skuId, $skuId]);
                $saldoRow = $stmt->fetch();
            }

            $saldoAnterior    = (int)$saldoRow['saldo'];
            $reservadoAnterior= (int)$saldoRow['reservado'];

            // ── Calcula novo saldo ────────────────────────
            switch ($direcao) {
                // No case 'entrada', recalcula disponível mas mantém reservado:
                case 'entrada':
                    $novoSaldo     = $saldoAnterior + $quantidade;
                    $novoReservado = $reservadoAnterior; // reservado não muda na entrada
                    break;

                case 'saida':
                    if (!$allowNegative && ($saldoAnterior - $quantidade) < 0) {
                        $this->db->rollBack();
                        return [
                            'ok'  => false,
                            'msg' => "Estoque insuficiente. Disponível: {$saldoAnterior}, solicitado: {$quantidade}.",
                        ];
                    }
                    $novoSaldo = $saldoAnterior - $quantidade;

                    // Ajusta reservado para não ultrapassar o novo saldo
                    $novoReservado = min($reservadoAnterior, max(0, $novoSaldo));
                    break;

                case 'reserva':
                    $disponivel = $saldoAnterior - $reservadoAnterior;
                    if ($disponivel < $quantidade) {
                        $this->db->rollBack();
                        return [
                            'ok'  => false,
                            'msg' => "Estoque disponível insuficiente. Disponível: {$disponivel}, solicitado: {$quantidade}.",
                        ];
                    }
                    $novoSaldo    = $saldoAnterior;
                    $novoReservado= $reservadoAnterior + $quantidade;
                    break;

                case 'reserva_cancelada':
                    $novoSaldo    = $saldoAnterior;
                    $novoReservado= max(0, $reservadoAnterior - $quantidade);
                    break;

                default:
                    $this->db->rollBack();
                    throw new \InvalidArgumentException("Direção inválida: {$direcao}");
            }

            // ── Atualiza saldo ────────────────────────────
            $this->db->prepare(
                "UPDATE estoque_saldo
                 SET saldo = ?, reservado = ?
                 WHERE produto_id = ?
                   AND (sku_id = ? OR (sku_id IS NULL AND ? IS NULL))"
            )->execute([$novoSaldo, $novoReservado, $produtoId, $skuId, $skuId]);

            // ── Sincroniza campo legado nos produtos/SKUs ─
            if ($skuId) {
                $this->db->prepare(
                    "UPDATE produto_skus SET estoque = ? WHERE id = ?"
                )->execute([$novoSaldo, $skuId]);

                // Recalcula estoque_total do produto somando todos os SKUs
                $this->db->prepare(
                    "UPDATE produtos
                     SET estoque_total = (
                         SELECT COALESCE(SUM(es.saldo), 0)
                         FROM estoque_saldo es
                         WHERE es.produto_id = ? AND es.sku_id IS NOT NULL
                     )
                     WHERE id = ?"
                )->execute([$produtoId, $produtoId]);
            } else {
                $this->db->prepare(
                    "UPDATE produtos SET estoque_total = ? WHERE id = ?"
                )->execute([$novoSaldo, $produtoId]);
            }

            // ── Insere log imutável ───────────────────────
            $stmt = $this->db->prepare(
                "INSERT INTO estoque_log (
                    produto_id, sku_id, tipo, quantidade,
                    saldo_anterior, saldo_posterior,
                    reservado_anterior, reservado_posterior,
                    origem, referencia_tipo, referencia_id,
                    idempotency_key, usuario_id, payload, observacao
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $produtoId,
                $skuId,
                $tipo,
                $quantidade,
                $saldoAnterior,
                $novoSaldo,
                $reservadoAnterior,
                $novoReservado,
                $origem,
                $referencaTipo,
                $referencaId,
                $idempotencyKey,
                $usuarioId,
                $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                $observacao,
            ]);

            $logId = (int)$this->db->lastInsertId();

            $this->db->commit();

            // ── GATILHO: voltou ao estoque (0 → positivo) ──
            // Só em entradas, fora de transação, e nunca quebra o fluxo.
            if ($direcao === 'entrada' && $saldoAnterior === 0 && $novoSaldo > 0) {
                try {
                    if (class_exists('ProdutoGatilhoService')) {
                        (new ProdutoGatilhoService())
                            ->verificarVoltaEstoque($produtoId, $saldoAnterior, $novoSaldo, $skuId);

                            LogService::info('gatilho volta_estoque: ' . $novoSaldo);
                    }
                } catch (\Throwable $e) {
                    LogService::error('gatilho volta_estoque: ' . $e->getMessage());
                }
            }

            return [
                'ok'              => true,
                'log_id'          => $logId,
                'saldo_anterior'  => $saldoAnterior,
                'saldo_posterior' => $novoSaldo,
                'reservado'       => $novoReservado,
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            LogService::error('EstoqueService::mover falhou: ' . $e->getMessage(), [$e], 'webhook');
            throw $e;
        }
    }
}