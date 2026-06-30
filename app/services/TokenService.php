<?php
// app/services/TokenService.php

class TokenService {

    private PDO $db;

    /**
     * Detecta HTTPS de forma proxy-aware.
     * Atrás de Cloudflare/proxy reverso, $_SERVER['HTTPS'] não é setado
     * mesmo com HTTPS na ponta — o cookie sairia sem a flag "secure"
     * e trafegaria em texto puro. Checa também os headers do proxy.
     */
    public static function isHttps(): bool {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        // Cloudflare envia {"scheme":"https"} neste header
        if (str_contains($_SERVER['HTTP_CF_VISITOR'] ?? '', 'https')) {
            return true;
        }
        return false;
    }

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ── Sessão persistente ────────────────────────────────────

    /**
     * Cria cookie e registro na tabela.
     * Agora retorna o token para que o chamador possa registrar o dispositivo.
     */
    // app/services/TokenService.php — método createRememberToken

    /**
     * Cria sessão persistente com rotação de token.
     *
     * Formato do cookie: "{familia}:{token}"
     *  - familia: identificador do dispositivo (32 hex). Persiste entre
     *    rotações e serve de selector indexado para o lookup.
     *  - token:   validador secreto. Só o sha256 vai pro banco, e é
     *    ROTACIONADO a cada uso do cookie.
     */
    public function createRememberToken(int $userId): string {
        $familia   = bin2hex(random_bytes(16));           // 32 chars
        $token     = SecurityHelper::generateToken(32);
        $tokenHash = hash('sha256', $token);
        $expiraEm  = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        $ip        = $_SERVER['REMOTE_ADDR']     ?? null;
        $ua        = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $device    = SessionManager::parseUserAgent($ua ?? '');

        $this->db->prepare(
            "INSERT INTO sessoes_persistentes
            (usuario_id, token, token_familia, ip, user_agent, nome_dispositivo,
            ultima_atividade, expira_em)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)"
        )->execute([
            $userId,
            $token,
            $familia,
            $ip,
            $ua,
            $device,
            $expiraEm,
        ]);

        // Vincula esta sessão PHP à linha persistente, para que a
        // revogação remota (painel de sessões) force logout a cada request.
        Session::set('_sessao_persistente_id', (int)$this->db->lastInsertId());

        $cookieValue = $familia . ':' . $token;
        self::setRememberCookie($cookieValue, time() + SESSION_LIFETIME);
        

        return $cookieValue;
    }

    /**
     * Verifica o cookie persistente e loga o usuário.
     *
     * Implementa o padrão selector:validator com ROTAÇÃO:
     *  - token atual confere      → loga + rotaciona token (cookie novo)
     *  - token ANTERIOR confere   → race de abas paralelas (janela 60s) → loga
     *  - token não confere        → ROUBO DETECTADO → revoga todas as
     *                               sessões do usuário + alerta + não loga
     * Cookies no formato legado (sem ":") são aceitos uma vez e migrados.
     */
    public static function checkRememberCookie(string $cookieToken): void {
        $db    = Database::getInstance()->getConnection();
        $parts = explode(':', $cookieToken, 2);

        if (count($parts) === 2) {
            self::checkRotatedToken($db, $parts[0], $parts[1]);
        } else {
            self::checkLegacyToken($db, $cookieToken);
        }
    }

    // ── Fluxo novo: familia + token rotacionado ──────────────

    private static function checkRotatedToken(PDO $db, string $familia, string $token): void {
        $stmt = $db->prepare(
            "SELECT sp.id, sp.usuario_id, sp.expira_em,
                    sp.token, sp.token_anterior, sp.rotacionado_em,
                    u.nome, u.email, u.ativo, u.deleted_at,
                    c.id AS cliente_id
             FROM sessoes_persistentes sp
             JOIN usuarios u ON u.id  = sp.usuario_id
             JOIN clientes c ON c.usuario_id = u.id
             WHERE sp.token_familia = ?
             LIMIT 1"
        );
        $stmt->execute([$familia]);
        $row = $stmt->fetch();

        // Família desconhecida (revogada ou inválida)
        if (!$row) {
            self::clearRememberCookie();
            return;
        }

        // Sessão expirada
        if (strtotime($row['expira_em']) < time()) {
            $db->prepare("DELETE FROM sessoes_persistentes WHERE token_familia = ?")
               ->execute([$familia]);
            self::clearRememberCookie();
            return;
        }

        // Usuário desativado ou deletado
        if (!$row['ativo'] || $row['deleted_at']) {
            self::clearRememberCookie();
            return;
        }

        $tokenHash = hash('sha256', $token);

        // ── Caso 1: token atual confere → loga e ROTACIONA ──
        if (hash_equals($row['token'], $tokenHash)) {
            self::loginFromCookie($row);

            $novoToken = SecurityHelper::generateToken(32);
            $novaExp   = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

            $db->prepare(
                "UPDATE sessoes_persistentes
                 SET token            = ?,
                     token_anterior   = ?,
                     rotacionado_em   = NOW(),
                     ultima_atividade = NOW(),
                     expira_em        = ?
                 WHERE token_familia = ?"
            )->execute([
                hash('sha256', $novoToken),
                $tokenHash,
                $novaExp,
                $familia,
            ]);

            self::setRememberCookie($familia . ':' . $novoToken, time() + SESSION_LIFETIME);
            return;
        }

        // ── Caso 2: token ANTERIOR dentro da janela de graça ──
        // Abas/requests paralelos: a aba A rotacionou, a aba B ainda
        // mandou o token antigo. Não é roubo — aceita sem rotacionar
        // (o browser já recebeu o cookie novo da aba A).
        $dentroDaJanela = !empty($row['rotacionado_em'])
            && (time() - strtotime($row['rotacionado_em'])) <= 60;

        if ($dentroDaJanela
            && !empty($row['token_anterior'])
            && hash_equals($row['token_anterior'], $tokenHash)) {
            self::loginFromCookie($row);
            return;
        }

        // ── Caso 3: token não confere → ROUBO DETECTADO ──────
        // Alguém apresentou um token desta família que já foi
        // rotacionado há mais de 60s: o cookie foi copiado.
        // Não dá pra saber se quem chegou agora é o ladrão ou o dono,
        // então revoga TUDO e ambos precisam logar com senha de novo.
        self::revokeAllSessionsStatic($db, (int)$row['usuario_id']);
        self::clearRememberCookie();

        error_log(sprintf(
            '[SECURITY] Reuso de remember token detectado — usuário %d (%s). Todas as sessões revogadas.',
            $row['usuario_id'],
            $row['email']
        ));

        try {
            if (class_exists('MailHelper') && method_exists('MailHelper', 'sendSecurityAlert')) {
                MailHelper::sendSecurityAlert($row['email'], $row['nome']);
            }
        } catch (\Throwable) {
            // Alerta é best-effort — nunca quebra o fluxo
        }
    }

    // ── Fluxo legado: cookie antigo só com token ─────────────
    // Aceito uma única vez e migrado para o formato com família.

    private static function checkLegacyToken(PDO $db, string $cookieToken): void {
        $tokenHash = hash('sha256', $cookieToken);

        $stmt = $db->prepare(
            "SELECT sp.id, sp.usuario_id, sp.expira_em,
                    u.nome, u.email, u.ativo, u.deleted_at,
                    c.id AS cliente_id
             FROM sessoes_persistentes sp
             JOIN usuarios u ON u.id  = sp.usuario_id
             JOIN clientes c ON c.usuario_id = u.id
             WHERE sp.token = ?
             LIMIT 1"
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();

        if (!$row) {
            self::clearRememberCookie();
            return;
        }

        if (strtotime($row['expira_em']) < time()) {
            $db->prepare("DELETE FROM sessoes_persistentes WHERE token = ?")
               ->execute([$tokenHash]);
            self::clearRememberCookie();
            return;
        }

        if (!$row['ativo'] || $row['deleted_at']) {
            self::clearRememberCookie();
            return;
        }

        self::loginFromCookie($row);

        // Migra a sessão legada para o formato rotacionado
        $familia   = bin2hex(random_bytes(16));
        $novoToken = SecurityHelper::generateToken(32);
        $novaExp   = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

        $db->prepare(
            "UPDATE sessoes_persistentes
             SET token            = ?,
                 token_familia    = ?,
                 token_anterior   = ?,
                 rotacionado_em   = NOW(),
                 ultima_atividade = NOW(),
                 expira_em        = ?
             WHERE id = ?"
        )->execute([
            hash('sha256', $novoToken),
            $familia,
            $tokenHash,
            $novaExp,
            $row['id'],
        ]);

        self::setRememberCookie($familia . ':' . $novoToken, time() + SESSION_LIFETIME);
    }

    // ── Helpers do fluxo de cookie ───────────────────────────

    private static function loginFromCookie(array $row): void {
        // Anti session-fixation: novo session ID antes de associar identidade
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        Session::loginCliente(
            ['id' => $row['usuario_id'], 'nome' => $row['nome'], 'email' => $row['email']],
            ['id' => $row['cliente_id']]
        );

        // Vincula a sessão PHP à linha persistente (logout remoto)
        Session::set('_sessao_persistente_id', (int)$row['id']);
    }

    /**
     * Validação de sessão ativa — chamar a CADA request de cliente logado
     * (no bootstrap, antes do roteamento, ou em AuthHelper::requireCustomer).
     *
     * Se a linha de sessoes_persistentes que originou este login foi
     * revogada remotamente (painel "encerrar sessão"), desloga na hora.
     *
     * Sessões SEM _sessao_persistente_id (ex: login sem "lembrar" muito
     * antigo) não são afetadas — segurança não regride.
     */
    public static function validateActiveSession(): void {
        if (!Session::isClienteLogado()) return;

        $sessaoId = Session::get('_sessao_persistente_id');
        if (!$sessaoId) return; // sessão não rastreada — nada a validar

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT 1 FROM sessoes_persistentes
             WHERE id = ? AND expira_em > NOW() LIMIT 1"
        );
        $stmt->execute([(int)$sessaoId]);


        

        if (!$stmt->fetchColumn()) {
            
            // Linha revogada ou expirada → encerra a sessão PHP imediatamente
            self::clearRememberCookie();
            Session::logoutCliente();
        }
    }

    private static function setRememberCookie(string $value, int $expires): void {
        setcookie('ec_remember', $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Revoga TODAS as sessões persistentes do usuário.
     * Usar em: troca/reset de senha, roubo detectado, painel "sair de todos".
     */
    public function revokeAllSessions(int $userId): void {
        self::revokeAllSessionsStatic($this->db, $userId);
        self::clearRememberCookie();
    }

    private static function revokeAllSessionsStatic(PDO $db, int $userId): void {
        $db->prepare(
            "DELETE FROM sessoes_persistentes WHERE usuario_id = ?"
        )->execute([$userId]);
    }

    public function deleteRememberToken(int $userId): void {
        // Remove apenas a sessão do dispositivo atual
        if (!empty($_COOKIE['ec_remember'])) {
            $cookie = $_COOKIE['ec_remember'];
            $cookie = urldecode($cookie);
            $parts = explode(':', $cookie, 2);
            
            if (count($parts) === 2) {
                // Formato novo: deleta pela família
                $this->db->prepare(
                    "DELETE FROM sessoes_persistentes WHERE usuario_id = ? AND token_familia = ?"
                )->execute([$userId, $parts[0]]);
            } else {
                // Formato legado
                $this->db->prepare(
                    "DELETE FROM sessoes_persistentes WHERE usuario_id = ? AND token = ?"
                )->execute([$userId, hash('sha256', $_COOKIE['ec_remember'])]);
            }

            
        }
        self::clearRememberCookie();
    }

    public static function clearRememberCookie(): void {
        setcookie('ec_remember', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE['ec_remember']);
    }

    // ── Tokens de verificação (e-mail, SMS, 2FA) ──────────────

    public function createVerificationToken(int $userId, string $tipo = 'email'): string {
        $this->db->prepare(
            "UPDATE tokens_verificacao SET usado = 1
             WHERE usuario_id = ? AND tipo = ? AND usado = 0"
        )->execute([$userId, $tipo]);

        $token    = ($tipo === 'sms') ? SecurityHelper::generateNumericCode(6)
                                      : SecurityHelper::generateToken(32);
        $expiraEm = date('Y-m-d H:i:s', time() + TOKEN_EXPIRY);

        $this->db->prepare(
            "INSERT INTO tokens_verificacao (usuario_id, token, tipo, expira_em)
             VALUES (?, ?, ?, ?)"
        )->execute([$userId, $token, $tipo, $expiraEm]);

        return $token;
    }

    public function consumeVerificationToken(string $token, string $tipo): ?int {
        $stmt = $this->db->prepare(
            "SELECT id, usuario_id, expira_em
             FROM tokens_verificacao
             WHERE token = ? AND tipo = ? AND usado = 0
             LIMIT 1"
        );
        $stmt->execute([$token, $tipo]);
        $row = $stmt->fetch();

        if (!$row || strtotime($row['expira_em']) < time()) return null;

        $this->db->prepare(
            "UPDATE tokens_verificacao SET usado = 1 WHERE id = ?"
        )->execute([$row['id']]);

        return (int) $row['usuario_id'];
    }

    public function createPasswordResetToken(int $userId): string {
        $this->db->prepare(
            "UPDATE recuperacao_senha SET usado = 1 WHERE usuario_id = ? AND usado = 0"
        )->execute([$userId]);

        $token    = SecurityHelper::generateToken(32);
        $expiraEm = date('Y-m-d H:i:s', time() + TOKEN_EXPIRY);
        $ip       = $_SERVER['REMOTE_ADDR'] ?? null;

        $this->db->prepare(
            "INSERT INTO recuperacao_senha (usuario_id, token, ip_solicitante, expira_em)
             VALUES (?, ?, ?, ?)"
        )->execute([$userId, $token, $ip, $expiraEm]);

        return $token;
    }

    public function consumePasswordResetToken(string $token): ?int {
        $stmt = $this->db->prepare(
            "SELECT id, usuario_id, expira_em FROM recuperacao_senha
             WHERE token = ? AND usado = 0 LIMIT 1"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row || strtotime($row['expira_em']) < time()) return null;

        $this->db->prepare(
            "UPDATE recuperacao_senha SET usado = 1 WHERE id = ?"
        )->execute([$row['id']]);

        return (int) $row['usuario_id'];
    }

    public function passwordResetTokenExists(string $token): bool {
        $stmt = $this->db->prepare(
            "SELECT id FROM recuperacao_senha
             WHERE token = ? AND usado = 0 AND expira_em > NOW() LIMIT 1"
        );
        $stmt->execute([$token]);
        return (bool) $stmt->fetchColumn();
    }

    public function create2FAToken(int $userId): string {
        return $this->createVerificationToken($userId, '2fa');
    }

    public function consume2FAToken(string $code, int $userId): bool {
        $stmt = $this->db->prepare(
            "SELECT id FROM tokens_verificacao
             WHERE token = ? AND usuario_id = ? AND tipo = '2fa'
               AND usado = 0 AND expira_em > NOW()
             LIMIT 1"
        );
        $stmt->execute([$code, $userId]);
        $row = $stmt->fetch();

        if (!$row) return false;

        $this->db->prepare(
            "UPDATE tokens_verificacao SET usado = 1 WHERE id = ?"
        )->execute([$row['id']]);

        return true;
    }
}