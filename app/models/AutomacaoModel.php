<?php
/**
 * app/models/AutomacaoModel.php
 *
 * CRUD sobre as tabelas automacao_fluxos, automacao_passos,
 * automacao_fila e automacao_historico.
 */
class AutomacaoModel
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // =========================================================================
    // FLUXOS
    // =========================================================================

    public function todosFluxos(): array
    {
        return $this->db->query(
            "SELECT f.*, COUNT(p.id) AS total_passos
             FROM automacao_fluxos f
             LEFT JOIN automacao_passos p ON p.fluxo_id = f.id
             GROUP BY f.id ORDER BY f.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findFluxo(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM automacao_fluxos WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function findFluxoPorTipo(string $tipo): ?array
    {
        $st = $this->db->prepare("SELECT * FROM automacao_fluxos WHERE tipo = :t LIMIT 1");
        $st->execute([':t' => $tipo]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function atualizarFluxo(int $id, array $dados): void
    {
        $allowed = ['nome', 'ativo', 'config_json'];
        $sets = []; $params = [':id' => $id];
        foreach ($dados as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $sets[] = "$k = :$k";
            $params[":$k"] = is_array($v) ? json_encode($v) : $v;
        }
        if (!$sets) return;
        $this->db->prepare("UPDATE automacao_fluxos SET " . implode(', ', $sets) . " WHERE id = :id")
                 ->execute($params);
    }

    public function passos(int $fluxoId): array
    {
        $st = $this->db->prepare(
            "SELECT p.*, t.nome AS template_nome
             FROM automacao_passos p
             LEFT JOIN email_templates t ON t.id = p.template_id
             WHERE p.fluxo_id = :f ORDER BY p.ordem ASC"
        );
        $st->execute([':f' => $fluxoId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findPasso(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM automacao_passos WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function atualizarPasso(int $id, array $dados): void
    {
        $allowed = ['nome', 'delay_horas', 'template_id'];
        $sets = []; $params = [':id' => $id];
        foreach ($dados as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;

            // ── ADICIONE ESTE BLOCO ──
            if ($k === 'delay_horas') {
                $v = (int)$v;  // vazio/null vira 0 (válido para passos PIX)
            }
            if ($k === 'template_id') {
                $v = $v !== '' && $v !== null ? (int)$v : null; // template pode ser null
            }
            // ─────────────────────────

            $sets[] = "$k = :$k";
            $params[":$k"] = $v;
        }
        if (!$sets) return;
        $this->db->prepare("UPDATE automacao_passos SET " . implode(', ', $sets) . " WHERE id = :id")
                ->execute($params);
    }

    // =========================================================================
    // FILA
    // =========================================================================

    /**
     * Enfileira um passo para um cliente.
     * Ignora silenciosamente se a chave de dedup já existir.
     */
    public function enfileirar(array $dados): ?int
    {
        $dedup = $dados['chave_dedup'] ?? null;

        // Verifica dedup
        if ($dedup) {
            $st = $this->db->prepare(
                "SELECT id FROM automacao_fila
                 WHERE chave_dedup = :d AND status IN ('pendente','enviado')
                 LIMIT 1"
            );
            $st->execute([':d' => $dedup]);
            if ($st->fetchColumn()) return null; // já existe
        }

        $sql = "INSERT IGNORE INTO automacao_fila
                (fluxo_id, passo_id, cliente_id, contexto_json,
                 disparo_em, status, cupom_id, chave_dedup)
                VALUES (:f, :p, :c, :ctx, :disp, 'pendente', :cup, :ded)";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':f'   => $dados['fluxo_id'],
            ':p'   => $dados['passo_id'],
            ':c'   => $dados['cliente_id'],
            ':ctx' => isset($dados['contexto']) ? json_encode($dados['contexto']) : null,
            ':disp'=> $dados['disparo_em'],
            ':cup' => $dados['cupom_id'] ?? null,
            ':ded' => $dedup,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Busca lote de itens prontos para disparo.
     */
    public function buscarProntos(int $limite = 50): array
    {
        $st = $this->db->prepare(
            "SELECT f.*, fl.tipo AS fluxo_tipo, fl.config_json AS fluxo_config,
                    p.ordem AS passo_ordem, p.template_id, p.nome AS passo_nome,
                    p.delay_horas
             FROM automacao_fila f
             JOIN automacao_fluxos fl ON fl.id = f.fluxo_id
             JOIN automacao_passos p  ON p.id  = f.passo_id
             WHERE f.status = 'pendente'
               AND f.disparo_em <= NOW()
               AND fl.ativo = 1
               AND f.tentativas < 3
             ORDER BY f.disparo_em ASC
             LIMIT :lim"
        );
        $st->bindValue(':lim', $limite, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function marcarEnviado(int $id): void
    {
        $this->db->prepare(
            "UPDATE automacao_fila
             SET status='enviado', processado_em=NOW()
             WHERE id = :id"
        )->execute([':id' => $id]);
    }

    public function marcarErro(int $id, string $detalhe = ''): void
    {
        $this->db->prepare(
            "UPDATE automacao_fila
             SET status='erro', tentativas=tentativas+1, processado_em=NOW()
             WHERE id = :id"
        )->execute([':id' => $id]);

        LogService::error("ERRO no processar(): " . $detalhe);
    }

    public function cancelarPorCliente(int $clienteId, string $tipo): int
    {
        $st = $this->db->prepare(
            "UPDATE automacao_fila f
             JOIN automacao_fluxos fl ON fl.id = f.fluxo_id
             SET f.status = 'cancelado'
             WHERE f.cliente_id = :c
               AND fl.tipo = :t
               AND f.status = 'pendente'"
        );
        $st->execute([':c' => $clienteId, ':t' => $tipo]);
        return $st->rowCount();
    }

    /**
     * Cancela todos os fluxos pendentes de um cliente que comprou.
     * Mantém aniversário, boas-vindas e pós-compra.
     */
    public function cancelarPorCompra(int $clienteId): int
    {
        $st = $this->db->prepare(
            "UPDATE automacao_fila f
             JOIN automacao_fluxos fl ON fl.id = f.fluxo_id
             SET f.status = 'cancelado'
             WHERE f.cliente_id = :c
               AND fl.tipo IN (
                   'carrinho_abandonado','produto_visitado',
                   'categoria_visitada','wishlist','reengajamento'
               )
               AND f.status = 'pendente'"
        );
        $st->execute([':c' => $clienteId]);
        return $st->rowCount();
    }

    // =========================================================================
    // HISTÓRICO
    // =========================================================================

    public function registrarHistorico(array $dados): void
    {
        $this->db->prepare(
            "INSERT INTO automacao_historico
             (fila_id, cliente_id, fluxo_id, passo_id, cupom_id,
              cupom_codigo, resultado, detalhe)
             VALUES (:fi, :c, :fl, :p, :cup, :cod, :res, :det)"
        )->execute([
            ':fi'  => $dados['fila_id'],
            ':c'   => $dados['cliente_id'],
            ':fl'  => $dados['fluxo_id'],
            ':p'   => $dados['passo_id'],
            ':cup' => $dados['cupom_id'] ?? null,
            ':cod' => $dados['cupom_codigo'] ?? null,
            ':res' => $dados['resultado'],
            ':det' => $dados['detalhe'] ?? null,
        ]);
    }

    public function historicoCliente(int $clienteId, int $limit = 50): array
    {
        $st = $this->db->prepare(
            "SELECT h.*, fl.nome AS fluxo_nome, p.nome AS passo_nome
             FROM automacao_historico h
             JOIN automacao_fluxos fl ON fl.id = h.fluxo_id
             JOIN automacao_passos p  ON p.id  = h.passo_id
             WHERE h.cliente_id = :c
             ORDER BY h.criado_em DESC
             LIMIT $limit"
        );
        $st->execute([':c' => $clienteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // RELATÓRIO / DASHBOARD
    // =========================================================================

    public function kpisPorFluxo(): array
    {
        return $this->db->query(
            "SELECT fl.tipo, fl.nome, fl.ativo,
                    COUNT(CASE WHEN f.status='pendente' THEN 1 END) AS pendentes,
                    COUNT(CASE WHEN f.status='enviado'  THEN 1 END) AS enviados,
                    COUNT(CASE WHEN f.status='cancelado' THEN 1 END) AS cancelados,
                    COUNT(CASE WHEN f.status='erro' THEN 1 END) AS erros
             FROM automacao_fluxos fl
             LEFT JOIN automacao_fila f ON f.fluxo_id = fl.id
               AND f.criado_em > DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY fl.id
             ORDER BY fl.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
