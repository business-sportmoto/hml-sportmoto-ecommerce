<?php
declare(strict_types=1);

class AuthLogService {

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