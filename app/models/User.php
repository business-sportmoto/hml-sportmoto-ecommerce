<?php
// app/models/User.php
// Gerencia autenticação, criação e consulta de usuários.

class User extends Model {

    protected string $table = 'usuarios';

    // ── Busca ─────────────────────────────────────────────────

    public function findByEmail(string $email): ?array {
        $stmt = $this->db->prepare(
            "SELECT u.*, 
                    c.usuario_id AS usuario_id,
                    c.id AS cliente_id, c.cpf, c.telefone, c.celular, c.nascimento,
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
    public function authenticate(string $email, string $senha): array
    {
        $user = $this->findByEmail($email);
    
        // Anti-timing: e-mail inexistente ainda paga o custo de um password_verify
        // (contra hash dummy), para o tempo ser indistinguível de senha errada.
        if (!$user) {
            $this->dummyVerify($senha);
            return ['ok' => false, 'reason' => 'invalid'];
        }
    
        // Bloqueio temporário por conta — sem vazar que a conta existe.
        if (!empty($user['bloqueado_ate']) && strtotime((string) $user['bloqueado_ate']) > time()) {
            $this->dummyVerify($senha); // consome tempo equivalente
            $retryMin = (int) ceil((strtotime((string) $user['bloqueado_ate']) - time()) / 60);
            return ['ok' => false, 'reason' => 'locked', 'retry_min' => $retryMin];
        }
    
        // Verifica a senha SEMPRE (custo real do hash) antes de qualquer decisão.
        $passwordOk = password_verify($senha, (string) $user['senha_hash']);
    
        if (!$user['ativo']) {
            return ['ok' => false, 'reason' => 'inactive'];
        }
    
        if (!$passwordOk) {
            $this->registerFailedAttempt((int) $user['id'], (int) $user['tentativas_login']);
            return ['ok' => false, 'reason' => 'invalid'];
        }
    
        // Sucesso — reseta tentativas e último login (UTC para consistência).
        $this->db->prepare(
            "UPDATE usuarios
                SET tentativas_login = 0, bloqueado_ate = NULL, ultimo_login = UTC_TIMESTAMP()
            WHERE id = ?"
        )->execute([$user['id']]);
    
        // Rehash oportunista se algoritmo/custo mudou.
        if (password_needs_rehash((string) $user['senha_hash'], PASSWORD_ALGO)) {
            $this->db->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?")
                    ->execute([password_hash($senha, PASSWORD_ALGO), $user['id']]);
        }
    
        return ['ok' => true, 'user' => $user];
    }

    private function dummyVerify(string $senha): void
    {
        static $dummyHash = null;
        if ($dummyHash === null) {
            // Senha aleatória descartável; o valor nunca corresponderá a login real.
            $dummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_ALGO);
        }
        password_verify($senha, $dummyHash);
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
        $verificado = 1;
        $this->db->prepare(
            "UPDATE usuarios SET senha_hash = ?, senha_definida = ?, email_verificado = ? WHERE id = ?"
        )->execute([password_hash($novaSenha, PASSWORD_ALGO), $verificado, $verificado, $userId]);

        LogService::audit('Recuperação de conta/senha', ['date'=>date('Y-m-d H:i:s', time()), 'usuario_id'=>$userId]);
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