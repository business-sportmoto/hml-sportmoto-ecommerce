<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/CreditoService.php
// ════════════════════════════════════════════════════════

class CreditoService {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    

    // ════════════════════════════════════════════════════
    // OPERAÇÕES PRINCIPAIS
    // ════════════════════════════════════════════════════

    /**
     * Lança crédito na conta do cliente.
     *
     * @param int         $clienteId
     * @param float       $valor
     * @param string      $tipo        Ex: 'credito_devolucao'
     * @param string      $descricao
     * @param int|null    $diasExpiracao  null = não expira
     * @param string|null $refTipo     Ex: 'solicitacao'
     * @param int|null    $refId       ID da referência
     * @param int|null    $adminId     Quem lançou (null = sistema)
     * @return int ID da transação criada
     */
    public function creditar(
        int     $clienteId,
        float   $valor,
        string  $tipo,
        string  $descricao,
        ?int    $diasExpiracao = null,
        ?string $refTipo       = null,
        ?int    $refId         = null,
        ?int    $adminId       = null
    ): int {
        if ($valor <= 0) {
            throw new \InvalidArgumentException("Valor de crédito deve ser positivo.");
        }

        $expiraEm = $diasExpiracao
            ? date('Y-m-d H:i:s', strtotime("+{$diasExpiracao} days"))
            : null;

        $this->db->beginTransaction();
        try {
            // Lock na linha do cliente para evitar race condition
            $stmt = $this->db->prepare(
                "SELECT saldo_disponivel FROM clientes WHERE usuario_id = ? FOR UPDATE"
            );
            $stmt->execute([$clienteId]);
            $row   = $stmt->fetch();
            $saldo = (float)($row['saldo_disponivel'] ?? 0);
            $novo  = round($saldo + $valor, 2);

            $this->db->prepare(
                "UPDATE clientes SET saldo_disponivel = ? WHERE usuario_id = ?"
            )->execute([$novo, $clienteId]);

            $this->db->prepare(
                "INSERT INTO cliente_saldo_transacoes
                 (cliente_id, tipo, valor, saldo_apos, descricao,
                  referencia_tipo, referencia_id, expira_em, criado_por_admin_id)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([
                $clienteId, $tipo, $valor, $novo, $descricao,
                $refTipo, $refId, $expiraEm, $adminId,
            ]);
            $txId = (int)$this->db->lastInsertId();

            $this->db->commit();
            return $txId;

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Debita saldo do cliente.
     * Usa FIFO por expiração: consome créditos que expiram primeiro.
     *
     * @throws \RuntimeException se saldo insuficiente
     */
    public function debitar(
        int     $clienteId,
        float   $valor,
        string  $tipo,
        string  $descricao,
        ?string $refTipo = null,
        ?int    $refId   = null
    ): void {
        if ($valor <= 0) return;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT saldo_disponivel FROM clientes WHERE usuario_id = ? FOR UPDATE"
            );
            $stmt->execute([$clienteId]);
            $row   = $stmt->fetch();
            $saldo = (float)($row['saldo_disponivel'] ?? 0);

            if ($saldo < $valor) {
                $this->db->rollBack();
                throw new \RuntimeException(
                    "Saldo insuficiente. Disponível: R$ {$saldo}, solicitado: R$ {$valor}."
                );
            }

            $novo = round($saldo - $valor, 2);

            $this->db->prepare(
                "UPDATE clientes SET saldo_disponivel = ? WHERE usuario_id = ?"
            )->execute([$novo, $clienteId]);

            $this->db->prepare(
                "INSERT INTO cliente_saldo_transacoes
                 (cliente_id, tipo, valor, saldo_apos, descricao,
                  referencia_tipo, referencia_id)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$clienteId, $tipo, $valor, $novo, $descricao, $refTipo, $refId]);

            $this->db->commit();

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    // ════════════════════════════════════════════════════
    // CHECKOUT — reserva e confirmação
    // ════════════════════════════════════════════════════

    /**
     * Reserva crédito durante o checkout (salvo na sessão).
     * Não debita ainda — só confirma que o saldo existe.
     *
     * @return bool true se o saldo está disponível
     */
    public function validarReserva(int $clienteId, float $valor): bool {
        if ($valor <= 0) return true;
        $saldo = $this->getSaldoDisponivel($clienteId);
        return $saldo >= $valor;
    }

    /**
     * Confirma e debita o crédito após pedido criado.
     * Chamado em CheckoutController::process() após INSERT pedido.
     */
    public function confirmarUsoCheckout(
        int   $clienteId,
        float $valor,
        int   $pedidoId,
        $codigoPedido
    ): void {
        if ($valor <= 0) return;
        $this->debitar(
            $clienteId,
            $valor,
            'debito_compra',
            "Usado no pedido #{$codigoPedido}",
            'pedido',
            $pedidoId
        );
    }

    /**
     * Estorna crédito de compra (pedido cancelado).
     */
    public function estornarCompra(
        int   $clienteId,
        float $valor,
        int   $pedidoId,
        string $codigoPedido,
        ?int  $adminId = null
    ): void {
        if ($valor <= 0) return;
        $this->creditar(
            $clienteId,
            $valor,
            'credito_manual',
            "Estorno do pedido #{$codigoPedido} cancelado",
            30, // 30 dias para usar o crédito estornado
            'pedido',
            $pedidoId,
            $adminId
        );
    }

    // ════════════════════════════════════════════════════
    // CRON — expiração
    // ════════════════════════════════════════════════════

    /**
     * Expira créditos vencidos. Chamado pelo cron diário.
     * Percorre todas as transações vencidas e debita o valor ainda não usado.
     */
    public function expirarSaldos(): int {
        // Busca transações de crédito vencidas ainda não expiradas
        $stmt = $this->db->query(
            "SELECT t.id, t.cliente_id, t.valor, t.tipo
             FROM cliente_saldo_transacoes t
             WHERE t.expira_em <= NOW()
               AND t.expirado  = 0
               AND t.tipo LIKE 'credito%'
             ORDER BY t.cliente_id, t.expira_em ASC"
        );
        $expiradas = $stmt->fetchAll();
        $count     = 0;

        foreach ($expiradas as $tx) {
            try {
                // Marca como expirada
                $this->db->prepare(
                    "UPDATE cliente_saldo_transacoes SET expirado = 1 WHERE id = ?"
                )->execute([$tx['id']]);

                // Verifica quanto saldo o cliente ainda tem (pode já ter usado)
                $saldoAtual = $this->getSaldoDisponivel((int)$tx['cliente_id']);
                $aDebitar   = min($saldoAtual, (float)$tx['valor']);

                if ($aDebitar > 0) {
                    $this->debitar(
                        (int)$tx['cliente_id'],
                        $aDebitar,
                        'debito_expiracao',
                        "Crédito expirado (tx #{$tx['id']})",
                        'saldo_transacao',
                        (int)$tx['id']
                    );
                }
                $count++;
            } catch (\Throwable $e) {
                error_log("[CreditoService] expirarSaldos tx {$tx['id']}: " . $e->getMessage());
            }
        }

        return $count;
    }

    // ════════════════════════════════════════════════════
    // LEITURA
    // ════════════════════════════════════════════════════

    public function getSaldoDisponivel(int $clienteId): float {
        $stmt = $this->db->prepare(
            "SELECT saldo_disponivel FROM clientes WHERE usuario_id = ? LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        return (float)($stmt->fetchColumn() ?? 0);
    }

    /**
     * Histórico de transações paginado.
     */
    public function getHistorico(int $clienteId, int $limit = 20, int $offset = 0): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM cliente_saldo_transacoes
             WHERE cliente_id = ?
             ORDER BY criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$clienteId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public function contarHistorico(int $clienteId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cliente_saldo_transacoes WHERE cliente_id = ?"
        );
        $stmt->execute([$clienteId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Próximos créditos a expirar (para o painel do admin).
     */
    public function getProximosExpirando(int $clienteId, int $dias = 30): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM cliente_saldo_transacoes
             WHERE cliente_id = ?
               AND tipo LIKE 'credito%'
               AND expirado = 0
               AND expira_em IS NOT NULL
               AND expira_em <= DATE_ADD(NOW(), INTERVAL ? DAY)
             ORDER BY expira_em ASC"
        );
        $stmt->execute([$clienteId, $dias]);
        return $stmt->fetchAll();
    }
}