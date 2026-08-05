<?php
/**
 * DivergenciaService — divergências de frete + alertas por produto.
 *
 * Divergência = quebra entre o previsto (cotado/informado) e o real
 * (cobrado pela transportadora / peso e dimensões aferidos). Ao registrar:
 *   1) calcula diferença (R$ e %), nível de impacto e tipo (peso/dimensão/…);
 *   2) vincula os produtos do pedido (log_divergencia_produtos);
 *   3) alimenta um ALERTA agregado por produto (log_produto_alertas):
 *      ocorrencias++ e impacto_acumulado += diferença, usando o
 *      UNIQUE(produto_id, status) como acumulador do alerta aberto;
 *   4) registra o vínculo alerta↔divergência (log_produto_alerta_pedidos).
 *
 * Objetivo: achar rápido QUAL produto está com cadastro de peso/dimensão
 * errado, quantificando o prejuízo acumulado.
 *
 * Métodos de DECISÃO (calcular, nivelImpacto, tipoDivergencia, motivoAuto,
 * acoesPermitidas, transições, rótulos) são PUROS — testáveis sem banco.
 */
class DivergenciaService
{
    private PDO $pdo;

    /* Régua de impacto (R$ e %) — ajuste conforme o negócio. */
    private const ALTO_VALOR = 15.0, ALTO_PCT = 40.0;
    private const MEDIO_VALOR = 5.0, MEDIO_PCT = 15.0;
    private const TOLERANCIA_PESO_G = 50;   // ruído de balança
    private const TOLERANCIA_DIM_CM = 1.0;  // ruído de medição

    private const TRANSICOES = [
        'aberta'     => ['em_analise', 'resolvida', 'ignorada'],
        'em_analise' => ['resolvida', 'ignorada', 'aberta'],
        'resolvida'  => ['aberta'],
        'ignorada'   => ['aberta'],
    ];
    private const STATUS_LABELS = [
        'aberta' => 'Aberta', 'em_analise' => 'Em análise', 'resolvida' => 'Resolvida', 'ignorada' => 'Ignorada',
    ];
    private const NIVEL_LABELS = ['baixo' => 'Baixo', 'medio' => 'Médio', 'alto' => 'Alto'];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /* =================================================================
       DECISÃO (puro)
       ================================================================= */

    /** Diferença entre o cobrado pela transportadora e o estimado. */
    public static function calcular(float $valorEstimado, float $valorTransportadora): array
    {
        $dif = round($valorTransportadora - $valorEstimado, 2);
        $pct = $valorEstimado > 0
            ? round($dif / $valorEstimado * 100, 2)
            : ($valorTransportadora > 0 ? 100.0 : 0.0);
        return ['diferenca_valor' => $dif, 'diferenca_pct' => $pct];
    }

    public static function nivelImpacto(float $difValor, float $difPct): string
    {
        $v = abs($difValor); $p = abs($difPct);
        if ($v >= self::ALTO_VALOR || $p >= self::ALTO_PCT)   return 'alto';
        if ($v >= self::MEDIO_VALOR || $p >= self::MEDIO_PCT) return 'medio';
        return 'baixo';
    }

    /** Classifica a natureza da divergência (para o tipo do alerta). */
    public static function tipoDivergencia(?int $pesoInf, ?int $pesoAfer, ?array $dimsInf, ?array $dimsAfer): string
    {
        $pesoDif = ($pesoInf !== null && $pesoAfer !== null) && abs($pesoAfer - $pesoInf) >= self::TOLERANCIA_PESO_G;
        $dimDif = self::dimsDivergem($dimsInf, $dimsAfer);
        if ($pesoDif && $dimDif) return 'misto';
        if ($pesoDif) return 'peso';
        if ($dimDif) return 'dimensao';
        return 'misto';
    }

    public static function motivoAuto(?int $pesoInf, ?int $pesoAfer, ?array $dimsInf, ?array $dimsAfer): string
    {
        $partes = [];
        if ($pesoInf !== null && $pesoAfer !== null && abs($pesoAfer - $pesoInf) >= self::TOLERANCIA_PESO_G) {
            $delta = $pesoAfer - $pesoInf;
            $partes[] = 'Peso: informado ' . $pesoInf . 'g, aferido ' . $pesoAfer . 'g (' . ($delta >= 0 ? '+' : '') . $delta . 'g)';
        }
        if (self::dimsDivergem($dimsInf, $dimsAfer)) {
            $partes[] = 'Dimensões aferidas divergem das informadas';
        }
        return $partes ? implode('; ', $partes) : 'Cobrança acima do previsto';
    }

    public static function acoesPermitidas(string $status): array
    {
        return match ($status) {
            'aberta'     => ['analisar', 'resolver', 'ignorar'],
            'em_analise' => ['resolver', 'ignorar'],
            'resolvida'  => ['reabrir'],
            'ignorada'   => ['reabrir'],
            default      => [],
        };
    }

    public static function transicaoValida(string $de, string $para): bool
    {
        return in_array($para, self::TRANSICOES[$de] ?? [], true);
    }

    public static function statusRotulo(string $s): string { return self::STATUS_LABELS[$s] ?? ucfirst(str_replace('_', ' ', $s)); }
    public static function nivelRotulo(string $n): string { return self::NIVEL_LABELS[$n] ?? ucfirst($n); }

    private static function dimsDivergem(?array $a, ?array $b): bool
    {
        if (!$a || !$b) return false;
        foreach (['altura', 'largura', 'comprimento'] as $k) {
            $va = (float)($a[$k] ?? $a[$k . '_cm'] ?? 0);
            $vb = (float)($b[$k] ?? $b[$k . '_cm'] ?? 0);
            if ($va > 0 && $vb > 0 && abs($va - $vb) >= self::TOLERANCIA_DIM_CM) return true;
        }
        return false;
    }

    /* =================================================================
       REGISTRO
       ================================================================= */

    public function registrar(array $d, ?int $usuarioId = null): array
    {
        $etiquetaId = !empty($d['etiqueta_id']) ? (int)$d['etiqueta_id'] : null;

        // Idempotência: uma divergência por etiqueta.
        if ($etiquetaId) {
            try {
                $st = $this->pdo->prepare("SELECT id FROM log_divergencias WHERE etiqueta_id = :e LIMIT 1");
                $st->execute([':e' => $etiquetaId]);
                if ($ex = (int)$st->fetchColumn()) return ['ok' => true, 'id' => $ex, 'existente' => true];
            } catch (\Throwable $e) { /* segue */ }
        }

        $estimado = round((float)($d['valor_estimado'] ?? 0), 2);
        $transp = round((float)($d['valor_transportadora'] ?? 0), 2);
        $calc = self::calcular($estimado, $transp);

        $pesoInf = isset($d['peso_informado_g']) ? (int)$d['peso_informado_g'] : null;
        $pesoAfer = isset($d['peso_aferido_g']) ? (int)$d['peso_aferido_g'] : null;
        $dimsInf = is_array($d['dimensoes_informadas'] ?? null) ? $d['dimensoes_informadas'] : null;
        $dimsAfer = is_array($d['dimensoes_aferidas'] ?? null) ? $d['dimensoes_aferidas'] : null;

        $nivel = in_array($d['nivel_impacto'] ?? '', ['baixo', 'medio', 'alto'], true)
            ? $d['nivel_impacto'] : self::nivelImpacto($calc['diferenca_valor'], $calc['diferenca_pct']);
        $tipo = self::tipoDivergencia($pesoInf, $pesoAfer, $dimsInf, $dimsAfer);
        $motivo = trim((string)($d['motivo'] ?? '')) !== '' ? $d['motivo'] : self::motivoAuto($pesoInf, $pesoAfer, $dimsInf, $dimsAfer);
        $produtos = array_values(array_unique(array_map('intval', is_array($d['produtos'] ?? null) ? $d['produtos'] : [])));
        $pedidoId = !empty($d['pedido_id']) ? (int)$d['pedido_id'] : null;

        try {
            $this->pdo->beginTransaction();
            $st = $this->pdo->prepare(
                "INSERT INTO log_divergencias
                 (pedido_id, etiqueta_id, transportadora_id, servico_codigo, valor_estimado, valor_cliente,
                  subsidio_loja, valor_transportadora, diferenca_valor, diferenca_pct, peso_informado_g, peso_aferido_g,
                  dimensoes_informadas, dimensoes_aferidas, motivo, nivel_impacto, status, observacoes)
                 VALUES (:ped, :etq, :tid, :sc, :est, :cli, :sub, :tra, :dif, :pct, :pinf, :pafe,
                  :dinf, :dafe, :mot, :niv, 'aberta', :obs)"
            );
            $st->execute([
                ':ped'  => $pedidoId,
                ':etq'  => $etiquetaId,
                ':tid'  => !empty($d['transportadora_id']) ? (int)$d['transportadora_id'] : null,
                ':sc'   => $d['servico_codigo'] ?? null,
                ':est'  => $estimado,
                ':cli'  => round((float)($d['valor_cliente'] ?? 0), 2),
                ':sub'  => round((float)($d['subsidio_loja'] ?? 0), 2),
                ':tra'  => $transp,
                ':dif'  => $calc['diferenca_valor'],
                ':pct'  => $calc['diferenca_pct'],
                ':pinf' => $pesoInf,
                ':pafe' => $pesoAfer,
                ':dinf' => $dimsInf ? json_encode($dimsInf, JSON_UNESCAPED_UNICODE) : null,
                ':dafe' => $dimsAfer ? json_encode($dimsAfer, JSON_UNESCAPED_UNICODE) : null,
                ':mot'  => mb_substr((string)$motivo, 0, 255),
                ':niv'  => $nivel,
                ':obs'  => $d['observacoes'] ?? null,
            ]);
            $id = (int)$this->pdo->lastInsertId();

            $this->vincularProdutos($id, $produtos);
            $this->alimentarAlertas($id, $produtos, $tipo, $calc['diferenca_valor'], $pedidoId);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            LogService::error('Falha ao registrar divergência', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao registrar a divergência.'];
        }

        LogService::audit('Divergência registrada', ['divergencia_id' => $id, 'nivel' => $nivel, 'diferenca' => $calc['diferenca_valor'], 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'id' => $id, 'nivel_impacto' => $nivel, 'diferenca_valor' => $calc['diferenca_valor'], 'diferenca_pct' => $calc['diferenca_pct'], 'tipo' => $tipo];
    }

    private function vincularProdutos(int $divId, array $produtos): void
    {
        if (!$produtos) return;
        $st = $this->pdo->prepare("INSERT IGNORE INTO log_divergencia_produtos (divergencia_id, produto_id) VALUES (:d, :p)");
        foreach ($produtos as $p) {
            if ($p > 0) $st->execute([':d' => $divId, ':p' => $p]);
        }
    }

    /** Acumula o alerta aberto por produto e vincula à divergência. */
    private function alimentarAlertas(int $divId, array $produtos, string $tipo, float $impacto, ?int $pedidoId): void
    {
        foreach ($produtos as $produtoId) {
            if ($produtoId <= 0) continue;

            $alertaId = 0;
            try {
                $sel = $this->pdo->prepare("SELECT id, tipo FROM log_produto_alertas WHERE produto_id = :p AND status = 'aberto' LIMIT 1");
                $sel->execute([':p' => $produtoId]);
                $aberto = $sel->fetch(PDO::FETCH_ASSOC);

                if ($aberto) {
                    $alertaId = (int)$aberto['id'];
                    $novoTipo = ($aberto['tipo'] === $tipo) ? $tipo : 'misto';
                    $this->pdo->prepare(
                        "UPDATE log_produto_alertas
                         SET ocorrencias = ocorrencias + 1, impacto_acumulado = impacto_acumulado + :imp, tipo = :t
                         WHERE id = :id"
                    )->execute([':imp' => $impacto, ':t' => $novoTipo, ':id' => $alertaId]);
                } else {
                    $this->pdo->prepare(
                        "INSERT INTO log_produto_alertas (produto_id, tipo, ocorrencias, impacto_acumulado, status)
                         VALUES (:p, :t, 1, :imp, 'aberto')"
                    )->execute([':p' => $produtoId, ':t' => $tipo, ':imp' => $impacto]);
                    $alertaId = (int)$this->pdo->lastInsertId();
                }

                if ($alertaId) {
                    $this->pdo->prepare(
                        "INSERT IGNORE INTO log_produto_alerta_pedidos (alerta_id, divergencia_id, pedido_id) VALUES (:a, :d, :ped)"
                    )->execute([':a' => $alertaId, ':d' => $divId, ':ped' => $pedidoId]);
                }
            } catch (\Throwable $e) {
                LogService::warning('Falha ao alimentar alerta de produto', ['produto_id' => $produtoId, 'erro' => $e->getMessage()]);
            }
        }
    }

    /* =================================================================
       TRATATIVA (divergências)
       ================================================================= */

    public function analisar(int $id, ?int $usuarioId = null): array { return $this->transicionar($id, 'em_analise', $usuarioId); }
    public function resolver(int $id, ?int $usuarioId = null): array { return $this->transicionar($id, 'resolvida', $usuarioId); }
    public function ignorar(int $id, ?int $usuarioId = null): array { return $this->transicionar($id, 'ignorada', $usuarioId); }
    public function reabrir(int $id, ?int $usuarioId = null): array { return $this->transicionar($id, 'aberta', $usuarioId); }

    public function atualizar(int $id, array $campos, ?int $usuarioId = null): array
    {
        $permit = ['nivel_impacto', 'observacoes', 'responsavel_id', 'motivo'];
        $sets = []; $vals = [':id' => $id];
        foreach ($campos as $k => $v) {
            if (!in_array($k, $permit, true)) continue;
            if ($k === 'nivel_impacto' && !in_array($v, ['baixo', 'medio', 'alto'], true)) continue;
            $sets[] = "`$k` = :$k"; $vals[":$k"] = $v;
        }
        if (!$sets) return ['ok' => false, 'erro' => 'Nada para atualizar.'];
        try {
            $this->pdo->prepare("UPDATE log_divergencias SET " . implode(', ', $sets) . " WHERE id = :id")->execute($vals);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao atualizar.'];
        }
        return ['ok' => true];
    }

    private function transicionar(int $id, string $para, ?int $usuarioId): array
    {
        $d = $this->obter($id);
        if (!$d) return ['ok' => false, 'erro' => 'Divergência não encontrada.'];
        if (!self::transicaoValida((string)$d['status'], $para)) {
            return ['ok' => false, 'erro' => 'Transição não permitida a partir de "' . self::statusRotulo((string)$d['status']) . '".'];
        }
        try {
            $this->pdo->prepare("UPDATE log_divergencias SET status = :s, responsavel_id = COALESCE(:u, responsavel_id) WHERE id = :id")
                      ->execute([':s' => $para, ':u' => $usuarioId, ':id' => $id]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao atualizar o status.'];
        }
        LogService::audit('Divergência ' . $para, ['divergencia_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'status' => $para];
    }

    /* =================================================================
       LEITURA — divergências
       ================================================================= */

    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 30): array
    {
        $where = []; $p = [];
        if (!empty($filtros['status'])) { $where[] = 'd.status = :st'; $p[':st'] = $filtros['status']; }
        if (!empty($filtros['nivel']))  { $where[] = 'd.nivel_impacto = :nv'; $p[':nv'] = $filtros['nivel']; }
        if (!empty($filtros['busca'])) {
            $where[] = '(d.pedido_id = :qexato OR d.motivo LIKE :q)';
            $p[':q'] = '%' . $filtros['busca'] . '%';
            $p[':qexato'] = ctype_digit((string)$filtros['busca']) ? (int)$filtros['busca'] : 0;
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pagina = max(1, $pagina); $porPagina = max(1, min(100, $porPagina));
        $off = ($pagina - 1) * $porPagina;

        try {
            $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM log_divergencias d$sqlWhere");
            $cnt->execute($p);
            $total = (int)$cnt->fetchColumn();

            $sql = "SELECT d.*, t.nome AS transportadora_nome
                    FROM log_divergencias d LEFT JOIN log_transportadoras t ON t.id = d.transportadora_id
                    $sqlWhere ORDER BY d.criado_em DESC, d.id DESC LIMIT $porPagina OFFSET $off";
            $st = $this->pdo->prepare($sql);
            $st->execute($p);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar divergências', ['erro' => $e->getMessage()]);
            return ['itens' => [], 'total' => 0, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'resumo' => []];
        }
        foreach ($rows as &$r) {
            $r['status_label'] = self::statusRotulo((string)$r['status']);
            $r['nivel_label'] = self::nivelRotulo((string)$r['nivel_impacto']);
            $r['acoes'] = self::acoesPermitidas((string)$r['status']);
        }
        return ['itens' => $rows, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'resumo' => $this->resumo()];
    }

    /** KPIs simples para o topo da tela. */
    public function resumo(): array
    {
        try {
            $abertas = (int)$this->pdo->query("SELECT COUNT(*) FROM log_divergencias WHERE status IN ('aberta','em_analise')")->fetchColumn();
            $impacto = (float)$this->pdo->query("SELECT COALESCE(SUM(diferenca_valor),0) FROM log_divergencias WHERE status <> 'ignorada'")->fetchColumn();
            $alertas = (int)$this->pdo->query("SELECT COUNT(*) FROM log_produto_alertas WHERE status = 'aberto'")->fetchColumn();
            return ['abertas' => $abertas, 'impacto_total' => round($impacto, 2), 'alertas_abertos' => $alertas];
        } catch (\Throwable $e) {
            return ['abertas' => 0, 'impacto_total' => 0, 'alertas_abertos' => 0];
        }
    }

    public function obter(int $id): ?array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT d.*, t.nome AS transportadora_nome
                 FROM log_divergencias d LEFT JOIN log_transportadoras t ON t.id = d.transportadora_id
                 WHERE d.id = :id LIMIT 1"
            );
            $st->execute([':id' => $id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $r['dimensoes_informadas_json'] = json_decode((string)$r['dimensoes_informadas'], true) ?: [];
            $r['dimensoes_aferidas_json'] = json_decode((string)$r['dimensoes_aferidas'], true) ?: [];
            $r['status_label'] = self::statusRotulo((string)$r['status']);
            $r['nivel_label'] = self::nivelRotulo((string)$r['nivel_impacto']);
            $r['acoes'] = self::acoesPermitidas((string)$r['status']);
            $pr = $this->pdo->prepare("SELECT produto_id FROM log_divergencia_produtos WHERE divergencia_id = :d");
            $pr->execute([':d' => $id]);
            $r['produtos'] = array_map('intval', $pr->fetchAll(PDO::FETCH_COLUMN) ?: []);
            return $r;
        } catch (\Throwable $e) {
            LogService::error('Falha ao obter divergência', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /* =================================================================
       ALERTAS DE PRODUTO
       ================================================================= */

    public function listarAlertas(array $filtros = [], int $pagina = 1, int $porPagina = 30): array
    {
        $where = []; $p = [];
        $status = $filtros['status'] ?? 'aberto';
        if ($status !== 'todos') { $where[] = 'status = :st'; $p[':st'] = $status; }
        if (!empty($filtros['tipo'])) { $where[] = 'tipo = :tp'; $p[':tp'] = $filtros['tipo']; }
        if (!empty($filtros['busca']) && ctype_digit((string)$filtros['busca'])) { $where[] = 'produto_id = :pid'; $p[':pid'] = (int)$filtros['busca']; }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pagina = max(1, $pagina); $porPagina = max(1, min(100, $porPagina));
        $off = ($pagina - 1) * $porPagina;

        try {
            $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM log_produto_alertas$sqlWhere");
            $cnt->execute($p);
            $total = (int)$cnt->fetchColumn();
            $st = $this->pdo->prepare("SELECT * FROM log_produto_alertas$sqlWhere ORDER BY impacto_acumulado DESC, ocorrencias DESC LIMIT $porPagina OFFSET $off");
            $st->execute($p);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar alertas de produto', ['erro' => $e->getMessage()]);
            return ['itens' => [], 'total' => 0, 'pagina' => $pagina, 'por_pagina' => $porPagina];
        }
        return ['itens' => $rows, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina];
    }

    public function obterAlerta(int $id): ?array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM log_produto_alertas WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if (!$a) return null;
            $dv = $this->pdo->prepare(
                "SELECT d.id, d.pedido_id, d.diferenca_valor, d.nivel_impacto, d.motivo, d.criado_em
                 FROM log_produto_alerta_pedidos ap
                 JOIN log_divergencias d ON d.id = ap.divergencia_id
                 WHERE ap.alerta_id = :a ORDER BY d.criado_em DESC"
            );
            $dv->execute([':a' => $id]);
            $a['divergencias'] = $dv->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return $a;
        } catch (\Throwable $e) {
            LogService::error('Falha ao obter alerta', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    public function resolverAlerta(int $id, ?int $usuarioId = null): array
    {
        try {
            $st = $this->pdo->prepare("SELECT produto_id, status FROM log_produto_alertas WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if (!$a) return ['ok' => false, 'erro' => 'Alerta não encontrado.'];
            if ($a['status'] === 'resolvido') return ['ok' => true, 'ja_resolvido' => true];

            $this->pdo->beginTransaction();
            // UNIQUE(produto_id, status): remove um resolvido antigo do mesmo produto antes.
            $this->pdo->prepare("DELETE FROM log_produto_alertas WHERE produto_id = :p AND status = 'resolvido' AND id <> :id")
                      ->execute([':p' => (int)$a['produto_id'], ':id' => $id]);
            $this->pdo->prepare("UPDATE log_produto_alertas SET status = 'resolvido', resolvido_por = :u, resolvido_em = NOW() WHERE id = :id")
                      ->execute([':u' => $usuarioId, ':id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['ok' => false, 'erro' => 'Falha ao resolver o alerta.'];
        }
        LogService::audit('Alerta de produto resolvido', ['alerta_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }

    public function reabrirAlerta(int $id, ?int $usuarioId = null): array
    {
        try {
            $st = $this->pdo->prepare("SELECT produto_id FROM log_produto_alertas WHERE id = :id AND status = 'resolvido' LIMIT 1");
            $st->execute([':id' => $id]);
            $produtoId = (int)$st->fetchColumn();
            if (!$produtoId) return ['ok' => false, 'erro' => 'Alerta não está resolvido.'];

            $ja = $this->pdo->prepare("SELECT COUNT(*) FROM log_produto_alertas WHERE produto_id = :p AND status = 'aberto'");
            $ja->execute([':p' => $produtoId]);
            if ((int)$ja->fetchColumn() > 0) return ['ok' => false, 'erro' => 'Já existe um alerta aberto para este produto.'];

            $this->pdo->prepare("UPDATE log_produto_alertas SET status = 'aberto', resolvido_por = NULL, resolvido_em = NULL WHERE id = :id")->execute([':id' => $id]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao reabrir o alerta.'];
        }
        return ['ok' => true];
    }

    /* =================================================================
       Prefill a partir da etiqueta (opcional, para a tela de registro)
       ================================================================= */

    public function contextoDaEtiqueta(int $etiquetaId): array
    {
        try {
            $st = $this->pdo->prepare("SELECT pedido_id, transportadora_id, servico_codigo, valor, volumes FROM log_etiquetas WHERE id = :id LIMIT 1");
            $st->execute([':id' => $etiquetaId]);
            $e = $st->fetch(PDO::FETCH_ASSOC);
            if (!$e) return [];
            $vol = json_decode((string)$e['volumes'], true) ?: [];
            $volumes = $vol['volumes'] ?? (isset($vol[0]) ? $vol : []);
            $peso = 0; foreach ($volumes as $v) $peso += (int)($v['peso_cobranca_g'] ?? $v['peso_g'] ?? 0);
            $dims = $volumes[0] ?? [];
            return [
                'pedido_id'         => $e['pedido_id'] ? (int)$e['pedido_id'] : null,
                'transportadora_id' => $e['transportadora_id'] ? (int)$e['transportadora_id'] : null,
                'servico_codigo'    => $e['servico_codigo'] ?? null,
                'valor_estimado'    => (float)($e['valor'] ?? 0),
                'peso_informado_g'  => $peso ?: null,
                'dimensoes_informadas' => $dims ? [
                    'altura' => (float)($dims['altura_cm'] ?? $dims['altura'] ?? 0),
                    'largura' => (float)($dims['largura_cm'] ?? $dims['largura'] ?? 0),
                    'comprimento' => (float)($dims['comprimento_cm'] ?? $dims['comprimento'] ?? 0),
                ] : null,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
