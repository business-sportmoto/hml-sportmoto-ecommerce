<?php
/**
 * FreteSaudeService — circuit breaker das integrações de frete.
 *
 * Se a API falha N vezes seguidas, o circuito "abre" por alguns minutos: nesse
 * intervalo servimos o fallback SEM tentar a API — evitando piorar o bloqueio/
 * limite que a gente quer justamente evitar. Um sucesso zera o contador.
 *
 * proximoEstado() é PURO — testável sem banco.
 */
class FreteSaudeService
{
    private PDO $pdo;

    private const LIMIAR = 3;      // falhas seguidas para abrir o circuito
    private const COOLDOWN = 300;  // segundos com o circuito aberto

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /* ---------------- puro ---------------- */

    /**
     * Calcula o próximo estado do circuito.
     * @param array $estado ['falhas'=>int, 'aberto_ate'=>?int(ts)]
     * @return array ['falhas'=>int, 'aberto_ate'=>?int(ts)]
     */
    public static function proximoEstado(array $estado, bool $sucesso, int $limiar, int $cooldownSeg, int $agora): array
    {
        if ($sucesso) return ['falhas' => 0, 'aberto_ate' => null];
        $falhas = (int)($estado['falhas'] ?? 0) + 1;
        $abertoAte = $estado['aberto_ate'] ?? null;
        if ($falhas >= $limiar) $abertoAte = $agora + $cooldownSeg;
        return ['falhas' => $falhas, 'aberto_ate' => $abertoAte];
    }

    public static function circuitoAberto(?int $abertoAteTs, int $agora): bool
    {
        return $abertoAteTs !== null && $abertoAteTs > $agora;
    }

    /* ---------------- banco ---------------- */

    public function deveTentar(string $origem): bool
    {
        $e = $this->estado($origem);
        return !self::circuitoAberto($e['aberto_ate'], time());
    }

    public function registrarFalha(string $origem): void
    {
        $this->aplicar($origem, false);
    }

    public function registrarSucesso(string $origem): void
    {
        // Só escreve se havia falhas (evita UPDATE desnecessário no caminho feliz).
        $e = $this->estado($origem);
        if (($e['falhas'] ?? 0) > 0 || $e['aberto_ate'] !== null) $this->aplicar($origem, true);
    }

    public function estado(string $origem): array
    {
        try {
            $st = $this->pdo->prepare("SELECT falhas, aberto_ate FROM log_frete_saude WHERE origem = :o LIMIT 1");
            $st->execute([':o' => $origem]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return ['falhas' => 0, 'aberto_ate' => null];
            return ['falhas' => (int)$r['falhas'], 'aberto_ate' => $r['aberto_ate'] ? strtotime($r['aberto_ate']) : null];
        } catch (\Throwable $e) {
            return ['falhas' => 0, 'aberto_ate' => null]; // fail-open
        }
    }

    private function aplicar(string $origem, bool $sucesso): void
    {
        $novo = self::proximoEstado($this->estado($origem), $sucesso, self::LIMIAR, self::COOLDOWN, time());
        try {
            $this->pdo->prepare(
                "INSERT INTO log_frete_saude (origem, falhas, aberto_ate)
                 VALUES (:o, :f, :ate)
                 ON DUPLICATE KEY UPDATE falhas = VALUES(falhas), aberto_ate = VALUES(aberto_ate)"
            )->execute([
                ':o'   => substr($origem, 0, 30),
                ':f'   => $novo['falhas'],
                ':ate' => $novo['aberto_ate'] ? date('Y-m-d H:i:s', $novo['aberto_ate']) : null,
            ]);
            if ($novo['aberto_ate'] && !$sucesso) {
                LogService::warning('Circuito de frete aberto', ['origem' => $origem, 'ate' => date('c', $novo['aberto_ate'])]);
            }
        } catch (\Throwable $e) {
            LogService::warning('Falha ao atualizar saúde de frete', ['erro' => $e->getMessage()]);
        }
    }
}
