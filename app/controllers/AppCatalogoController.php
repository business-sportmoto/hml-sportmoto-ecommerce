<?php
// app/controllers/AppCatalogoController.php
// Catálogo, busca, filtros, categorias e marcas.
//
// Todo o trabalho pesado já existe em Product::getCatalog()/countCatalog() e
// nas quatro funções de faceta. Este controller é tradução de parâmetros de
// entrada e serialização de saída — nada de SQL novo.

class AppCatalogoController extends AppApiController
{
    /** Ordenações aceitas — espelha Product::buildOrder(). */
    private const ORDENS = [
        'relevancia', 'novidades', 'menor_preco', 'maior_preco',
        'maior_desconto', 'mais_vendidos', 'mais_vistos', 'destaque',
    ];

    /**
     * GET /api/app/v1/catalogo
     *
     * Query: q, categoria, marca[], preco_min, preco_max, atributos[Tipo][],
     *        caracteristicas[Nome][], ordem, em_promocao, com_estoque,
     *        apenas_compativel, page, per_page
     */
    public function index(): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $ctx     = $this->contexto();
        $pagina  = $this->pagina();
        $filtros = $this->filtros($ctx);

        $modelo   = new Product();
        $produtos = $modelo->getCatalog($filtros, $pagina['limit'], $pagina['offset']);
        $total    = $modelo->countCatalog($filtros);

        $this->okPaginado(
            'produtos',
            ProductCardPresenter::colecao($produtos, $ctx),
            $total,
            $pagina,
            ['filtros_aplicados' => $this->filtrosAplicados($filtros)]
        );
    }

    /**
     * GET /api/app/v1/catalogo/filtros
     *
     * As facetas para a bottom sheet de filtros. Contagens sempre refletem o
     * filtro corrente — é o que as funções *ForFilter() do model já fazem, cada
     * uma removendo a própria dimensão antes de contar.
     */
    public function filtrosDisponiveis(): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $ctx     = $this->contexto();
        $filtros = $this->filtros($ctx);
        $modelo  = new Product();

        $faixa = $modelo->getPriceRange($filtros);

        $this->ok([
            'marcas' => MarcaPresenter::colecao($modelo->getBrandsForFilter($filtros), $ctx),
            'faixa_preco' => [
                'min' => PrecoPresenter::dec($faixa['min_price'] ?? 0),
                'max' => PrecoPresenter::dec($faixa['max_price'] ?? 0),
            ],
            'atributos'       => $this->grupos($modelo->getAttributesForFilter($filtros)),
            'caracteristicas' => $this->grupos($modelo->getCaracteristicasForFilter($filtros)),
            'ordens'          => $this->ordens(),
        ]);
    }

    /**
     * GET /api/app/v1/busca
     * Mesma resposta do catálogo, mas registra o termo para a personalização.
     */
    public function busca(): void
    {
        $this->bootOpcional();

        $termo = trim((string)$this->query('q', ''));
        if (mb_strlen($termo) < 2) {
            $this->falha(422, 'termo_curto', 'Informe ao menos 2 caracteres.');
        }

        // Registrar a busca é escrita em sessão/histórico: acontece ANTES de
        // liberar o lock.
        $this->registrarBusca($termo);
        $this->liberarSessao();

        $ctx     = $this->contexto();
        $pagina  = $this->pagina();
        $filtros = $this->filtros($ctx);

        $modelo   = new Product();
        $produtos = $modelo->search($termo, $pagina['limit'], $pagina['offset'], $filtros);
        $total    = $modelo->countSearch($termo, $filtros);

        $this->okPaginado(
            'produtos',
            ProductCardPresenter::colecao($produtos, $ctx),
            $total,
            $pagina,
            ['termo' => $termo]
        );
    }

    /**
     * GET /api/app/v1/busca/autocomplete?q=
     * Resposta enxuta de propósito: roda a cada tecla digitada.
     */
    public function autocomplete(): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $termo = trim((string)$this->query('q', ''));
        if (mb_strlen($termo) < 2) {
            $this->ok(['produtos' => [], 'termo' => $termo]);
        }

        $ctx     = $this->contexto();
        $filtros = [];
        if ($cat = (int)$this->query('categoria_id', 0)) {
            $filtros['categoria_id'] = $cat;
        }

        $rows = (new Product())->autocomplete($termo, 8, $filtros);

        $this->ok([
            'termo'    => $termo,
            'produtos' => array_values(array_map(static fn(array $p) => [
                'id'        => (int)$p['id'],
                'nome'      => $p['nome'],
                'slug'      => $p['slug'],
                'categoria' => $p['categoria'] ?? null,
                'imagem'    => $ctx->url($p['imagem'] ?? null),
                'preco'     => PrecoPresenter::dec(PriceHelper::currentPrice($p)),
            ], $rows)),
        ]);
    }

    /**
     * GET /api/app/v1/categorias
     * ?arvore=1 devolve a hierarquia completa; sem isso, só as raízes.
     */
    public function categorias(): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $ctx    = $this->contexto();
        $modelo = new Category();

        $rows = $this->query('arvore') ? $modelo->getNavTree() : $modelo->getActive();

        $this->ok(['categorias' => CategoriaPresenter::colecao($rows, $ctx)]);
    }

    /**
     * GET /api/app/v1/categorias/{slug}
     * Cabeçalho da categoria + subcategorias. Os produtos vêm de /catalogo com
     * ?categoria={slug} — separar evita recarregar o cabeçalho a cada página.
     */
    public function categoria(string $slug = ''): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $ctx    = $this->contexto();
        $modelo = new Category();
        $cat    = $modelo->findBySlug($slug);

        if (!$cat) {
            $this->falha(404, 'nao_encontrada', 'Categoria não encontrada.');
        }

        $this->ok([
            'categoria' => CategoriaPresenter::uma($cat, $ctx),
            'filhos'    => CategoriaPresenter::colecao(
                $modelo->getActive((int)$cat['id']),
                $ctx
            ),
            'total_produtos' => (new Product())->countCatalog(['categoria_id' => (int)$cat['id']]),
        ]);
    }

    /**
     * GET /api/app/v1/marcas
     */
    public function marcas(): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $ctx  = $this->contexto();
        $rows = (new Brand())->getActive();

        $this->ok(['marcas' => MarcaPresenter::colecao($rows, $ctx)]);
    }

    /**
     * GET /api/app/v1/marcas/{slug}
     */
    public function marca(string $slug = ''): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $ctx   = $this->contexto();
        $marca = (new Brand())->findBySlug($slug);

        if (!$marca) {
            $this->falha(404, 'nao_encontrada', 'Marca não encontrada.');
        }

        $this->ok([
            'marca'          => MarcaPresenter::uma($marca, $ctx),
            'total_produtos' => (new Product())->countCatalog(['marca_id' => (int)$marca['id']]),
        ]);
    }

    /* =================================================================
       Tradução de filtros
       ================================================================= */

    /**
     * Query string do app → array de filtros de Product::buildFilters().
     *
     * Diferença importante do web: aqui categoria e marca chegam por SLUG, não
     * por id. O app navega por slug (é o que aparece no deep link), e resolver
     * o id aqui evita uma ida e volta extra só para descobrir o número.
     */
    private function filtros(PresenterContext $ctx): array
    {
        $f = [];

        if ($q = trim((string)$this->query('q', ''))) {
            $f['q'] = $q;
        }

        if ($slug = trim((string)$this->query('categoria', ''))) {
            if ($cat = (new Category())->findBySlug($slug)) {
                $f['categoria_id'] = (int)$cat['id'];
            } else {
                // Slug inexistente não pode virar "sem filtro" — isso devolveria
                // o catálogo inteiro como se fosse o resultado da categoria.
                $f['categoria_id'] = -1;
            }
        } elseif ($id = (int)$this->query('categoria_id', 0)) {
            $f['categoria_id'] = $id;
        }

        $marcas = (array)$this->query('marca', []);
        $marcas = array_values(array_filter(array_map('strval', $marcas)));
        if ($marcas) {
            $ids = (new Brand())->idsPorSlugs($marcas);
            $f['marcas'] = $ids ?: [-1];
        } elseif ($marcaId = (int)$this->query('marca_id', 0)) {
            $f['marca_id'] = $marcaId;
        }

        foreach (['preco_min', 'preco_max'] as $campo) {
            $v = (float)$this->query($campo, 0);
            if ($v > 0) {
                $f[$campo] = $v;
            }
        }

        if ($this->query('em_promocao')) { $f['em_promocao'] = 1; }
        if ($this->query('com_estoque'))  { $f['com_estoque']  = 1; }

        $ordem = (string)$this->query('ordem', 'relevancia');
        $f['ordem'] = in_array($ordem, self::ORDENS, true) ? $ordem : 'relevancia';

        // atributos[Tamanho][]=42&atributos[Cor][]=Preto
        foreach (['atributos', 'caracteristicas'] as $grupo) {
            $valores = $this->query($grupo, []);
            if (is_array($valores) && $valores) {
                $f[$grupo] = array_map(
                    static fn($v) => array_values(array_filter((array)$v, static fn($x) => $x !== '')),
                    $valores
                );
            }
        }

        // "Só o que serve na minha moto": traduz a moto ativa da sessão nos
        // filtros de compatibilidade que buildFilters() já entende.
        if ($this->query('apenas_compativel') && $ctx->temVeiculo()) {
            $v = $ctx->veiculoAtivo;
            $f['montadora_id'] = (int)$v['montadora_id'];
            if (!empty($v['modelo_id'])) { $f['modelo_id'] = (int)$v['modelo_id']; }
            if (!empty($v['ano']))       { $f['ano']       = (int)$v['ano']; }
        }

        // Moto EXPLÍCITA na query — a busca por moto da home.
        //
        // Deliberadamente separado de `apenas_compativel`: aquele usa a moto
        // ATIVA da sessão; este consulta uma moto qualquer sem tocar na
        // sessão. É o equivalente do /montadora/{slug}/{modelo}-{ano} da web,
        // onde olhar peça da moto de um amigo não troca a sua moto ativa.
        //
        // Vem DEPOIS por decisão: se as duas chegarem juntas, a moto explícita
        // é a que o usuário acabou de escolher na tela e deve vencer.
        if ($montadora = (int)$this->query('montadora_id', 0)) {
            $f['montadora_id'] = $montadora;

            // Modelo e ano só entram acompanhados da montadora: um modelo_id
            // solto filtraria por um id de outra montadora sem que nada na
            // tela indicasse isso.
            if ($modelo = (int)$this->query('modelo_id', 0)) { $f['modelo_id'] = $modelo; }
            if ($ano    = (int)$this->query('ano', 0))       { $f['ano']       = $ano; }
        }

        return $f;
    }

    /** Eco dos filtros ativos, para o app desenhar os chips de "limpar filtro". */
    private function filtrosAplicados(array $f): array
    {
        unset($f['ordem']);
        return array_keys(array_filter($f, static fn($v) => $v !== null && $v !== [] && $v !== ''));
    }

    /**
     * getAttributesForFilter() e getCaracteristicasForFilter() já devolvem
     * agrupado por nome do tipo: ['Tamanho' => [['valor'=>'42','total'=>7], …]].
     * Converte esse mapa numa lista, que é o que o app consome sem depender da
     * ordem de chaves do JSON.
     */
    private function grupos(array $agrupado): array
    {
        $out = [];

        foreach ($agrupado as $nome => $valores) {
            if (!is_array($valores) || !$valores) {
                continue;
            }

            $out[] = [
                'nome'    => (string)$nome,
                'valores' => array_values(array_map(static fn(array $v) => [
                    'valor' => $v['valor'],
                    'total' => isset($v['total']) ? (int)$v['total'] : null,
                ], $valores)),
            ];
        }

        return $out;
    }

    private function ordens(): array
    {
        return [
            ['valor' => 'relevancia',     'rotulo' => 'Mais relevantes'],
            ['valor' => 'mais_vendidos',  'rotulo' => 'Mais vendidos'],
            ['valor' => 'novidades',      'rotulo' => 'Lançamentos'],
            ['valor' => 'menor_preco',    'rotulo' => 'Menor preço'],
            ['valor' => 'maior_preco',    'rotulo' => 'Maior preço'],
            ['valor' => 'maior_desconto', 'rotulo' => 'Maior desconto'],
            ['valor' => 'mais_vistos',    'rotulo' => 'Mais vistos'],
        ];
    }

    /**
     * Alimenta a seção "relacionado às suas buscas" da home.
     * sessao_id é NOT NULL na tabela; sem sessão não há o que personalizar,
     * então simplesmente não registramos.
     */
    private function registrarBusca(string $termo): void
    {
        $sessao = session_id();
        if ($sessao === '') {
            return;
        }

        try {
            $this->db()->prepare(
                "INSERT INTO historico_navegacao (cliente_id, sessao_id, tipo, termo_busca)
                 VALUES (:c, :s, 'busca', :t)"
            )->execute([
                ':c' => $this->clienteId,
                ':s' => $sessao,
                ':t' => mb_substr($termo, 0, 200),
            ]);
        } catch (\Throwable $e) {
            // Histórico é enriquecimento, nunca requisito para responder a busca.
        }
    }
}
