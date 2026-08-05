<?php
/**
 * FreteFallbackService — estimativa de frete quando TODAS as integrações caem.
 *
 * Lê a tabela editável log_frete_fallback (região × faixa de peso) e devolve
 * opções BRUTAS (mesmo formato das transportadoras) rotuladas como estimativa.
 * A régua de especificidade é: linha por UF > linha por região > regra geral.
 *
 * estimarComTabela() é PURO — testável sem banco.
 */
class FreteFallbackService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /* ---------------- puro ---------------- */

    /**
     * Escolhe as linhas que casam e calcula o valor por serviço.
     * @param array  $linhas linhas ativas do fallback (assoc)
     * @param string $uf     UF de destino
     * @param int    $pesoG  peso de cobrança em gramas
     * @return array opções brutas [{transportadora_nome, servico_codigo, servico_nome, prazo_dias, valor, tipo_postagem, estimativa}]
     */
    public static function estimarComTabela(array $linhas, string $uf, int $pesoG): array
    {
        $uf = strtoupper(trim($uf));
        $regiao = self::regiaoDaUf($uf);
        $pesoG = max(0, $pesoG);

        // Para cada serviço, guarda a linha mais específica que casa peso + local.
        $melhorPorServico = [];
        foreach ($linhas as $l) {
            if (isset($l['ativo']) && !$l['ativo']) continue;
            $min = (int)($l['peso_min_g'] ?? 0);
            $max = (int)($l['peso_max_g'] ?? PHP_INT_MAX);
            if ($pesoG < $min || $pesoG > $max) continue;

            $espec = self::especificidade($l, $uf, $regiao);
            if ($espec < 0) continue; // não casa o local

            $sv = (string)($l['servico'] ?? 'PAC');
            if (!isset($melhorPorServico[$sv]) || $espec > $melhorPorServico[$sv]['_espec']) {
                $l['_espec'] = $espec;
                $melhorPorServico[$sv] = $l;
            }
        }

        $out = [];
        foreach ($melhorPorServico as $l) {
            $valor = round((float)($l['valor_base'] ?? 0) + (float)($l['valor_por_kg'] ?? 0) * ($pesoG / 1000), 2);
            $out[] = [
                'transportadora_id'   => 0,
                'transportadora_nome' => 'Estimativa',
                'transportadora_slug' => 'estimativa',
                'servico_codigo'      => (string)($l['servico'] ?? 'PAC'),
                'servico_nome'        => (string)($l['servico_nome'] ?? 'Estimativa'),
                'prazo_dias'          => (int)($l['prazo_dias'] ?? 7),
                'valor'               => max(0, $valor),
                'tipo_postagem'       => 'postagem',
                'estimativa'          => true,
            ];
        }
        usort($out, static fn($a, $b) => ($a['valor'] ?? INF) <=> ($b['valor'] ?? INF));
        return $out;
    }

    /** 2 = casa por UF, 1 = casa por região, 0 = regra geral, -1 = não casa. */
    private static function especificidade(array $l, string $uf, string $regiao): int
    {
        $lUf = strtoupper(trim((string)($l['uf'] ?? '')));
        $lReg = strtoupper(trim((string)($l['regiao'] ?? '')));
        if ($lUf !== '') return $lUf === $uf ? 2 : -1;
        if ($lReg !== '') return $lReg === $regiao ? 1 : -1;
        return 0;
    }

    public static function regiaoDaUf(string $uf): string
    {
        if (class_exists('MotorRegras') && method_exists('MotorRegras', 'regiaoDaUf')) {
            return MotorRegras::regiaoDaUf($uf);
        }
        $mapa = [
            'AC' => 'N', 'AP' => 'N', 'AM' => 'N', 'PA' => 'N', 'RO' => 'N', 'RR' => 'N', 'TO' => 'N',
            'AL' => 'NE', 'BA' => 'NE', 'CE' => 'NE', 'MA' => 'NE', 'PB' => 'NE', 'PE' => 'NE', 'PI' => 'NE', 'RN' => 'NE', 'SE' => 'NE',
            'DF' => 'CO', 'GO' => 'CO', 'MT' => 'CO', 'MS' => 'CO',
            'ES' => 'SE', 'MG' => 'SE', 'RJ' => 'SE', 'SP' => 'SE',
            'PR' => 'S', 'RS' => 'S', 'SC' => 'S',
        ];
        return $mapa[strtoupper($uf)] ?? 'SE';
    }

    /* ---------------- banco ---------------- */

    public function estimar(string $uf, int $pesoG): array
    {
        return self::estimarComTabela($this->linhasAtivas(), $uf, $pesoG);
    }

    public function linhasAtivas(): array
    {
        try {
            return $this->pdo->query("SELECT * FROM log_frete_fallback WHERE ativo = 1 ORDER BY ordem ASC, id ASC")
                             ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao carregar fallback de frete', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /* ---------------- admin CRUD ---------------- */

    public function listar(): array
    {
        try {
            return $this->pdo->query("SELECT * FROM log_frete_fallback ORDER BY ordem ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function salvar(array $d, ?int $usuarioId = null): array
    {
        $campos = [
            'uf'           => ($d['uf'] ?? '') !== '' ? strtoupper(substr((string)$d['uf'], 0, 2)) : null,
            'regiao'       => in_array($d['regiao'] ?? '', ['N', 'NE', 'CO', 'SE', 'S'], true) ? $d['regiao'] : null,
            'peso_min_g'   => max(0, (int)($d['peso_min_g'] ?? 0)),
            'peso_max_g'   => max(1, (int)($d['peso_max_g'] ?? 30000)),
            'servico'      => substr((string)($d['servico'] ?? 'PAC'), 0, 30),
            'servico_nome' => substr((string)($d['servico_nome'] ?? 'Estimativa'), 0, 60),
            'prazo_dias'   => max(0, (int)($d['prazo_dias'] ?? 7)),
            'valor_base'   => round((float)($d['valor_base'] ?? 0), 2),
            'valor_por_kg' => round((float)($d['valor_por_kg'] ?? 0), 2),
            'ativo'        => !empty($d['ativo']) ? 1 : 0,
            'ordem'        => (int)($d['ordem'] ?? 0),
        ];
        try {
            $id = (int)($d['id'] ?? 0);
            if ($id > 0) {
                $sets = implode(', ', array_map(static fn($k) => "`$k` = :$k", array_keys($campos)));
                $campos['id'] = $id;
                $this->pdo->prepare("UPDATE log_frete_fallback SET $sets WHERE id = :id")->execute($this->bind($campos));
            } else {
                $cols = implode(', ', array_keys($campos));
                $ph = implode(', ', array_map(static fn($k) => ":$k", array_keys($campos)));
                $this->pdo->prepare("INSERT INTO log_frete_fallback ($cols) VALUES ($ph)")->execute($this->bind($campos));
                $id = (int)$this->pdo->lastInsertId();
            }
        } catch (\Throwable $e) {
            LogService::error('Falha ao salvar fallback de frete', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao salvar a linha.'];
        }
        LogService::audit('Fallback de frete salvo', ['id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'id' => $id];
    }

    public function remover(int $id): array
    {
        try {
            $this->pdo->prepare("DELETE FROM log_frete_fallback WHERE id = :id")->execute([':id' => $id]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao remover.'];
        }
        return ['ok' => true];
    }

    public function alternar(int $id): array
    {
        try {
            $this->pdo->prepare("UPDATE log_frete_fallback SET ativo = 1 - ativo WHERE id = :id")->execute([':id' => $id]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao alternar.'];
        }
        return ['ok' => true];
    }

    private function bind(array $campos): array
    {
        $out = [];
        foreach ($campos as $k => $v) $out[":$k"] = $v;
        return $out;
    }
}
