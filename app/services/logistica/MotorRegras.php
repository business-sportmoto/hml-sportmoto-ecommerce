<?php
/**
 * MotorRegras — avaliação de regras de frete sobre as opções cotadas.
 *
 * Núcleo (processar) é PURO: recebe contexto + opções + regras e devolve as
 * opções ajustadas, sem tocar banco. A instância (avaliar) só carrega as
 * regras ativas e delega ao núcleo — o que mantém a lógica 100% testável.
 *
 * Modelo:
 *  - CONDIÇÕES decidem se a regra dispara (valor, peso, UF, categoria, CEP…),
 *    em AND. Campos 'transportadora'/'modalidade' NÃO gatilham: são ESCOPO
 *    (definem quais opções recebem o efeito).
 *  - Prioridade menor primeiro. Regra exclusiva (acumulativa=0) para no
 *    primeiro match; acumulativas empilham.
 *  - AÇÕES: frete_gratis, subsidio_max_valor/pct (teto do subsídio → "cobrar
 *    a diferença"), desconto_pct, desconto_fixo, acrescimo, prazo_adicional,
 *    ocultar_servicos, bloquear_frete_gratis.
 */
class MotorRegras
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /** Carrega regras ativas (com condições) e avalia sobre as opções. */
    public function avaliar(array $contexto, array $opcoes): array
    {
        return self::processar($contexto, $opcoes, $this->regrasAtivas());
    }

    /** Regras ativas + suas condições, para o núcleo consumir. */
    public function regrasAtivas(): array
    {
        try {
            $rs = $this->pdo->query("SELECT * FROM log_regras WHERE ativa = 1 ORDER BY prioridade ASC, id ASC")
                            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$rs) return [];
            $ids = array_column($rs, 'id');
            $in = implode(',', array_map('intval', $ids));
            $conds = $this->pdo->query("SELECT * FROM log_regra_condicoes WHERE regra_id IN ($in)")
                               ->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $porRegra = [];
            foreach ($conds as $c) {
                $c['valor'] = json_decode((string)$c['valor'], true);
                $porRegra[$c['regra_id']][] = $c;
            }
            foreach ($rs as &$r) {
                $r['acoes'] = json_decode((string)$r['acoes'], true) ?: [];
                $r['condicoes'] = $porRegra[$r['id']] ?? [];
            }
            return $rs;
        } catch (\Throwable $e) {
            LogService::error('Falha ao carregar regras de frete', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /* =================================================================
       NÚCLEO PURO
       ================================================================= */

    /**
     * @param array $ctx    contexto do carrinho/cotação
     * @param array $opcoes opções cruas das transportadoras (valor = custo já com margem)
     * @param array $regras regras ativas normalizadas (com 'acoes' e 'condicoes')
     * @return array{opcoes:array,frete_gratis:bool,bloqueado:bool,regras_aplicadas:array}
     */
    public static function processar(array $ctx, array $opcoes, array $regras): array
    {
        usort($regras, static fn($a, $b) => ((int)($a['prioridade'] ?? 100)) <=> ((int)($b['prioridade'] ?? 100)));

        // Passo 1: seleciona regras que disparam (respeita agendamento e exclusividade).
        $agora = time();
        $aplicadas = [];
        foreach ($regras as $r) {
            if (isset($r['ativa']) && !$r['ativa']) continue;
            if (!empty($r['inicio_em']) && strtotime((string)$r['inicio_em']) > $agora) continue;
            if (!empty($r['fim_em']) && strtotime((string)$r['fim_em']) < $agora) continue;
            if (!self::regraDispara($r, $ctx)) continue;
            $aplicadas[] = $r;
            if (empty($r['acumulativa'])) break; // exclusiva: para no primeiro match
        }

        $mercadoria = (float)($ctx['valor_mercadoria'] ?? 0);

        // Passo 2: aplica efeitos por opção (respeitando o escopo de cada regra).
        $out = [];
        $idsGlobais = [];
        foreach ($opcoes as $op) {
            $base = (float)($op['valor'] ?? $op['valor_original'] ?? 0);
            $op['valor_original'] = round($base, 2);
            $op['prazo_dias'] = (int)($op['prazo_dias'] ?? 0);
            $op['oculto'] = false;
            $op['erro'] = $op['erro'] ?? null;

            $freteGratisReq = false; $bloq = false; $gratisMaisBaratoReq = false;
            $tetoV = null; $tetoP = null;
            $dpct = 0.0; $dfix = 0.0; $acr = 0.0; $prazo = 0;
            $ocultar = []; $ids = [];

            foreach ($aplicadas as $r) {
                if (!self::opcaoNoEscopo($r, $op)) continue;
                $a = self::normalizarAcoes($r['acoes'] ?? []);
                if ($a['frete_gratis'])           $freteGratisReq = true;
                if ($a['frete_gratis_mais_barato']) { $freteGratisReq = true; $gratisMaisBaratoReq = true; }
                if ($a['bloquear_frete_gratis'])  $bloq = true;
                if ($a['subsidio_max_valor'] !== null) $tetoV = self::menorNaoNulo($tetoV, $a['subsidio_max_valor']);
                if ($a['subsidio_max_pct']   !== null) $tetoP = self::menorNaoNulo($tetoP, $a['subsidio_max_pct']);
                $dpct += $a['desconto_pct'];
                $dfix += $a['desconto_fixo'];
                $acr  += $a['acrescimo'];
                $prazo += $a['prazo_adicional'];
                $ocultar = array_merge($ocultar, $a['ocultar_servicos']);
                $ids[] = (int)($r['id'] ?? 0);
            }

            $ids = array_values(array_unique(array_filter($ids)));
            $op['regras_aplicadas'] = $ids;
            $op['regra_id'] = $ids[0] ?? null;
            $idsGlobais = array_merge($idsGlobais, $ids);

            // Ocultar serviço?
            if (self::servicoOculto($op, $ocultar)) {
                $op['oculto'] = true;
                $op['erro'] = 'Ocultado por regra';
                $op['valor_final'] = null;
                $op['valor_ajuste'] = 0.0;
                $out[] = $op;
                continue;
            }

            $final = $base;
            $freteGratis = $freteGratisReq && !$bloq;
            if ($freteGratis) {
                $teto = self::resolverTeto($tetoV, $tetoP, $mercadoria);
                $subsidio = $teto === null ? $base : min($base, $teto);
                $final = $base - $subsidio;               // cobra só a diferença acima do teto
            } else {
                $final -= $final * ($dpct / 100);
                $final -= $dfix;
                $final += $acr;
            }
            $final = max(0.0, round($final, 2));

            $op['valor_final']  = $final;
            $op['valor_ajuste'] = round($final - $base, 2); // negativo = desconto/subsídio
            $op['prazo_dias']   = max(0, $op['prazo_dias'] + $prazo);
            $op['frete_gratis'] = $freteGratis && $final <= 0.0001;

            // Modo "frete grátis só na mais barata": guarda o preço cheio para
            // restaurar as opções que NÃO forem a mais barata (pós-processamento).
            if ($gratisMaisBaratoReq) {
                $semG = $base - $base * ($dpct / 100) - $dfix + $acr;
                $op['_valor_sem_gratis'] = max(0.0, round($semG, 2));
                $op['_so_mais_barato'] = true;
            }
            $out[] = $op;
        }

        // Aplica o modo "só na mais barata" (no-op quando nenhuma ação pediu).
        self::freteGratisSoMaisBarato($out);

        return [
            'opcoes'           => $out,
            'frete_gratis'     => (bool)array_filter($out, static fn($o) => !empty($o['frete_gratis'])),
            'bloqueado'        => false,
            'regras_aplicadas' => array_values(array_unique(array_map(static fn($r) => (int)($r['id'] ?? 0), $aplicadas))),
        ];
    }

    /**
     * Frete grátis apenas na opção de MENOR custo bruto.
     * Quando uma ação usa `frete_gratis_mais_barato`, todas as opções no escopo
     * entram como grátis; aqui mantemos grátis só a mais barata e devolvemos as
     * demais ao preço cheio (respeitando eventuais descontos já calculados).
     */
    private static function freteGratisSoMaisBarato(array &$out): void
    {
        $cands = [];
        foreach ($out as $i => $o) {
            if (empty($o['oculto']) && !empty($o['_so_mais_barato']) && !empty($o['frete_gratis'])) {
                $cands[] = $i;
            }
        }
        if (count($cands) > 1) {
            usort($cands, static fn($a, $b) => ($out[$a]['valor_original'] ?? INF) <=> ($out[$b]['valor_original'] ?? INF));
            $manter = $cands[0]; // a mais barata continua grátis
            foreach ($cands as $i) {
                if ($i === $manter) continue;
                $base  = (float)($out[$i]['valor_original'] ?? 0);
                $preco = (float)($out[$i]['_valor_sem_gratis'] ?? $base);
                $out[$i]['valor_final']  = round($preco, 2);
                $out[$i]['valor_ajuste'] = round($preco - $base, 2);
                $out[$i]['frete_gratis'] = false;
            }
        }
        foreach ($out as &$o) { unset($o['_so_mais_barato'], $o['_valor_sem_gratis']); }
    }

    /* =================================================================
       Avaliação de condição / escopo (puro)
       ================================================================= */

    public static function regraDispara(array $regra, array $ctx): bool
    {
        // Agrupa condições de GATILHO por campo. Regras de negócio:
        //   - Igualdades no MESMO campo são OU entre si
        //     (uf = RS, uf = SC, uf = PR  =>  uf ∈ {RS, SC, PR}).
        //   - As demais (ranges >=,<=, !=, between, not_in) continuam E.
        //   - Campos diferentes: E entre os grupos.
        $grupos = [];
        foreach (($regra['condicoes'] ?? []) as $c) {
            $campo = (string)($c['campo'] ?? '');
            if ($campo === '' || $campo === 'transportadora' || $campo === 'modalidade') continue; // escopo, não gatilho
            $grupos[$campo][] = ['op' => (string)($c['operador'] ?? '='), 'valor' => $c['valor'] ?? null];
        }

        foreach ($grupos as $campo => $conds) {
            $igualdades = [];
            $outras = [];
            foreach ($conds as $c) {
                if (self::operadorDeIgualdade($c['op'])) $igualdades[] = $c; else $outras[] = $c;
            }
            // Igualdades do campo: basta UMA bater (OU).
            if ($igualdades) {
                $alguma = false;
                foreach ($igualdades as $c) {
                    if (self::condicaoBate($campo, $c['op'], $c['valor'], $ctx)) { $alguma = true; break; }
                }
                if (!$alguma) return false;
            }
            // Demais do campo: TODAS têm que bater (E).
            foreach ($outras as $c) {
                if (!self::condicaoBate($campo, $c['op'], $c['valor'], $ctx)) return false;
            }
        }
        return true;
    }

    /** Operadores tratados como "igualdade/pertencimento" (agrupados em OU). */
    private static function operadorDeIgualdade(string $op): bool
    {
        return in_array($op, ['=', '==', 'igual', 'eq', 'in', 'contem'], true);
    }

    public static function opcaoNoEscopo(array $regra, array $op): bool
    {
        $temEscopo = false;
        foreach (($regra['condicoes'] ?? []) as $c) {
            $campo = (string)($c['campo'] ?? '');
            if ($campo !== 'transportadora' && $campo !== 'modalidade') continue;
            $temEscopo = true;
            $alvo = array_map('strval', self::paraLista($c['valor'] ?? []));
            if ($campo === 'transportadora') {
                $vals = [(string)($op['transportadora_id'] ?? ''), (string)($op['transportadora_slug'] ?? '')];
            } else {
                $alvo = array_map('mb_strtolower', $alvo);
                $vals = [mb_strtolower((string)($op['tipo_postagem'] ?? $op['modalidade'] ?? ''))];
            }
            $match = count(array_intersect($vals, $alvo)) > 0;
            $oper = (string)($c['operador'] ?? 'in');
            if ($oper === '!=' || $oper === 'not_in') $match = !$match;
            if (!$match) return false;
        }
        return true; // sem condição de escopo => aplica a todas
    }

    public static function condicaoBate(string $campo, string $op, $valorRegra, array $ctx): bool
    {
        [$tipo, $ctxVal] = self::valorContexto($campo, $ctx);

        if ($tipo === 'lista') {
            $alvo = array_map('strval', self::paraLista($valorRegra));
            $atual = array_map('strval', is_array($ctxVal) ? $ctxVal : [$ctxVal]);
            $inter = array_intersect($atual, $alvo);
            return match ($op) {
                'in', 'contem', '=' => count($inter) > 0,
                'not_in', '!='      => count($inter) === 0,
                default             => false,
            };
        }

        if ($tipo === 'numero') {
            $n = (float)$ctxVal;
            if ($op === 'between') {
                $a = self::paraLista($valorRegra);
                return isset($a[0], $a[1]) && $n >= (float)$a[0] && $n <= (float)$a[1];
            }
            if ($op === 'in') {
                foreach (self::paraLista($valorRegra) as $v) { if ($n == (float)$v) return true; }
                return false;
            }
            $alvo = is_array($valorRegra) ? (float)($valorRegra[0] ?? 0) : (float)$valorRegra;
            return match ($op) {
                '='  => $n == $alvo,
                '!=' => $n != $alvo,
                '>'  => $n >  $alvo,
                '<'  => $n <  $alvo,
                '>=' => $n >= $alvo,
                '<=' => $n <= $alvo,
                default => false,
            };
        }

        // texto
        $s = mb_strtolower(trim((string)$ctxVal));
        $lista = array_map(static fn($v) => mb_strtolower(trim((string)$v)), self::paraLista($valorRegra));
        return match ($op) {
            '='      => in_array($s, $lista, true),
            '!='     => !in_array($s, $lista, true),
            'in'     => in_array($s, $lista, true),
            'not_in' => !in_array($s, $lista, true),
            'contem' => $s !== '' && $s !== null && str_contains($s, (string)($lista[0] ?? "\0")),
            default  => false,
        };
    }

    /** Mapeia o campo da condição para [tipo, valor] do contexto. */
    private static function valorContexto(string $campo, array $ctx): array
    {
        return match ($campo) {
            'valor', 'valor_min', 'valor_max' => ['numero', $ctx['valor_mercadoria'] ?? 0],
            'peso'         => ['numero', $ctx['peso_total_g'] ?? 0],
            'quantidade'   => ['numero', $ctx['quantidade_total'] ?? 0],
            'dia_semana'   => ['numero', $ctx['dia_semana'] ?? 0],
            'hora'         => ['numero', $ctx['hora'] ?? 0],
            'cep_faixa'    => ['numero', (int)preg_replace('/\D/', '', (string)($ctx['cep_destino'] ?? '0'))],
            'categoria'    => ['lista',  $ctx['categorias'] ?? []],
            'marca'        => ['lista',  $ctx['marcas'] ?? []],
            'produto'      => ['lista',  $ctx['produtos'] ?? []],
            'regiao'       => ['texto',  self::regiaoDaUf((string)($ctx['uf'] ?? ''))],
            'uf'           => ['texto',  $ctx['uf'] ?? ''],
            'cidade'       => ['texto',  $ctx['cidade'] ?? ''],
            'canal'        => ['texto',  $ctx['canal'] ?? ''],
            'tipo_cliente' => ['texto',  $ctx['tipo_cliente'] ?? ''],
            'pais'         => ['texto',  $ctx['pais'] ?? 'BR'],
            default        => ['texto',  $ctx[$campo] ?? ''],
        };
    }

    public static function regiaoDaUf(string $uf): string
    {
        static $mapa = [
            'AC'=>'N','AM'=>'N','AP'=>'N','PA'=>'N','RO'=>'N','RR'=>'N','TO'=>'N',
            'AL'=>'NE','BA'=>'NE','CE'=>'NE','MA'=>'NE','PB'=>'NE','PE'=>'NE','PI'=>'NE','RN'=>'NE','SE'=>'NE',
            'DF'=>'CO','GO'=>'CO','MT'=>'CO','MS'=>'CO',
            'ES'=>'SE','MG'=>'SE','RJ'=>'SE','SP'=>'SE',
            'PR'=>'S','RS'=>'S','SC'=>'S',
        ];
        return $mapa[strtoupper(trim($uf))] ?? '';
    }

    /* =================================================================
       Ações / helpers (puro)
       ================================================================= */

    public static function normalizarAcoes($acoes): array
    {
        $a = is_array($acoes) ? $acoes : (json_decode((string)$acoes, true) ?: []);
        $num = static fn($k) => (isset($a[$k]) && $a[$k] !== '' && $a[$k] !== null) ? (float)$a[$k] : null;
        return [
            'frete_gratis'          => !empty($a['frete_gratis']),
            'frete_gratis_mais_barato' => !empty($a['frete_gratis_mais_barato']),
            'bloquear_frete_gratis' => !empty($a['bloquear_frete_gratis']),
            'desconto_pct'          => (float)($a['desconto_pct'] ?? 0),
            'desconto_fixo'         => (float)($a['desconto_fixo'] ?? 0),
            'acrescimo'             => (float)($a['acrescimo'] ?? 0),
            'prazo_adicional'       => (int)($a['prazo_adicional'] ?? 0),
            'subsidio_max_valor'    => $num('subsidio_max_valor'),
            'subsidio_max_pct'      => $num('subsidio_max_pct'),
            'ocultar_servicos'      => self::paraLista($a['ocultar_servicos'] ?? []),
        ];
    }

    private static function resolverTeto(?float $valor, ?float $pct, float $mercadoria): ?float
    {
        $tetos = [];
        if ($valor !== null) $tetos[] = $valor;
        if ($pct !== null)   $tetos[] = $mercadoria * $pct / 100;
        return $tetos ? min($tetos) : null;
    }

    private static function servicoOculto(array $op, array $ocultar): bool
    {
        if (!$ocultar) return false;
        $chaves = array_map('strval', $ocultar);
        $alvos = [
            (string)($op['servico_codigo'] ?? ''),
            (string)($op['transportadora_slug'] ?? ''),
            't:' . (string)($op['transportadora_id'] ?? ''),
        ];
        foreach ($alvos as $al) {
            if ($al !== '' && $al !== 't:' && in_array($al, $chaves, true)) return true;
        }
        return false;
    }

    private static function menorNaoNulo(?float $a, float $b): float
    {
        return $a === null ? $b : min($a, $b);
    }

    /** Normaliza qualquer valor de regra/condição para lista. */
    private static function paraLista($v): array
    {
        if (is_array($v)) return array_values($v);
        if ($v === null || $v === '') return [];
        if (is_string($v)) {
            $dec = json_decode($v, true);
            if (is_array($dec)) return array_values($dec);
            if (str_contains($v, ',')) return array_map('trim', explode(',', $v));
        }
        return [$v];
    }
}