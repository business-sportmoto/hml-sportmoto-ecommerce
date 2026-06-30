<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/CartCompartilhado.php
// Analytics e gestão de carrinhos compartilhados do cliente
// ════════════════════════════════════════════════════════

class CartCompartilhado {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // LISTAGEM DO CLIENTE
    // ════════════════════════════════════════════════════

    /**
     * Retorna todos os carrinhos compartilhados de um cliente
     * com stats agregados de uso.
     */
    public function getByCliente(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT
                cc.*,
                -- Stats de uso
                COUNT(DISTINCT CASE WHEN u.acao = 'visualizou'         THEN u.sessao_hash END) AS total_visualizacoes_unicas,
                COUNT(DISTINCT CASE WHEN u.acao = 'criou_carrinho'     THEN u.sessao_hash END) AS total_carrinhos_criados,
                COUNT(DISTINCT CASE WHEN u.acao = 'finalizou_pedido'   THEN u.pedido_id   END) AS total_pedidos,
                COUNT(DISTINCT CASE WHEN u.acao IN ('criou_carrinho','finalizou_pedido') AND u.cliente_id IS NOT NULL
                                    THEN u.cliente_id END)                                      AS clientes_unicos,
                COALESCE(SUM(CASE WHEN u.acao = 'finalizou_pedido'
                                  THEN p.total END), 0)                                         AS receita_gerada,
                COALESCE(MAX(u.criado_em), cc.criado_em)                                        AS ultima_atividade
             FROM carrinhos_compartilhados cc
             LEFT JOIN carrinhos_compartilhados_uso u ON u.token = cc.token
             LEFT JOIN pedidos p ON p.id = u.pedido_id
             WHERE cc.usuario_id = ?
             GROUP BY cc.id
             ORDER BY cc.criado_em DESC"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    /**
     * Retorna um carrinho compartilhado específico (com validação de dono).
     */
    public function findByToken(string $token, int $clienteId): ?array {
        $stmt = $this->db->prepare(
            "SELECT cc.*,
                    COUNT(DISTINCT CASE WHEN u.acao = 'visualizou'       THEN u.sessao_hash END) AS total_visualizacoes_unicas,
                    COUNT(DISTINCT CASE WHEN u.acao = 'criou_carrinho'   THEN u.sessao_hash END) AS total_carrinhos_criados,
                    COUNT(DISTINCT CASE WHEN u.acao = 'finalizou_pedido' THEN u.pedido_id   END) AS total_pedidos,
                    COUNT(DISTINCT CASE WHEN u.acao IN ('criou_carrinho','finalizou_pedido')
                                        AND u.cliente_id IS NOT NULL
                                        THEN u.cliente_id END)                                    AS clientes_unicos,
                    COALESCE(SUM(CASE WHEN u.acao = 'finalizou_pedido'
                                      THEN p.total END), 0)                                       AS receita_gerada
             FROM carrinhos_compartilhados cc
             LEFT JOIN carrinhos_compartilhados_uso u ON u.token = cc.token
             LEFT JOIN pedidos p ON p.id = u.pedido_id
             WHERE cc.token = ? AND cc.usuario_id = ?
             GROUP BY cc.id
             LIMIT 1"
        );
        $stmt->execute([$token, $clienteId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Retorna o log de uso de um token específico.
     */
    public function getLog(string $token, int $clienteId): array {
        // Anti-IDOR: valida que o token pertence ao cliente
        $stmt = $this->db->prepare(
            "SELECT 1 FROM carrinhos_compartilhados
             WHERE token = ? AND usuario_id = ? LIMIT 1"
        );
        $stmt->execute([$token, $clienteId]);
        if (!$stmt->fetchColumn()) return [];

        $stmt = $this->db->prepare(
            "SELECT
                u.id,
                u.acao,
                u.criado_em,
                u.ip,
                u.pedido_id,
                u.cliente_id,
                p.codigo    AS pedido_codigo,
                p.total     AS pedido_total,
                pu.nome     AS cliente_nome,
                pu.email    AS cliente_email
             FROM carrinhos_compartilhados_uso u
             LEFT JOIN pedidos   p  ON p.id  = u.pedido_id
             LEFT JOIN clientes  c  ON c.id  = u.cliente_id
             LEFT JOIN usuarios  pu ON pu.id = c.usuario_id
             WHERE u.token = ?
             ORDER BY u.criado_em DESC"
        );
        $stmt->execute([$token]);
        return $stmt->fetchAll();
    }

    /**
     * Retorna itens do snapshot para exibição.
     */
    public function getItensSnapshot(string $token): array {
        $stmt = $this->db->prepare(
            "SELECT itens_snapshot FROM carrinhos_compartilhados
             WHERE token = ? LIMIT 1"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row || empty($row['itens_snapshot'])) return [];
        return json_decode($row['itens_snapshot'], true) ?: [];
    }

    // ════════════════════════════════════════════════════
    // REGISTRO DE EVENTOS (chamado pelo fluxo do carrinho)
    // ════════════════════════════════════════════════════

    /**
     * Registra uma ação de uso do link compartilhado.
     */
    public function registrarUso(
        string  $token,
        string  $acao,
        ?int    $clienteId = null,
        ?int    $pedidoId  = null,
        ?string $ip        = null
    ): void {
        $sessaoHash = md5(session_id());

        // Evita log duplicado de "visualizou" na mesma sessão
        if ($acao === 'visualizou') {
            $stmt = $this->db->prepare(
                "SELECT id FROM carrinhos_compartilhados_uso
                 WHERE token = ? AND sessao_hash = ? AND acao = 'visualizou'
                 AND criado_em > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 LIMIT 1"
            );
            $stmt->execute([$token, $sessaoHash]);
            if ($stmt->fetchColumn()) return;
        }

        $this->db->prepare(
            "INSERT INTO carrinhos_compartilhados_uso
             (token, cliente_id, sessao_hash, ip, acao, pedido_id)
             VALUES (?,?,?,?,?,?)"
        )->execute([$token, $clienteId, $sessaoHash, $ip, $acao, $pedidoId]);

        // Atualiza o contador denormalizado de visualizações
        if ($acao === 'visualizou') {
            $this->db->prepare(
                "UPDATE carrinhos_compartilhados
                 SET visualizacoes = visualizacoes + 1
                 WHERE token = ?"
            )->execute([$token]);
        }
    }

    /**
     * Verifica se o token existe e está válido.
     */
    public function tokenValido(string $token): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM carrinhos_compartilhados
             WHERE token = ? AND expira_em > NOW() LIMIT 1"
        );
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Remove carrinhos compartilhados expirados que não geraram uso relevante.
     *
     * Regra:
     * - Apaga somente carrinhos com expira_em < NOW()
     * - Mantém carrinhos que tiveram:
     *   - criou_carrinho
     *   - finalizou_pedido
     *
     * @param int $limit Quantidade máxima de carrinhos removidos por execução.
     * @return int Total de carrinhos removidos.
     */
    public function limparExpiradosSemConversao(int $limit = 500): int
    {
        $limit = max(1, min($limit, 5000));

        $this->db->beginTransaction();

        try {
            // Remove logs irrelevantes dos carrinhos que serão apagados
            $this->db->exec("
                DELETE u
                FROM carrinhos_compartilhados_uso u
                INNER JOIN carrinhos_compartilhados cc ON cc.token = u.token
                WHERE cc.expira_em < NOW()
                AND NOT EXISTS (
                    SELECT 1
                    FROM carrinhos_compartilhados_uso ux
                    WHERE ux.token = cc.token
                        AND ux.acao IN ('criou_carrinho', 'finalizou_pedido')
                    LIMIT 1
                )
            ");

            // Remove os carrinhos expirados sem conversão
            $stmt = $this->db->prepare("
                DELETE FROM carrinhos_compartilhados
                WHERE expira_em < NOW()
                AND NOT EXISTS (
                    SELECT 1
                    FROM carrinhos_compartilhados_uso u
                    WHERE u.token = carrinhos_compartilhados.token
                        AND u.acao IN ('criou_carrinho', 'finalizou_pedido')
                    LIMIT 1
                )
                LIMIT {$limit}
            ");

            $stmt->execute();

            $removidos = $stmt->rowCount();

            $this->db->commit();

            return $removidos;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            // Se você tiver LogService:
            // LogService::error('Erro ao limpar carrinhos compartilhados expirados', [
            //     'erro' => $e->getMessage(),
            // ]);

            return 0;
        }
    }
}