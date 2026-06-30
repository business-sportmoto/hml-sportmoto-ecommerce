<?php
// app/models/User.php
// Gerencia autenticação, criação e consulta de usuários.

class User extends Model {

    protected string $table = 'usuarios';

    // ── Busca ─────────────────────────────────────────────────

    public function findByEmail(string $email): ?array {
        $stmt = $this->db->prepare(
            "SELECT u.*, c.id AS cliente_id, c.cpf, c.telefone, c.celular, c.nascimento,
                    c.genero, c.avatar, c.newsletter,
                    a.id AS admin_id, a.nivel, a.permissoes
             FROM usuarios u
             LEFT JOIN clientes c ON c.usuario_id = u.id
             LEFT JOIN admins a   ON a.usuario_id = u.id
             WHERE u.email = ? AND u.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function findWithProfile(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT u.*, c.id AS cliente_id, c.cpf, c.telefone, c.celular,
                    c.nascimento, c.genero, c.avatar, c.newsletter
             FROM usuarios u
             LEFT JOIN clientes c ON c.usuario_id = u.id
             WHERE u.id = ? AND u.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // ── Criação ───────────────────────────────────────────────

    /**
     * Registra novo cliente: cria usuário + perfil cliente em transação.
     */
    public function registerCliente(array $data): array {
        $this->db->beginTransaction();
        try {
            // Cria o usuário base
            $userId = $this->insert([
                'nome'             => $data['nome'],
                'email'            => $data['email'],
                'senha_hash'       => password_hash($data['senha'], PASSWORD_ALGO),
                'tipo'             => 'cliente',
                'email_verificado' => 0,
                'ativo'            => 1,
            ]);

            // Cria o perfil do cliente
            $stmtC = $this->db->prepare(
                "INSERT INTO clientes (usuario_id, cpf, telefone, celular, nascimento, genero, newsletter)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmtC->execute([
                $userId,
                !empty($data['cpf'])        ? preg_replace('/\D/', '', $data['cpf']) : null,
                !empty($data['telefone'])   ? $data['telefone']   : null,
                !empty($data['celular'])    ? $data['celular']    : null,
                !empty($data['nascimento']) ? $data['nascimento'] : null,
                !empty($data['genero'])     ? $data['genero']     : null,
                isset($data['newsletter'])  ? 1 : 0,
            ]);

            $clienteId = (int) $this->db->lastInsertId();
            $this->db->commit();

            return ['usuario_id' => $userId, 'cliente_id' => $clienteId];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── Verificações ──────────────────────────────────────────

    public function emailExists(string $email, int $ignoreId = 0): bool {
        $stmt = $this->db->prepare(
            "SELECT id FROM usuarios WHERE email = ? AND id != ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$email, $ignoreId]);
        return (bool) $stmt->fetchColumn();
    }

    public function cpfExists(string $cpf, int $ignoreClienteId = 0): bool {
        $cpf  = preg_replace('/\D/', '', $cpf);
        $stmt = $this->db->prepare(
            "SELECT id FROM clientes WHERE cpf = ? AND id != ? LIMIT 1"
        );
        $stmt->execute([$cpf, $ignoreClienteId]);
        return (bool) $stmt->fetchColumn();
    }

    // ── Autenticação ──────────────────────────────────────────

    /**
     * Tenta autenticar por e-mail e senha.
     * Gerencia tentativas de login e bloqueio temporário.
     */
    public function authenticate(string $email, string $senha): array {
        $user = $this->findByEmail($email);

        if (!$user) {
            return ['ok' => false, 'msg' => 'E-mail ou senha incorretos.'];
        }

        // Verifica bloqueio temporário
        if (!empty($user['bloqueado_ate']) && strtotime($user['bloqueado_ate']) > time()) {
            $minutos = ceil((strtotime($user['bloqueado_ate']) - time()) / 60);
            return ['ok' => false, 'msg' => "Conta temporariamente bloqueada. Tente em {$minutos} minuto(s)."];
        }

        // Verifica conta ativa
        if (!$user['ativo']) {
            return ['ok' => false, 'msg' => 'Esta conta está desativada.'];
        }

        // Verifica senha
        if (!password_verify($senha, $user['senha_hash'])) {
            $this->registerFailedAttempt($user['id'], $user['tentativas_login']);
            return ['ok' => false, 'msg' => 'E-mail ou senha incorretos.'];
        }

        // Login bem-sucedido — reseta tentativas
        $this->db->prepare(
            "UPDATE usuarios SET tentativas_login = 0, bloqueado_ate = NULL, ultimo_login = NOW()
             WHERE id = ?"
        )->execute([$user['id']]);

        // Atualiza hash se necessário (password_needs_rehash)
        if (password_needs_rehash($user['senha_hash'], PASSWORD_ALGO)) {
            $novoHash = password_hash($senha, PASSWORD_ALGO);
            $this->db->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?")
                     ->execute([$novoHash, $user['id']]);
        }

        return ['ok' => true, 'user' => $user];
    }

    private function registerFailedAttempt(int $userId, int $attempts): void {
        $attempts++;
        $bloqueadoAte = null;

        if ($attempts >= MAX_LOGIN_TRIES) {
            // Bloqueia por 15 minutos
            $bloqueadoAte = date('Y-m-d H:i:s', time() + 900);
        }

        $this->db->prepare(
            "UPDATE usuarios SET tentativas_login = ?, bloqueado_ate = ? WHERE id = ?"
        )->execute([$attempts, $bloqueadoAte, $userId]);
    }

    // ── Verificação de e-mail ─────────────────────────────────

    public function markEmailVerified(int $userId): void {
        $this->db->prepare(
            "UPDATE usuarios SET email_verificado = 1 WHERE id = ?"
        )->execute([$userId]);
    }

    // ── Senha ─────────────────────────────────────────────────

    public function updatePassword(int $userId, string $novaSenha): void {
        $this->db->prepare(
            "UPDATE usuarios SET senha_hash = ? WHERE id = ?"
        )->execute([password_hash($novaSenha, PASSWORD_ALGO), $userId]);
    }

    public function verifyCurrentPassword(int $userId, string $senha): bool {
        $stmt = $this->db->prepare("SELECT senha_hash FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        return $hash && password_verify($senha, $hash);
    }

    public function getUserComplete(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT u.*, c.id AS cliente_id, u.id AS usuario_id, c.cpf, c.telefone, c.celular,
                    c.nascimento, c.genero, c.avatar, c.newsletter
             FROM usuarios u
             LEFT JOIN clientes c ON c.usuario_id = u.id
             WHERE c.id = ? AND u.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    
    public function getUserParcial(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT u.nome, u.email, u.tipo,c.id AS cliente_id, u.id AS usuario_id, c.cpf, c.telefone, c.celular,
                    c.nascimento, c.genero, c.avatar, c.newsletter
             FROM usuarios u
             LEFT JOIN clientes c ON c.usuario_id = u.id
             WHERE c.id = ? AND u.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getUserParcialByUid(int $uid): ?array {
        $stmt = $this->db->prepare(
            "SELECT u.nome, u.email, u.tipo,c.id AS cliente_id, u.id AS usuario_id, c.cpf, c.telefone, c.celular,
                    c.nascimento, c.genero, c.avatar, c.newsletter
             FROM usuarios u
             LEFT JOIN clientes c ON c.usuario_id = u.id
             WHERE u.id = ? AND u.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$uid]);
        return $stmt->fetch() ?: null;
    }
    
}