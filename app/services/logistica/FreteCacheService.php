<?php
/**
 * FreteCacheService — cache de frete (durável, em banco).
 *
 * Guarda a cotação BRUTA das transportadoras por chave (CEP+peso+dims) e o
 * CEP->cidade do ViaCEP. O objetivo é NUNCA reconsultar a API dentro do TTL —
 * por isso banco, não cache volátil (que evapora em deploy/pressão de memória
 * justo quando você não quer bater na API).
 *
 * `buscar` devolve só o que está fresco; `buscarQualquer` devolve mesmo expirado
 * (usado como stale-while-error quando a integração cai).
 *
 * chave()/expirado() são PUROS — testáveis sem banco.
 */
class FreteCacheService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /* ---------------- puro ---------------- */

    /** Chave estável a partir dos ingredientes (ordem não importa). */
    public static function chave(array $ingredientes): string
    {
        $norm = self::ordenar($ingredientes);
        return hash('sha256', json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function expirado(?string $expiraEm, ?int $agora = null): bool
    {
        if (!$expiraEm) return true;
        $ts = strtotime($expiraEm);
        return $ts === false ? true : $ts <= ($agora ?? time());
    }

    private static function ordenar($v)
    {
        if (!is_array($v)) return $v;
        if (array_is_list($v)) return array_map([self::class, 'ordenar'], $v);
        ksort($v);
        foreach ($v as $k => $x) $v[$k] = self::ordenar($x);
        return $v;
    }

    /* ---------------- leitura ---------------- */

    public function buscar(string $chave): ?array
    {
        $row = $this->linha($chave);
        if (!$row) return null;
        return self::expirado($row['expira_em']) ? null : $this->decodificar($row);
    }

    /** Ignora a validade (stale) — para servir o último preço quando a API falha. */
    public function buscarQualquer(string $chave): ?array
    {
        $row = $this->linha($chave);
        return $row ? $this->decodificar($row) : null;
    }

    public function cep(string $cep): ?array
    {
        $cep = preg_replace('/\D+/', '', $cep) ?? '';
        if (strlen($cep) !== 8) return null;
        $r = $this->buscar(self::chave(['cep' => $cep, 't' => 'cep']));
        return $r['opcoes'] ?? null;
    }

    /* ---------------- escrita ---------------- */

    public function salvar(string $chave, string $tipo, array $opcoes, int $ttlSegundos, array $meta = []): void
    {
        $ttlSegundos = max(60, $ttlSegundos);
        try {
            $this->pdo->prepare(
                "INSERT INTO log_frete_cache (chave, tipo, cep, produto_id, peso_g, origem, opcoes_json, expira_em)
                 VALUES (:k, :t, :cep, :pid, :peso, :orig, :json, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
                 ON DUPLICATE KEY UPDATE
                   opcoes_json = VALUES(opcoes_json), origem = VALUES(origem),
                   peso_g = VALUES(peso_g), expira_em = VALUES(expira_em)"
            )->execute([
                ':k'    => $chave,
                ':t'    => in_array($tipo, ['cotacao', 'cep'], true) ? $tipo : 'cotacao',
                ':cep'  => $meta['cep'] ?? null,
                ':pid'  => !empty($meta['produto_id']) ? (int)$meta['produto_id'] : null,
                ':peso' => isset($meta['peso_g']) ? (int)$meta['peso_g'] : null,
                ':orig' => substr((string)($meta['origem'] ?? 'transportadora'), 0, 30),
                ':json' => json_encode($opcoes, JSON_UNESCAPED_UNICODE),
                ':ttl'  => $ttlSegundos,
            ]);
        } catch (\Throwable $e) {
            LogService::warning('Falha ao gravar cache de frete', ['erro' => $e->getMessage()]);
        }
    }

    public function salvarCep(string $cep, array $localidade, int $ttlSegundos): void
    {
        $cep = preg_replace('/\D+/', '', $cep) ?? '';
        if (strlen($cep) !== 8) return;
        $this->salvar(self::chave(['cep' => $cep, 't' => 'cep']), 'cep', $localidade, $ttlSegundos, ['cep' => $cep, 'origem' => 'viacep']);
    }

    public function limparExpirados(int $limite = 2000): int
    {
        try {
            $st = $this->pdo->prepare("DELETE FROM log_frete_cache WHERE expira_em <= NOW() LIMIT " . max(1, min(100000, $limite)));
            $st->execute();
            return $st->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Invalida TODO o cache de cotações (mantém o cache de CEP, que não muda com
     * transportadora/regra). Chamar sempre que alterar transportadoras ou regras.
     */
    public function invalidarCotacoes(): int
    {
        try {
            $n = (int)$this->pdo->exec("DELETE FROM log_frete_cache WHERE tipo = 'cotacao'");
            if ($n > 0) LogService::info('Cache de cotações invalidado', ['removidos' => $n]);
            return $n;
        } catch (\Throwable $e) {
            LogService::warning('Falha ao invalidar cache de cotações', ['erro' => $e->getMessage()]);
            return 0;
        }
    }

    /** Atalho estático para invalidar o cache de cotações. */
    public static function invalidar(): int
    {
        return (new self())->invalidarCotacoes();
    }

    /* ---------------- internos ---------------- */

    private function linha(string $chave): ?array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM log_frete_cache WHERE chave = :k LIMIT 1");
            $st->execute([':k' => $chave]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            LogService::warning('Falha ao ler cache de frete', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    private function decodificar(array $row): array
    {
        return [
            'opcoes'    => json_decode((string)$row['opcoes_json'], true) ?: [],
            'origem'    => $row['origem'] ?? 'cache',
            'cep'       => $row['cep'] ?? null,
            'peso_g'    => isset($row['peso_g']) ? (int)$row['peso_g'] : null,
            'expira_em' => $row['expira_em'] ?? null,
        ];
    }
}