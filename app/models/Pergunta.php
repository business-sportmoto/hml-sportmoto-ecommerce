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

    public function salvarRespostaIA(int $id, string $resposta): void {
        $this->db->prepare(
            "UPDATE produto_perguntas
             SET resposta = ?, resposta_fonte = 'ia',
                 respondida_em = NOW(), status = 'respondida'
             WHERE id = ?"
        )->execute([$resposta, $id]);
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
}