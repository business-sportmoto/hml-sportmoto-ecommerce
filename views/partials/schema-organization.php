<?php
// ════════════════════════════════════════════════════════
// SCHEMA.ORG JSON-LD — Organization + Store (SEO local)
// SportMoto tem loja física → tipo "Store" (subtipo de
// LocalBusiness + Organization): cobre Knowledge Panel (marca)
// E potencial Local Pack / Google Maps (loja física).
//
// GLOBAL: renderiza UMA vez, em TODAS as páginas (é a mesma
// empresa sempre). Vai no <head> do layout, não por-página.
//
// IDEAL: virar método SeoHelper::setOrganization() no padrão do
// setWebSite() que já existe. Alternativa: incluir este bloco
// direto no <head> do main.php.
// ════════════════════════════════════════════════════════

$org = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Store',                       // loja física + online
    '@id'         => BASE_URL . '/#organization',   // âncora p/ referências
    'name'        => 'Sportmoto',
    'url'         => BASE_URL,
    'description' => 'Loja de peças e acessórios para motos e motociclistas.',

    // Logo — ver RESSALVA sobre SVG abaixo. Google prefere PNG/JPG
    // p/ o campo logo; o SVG pode não ser aceito no Knowledge Panel.
    'logo'  => 'https://hml.sportmoto.com.br/uploads/logo/logo.svg',
    'image' => 'https://hml.sportmoto.com.br/uploads/logo/logo.svg',

    // Perfis sociais oficiais — conecta as redes à marca (evita
    // que perfis falsos apareçam como oficiais na busca)
    'sameAs' => [
        'https://www.instagram.com/Sportmotopoa/',
        'https://www.facebook.com/sportmotopoa/',
        'https://www.tiktok.com/@sportmotopoa',
    ],

    // Contato
    'telephone' => '+5551997824826',
    'email'     => 'ecommerce@sportmoto.com.br',

    'contactPoint' => [
        '@type'             => 'ContactPoint',
        'telephone'         => '+5551997824826',
        'contactType'       => 'customer service',   // SAC e vendas
        'availableLanguage' => ['Portuguese'],
        'areaServed'        => 'BR',
    ],

    // Endereço físico (loja que atende público)
    'address' => [
        '@type'           => 'PostalAddress',
        'streetAddress'   => 'Avenida Juca Batista, 480',
        'addressLocality' => 'Porto Alegre',
        'addressRegion'   => 'RS',
        'postalCode'      => '91770-000',
        'addressCountry'  => 'BR',
    ],

    // Bairro (ajuda SEO local)
    'areaServed' => [
        '@type' => 'City',
        'name'  => 'Porto Alegre',
    ],

    // CNPJ como identificador oficial
    'identifier' => [
        '@type' => 'PropertyValue',
        'name'  => 'CNPJ',
        'value' => '40.244.615/0001-88',
    ],

    // Horário de funcionamento — Seg-Sex 8h-19h, Sáb 8h-14h
    'openingHoursSpecification' => [
        [
            '@type'     => 'OpeningHoursSpecification',
            'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday'],
            'opens'     => '08:00',
            'closes'    => '19:00',
        ],
        [
            '@type'     => 'OpeningHoursSpecification',
            'dayOfWeek' => 'Saturday',
            'opens'     => '08:00',
            'closes'    => '14:00',
        ],
    ],
];
?>
<script type="application/ld+json">
<?= json_encode($org, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>