<?php
// app/models/SessionManager.php
// Gerencia sessões ativas do cliente: listagem, revogação e detecção de dispositivo.

class SessionManager extends Model {

    protected string $table = 'sessoes_persistentes';

    /**
     * Retorna todas as sessões ativas de um usuário.
     */
    // Substitua o método getActiveSessions para incluir sessões sem cookie

    public function getActiveSessions(int $userId): array {
        $stmt = $this->db->prepare(
            "SELECT id, ip, user_agent, nome_dispositivo,
                    ultima_atividade, criado_em, token, expira_em
            FROM sessoes_persistentes
            WHERE usuario_id = ? AND expira_em > NOW()
            ORDER BY ultima_atividade DESC"
        );
        $stmt->execute([$userId]);
        $sessions = $stmt->fetchAll();

        // Token do cookie "lembrar de mim" — formato "familia:token".
        // A coluna sessoes_persistentes.token guarda só o hash do token
        // puro (sem a família), então é preciso extrair a parte certa
        // antes de hashear/comparar.
        $cookieTokenHash = null;
        if (!empty($_COOKIE['ec_remember'])) {
            $cookie = urldecode($_COOKIE['ec_remember']);
            $parts  = explode(':', $cookie, 2);

            // BUG CORRIGIDO: count($parts) >= 2, não $parts >= 2
            // (comparar array com int nunca avalia como esperado em PHP)
            $tokenPuro       = (count($parts) >= 2) ? $parts[1] : $cookie;
            $cookieTokenHash = $tokenPuro;
        }

        // Token da sessão de auditoria (sem cookie) — salvo na sessão PHP
        $auditToken = Session::get('_audit_session_token');

        foreach ($sessions as &$s) {
            // É a sessão atual se bater com o cookie OU com o token de auditoria
            $isCookieSession = $cookieTokenHash && $s['token'] === $cookieTokenHash;
            $isAuditSession  = $auditToken      && $s['token'] === $auditToken;

            $s['atual']       = $isCookieSession || $isAuditSession;
            $s['tem_cookie']  = (bool)$isCookieSession;
            $s['dispositivo'] = $s['nome_dispositivo']
                            ?: self::parseUserAgent($s['user_agent'] ?? '');
            $s['ultima_fmt']  = self::formatTimeAgo(
                $s['ultima_atividade'] ?? $s['criado_em']
            );
            $s['expira_fmt']  = date('d/m/Y H:i', strtotime($s['expira_em']));
            $s['tipo']        = $isCookieSession ? 'Sessão lembrada' : 'Sessão atual';
        }

        return $sessions;
    }

    /**
     * Revoga uma sessão específica (pelo ID e userId para segurança).
     */
    public function revokeSession(int $sessionId, int $userId): bool {
        return (bool) $this->db->prepare(
            "DELETE FROM sessoes_persistentes WHERE id = ? AND usuario_id = ?"
        )->execute([$sessionId, $userId]);
    }

    /**
     * Revoga todas as sessões exceto a atual.
     */
    public function revokeAllExceptCurrent(int $userId): int {
        // Identifica o token da sessão atual
        $tokenAtual = null;

        if (!empty($_COOKIE['ec_remember'])) {
            // Sessão com cookie — formato "familia:token". Mesmo fix de
            // getActiveSessions(): extrai o token puro antes de hashear,
            // senão nunca bate com o que está salvo no banco.
            $cookie = urldecode($_COOKIE['ec_remember']);
            $parts  = explode(':', $cookie, 2);
            $tokenPuro  = (count($parts) >= 2) ? $parts[1] : $cookie;
            $tokenAtual = $tokenPuro;
        } else {
            // Sessão de auditoria
            $tokenAtual = Session::get('_audit_session_token');
        }

        if ($tokenAtual) {
            // Remove todas EXCETO a sessão atual
            $stmt = $this->db->prepare(
                "DELETE FROM sessoes_persistentes
                WHERE usuario_id = ?
                AND token != ?"
            );
            $stmt->execute([$userId, $tokenAtual]);
        } else {
            // Não conseguiu identificar a sessão atual — não remove nada por segurança
            return 0;
        }

        return (int) $stmt->rowCount();
    }

    /**
     * Atualiza a última atividade de uma sessão.
     * Chamar no bootstrap quando o usuário estiver logado.
     */
    public function touchSession(int $userId): void {
        if (empty($_COOKIE['ec_remember'])) return;

        // Mesmo fix das outras duas funções: cookie é "familia:token",
        // a coluna token guarda só o hash do token puro.
        $cookie = urldecode($_COOKIE['ec_remember']);
        $parts  = explode(':', $cookie, 2);
        $tokenPuro = (count($parts) >= 2) ? $parts[1] : $cookie;
        $token = hash('sha256', $tokenPuro);

        $this->db->prepare(
            "UPDATE sessoes_persistentes
             SET ultima_atividade = NOW()
             WHERE usuario_id = ? AND token = ?"
        )->execute([$userId, $token]);
    }

    /**
     * Registra uma nova sessão com detecção de dispositivo.
     */
    public function createSession(int $userId, string $token): void {
        $ua          = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $dispositivo = self::parseUserAgent($ua);

        $this->db->prepare(
            "UPDATE sessoes_persistentes
             SET nome_dispositivo = ?, ultima_atividade = NOW()
             WHERE usuario_id = ? AND token = ?"
        )->execute([$dispositivo, $userId, hash('sha256', $token)]);
    }

    /**
     * Detecta tipo de dispositivo a partir do User-Agent.
     */
    public static function parseUserAgent(string $ua): string {
        if (empty($ua)) return 'Dispositivo desconhecido';

        $os     = 'Desconhecido';
        $device = 'Desktop';
        $browser = 'Navegador';

        // OS
        if (str_contains($ua, 'Windows'))      $os = 'Windows';
        elseif (str_contains($ua, 'Mac OS'))   $os = 'macOS';
        elseif (str_contains($ua, 'Android'))  { $os = 'Android'; $device = 'Mobile'; }
        elseif (str_contains($ua, 'iPhone'))   { $os = 'iOS';     $device = 'Mobile'; }
        elseif (str_contains($ua, 'iPad'))     { $os = 'iOS';     $device = 'Tablet'; }
        elseif (str_contains($ua, 'Linux'))    $os = 'Linux';

        // Browser
        if (str_contains($ua, 'Chrome') && !str_contains($ua, 'Edg'))  $browser = 'Chrome';
        elseif (str_contains($ua, 'Safari') && !str_contains($ua, 'Chrome')) $browser = 'Safari';
        elseif (str_contains($ua, 'Firefox'))  $browser = 'Firefox';
        elseif (str_contains($ua, 'Edg'))      $browser = 'Edge';
        elseif (str_contains($ua, 'Opera'))    $browser = 'Opera';

        return "{$browser} • {$os} • {$device}";
    }

    private static function formatTimeAgo(string $datetime): string {
        $diff = time() - strtotime($datetime);

        if ($diff < 60)       return 'Agora mesmo';
        if ($diff < 3600)     return (int)($diff/60) . ' min atrás';
        if ($diff < 86400)    return (int)($diff/3600) . 'h atrás';
        if ($diff < 604800)   return (int)($diff/86400) . ' dias atrás';
        return date('d/m/Y', strtotime($datetime));
    }
}