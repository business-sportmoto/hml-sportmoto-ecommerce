<?php
// app/helpers/SeoHelper.php
// Gerencia todos os metadados de SEO: title, description, canonical,
// Open Graph, Twitter Card, JSON-LD e breadcrumb schema.

class SeoHelper {

    private static array $meta = [];

    // ── Setters básicos ───────────────────────────────────────

    public static function setTitle(string $title, bool $withSuffix = true): void {
        $sufixo = $withSuffix ? ConfigHelper::get('seo_title_sufixo', '') : '';
        self::$meta['title'] = View::e($title . $sufixo);
    }

    public static function setDescription(string $desc): void {
        $clean = preg_replace('/\s+/', ' ', strip_tags($desc));
        self::$meta['description'] = View::e(mb_substr(trim($clean), 0, 160));
    }

    public static function setCanonical(string $url): void {
        self::$meta['canonical'] = filter_var($url, FILTER_SANITIZE_URL);
    }

    public static function setRobots(string $content): void {
        self::$meta['robots'] = $content;
    }

    public static function setOg(string $key, string $value): void {
        self::$meta['og'][$key] = View::e($value);
    }

    public static function setTwitter(string $key, string $value): void {
        self::$meta['twitter'][$key] = View::e($value);
    }

    // app/helpers/SeoHelper.php — adicionar após o método setBreadcrumb()

    /**
     * Define um JSON-LD (substitui todos os anteriores do mesmo tipo).
     */
    public static function setJsonLd(array $data): void {
        self::$meta['jsonld'][] = $data;
    }

    /**
     * Adiciona um JSON-LD extra (acumula).
     */
    public static function addJsonLd(array $data): void {
        self::$meta['jsonld'][] = $data;
    }

    // ── Imagem para redes sociais ─────────────────────────────

    /**
     * A imagem que WhatsApp, Instagram, Facebook e X mostram no link.
     *
     * ── POR QUE NÃO SERVE A IMAGEM DO SITE DIRETO ────────────────────
     *
     * As fotos de produto são **WebP** (`-full.webp` no R2). O WhatsApp não
     * renderiza WebP em prévia de link: o link aparece sem imagem nenhuma,
     * que foi o sintoma relatado. O Instagram e o Facebook idem, no melhor
     * caso cortam errado.
     *
     * Então a imagem social é **JPEG 1200×630** (proporção 1.91:1, o que os
     * três esperam), gerada na hora pelo redimensionador da Cloudflare no
     * próprio domínio de mídia — sem gravar arquivo novo e sem tocar no
     * acervo. Para imagem fora daquele domínio, devolve a URL absoluta como
     * está: melhor uma imagem no formato errado do que nenhuma.
     *
     * @param  string|null $url  absoluta, ou caminho do /uploads
     * @return string|null       null quando não há imagem — e aí é melhor
     *                           NÃO emitir og:image (ver render()).
     */
    public static function imagemSocial(?string $url, int $largura = 1200, int $altura = 630): ?string
    {
        $url = trim((string) $url);
        if ($url === '') return null;

        // Caminho relativo → absoluto. `/uploads/...` já vem completo de
        // alguns lugares; o resto é relativo à raiz do site.
        if (!preg_match('~^https?://~i', $url)) {
            $url = str_starts_with($url, '/')
                 ? BASE_URL . $url
                 : UPLOAD_URL . '/' . ltrim($url, '/');
        }

        // `getenv()` e nao `env()`: o projeto le variavel de ambiente assim
        // (ImageUploadService, core/View). Com `env()` a conversao nunca
        // rodava e o og:image continuava saindo em WebP.
        $media = rtrim((string) getenv('R2_MEDIA_PUBLIC_URL'), '/');
        if ($media !== '' && str_starts_with($url, $media)) {
            $caminho = ltrim(substr($url, strlen($media)), '/');
            return $media . '/cdn-cgi/image/'
                 . "width={$largura},height={$altura},fit=cover,format=jpeg,quality=82/"
                 . $caminho;
        }

        return $url;
    }

    /**
     * Imagem de último recurso: a logo da loja.
     *
     * Antes o padrão era `assets/images/og-default.jpg`, arquivo que NÃO
     * existe (404). Link sem imagem e link apontando para imagem quebrada
     * dão o mesmo resultado na tela, mas o segundo ainda gasta a requisição
     * do robô e suja o log.
     */
    public static function imagemPadrao(): ?string
    {
        $logo = ConfigHelper::get('site_logo_vetor', '') ?: ConfigHelper::get('site_logo_png', '');
        if ($logo === '') return null;

        // Logo em SVG não serve: nenhuma rede social renderiza SVG.
        if (preg_match('~\.svgz?$~i', $logo)) return null;

        return self::imagemSocial(UPLOAD_URL . '/' . ltrim($logo, '/'));
    }

    // ── Atalhos para tipos específicos ────────────────────────

    /**
     * Página de produto: title, description, canonical, Open Graph e JSON-LD.
     *
     * ── O BUG QUE ISTO CORRIGE (12/09/2026) ──────────────────────────
     *
     * `produto_imagens.arquivo` guarda a URL COMPLETA da imagem no R2
     * (`https://media.…/produtos/ab/….webp`). O código antigo ainda colava
     * `UPLOAD_URL . '/products/'` na frente, produzindo
     * `https://loja/uploads/products/https://media…` — uma URL que não
     * existe. E como o ProductController chamava sem as imagens, todo
     * produto caía na imagem padrão, que também não existe (404). Resultado:
     * link compartilhado no WhatsApp e no Instagram sem imagem nenhuma.
     *
     * @param array $product
     * @param array $images  linhas de produto_imagens (campo `arquivo`)
     */
    public static function setProduct(array $product, array $images = []): void {
        $preco     = PriceHelper::currentPrice($product);
        $temEstoque = ((int)($product['estoque_total'] ?? 1)) > 0;
        $available = $temEstoque ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
        $url       = BASE_URL . '/produto/' . $product['slug'];
        $marca     = trim((string) ($product['marca_nome'] ?? ''));

        // Descrição em cascata: a curta é a boa; sem ela, a longa sem HTML;
        // sem nada, uma frase que ao menos diz o que é e de quem é.
        $descricao = trim((string) ($product['meta_description'] ?? ''))
                  ?: trim((string) ($product['descricao_curta'] ?? ''))
                  ?: trim(preg_replace('/\s+/', ' ', strip_tags((string) ($product['descricao'] ?? ''))))
                  ?: trim($product['nome'] . ($marca !== '' ? ' — ' . $marca : '') . '. '
                        . ($temEstoque ? 'Em estoque, pronta entrega.' : 'Consulte disponibilidade.'));
        $descricao = mb_substr($descricao, 0, 300);

        self::setTitle($product['meta_title'] ?: $product['nome']);
        self::setDescription($descricao);
        self::setCanonical($url);

        // ── Imagens ──────────────────────────────────────────────────
        // `arquivo` já é URL absoluta. Nada de prefixo.
        $arquivos = [];
        foreach ($images as $img) {
            $a = is_array($img) ? ($img['arquivo'] ?? '') : (string) $img;
            if ($a !== '') $arquivos[] = $a;
        }
        if (!$arquivos && !empty($product['imagem_principal'])) {
            $arquivos[] = (string) $product['imagem_principal'];
        }

        foreach (array_slice($arquivos, 0, 3) as $a) {
            $social = self::imagemSocial($a);
            if ($social) self::$meta['og_images'][] = $social;
        }
        if (empty(self::$meta['og_images'])) {
            $padrao = self::imagemPadrao();
            if ($padrao) self::$meta['og_images'][] = $padrao;
        }
        self::$meta['og_image_alt'] = $product['nome'];

        // Open Graph produto
        self::setOg('type',            'product');
        self::setOg('title',            $product['nome']);
        self::setOg('description',      $descricao);
        self::setOg('url',              $url);
        self::setOg('product:price:amount',   number_format($preco, 2, '.', ''));
        self::setOg('product:price:currency', 'BRL');
        self::setOg('product:availability',   $temEstoque ? 'instock' : 'oos');
        if ($marca !== '') self::setOg('product:brand', $marca);

        // Twitter Card
        self::setTwitter('card',        'summary_large_image');
        self::setTwitter('title',       $product['nome']);
        self::setTwitter('description', $descricao);

        // JSON-LD Product
        $ld = [
            '@context'    => 'https://schema.org/',
            '@type'       => 'Product',
            '@id'         => $url . '#product',
            'name'        => $product['nome'],
            'description' => $descricao,
            'url'         => $url,
            'sku'         => $product['sku_legado'] ?: (string) ($product['id'] ?? ''),
            'offers'      => [
                '@type'         => 'Offer',
                'url'           => $url,
                'priceCurrency' => 'BRL',
                'price'         => number_format($preco, 2, '.', ''),
                // Sem validade o Google marca "price is missing validity".
                'priceValidUntil' => date('Y-m-d', strtotime('+1 year')),
                'itemCondition' => 'https://schema.org/NewCondition',
                'availability'  => $available,
                'seller'        => ['@type' => 'Organization',
                                    'name'  => ConfigHelper::get('site_nome', '')],
            ],
        ];
        if ($marca !== '') {
            $ld['brand'] = ['@type' => 'Brand', 'name' => $marca];
        }
        if (!empty($product['sku_legado'])) {
            $ld['mpn'] = $product['sku_legado'];
        }

        if ($arquivos) {
            // No schema vale a imagem original (o Google lê WebP); a conversão
            // para JPEG existe por causa das redes sociais, não da busca.
            $ld['image'] = array_slice($arquivos, 0, 5);
        }

        if (!empty($product['review_stats']['total'])) {
            $ld['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $product['review_stats']['media'],
                'reviewCount' => $product['review_stats']['total'],
                'bestRating'  => '5',
                'worstRating' => '1',
            ];
        }

        self::$meta['jsonld'][] = $ld;
    }

    /**
     * Página de LISTAGEM de produtos: categoria, marca, montadora, modelo de
     * moto e busca. Tudo o que mostra vários produtos passa por aqui.
     *
     * Até 12/09/2026 nenhuma delas chamava o SeoHelper: o `<title>` era o nome
     * da loja, a descrição era a genérica da home, não havia canonical e o
     * `og:url` apontava para a home — em TODAS. Compartilhar uma categoria no
     * WhatsApp mostrava "Sportmoto" e a home.
     *
     * @param array{
     *   titulo:string, descricao?:string, url:string, imagem?:?string,
     *   produtos?:array, total?:int, pagina?:int, totalPaginas?:int,
     *   filtrado?:bool, indexavel?:bool, breadcrumb?:array, tipo?:string
     * } $o
     */
    public static function setColecao(array $o): void {
        $titulo  = trim((string) ($o['titulo'] ?? ''));
        $url     = (string) ($o['url'] ?? BASE_URL);
        $pagina  = max(1, (int) ($o['pagina'] ?? 1));
        $paginas = max(1, (int) ($o['totalPaginas'] ?? 1));
        $total   = (int) ($o['total'] ?? 0);

        // Página 2+ com o mesmo título vira conteúdo duplicado aos olhos do
        // Google. O número no título resolve, e o canonical aponta para a
        // própria página — nunca para a 1, senão as demais somem do índice.
        self::setTitle($titulo . ($pagina > 1 ? " — página {$pagina}" : ''));
        self::setDescription((string) ($o['descricao'] ?? ''));

        // Categoria e busca paginam com `?pagina=`; a listagem por moto usa
        // `?page=`. Quem chama diz qual é o seu, senão o canonical aponta
        // para uma URL que a página não sabe ler.
        $pp = (string) ($o['paramPagina'] ?? 'pagina');
        $canonical = $url . ($pagina > 1 ? (str_contains($url, '?') ? '&' : '?') . $pp . '=' . $pagina : '');
        self::setCanonical($canonical);

        // Filtro aplicado (cor, preço, atributo) cria combinação infinita de
        // URLs com o mesmo conteúdo: segue os links, não indexa a combinação.
        $indexavel = ($o['indexavel'] ?? true) && empty($o['filtrado']);
        self::setRobots($indexavel ? 'index, follow' : 'noindex, follow');

        if ($paginas > 1) {
            if ($pagina > 1) {
                self::$meta['prev'] = $url . ($pagina - 1 > 1 ? (str_contains($url, '?') ? '&' : '?') . $pp . '=' . ($pagina - 1) : '');
            }
            if ($pagina < $paginas) {
                self::$meta['next'] = $url . (str_contains($url, '?') ? '&' : '?') . $pp . '=' . ($pagina + 1);
            }
        }

        self::setOg('type',        'website');
        self::setOg('title',        $titulo);
        self::setOg('description', (string) ($o['descricao'] ?? ''));
        self::setOg('url',          $canonical);

        // A imagem do compartilhamento é a do primeiro produto da lista: é o
        // que representa a página. Sem produtos, a logo.
        $produtos = array_values($o['produtos'] ?? []);
        $imagem   = $o['imagem'] ?? null;
        if (!$imagem) {
            // O primeiro produto COM foto, nao o primeiro da lista: produto
            // sem imagem e comum e derrubava a pagina inteira para a logo.
            foreach ($produtos as $p) {
                if (!empty($p['imagem_principal'])) { $imagem = $p['imagem_principal']; break; }
            }
        }
        $social   = self::imagemSocial($imagem) ?: self::imagemPadrao();
        if ($social) {
            self::$meta['og_images'][] = $social;
            self::$meta['og_image_alt'] = $titulo;
        }

        self::setTwitter('card',        'summary_large_image');
        self::setTwitter('title',       $titulo);
        self::setTwitter('description', (string) ($o['descricao'] ?? ''));

        // JSON-LD: a coleção e a lista de produtos que ela mostra.
        $ld = [
            '@context'        => 'https://schema.org',
            '@type'           => 'CollectionPage',
            '@id'             => $canonical . '#colecao',
            'name'            => $titulo,
            'url'             => $canonical,
            // Referencia por @id em vez de repetir o no: o WebSite ja esta
            // declarado uma vez por pagina (setWebSite).
            'isPartOf'        => ['@id' => BASE_URL . '/#website'],
        ];
        if (!empty($o['descricao'])) $ld['description'] = (string) $o['descricao'];

        if ($produtos) {
            $offset = ($pagina - 1) * max(1, (int) ($o['porPagina'] ?? count($produtos)));
            $itens  = [];
            foreach (array_slice($produtos, 0, 24) as $i => $p) {
                if (empty($p['slug'])) continue;
                $pUrl  = BASE_URL . '/produto/' . $p['slug'];
                $preco = isset($p['preco']) ? PriceHelper::currentPrice($p) : null;
                $item  = ['@type' => 'Product', 'name' => $p['nome'] ?? '', 'url' => $pUrl];
                if (!empty($p['imagem_principal'])) $item['image'] = $p['imagem_principal'];
                if (!empty($p['marca_nome']))       $item['brand'] = ['@type' => 'Brand', 'name' => $p['marca_nome']];
                if ($preco !== null && $preco > 0) {
                    $item['offers'] = [
                        '@type'         => 'Offer',
                        'url'           => $pUrl,
                        'priceCurrency' => 'BRL',
                        'price'         => number_format((float) $preco, 2, '.', ''),
                        'availability'  => ((int) ($p['estoque_total'] ?? 1)) > 0
                                         ? 'https://schema.org/InStock'
                                         : 'https://schema.org/OutOfStock',
                    ];
                }
                $itens[] = ['@type' => 'ListItem', 'position' => $offset + $i + 1, 'item' => $item];
            }
            if ($itens) {
                $ld['mainEntity'] = [
                    '@type'           => 'ItemList',
                    'numberOfItems'   => $total ?: count($itens),
                    'itemListElement' => $itens,
                ];
            }
        }
        self::$meta['jsonld'][] = $ld;

        if (!empty($o['breadcrumb'])) self::setBreadcrumb($o['breadcrumb']);
    }

    public static function setCategory(array $category): void {
        self::setTitle($category['meta_title'] ?: $category['nome']);
        self::setDescription($category['meta_description'] ?: $category['descricao'] ?? '');
        self::setCanonical(BASE_URL . '/categoria/' . $category['slug']);
        self::setOg('type', 'website');
        self::setOg('title', $category['nome']);
    }

    /**
     * Gera breadcrumb JSON-LD (BreadcrumbList).
     */
    public static function setBreadcrumb(array $items): void {
        $list = [];
        foreach ($items as $pos => $item) {
            $list[] = [
                '@type'    => 'ListItem',
                'position' => $pos + 1,
                'name'     => $item['label'],
                'item'     => $item['url'] ?? '',
            ];
        }
        self::$meta['jsonld'][] = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $list,
        ];
    }

    /**
     * JSON-LD da organização (colocar na home).
     * Tipo "Store": loja física + online → Knowledge Panel E Local Pack.
     */
    public static function setOrganization(): void {
        $siteName = ConfigHelper::get('site_nome', '');
        $logo     = ConfigHelper::get('site_logo_vetor', '');

        $ld = [
            '@context' => 'https://schema.org',
            '@type'    => 'Store',                       // era Organization
            '@id'      => BASE_URL . '/#organization',
            'name'     => $siteName,
            'url'      => BASE_URL,
            'logo'     => !empty($logo) ? BASE_URL. '/uploads' . $logo : '',
            'image'    => !empty($logo) ? BASE_URL . '/uploads' . $logo : '',
            'description' => ConfigHelper::get('site_descricao', ''),
            'contactPoint' => [
                '@type'             => 'ContactPoint',
                'telephone'         => ConfigHelper::get('site_telefone', ''),
                'contactType'       => 'customer service',
                'availableLanguage' => 'Portuguese',
                'areaServed'        => 'BR',
            ],
            'sameAs' => array_values(array_filter([
                ConfigHelper::get('social_instagram', ''),
                ConfigHelper::get('social_facebook',  ''),
                ConfigHelper::get('social_youtube',   ''),
                ConfigHelper::get('social_tiktok',    ''),
            ])),
        ];

        $priceRange = ConfigHelper::get('site_price_range', '');
        if (!empty($priceRange)) {
            $ld['priceRange'] = $priceRange;
        }

        // E-mail (se configurado)
        $email = ConfigHelper::get('site_email', '');
        if (!empty($email)) {
            $ld['email'] = $email;
        }

        // Endereço físico — só monta se houver logradouro configurado
        $logradouro = ConfigHelper::get('endereco_logradouro', '');
        if (!empty($logradouro)) {
            $ld['address'] = [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $logradouro,
                'addressLocality' => ConfigHelper::get('endereco_cidade', ''),
                'addressRegion'   => ConfigHelper::get('endereco_uf', ''),
                'postalCode'      => ConfigHelper::get('endereco_cep', ''),
                'addressCountry'  => 'BR',
            ];
        }

        // CNPJ (se configurado)
        $cnpj = ConfigHelper::get('empresa_cnpj', '');
        if (!empty($cnpj)) {
            $ld['identifier'] = [
                '@type' => 'PropertyValue',
                'name'  => 'CNPJ',
                'value' => $cnpj,
            ];
        }

        // Horário — só monta se as configs de horário existirem.
        // Formato: "08:00" / "19:00" por bloco. Domingo omitido.
        $abreSemana = ConfigHelper::get('horario_semana_abre', '');
        if (!empty($abreSemana)) {
            $ld['openingHoursSpecification'] = [
                [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday'],
                    'opens'     => $abreSemana,
                    'closes'    => ConfigHelper::get('horario_semana_fecha', ''),
                ],
            ];
            // Sábado (se configurado)
            $abreSab = ConfigHelper::get('horario_sabado_abre', '');
            if (!empty($abreSab)) {
                $ld['openingHoursSpecification'][] = [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => 'Saturday',
                    'opens'     => $abreSab,
                    'closes'    => ConfigHelper::get('horario_sabado_fecha', ''),
                ];
            }
        }

        self::$meta['jsonld'][] = $ld;
    }

    /**
     * JSON-LD de WebSite com SearchAction (busca no Google).
     */
    public static function setWebSite(): void {
        self::$meta['jsonld'][] = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            '@id'      => BASE_URL . '/#website',
            'url'      => BASE_URL,
            'name'     => ConfigHelper::get('site_nome', ''),
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => BASE_URL . '/busca?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    // ── Render ────────────────────────────────────────────────

    /**
     * Renderiza todas as metatags.
     * Chamar dentro do <head> via View::partial('partials/seo-tags').
     */
    public static function render(): string {
        $siteName = ConfigHelper::get('site_nome', '');
        $html     = '';

        // Title
        $title = self::$meta['title'] ?? ($siteName . ConfigHelper::get('seo_title_sufixo', ''));
        $html .= "<title>{$title}</title>\n";

        // Description
        $desc = self::$meta['description'] ?? ConfigHelper::get('seo_description', '');
        if ($desc) $html .= "<meta name=\"description\" content=\"{$desc}\">\n";

        // Robots
        $robots = self::$meta['robots'] ?? 'index, follow';
        $html  .= "<meta name=\"robots\" content=\"{$robots}\">\n";

        // Canonical
        if (!empty(self::$meta['canonical'])) {
            $c     = self::$meta['canonical'];
            $html .= "<link rel=\"canonical\" href=\"{$c}\">\n";
        }

        // Open Graph base
        $ogType  = self::$meta['og']['type']        ?? 'website';
        $ogTitle = self::$meta['og']['title']        ?? $title;
        $ogDesc  = self::$meta['og']['description']  ?? $desc;
        $ogUrl   = self::$meta['og']['url']          ?? (self::$meta['canonical'] ?? BASE_URL);
        // Sem imagem NENHUMA meta é melhor do que uma que dá 404: era o caso
        // do antigo `og-default.jpg`, arquivo que nunca existiu.
        $ogImg   = self::$meta['og_images'][0] ?? self::imagemPadrao();

        $html .= "<meta property=\"og:type\"        content=\"{$ogType}\">\n";
        $html .= "<meta property=\"og:site_name\"   content=\"" . View::e($siteName) . "\">\n";
        $html .= "<meta property=\"og:title\"       content=\"{$ogTitle}\">\n";
        $html .= "<meta property=\"og:description\" content=\"{$ogDesc}\">\n";
        $html .= "<meta property=\"og:url\"         content=\"{$ogUrl}\">\n";
        $html .= "<meta property=\"og:locale\"      content=\"pt_BR\">\n";

        if ($ogImg) {
            // Dimensão declarada: o WhatsApp monta o card sem precisar baixar a
            // imagem antes, e nenhuma rede corta a foto por conta própria.
            $alt = View::e((string) (self::$meta['og_image_alt'] ?? $siteName));
            $html .= "<meta property=\"og:image\"            content=\"{$ogImg}\">\n";
            $html .= "<meta property=\"og:image:secure_url\" content=\"{$ogImg}\">\n";
            $html .= "<meta property=\"og:image:type\"       content=\"image/jpeg\">\n";
            $html .= "<meta property=\"og:image:width\"      content=\"1200\">\n";
            $html .= "<meta property=\"og:image:height\"     content=\"630\">\n";
            $html .= "<meta property=\"og:image:alt\"        content=\"{$alt}\">\n";
        }

        // Paginação de listagem
        if (!empty(self::$meta['prev'])) {
            $html .= "<link rel=\"prev\" href=\"" . self::$meta['prev'] . "\">\n";
        }
        if (!empty(self::$meta['next'])) {
            $html .= "<link rel=\"next\" href=\"" . self::$meta['next'] . "\">\n";
        }

        // OG extras (produto)
        foreach (['product:price:amount','product:price:currency','product:availability'] as $k) {
            if (!empty(self::$meta['og'][$k])) {
                $v     = self::$meta['og'][$k];
                $html .= "<meta property=\"{$k}\" content=\"{$v}\">\n";
            }
        }

        // Múltiplas imagens OG
        if (!empty(self::$meta['og_images'])) {
            foreach (array_slice(self::$meta['og_images'], 1) as $img) {
                $html .= "<meta property=\"og:image\" content=\"{$img}\">\n";
            }
        }

        // Twitter Card
        $twCard  = self::$meta['twitter']['card']        ?? 'summary_large_image';
        $twTitle = self::$meta['twitter']['title']       ?? $ogTitle;
        $twDesc  = self::$meta['twitter']['description'] ?? $ogDesc;
        $html .= "<meta name=\"twitter:card\"        content=\"{$twCard}\">\n";
        $html .= "<meta name=\"twitter:title\"       content=\"{$twTitle}\">\n";
        $html .= "<meta name=\"twitter:description\" content=\"{$twDesc}\">\n";
        if ($ogImg) {
            $html .= "<meta name=\"twitter:image\"       content=\"{$ogImg}\">\n";
        }

        // JSON-LD blocks
        foreach (self::$meta['jsonld'] ?? [] as $ld) {
            $json  = json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $html .= "<script type=\"application/ld+json\">{$json}</script>\n";
        }

        return $html;
    }
    
}