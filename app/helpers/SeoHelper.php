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

    // ── Atalhos para tipos específicos ────────────────────────

    public static function setProduct(array $product, array $images = []): void {
        $preco     = PriceHelper::currentPrice($product);
        $available = ((int)($product['estoque_total'] ?? 1)) > 0
                     ? 'https://schema.org/InStock'
                     : 'https://schema.org/OutOfStock';

        self::setTitle($product['meta_title'] ?: $product['nome']);
        self::setDescription($product['meta_description'] ?: $product['descricao_curta'] ?? $product['nome']);
        self::setCanonical(BASE_URL . '/produto/' . $product['slug']);

        // Open Graph produto
        self::setOg('type',            'product');
        self::setOg('title',            $product['nome']);
        self::setOg('description',      $product['descricao_curta'] ?? '');
        self::setOg('url',              BASE_URL . '/produto/' . $product['slug']);
        self::setOg('product:price:amount',   (string)$preco);
        self::setOg('product:price:currency', 'BRL');
        self::setOg('product:availability',   $product['estoque_total'] > 0 ? 'instock' : 'oos');

        if (!empty($images)) {
            foreach (array_slice($images, 0, 3) as $img) {
                self::$meta['og_images'][] = UPLOAD_URL . '/products/' . $img['arquivo'];
            }
        }

        // Twitter Card
        self::setTwitter('card',        'summary_large_image');
        self::setTwitter('title',       $product['nome']);
        self::setTwitter('description', $product['descricao_curta'] ?? '');

        // JSON-LD Product
        $ld = [
            '@context'    => 'https://schema.org/',
            '@type'       => 'Product',
            'name'        => $product['nome'],
            'description' => strip_tags($product['descricao_curta'] ?? $product['nome']),
            'sku'         => $product['sku_legado'],
            'brand'       => ['@type' => 'Brand', 'name' => $product['marca_nome'] ?? ''],
            'offers'      => [
                '@type'         => 'Offer',
                'url'           => BASE_URL . '/produto/' . $product['slug'],
                'priceCurrency' => 'BRL',
                'price'         => number_format($preco, 2, '.', ''),
                'availability'  => $available,
                'seller'        => ['@type' => 'Organization',
                                    'name'  => ConfigHelper::get('site_nome', '')],
            ],
        ];

        if (!empty($images)) {
            $ld['image'] = array_map(
                fn($img) => UPLOAD_URL . '/products/' . $img['arquivo'],
                array_slice($images, 0, 5)
            );
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
        $ogImg   = self::$meta['og_images'][0]       ?? (ASSET_URL . '/images/og-default.jpg');

        $html .= "<meta property=\"og:type\"        content=\"{$ogType}\">\n";
        $html .= "<meta property=\"og:site_name\"   content=\"" . View::e($siteName) . "\">\n";
        $html .= "<meta property=\"og:title\"       content=\"{$ogTitle}\">\n";
        $html .= "<meta property=\"og:description\" content=\"{$ogDesc}\">\n";
        $html .= "<meta property=\"og:url\"         content=\"{$ogUrl}\">\n";
        $html .= "<meta property=\"og:image\"       content=\"{$ogImg}\">\n";
        $html .= "<meta property=\"og:locale\"      content=\"pt_BR\">\n";

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
        $html .= "<meta name=\"twitter:image\"       content=\"{$ogImg}\">\n";

        // JSON-LD blocks
        foreach (self::$meta['jsonld'] ?? [] as $ld) {
            $json  = json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $html .= "<script type=\"application/ld+json\">{$json}</script>\n";
        }

        return $html;
    }
    
}