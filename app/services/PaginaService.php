<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/PaginaService.php
//
// Regras das páginas de conteúdo: slug, validação e sanitização.
//
// ── Onde mora a segurança ─────────────────────────────────────────────────
// O conteúdo é HTML digitado no painel e renderizado sem escape numa página
// pública que compartilha domínio com o checkout. A defesa é o
// HtmlHelper::sanitizeRich() (HTML Purifier) chamado AQUI, no momento de
// gravar — o editor do painel é conforto, e um POST direto ignora o editor.
// ════════════════════════════════════════════════════════

class PaginaService
{
    private Pagina $model;

    public function __construct(?Pagina $model = null)
    {
        $this->model = $model ?? new Pagina();
    }

    /**
     * Mínimo de texto visível para uma página poder ir ao ar.
     *
     * Existe por causa das 6 páginas de exemplo que estavam no banco desde
     * abril com frases como "Nossos termos de uso." — publicar isso é pior que
     * 404, porque o cliente conclui que a loja não tem termos, e não que a
     * página ainda não existe.
     *
     * Medido sobre o texto sem marcação: 120 caracteres de <div> aninhado
     * continuam sendo uma página vazia.
     */
    public const MIN_CONTEUDO = 120;

    /**
     * Primeiros segmentos de URL que o roteador já usa.
     *
     * Uma página criada com um desses slugs nunca seria alcançada: o curinga
     * /{slug} é a ÚLTIMA rota do arquivo, então /carrinho sempre cai no
     * CarrinhoController. Sem esta checagem o admin salvaria uma página que
     * simplesmente não abre, sem nenhum erro para explicar por quê.
     *
     * Extraída de config/routes.php. Rota nova de primeiro nível entra aqui.
     */
    public const SLUGS_RESERVADOS = [
        'admin', 'ajax', 'ajuda', 'api', 'autenticacao-2fa', 'auth', 'avaliacao',
        'avaliacoes', 'banner', 'beacon', 'busca', 'cadastro', 'carrinho',
        'categoria', 'cep', 'checkout', 'clip', 'clips', 'consent', 'cupom',
        'dica', 'favoritos', 'feed', 'frete', 'historico', 'home', 'ir', 'lista',
        'login', 'manifest', 'marca', 'marcas', 'meu-veiculo', 'minha-conta',
        'montadora', 'motos', 'newsletter', 'notificacoes', 'pages', 'perguntas',
        'produto', 'promocoes', 'rastreio', 'recuperar-senha', 'redefinir-senha',
        'sair', 'sessao', 'sitemap', 'storage', 'track', 'uploads',
        'verificar-documento', 'verificar-email', 'webhook', 'webhooks',
    ];

    /* =================================================================
       LEITURA
       ================================================================= */

    public function listar(array $filtros = []): array
    {
        return $this->model->listar($filtros);
    }

    public function porId(int $id): ?array
    {
        return $this->model->porId($id);
    }

    public function porSlug(string $slug, bool $somenteAtivas = true): ?array
    {
        return $this->model->porSlug($slug, $somenteAtivas);
    }

    public function publicadas(): array
    {
        return $this->model->publicadas();
    }

    /* =================================================================
       ESCRITA
       ================================================================= */

    /**
     * Cria ou atualiza. Devolve ['ok', 'msg', 'id'].
     *
     * Recebe o $_POST cru: quem valida é este método, não o controller nem a
     * view. Assim a mesma regra vale para o formulário, para um import e para
     * qualquer chamada futura.
     */
    public function salvar(array $post, int $id = 0): array
    {
        $titulo = trim(strip_tags((string) ($post['titulo'] ?? '')));
        if ($titulo === '') {
            return ['ok' => false, 'msg' => 'O título é obrigatório.'];
        }
        if (mb_strlen($titulo) > 200) {
            return ['ok' => false, 'msg' => 'O título passa de 200 caracteres.'];
        }

        // Slug em branco vira o título — é o caso comum, e obrigar a digitar
        // duas vezes só produz slug esquecido com maiúscula e acento.
        $slug = self::slugify((string) ($post['slug'] ?? '') ?: $titulo);
        if ($slug === '') {
            return ['ok' => false, 'msg' => 'Não foi possível gerar um endereço a partir do título.'];
        }
        if (mb_strlen($slug) > 120) {
            return ['ok' => false, 'msg' => 'O endereço passa de 120 caracteres.'];
        }

        if (in_array($slug, self::SLUGS_RESERVADOS, true)) {
            return ['ok' => false, 'msg' => "O endereço “{$slug}” é usado pelo próprio site. Escolha outro."];
        }
        if ($this->model->slugEmUso($slug, $id)) {
            return ['ok' => false, 'msg' => "Já existe outra página em “/{$slug}”."];
        }
        if (self::existeEmArquivo($slug)) {
            return ['ok' => false, 'msg' => "Já existe uma página montada em arquivo em “/{$slug}”. "
                . 'Ela tem prioridade e esta nunca apareceria.'];
        }

        $ativo = !empty($post['ativo']) ? 1 : 0;

        // Página vazia publicada é pior que 404: o cliente acha que a loja
        // esqueceu de escrever os termos.
        $conteudo = HtmlHelper::sanitizeRich((string) ($post['conteudo'] ?? ''));
        if ($ativo === 1 && !self::temConteudo($conteudo)) {
            return ['ok' => false, 'msg' => 'A página ainda está curta demais para ir ao ar. '
                . 'Escreva o texto e publique depois — ou salve como rascunho.'];
        }

        $dados = [
            'slug'             => $slug,
            'titulo'           => $titulo,
            'menu_label'       => trim(strip_tags((string) ($post['menu_label'] ?? ''))),
            'conteudo'         => $conteudo,
            'meta_title'       => trim(strip_tags((string) ($post['meta_title'] ?? ''))),
            'meta_description' => trim(strip_tags((string) ($post['meta_description'] ?? ''))),
            'ativo'            => $ativo,
            'ordem_menu'       => ($post['ordem_menu'] ?? '') !== '' ? (int) $post['ordem_menu'] : null,
            'no_menu'          => !empty($post['no_menu']) ? 1 : 0,
            'no_rodape'        => !empty($post['no_rodape']) ? 1 : 0,
            'noindex'          => !empty($post['noindex']) ? 1 : 0,
            'publicado_em'     => null,
        ];

        try {
            if ($id > 0) {
                $atual = $this->model->porId($id);
                if (!$atual) return ['ok' => false, 'msg' => 'Página não encontrada.'];

                // Preserva a primeira publicação; só carimba se estreou agora.
                $dados['publicado_em'] = $atual['publicado_em']
                    ?: ($ativo === 1 ? date('Y-m-d H:i:s') : null);

                $this->model->atualizar($id, $dados);
                $novoId = $id;
            } else {
                $dados['publicado_em'] = $ativo === 1 ? date('Y-m-d H:i:s') : null;
                $novoId = $this->model->criar($dados);
            }
        } catch (Throwable $e) {
            LogService::exception($e, 'error', 'app', ['onde' => 'PaginaService::salvar', 'slug' => $slug]);
            return ['ok' => false, 'msg' => 'Falha ao gravar a página.'];
        }

        return [
            'ok'   => true,
            'msg'  => $ativo ? 'Página publicada.' : 'Rascunho salvo.',
            'id'   => $novoId,
            'slug' => $slug,
            'url'  => BASE_URL . '/' . $slug,
        ];
    }

    public function excluir(int $id): array
    {
        $p = $this->model->porId($id);
        if (!$p) return ['ok' => false, 'msg' => 'Página não encontrada.'];

        $this->model->excluir($id);
        LogService::audit('Página excluída', [
            'pagina_id' => $id, 'slug' => $p['slug'], 'usuario_id' => AuthHelper::usuarioId(),
        ]);
        return ['ok' => true, 'msg' => 'Página excluída.'];
    }

    /**
     * Liga/desliga a publicação pela lista.
     *
     * A regra de "não publica página vazia" vale aqui também. Ela existia só no
     * salvar(), e o botão da lista passava por fora: dava para pôr no ar uma
     * página com uma frase de exemplo sem nenhum aviso. Regra que vale num
     * caminho e não no outro é regra que não vale.
     */
    public function alternarAtivo(int $id): array
    {
        $p = $this->model->porId($id);
        if (!$p) return ['ok' => false, 'msg' => 'Página não encontrada.'];

        $vaiPublicar = ((int) $p['ativo']) === 0;
        if ($vaiPublicar && !self::temConteudo((string) $p['conteudo'])) {
            return ['ok' => false, 'msg' => 'Esta página ainda está curta demais para ir ao ar. '
                . 'Abra e escreva o texto antes de publicar.'];
        }

        $novo = $this->model->alternarAtivo($id);
        if ($novo === null) return ['ok' => false, 'msg' => 'Página não encontrada.'];

        return ['ok' => true, 'ativo' => $novo,
                'msg' => $novo ? 'Página publicada.' : 'Página despublicada.'];
    }

    /* =================================================================
       AS DUAS FONTES
       ================================================================= */

    /**
     * Todas as páginas publicadas — de arquivo E de banco — num formato só.
     *
     * Isto morava no PageController, e o painel não conseguia chamar: o
     * autoloader do admin não inclui app/controllers/, então
     * PageController::getAllPages() era "class not found" em toda tela do
     * painel que precisasse da lista (a de páginas e a do rodapé).
     *
     * Listar páginas é regra de domínio, não de um controller. Aqui o
     * rodapé, o mapa do site, o menu e o painel alcançam a mesma função.
     * O PageController::getAllPages() continua existindo e delega para cá.
     *
     * Arquivo vence em caso de slug repetido, igual à resolução da URL.
     */
    public static function todas(): array
    {
        $paginas        = self::emArquivo();
        $slugsEmArquivo = array_column($paginas, 'slug');

        try {
            foreach ((new Pagina())->publicadas() as $p) {
                if (in_array($p['slug'], $slugsEmArquivo, true)) continue;

                $paginas[] = [
                    'slug'          => $p['slug'],
                    'titulo'        => $p['titulo'],
                    'descricao'     => $p['meta_description'] ?? '',
                    'menu_label'    => $p['menu_label'] ?: $p['titulo'],
                    'menu_ordem'    => $p['ordem_menu'] !== null ? (int) $p['ordem_menu'] : 99,
                    'no_menu'       => (bool) $p['no_menu'],
                    'no_rodape'     => (bool) $p['no_rodape'],
                    'noindex'       => (bool) $p['noindex'],
                    'ativa'         => true,
                    'origem'        => 'banco',
                    'atualizado_em' => $p['atualizado_em'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            // Banco fora do ar não pode derrubar o menu inteiro: as páginas de
            // arquivo continuam listadas e a loja segue navegável.
            if (class_exists('LogService')) {
                LogService::exception($e, 'warning', 'app', ['onde' => 'PaginaService::todas']);
            }
        }

        usort($paginas, fn($a, $b) => ($a['menu_ordem'] ?? 99) <=> ($b['menu_ordem'] ?? 99));
        return $paginas;
    }

    /** Só as páginas montadas em /pages/{slug}/index.php. */
    public static function emArquivo(): array
    {
        if (!defined('ROOT_PATH')) return [];

        $paginas = [];
        foreach (glob(ROOT_PATH . '/pages/*/page.json') ?: [] as $jsonFile) {
            $config = json_decode((string) file_get_contents($jsonFile), true);
            if (!is_array($config) || !($config['ativa'] ?? true)) continue;

            $config['slug']   = basename(dirname($jsonFile));
            $config['origem'] = 'arquivo';
            $paginas[]        = $config;
        }
        return $paginas;
    }

    /* =================================================================
       AUXILIARES
       ================================================================= */

    /**
     * Acentuada → sem acento, tabela explícita.
     *
     * Não se usa iconv('ASCII//TRANSLIT') aqui: o resultado dele depende da
     * biblioteca C do sistema. Neste servidor (Windows) "ç" virava "c'" e
     * "devoluções" saía como "devoluc-oes"; em glibc sairia "devolucoes". Slug
     * que muda conforme o sistema operacional é URL que muda no deploy.
     */
    private const ACENTOS = [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n','ý'=>'y','ÿ'=>'y',
        'Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a','Ä'=>'a','Å'=>'a',
        'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
        'Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
        'Ó'=>'o','Ò'=>'o','Õ'=>'o','Ô'=>'o','Ö'=>'o',
        'Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
        'Ç'=>'c','Ñ'=>'n','Ý'=>'y',
        'ª'=>'a','º'=>'o','ß'=>'ss','æ'=>'ae','Æ'=>'ae','ø'=>'o','Ø'=>'o',
    ];

    /**
     * Texto → slug.
     *
     * Acento vira a letra sem acento, não some: "Trocas e devoluções" tem que
     * virar "trocas-e-devolucoes", e não "trocas-e-devolues".
     *
     * O paginas.js faz o mesmo no navegador (via normalize NFD) para que a
     * prévia do endereço bata com o que o servidor vai gravar.
     */
    public static function slugify(string $texto): string
    {
        $s = trim($texto);
        if ($s === '') return '';

        $s = strtr($s, self::ACENTOS);
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    /** Tem texto suficiente para valer uma página pública? */
    public static function temConteudo(?string $html): bool
    {
        $texto = trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8'));
        $texto = preg_replace('/\s+/u', ' ', $texto) ?? '';
        return mb_strlen($texto) >= self::MIN_CONTEUDO;
    }

    /** O slug já é de uma página montada em arquivo? */
    public static function existeEmArquivo(string $slug): bool
    {
        if ($slug === '' || !defined('ROOT_PATH')) return false;
        $dir = ROOT_PATH . '/pages/' . $slug;
        return is_dir($dir) && is_file($dir . '/index.php');
    }
}
