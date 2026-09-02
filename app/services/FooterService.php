<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/FooterService.php
//
// Conteúdo do rodapé da loja.
//
// O rodapé era markup fixo: os links, os benefícios, as buscas populares e os
// textos viviam dentro de views/partials/footer.php. Editar um telefone exigia
// deploy. Aqui esse conteúdo passa a morar em `configuracoes`, e a view vira
// só apresentação.
//
// ── Duas famílias de chave, de propósito ──────────────────────────────────
// Dados da LOJA (nome, telefone, e-mail, CNPJ, endereço, redes, horários) já
// existiam e são usados em outras telas — nota fiscal, SEO, frete. O rodapé lê
// as MESMAS chaves em vez de criar cópias suas: dois telefones editáveis em
// lugares diferentes divergem no primeiro dia.
//
// Conteúdo do RODAPÉ (colunas de links, newsletter, selos, buscas) é só dele,
// e ganha chaves `footer_*` no grupo `footer`.
//
// ── Por que os padrões ficam aqui ─────────────────────────────────────────
// Todo campo tem padrão em código, igual ao que o rodapé mostrava antes. Sem
// nenhuma linha no banco a loja renderiza exatamente como renderizava — a
// migration só materializa o que já é o comportamento. Salvar pelo painel faz
// upsert, então o admin funciona mesmo com o banco intocado.
// ════════════════════════════════════════════════════════

class FooterService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /* =================================================================
       CHAVES
       ================================================================= */

    /** Chaves da loja que o rodapé exibe e o painel do rodapé também edita. */
    public const CHAVES_LOJA = [
        'site_nome', 'site_slogan', 'site_telefone', 'site_email', 'site_cnpj',
        'endereco_logradouro', 'endereco_cidade', 'endereco_uf', 'endereco_cep',
        'social_instagram', 'social_facebook', 'social_youtube', 'social_tiktok',
        'horario_semana_abre', 'horario_semana_fecha',
        'horario_sabado_abre', 'horario_sabado_fecha',
    ];

    /**
     * Chaves só do rodapé: padrão, tipo e descrição.
     *
     * O padrão é o conteúdo que estava fixo na view. Trocar um valor aqui muda
     * o que aparece para quem ainda não salvou nada no painel.
     */
    public static function definicoes(): array
    {
        return [
            'footer_descricao' => [
                'tipo' => 'text',
                'descricao' => 'Parágrafo de apresentação no rodapé',
                'padrao' => 'A maior loja de peças e acessórios para motos do Brasil. '
                    . 'Mais de 15 anos acelerando junto com você, com curadoria, '
                    . 'originalidade e suporte de quem entende.',
            ],
            'footer_whatsapp' => [
                'tipo' => 'string',
                'descricao' => 'WhatsApp exibido no rodapé (vazio = usa o telefone)',
                'padrao' => '',
            ],
            'footer_newsletter_ativo' => [
                'tipo' => 'bool', 'descricao' => 'Exibe o bloco de newsletter', 'padrao' => '1',
            ],
            'footer_newsletter_badge' => [
                'tipo' => 'string', 'descricao' => 'Selo acima do título da newsletter',
                'padrao' => 'Assine e ganhe R$ 10 off',
            ],
            'footer_newsletter_titulo' => [
                'tipo' => 'string', 'descricao' => 'Título da newsletter',
                'padrao' => 'Acelere com a gente. Cupom exclusivo na primeira compra.',
            ],
            'footer_newsletter_texto' => [
                'tipo' => 'text', 'descricao' => 'Texto de apoio da newsletter',
                'padrao' => 'Receba lançamentos, ofertas relâmpago e conteúdos para '
                    . 'motociclistas direto no seu e-mail.',
            ],
            'footer_newsletter_botao' => [
                'tipo' => 'string', 'descricao' => 'Rótulo do botão da newsletter',
                'padrao' => 'Quero meu cupom',
            ],

            // Cupom de boas-vindas: decisão comercial, não de código. O valor do
            // desconto muda com a margem, e margem não pode exigir deploy.
            // Quem lê estas chaves e cria o cupom é o NewsletterService.
            'footer_newsletter_cupom_ativo' => [
                'tipo' => 'bool', 'descricao' => 'Gera cupom ao confirmar a inscrição', 'padrao' => '1',
            ],
            'footer_newsletter_cupom_tipo' => [
                'tipo' => 'string', 'descricao' => 'Tipo do cupom (percentual ou fixo)', 'padrao' => 'fixo',
            ],
            'footer_newsletter_cupom_valor' => [
                'tipo' => 'string', 'descricao' => 'Valor do desconto', 'padrao' => '10',
            ],
            'footer_newsletter_cupom_minimo' => [
                'tipo' => 'string', 'descricao' => 'Pedido mínimo para usar o cupom', 'padrao' => '0',
            ],
            'footer_newsletter_cupom_dias' => [
                'tipo' => 'string', 'descricao' => 'Dias de validade do cupom', 'padrao' => '30',
            ],
            'footer_newsletter_cupom_prefixo' => [
                'tipo' => 'string', 'descricao' => 'Prefixo do código gerado', 'padrao' => 'BV',
            ],

            'footer_beneficios' => [
                'tipo' => 'json', 'descricao' => 'Faixa de benefícios do rodapé',
                'padrao' => [
                    ['icone' => 'entrega',  'titulo' => 'Frete grátis',       'texto' => 'Acima de R$ 299',       'link_texto' => '*Consulte regras', 'link_url' => '/prazos-de-entrega'],
                    ['icone' => 'escudo',   'titulo' => 'Compra segura',      'texto' => 'Ambiente SSL',          'link_texto' => '', 'link_url' => ''],
                    ['icone' => 'cartao',   'titulo' => 'Até 12x sem juros',  'texto' => 'Cartão de crédito',     'link_texto' => '', 'link_url' => ''],
                    ['icone' => 'suporte',  'titulo' => 'Suporte 7 dias',     'texto' => 'Atendimento humano',    'link_texto' => '', 'link_url' => ''],
                    ['icone' => 'medalha',  'titulo' => 'Garantia real',      'texto' => '3 meses em produtos',   'link_texto' => '', 'link_url' => ''],
                ],
            ],

            'footer_colunas' => [
                'tipo' => 'json', 'descricao' => 'Colunas de links do rodapé',
                'padrao' => [
                    ['titulo' => 'Institucional', 'auto' => 'paginas', 'links' => []],
                    ['titulo' => 'Atendimento', 'links' => [
                        ['label' => 'Central de ajuda',       'url' => '/central-de-ajuda'],
                        ['label' => 'Como comprar',           'url' => '/como-comprar'],
                        ['label' => 'Prazos de entrega',      'url' => '/prazos-de-entrega'],
                        ['label' => 'Trocas e devoluções',    'url' => '/trocas-e-devolucoes'],
                        ['label' => 'Rastrear pedido',        'url' => '/rastrear-pedido'],
                        ['label' => 'Política de privacidade','url' => '/politica-de-privacidade'],
                        ['label' => 'Termos de uso',          'url' => '/termos-de-uso'],
                    ]],
                    ['titulo' => 'Categorias', 'links' => [
                        ['label' => 'Capacetes',              'url' => '/categoria/capacetes'],
                        ['label' => 'Vestuário & Proteção',   'url' => '/categoria/vestuario-protecao'],
                        ['label' => 'Pneus & Rodas',          'url' => '/categoria/pneus-rodas'],
                        ['label' => 'Mecânica & Motor',       'url' => '/categoria/mecanica-motor'],
                        ['label' => 'Acessórios',             'url' => '/categoria/acessorios'],
                    ]],
                    ['titulo' => 'Minha conta', 'links' => [
                        ['label' => 'Meus pedidos',      'url' => '/minha-conta/pedidos'],
                        ['label' => 'Lista de desejos',  'url' => '/minha-conta/favoritos'],
                        ['label' => 'Notificações',      'url' => '/minha-conta/notificacoes'],
                        ['label' => 'Endereços salvos',  'url' => '/minha-conta/enderecos'],
                    ]],
                ],
            ],

            'footer_buscas' => [
                'tipo' => 'json', 'descricao' => 'Buscas populares do rodapé',
                'padrao' => [
                    'capacete fechado', 'capacete articulado', 'jaqueta de couro',
                    'luva motociclista', 'pneu pirelli', 'pneu michelin',
                    'escapamento esportivo', 'óleo motul', 'bateria moto',
                    'bagageiro givi', 'bauleto 50 litros', 'intercomunicador',
                ],
            ],

            'footer_pagamentos' => [
                'tipo' => 'json', 'descricao' => 'Bandeiras exibidas no rodapé',
                'padrao' => ['visa', 'mastercard', 'amex', 'elo', 'hipercard', 'diners', 'pix', 'boleto'],
            ],
            'footer_pagamento_nota' => [
                'tipo' => 'string', 'descricao' => 'Texto abaixo das bandeiras',
                'padrao' => 'Parcele em até 12x sem juros no cartão · 5% OFF no Pix',
            ],

            'footer_logistica' => [
                'tipo' => 'json', 'descricao' => 'Transportadoras citadas no rodapé',
                'padrao' => ['Correios', 'Sedex', 'Jadlog', 'Loggi', 'Total Express', 'Retira na loja'],
            ],
            'footer_logistica_nota' => [
                'tipo' => 'string', 'descricao' => 'Texto abaixo das transportadoras',
                'padrao' => 'Entregamos em todo o Brasil · Despacho em até 24h',
            ],

            'footer_selos' => [
                'tipo' => 'json', 'descricao' => 'Selos de segurança do rodapé',
                'padrao' => [
                    ['icone' => 'cadeado', 'titulo' => 'Conexão SSL',        'texto' => 'Criptografia 256-bit'],
                    ['icone' => 'escudo',  'titulo' => 'LGPD',               'texto' => 'Dados protegidos por lei'],
                    ['icone' => 'cartao',  'titulo' => 'Pagamento PCI DSS',  'texto' => 'Cartão nunca fica na loja'],
                ],
            ],
            'footer_selos_nota' => [
                'tipo' => 'string', 'descricao' => 'Texto abaixo dos selos',
                'padrao' => 'Checkout criptografado de ponta a ponta',
            ],

            'footer_links_legais' => [
                'tipo' => 'json', 'descricao' => 'Links da barra inferior',
                'padrao' => [
                    ['label' => 'Privacidade',       'url' => '/politica-de-privacidade'],
                    ['label' => 'Gerenciar cookies', 'url' => '/cookies'],
                    ['label' => 'Termos',            'url' => '/termos-de-uso'],
                    ['label' => 'Mapa do site',      'url' => '/mapa-do-site'],
                ],
            ],
            'footer_copyright_extra' => [
                'tipo' => 'string', 'descricao' => 'Frase extra ao lado do copyright',
                'padrao' => 'Preços e condições exclusivos para o site.',
            ],
            'footer_assinatura' => [
                'tipo' => 'string', 'descricao' => 'Assinatura no fim da barra inferior',
                'padrao' => '',
            ],
        ];
    }

    /* =================================================================
       LEITURA
       ================================================================= */

    /**
     * Tudo que a view do rodapé precisa, já normalizado.
     *
     * A view não decide nada: não compõe endereço, não escolhe fallback, não
     * checa se a lista é array. Quem erra esse tipo de coisa é sempre a view,
     * porque lá o erro é silencioso — some um bloco e ninguém vê.
     */
    public function dados(): array
    {
        $c = $this->valores();

        $telefone = trim((string) ConfigHelper::get('site_telefone', ''));
        $whats    = trim((string) $c['footer_whatsapp']) ?: $telefone;

        return [
            'nome'      => (string) ConfigHelper::get('site_nome', 'Loja'),
            'descricao' => (string) $c['footer_descricao'],
            'telefone'  => $telefone,
            'email'     => (string) ConfigHelper::get('site_email', ''),
            'cnpj'      => self::formatarCnpj((string) ConfigHelper::get('site_cnpj', '')),
            'endereco'  => self::montarEndereco(),
            'horario'   => self::montarHorario(),
            'whatsapp'  => $whats,
            'whats_url' => $whats !== '' ? 'https://wa.me/' . self::ddi($whats) : '',
            'social'    => $this->social(),

            'newsletter' => [
                'ativo'  => (bool) $c['footer_newsletter_ativo'],
                'badge'  => (string) $c['footer_newsletter_badge'],
                'titulo' => (string) $c['footer_newsletter_titulo'],
                'texto'  => (string) $c['footer_newsletter_texto'],
                'botao'  => (string) $c['footer_newsletter_botao'],
            ],

            'beneficios' => self::listaDe($c['footer_beneficios']),
            'colunas'    => $this->colunas(self::listaDe($c['footer_colunas'])),
            'buscas'     => array_values(array_filter(array_map(
                'strval', self::listaDe($c['footer_buscas'])
            ), fn($t) => trim($t) !== '')),

            'pagamentos'      => self::listaDe($c['footer_pagamentos']),
            'pagamento_nota'  => (string) $c['footer_pagamento_nota'],
            'logistica'       => self::listaDe($c['footer_logistica']),
            'logistica_nota'  => (string) $c['footer_logistica_nota'],
            'selos'           => self::listaDe($c['footer_selos']),
            'selos_nota'      => (string) $c['footer_selos_nota'],

            'links_legais'     => self::listaDe($c['footer_links_legais']),
            'copyright_extra'  => (string) $c['footer_copyright_extra'],
            'assinatura'       => (string) $c['footer_assinatura'],
        ];
    }

    /** Valores das chaves `footer_*`, caindo no padrão quando não há linha. */
    public function valores(): array
    {
        $defs  = self::definicoes();
        $salvo = [];

        $in   = implode(',', array_fill(0, count($defs), '?'));
        $stmt = $this->db->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ({$in})");
        $stmt->execute(array_keys($defs));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $salvo[$r['chave']] = $r['valor'];
        }

        $out = [];
        foreach ($defs as $chave => $def) {
            // Linha ausente é diferente de valor vazio: quem apagou o texto no
            // painel quer o campo vazio na loja, não o padrão de volta.
            if (!array_key_exists($chave, $salvo) || $salvo[$chave] === null) {
                $out[$chave] = $def['padrao'];
                continue;
            }

            if ($def['tipo'] === 'json') {
                $dec = json_decode((string) $salvo[$chave], true);
                $out[$chave] = is_array($dec) ? $dec : $def['padrao'];
                continue;
            }

            $out[$chave] = (string) $salvo[$chave];
        }

        return $out;
    }

    /** Chaves da loja com o valor atual, para o formulário do painel. */
    public function valoresLoja(): array
    {
        $out = [];
        foreach (self::CHAVES_LOJA as $chave) {
            $out[$chave] = (string) ConfigHelper::get($chave, '');
        }
        return $out;
    }

    /**
     * Resolve as colunas marcadas como automáticas.
     *
     * O rodápé antigo preenchia a primeira coluna com as páginas de /pages
     * marcadas com `no_menu` — e as 5 páginas reais da loja estão lá. Virou
     * opção explícita por coluna em vez de regra escondida na view: dá para ter
     * a coluna automática e as manuais lado a lado, e quem olha o painel vê
     * por que aquela coluna não tem links para editar.
     */
    private function colunas(array $colunas): array
    {
        foreach ($colunas as &$col) {
            if (($col['auto'] ?? '') !== 'paginas') continue;
            $col['links'] = self::linksDasPaginas();
        }
        unset($col);
        return $colunas;
    }

    /**
     * Páginas que pedem lugar no rodapé — de arquivo e de banco.
     *
     * As duas fontes declaram isso de jeitos diferentes: a página de banco tem
     * `no_rodape` próprio, e a de arquivo só tem `no_menu` no page.json. Sem
     * essa distinção, uma página criada no painel marcada só para o rodapé
     * ficaria de fora justamente do rodapé — que é onde termos e privacidade
     * moram.
     */
    public static function linksDasPaginas(): array
    {
        $out = [];
        foreach (PaginaService::todas() as $pg) {
            $quer = array_key_exists('no_rodape', $pg)
                ? !empty($pg['no_rodape'])
                : !empty($pg['no_menu']);
            if (!$quer) continue;

            $out[] = [
                'label' => (string) ($pg['menu_label'] ?? $pg['titulo'] ?? $pg['slug']),
                'url'   => '/' . (string) $pg['slug'],
            ];
        }
        return $out;
    }

    private function social(): array
    {
        $redes = [];
        foreach (['instagram', 'facebook', 'youtube', 'tiktok'] as $rede) {
            $url = trim((string) ConfigHelper::get('social_' . $rede, ''));
            if ($url !== '' && $url !== '#') $redes[$rede] = $url;
        }
        return $redes;
    }

    /* =================================================================
       ESCRITA
       ================================================================= */

    /**
     * Grava o que veio do formulário.
     *
     * Upsert, não update: ConfigHelper::set() só faz UPDATE, e uma chave que
     * ainda não existe no banco seria salva no vazio — o painel diria "salvo" e
     * nada mudaria na loja.
     */
    public function salvar(array $post): array
    {
        $defs = self::definicoes();

        $this->db->beginTransaction();
        try {
            foreach ($defs as $chave => $def) {
                if (!array_key_exists($chave, $post)) continue;
                $this->gravar($chave, $this->normalizar($post[$chave], $def['tipo']),
                              $def['tipo'], 'footer', $def['descricao']);
            }

            // Dados da loja: mesmas chaves que as outras telas usam.
            foreach (self::CHAVES_LOJA as $chave) {
                if (!array_key_exists($chave, $post)) continue;
                $this->gravar($chave, SecurityHelper::sanitizeString((string) $post[$chave]));
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            LogService::exception($e, 'error', 'app', ['onde' => 'FooterService::salvar']);
            return ['ok' => false, 'msg' => 'Falha ao salvar o rodapé.'];
        }

        ConfigHelper::limparCache();
        return ['ok' => true, 'msg' => 'Rodapé salvo.'];
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE.
     *
     * Nas chaves da loja, tipo/grupo/descrição vêm nulos: a linha já existe e
     * sobrescrever a categoria dela pelo formulário do rodapé bagunçaria a tela
     * de Configurações, que agrupa por `grupo`.
     */
    private function gravar(
        string $chave, string $valor,
        ?string $tipo = null, ?string $grupo = null, ?string $descricao = null
    ): void {
        if ($tipo === null) {
            $st = $this->db->prepare("UPDATE configuracoes SET valor = ? WHERE chave = ?");
            $st->execute([$valor, $chave]);
            if ($st->rowCount() > 0) return;

            // Chave da loja que ainda não existe (banco novo): cria no geral.
            $tipo = 'string'; $grupo = 'geral'; $descricao = null;
        }

        $this->db->prepare(
            "INSERT INTO configuracoes (chave, valor, tipo, grupo, descricao)
                  VALUES (:c, :v, :t, :g, :d)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
        )->execute([':c' => $chave, ':v' => $valor, ':t' => $tipo, ':g' => $grupo, ':d' => $descricao]);
    }

    private function normalizar(mixed $valor, string $tipo): string
    {
        if ($tipo === 'json') {
            $lista = is_array($valor) ? $valor : (json_decode((string) $valor, true) ?? []);
            return json_encode(self::limpar($lista), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($tipo === 'bool') {
            return (string) (int) (bool) $valor;
        }
        // `text` guarda parágrafo: tags fora, quebras de linha preservadas.
        $s = is_array($valor) ? '' : (string) $valor;
        return trim(strip_tags($s));
    }

    /** Tira linhas em branco das listas — o formulário manda campos vazios. */
    private static function limpar(array $lista): array
    {
        $out = [];
        foreach ($lista as $item) {
            if (is_array($item)) {
                $item = array_map(fn($v) => is_array($v) ? self::limpar($v) : trim(strip_tags((string) $v)), $item);
                $temTexto = false;
                foreach ($item as $k => $v) {
                    if ($k === 'links') continue;
                    if (is_string($v) && $v !== '') { $temTexto = true; break; }
                }
                if (!$temTexto && empty($item['links'])) continue;
                $out[] = $item;
                continue;
            }
            $item = trim(strip_tags((string) $item));
            if ($item !== '') $out[] = $item;
        }
        return $out;
    }

    /* =================================================================
       APRESENTAÇÃO
       ================================================================= */

    /** Endereço em uma linha, a partir das chaves do grupo `address`. */
    public static function montarEndereco(): string
    {
        $rua    = trim((string) ConfigHelper::get('endereco_logradouro', ''));
        $cidade = trim((string) ConfigHelper::get('endereco_cidade', ''));
        $uf     = trim((string) ConfigHelper::get('endereco_uf', ''));
        $cep    = preg_replace('/\D/', '', (string) ConfigHelper::get('endereco_cep', '')) ?? '';

        $partes = [];
        if ($rua !== '')    $partes[] = $rua;
        if ($cidade !== '') $partes[] = $cidade . ($uf !== '' ? ' / ' . $uf : '');
        if (strlen($cep) === 8) $partes[] = 'CEP ' . substr($cep, 0, 5) . '-' . substr($cep, 5);

        return implode(' — ', $partes);
    }

    /** Linha de horário a partir das chaves do grupo `hour`. */
    public static function montarHorario(): string
    {
        $sa = (string) ConfigHelper::get('horario_semana_abre', '');
        $sf = (string) ConfigHelper::get('horario_semana_fecha', '');
        $ba = (string) ConfigHelper::get('horario_sabado_abre', '');
        $bf = (string) ConfigHelper::get('horario_sabado_fecha', '');

        $partes = [];
        if ($sa !== '' && $sf !== '') $partes[] = "Seg a Sex: {$sa} às {$sf}";
        if ($ba !== '' && $bf !== '') $partes[] = "Sáb: {$ba} às {$bf}";
        return implode(' · ', $partes);
    }

    public static function formatarCnpj(string $cnpj): string
    {
        $d = preg_replace('/\D/', '', $cnpj) ?? '';
        if (strlen($d) !== 14) return $cnpj;
        return sprintf('%s.%s.%s/%s-%s',
            substr($d, 0, 2), substr($d, 2, 3), substr($d, 5, 3), substr($d, 8, 4), substr($d, 12, 2));
    }

    /** Número de WhatsApp com DDI — sem o 55 o link wa.me não abre. */
    public static function ddi(string $numero): string
    {
        $d = preg_replace('/\D/', '', $numero) ?? '';
        return strlen($d) <= 11 ? '55' . $d : $d;
    }

    private static function listaDe(mixed $v): array
    {
        return is_array($v) ? array_values($v) : [];
    }

    /* =================================================================
       CATÁLOGOS
       ================================================================= */

    /**
     * Ícones que os benefícios e selos podem usar.
     *
     * O SVG mora aqui, não na view, porque o painel também desenha o ícone na
     * pré-visualização — duas cópias divergem na primeira troca de traço.
     */
    public static function icones(): array
    {
        return [
            'entrega' => ['Entrega / frete',   '<path d="M3 7h11v10H3V7Zm11 4h4l3 3v3h-7v-6Z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>'],
            'escudo'  => ['Escudo / segurança','<path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Z"/><path d="m8.5 12 2.2 2.2L15.8 9"/>'],
            'cartao'  => ['Cartão / pagamento','<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/>'],
            'suporte' => ['Suporte / headset', '<path d="M4 13a8 8 0 0 1 16 0"/><path d="M4 13v4a2 2 0 0 0 2 2h1v-8H6a2 2 0 0 0-2 2Zm16 0v4a2 2 0 0 1-2 2h-1v-8h1a2 2 0 0 1 2 2Z"/>'],
            'medalha' => ['Medalha / garantia','<path d="M12 15a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"/><path d="m8.5 14.5-1 7 4.5-2.5 4.5 2.5-1-7"/>'],
            'cadeado' => ['Cadeado / SSL',     '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>'],
            'relogio' => ['Relógio / prazo',   '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'],
            'troca'   => ['Troca / devolução', '<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>'],
            'loja'    => ['Loja física',       '<path d="M4 9h16v11H4V9Z"/><path d="m3 9 2-5h14l2 5"/><path d="M9 20v-6h6v6"/>'],
            'estrela' => ['Estrela / avaliação','<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9L12 3Z"/>'],
        ];
    }

    /** SVG completo de um ícone do catálogo. Chave inexistente devolve ''. */
    public static function icone(string $chave): string
    {
        $cat = self::icones();
        if (!isset($cat[$chave])) return '';
        return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $cat[$chave][1] . '</svg>';
    }

    /** Bandeiras e meios de pagamento que o rodapé sabe desenhar. */
    public static function pagamentos(): array
    {
        return [
            'visa'       => ['Visa',        '<text x="24" y="20" text-anchor="middle" font-size="11" font-weight="800" font-style="italic" fill="#1434CB" letter-spacing=".5">VISA</text>'],
            'mastercard' => ['Mastercard',  '<circle cx="20" cy="15" r="8.5" fill="#EB001B"/><circle cx="28" cy="15" r="8.5" fill="#F79E1B"/><path d="M24 8.6a8.5 8.5 0 0 1 0 12.8 8.5 8.5 0 0 1 0-12.8Z" fill="#FF5F00"/>'],
            'amex'       => ['American Express', '<rect width="48" height="30" rx="5" fill="#2E77BC"/><text x="24" y="19" text-anchor="middle" font-size="8.5" font-weight="800" fill="#fff" letter-spacing=".4">AMEX</text>'],
            'elo'        => ['Elo',         '<text x="24" y="20" text-anchor="middle" font-size="12" font-weight="900" fill="#0f172a">elo</text><circle cx="35.5" cy="11" r="2" fill="#FFCB05"/>'],
            'hipercard'  => ['Hipercard',   '<rect width="48" height="30" rx="5" fill="#B3131B"/><text x="24" y="19" text-anchor="middle" font-size="8" font-weight="800" font-style="italic" fill="#fff">Hipercard</text>'],
            'diners'     => ['Diners Club', '<circle cx="24" cy="15" r="9" fill="#0079BE"/><rect x="21.4" y="8.4" width="5.2" height="13.2" rx="2.6" fill="#fff"/>'],
            'pix'        => ['Pix',         '<g transform="translate(24 15)"><rect x="-6.4" y="-6.4" width="12.8" height="12.8" rx="3.4" transform="rotate(45)" fill="none" stroke="#32BCAD" stroke-width="2.6"/><rect x="-2.5" y="-2.5" width="5" height="5" rx="1.4" transform="rotate(45)" fill="#32BCAD"/></g>'],
            'boleto'     => ['Boleto bancário', '<g fill="#0f172a"><rect x="10" y="8" width="2.4" height="14"/><rect x="14.5" y="8" width="1.2" height="14"/><rect x="17.8" y="8" width="3" height="14"/><rect x="22.8" y="8" width="1.2" height="14"/><rect x="26" y="8" width="2.2" height="14"/><rect x="30.4" y="8" width="1.2" height="14"/><rect x="33.6" y="8" width="3" height="14"/><rect x="38.6" y="8" width="1.4" height="14"/></g>'],
            'picpay'     => ['PicPay',      '<rect width="48" height="30" rx="5" fill="#21C25E"/><text x="24" y="19.5" text-anchor="middle" font-size="9" font-weight="800" fill="#fff">PicPay</text>'],
            'nubank'     => ['Nubank',      '<rect width="48" height="30" rx="5" fill="#820AD1"/><text x="24" y="19.5" text-anchor="middle" font-size="8.5" font-weight="800" fill="#fff">nubank</text>'],
        ];
    }

    /** SVG completo de uma bandeira. Chave inexistente devolve ''. */
    public static function pagamento(string $chave): string
    {
        $cat = self::pagamentos();
        if (!isset($cat[$chave])) return '';
        return '<svg viewBox="0 0 48 30" aria-hidden="true">' . $cat[$chave][1] . '</svg>';
    }
}
