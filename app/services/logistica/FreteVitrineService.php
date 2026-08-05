<?php
/**
 * FreteVitrineService — frete para a vitrine (produto/carrinho).
 *
 * Faz a CASCATA de resiliência e o CTA de frete grátis:
 *
 *   cache fresco → API (se o circuito permitir) → cache velho (stale) → fallback
 *
 * A cotação da API é guardada em log_frete_cache (custo caro, com limite). O
 * frete grátis do CTA é calculado por um PROBE puro do MotorRegras (sem tocar a
 * API), então a promessa "adicione e ganhe frete grátis" bate exatamente com o
 * que o checkout aplica.
 *
 * freteGratisEm / montarCta / limiarFreteGratis são PUROS — testáveis sem banco.
 */
class FreteVitrineService
{
    private PDO $pdo;
    private CalculadoraService $calc;
    private FreteCacheService $cache;
    private FreteFallbackService $fallback;
    private FreteSaudeService $saude;
    private MotorRegras $motor;
    private ?array $regrasCache = null;

    private const TTL_COTACAO = 43200;   // 12h — preço da transportadora
    private const TTL_CEP     = 2592000; // 30d — CEP -> cidade (ViaCEP)

    /** Categorias de entrega tratadas como "especiais" (sempre em destaque). */
    public const CATEGORIAS_ESPECIAIS = ['d1', 'tele_entrega', 'expressa_local', 'retirada'];

    public function __construct(
        ?PDO $pdo = null,
        ?CalculadoraService $calc = null,
        ?FreteCacheService $cache = null,
        ?FreteFallbackService $fallback = null,
        ?FreteSaudeService $saude = null,
        ?MotorRegras $motor = null
    ) {
        $this->pdo      = $pdo ?? Database::getInstance()->getConnection();
        $this->calc     = $calc ?? new CalculadoraService($this->pdo);
        $this->cache    = $cache ?? new FreteCacheService($this->pdo);
        $this->fallback = $fallback ?? new FreteFallbackService($this->pdo);
        $this->saude    = $saude ?? new FreteSaudeService($this->pdo);
        $this->motor    = $motor ?? new MotorRegras($this->pdo);
    }

    /* =================================================================
       Cotação da vitrine
       ================================================================= */

    /**
     * @param array $req cep_destino, itens[], valor_mercadoria?, produto_id?, uf?,
     *                   transportadora_ids?, seguro?, cta{subtotal_atual, preco_produto}
     */
    public function cotar(array $req): array
    {
        $cep = preg_replace('/\D+/', '', (string)($req['cep_destino'] ?? '')) ?? '';
        if (strlen($cep) !== 8) return ['ok' => false, 'erro' => 'CEP inválido.'];

        $itens = is_array($req['itens'] ?? null) ? $req['itens'] : [];
        if (!$itens) return ['ok' => false, 'erro' => 'Sem itens para cotar.'];

        // Localidade (cache -> ViaCEP -> cache). Não é fatal se falhar.
        $loc = $this->resolverLocalidade($cep);
        $uf = strtoupper((string)($req['uf'] ?? $loc['uf'] ?? ''));

        // Empacotamento (peso/dimensões) para a chave de cache e o fallback.
        $pack = CalculadoraService::empacotar($itens, $this->embalagensAtivas());
        $pesoG = (int)($pack['peso_cobranca_g'] ?? 0);

        $valorMerc = (float)($req['valor_mercadoria'] ?? 0);
        if ($valorMerc <= 0) {
            foreach ($itens as $it) $valorMerc += (float)($it['valor'] ?? 0) * max(1, (int)($it['quantidade'] ?? 1));
        }

        $chave = FreteCacheService::chave([
            'cep'   => $cep,
            'peso'  => $pesoG,
            'dims'  => array_map(static fn($v) => [(int)$v['altura_cm'], (int)$v['largura_cm'], (int)$v['comprimento_cm']], $pack['volumes'] ?? []),
            'seg'   => !empty($req['seguro']),
            'tr'    => $req['transportadora_ids'] ?? 0,
            'valor' => (int)round($valorMerc), // faixa de R$1 (afeta seguro/frete grátis)
        ]);

        [$opcoes, $origem, $estimativa] = $this->obterCotacao($chave, $cep, $uf, $pesoG, $req, $valorMerc);

        // CTA de frete grátis (probe puro — sem API).
        $ctxBase = $this->contextoBase($req, $cep, $uf, $pesoG, $itens);
        $cta = self::montarCta(
            $ctxBase,
            (float)($req['cta']['subtotal_atual'] ?? 0),
            (float)($req['cta']['preco_produto'] ?? $valorMerc),
            $this->regras()
        );

        return [
            'ok'         => true,
            'opcoes'     => $opcoes,
            'destaques'  => self::destaques($opcoes), // curadoria (mais barato + mais rápido) p/ vitrine/carrinho/checkout
            'origem'     => $origem,       // transportadora | cache | cache_expirado | fallback
            'estimativa' => $estimativa,   // true quando veio do fallback
            'cta'        => $cta,
            'localidade' => $loc['localidade'] ?? null,
            'uf'         => $uf ?: null,
            'peso_g'     => $pesoG,
            'deg'=>$ctxBase
        ];
    }

    private function obterCotacao(string $chave, string $cep, string $uf, int $pesoG, array $req, float $valorMerc): array
    {
        // 1) cache fresco
        $fresh = $this->cache->buscar($chave);
        if ($fresh && !empty($fresh['opcoes'])) return [$fresh['opcoes'], 'cache', false];

        // 2) API (se o circuito permitir)
        if ($this->saude->deveTentar('cotacao')) {
            try {
                $r = $this->calc->cotar(array_merge($req, ['persistir' => false, 'cep_destino' => $cep, 'uf' => $uf, 'valor_mercadoria' => $valorMerc]));
            } catch (\Throwable $e) {
                $r = ['ok' => false];
                LogService::warning('Cotação de vitrine falhou', ['erro' => $e->getMessage()]);
            }
            if (!empty($r['ok']) && !empty($r['opcoes'])) {
                LogService::debug('obterCotacao', $r);
                $pub = $this->paraPublico($r['opcoes'], false);
                if ($pub) {
                    $this->cache->salvar($chave, 'cotacao', $pub, self::TTL_COTACAO, [
                        'cep' => $cep, 'peso_g' => $pesoG,
                        'produto_id' => $req['produto_id'] ?? null, 'origem' => 'transportadora',
                    ]);
                    $this->saude->registrarSucesso('cotacao');
                    return [$pub, 'transportadora', false];
                }
            }
            $this->saude->registrarFalha('cotacao');
        }

        // 3) cache velho (stale-while-error)
        $stale = $this->cache->buscarQualquer($chave);
        if ($stale && !empty($stale['opcoes'])) return [$stale['opcoes'], 'cache_expirado', false];
        

        // 4) fallback (estimativa) — regras ainda se aplicam (frete grátis vale)
        $raw = $this->fallback->estimar($uf, $pesoG);
        if (!$raw) {
            LogService::error('Sem cotação e sem fallback de frete', ['cep' => $cep, 'uf' => $uf, 'peso_g' => $pesoG]);
            return [[], 'indisponivel', true];
        }
        $ctx = $this->contextoBase($req, $cep, $uf, $pesoG, $req['itens'] ?? []) + ['valor_mercadoria' => round($valorMerc, 2)];
        $proc = MotorRegras::processar($ctx, $raw, $this->regras());
        LogService::warning('Frete servido por fallback (estimativa)', ['cep' => $cep, 'uf' => $uf]);
        return [$this->paraPublico($proc['opcoes'] ?? [], true), 'fallback', true];
    }

    /* =================================================================
       CTA de frete grátis (puro)
       ================================================================= */

    public static function freteGratisEm(array $ctxBase, float $valor, array $regras): bool
    {
        if (!$regras) return false;
        $ctx = $ctxBase;
        $ctx['valor_mercadoria'] = round($valor, 2);
        $opt = [[
            'transportadora_id' => 0, 'servico_codigo' => 'CTA', 'servico_nome' => 'CTA',
            'prazo_dias' => 3, 'valor' => 50.0, 'tipo_postagem' => 'postagem',
        ]];
        $res = MotorRegras::processar($ctx, $opt, $regras);
        return !empty($res['frete_gratis']);
    }

    /** Menor valor de carrinho que dá frete grátis (busca binária). null = nunca por valor. */
    public static function limiarFreteGratis(array $ctxBase, array $regras, float $cap = 100000.0, int $iteracoes = 24): ?float
    {
        if (!self::freteGratisEm($ctxBase, $cap, $regras)) return null;
        $lo = 0.0; $hi = $cap;
        for ($i = 0; $i < $iteracoes; $i++) {
            $mid = ($lo + $hi) / 2;
            if (self::freteGratisEm($ctxBase, $mid, $regras)) $hi = $mid; else $lo = $mid;
        }
        return round($hi, 2);
    }

    public static function montarCta(array $ctxBase, float $subtotalAtual, float $precoProduto, array $regras): array
    {
        if (!$regras) return ['tipo' => 'nenhum'];

        // Limiar do frete grátis (para o selo "FRETE GRÁTIS ACIMA DE R$ X").
        $limiar = self::limiarFreteGratis($ctxBase, $regras);
        $extra = $limiar !== null ? ['limiar' => $limiar] : [];

        if (self::freteGratisEm($ctxBase, $subtotalAtual, $regras)) {
            return ['tipo' => 'ja_tem', 'mensagem' => 'Você já tem frete grátis — aproveite!'] + $extra;
        }
        if ($precoProduto > 0 && self::freteGratisEm($ctxBase, $subtotalAtual + $precoProduto, $regras)) {
            return ['tipo' => 'ganha', 'mensagem' => 'Adicione ao carrinho e ganhe frete grátis!'] + $extra;
        }
        if ($limiar !== null && $limiar > $subtotalAtual) {
            $faltam = round($limiar - $subtotalAtual, 2);
            return ['tipo' => 'falta', 'faltam' => $faltam, 'mensagem' => 'Faltam ' . self::real($faltam) . ' para o frete grátis'] + $extra;
        }
        return ['tipo' => 'nenhum'] + $extra;
    }

    /* =================================================================
       Curadoria de opções (reutilizável: vitrine, carrinho, checkout,
       pedido manual). PURO — testável sem banco.
       ================================================================= */

    /**
     * Escolhe as opções em foco: no máximo especiais (D+1/tele-entrega/…) +
     * a mais barata + a mais rápida. Se a mais barata já é a mais rápida,
     * devolve 1 só (tipo 'unica').
     * @return array<int,array{tipo:string,opcao:array}>
     */
    public static function destaques(array $opcoes): array
    {
        if (!$opcoes) return [];
        $eEspecial = static fn($o) => in_array(strtolower((string)($o['categoria'] ?? '')), self::CATEGORIAS_ESPECIAIS, true);
        $especiais = array_values(array_filter($opcoes, $eEspecial));
        $comuns    = array_values(array_filter($opcoes, static fn($o) => !$eEspecial($o)));

        $out = [];
        foreach ($especiais as $e) $out[] = ['tipo' => 'especial', 'opcao' => $e];

        if ($comuns) {
            $valor  = static fn($o) => !empty($o['frete_gratis']) ? 0.0 : (float)($o['valor'] ?? 0);
            $barato = self::menorPor($comuns, static fn($o) => [$valor($o), (int)($o['prazo_dias'] ?? 0)]);
            $rapido = self::menorPor($comuns, static fn($o) => [(int)($o['prazo_dias'] ?? 0), $valor($o)]);
            if ($barato === $rapido) {
                $out[] = ['tipo' => 'unica', 'opcao' => $barato];
            } else {
                $out[] = ['tipo' => 'barato', 'opcao' => $barato];
                $out[] = ['tipo' => 'rapido', 'opcao' => $rapido];
            }
        }
        return $out;
    }

    /** Retorna o elemento de menor chave (chave = array comparável). */
    private static function menorPor(array $itens, callable $chave): array
    {
        $melhor = $itens[0];
        $melhorK = $chave($melhor);
        foreach ($itens as $o) {
            $k = $chave($o);
            if ($k < $melhorK) { $melhor = $o; $melhorK = $k; }
        }
        return $melhor;
    }

    /* =================================================================
       Internos
       ================================================================= */

    private function paraPublico(array $opcoes, bool $estimativa): array
    {
        $vis = array_values(array_filter($opcoes, static fn($o) => empty($o['oculto'])));
        // destaques (mais barato / mais rápido) para a UI
        $barato = null; $rapido = null;
        foreach ($vis as $i => $o) {
            $v = $o['valor_final'] ?? $o['valor'] ?? INF;
            $p = $o['prazo_dias'] ?? PHP_INT_MAX;
            if ($barato === null || $v < ($vis[$barato]['valor_final'] ?? $vis[$barato]['valor'] ?? INF)) $barato = $i;
            if ($rapido === null || $p < ($vis[$rapido]['prazo_dias'] ?? PHP_INT_MAX)) $rapido = $i;
        }
        $out = [];
        foreach ($vis as $i => $o) {
            $out[] = [
                'transportadora' => $o['transportadora_nome'] ?? 'Estimativa',
                'servico'        => $o['servico_nome'] ?? $o['servico_codigo'] ?? '',
                'servico_codigo' => $o['servico_codigo'] ?? '',
                'categoria'      => strtolower((string)($o['categoria'] ?? 'padrao')),
                'prazo_dias'     => (int)($o['prazo_dias'] ?? 0),
                'valor'          => round((float)($o['valor_final'] ?? $o['valor'] ?? 0), 2),
                'frete_gratis'   => !empty($o['frete_gratis']),
                'mais_barato'    => $i === $barato,
                'mais_rapido'    => $i === $rapido,
                'estimativa'     => $estimativa,
            ];
        }
        // Mais barato sempre primeiro (a vitrine destaca a opção[0]).
        usort($out, static fn($a, $b) => ($a['valor'] ?? INF) <=> ($b['valor'] ?? INF));
        return $out;
    }

    private function contextoBase(array $req, string $cep, string $uf, int $pesoG, array $itens): array
    {
        $cats = []; $marcas = []; $prods = []; $qtd = 0;
        foreach ($itens as $it) {
            $qtd += max(1, (int)($it['quantidade'] ?? 1));
            if (!empty($it['categoria_id'])) $cats[] = $it['categoria_id'];
            if (!empty($it['marca_id']))     $marcas[] = $it['marca_id'];
            if (!empty($it['produto_id']))   $prods[] = $it['produto_id'];
        }
        return [
            'uf'               => $uf,
            'cidade'           => (string)($req['cidade'] ?? ''),
            'canal'            => (string)($req['canal'] ?? 'site'),
            'tipo_cliente'     => (string)($req['tipo_cliente'] ?? ''),
            'pais'             => 'BR',
            'dia_semana'       => (int)date('N'),
            'hora'             => (int)date('G'),
            'cep_destino'      => $cep,
            'peso_total_g'     => $pesoG,
            'quantidade_total' => $qtd,
            'categorias'       => $cats,
            'marcas'           => $marcas,
            'produtos'         => $prods,
        ];
    }

    private function resolverLocalidade(string $cep): array
    {
        $cached = $this->cache->cep($cep);
        if ($cached) return $cached;
        $loc = $this->fetchViaCep($cep);
        if ($loc) {
            $this->cache->salvarCep($cep, $loc, self::TTL_CEP);
            return $loc;
        }
        return ['localidade' => null, 'uf' => null];
    }

    private function fetchViaCep(string $cep): ?array
    {
        $cep = preg_replace('/\D+/', '', $cep) ?? '';
        if (strlen($cep) !== 8) return null;
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
            $res = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/", false, $ctx);
            if (!$res) return null;
            $d = json_decode($res, true);
            if (!$d || !empty($d['erro'])) return null;
            return ['localidade' => $d['localidade'] ?? null, 'uf' => $d['uf'] ?? null, 'bairro' => $d['bairro'] ?? null];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function embalagensAtivas(): array
    {
        try {
            return (new EmbalagemService($this->pdo))->ativas();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function regras(): array
    {
        if ($this->regrasCache === null) {
            try { $this->regrasCache = $this->motor->regrasAtivas(); }
            catch (\Throwable $e) { $this->regrasCache = []; }
        }
        return $this->regrasCache;
    }

    private static function real(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }
}
