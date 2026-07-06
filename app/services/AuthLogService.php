<?php
declare(strict_types=1);

class AuthLogService {

    /**
     * PATCH — app/services/AuthLogService.php
     *
     * Consolida TODO o rate-limiting de autenticacao neste service (decisao de
     * arquitetura: fonte unica de verdade para auth logging + throttle, sem
     * classe separada). Reaproveita a tabela auth_logs existente (DRY).
     *
     * Adiciona:
     *  (1) captura de IP REAL pos-Cloudflare (substitui REMOTE_ADDR cru);
     *  (2) rate-limit por IP para login LOCAL, contando FALHAS na janela;
     *  (3) circuit breaker GLOBAL contra botnet distribuida;
     *  (4) helper unico gate() que o controller chama.
     *
     * Vocabulario de login_status PADRONIZADO: 'fail' / 'success' (ingles).
     * Coluna de data: criado_em. Alvo: MySQL 8.4 LTS (InnoDB).
     *
     * @see OWASP A07:2021 (Authentication Failures), A09 (Logging & Monitoring)
     */

    // ═════════════════════════════════════════════════════════════════════════
    // (1) No metodo registrar() EXISTENTE, troque a captura de IP:
    //        DE:  $_SERVER['REMOTE_ADDR'] ?? null,
    //        PARA: self::clientIp(),
    //     (mantem todo o resto do registrar() como esta)
    // ═════════════════════════════════════════════════════════════════════════

    // ═════════════════════════════════════════════════════════════════════════
    // (2) ADICIONE os metodos abaixo a classe AuthLogService:
    // ═════════════════════════════════════════════════════════════════════════

    /** Constantes de politica (ajuste conforme apetite de risco). */
    private const IP_MAX_FAILS      = 15;    // falhas por IP na janela
    private const GLOBAL_MAX_FAILS  = 200;   // teto global na janela
    private const WINDOW_SECONDS    = 900;   // 15 min
    private const BACKOFF_BASE      = 60;    // s
    private const BACKOFF_CAP       = 3600;  // s (1h)

    /**
     * IP REAL do cliente. Pos-Fase 8 o Nginx reescreve REMOTE_ADDR a partir do
     * CF-Connecting-IP (validado a prova de spoofing XFF). Fallback ao header da
     * CF; NUNCA confiar em X-Forwarded-For (encadeavel/forjavel).
     */
    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '' && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /**
     * Gate de rate-limit chamado pelo controller ANTES de tocar a senha.
     * Combina circuit breaker global + limite por IP com backoff exponencial.
     *
     * @return array{allowed:bool, retry_after:int, reason:string}
     */
    public static function loginGate(): array
    {
        // Camada global — circuit breaker anti-botnet.
        if (self::globalFailureExceeded()) {
            return ['allowed' => false, 'retry_after' => 60, 'reason' => 'global'];
        }

        // Camada por IP.
        $ip    = self::clientIp();
        $fails = self::ipFailureCount($ip);
        if ($fails >= self::IP_MAX_FAILS) {
            $over  = $fails - self::IP_MAX_FAILS + 1;
            $delay = (int) min(self::BACKOFF_BASE * (2 ** ($over - 1)), self::BACKOFF_CAP);
            return ['allowed' => false, 'retry_after' => $delay, 'reason' => 'ip'];
        }

        return ['allowed' => true, 'retry_after' => 0, 'reason' => ''];
    }

    /** Conta FALHAS locais recentes de um IP. */
    private static function ipFailureCount(string $ip): int
    {
        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT COUNT(*) FROM auth_logs
            WHERE ip = ?
                AND provider = 'local'
                AND login_status = 'fail'
                AND criado_em > (UTC_TIMESTAMP() - INTERVAL ? SECOND)"
        );
        $stmt->execute([$ip, self::WINDOW_SECONDS]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Teto GLOBAL de falhas locais na janela — circuit breaker contra botnet
     * distribuida (muitos IPs, poucas tentativas cada, que passariam nas camadas
     * por-conta e por-IP).
     */
    public static function globalFailureExceeded(): bool
    {
        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT COUNT(*) FROM auth_logs
            WHERE provider = 'local'
                AND login_status = 'fail'
                AND criado_em > (UTC_TIMESTAMP() - INTERVAL ? SECOND)"
        );
        $stmt->execute([self::WINDOW_SECONDS]);
        return (int) $stmt->fetchColumn() >= self::GLOBAL_MAX_FAILS;
    }

    public static function registrar(
        ?int $clienteId,
        string $eventType,
        string $loginStatus,
        string $provider = 'local',
        array $metadados = []
    ): void {
        try {
            Database::getInstance()->getConnection()->prepare(
                "INSERT INTO auth_logs
                 (cliente_id, provider, event_type, login_status, ip, user_agent, metadados)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([
                $clienteId,
                $provider,
                $eventType,
                $loginStatus,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                $metadados ? json_encode($metadados, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\Throwable $e) {
            // Não quebrar o fluxo de login por causa de log
            error_log('AuthLog error: ' . $e->getMessage());
        }
    }

    /**
     * Verifica rate limit para tentativas de Google login por IP.
     * 10 tentativas por minuto é generoso pro caso legítimo, mas barra bot.
     */
    public static function rateLimit(string $ip, int $max = 10, int $janelaSegundos = 60): bool {
        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT COUNT(*) FROM auth_logs
             WHERE ip = ? AND provider = 'google'
               AND criado_em > DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->execute([$ip, $janelaSegundos]);
        return (int)$stmt->fetchColumn() < $max;
    }
}