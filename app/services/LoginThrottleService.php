<?php
declare(strict_types=1);

/**
 * app/services/LoginThrottleService.php
 *
 * Rate limiting MULTICAMADA para autenticação administrativa.
 *
 * POR QUÊ (defense in depth aplicada a brute-force / credential stuffing):
 *  - Camada 1 (por e-mail): protege UMA conta de ataque focado.
 *  - Camada 2 (por IP):     protege contra um atacante testando MUITAS contas
 *                           (o rate-limit só-por-email é bypassável rotacionando
 *                           o e-mail; a dimensão por IP fecha esse vetor —
 *                           e pós-Fase 8 o IP é o real, à prova de spoofing XFF).
 *  - Camada 3 (global):     circuit breaker contra botnet distribuída (muitos
 *                           IPs, poucas tentativas cada — passaria nas camadas
 *                           1 e 2). Se o volume global de falhas explode, exige
 *                           captcha/desafio para todos até normalizar.
 *
 * Backoff PROGRESSIVO: a janela de bloqueio cresce a cada rodada de falhas,
 * tornando brute-force economicamente inviável sem punir o usuário legítimo
 * que erra a senha uma ou duas vezes.
 *
 * Persistência em MySQL 8.4 (InnoDB) com PDO + prepared statements.
 * NÃO usa MariaDB — testado contra MySQL 8.4 LTS.
 *
 * @see OWASP A07:2021 (Identification and Authentication Failures)
 */
final class LoginThrottleService
{
    /** Máx. de falhas por e-mail antes do 1º bloqueio. */
    private const EMAIL_MAX_FAILS = 5;

    /** Máx. de falhas por IP (across accounts) antes do bloqueio. */
    private const IP_MAX_FAILS = 15;

    /** Janela de contagem (segundos) — falhas mais antigas não contam. */
    private const WINDOW_SECONDS = 900; // 15 min

    /** Teto global de falhas na janela antes de exigir desafio a todos. */
    private const GLOBAL_MAX_FAILS = 200;

    /** Base do backoff exponencial (segundos): 60, 120, 240, 480... */
    private const BACKOFF_BASE = 60;

    /** Teto do backoff (segundos) para não bloquear eternamente. */
    private const BACKOFF_CAP = 3600; // 1h

    public function __construct(private PDO $db) {}

    /**
     * Verifica se a tentativa deve ser BLOQUEADA antes de tocar a senha.
     *
     * @return array{blocked:bool, retry_after:int, reason:string}
     *         retry_after em segundos (0 se não bloqueado).
     */
    public function check(string $email, string $ip): array
    {
        $emailKey = $this->emailKey($email);

        // Camada 3 — global (circuit breaker)
        if ($this->countGlobal() >= self::GLOBAL_MAX_FAILS) {
            return ['blocked' => true, 'retry_after' => 60, 'reason' => 'global'];
        }

        // Camada 2 — por IP
        $ipFails = $this->countBy('ip', $ip);
        if ($ipFails >= self::IP_MAX_FAILS) {
            return [
                'blocked'     => true,
                'retry_after' => $this->backoff($ipFails - self::IP_MAX_FAILS + 1),
                'reason'      => 'ip',
            ];
        }

        // Camada 1 — por e-mail
        $emailFails = $this->countBy('email_key', $emailKey);
        if ($emailFails >= self::EMAIL_MAX_FAILS) {
            return [
                'blocked'     => true,
                'retry_after' => $this->backoff($emailFails - self::EMAIL_MAX_FAILS + 1),
                'reason'      => 'email',
            ];
        }

        return ['blocked' => false, 'retry_after' => 0, 'reason' => ''];
    }

    /**
     * Registra uma tentativa FALHA. Chamar SEMPRE que a senha não confere,
     * OU quando o e-mail não existe (para não criar oráculo de enumeração:
     * o custo de registro é o mesmo exista ou não a conta).
     */
    public function registerFailure(string $email, string $ip): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO admin_login_attempts (email_key, ip, created_at)
             VALUES (:email_key, :ip, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            ':email_key' => $this->emailKey($email),
            ':ip'        => $this->normalizeIp($ip),
        ]);
    }

    /**
     * Limpa as falhas de um e-mail+IP após login BEM-SUCEDIDO.
     * Mantém o histórico de outros IPs/contas intacto.
     */
    public function clear(string $email, string $ip): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM admin_login_attempts
             WHERE email_key = :email_key AND ip = :ip'
        );
        $stmt->execute([
            ':email_key' => $this->emailKey($email),
            ':ip'        => $this->normalizeIp($ip),
        ]);
    }

    /**
     * Manutenção: remove tentativas fora da janela. Chamar via cron
     * (ex.: worker do cli/) para a tabela não crescer indefinidamente.
     */
    public function purgeExpired(): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM admin_login_attempts
             WHERE created_at < (UTC_TIMESTAMP() - INTERVAL :win SECOND)'
        );
        $stmt->bindValue(':win', self::WINDOW_SECONDS, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ── internos ──────────────────────────────────────────────────────────

    /** Conta falhas de uma coluna dentro da janela. */
    private function countBy(string $column, string $value): int
    {
        // whitelist rígida da coluna (nunca interpolar entrada do usuário em SQL)
        $allowed = ['ip' => 'ip', 'email_key' => 'email_key'];
        $col = $allowed[$column] ?? null;
        if ($col === null) {
            throw new InvalidArgumentException('Coluna de contagem inválida.');
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM admin_login_attempts
             WHERE {$col} = :val
               AND created_at >= (UTC_TIMESTAMP() - INTERVAL :win SECOND)"
        );
        $stmt->bindValue(':val', $col === 'ip' ? $this->normalizeIp($value) : $value);
        $stmt->bindValue(':win', self::WINDOW_SECONDS, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private function countGlobal(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL :win SECOND)'
        );
        $stmt->bindValue(':win', self::WINDOW_SECONDS, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /** Backoff exponencial com teto. rounds >= 1. */
    private function backoff(int $rounds): int
    {
        $delay = self::BACKOFF_BASE * (2 ** max(0, $rounds - 1));
        return (int) min($delay, self::BACKOFF_CAP);
    }

    /**
     * Hash do e-mail (não guardar e-mail em claro na tabela de tentativas —
     * minimização de dado: a tabela de segurança não precisa do PII legível,
     * só de uma chave estável para contagem). Normaliza case.
     */
    private function emailKey(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /** Normaliza IP (defende contra variações de representação). */
    private function normalizeIp(string $ip): string
    {
        $packed = @inet_pton($ip);
        return $packed !== false ? inet_ntop($packed) : substr($ip, 0, 45);
    }
}