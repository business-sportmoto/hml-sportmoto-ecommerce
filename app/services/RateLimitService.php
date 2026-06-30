<?php
declare(strict_types=1);

/**
 * app/services/RateLimitService.php
 *
 * Rate limiting de autenticação com backend MySQL.
 * Duas dimensões independentes:
 *   - por IP    → bloqueia credential-stuffing e scraping
 *   - por conta → bloqueia brute force numa conta específica
 *
 * A mesma tabela (login_attempts) serve de base para a auditoria
 * de acessos (Sprint 4) — por isso grava e-mail e user-agent.
 */
class RateLimitService
{
    private PDO $db;

    // ── Limites por IP ────────────────────────────────────
    const IP_CAPTCHA_LIMIT   = 10;   // tentativas falhas → exige CAPTCHA
    const IP_BLOCK_LIMIT     = 30;   // tentativas falhas → bloqueia IP
    const IP_WINDOW_SECONDS  = 900;  // janela de 15 min

    // ── Limites por conta (soft-block progressivo) ────────
    const ACCOUNT_WINDOW_SECONDS = 900; // 15 min
    // [tentativas_falhas => segundos_de_bloqueio]
    const ACCOUNT_TIERS = [
        5  => 60,    // 5 erros  → 1 min
        10 => 300,   // 10 erros → 5 min
        15 => 900,   // 15 erros → 15 min
    ];

    // ── Limite de tentativas por código (2FA / e-mail) ────
    const CODE_MAX_ATTEMPTS  = 5;
    const CODE_WINDOW_SECONDS= 600; // 10 min

    // Probabilidade de rodar o cleanup a cada chamada (1 em N)
    const CLEANUP_CHANCE = 50;

    private RecaptchaService $recaptcha;

    public function __construct()
    {
        $this->db        = Database::getInstance()->getConnection();
        $this->recaptcha = new RecaptchaService();
        $this->maybeCleanup();
    }

    // ════════════════════════════════════════════════════
    // VERIFICAÇÃO — chamar ANTES de validar a senha/código
    // ════════════════════════════════════════════════════

    /**
     * Verifica os limites de IP e conta.
     * Retorna:
     *   ['status' => 'ok']
     *   ['status' => 'captcha',  'msg' => ...]   → frontend mostra CAPTCHA
     *   ['status' => 'blocked',  'msg' => ..., 'retry_after' => seg]
     */
    public function check(string $ip, ?string $email = null, ?string $recaptchaToken = null): array
    {
        $ipBin = $this->ipToBinary($ip);

        // ── Camada 1: bloqueio por IP ─────────────────────
        $ipFails = $this->countFails(['ip' => $ipBin], self::IP_WINDOW_SECONDS);

        if ($ipFails >= self::IP_BLOCK_LIMIT) {
            return [
                'status'      => 'blocked',
                'msg'         => 'Muitas tentativas a partir deste local. Tente novamente em alguns minutos.',
                'retry_after' => self::IP_WINDOW_SECONDS,
            ];
        }

        // ── Camada 2: bloqueio progressivo por conta ──────
        if ($email !== null) {
            $emailHash    = $this->hashEmail($email);
            $accountFails = $this->countFails(['email_hash' => $emailHash], self::ACCOUNT_WINDOW_SECONDS);

            $blockSeconds = 0;
            foreach (self::ACCOUNT_TIERS as $threshold => $seconds) {
                if ($accountFails >= $threshold) $blockSeconds = $seconds;
            }

            if ($blockSeconds > 0) {
                // Verifica se a última falha ainda está dentro do bloqueio
                $ultimaFalha = $this->lastFailTime($emailHash);
                if ($ultimaFalha !== null) {
                    $desbloqueioEm = $ultimaFalha + $blockSeconds;
                    $faltam        = $desbloqueioEm - time();
                    if ($faltam > 0) {
                        return [
                            'status'      => 'blocked',
                            'msg'         => 'Conta temporariamente bloqueada por tentativas. Tente em ' . ceil($faltam / 60) . ' minuto(s).',
                            'retry_after' => $faltam,
                        ];
                    }
                }
            }
        }

        // ── Camada 3: CAPTCHA (IP suspeito mas não bloqueado) ──
        if ($ipFails >= self::IP_CAPTCHA_LIMIT) {
            // Se o reCAPTCHA não está configurado no servidor, mantém
            // o comportamento anterior (sinaliza e deixa o front decidir
            // o que fazer) — não quebra ambientes sem chave configurada.
            if (!$this->recaptcha->isConfigured()) {
                return [
                    'status' => 'captcha',
                    'msg'    => 'Confirme que você não é um robô para continuar.',
                ];
            }

            // Sem token ainda: front precisa gerar e reenviar o request
            // com o token do reCAPTCHA v3.
            if ($recaptchaToken === null || $recaptchaToken === '') {
                return [
                    'status'          => 'captcha',
                    'msg'             => 'Confirme que você não é um robô para continuar.',
                    'recaptcha_token' => true, // sinaliza ao front que precisa gerar o token
                ];
            }

            // Token presente: valida contra a API do Google. Score baixo
            // ou token inválido → continua bloqueado pedindo captcha
            // (o usuário pode tentar de novo, o JS gera um token novo a
            // cada submit). Score OK → libera, segue o fluxo normal.
            if (!$this->recaptcha->passou($recaptchaToken, $ip)) {
                return [
                    'status'          => 'captcha',
                    'msg'             => 'Não foi possível confirmar que você não é um robô. Tente novamente.',
                    'recaptcha_token' => true,
                ];
            }
            // passou no captcha — cai para o "ok" abaixo
        }

        return ['status' => 'ok'];
    }

    /**
     * Verifica limite de tentativas de um código (2FA ou login por e-mail).
     * Separado do check() porque a janela e o threshold são diferentes.
     */
    public function checkCode(string $ip, string $email): array
    {
        $emailHash = $this->hashEmail($email);
        $fails = $this->countFails(
            ['email_hash' => $emailHash, 'tipo_in' => ['codigo_email', '2fa']],
            self::CODE_WINDOW_SECONDS
        );

        if ($fails >= self::CODE_MAX_ATTEMPTS) {
            return [
                'status' => 'blocked',
                'msg'    => 'Muitas tentativas de código. Solicite um novo código.',
            ];
        }
        return ['status' => 'ok'];
    }

    // ════════════════════════════════════════════════════
    // REGISTRO — chamar DEPOIS de cada tentativa
    // ════════════════════════════════════════════════════

    public function register(
        string  $ip,
        ?string $email,
        bool    $sucesso,
        string  $tipo = 'senha'
    ): void {
        $this->db->prepare(
            "INSERT INTO login_attempts (ip, email_hash, email_plain, tipo, sucesso, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $this->ipToBinary($ip),
            $email !== null ? $this->hashEmail($email) : null,
            $email,
            $tipo,
            $sucesso ? 1 : 0,
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    }

    /**
     * Limpa as falhas de uma conta após login bem-sucedido.
     * Mantém o registro de SUCESSO (auditoria) e remove só as falhas.
     */
    public function clearAccount(string $email): void
    {
        $this->db->prepare(
            "DELETE FROM login_attempts
             WHERE email_hash = ? AND sucesso = 0"
        )->execute([$this->hashEmail($email)]);
    }

    // ════════════════════════════════════════════════════
    // CONSULTAS INTERNAS
    // ════════════════════════════════════════════════════

    /**
     * Conta tentativas FALHAS dentro de uma janela.
     * $filtros aceita: ip, email_hash, tipo_in (array)
     */
    private function countFails(array $filtros, int $windowSeconds): int
    {
        $where  = ['sucesso = 0', 'criado_em >= (NOW() - INTERVAL ? SECOND)'];
        $params = [$windowSeconds];

        if (isset($filtros['ip'])) {
            $where[]  = 'ip = ?';
            $params[] = $filtros['ip'];
        }
        if (isset($filtros['email_hash'])) {
            $where[]  = 'email_hash = ?';
            $params[] = $filtros['email_hash'];
        }
        if (isset($filtros['tipo_in']) && $filtros['tipo_in']) {
            $place    = implode(',', array_fill(0, count($filtros['tipo_in']), '?'));
            $where[]  = "tipo IN ({$place})";
            $params   = array_merge($params, $filtros['tipo_in']);
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM login_attempts WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function lastFailTime(string $emailHash): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT UNIX_TIMESTAMP(MAX(criado_em))
             FROM login_attempts
             WHERE email_hash = ? AND sucesso = 0"
        );
        $stmt->execute([$emailHash]);
        $ts = $stmt->fetchColumn();
        return $ts !== null && $ts !== false ? (int)$ts : null;
    }

    // ════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════

    private function hashEmail(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    /**
     * Converte IP (v4 ou v6) para binário — cabe em VARBINARY(16).
     * Fallback para 16 bytes zerados se o IP for inválido.
     */
    private function ipToBinary(string $ip): string
    {
        $bin = @inet_pton($ip);
        return $bin !== false ? $bin : str_repeat("\0", 16);
    }

    /**
     * Cleanup probabilístico: ~1 em CLEANUP_CHANCE chamadas,
     * apaga registros com mais de 24h. Evita cron dedicado.
     * (Registros de sucesso mais antigos também saem — a auditoria
     *  de longo prazo do Sprint 4 usará tabela própria se necessário.)
     */
    private function maybeCleanup(): void
    {
        if (random_int(1, self::CLEANUP_CHANCE) !== 1) return;

        try {
            $this->db->query(
                "DELETE FROM login_attempts WHERE criado_em < (NOW() - INTERVAL 24 HOUR)"
            );
        } catch (\Throwable) {
            // Cleanup é best-effort — nunca quebra o fluxo de login
        }
    }
}