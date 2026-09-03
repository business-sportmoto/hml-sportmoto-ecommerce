<?php
declare(strict_types=1);

// app/models/Pergunta.php

class Pergunta extends Model {

    protected string $table = 'produto_perguntas';

    /**
     * Lista perguntas visíveis e respondidas de um produto.
     * Inclui ranking por util_count para destaque.
     */
    // public function listarPorProduto(int $produtoId, ?string $emailLogado = null): array {
    //     $stmt = $this->db->prepare(
    //         "SELECT id, autor_nome, autor_email, pergunta, resposta,
    //                 resposta_fonte, respondida_em, status, util_count, criado_em,
    //                 cliente_id
    //          FROM produto_perguntas
    //          WHERE produto_id = ?
    //            AND visivel = 1
    //            AND status IN ('respondida', 'aguardando_admin')
    //          ORDER BY util_count DESC, criado_em DESC
    //          LIMIT 50"
    //     );
    //     $stmt->execute([$produtoId]);
    //     $rows = $stmt->fetchAll();

    //     // Marca quais perguntas são do cliente atual (match por email)
    //     foreach ($rows as &$r) {
    //         $r['minha']           = $emailLogado && hash_equals(
    //                                    mb_strtolower($r['autor_email']),
    //                                    mb_strtolower($emailLogado)
    //                                 );
    //         $r['feita_anonima']   = $r['minha'] && empty($r['cliente_id']);
    //         // Nunca expor email/telefone na listagem
    //         unset($r['autor_email']);
    //     }

    //     return $rows;
    // }

    
    public function listarPorProduto(
        int     $produtoId,
        ?string $emailLogado = null,
        int     $page        = 1,
        int     $perPage     = 4
    ): array {
        $offset = ($page - 1) * $perPage;
    
        $stmt = $this->db->prepare(
            "SELECT id, autor_nome, autor_email, pergunta, resposta,
                    resposta_fonte, respondida_em, status,
                    util_count, criado_em, cliente_id
            FROM produto_perguntas
            WHERE produto_id = ?
            AND visivel    = 1
            AND status    IN ('respondida', 'aguardando_admin')
            ORDER BY util_count DESC, criado_em DESC
            LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $produtoId, PDO::PARAM_INT);
        $stmt->bindValue(2, $perPage,   PDO::PARAM_INT);
        $stmt->bindValue(3, $offset,    PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    
        foreach ($rows as &$r) {
            $r['minha']        = $emailLogado && hash_equals(
                                    mb_strtolower($r['autor_email']),
                                    mb_strtolower($emailLogado)
                                );
            $r['feita_anonima'] = $r['minha'] && empty($r['cliente_id']);
            unset($r['autor_email']);
        }
        unset($r);
    
        return $rows;
    }
    
    // ── Adicionar este método novo ──────────────────────────
    public function contarPorProduto(int $produtoId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*)
            FROM produto_perguntas
            WHERE produto_id = ?
            AND visivel    = 1
            AND status    IN ('respondida', 'aguardando_admin')"
        );
        $stmt->execute([$produtoId]);
        return (int) $stmt->fetchColumn();
    }


    /**
     * Conta perguntas feitas no dia por uma chave (cliente|ip|email).
     */
    public function contarPerguntasDia(string $chave, string $tipo): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM pergunta_rate_limit
             WHERE chave = ? AND tipo = ?
               AND criado_em > DATE_SUB(NOW(), INTERVAL 1 DAY)"
        );
        $stmt->execute([$chave, $tipo]);
        return (int)$stmt->fetchColumn();
    }

    public function registrarRateLimit(string $chave, string $tipo): void {
        $this->db->prepare(
            "INSERT INTO pergunta_rate_limit (chave, tipo) VALUES (?, ?)"
        )->execute([$chave, $tipo]);
    }

    /**
     * Cria a pergunta. Status inicial = aguardando_ia.
     */
    public function criar(array $dados): int {
        $hash = hash('sha256',
            $dados['produto_id'] . '|' . mb_strtolower(trim($dados['pergunta']))
        );

        $this->db->prepare(
            "INSERT INTO produto_perguntas
             (produto_id, cliente_id, autor_nome, autor_email, autor_telefone,
              pergunta, pergunta_hash, status, ip_origem, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $dados['produto_id'],
            $dados['cliente_id'] ?? null,
            $dados['autor_nome'],
            mb_strtolower($dados['autor_email']),
            $dados['autor_telefone'] ?? null,
            $dados['pergunta'],
            $hash,
            'aguardando_ia',
            $dados['ip']         ?? null,
            $dados['user_agent'] ?? null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * @param ?int $geracaoId Geração da Central de IA que produziu o texto.
     *                        Sem ele a resposta fica sem procedência — era o
     *                        estado antigo, em que não dava para saber qual
     *                        modelo respondeu. Continua opcional para não
     *                        quebrar chamador que ainda não o passe.
     */
    public function salvarRespostaIA(int $id, string $resposta, ?int $geracaoId = null): void {
        $this->db->prepare(
            "UPDATE produto_perguntas
             SET resposta = ?, resposta_fonte = 'ia', ia_geracao_id = ?,
                 respondida_em = NOW(), status = 'respondida'
             WHERE id = ?"
        )->execute([$resposta, $geracaoId ?: null, $id]);
    }

    public function marcarParaAdmin(int $id): void {
        $this->db->prepare(
            "UPDATE produto_perguntas SET status = 'aguardando_admin' WHERE id = ?"
        )->execute([$id]);
    }

    public function salvarRespostaAdmin(int $id, string $resposta, int $adminId): array {
        $this->db->prepare(
            "UPDATE produto_perguntas
             SET resposta = ?, resposta_fonte = 'admin',
                 respondida_em = NOW(), respondida_por = ?, status = 'respondida'
             WHERE id = ?"
        )->execute([$resposta, $adminId, $id]);

        return $this->buscarComProduto($id);
    }

    public function buscarComProduto(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT pp.*, 
                p.nome AS produto_nome, 
                p.slug AS produto_slug,
                p.id AS produto_id,
                pp.autor_telefone AS autor_telefone    
             FROM produto_perguntas pp
             JOIN produtos p ON p.id = pp.produto_id
             WHERE pp.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function jaVotouUtil(int $perguntaId, ?int $clienteId, string $sessao): bool {
        if ($clienteId) {
            $stmt = $this->db->prepare(
                "SELECT id FROM pergunta_util_votos
                 WHERE pergunta_id = ? AND cliente_id = ? LIMIT 1"
            );
            $stmt->execute([$perguntaId, $clienteId]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT id FROM pergunta_util_votos
                 WHERE pergunta_id = ? AND session_key = ? LIMIT 1"
            );
            $stmt->execute([$perguntaId, $sessao]);
        }
        return (bool)$stmt->fetchColumn();
    }

    public function toggleUtil(int $perguntaId, ?int $clienteId, string $sessao, string $ip): array {
        $jaVotou = $this->jaVotouUtil($perguntaId, $clienteId, $sessao);

        if ($jaVotou) {
            if ($clienteId) {
                $this->db->prepare(
                    "DELETE FROM pergunta_util_votos
                     WHERE pergunta_id = ? AND cliente_id = ?"
                )->execute([$perguntaId, $clienteId]);
            } else {
                $this->db->prepare(
                    "DELETE FROM pergunta_util_votos
                     WHERE pergunta_id = ? AND session_key = ?"
                )->execute([$perguntaId, $sessao]);
            }
            $this->db->prepare(
                "UPDATE produto_perguntas
                 SET util_count = GREATEST(util_count - 1, 0) WHERE id = ?"
            )->execute([$perguntaId]);
        } else {
            $this->db->prepare(
                "INSERT IGNORE INTO pergunta_util_votos
                 (pergunta_id, cliente_id, session_key, ip) VALUES (?,?,?,?)"
            )->execute([$perguntaId, $clienteId ?: null, $sessao, $ip]);

            $this->db->prepare(
                "UPDATE produto_perguntas SET util_count = util_count + 1 WHERE id = ?"
            )->execute([$perguntaId]);
        }

        $stmt = $this->db->prepare(
            "SELECT util_count FROM produto_perguntas WHERE id = ?"
        );
        $stmt->execute([$perguntaId]);

        return [
            'votou'      => !$jaVotou,
            'util_count' => (int)$stmt->fetchColumn(),
        ];
    }

    /**
     * Votos "útil" em lote — mesma razão de Review::votosEmLote():
     * PerguntaController::listar() chama jaVotouUtil() dentro do laço.
     *
     * @param int[] $ids
     * @return array<int,bool>  [pergunta_id => votou]
     */
    public function votosEmLote(array $ids, ?int $clienteId, string $sessao): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) return [];
        if (!$clienteId && $sessao === '') return [];

        $in = implode(',', array_fill(0, count($ids), '?'));

        if ($clienteId) {
            $sql    = "SELECT pergunta_id FROM pergunta_util_votos
                       WHERE pergunta_id IN ({$in}) AND cliente_id = ?";
            $params = array_merge($ids, [$clienteId]);
        } else {
            $sql    = "SELECT pergunta_id FROM pergunta_util_votos
                       WHERE pergunta_id IN ({$in}) AND session_key = ?";
            $params = array_merge($ids, [$sessao]);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $id) {
            $out[(int)$id] = true;
        }
        return $out;
    }

    /**
     * Pergunta idêntica já feita neste produto (mesmo hash de criar()).
     *
     * A coluna pergunta_hash existe desde sempre e nunca foi consultada: dois
     * clientes perguntando a mesma coisa geravam duas linhas e duas chamadas
     * à IA. No app, onde repetir a pergunta é um toque, isso pesa mais.
     */
    public function jaRespondida(int $produtoId, string $pergunta): ?array {
        $hash = hash('sha256', $produtoId . '|' . mb_strtolower(trim($pergunta)));

        $stmt = $this->db->prepare(
            "SELECT id, pergunta, resposta, resposta_fonte, respondida_em,
                    status, util_count, criado_em, cliente_id, autor_nome
             FROM produto_perguntas
             WHERE produto_id = ? AND pergunta_hash = ?
               AND status = 'respondida' AND visivel = 1
             ORDER BY respondida_em DESC
             LIMIT 1"
        );
        $stmt->execute([$produtoId, $hash]);
        return $stmt->fetch() ?: null;
    }
}
