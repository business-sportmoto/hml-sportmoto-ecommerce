<?php
declare(strict_types=1);

/**
 * IAAgenteGateway — a única porta entre um agente de IA e os dados.
 *
 * É o "Tool Gateway" da spec (§20): o modelo pede uma ferramenta pelo
 * nome, com parâmetros; esta classe valida o nome contra a whitelist do
 * agente, valida os parâmetros contra um schema fechado, consulta o
 * cache, chama o BiService, poda o retorno, remove dado pessoal, mede e
 * registra. O modelo NUNCA escreve SQL, NUNCA escolhe coluna, NUNCA
 * decide o limite de linhas — tudo isso mora aqui, em PHP.
 *
 * Dois chamadores usam a mesma porta:
 *   · o PHP, para pré-carregar as ferramentas padrão da página no
 *     primeiro turno (o caso comum resolve em UMA chamada ao modelo);
 *   · o modelo, via tool_use, quando a pergunta exige aprofundar.
 *
 * Só lê o BiService, que só lê views bi_*. Não existe caminho de escrita
 * — não por disciplina, por construção.
 */
class IAAgenteGateway
{
    public const PERIODOS = ['7d', '30d', '90d', '12m'];

    /**
     * Domínio de cada ferramenta, para a tela do catálogo agrupar as
     * checkboxes. É rótulo de apresentação — a whitelist real é a lista
     * gravada no agente, não este mapa.
     */
    public const DOMINIO_DA_FERRAMENTA = [
        'consultar_faturamento' => 'Financeiro', 'consultar_vendas' => 'Financeiro',
        'consultar_margem' => 'Financeiro', 'consultar_descontos' => 'Financeiro',
        'consultar_pagamentos' => 'Financeiro', 'consultar_devolucoes_cancelamentos' => 'Financeiro',
        'consultar_metas' => 'Financeiro', 'consultar_projecao' => 'Financeiro',
        'consultar_curva_abc' => 'Produtos', 'consultar_tendencia_produtos' => 'Produtos',
        'consultar_frete' => 'Logística',
        'consultar_giro_estoque' => 'Estoque', 'consultar_estoque_parado' => 'Estoque', 'consultar_ruptura' => 'Estoque',
        'consultar_funil' => 'Conversão', 'consultar_dispositivos' => 'Conversão', 'consultar_carrinhos' => 'Conversão',
        'consultar_clientes' => 'Clientes', 'consultar_geografia' => 'Clientes',
        'consultar_alertas' => 'Todos', 'consultar_saude_dados' => 'Todos',
        'perguntar_agente' => 'Coordenação',
    ];

    /** Catálogo de agentes (ia_agentes), carregado uma vez por requisição. */
    private static ?array $agentes = null;

    /**
     * Delegação a outro agente — a ferramenta `perguntar_agente` do Diretor.
     * O gateway valida nome/whitelist/schema como em qualquer ferramenta;
     * quem EXECUTA é o IAAgenteService, que registra o callback por
     * pergunta (e o restaura ao sair, porque a execução é reentrante: o
     * analista consultado roda o mesmo caminho).
     * @var callable|null fn(array $params): array{ok:bool, dados?:array, erro?:string, mensagem?:string}
     */
    private $delegacao = null;

    /** Consultas a analistas por pergunta. Acima disso o Diretor consolida com o que tem. */
    public const DELEGACOES_MAX = 4;

    /** Teto de linhas que uma ferramenta devolve. O modelo não pode pedir mais. */
    private const LIMITE_MAX    = 20;
    private const LIMITE_PADRAO = 10;

    /** Ferramenta acima disto vira warning no log (spec §4: registrar queries lentas). */
    private const AVISO_LENTO_MS = 2000;

    /**
     * Chaves que nunca saem daqui, em qualquer profundidade (spec §26).
     * `nome` não está na lista porque produto, marca e categoria também
     * têm nome — ver semDadoPessoal(), que o remove só em linha de pessoa.
     */
    private const CHAVES_PESSOAIS = [
        'email', 'e_mail', 'cpf', 'cnpj', 'rg', 'telefone', 'celular', 'whatsapp',
        'endereco', 'logradouro', 'numero_endereco', 'cep', 'ip', 'cliente_nome',
        'nome_cliente', 'sobrenome', 'senha', 'senha_hash', 'user_agent',
    ];

    /**
     * As páginas do painel que um agente pode atender (data-pwb-view).
     * Quem atende cada uma é decidido no catálogo (ia_agentes.paginas):
     * uma página, um agente. A Central de IA ('ai') fica de fora.
     */
    public const PAGINAS = [
        'overview' => 'Visão Geral', 'orders' => 'Pedidos', 'products' => 'Produtos',
        'marcas' => 'Marcas', 'categorias' => 'Categorias', 'rentabilidade' => 'Rentabilidade',
        'cupons' => 'Cupons', 'pagamentos' => 'Pagamentos', 'posvenda' => 'Pós-venda',
        'metas' => 'Metas', 'central' => 'Central executiva',
        'stock' => 'Estoque', 'logistica' => 'Logística',
        'customers' => 'Clientes', 'geo' => 'Geografia', 'access' => 'Funil',
        'clips' => 'Clips', 'compartilhados' => 'Carrinhos compartilhados', 'faq' => 'Perguntas e IA',
    ];

    /**
     * O que o PHP pré-carrega ao abrir o agente numa página. `periodo`
     * é preenchido em tempo de execução com o filtro do painel. É
     * propriedade da PÁGINA, não do agente: o agente que atende a página
     * recebe o que dela passa pela sua whitelist.
     */
    private const PADRAO_DA_PAGINA = [
        'overview'       => [['consultar_faturamento'], ['consultar_alertas']],
        'central'        => [['consultar_alertas'], ['consultar_faturamento']],
        'orders'         => [['consultar_faturamento'], ['consultar_vendas', ['agrupar' => 'canal']], ['consultar_pagamentos']],
        'products'       => [['consultar_vendas', ['agrupar' => 'produto']], ['consultar_curva_abc', ['dimensao' => 'produto']]],
        'marcas'         => [['consultar_vendas', ['agrupar' => 'marca']], ['consultar_margem', ['agrupar' => 'marca']]],
        'categorias'     => [['consultar_vendas', ['agrupar' => 'categoria']], ['consultar_margem', ['agrupar' => 'categoria']]],
        'rentabilidade'  => [['consultar_faturamento'], ['consultar_margem', ['agrupar' => 'marca']], ['consultar_saude_dados']],
        'cupons'         => [['consultar_descontos']],
        'pagamentos'     => [['consultar_pagamentos']],
        'posvenda'       => [['consultar_devolucoes_cancelamentos']],
        'metas'          => [['consultar_metas'], ['consultar_faturamento']],
        'stock'          => [['consultar_ruptura'], ['consultar_giro_estoque']],
        'logistica'      => [['consultar_frete']],
        'customers'      => [['consultar_clientes']],
        'geo'            => [['consultar_geografia', ['nivel' => 'uf']]],
        'access'         => [['consultar_funil'], ['consultar_dispositivos']],
        'clips'          => [['consultar_alertas']],
        'compartilhados' => [['consultar_carrinhos']],
        'faq'            => [['consultar_alertas']],
    ];

    private BiService $bi;

    /** @var array<string, array{descricao:string, propriedades:array, ttl:int, fn:Closure}> */
    private array $catalogo;

    public function __construct(?BiService $bi = null)
    {
        $this->bi       = $bi ?? new BiService();
        $this->catalogo = $this->montarCatalogo();
    }

    // ════════════════════════════════════════════════════
    // CATÁLOGO
    // ════════════════════════════════════════════════════

    /** @return string[] */
    public function nomes(): array
    {
        return array_keys($this->catalogo);
    }

    public function existe(string $nome): bool
    {
        return isset($this->catalogo[$nome]);
    }

    /* ── Catálogo de agentes (ia_agentes) ─────────────────────────────── */

    /**
     * Agentes ativos, por código. Vem do banco (tela da Central), com
     * cache por requisição. Sem a tabela (ambiente não migrado) devolve
     * vazio: o painel diz "sem agente", o diagnóstico diz o que rodar.
     * @return array<string, array> codigo => linha do catálogo
     */
    public static function agentes(): array
    {
        if (self::$agentes !== null) return self::$agentes;
        try {
            $lista = (new IAAgente())->listarAtivos();
        } catch (\PDOException $e) {
            if (($e->getCode() ?: '') !== '42S02') throw $e;
            $lista = [];
        }
        self::$agentes = [];
        foreach ($lista as $a) self::$agentes[$a['codigo']] = $a;
        return self::$agentes;
    }

    /** @return string[] códigos dos agentes ativos */
    public static function agentesAtivos(): array
    {
        return array_keys(self::agentes());
    }

    /** Depois de salvar na tela (e nos testes). */
    public static function limparCacheAgentes(): void
    {
        self::$agentes = null;
    }

    public static function agenteDaPagina(string $pagina): ?string
    {
        return self::mapaPaginas()[$pagina] ?? null;
    }

    /** @return array<string,string> pagina => agente, para a view emitir ao JS */
    public static function mapaPaginas(): array
    {
        $out = [];
        foreach (self::agentes() as $codigo => $a) {
            foreach ($a['paginas'] as $p) {
                if (!isset($out[$p])) $out[$p] = $codigo;   // primeira na ordem vence
            }
        }
        return $out;
    }

    /**
     * O catálogo de ferramentas para a tela: nome, descrição (a mesma que
     * o modelo lê) e domínio. Sem as closures.
     * @return array<string, array{descricao:string, dominio:string, parametros:string[]}>
     */
    public function catalogoPublico(): array
    {
        $out = [];
        foreach ($this->catalogo as $nome => $f) {
            $out[$nome] = [
                'descricao'  => $f['descricao'],
                'dominio'    => self::DOMINIO_DA_FERRAMENTA[$nome] ?? 'Outros',
                'parametros' => array_keys($f['propriedades']),
            ];
        }
        return $out;
    }

    /**
     * pagina => nomes das ferramentas pré-carregadas. Para a tela mostrar
     * "Consultando faturamento…" enquanto espera — o que de fato está
     * acontecendo no servidor, não uma animação genérica.
     * @return array<string, string[]>
     */
    public static function mapaPadroes(): array
    {
        $out = [];
        foreach (self::PADRAO_DA_PAGINA as $pagina => $itens) {
            $out[$pagina] = array_values(array_unique(array_map(fn($i) => $i[0], $itens)));
        }
        return $out;
    }

    /**
     * Ferramentas padrão de uma página, já com o período do painel —
     * só nas que aceitam período: mandar `periodo` para uma ferramenta
     * sem esse parâmetro é recusado pelo validador (parâmetro
     * desconhecido é erro, não é ignorado), e a pré-carga sumiria em
     * silêncio.
     * @return array<int, array{0:string, 1:array}>
     */
    public function padraoDaPagina(string $pagina, string $periodo): array
    {
        $out = [];
        foreach (self::PADRAO_DA_PAGINA[$pagina] ?? [['consultar_alertas']] as $item) {
            $params = $item[1] ?? [];
            if (isset($this->catalogo[$item[0]]['propriedades']['periodo'])) {
                $params += ['periodo' => $periodo];
            }
            $out[] = [$item[0], $params];
        }
        return $out;
    }

    /**
     * Definições no formato `tools` da Messages API, só das ferramentas
     * da whitelist. Schema fechado (`additionalProperties: false`, todos
     * os campos obrigatórios) para `strict: true` — o modelo não consegue
     * inventar parâmetro.
     */
    /** Registra (ou limpa) o executor de `perguntar_agente`; devolve o anterior, para restaurar. */
    public function registrarDelegacao(?callable $fn): ?callable
    {
        $anterior = $this->delegacao;
        $this->delegacao = $fn;
        return $anterior;
    }

    /**
     * @param string[]    $whitelist   ferramentas do agente
     * @param string|null $agenteAtual quem pergunta — sai do enum de `perguntar_agente`
     *                                 (o Diretor não consulta a si mesmo)
     */
    public function definicoes(array $whitelist, ?string $agenteAtual = null): array
    {
        $out = [];
        foreach ($whitelist as $nome) {
            if (!isset($this->catalogo[$nome])) continue;
            $f = $this->catalogo[$nome];

            $props = [];
            $vazio = false;
            foreach ($f['propriedades'] as $chave => $p) {
                $prop = ['type' => $p['type'], 'description' => $p['descricao']];
                if (isset($p['enum'])) $prop['enum'] = $p['enum'];
                if (isset($p['enum_dinamico'])) {
                    $enum = ($p['enum_dinamico'])($agenteAtual);
                    if ($enum === []) { $vazio = true; break; }   // ninguém a quem delegar: a ferramenta some
                    $prop['enum'] = array_values($enum);
                }
                // `minimum`/`maximum` NÃO vão para a API: com strict:true a
                // Anthropic aceita só um subconjunto do JSON Schema e devolve
                // 400 ("For 'integer' type, properties maximum, minimum are
                // not supported"). A faixa vai na descrição; quem garante o
                // teto é validar(), que trava o valor no PHP — o modelo nunca
                // decidiu o limite, com ou sem o schema.
                if (isset($p['minimo'], $p['maximo'])) {
                    $prop['description'] .= ' Faixa aceita: ' . $p['minimo'] . ' a ' . $p['maximo'] . '.';
                }
                $props[$chave] = $prop;
            }
            if ($vazio) continue;

            $out[] = [
                'name'         => $nome,
                'description'  => $f['descricao'],
                'strict'       => true,
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => $props ?: new stdClass(),
                    'required'             => array_keys($props),
                    'additionalProperties' => false,
                ],
            ];
        }
        return $out;
    }

    // ════════════════════════════════════════════════════
    // EXECUÇÃO
    // ════════════════════════════════════════════════════

    /**
     * Executa uma ferramenta em nome de um agente.
     *
     * Nunca lança: o chamador (o loop do agente) transforma `ok=false`
     * em tool_result com is_error, e o modelo lê a mensagem. Uma
     * exceção aqui derrubaria a conversa inteira por um dado ausente.
     *
     * @param string[] $whitelist ferramentas do agente. Vazia = nada passa.
     * @return array{ok:bool, ferramenta:string, parametros?:array, dados?:mixed, ms?:int, cache?:bool, erro?:string, mensagem?:string}
     */
    public function executar(string $nome, array $params, array $whitelist, ?string $agenteAtual = null): array
    {
        if (!isset($this->catalogo[$nome])) {
            return $this->recusa($nome, 'ferramenta_desconhecida', "A ferramenta '{$nome}' não existe.");
        }
        if (!in_array($nome, $whitelist, true)) {
            return $this->recusa($nome, 'fora_do_dominio',
                "A ferramenta '{$nome}' não pertence ao domínio deste agente. Diga ao usuário qual agente cobre esse assunto.");
        }

        $f = $this->catalogo[$nome];

        try {
            $p = $this->validar($f['propriedades'], $params, $agenteAtual);
        } catch (InvalidArgumentException $e) {
            return $this->recusa($nome, 'parametro_invalido', $e->getMessage());
        }

        // Delegação: o executor é do serviço (reentrante), sem cache — cada
        // consulta a um analista é uma conversa nova, com custo próprio.
        if (!empty($f['delegada'])) {
            if ($this->delegacao === null) {
                return $this->recusa($nome, 'execucao', 'Consultar outro analista não está disponível neste contexto.');
            }
            $t0 = microtime(true);
            $ex = ($this->delegacao)($p);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            LogService::info('ia_delegacao', ['de' => $agenteAtual, 'para' => $p['agente'] ?? null, 'ok' => (bool) ($ex['ok'] ?? false), 'ms' => $ms], 'ia');
            if (empty($ex['ok'])) {
                return ['ok' => false, 'ferramenta' => $nome, 'erro' => (string) ($ex['erro'] ?? 'execucao'),
                        'mensagem' => (string) ($ex['mensagem'] ?? 'O analista não respondeu.')];
            }
            return ['ok' => true, 'ferramenta' => $nome, 'parametros' => $p,
                    'dados' => $this->semDadoPessoal($ex['dados'] ?? []), 'ms' => $ms, 'cache' => false];
        }

        $chave = self::chaveCache($nome, $p);
        if ($f['ttl'] > 0) {
            $emCache = CacheHelper::get($chave);
            if (is_array($emCache)) {
                return ['ok' => true, 'ferramenta' => $nome, 'parametros' => $p,
                        'dados' => $emCache, 'ms' => 0, 'cache' => true];
            }
        }

        $t0 = microtime(true);
        try {
            $dados = ($f['fn'])($p);
        } catch (Throwable $e) {
            LogService::exception($e, 'error', 'ia', ['ferramenta' => $nome, 'params' => $p]);
            return $this->recusa($nome, 'execucao',
                "A ferramenta '{$nome}' falhou ao consultar os dados. Informe que o dado está indisponível no momento.");
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);

        $limite = (int) ($p['limite'] ?? self::LIMITE_PADRAO);
        $dados  = $this->semDadoPessoal($this->normalizar($dados, $limite));

        if ($f['ttl'] > 0) {
            CacheHelper::set($chave, $dados, $f['ttl']);
        }

        $linhas = $this->contarLinhas($dados);
        $ctx    = ['ferramenta' => $nome, 'params' => $p, 'linhas' => $linhas, 'ms' => $ms];
        if ($ms >= self::AVISO_LENTO_MS) {
            LogService::warning('ia_ferramenta_lenta', $ctx, 'ia');
        } else {
            LogService::info('ia_ferramenta', $ctx, 'ia');
        }

        return ['ok' => true, 'ferramenta' => $nome, 'parametros' => $p,
                'dados' => $dados, 'ms' => $ms, 'cache' => false];
    }

    /**
     * Chave de cache de uma execução. Pública para quem precisa
     * invalidar (ex.: salvar uma meta deve derrubar consultar_metas).
     * Recebe os parâmetros já normalizados — a mesma forma que
     * executar() usa — senão a chave não casa.
     */
    public static function chaveCache(string $nome, array $params): string
    {
        return 'ia_tool|' . $nome . '|' . json_encode($params);
    }

    private function recusa(string $nome, string $erro, string $msg): array
    {
        LogService::warning('ia_ferramenta_recusada', ['ferramenta' => $nome, 'erro' => $erro], 'ia');
        return ['ok' => false, 'ferramenta' => $nome, 'erro' => $erro, 'mensagem' => $msg];
    }

    /**
     * Valida e normaliza os parâmetros contra o schema da ferramenta.
     * Param desconhecido é erro (não é ignorado): se o modelo mandou,
     * ele acha que tem efeito, e silêncio aqui viraria resposta errada.
     */
    private function validar(array $schema, array $params, ?string $agenteAtual = null): array
    {
        foreach (array_keys($params) as $k) {
            if (!isset($schema[$k])) {
                throw new InvalidArgumentException("Parâmetro desconhecido: '{$k}'.");
            }
        }

        $out = [];
        foreach ($schema as $chave => $def) {
            if (!array_key_exists($chave, $params)) {
                if (array_key_exists('padrao', $def)) { $out[$chave] = $def['padrao']; continue; }
                throw new InvalidArgumentException("Parâmetro obrigatório ausente: '{$chave}'.");
            }
            $v = $params[$chave];

            if ($def['type'] === 'integer') {
                if (!is_numeric($v) || (int) $v != $v) {
                    throw new InvalidArgumentException("'{$chave}' precisa ser inteiro.");
                }
                $v = (int) $v;
                if (isset($def['minimo']) && $v < $def['minimo']) $v = (int) $def['minimo'];
                if (isset($def['maximo']) && $v > $def['maximo']) $v = (int) $def['maximo'];
            } else {
                $v = trim((string) $v);
                $enum = $def['enum'] ?? (isset($def['enum_dinamico']) ? ($def['enum_dinamico'])($agenteAtual) : null);
                if ($enum !== null && !in_array($v, $enum, true)) {
                    throw new InvalidArgumentException(
                        "'{$chave}' inválido: '{$v}'. Aceitos: " . implode(', ', $enum) . '.');
                }
                if (isset($def['tamanho']) && mb_strlen($v) > $def['tamanho']) {
                    $v = mb_substr($v, 0, (int) $def['tamanho']);
                }
                if (!empty($def['obrigatorio_texto']) && $v === '') {
                    throw new InvalidArgumentException("'{$chave}' não pode ser vazio.");
                }
            }
            $out[$chave] = $v;
        }
        return $out;
    }

    // ════════════════════════════════════════════════════
    // PODA E PRIVACIDADE
    // ════════════════════════════════════════════════════

    /**
     * Tudo que sai do PDO é string; o modelo lê melhor número como
     * número, e "1234.5000" custa token à toa. Listas de linhas são
     * cortadas no limite — a ferramenta pediu ao BiService com margem,
     * mas o que viaja para o modelo é o que ele precisa.
     */
    private function normalizar(mixed $v, int $limite): mixed
    {
        if (is_array($v)) {
            if ($this->ehListaDeLinhas($v) && count($v) > $limite) {
                $v = array_slice($v, 0, $limite);
            }
            $out = [];
            foreach ($v as $k => $item) $out[$k] = $this->normalizar($item, $limite);
            return $out;
        }
        if (is_float($v)) return round($v, 2);
        if (is_string($v) && is_numeric($v) && $v !== '' && !preg_match('/^0\d/', $v)) {
            return str_contains($v, '.') ? round((float) $v, 2) : (int) $v;
        }
        return $v;
    }

    /** Lista sequencial de arrays associativos = "linhas" de resultado. */
    private function ehListaDeLinhas(array $v): bool
    {
        if ($v === [] || !array_is_list($v)) return false;
        return is_array($v[0]) && !array_is_list($v[0]);
    }

    /**
     * Remove dado pessoal em qualquer profundidade. `nome` só cai quando
     * a linha é de pessoa (tem cliente_id); em linha de produto/marca/
     * categoria o nome é o próprio dado. Texto livre de alerta com nome
     * de cliente é mascarado — o BiService::alertas() escreve
     * "Cliente parado: Fulano" e isso não pode chegar ao modelo.
     */
    private function semDadoPessoal(mixed $v): mixed
    {
        if (is_string($v)) {
            return preg_replace('/Cliente parado: .+?(?=$| \(| ·)/u', 'Cliente parado: #(nome omitido)', $v);
        }
        if (!is_array($v)) return $v;

        $linhaDePessoa = !array_is_list($v) && array_key_exists('cliente_id', $v);
        $out = [];
        foreach ($v as $k => $item) {
            if (is_string($k)) {
                $kl = strtolower($k);
                if (in_array($kl, self::CHAVES_PESSOAIS, true)) continue;
                if ($linhaDePessoa && in_array($kl, ['nome', 'nome_completo', 'apelido'], true)) continue;
            }
            $out[$k] = $this->semDadoPessoal($item);
        }
        return $out;
    }

    private function contarLinhas(mixed $v): int
    {
        if (!is_array($v)) return 0;
        if ($this->ehListaDeLinhas($v)) return count($v);
        $n = 0;
        foreach ($v as $item) $n += $this->contarLinhas($item);
        return $n;
    }

    // ════════════════════════════════════════════════════
    // AS FERRAMENTAS
    // ════════════════════════════════════════════════════

    private function periodo(array $p): array
    {
        return $this->bi->periodo((string) $p['periodo']);
    }

    private function propPeriodo(): array
    {
        return ['type' => 'string', 'enum' => self::PERIODOS, 'padrao' => '30d',
                'descricao' => 'Janela de análise: 7d, 30d, 90d ou 12m (últimos N). Use o período selecionado no painel salvo se o usuário pedir outro.'];
    }

    private function propLimite(int $padrao = self::LIMITE_PADRAO): array
    {
        return ['type' => 'integer', 'minimo' => 1, 'maximo' => self::LIMITE_MAX, 'padrao' => $padrao,
                'descricao' => 'Quantidade de linhas (1 a ' . self::LIMITE_MAX . ').'];
    }

    private function propDias(): array
    {
        return ['type' => 'integer', 'minimo' => 7, 'maximo' => 365, 'padrao' => 90,
                'descricao' => 'Janela em dias para medir a saída (7 a 365).'];
    }

    /** Cobertura de custo — viaja junto de qualquer número de margem. */
    private function coberturaCusto(): array
    {
        foreach ($this->bi->saude() as $s) {
            if (($s['indicador'] ?? '') === 'custo_item') {
                return ['pct' => (float) $s['pct'], 'itens_com_custo' => (int) $s['preenchido'],
                        'itens_total' => (int) $s['total'],
                        'aviso' => 'Margem e lucro só existem sobre os itens com custo cadastrado.'];
            }
        }
        return ['pct' => 0.0, 'aviso' => 'Cobertura de custo desconhecida.'];
    }

    private function montarCatalogo(): array
    {
        $bi = $this->bi;

        return [
            // ── Financeiro ───────────────────────────────────────
            'consultar_faturamento' => [
                'descricao'    => 'KPIs do período com comparativo contra o período anterior de mesma duração: faturamento, pedidos, ticket médio, itens, clientes, desconto, frete e lucro (quando há custo). Variação null = sem base de comparação.',
                'propriedades' => ['periodo' => $this->propPeriodo()],
                'ttl'          => 900,
                'fn'           => fn(array $p) => ['periodo' => $this->periodo($p), 'kpis' => $bi->kpis($this->periodo($p))],
            ],
            'consultar_vendas' => [
                'descricao'    => 'Ranking de vendas do período agrupado por produto, marca, categoria, canal ou forma de pagamento: receita, quantidade, pedidos, clientes, desconto e margem (com cobertura de custo por linha).',
                'propriedades' => [
                    'periodo' => $this->propPeriodo(),
                    'agrupar' => ['type' => 'string', 'enum' => ['produto', 'marca', 'categoria', 'canal', 'pagamento'], 'padrao' => 'produto',
                                  'descricao' => 'Dimensão do agrupamento.'],
                    'limite'  => $this->propLimite(),
                ],
                'ttl' => 900,
                'fn'  => function (array $p) use ($bi) {
                    $per = $this->periodo($p);
                    return $p['agrupar'] === 'pagamento'
                        ? ['periodo' => $per, 'agrupar' => 'pagamento', 'linhas' => $bi->porPagamento($per)]
                        : ['periodo' => $per, 'agrupar' => $p['agrupar'], 'linhas' => $bi->ranking($per, $p['agrupar'], (int) $p['limite'])];
                },
            ],
            'consultar_margem' => [
                'descricao'    => 'Margem e lucro por marca ou categoria no período, com comparativo e a cobertura de custo (quantos itens têm custo cadastrado). Sem cobertura, margem é desconhecida — não é zero.',
                'propriedades' => [
                    'periodo' => $this->propPeriodo(),
                    'agrupar' => ['type' => 'string', 'enum' => ['marca', 'categoria'], 'padrao' => 'marca', 'descricao' => 'Dimensão.'],
                    'limite'  => $this->propLimite(),
                ],
                'ttl' => 900,
                'fn'  => fn(array $p) => [
                    'periodo'         => $this->periodo($p),
                    'cobertura_custo' => $this->coberturaCusto(),
                    'linhas'          => $bi->porMarca($this->periodo($p), $p['agrupar'], (int) $p['limite']),
                ],
            ],
            'consultar_frete' => [
                'descricao'    => 'Frete do período: cobrado × custo real por tipo (pedido, reversa, avulso) e por transportadora, com prazo e atraso quando há rastreio. Custo real só existe quando a etiqueta tem valor postado.',
                'propriedades' => ['periodo' => $this->propPeriodo()],
                'ttl'          => 900,
                'fn'           => fn(array $p) => [
                    'periodo'         => $this->periodo($p),
                    'por_tipo'        => $bi->fretePorTipo($this->periodo($p)),
                    'transportadoras' => $bi->transportadoras($this->periodo($p)),
                ],
            ],
            'consultar_descontos' => [
                'descricao'    => 'Impacto de descontos no período (receita bruta × desconto × líquida, pedidos com e sem desconto) e o ranking de cupons por uso, desconto concedido e receita gerada.',
                'propriedades' => ['periodo' => $this->propPeriodo(), 'limite' => $this->propLimite()],
                'ttl'          => 900,
                'fn'           => fn(array $p) => [
                    'periodo' => $this->periodo($p),
                    'impacto' => $bi->impactoDesconto($this->periodo($p)),
                    'cupons'  => $bi->cupons($this->periodo($p), (int) $p['limite']),
                ],
            ],
            'consultar_pagamentos' => [
                'descricao'    => 'Aprovação de pagamento por método e por adquirente (sobre TENTATIVAS, inclusive recusadas), distribuição de parcelas e principais motivos de recusa.',
                'propriedades' => ['periodo' => $this->propPeriodo()],
                'ttl'          => 900,
                'fn'           => fn(array $p) => [
                    'periodo'        => $this->periodo($p),
                    'por_metodo'     => $bi->pagamentoAprovacao($this->periodo($p), 'metodo'),
                    'por_adquirente' => $bi->pagamentoAprovacao($this->periodo($p), 'adquirente'),
                    'parcelas'       => $bi->parcelas($this->periodo($p)),
                    'recusas'        => $bi->recusas($this->periodo($p), 10),
                ],
            ],
            'consultar_devolucoes_cancelamentos' => [
                'descricao'    => 'Devoluções (volume, valor, motivos, produtos mais devolvidos) e cancelamentos (volume, valor, motivo estruturado) do período.',
                'propriedades' => ['periodo' => $this->propPeriodo()],
                'ttl'          => 900,
                'fn'           => fn(array $p) => [
                    'periodo'             => $this->periodo($p),
                    'devolucoes'          => $bi->devolucoes($this->periodo($p)),
                    'produtos_devolvidos' => $bi->produtosDevolvidos($this->periodo($p), 10),
                    'cancelamentos'       => $bi->cancelamentos($this->periodo($p)),
                ],
            ],
            'consultar_curva_abc' => [
                'descricao'    => 'Curva ABC por receita acumulada (A ≤ 80%, B ≤ 95%, C resto) e Pareto: quantos itens concentram 80% da receita. Classificação feita sobre o ranking completo; só as primeiras linhas viajam.',
                'propriedades' => [
                    'periodo'  => $this->propPeriodo(),
                    'dimensao' => ['type' => 'string', 'enum' => ['produto', 'marca', 'categoria'], 'padrao' => 'produto', 'descricao' => 'Dimensão.'],
                    'limite'   => $this->propLimite(),
                ],
                'ttl' => 3600,
                'fn'  => function (array $p) use ($bi) {
                    $per = $this->periodo($p);
                    // ABC precisa do ranking INTEIRO para classificar; o corte
                    // de linhas é só no que viaja, senão a classe sai errada.
                    $abc = $bi->curvaABC($per, $p['dimensao'], 500);
                    $porClasse = ['A' => 0, 'B' => 0, 'C' => 0];
                    foreach ($abc as $l) $porClasse[$l['classe']]++;
                    return ['periodo' => $per, 'dimensao' => $p['dimensao'],
                            'itens_por_classe' => $porClasse,
                            'pareto' => $bi->pareto($per, $p['dimensao']),
                            'linhas' => $abc];
                },
            ],
            'consultar_metas' => [
                'descricao'    => 'Metas cadastradas que cobrem o período, com o realizado, o percentual atingido e a projeção. Sem meta cadastrada a lista vem vazia — isso é ausência de cadastro, não de venda.',
                'propriedades' => ['periodo' => $this->propPeriodo()],
                'ttl'          => 900,
                'fn'           => fn(array $p) => ['periodo' => $this->periodo($p), 'metas' => $bi->metas($this->periodo($p))],
            ],
            'consultar_projecao' => [
                'descricao'    => 'Projeção do mês corrente por run-rate, em três cenários, com a confiança da base (o histórico é curto e não há sazonalidade anual).',
                'propriedades' => [],
                'ttl'          => 900,
                'fn'           => fn(array $p) => $bi->projecaoMes(),
            ],
            'consultar_alertas' => [
                'descricao'    => 'Alertas que o sistema já detectou (ruptura, queda de faturamento, aprovação baixa, cliente parado, estoque parado, cobertura de dado) por prioridade, e os insights calculados do período. Clientes aparecem sem nome.',
                'propriedades' => ['periodo' => $this->propPeriodo()],
                'ttl'          => 900,
                'fn'           => fn(array $p) => [
                    'periodo'  => $this->periodo($p),
                    'alertas'  => $bi->alertas($this->periodo($p)),
                    'insights' => $bi->insights($this->periodo($p)),
                ],
            ],
            'consultar_tendencia_produtos' => [
                'descricao'    => 'Produtos em alta ou em queda: receita da janela atual contra a janela anterior de mesmo tamanho, com variação percentual.',
                'propriedades' => [
                    'dias'    => ['type' => 'integer', 'minimo' => 7, 'maximo' => 180, 'padrao' => 30, 'descricao' => 'Tamanho da janela em dias.'],
                    'direcao' => ['type' => 'string', 'enum' => ['alta', 'queda'], 'padrao' => 'alta', 'descricao' => 'alta ou queda.'],
                    'limite'  => $this->propLimite(),
                ],
                'ttl' => 3600,
                'fn'  => fn(array $p) => ['dias' => $p['dias'], 'direcao' => $p['direcao'],
                                          'linhas' => $bi->tendenciaProdutos((int) $p['dias'], $p['direcao'], (int) $p['limite'])],
            ],
            'consultar_geografia' => [
                'descricao'    => 'Receita, pedidos, clientes, ticket e frete médio por UF ou cidade no período, com a confiabilidade da origem do endereço.',
                'propriedades' => [
                    'periodo' => $this->propPeriodo(),
                    'nivel'   => ['type' => 'string', 'enum' => ['uf', 'cidade'], 'padrao' => 'uf', 'descricao' => 'uf ou cidade.'],
                    'limite'  => $this->propLimite(),
                ],
                'ttl' => 3600,
                'fn'  => fn(array $p) => ['periodo' => $this->periodo($p), 'nivel' => $p['nivel'],
                                          'linhas' => $bi->geografia($this->periodo($p), $p['nivel'], (int) $p['limite'])],
            ],

            // ── Estoque ──────────────────────────────────────────
            'consultar_giro_estoque' => [
                'descricao'    => 'Giro por produto: saldo atual, saída média diária na janela e cobertura em dias (quantos dias o saldo aguenta no ritmo atual). Cobertura null = sem venda na janela.',
                'propriedades' => ['dias' => $this->propDias(), 'limite' => $this->propLimite()],
                'ttl'          => 3600,
                'fn'           => fn(array $p) => ['dias' => $p['dias'],
                                                   'linhas' => $bi->giroEstoque((int) $p['dias'], (int) $p['limite'])],
            ],
            'consultar_estoque_parado' => [
                'descricao'    => 'Produtos com saldo e sem venda na janela: capital imobilizado (valor só quando há custo cadastrado) e dias desde a última venda.',
                'propriedades' => ['dias' => $this->propDias(), 'limite' => $this->propLimite()],
                'ttl'          => 3600,
                'fn'           => fn(array $p) => ['dias' => $p['dias'],
                                                   'linhas' => $bi->estoqueParado((int) $p['dias'], (int) $p['limite'])],
            ],
            'consultar_ruptura' => [
                'descricao'    => 'Produtos no estoque mínimo ou abaixo dele (o estoque_minimo é cadastrado por produto).',
                'propriedades' => ['limite' => $this->propLimite(15)],
                'ttl'          => 900,
                'fn'           => fn(array $p) => ['linhas' => $bi->alertasEstoque((int) $p['limite'])],
            ],

            // ── Analytics ────────────────────────────────────────
            'consultar_funil' => [
                'descricao'    => 'Funil de 4 etapas (produto visto → carrinho → checkout → compra) por pessoas distintas, com a conversão de cada etapa contra a ANTERIOR. Não existe etapa "visitantes".',
                'propriedades' => ['periodo' => $this->propPeriodo()],
                'ttl'          => 900,
                'fn'           => fn(array $p) => ['periodo' => $this->periodo($p), 'etapas' => $bi->funil($this->periodo($p))],
            ],
            'consultar_dispositivos' => [
                'descricao'    => 'O mesmo funil aberto por dispositivo (mobile × desktop × desconhecido), cada um com sua conversão por etapa. É o corte para achar problema de experiência mobile.',
                'propriedades' => ['periodo' => $this->propPeriodo()],
                'ttl'          => 900,
                'fn'           => fn(array $p) => ['periodo' => $this->periodo($p), 'por_dispositivo' => $bi->funilPorDispositivo($this->periodo($p))],
            ],
            'consultar_carrinhos' => [
                'descricao'    => 'Carrinhos abandonados e recuperação no período: quantidade, valor abandonado, recuperados e valor recuperado (real, não estimado).',
                'propriedades' => ['periodo' => $this->propPeriodo()],
                'ttl'          => 900,
                'fn'           => fn(array $p) => ['periodo' => $this->periodo($p), 'carrinhos' => $bi->carrinhos($this->periodo($p))],
            ],
            'consultar_clientes' => [
                'descricao'    => 'Clientes em agregado: novos × recorrentes no período, resumo RFM por segmento, taxa de recompra e os clientes em risco identificados por #id (compravam com regularidade e pararam). Nunca traz nome ou contato.',
                'propriedades' => ['periodo' => $this->propPeriodo(), 'limite' => $this->propLimite()],
                'ttl'          => 3600,
                'fn'           => fn(array $p) => [
                    'periodo'   => $this->periodo($p),
                    'segmentos' => $bi->segmentosCliente($this->periodo($p)),
                    'rfm'       => $bi->rfmResumo(),
                    'recompra'  => $bi->recompra(),
                    'em_risco'  => $bi->clientesRisco((int) $p['limite']),
                ],
            ],

            // ── Coordenação (só o Diretor tem na whitelist) ──────
            'perguntar_agente' => [
                'descricao'    => 'Consulta um analista especializado e devolve a resposta dele (RESUMO, INDICADORES, CAUSAS, IMPACTO, RECOMENDAÇÕES, PRIORIDADE). Use para tudo que é do domínio do analista: margem, estoque, funil. Faça a pergunta completa e específica, com o período. Cada consulta leva 15 a 30 segundos e tem custo — no máximo 3 por pergunta, e nunca a mesma pergunta duas vezes ao mesmo analista. Para cruzar domínios, chame vários de uma só vez.',
                'propriedades' => [
                    'agente'   => ['type' => 'string',
                                   'enum_dinamico' => fn(?string $atual) => array_values(array_diff(array_keys(self::agentes()), [$atual, ''])),
                                   'descricao' => 'Código do analista a consultar.'],
                    'pergunta' => ['type' => 'string', 'tamanho' => 600, 'obrigatorio_texto' => true,
                                   'descricao' => 'A pergunta ao analista, completa e específica (inclua o período).'],
                ],
                'ttl'      => 0,
                'delegada' => true,
                'fn'       => fn(array $p) => throw new LogicException('perguntar_agente é executada pela delegação.'),
            ],

            // ── Todos ────────────────────────────────────────────
            'consultar_saude_dados' => [
                'descricao'    => 'Cobertura de cada dado que as análises dependem (custo, geografia, atribuição, vendedor, motivo de cancelamento, frete real). Use para dizer o que NÃO pode ser afirmado.',
                'propriedades' => [],
                'ttl'          => 3600,
                'fn'           => fn(array $p) => ['indicadores' => $bi->saude()],
            ],
        ];
    }
}
