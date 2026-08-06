<?php
/**
 * CalculadoraService — motor de cálculo de frete.
 *
 * Fluxo: empacota o carrinho (embalagens + múltiplos volumes + peso cúbico)
 * -> cota as transportadoras ativas via adapters (Fase 2) -> aplica o
 * MotorRegras -> ordena -> persiste (log_cotacoes / log_cotacao_opcoes).
 *
 * O empacotador (empacotar) é PURO e estático: dado itens + embalagens,
 * devolve os volumes e os pesos — testável sem banco.
 *
 * Fator de cubagem: peso cúbico (kg) = (A x L x C) / 6000 (cm). É o padrão
 * usado por Correios/Melhor Envio; o peso de cobrança é max(real, cúbico).
 */
class CalculadoraService
{
    private PDO $pdo;
    private EmbalagemService $embalagens;
    private MotorRegras $motor;

    private const FATOR_CUBAGEM = 6000;

    public function __construct(?PDO $pdo = null, ?EmbalagemService $embalagens = null, ?MotorRegras $motor = null)
    {
        $this->pdo        = $pdo ?? Database::getInstance()->getConnection();
        $this->embalagens = $embalagens ?? new EmbalagemService($this->pdo);
        $this->motor      = $motor ?? new MotorRegras($this->pdo);
    }

    /* =================================================================
       ENTRADA PRINCIPAL
       ================================================================= */

    /**
     * @param array $req cep_destino (obrig.), itens[], cep_origem?, uf?, cidade?,
     *                   valor_mercadoria?, valor_declarado?, seguro?, canal?,
     *                   origem?, tipo_cliente?, transportadora_ids?, persistir?
     * @return array{ok:bool, cotacao_id?:int, opcoes?:array, empacotamento?:array, erro?:string}
     */
    public function cotar(array $req): array
    {
        $cepDestino = preg_replace('/\D/', '', (string)($req['cep_destino'] ?? '')) ?? '';
        if (strlen($cepDestino) < 8) {
            return ['ok' => false, 'erro' => 'CEP de destino inválido.'];
        }

        $itens = is_array($req['itens'] ?? null) ? $req['itens'] : [];

        // Agregados do carrinho.
        $qtdTotal = 0; $cats = []; $marcas = []; $prods = []; $valorMerc = (float)($req['valor_mercadoria'] ?? 0);
        $somaValor = 0.0;
        foreach ($itens as $it) {
            $q = max(1, (int)($it['quantidade'] ?? 1));
            $qtdTotal += $q;
            $somaValor += (float)($it['valor'] ?? 0) * $q;
            if (!empty($it['categoria_id'])) $cats[]   = $it['categoria_id'];
            if (!empty($it['marca_id']))     $marcas[] = $it['marca_id'];
            if (!empty($it['produto_id']))   $prods[]  = $it['produto_id'];
        }
        if ($valorMerc <= 0) $valorMerc = $somaValor;

        // Empacotamento.
        $pack = self::empacotar($itens, $this->embalagens->ativas());

        $cepOrigem = !empty($req['cep_origem']) ? preg_replace('/\D/', '', (string)$req['cep_origem']) : null;
        $seguro = !empty($req['seguro']);

        $volumes = array_map(static fn($v) => [
            'altura'      => $v['altura_cm'],
            'largura'     => $v['largura_cm'],
            'comprimento' => $v['comprimento_cm'],
            'peso_g'      => $v['peso_cobranca_g'],
        ], $pack['volumes']);

        $paramsBase = [
            'cep_origem'        => $cepOrigem,
            'cep_destino'       => $cepDestino,
            'volumes'           => $volumes,
            'peso_g'            => $pack['peso_cobranca_g'],
            'valor'             => $valorMerc,
            'seguro'            => $seguro,
            'aviso_recebimento' => !empty($req['aviso_recebimento']),
            'maos_proprias'     => !empty($req['maos_proprias']),
        ];

        // Cota cada transportadora ativa.
        $opcoes = [];
        foreach ($this->transportadorasAtivas($req['transportadora_ids'] ?? null) as $c) {
            $params = $paramsBase;
            if (empty($cepOrigem) && !empty($c['cep_origem'])) {
                $params['cep_origem'] = preg_replace('/\D/', '', (string)$c['cep_origem']);
            }
            try {
                $adapter = TransportadoraManager::resolver($c);
                $r = $adapter->cotar($params);
            } catch (\Throwable $e) {
                LogService::warning('Falha ao cotar transportadora', ['transportadora' => $c['slug'] ?? $c['id'], 'erro' => $e->getMessage()]);
                continue;
            }
            if (empty($r['ok'])) continue;
            foreach (($r['opcoes'] ?? []) as $op) {
                $opcoes[] = [
                    'transportadora_id'   => (int)$c['id'],
                    'transportadora_slug' => (string)($c['slug'] ?? ''),
                    'transportadora_nome' => (string)($c['nome'] ?? ''),
                    'servico_codigo'      => (string)($op['servico_codigo'] ?? ''),
                    'servico_nome'        => (string)($op['servico_nome'] ?? ''),
                    'prazo_dias'          => (int)($op['prazo_dias'] ?? 0),
                    'valor'               => (float)($op['valor'] ?? 0),
                    'valor_fmt'           => PriceHelper::format((float)($op['valor'] ?? 0)),
                    'tipo_postagem'       => (string)($op['tipo_postagem'] ?? 'postagem'),
                    'categoria'           => strtolower((string)($op['categoria'] ?? 'padrao')),
                    'avisos'              => $op['avisos'] ?? [],
                ];
            }
        }

        // Contexto + regras.
        $ctx = $this->montarContexto($req, [
            'valor_mercadoria' => round($valorMerc, 2),
            'peso_total_g'     => $pack['peso_cobranca_g'],
            'quantidade_total' => $qtdTotal,
            'categorias'       => $cats,
            'marcas'           => $marcas,
            'produtos'         => $prods,
            'cep_destino'      => $cepDestino,
        ]);
        $res = $this->motor->avaliar($ctx, $opcoes);
        $opcoes = $res['opcoes'];

        // Ordena: visíveis primeiro, por valor final asc.
        usort($opcoes, static function ($a, $b) {
            $ah = !empty($a['oculto']); $bh = !empty($b['oculto']);
            if ($ah !== $bh) return $ah ? 1 : -1;
            return ($a['valor_final'] ?? INF) <=> ($b['valor_final'] ?? INF);
        });
        $this->marcarDestaques($opcoes);

        $cotacaoId = null;
        if ($req['persistir'] ?? true) {
            $cotacaoId = $this->persistir($req, $ctx, $pack, $opcoes, $cepOrigem ?? '', $cepDestino, $valorMerc, $seguro);
        }

        return [
            'ok'               => true,
            'cotacao_id'       => $cotacaoId,
            'opcoes'           => $opcoes,
            'empacotamento'    => $pack,
            'contexto'         => $ctx,
            'regras_aplicadas' => $res['regras_aplicadas'] ?? [],
        ];
    }

    /* =================================================================
       EMPACOTADOR (puro)
       ================================================================= */

    public static function pesoCubicoG(float $a, float $l, float $c): int
    {
        $kg = ($a * $l * $c) / self::FATOR_CUBAGEM;
        return (int)max(0, round($kg * 1000));
    }

    /**
     * Distribui os itens em volumes usando as embalagens disponíveis.
     * Heurística (bin-packing por volume, first-fit decreasing) — aproximação
     * pragmática; cotação usa dims+peso por volume.
     *
     * @return array{volumes:array,qtd_volumes:int,peso_real_g:int,peso_cubico_g:int,peso_cobranca_g:int,embalagens:array,avisos:array}
     */
    public static function empacotar(array $itens, array $embalagens): array
    {
        // Expande itens em unidades.
        $unidades = [];
        foreach ($itens as $it) {
            $q = max(1, (int)($it['quantidade'] ?? 1));
            $a = (float)($it['altura_cm'] ?? 0);
            $l = (float)($it['largura_cm'] ?? 0);
            $c = (float)($it['comprimento_cm'] ?? 0);
            $p = (int)($it['peso_g'] ?? 0);
            for ($i = 0; $i < $q; $i++) {
                $unidades[] = ['a' => $a, 'l' => $l, 'c' => $c, 'p' => $p, 'vol' => max(0.0, $a * $l * $c)];
            }
        }

        $avisos = [];
        if (empty($unidades)) {
            return ['volumes' => [], 'qtd_volumes' => 0, 'peso_real_g' => 0, 'peso_cubico_g' => 0, 'peso_cobranca_g' => 0, 'embalagens' => [], 'avisos' => ['Sem itens para empacotar.']];
        }

        $pesoTotal = array_sum(array_column($unidades, 'p'));
        $volTotal  = array_sum(array_column($unidades, 'vol'));

        // Sem embalagens: volume virtual (empilha comprimento). Aproximação.
        if (empty($embalagens)) {
            $a = max(array_column($unidades, 'a')) ?: 1;
            $l = max(array_column($unidades, 'l')) ?: 1;
            $c = array_sum(array_column($unidades, 'c')) ?: 1;
            $vol = self::montarVolume($a, $l, $c, $pesoTotal, null);
            return ['volumes' => [$vol], 'qtd_volumes' => 1, 'peso_real_g' => $pesoTotal,
                    'peso_cubico_g' => $vol['peso_cubico_g'], 'peso_cobranca_g' => $vol['peso_cobranca_g'],
                    'embalagens' => ['(sem embalagem cadastrada)'], 'avisos' => ['Nenhuma embalagem ativa — usando caixa estimada.']];
        }

        // Ordena embalagens por volume asc e unidades por volume desc (FFD).
        usort($embalagens, static fn($x, $y) => self::volEmb($x) <=> self::volEmb($y));
        usort($unidades, static fn($x, $y) => $y['vol'] <=> $x['vol']);

        // 1) Cabe tudo numa única embalagem?
        foreach ($embalagens as $emb) {
            if (self::caberTudo($unidades, $pesoTotal, $volTotal, $emb)) {
                $vol = self::montarVolume((float)$emb['altura_cm'], (float)$emb['largura_cm'], (float)$emb['comprimento_cm'], $pesoTotal + (int)$emb['peso_g'], $emb['nome']);
                return ['volumes' => [$vol], 'qtd_volumes' => 1, 'peso_real_g' => $pesoTotal + (int)$emb['peso_g'],
                        'peso_cubico_g' => $vol['peso_cubico_g'], 'peso_cobranca_g' => $vol['peso_cobranca_g'],
                        'embalagens' => [$emb['nome']], 'avisos' => $avisos];
            }
        }

        // 2) Múltiplos volumes — usa a MAIOR embalagem como bin padrão.
        $bin = end($embalagens);
        $bins = []; // cada bin: ['peso'=>, 'vol'=>, 'unidades'=>[]]
        foreach ($unidades as $u) {
            if (!self::dimsCabem($u, $bin)) {
                // Unidade maior que a maior embalagem: volume próprio (superdimensionado).
                $vol = self::montarVolume(max($u['a'], (float)$bin['altura_cm']), max($u['l'], (float)$bin['largura_cm']), max($u['c'], (float)$bin['comprimento_cm']), $u['p'] + (int)$bin['peso_g'], $bin['nome']);
                $vol['superdimensionado'] = true;
                $bins[] = ['peso' => $u['p'], 'vol' => $u['vol'], 'unidades' => [$u], 'fixo' => $vol];
                $avisos[] = 'Item maior que a maior embalagem — volume avulso.';
                continue;
            }
            $capVol = self::volEmb($bin);
            $capPeso = $bin['peso_max_g'] !== null ? (int)$bin['peso_max_g'] : PHP_INT_MAX;
            $colocado = false;
            foreach ($bins as &$b) {
                if (isset($b['fixo'])) continue;
                if (($b['vol'] + $u['vol']) <= $capVol && ($b['peso'] + $u['p']) <= $capPeso) {
                    $b['peso'] += $u['p']; $b['vol'] += $u['vol']; $b['unidades'][] = $u; $colocado = true; break;
                }
            }
            unset($b);
            if (!$colocado) {
                $bins[] = ['peso' => $u['p'], 'vol' => $u['vol'], 'unidades' => [$u]];
            }
        }

        $volumes = []; $pesoRealTot = 0; $cubTot = 0; $cobTot = 0; $nomes = [];
        foreach ($bins as $b) {
            if (isset($b['fixo'])) {
                $v = $b['fixo'];
            } else {
                $v = self::montarVolume((float)$bin['altura_cm'], (float)$bin['largura_cm'], (float)$bin['comprimento_cm'], $b['peso'] + (int)$bin['peso_g'], $bin['nome']);
            }
            $volumes[] = $v;
            $pesoRealTot += $v['peso_g'];
            $cubTot += $v['peso_cubico_g'];
            $cobTot += $v['peso_cobranca_g'];
            $nomes[] = $v['embalagem'];
        }

        return ['volumes' => $volumes, 'qtd_volumes' => count($volumes), 'peso_real_g' => $pesoRealTot,
                'peso_cubico_g' => $cubTot, 'peso_cobranca_g' => $cobTot,
                'embalagens' => array_values(array_unique($nomes)), 'avisos' => $avisos];
    }

    private static function montarVolume(float $a, float $l, float $c, int $peso, ?string $embalagem): array
    {
        $cub = self::pesoCubicoG($a, $l, $c);
        return [
            'altura_cm'       => round($a, 2),
            'largura_cm'      => round($l, 2),
            'comprimento_cm'  => round($c, 2),
            'peso_g'          => $peso,
            'peso_cubico_g'   => $cub,
            'peso_cobranca_g' => max($peso, $cub),
            'embalagem'       => $embalagem ?? '(estimada)',
        ];
    }

    private static function volEmb(array $emb): float
    {
        return (float)$emb['altura_cm'] * (float)$emb['largura_cm'] * (float)$emb['comprimento_cm'];
    }

    /** Compara dimensões de forma agnóstica a orientação (triplas ordenadas). */
    private static function dimsCabem(array $u, array $emb): bool
    {
        $du = [$u['a'], $u['l'], $u['c']]; rsort($du);
        $de = [(float)$emb['altura_cm'], (float)$emb['largura_cm'], (float)$emb['comprimento_cm']]; rsort($de);
        return $du[0] <= $de[0] + 1e-6 && $du[1] <= $de[1] + 1e-6 && $du[2] <= $de[2] + 1e-6;
    }

    private static function caberTudo(array $unidades, int $pesoTotal, float $volTotal, array $emb): bool
    {
        if (self::volEmb($emb) + 1e-6 < $volTotal) return false;
        if ($emb['peso_max_g'] !== null && $pesoTotal > (int)$emb['peso_max_g']) return false;
        foreach ($unidades as $u) {
            if (!self::dimsCabem($u, $emb)) return false;
        }
        return true;
    }

    /* =================================================================
       Contexto, destaques, persistência
       ================================================================= */

    private function montarContexto(array $req, array $agg): array
    {
        return array_merge([
            'uf'           => strtoupper((string)($req['uf'] ?? '')),
            'cidade'       => (string)($req['cidade'] ?? ''),
            'canal'        => (string)($req['canal'] ?? 'site'),
            'tipo_cliente' => (string)($req['tipo_cliente'] ?? ''),
            'pais'         => (string)($req['pais'] ?? 'BR'),
            'dia_semana'   => (int)date('N'),
            'hora'         => (int)date('G'),
        ], $agg);
    }

    private function marcarDestaques(array &$opcoes): void
    {
        $barato = null; $rapido = null;
        foreach ($opcoes as $i => $o) {
            if (!empty($o['oculto'])) continue;
            if ($barato === null || ($o['valor_final'] ?? INF) < ($opcoes[$barato]['valor_final'] ?? INF)) $barato = $i;
            if ($rapido === null || ($o['prazo_dias'] ?? PHP_INT_MAX) < ($opcoes[$rapido]['prazo_dias'] ?? PHP_INT_MAX)) $rapido = $i;
        }
        foreach ($opcoes as $i => &$o) {
            $o['mais_barato'] = ($i === $barato);
            $o['mais_rapido'] = ($i === $rapido);
        }
    }

    private function transportadorasAtivas(?array $ids): array
    {
        $sql = "SELECT * FROM log_transportadoras WHERE status = 'ativo'";
        $params = [];
        if (!empty($ids)) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $sql .= " AND id IN ($in)";
            $params = array_map('intval', $ids);
        }
        $sql .= " ORDER BY prioridade ASC, id ASC";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar transportadoras ativas', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    private function persistir(array $req, array $ctx, array $pack, array $opcoes, string $cepOrigem, string $cepDestino, float $valorMerc, bool $seguro): ?int
    {
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare(
                "INSERT INTO log_cotacoes
                 (origem, canal, cep_origem, cep_destino, valor_mercadoria, valor_declarado, peso_total_g, seguro, reversa, pedido_id, usuario_id, payload)
                 VALUES (:origem, :canal, :co, :cd, :vm, :vd, :peso, :seg, :rev, :ped, :usr, :pay)"
            );
            $stmt->execute([
                ':origem' => in_array($req['origem'] ?? '', ['checkout', 'manual', 'api', 'reversa'], true) ? $req['origem'] : 'manual',
                ':canal'  => (string)($ctx['canal'] ?? 'site'),
                ':co'     => substr($cepOrigem, 0, 9),
                ':cd'     => substr($cepDestino, 0, 9),
                ':vm'     => round($valorMerc, 2),
                ':vd'     => round((float)($req['valor_declarado'] ?? ($seguro ? $valorMerc : 0)), 2),
                ':peso'   => (int)$pack['peso_cobranca_g'],
                ':seg'    => $seguro ? 1 : 0,
                ':rev'    => !empty($req['reversa']) ? 1 : 0,
                ':ped'    => !empty($req['pedido_id']) ? (int)$req['pedido_id'] : null,
                ':usr'    => !empty($req['usuario_id']) ? (int)$req['usuario_id'] : null,
                ':pay'    => json_encode(['empacotamento' => $pack, 'contexto' => $ctx], JSON_UNESCAPED_UNICODE),
            ]);
            $id = (int)$this->pdo->lastInsertId();

            $ins = $this->pdo->prepare(
                "INSERT INTO log_cotacao_opcoes
                 (cotacao_id, transportadora_id, servico_codigo, servico_nome, prazo_dias, valor_original, valor_ajuste, valor_final, tipo_postagem, regra_id, avisos, erro)
                 VALUES (:cot, :tid, :sc, :sn, :prazo, :vo, :va, :vf, :tp, :reg, :av, :err)"
            );
            foreach ($opcoes as $o) {
                $ins->execute([
                    ':cot'   => $id,
                    ':tid'   => (int)($o['transportadora_id'] ?? 0) ?: null,
                    ':sc'    => (string)($o['servico_codigo'] ?? ''),
                    ':sn'    => (string)($o['servico_nome'] ?? ''),
                    ':prazo' => (int)($o['prazo_dias'] ?? 0),
                    ':vo'    => isset($o['valor_original']) ? round((float)$o['valor_original'], 2) : null,
                    ':va'    => round((float)($o['valor_ajuste'] ?? 0), 2),
                    ':vf'    => isset($o['valor_final']) ? round((float)$o['valor_final'], 2) : null,
                    ':tp'    => in_array($o['tipo_postagem'] ?? '', ['postagem', 'coleta'], true) ? $o['tipo_postagem'] : null,
                    ':reg'   => !empty($o['regra_id']) ? (int)$o['regra_id'] : null,
                    ':av'    => json_encode($o['regras_aplicadas'] ?? [], JSON_UNESCAPED_UNICODE),
                    ':err'   => $o['erro'] ?? null,
                ]);
            }
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            LogService::error('Falha ao persistir cotação', ['erro' => $e->getMessage()]);
            return null;
        }
    }
}
