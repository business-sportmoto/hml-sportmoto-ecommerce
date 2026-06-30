<?php
/**
 * app/models/EmailContact.php
 */
class EmailContact
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Normaliza email para gravação/lookup */
    public static function normalizeEmail($email)
    {
        return strtolower(trim((string)$email));
    }

    /** Gera SHA2(email,256) já normalizado */
    public static function emailHash($email)
    {
        return hash('sha256', self::normalizeEmail($email));
    }

    public function findByEmail($email)
    {
        $email = self::normalizeEmail($email);
        $st = $this->db->prepare("SELECT * FROM email_contatos WHERE email = :e LIMIT 1");
        $st->execute([':e' => $email]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function find($id)
    {
        $st = $this->db->prepare("SELECT * FROM email_contatos WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByUnsubToken($token)
    {
        $token = preg_replace('/[^a-f0-9]/i', '', (string)$token);
        if ($token === '') return null;
        $st = $this->db->prepare("SELECT * FROM email_contatos WHERE token_descadastro = :t LIMIT 1");
        $st->execute([':t' => $token]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insere ou atualiza um contato.
     * Os campos sensíveis (status) NÃO são sobrescritos se já existirem como descadastrado/bounce/complaint/bloqueado.
     */
    public function upsert(array $data)
    {
        $email = self::normalizeEmail($data['email'] ?? '');
        if ($email === '') return null;

        $hash  = self::emailHash($email);
        $token = isset($data['token_descadastro']) && $data['token_descadastro']
            ? $data['token_descadastro']
            : bin2hex(random_bytes(32));

        $exists = $this->findByEmail($email);

        if ($exists) {
            // Protege status final
            $bloqueados = ['descadastrado','bounce','complaint','bloqueado'];
            $statusFinal = in_array($exists['status'], $bloqueados, true)
                ? $exists['status']
                : ($data['status'] ?? $exists['status']);

            $sql = "UPDATE email_contatos SET
                nome = COALESCE(:nome, nome),
                primeiro_nome = COALESCE(:primeiro_nome, primeiro_nome),
                cliente_id = COALESCE(:cliente_id, cliente_id),
                usuario_id = COALESCE(:usuario_id, usuario_id),
                newsletter_id = COALESCE(:newsletter_id, newsletter_id),
                origem = COALESCE(:origem, origem),
                base_legal = COALESCE(:base_legal, base_legal),
                status = :status,
                email_verificado = COALESCE(:email_verificado, email_verificado),
                genero = COALESCE(:genero, genero),
                nascimento = COALESCE(:nascimento, nascimento),
                telefone = COALESCE(:telefone, telefone),
                idioma = COALESCE(:idioma, idioma),
                pais = COALESCE(:pais, pais)
                WHERE id = :id";
            $st = $this->db->prepare($sql);
            $st->execute([
                ':nome' => $data['nome'] ?? null,
                ':primeiro_nome' => $data['primeiro_nome'] ?? null,
                ':cliente_id' => $data['cliente_id'] ?? null,
                ':usuario_id' => $data['usuario_id'] ?? null,
                ':newsletter_id' => $data['newsletter_id'] ?? null,
                ':origem' => $data['origem'] ?? null,
                ':base_legal' => $data['base_legal'] ?? null,
                ':status' => $statusFinal,
                ':email_verificado' => isset($data['email_verificado']) ? (int)$data['email_verificado'] : null,
                ':genero' => $data['genero'] ?? null,
                ':nascimento' => $data['nascimento'] ?? null,
                ':telefone' => $data['telefone'] ?? null,
                ':idioma' => $data['idioma'] ?? null,
                ':pais' => $data['pais'] ?? null,
                ':id' => (int)$exists['id'],
            ]);
            return (int)$exists['id'];
        }

        $sql = "INSERT INTO email_contatos
            (email,email_hash,nome,primeiro_nome,cliente_id,usuario_id,newsletter_id,
             origem,base_legal,status,email_verificado,genero,nascimento,telefone,
             idioma,pais,token_descadastro)
            VALUES
            (:email,:email_hash,:nome,:primeiro_nome,:cliente_id,:usuario_id,:newsletter_id,
             :origem,:base_legal,:status,:email_verificado,:genero,:nascimento,:telefone,
             :idioma,:pais,:token)";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':email' => $email,
            ':email_hash' => $hash,
            ':nome' => $data['nome'] ?? null,
            ':primeiro_nome' => $data['primeiro_nome'] ?? null,
            ':cliente_id' => $data['cliente_id'] ?? null,
            ':usuario_id' => $data['usuario_id'] ?? null,
            ':newsletter_id' => $data['newsletter_id'] ?? null,
            ':origem' => $data['origem'] ?? 'admin',
            ':base_legal' => $data['base_legal'] ?? 'nao_definida',
            ':status' => $data['status'] ?? 'ativo',
            ':email_verificado' => isset($data['email_verificado']) ? (int)$data['email_verificado'] : 0,
            ':genero' => $data['genero'] ?? 'NaoInformado',
            ':nascimento' => $data['nascimento'] ?? null,
            ':telefone' => $data['telefone'] ?? null,
            ':idioma' => $data['idioma'] ?? 'pt-BR',
            ':pais' => $data['pais'] ?? 'BR',
            ':token' => $token,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function setStatus($contatoId, $status)
    {
        $st = $this->db->prepare("UPDATE email_contatos
            SET status = :s, ultimo_evento_em = NOW()
            WHERE id = :id");
        return $st->execute([':s' => $status, ':id' => (int)$contatoId]);
    }

    public function setStatusByEmail($email, $status)
    {
        $email = self::normalizeEmail($email);
        $st = $this->db->prepare("UPDATE email_contatos
            SET status = :s, ultimo_evento_em = NOW()
            WHERE email = :e");
        return $st->execute([':s' => $status, ':e' => $email]);
    }

    public function contarPorStatus($status = 'ativo')
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM email_contatos WHERE status = :s");
        $st->execute([':s' => $status]);
        return (int)$st->fetchColumn();
    }

    /**
     * Lista paginada com filtros simples.
     */
    public function listar(array $filtros = [], $pagina = 1, $porPagina = 50)
    {
        $where = [];
        $params = [];

        if (!empty($filtros['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $filtros['status'];
        }
        if (!empty($filtros['origem'])) {
            $where[] = 'origem = :origem';
            $params[':origem'] = $filtros['origem'];
        }
        if (!empty($filtros['busca'])) {
            $where[] = '(email LIKE :busca OR nome LIKE :busca)';
            $params[':busca'] = '%' . $filtros['busca'] . '%';
        }

        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // total
        $stc = $this->db->prepare("SELECT COUNT(*) FROM email_contatos $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $pagina = max(1, (int)$pagina);
        $porPagina = max(1, min(500, (int)$porPagina));
        $offset = ($pagina - 1) * $porPagina;

        $sql = "SELECT * FROM email_contatos $sqlWhere
                ORDER BY id DESC LIMIT $porPagina OFFSET $offset";
        $st = $this->db->prepare($sql);
        $st->execute($params);

        return [
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'itens' => $st->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
