<?php
/**
 * app/models/EmailImport.php
 */
// class EmailImport
// {
//     /** @var PDO */
//     private $db;

//     public function __construct()
//     {
//         $this->db = Database::getInstance()->getConnection();
//     }

//     public function criar(array $d)
//     {
//         $sql = "INSERT INTO email_importacoes
//             (nome, arquivo, lista_id, status, criado_por)
//             VALUES (:n, :a, :l, 'pendente', :u)";
//         $st = $this->db->prepare($sql);
//         $st->execute([
//             ':n' => $d['nome'],
//             ':a' => $d['arquivo'] ?? null,
//             ':l' => isset($d['lista_id']) ? (int)$d['lista_id'] : null,
//             ':u' => isset($d['criado_por']) ? (int)$d['criado_por'] : null,
//         ]);
//         return (int)$this->db->lastInsertId();
//     }

//     public function atualizar($id, array $d)
//     {
//         $campos = ['status','total_linhas','total_validos','total_invalidos',
//                    'total_duplicados','erro','iniciado_em','concluido_em'];
//         $set = [];
//         $params = [':id' => (int)$id];
//         foreach ($campos as $c) {
//             if (array_key_exists($c, $d)) {
//                 $set[] = "$c = :$c";
//                 $params[":$c"] = $d[$c];
//             }
//         }
//         if (!$set) return false;
//         $sql = "UPDATE email_importacoes SET " . implode(', ', $set) . " WHERE id = :id";
//         $st = $this->db->prepare($sql);
//         return $st->execute($params);
//     }

//     public function find($id)
//     {
//         $st = $this->db->prepare("SELECT * FROM email_importacoes WHERE id = :id LIMIT 1");
//         $st->execute([':id' => (int)$id]);
//         return $st->fetch(PDO::FETCH_ASSOC) ?: null;
//     }
// }


/**
 * app/models/EmailImport.php
 *
 * Versão v2 — gestão completa de jobs de importação CSV.
 * SUBSTITUI o EmailImport.php existente (mantém retrocompatibilidade).
 */
class EmailImport
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM email_importacoes WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 30): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filtros['status'])) {
            $where[] = 'status = :st';
            $params[':st'] = $filtros['status'];
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stCount = $this->db->prepare("SELECT COUNT(*) FROM email_importacoes $whereSql");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();

        $offset = max(0, ($pagina - 1) * $porPagina);
        $sql = "SELECT i.*, l.nome AS lista_nome
                FROM email_importacoes i
                LEFT JOIN email_listas l ON l.id = i.lista_id
                $whereSql
                ORDER BY i.id DESC
                LIMIT $porPagina OFFSET $offset";
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return [
            'itens' => $st->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
        ];
    }

    /**
     * Cria um novo job de importação no status 'fila'.
     */
    public function criar(array $dados): int
    {
        $sql = "INSERT INTO email_importacoes
                (lista_id, arquivo, arquivo_path, delimiter, encoding, tem_header,
                 mapeamento_json, opcoes_json, total_linhas, status, criado_por, criado_em)
                VALUES (:lista_id, :arquivo, :arquivo_path, :delim, :enc, :hdr,
                        :map, :opc, :total, :status, :criado_por, NOW())";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':lista_id' => $dados['lista_id'] ?? null,
            ':arquivo' => $dados['arquivo'] ?? null,
            ':arquivo_path' => $dados['arquivo_path'] ?? null,
            ':delim' => $dados['delimiter'] ?? ',',
            ':enc' => $dados['encoding'] ?? 'UTF-8',
            ':hdr' => !empty($dados['tem_header']) ? 1 : 0,
            ':map' => isset($dados['mapeamento']) ? json_encode($dados['mapeamento']) : null,
            ':opc' => isset($dados['opcoes']) ? json_encode($dados['opcoes']) : null,
            ':total' => $dados['total_linhas'] ?? 0,
            ':status' => $dados['status'] ?? 'fila',
            ':criado_por' => $dados['criado_por'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $allowed = [
            'lista_id', 'mapeamento_json', 'opcoes_json', 'total_linhas',
            'inseridos', 'atualizados', 'duplicados', 'invalidos', 'suprimidos',
            'linhas_processadas', 'progresso_pct', 'status', 'iniciado_em',
            'concluido_em', 'erros_arquivo_path', 'lock_token', 'locked_at',
        ];
        $sets = [];
        $params = [':id' => $id];
        foreach ($dados as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $sets[] = "$k = :$k";
            $params[":$k"] = is_array($v) ? json_encode($v) : $v;
        }
        if (!$sets) return;
        $sql = "UPDATE email_importacoes SET " . implode(', ', $sets) . " WHERE id = :id";
        $this->db->prepare($sql)->execute($params);
    }

    /**
     * Reserva job em fila para processamento. Atômico via lock_token.
     */
    public function reservarProximo(): ?array
    {
        $token = $this->uuidV4();
        // SELECT primeiro o id, depois UPDATE com lock_token (evita race)
        $st = $this->db->prepare(
            "SELECT id FROM email_importacoes
             WHERE status = 'fila' AND lock_token IS NULL
             ORDER BY id ASC LIMIT 1"
        );
        $st->execute();
        $id = $st->fetchColumn();
        if (!$id) return null;

        $stUp = $this->db->prepare(
            "UPDATE email_importacoes
             SET status = 'processando', lock_token = :tk,
                 locked_at = NOW(), iniciado_em = COALESCE(iniciado_em, NOW())
             WHERE id = :id AND lock_token IS NULL"
        );
        $stUp->execute([':tk' => $token, ':id' => $id]);
        if ($stUp->rowCount() === 0) return null;

        return $this->find((int)$id);
    }

    public function liberarLocksVencidos(int $segundos = 1800): int
    {
        $st = $this->db->prepare(
            "UPDATE email_importacoes
             SET status='fila', lock_token=NULL, locked_at=NULL
             WHERE status='processando'
               AND locked_at IS NOT NULL
               AND locked_at < (NOW() - INTERVAL :s SECOND)"
        );
        $st->execute([':s' => $segundos]);
        return $st->rowCount();
    }

    public function registrarErro(int $importacaoId, int $linha, ?string $email, string $motivo, ?string $detalhe = null, ?array $dados = null): void
    {
        $st = $this->db->prepare(
            "INSERT INTO email_importacao_erros
                (importacao_id, linha, email, motivo, detalhe, dados_json)
             VALUES (:imp, :lin, :em, :mt, :dt, :dj)"
        );
        $st->execute([
            ':imp' => $importacaoId,
            ':lin' => $linha,
            ':em' => $email,
            ':mt' => $motivo,
            ':dt' => $detalhe,
            ':dj' => $dados ? json_encode($dados) : null,
        ]);
    }

    public function erros(int $importacaoId, int $pagina = 1, int $porPagina = 100): array
    {
        $stCount = $this->db->prepare("SELECT COUNT(*) FROM email_importacao_erros WHERE importacao_id = :id");
        $stCount->execute([':id' => $importacaoId]);
        $total = (int)$stCount->fetchColumn();

        $offset = max(0, ($pagina - 1) * $porPagina);
        $st = $this->db->prepare(
            "SELECT * FROM email_importacao_erros
             WHERE importacao_id = :id
             ORDER BY linha ASC
             LIMIT $porPagina OFFSET $offset"
        );
        $st->execute([':id' => $importacaoId]);
        return [
            'itens' => $st->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
        ];
    }

    /** Para o JS de progresso. */
    public function progresso(int $id): ?array
    {
        $st = $this->db->prepare(
            "SELECT id, status, progresso_pct, total_linhas, linhas_processadas,
                    inseridos, atualizados, duplicados, invalidos, suprimidos
             FROM email_importacoes WHERE id = :id LIMIT 1"
        );
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function cancelar(int $id): bool
    {
        $st = $this->db->prepare(
            "UPDATE email_importacoes
             SET status='cancelada', concluido_em=NOW(), lock_token=NULL
             WHERE id = :id AND status IN ('fila','processando','validando')"
        );
        $st->execute([':id' => $id]);
        return $st->rowCount() > 0;
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
