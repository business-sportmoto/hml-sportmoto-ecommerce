<?php
declare(strict_types=1);

/**
 * DeviceRecognitionService — heurística leve para reconhecer "este
 * navegador já logou aqui antes", SEM depender do cookie de
 * lembrar-me (que só existe se o usuário marcou o checkbox).
 *
 * Usa o histórico de login_attempts (User-Agent + faixa /24 de IP)
 * já coletado pelo rate limiting. Não é fingerprint de dispositivo de
 * verdade (canvas, fontes, etc.) — é proporcional ao caso de uso:
 * decidir se vale a pena perguntar de novo "quer continuar conectado
 * aqui?" para quem esqueceu de marcar o checkbox no login.
 *
 * IP completo NÃO é usado para comparação — IP dinâmico (4G, trocar de
 * wifi, operadora residencial) muda com frequência no Brasil, geraria
 * falsos negativos. A faixa /24 (primeiros 3 octetos) tolera pequena
 * variação dentro da mesma rede/provedor sem perder o sinal todo.
 */
class DeviceRecognitionService {

    private PDO $db;

    /** Logins de sucesso mínimos (mesmo UA + mesma faixa de IP) para
     *  considerar o dispositivo "reconhecido". 2 evita falso positivo
     *  de uma coincidência isolada, sem ser tão rígido que demore
     *  demais para reconhecer alguém recorrente. */
    private const MIN_LOGINS_RECONHECIDO = 2;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Verifica se o dispositivo atual (User-Agent + faixa de IP) já
     * teve pelo menos MIN_LOGINS_RECONHECIDO logins de sucesso
     * anteriores para este usuário.
     */
    public function isDispositivoReconhecido(int $userId, string $emailHash): bool {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if ($ip === '' || $ua === '') return false;

        $faixaIp = $this->faixaIp($ip);
        if ($faixaIp === null) return false;

        // login_attempts.ip é armazenado em binário (inet_pton) — usa
        // INET6_NTOA para comparar como string e aplicar LIKE na faixa.
        // Mesma tabela/colunas já usadas em RateLimitService e na tela
        // de "últimos acessos" do cliente.
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE email_hash = ?
               AND sucesso = 1
               AND user_agent = ?
               AND INET6_NTOA(ip) LIKE ?
             LIMIT 50"
        );
        $stmt->execute([$emailHash, $ua, $faixaIp . '.%']);

        return (int)$stmt->fetchColumn() >= self::MIN_LOGINS_RECONHECIDO;
    }

    /**
     * Extrai a faixa /24 de um IPv4 (primeiros 3 octetos).
     * Para IPv6 ou formatos inesperados, retorna null — o reconhecimento
     * fica desabilitado para esses casos em vez de tentar uma heurística
     * frágil (IPv6 muda de estrutura, comparar prefixo não é trivial
     * com a mesma simplicidade do IPv4).
     */
    private function faixaIp(string $ip): ?string {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $partes = explode('.', $ip);
        if (count($partes) !== 4) return null;

        return "{$partes[0]}.{$partes[1]}.{$partes[2]}";
    }
}