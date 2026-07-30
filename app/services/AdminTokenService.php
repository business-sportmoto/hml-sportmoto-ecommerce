<?php
declare(strict_types=1);

/**
 * app/services/AdminTokenService.php
 *
 * "Lembrar-me" para ADMIN — sessão persistente até logout, remoção
 * ou bloqueio. Isolado do TokenService do cliente (zero risco de
 * regressão no login do cliente, que é caminho crítico pré-launch).
 *
 * SEGURANÇA (mesmo modelo do cliente + revalidação por request):
 *  - Token rotaciona a cada uso (família de token detecta roubo)
 *  - Cookie próprio 'adm_remember', path /admin (não vaza pra loja)
 *  - Reuso de token > 60s = roubo → revoga toda a família + expulsa
 *  - Revalidação A CADA REQUEST: usuarios.ativo=1 E admins existe.
 *    Banir (usuarios.ativo=0) ou remover do painel expulsa no
 *    próximo clique — não espera a sessão expirar.
 *
 * Reusa a tabela sessoes_persistentes (já é por usuario_id, genérica).
 * O que distingue admin de cliente é o JOIN na validação: admins,
 * não clientes.
 */
final class AdminTokenService
{
    private const COOKIE      = 'adm_remember';
    private const COOKIE_PATH = '/admin';
    private const DIAS        = 90;
    private const JANELA_ROT  = 60; // s de tolerância p/ abas paralelas

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // ══════════════════════════════════════════════════
    // EMITIR — chamado no login do admin (se "lembrar" marcado)
    // ══════════════════════════════════════════════════

    public function emitir(int $usuarioId): void
    {
        $token   = bin2hex(random_bytes(32));
        $familia = bin2hex(random_bytes(16));
        $expira  = date('Y-m-d H:i:s', time() + self::DIAS * 86400);

        $ua     = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $device = SessionManager::parseUserAgent($ua ?? '');

        $this->db->prepare(
            "INSERT INTO sessoes_persistentes
             (usuario_id, token, token_familia, ip, user_agent, nome_dispositivo,
              ultima_atividade, expira_em)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)"
        )->execute([
            $usuarioId,
            hash('sha256', $token),   // guarda só o HASH do token
            $familia,
            SecurityHelper::clientIp(),
            $ua,
            $device,
            $expira,
        ]);

        $idLinha = (int)$this->db->lastInsertId();
        Session::set('_adm_sessao_persistente_id', $idLinha);

        // Cookie: "familia:token" (o cliente usa o mesmo formato).
        // O token cru só existe aqui e no cookie — nunca no banco.
        $this->setCookie($familia . ':' . $token, $expira);
    }

    // ══════════════════════════════════════════════════
    // VALIDAR — chamado no bootstrap do admin quando NÃO há
    // sessão PHP ativa (tenta ressuscitar pelo cookie)
    // ══════════════════════════════════════════════════

    /**
     * @return array|null dados do admin p/ recriar a sessão, ou null
     */
    public function validarCookie(): ?array
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if ($raw === '') return null;

        // Extrai o token puro após a família (bug histórico do cliente:
        // hashear o cookie inteiro em vez do token — aqui já corrigido)
        $parts     = explode(':', $raw, 2);
        $tokenPuro = (count($parts) >= 2) ? $parts[1] : $raw;
        $tokenHash = hash('sha256', $tokenPuro);

        // Busca por HASH, com JOIN em ADMINS (não clientes).
        // admins ausente / usuarios.ativo=0 → a linha nem retorna,
        // então bloqueio/remoção invalidam o token automaticamente.
        $stmt = $this->db->prepare(
            "SELECT sp.id, sp.usuario_id, sp.token, sp.token_anterior,
                    sp.token_familia, sp.rotacionado_em, sp.expira_em,
                    u.nome, u.email, u.ativo AS usuario_ativo,
                    a.id AS admin_id, a.nivel
             FROM sessoes_persistentes sp
             JOIN usuarios u ON u.id = sp.usuario_id
             JOIN admins   a ON a.usuario_id = u.id
             WHERE sp.token_familia = ?
             LIMIT 1"
        );
        $stmt->execute([$parts[0] ?? '']);
        $row = $stmt->fetch();

        if (!$row) {
            $this->limparCookie();
            return null;
        }

        // Expiração por data
        if (strtotime($row['expira_em']) < time()) {
            $this->revogarFamilia((string)$row['token_familia']);
            $this->limparCookie();
            return null;
        }

        // Banido (usuarios.ativo=0) — a arquitetura de banimento.
        // O JOIN já exigiria admins; esta checagem cobre ativo.
        if ((int)$row['usuario_ativo'] !== 1) {
            $this->revogarFamilia((string)$row['token_familia']);
            $this->limparCookie();
            return null;
        }

        // ── Token bate: rotaciona ──
        if (hash_equals((string)$row['token'], $tokenHash)) {
            return $this->rotacionarERetornar($row);
        }

        // ── Não bate: pode ser aba paralela (janela) ou ROUBO ──
        $dentroDaJanela = !empty($row['rotacionado_em'])
            && (time() - strtotime($row['rotacionado_em'])) <= self::JANELA_ROT;

        if ($dentroDaJanela
            && !empty($row['token_anterior'])
            && hash_equals((string)$row['token_anterior'], $tokenHash)) {
            // Aba B mandou o token antigo logo após a aba A rotacionar.
            // Não é roubo — aceita SEM rotacionar de novo.
            return $this->montarAdmin($row);
        }

        // Token antigo fora da janela = cookie copiado = ROUBO.
        // Revoga TODA a família (o ladrão e o dono caem juntos —
        // o dono reloga, o ladrão perde tudo).
        $this->revogarFamilia((string)$row['token_familia']);
        $this->limparCookie();
        return null;
    }

    /**
     * Revalidação POR REQUEST — chamado em todo request do admin
     * já logado (via sessão PHP). Confirma que ele ainda pode entrar.
     * Bloqueio/remoção expulsa NO PRÓXIMO CLIQUE.
     *
     * @return bool true se ainda válido; false → destruir sessão
     */
    public function revalidarRequest(int $usuarioId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT u.ativo, a.id AS admin_id
             FROM usuarios u
             JOIN admins a ON a.usuario_id = u.id
             WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch();

        // Sem linha (admin removido) OU inativo (banido) → fora
        if (!$row || (int)$row['ativo'] !== 1) {
            return false;
        }
        return true;
    }

    // ══════════════════════════════════════════════════
    // LOGOUT — remove o token desta sessão
    // ══════════════════════════════════════════════════

    public function revogarAtual(): void
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if ($raw !== '') {
            $familia = explode(':', $raw, 2)[0];
            $this->revogarFamilia($familia);
        }
        $this->limparCookie();
    }

    // ══════════════════════════════════════════════════
    // INTERNOS
    // ══════════════════════════════════════════════════

    private function rotacionarERetornar(array $row): array
    {
        $novoToken = bin2hex(random_bytes(32));
        $novaExp   = date('Y-m-d H:i:s', time() + self::DIAS * 86400);

        $this->db->prepare(
            "UPDATE sessoes_persistentes
             SET token_anterior = token,
                 token          = ?,
                 rotacionado_em = NOW(),
                 ultima_atividade = NOW(),
                 expira_em      = ?
             WHERE token_familia = ?"
        )->execute([
            hash('sha256', $novoToken),
            $novaExp,
            (string)$row['token_familia'],
        ]);

        $this->setCookie((string)$row['token_familia'] . ':' . $novoToken, $novaExp);
        Session::set('_adm_sessao_persistente_id', (int)$row['id']);

        return $this->montarAdmin($row);
    }

    private function montarAdmin(array $row): array
    {
        return [
            'user'  => ['id' => (int)$row['usuario_id'],
                        'nome' => $row['nome'], 'email' => $row['email']],
            'admin' => ['id' => (int)$row['admin_id'], 'nivel' => $row['nivel']],
        ];
    }

    private function revogarFamilia(string $familia): void
    {
        $this->db->prepare(
            "DELETE FROM sessoes_persistentes WHERE token_familia = ?"
        )->execute([$familia]);
    }

    private function setCookie(string $valor, string $expira): void
    {
        setcookie(self::COOKIE, $valor, [
            'expires'  => strtotime($expira),
            'path'     => self::COOKIE_PATH,   // restrito ao painel
            'secure'   => SecurityHelper::isHttps(),
            'httponly' => true,                // JS não lê (anti-XSS)
            'samesite' => 'Lax',
        ]);
    }

    private function limparCookie(): void
    {
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => self::COOKIE_PATH,
            'secure'   => SecurityHelper::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE]);
    }
}