<?php
/**
 * RegrasAdminService — gestão das regras de frete.
 *
 * Regra = log_regras (ações em JSON) + N log_regra_condicoes. Salvamento
 * substitui o conjunto de condições em transação. Toda mutação registra em
 * log_regra_historico + LogService::audit. validar() é pura (testável).
 */
class RegrasAdminService
{
    private PDO $pdo;

    private const CAMPOS = ['nome', 'descricao', 'prioridade', 'ativa', 'acumulativa', 'acoes', 'inicio_em', 'fim_em'];

    public const CAMPOS_CONDICAO = [
        'valor', 'peso', 'quantidade', 'uf', 'cidade', 'regiao', 'cep_faixa', 'pais',
        'categoria', 'marca', 'produto', 'canal', 'tipo_cliente', 'dia_semana', 'hora',
        'transportadora', 'modalidade',
    ];
    public const OPERADORES = ['=', '!=', '>', '<', '>=', '<=', 'in', 'not_in', 'between', 'contem'];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /* ------------------------------------------------- leitura */

    public function listar(array $filtros = []): array
    {
        $where = []; $p = [];
        if (isset($filtros['ativa']) && $filtros['ativa'] !== '') { $where[] = 'r.ativa = :a'; $p[':a'] = (int)$filtros['ativa']; }
        if (!empty($filtros['busca'])) { $where[] = 'r.nome LIKE :q'; $p[':q'] = '%' . $filtros['busca'] . '%'; }
        $sql = "SELECT r.*, (SELECT COUNT(*) FROM log_regra_condicoes c WHERE c.regra_id = r.id) AS condicoes_qtd FROM log_regras r";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY r.prioridade ASC, r.id ASC';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($p);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar regras', ['erro' => $e->getMessage()]);
            return [];
        }
        foreach ($rows as &$r) {
            $r['acoes'] = json_decode((string)$r['acoes'], true) ?: [];
            $r['resumo_acoes'] = self::resumirAcoes($r['acoes']);
        }
        return $rows;
    }

    public function obter(int $id): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM log_regras WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $cs = $this->pdo->prepare("SELECT id, campo, operador, valor FROM log_regra_condicoes WHERE regra_id = :id ORDER BY id ASC");
            $cs->execute([':id' => $id]);
            $conds = $cs->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($conds as &$c) $c['valor'] = json_decode((string)$c['valor'], true);
            $r['acoes'] = json_decode((string)$r['acoes'], true) ?: [];
            $r['condicoes'] = $conds;
            return $r;
        } catch (\Throwable $e) {
            LogService::error('Falha ao obter regra', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /* ------------------------------------------------- escrita */

    public function salvar(array $d, ?int $usuarioId = null): array
    {
        $id = (int)($d['id'] ?? 0);
        $isUpdate = $id > 0;

        $erros = self::validar($d);
        if ($erros) return ['ok' => false, 'erros' => $erros];

        $campos = [
            'nome'        => trim((string)$d['nome']),
            'descricao'   => ($d['descricao'] ?? '') === '' ? null : mb_substr((string)$d['descricao'], 0, 500),
            'prioridade'  => (int)($d['prioridade'] ?? 100),
            'ativa'       => !empty($d['ativa']) ? 1 : 0,
            'acumulativa' => !empty($d['acumulativa']) ? 1 : 0,
            'acoes'       => json_encode(self::sanitizarAcoes($d['acoes'] ?? []), JSON_UNESCAPED_UNICODE),
            'inicio_em'   => self::dataOuNull($d['inicio_em'] ?? null),
            'fim_em'      => self::dataOuNull($d['fim_em'] ?? null),
        ];

        try {
            $this->pdo->beginTransaction();
            if ($isUpdate) {
                $sets = implode(', ', array_map(static fn($c) => "`$c` = :$c", self::CAMPOS));
                $stmt = $this->pdo->prepare("UPDATE log_regras SET $sets WHERE id = :id");
                $campos['id'] = $id;
                $stmt->execute($campos);
            } else {
                $cols = self::CAMPOS;
                $stmt = $this->pdo->prepare("INSERT INTO log_regras (`" . implode('`,`', $cols) . "`) VALUES (:" . implode(',:', $cols) . ")");
                $stmt->execute($campos);
                $id = (int)$this->pdo->lastInsertId();
            }

            $this->sincronizarCondicoes($id, is_array($d['condicoes'] ?? null) ? $d['condicoes'] : []);
            $this->registrarHistorico($id, $isUpdate ? 'editada' : 'criada', $usuarioId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            LogService::error('Falha ao salvar regra', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao salvar a regra.'];
        }

        LogService::audit($isUpdate ? 'Regra de frete atualizada' : 'Regra de frete criada', ['regra_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'id' => $id];
    }

    public function alternar(int $id, bool $ativa, ?int $usuarioId = null): array
    {
        try {
            $this->pdo->prepare("UPDATE log_regras SET ativa = :a WHERE id = :id")->execute([':a' => $ativa ? 1 : 0, ':id' => $id]);
            $this->registrarHistorico($id, $ativa ? 'ativada' : 'desativada', $usuarioId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Não foi possível alterar.'];
        }
        LogService::audit('Regra de frete ' . ($ativa ? 'ativada' : 'desativada'), ['regra_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }

    public function reordenar(array $ids, ?int $usuarioId = null): array
    {
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("UPDATE log_regras SET prioridade = :p WHERE id = :id");
            $p = 10;
            foreach ($ids as $rid) { $stmt->execute([':p' => $p, ':id' => (int)$rid]); $p += 10; }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['ok' => false, 'erro' => 'Não foi possível reordenar.'];
        }
        LogService::audit('Regras de frete reordenadas', ['ordem' => $ids, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }

    public function remover(int $id, ?int $usuarioId = null): array
    {
        try {
            $this->pdo->beginTransaction();
            $this->registrarHistorico($id, 'removida', $usuarioId);
            $this->pdo->prepare("DELETE FROM log_regra_condicoes WHERE regra_id = :id")->execute([':id' => $id]);
            $this->pdo->prepare("DELETE FROM log_regras WHERE id = :id")->execute([':id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['ok' => false, 'erro' => 'Não foi possível remover.'];
        }
        LogService::audit('Regra de frete removida', ['regra_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }

    /* ------------------------------------------------- validação (pura) */

    public static function validar(array $d): array
    {
        $e = [];
        $nome = trim((string)($d['nome'] ?? ''));
        if ($nome === '') $e['nome'] = 'Informe o nome da regra.';
        elseif (mb_strlen($nome) > 160) $e['nome'] = 'Nome muito longo (máx. 160).';

        foreach (($d['condicoes'] ?? []) as $i => $c) {
            $campo = (string)($c['campo'] ?? '');
            $oper  = (string)($c['operador'] ?? '');
            if ($campo === '') continue; // linha vazia é ignorada no salvamento
            if (!in_array($campo, self::CAMPOS_CONDICAO, true)) $e["condicao_$i"] = 'Campo de condição inválido.';
            elseif (!in_array($oper, self::OPERADORES, true)) $e["condicao_$i"] = 'Operador inválido.';
        }

        // Precisa ter ao menos uma ação com efeito.
        $ac = self::sanitizarAcoes($d['acoes'] ?? []);
        $temEfeito = $ac['frete_gratis'] || $ac['bloquear_frete_gratis']
            || $ac['desconto_pct'] > 0 || $ac['desconto_fixo'] > 0 || $ac['acrescimo'] > 0
            || $ac['prazo_adicional'] > 0 || !empty($ac['ocultar_servicos'])
            || $ac['subsidio_max_valor'] !== null || $ac['subsidio_max_pct'] !== null;
        if (!$temEfeito) $e['acoes'] = 'Defina ao menos uma ação (frete grátis, desconto, etc.).';

        return $e;
    }

    /* ------------------------------------------------- helpers */

    private function sincronizarCondicoes(int $regraId, array $condicoes): void
    {
        $this->pdo->prepare("DELETE FROM log_regra_condicoes WHERE regra_id = :id")->execute([':id' => $regraId]);
        if (!$condicoes) return;
        $stmt = $this->pdo->prepare("INSERT INTO log_regra_condicoes (regra_id, campo, operador, valor) VALUES (:r, :campo, :op, :val)");
        foreach ($condicoes as $c) {
            $campo = (string)($c['campo'] ?? '');
            $oper  = (string)($c['operador'] ?? '');
            if ($campo === '' || !in_array($campo, self::CAMPOS_CONDICAO, true) || !in_array($oper, self::OPERADORES, true)) continue;
            $valor = $c['valor'] ?? null;
            // Normaliza: 'between'/'in'/'not_in' viram lista.
            if (in_array($oper, ['in', 'not_in', 'between'], true) && !is_array($valor)) {
                $valor = array_map('trim', explode(',', (string)$valor));
            }
            $stmt->execute([':r' => $regraId, ':campo' => $campo, ':op' => $oper, ':val' => json_encode($valor, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private function registrarHistorico(int $regraId, string $acao, ?int $usuarioId): void
    {
        try {
            $this->pdo->prepare("INSERT INTO log_regra_historico (regra_id, usuario_id, acao) VALUES (:r, :u, :a)")
                      ->execute([':r' => $regraId, ':u' => $usuarioId, ':a' => $acao]);
        } catch (\Throwable $e) { /* histórico não derruba a operação */ }
    }

    public static function sanitizarAcoes($acoes): array
    {
        $a = is_array($acoes) ? $acoes : (json_decode((string)$acoes, true) ?: []);
        $numOuNull = static fn($k) => (isset($a[$k]) && $a[$k] !== '' && $a[$k] !== null) ? round((float)$a[$k], 2) : null;
        $ocultar = $a['ocultar_servicos'] ?? [];
        if (!is_array($ocultar)) $ocultar = array_filter(array_map('trim', explode(',', (string)$ocultar)));
        return [
            'frete_gratis'          => !empty($a['frete_gratis']),
            'frete_gratis_mais_barato'          => !empty($a['frete_gratis_mais_barato']),
            'bloquear_frete_gratis' => !empty($a['bloquear_frete_gratis']),
            'desconto_pct'          => max(0.0, (float)($a['desconto_pct'] ?? 0)),
            'desconto_fixo'         => max(0.0, (float)($a['desconto_fixo'] ?? 0)),
            'acrescimo'             => max(0.0, (float)($a['acrescimo'] ?? 0)),
            'prazo_adicional'       => max(0, (int)($a['prazo_adicional'] ?? 0)),
            'subsidio_max_valor'    => $numOuNull('subsidio_max_valor'),
            'subsidio_max_pct'      => $numOuNull('subsidio_max_pct'),
            'ocultar_servicos'      => array_values($ocultar),
        ];
    }

    private static function resumirAcoes(array $a): array
    {
        $r = [];
        if (!empty($a['frete_gratis'])) {
            $txt = 'Frete grátis';
            if (!empty($a['subsidio_max_valor'])) $txt .= ' (teto R$ ' . number_format((float)$a['subsidio_max_valor'], 2, ',', '.') . ')';
            elseif (!empty($a['subsidio_max_pct'])) $txt .= ' (teto ' . rtrim(rtrim(number_format((float)$a['subsidio_max_pct'], 2, ',', '.'), '0'), ',') . '%)';
            $r[] = $txt;
        }
        if (!empty($a['desconto_pct']))    $r[] = '-' . rtrim(rtrim(number_format((float)$a['desconto_pct'], 2, ',', '.'), '0'), ',') . '%';
        if (!empty($a['desconto_fixo']))   $r[] = '-R$ ' . number_format((float)$a['desconto_fixo'], 2, ',', '.');
        if (!empty($a['acrescimo']))       $r[] = '+R$ ' . number_format((float)$a['acrescimo'], 2, ',', '.');
        if (!empty($a['prazo_adicional'])) $r[] = '+' . (int)$a['prazo_adicional'] . 'd';
        if (!empty($a['bloquear_frete_gratis'])) $r[] = 'Bloqueia frete grátis';
        if (!empty($a['ocultar_servicos'])) $r[] = 'Oculta ' . count((array)$a['ocultar_servicos']) . ' serviço(s)';
        return $r;
    }

    private static function dataOuNull($v): ?string
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        $v = str_replace('T', ' ', $v);
        return strlen($v) === 16 ? $v . ':00' : $v;
    }
}
