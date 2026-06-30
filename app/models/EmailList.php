<?php
/**
 * app/models/EmailList.php
 */
// class EmailList
// {
//     /** @var PDO */
//     private $db;

//     public function __construct()
//     {
//         $this->db = Database::getInstance()->getConnection();
//     }

//     public function all($apenasAtivas = true)
//     {
//         $sql = "SELECT * FROM email_listas";
//         if ($apenasAtivas) $sql .= " WHERE ativo = 1";
//         $sql .= " ORDER BY nome ASC";
//         return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
//     }

//     public function find($id)
//     {
//         $st = $this->db->prepare("SELECT * FROM email_listas WHERE id = :id LIMIT 1");
//         $st->execute([':id' => (int)$id]);
//         return $st->fetch(PDO::FETCH_ASSOC) ?: null;
//     }

//     public function save(array $data)
//     {
//         $id = isset($data['id']) ? (int)$data['id'] : 0;
//         if ($id > 0) {
//             $st = $this->db->prepare("UPDATE email_listas
//                 SET nome=:n, descricao=:d, ativo=:a WHERE id=:id");
//             $st->execute([
//                 ':n' => $data['nome'],
//                 ':d' => $data['descricao'] ?? null,
//                 ':a' => !empty($data['ativo']) ? 1 : 0,
//                 ':id' => $id,
//             ]);
//             return $id;
//         }
//         $st = $this->db->prepare("INSERT INTO email_listas (nome, descricao, ativo)
//             VALUES (:n, :d, :a)");
//         $st->execute([
//             ':n' => $data['nome'],
//             ':d' => $data['descricao'] ?? null,
//             ':a' => !empty($data['ativo']) ? 1 : 0,
//         ]);
//         return (int)$this->db->lastInsertId();
//     }

//     public function delete($id)
//     {
//         $st = $this->db->prepare("DELETE FROM email_listas WHERE id = :id");
//         return $st->execute([':id' => (int)$id]);
//     }

//     public function adicionarContato($listaId, $contatoId)
//     {
//         $sql = "INSERT INTO email_lista_contatos (lista_id, contato_id, status)
//                 VALUES (:l, :c, 'ativo')
//                 ON DUPLICATE KEY UPDATE status = 'ativo'";
//         $st = $this->db->prepare($sql);
//         $st->execute([':l' => (int)$listaId, ':c' => (int)$contatoId]);
//         $this->recontar($listaId);
//     }

//     public function removerContato($listaId, $contatoId)
//     {
//         // Não apaga: marca como removido. Mantém histórico.
//         $st = $this->db->prepare("UPDATE email_lista_contatos
//             SET status = 'removido'
//             WHERE lista_id = :l AND contato_id = :c");
//         $st->execute([':l' => (int)$listaId, ':c' => (int)$contatoId]);
//         $this->recontar($listaId);
//     }

//     public function recontar($listaId)
//     {
//         $st = $this->db->prepare("UPDATE email_listas l
//             SET total_contatos = (
//                 SELECT COUNT(*) FROM email_lista_contatos
//                 WHERE lista_id = :l AND status = 'ativo'
//             ) WHERE l.id = :l2");
//         $st->execute([':l' => (int)$listaId, ':l2' => (int)$listaId]);
//     }
// }


// <?php
/**
 * app/models/EmailList.php
 *
 * Versão ATUALIZADA: contém o CRUD original + métodos novos para
 * listagem paginada de contatos da lista, adição em lote e importação CSV.
 *
 * Substitua o arquivo existente por este.
 */
class EmailList
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function all(bool $somenteAtivos = false): array
    {
        $sql = "SELECT * FROM email_listas";
        if ($somenteAtivos) $sql .= " WHERE ativo = 1";
        $sql .= " ORDER BY nome ASC";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM email_listas WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(array $dados): int
    {
        $id = (int)($dados['id'] ?? 0);
        $nome = trim((string)$dados['nome']);
        if ($nome === '') throw new RuntimeException('Nome é obrigatório');

        if ($id > 0) {
            $st = $this->db->prepare(
                "UPDATE email_listas
                 SET nome = :n, descricao = :d, ativo = :a, atualizado_em = NOW()
                 WHERE id = :id"
            );
            $st->execute([
                ':n' => $nome,
                ':d' => $dados['descricao'] ?? null,
                ':a' => !empty($dados['ativo']) ? 1 : 0,
                ':id' => $id,
            ]);
            return $id;
        }

        $st = $this->db->prepare(
            "INSERT INTO email_listas (nome, descricao, ativo, criado_em, atualizado_em)
             VALUES (:n, :d, :a, NOW(), NOW())"
        );
        $st->execute([
            ':n' => $nome,
            ':d' => $dados['descricao'] ?? null,
            ':a' => !empty($dados['ativo']) ? 1 : 0,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->db->prepare("DELETE FROM email_listas WHERE id = :id")
                 ->execute([':id' => $id]);
    }

    /**
     * Adiciona um contato à lista. Idempotente: se já estiver dentro
     * com status ativo, não faz nada.
     */
    public function adicionarContato(int $listaId, int $contatoId): bool
    {
        $st = $this->db->prepare(
            "INSERT INTO email_lista_contatos (lista_id, contato_id, status, criado_em)
             VALUES (:l, :c, 'ativo', NOW())
             ON DUPLICATE KEY UPDATE status = 'ativo', removido_em = NULL"
        );
        $ok = $st->execute([':l' => $listaId, ':c' => $contatoId]);
        if ($ok) $this->recontar($listaId);
        return $ok;
    }

    /**
     * Remove (soft) um contato da lista. Mantém histórico.
     */
    public function removerContato(int $listaId, int $contatoId): bool
    {
        $st = $this->db->prepare(
            "UPDATE email_lista_contatos
             SET status = 'removido', removido_em = NOW()
             WHERE lista_id = :l AND contato_id = :c"
        );
        $ok = $st->execute([':l' => $listaId, ':c' => $contatoId]);
        if ($ok) $this->recontar($listaId);
        return $ok;
    }

    /**
     * Recalcula o contador total_contatos a partir dos pivots ativos.
     */
    public function recontar(int $listaId): void
    {
        $st = $this->db->prepare(
            "UPDATE email_listas l
             SET total_contatos = (
                 SELECT COUNT(*) FROM email_lista_contatos lc
                 WHERE lc.lista_id = l.id AND lc.status = 'ativo'
             )
             WHERE l.id = :l"
        );
        $st->execute([':l' => $listaId]);
    }

    // =========================================================================
    // ===== NOVOS MÉTODOS ABAIXO ==============================================
    // =========================================================================

    /**
     * Lista paginada dos contatos atualmente na lista.
     *
     * @return array{itens: array, total: int, pagina: int, por_pagina: int}
     */
    public function contatosDaLista(int $listaId, array $filtros = [], int $pagina = 1, int $porPagina = 50): array
    {
        $where = ["lc.lista_id = :l", "lc.status = 'ativo'"];
        $params = [':l' => $listaId];

        if (!empty($filtros['busca'])) {
            $where[] = "(c.email LIKE :q OR c.nome LIKE :q)";
            $params[':q'] = '%' . $filtros['busca'] . '%';
        }
        if (!empty($filtros['status_contato'])) {
            $where[] = "c.status = :sc";
            $params[':sc'] = $filtros['status_contato'];
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // total
        $stCount = $this->db->prepare(
            "SELECT COUNT(*) FROM email_lista_contatos lc
             JOIN email_contatos c ON c.id = lc.contato_id
             $whereSql"
        );
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();

        // itens
        $offset = max(0, ($pagina - 1) * $porPagina);
        $sql = "SELECT c.id, c.email, c.nome, c.status AS status_contato,
                       c.origem, c.email_verificado, c.criado_em AS contato_criado_em,
                       lc.criado_em
                FROM email_lista_contatos lc
                JOIN email_contatos c ON c.id = lc.contato_id
                $whereSql
                ORDER BY lc.criado_em DESC
                LIMIT $porPagina OFFSET $offset";
        $st = $this->db->prepare($sql);
        $st->execute($params);
        $itens = $st->fetchAll(PDO::FETCH_ASSOC);

        return [
            'itens' => $itens,
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
        ];
    }

    /**
     * Busca contatos no banco para o autocomplete da modal "Adicionar".
     * Retorna apenas contatos que NÃO estão atualmente ativos na lista.
     */
    public function buscarContatosDisponiveis(int $listaId, string $busca, int $limit = 20): array
    {
        $busca = trim($busca);
        if ($busca === '') {
            return [];
        }

        $limit = max(1, min($limit, 100));

        $sql = "SELECT c.id, c.email, c.nome, c.status, c.origem
                FROM email_contatos c
                WHERE (c.email LIKE :q_email OR c.nome LIKE :q_nome)
                AND c.status = 'ativo'
                AND NOT EXISTS (
                    SELECT 1 
                    FROM email_lista_contatos lc
                    WHERE lc.lista_id = :lista_id
                        AND lc.contato_id = c.id
                        AND lc.status = 'ativo'
                )
                ORDER BY
                    CASE WHEN c.email LIKE :exact_email THEN 0 ELSE 1 END,
                    c.email ASC
                LIMIT {$limit}";

        $st = $this->db->prepare($sql);

        $st->execute([
            ':q_email'     => '%' . $busca . '%',
            ':q_nome'      => '%' . $busca . '%',
            ':exact_email' => $busca . '%',
            ':lista_id'    => $listaId,
        ]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Adiciona múltiplos contatos à lista em uma transação.
     *
     * @return array{adicionados: int, ja_estavam: int, ignorados: int}
     */
    public function adicionarEmLote(int $listaId, array $contatoIds): array
    {
        $contatoIds = array_filter(array_map('intval', $contatoIds));
        if (!$contatoIds) {
            return ['adicionados' => 0, 'ja_estavam' => 0, 'ignorados' => 0];
        }

        $stats = ['adicionados' => 0, 'ja_estavam' => 0, 'ignorados' => 0];

        $this->db->beginTransaction();
        try {
            // Verifica quais já estão ativos
            $in = implode(',', array_fill(0, count($contatoIds), '?'));
            $stCheck = $this->db->prepare(
                "SELECT contato_id FROM email_lista_contatos
                 WHERE lista_id = ? AND status = 'ativo' AND contato_id IN ($in)"
            );
            $stCheck->execute(array_merge([$listaId], $contatoIds));
            $jaAtivos = array_column($stCheck->fetchAll(PDO::FETCH_ASSOC), 'contato_id');
            $stats['ja_estavam'] = count($jaAtivos);

            $paraAdicionar = array_diff($contatoIds, $jaAtivos);

            $stIns = $this->db->prepare(
                "INSERT INTO email_lista_contatos (lista_id, contato_id, status, criado_em)
                 VALUES (:l, :c, 'ativo', NOW())
                 ON DUPLICATE KEY UPDATE status = 'ativo', removido_em = NULL, criado_em = NOW()"
            );
            foreach ($paraAdicionar as $cid) {
                if ($stIns->execute([':l' => $listaId, ':c' => (int)$cid])) {
                    $stats['adicionados']++;
                } else {
                    $stats['ignorados']++;
                }
            }

            $this->recontar($listaId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $stats;
    }

    /**
     * Importa CSV com colunas: email,nome (nome opcional).
     * Cria contatos novos se necessário e os adiciona à lista.
     *
     * @param string $csvPath caminho do arquivo CSV no servidor
     * @return array stats detalhados
     */
    public function importarCsv(int $listaId, string $csvPath): array
    {
        if (!is_file($csvPath)) {
            throw new RuntimeException('Arquivo CSV não encontrado');
        }

        $stats = [
            'total_linhas'     => 0,
            'emails_invalidos' => 0,
            'duplicados'       => 0,
            'contatos_criados' => 0,
            'adicionados_lista' => 0,
            'ja_estavam_lista' => 0,
            'suprimidos'       => 0,
            'erros'            => [],
        ];

        $fh = fopen($csvPath, 'r');
        if (!$fh) throw new RuntimeException('Não foi possível abrir o CSV');

        // Detecta delimitador (vírgula ou ponto-e-vírgula)
        $primeira = fgets($fh);
        rewind($fh);
        $delim = (substr_count($primeira, ';') > substr_count($primeira, ','))
            ? ';' : ',';

        // Pula header se a primeira linha não parece um email
        $header = fgetcsv($fh, 0, $delim);
        if ($header && !filter_var(trim($header[0]), FILTER_VALIDATE_EMAIL)) {
            // header pulado
        } else {
            rewind($fh);
        }

        // Carrega supressoes em memória pra evitar query a cada linha
        $stSup = $this->db->query("SELECT email FROM email_supressoes
                                   WHERE expira_em IS NULL OR expira_em > NOW()");
        $suprimidos = array_flip(
            array_map('strtolower', array_column($stSup->fetchAll(PDO::FETCH_ASSOC), 'email'))
        );

        $contactModel = new EmailContact();
        $vistos = [];

        $this->db->beginTransaction();
        try {
            while (($linha = fgetcsv($fh, 0, $delim)) !== false) {
                $stats['total_linhas']++;
                $email = strtolower(trim($linha[0] ?? ''));
                $nome  = trim($linha[1] ?? '');

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $stats['emails_invalidos']++;
                    continue;
                }
                if (isset($vistos[$email])) {
                    $stats['duplicados']++;
                    continue;
                }
                $vistos[$email] = true;

                if (isset($suprimidos[$email])) {
                    $stats['suprimidos']++;
                    continue;
                }

                // Upsert do contato (origem = importacao)
                $existente = $contactModel->findByEmail($email);
                if ($existente) {
                    $contatoId = (int)$existente['id'];
                } else {
                    $contatoId = $contactModel->upsert([
                        'email'      => $email,
                        'nome'       => $nome ?: null,
                        'origem'     => 'importacao',
                        'base_legal' => 'consentimento',
                        'status'     => 'ativo',
                    ]);
                    $stats['contatos_criados']++;
                }

                if (!$contatoId) {
                    $stats['erros'][] = "linha {$stats['total_linhas']}: falha ao gravar $email";
                    continue;
                }

                // Verifica se já está ativo na lista
                $stCheck = $this->db->prepare(
                    "SELECT 1 FROM email_lista_contatos
                     WHERE lista_id = :l AND contato_id = :c AND status = 'ativo'"
                );
                $stCheck->execute([':l' => $listaId, ':c' => $contatoId]);
                if ($stCheck->fetchColumn()) {
                    $stats['ja_estavam_lista']++;
                    continue;
                }

                $stIns = $this->db->prepare(
                    "INSERT INTO email_lista_contatos (lista_id, contato_id, status, criado_em)
                     VALUES (:l, :c, 'ativo', NOW())
                     ON DUPLICATE KEY UPDATE status = 'ativo', removido_em = NULL, criado_em = NOW()"
                );
                if ($stIns->execute([':l' => $listaId, ':c' => $contatoId])) {
                    $stats['adicionados_lista']++;
                }
            }

            $this->recontar($listaId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            fclose($fh);
            throw $e;
        }

        fclose($fh);
        return $stats;
    }
}
