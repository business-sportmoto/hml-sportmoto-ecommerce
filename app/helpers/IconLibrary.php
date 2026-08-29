<?php
/*
<?= IconLibrary::render('truck', 'icon icon--md') ?>
<?= IconLibrary::render('favorite', 'icon icon--heart', ['style' => 'color:#e11d48']) ?>
*/ 

class IconLibrary
{
    private static ?array $cache = null;

    public static function getAll(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $file = ROOT_PATH . '/assets/icons.json';

        if (!file_exists($file)) {
            self::$cache = [];
            return self::$cache;
        }

        $json = file_get_contents($file);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            self::$cache = [];
            return self::$cache;
        }

        self::$cache = array_values(array_filter($data, function ($item) {
            return !empty($item['key']) && !empty($item['svg']);
        }));

        return self::$cache;
    }

    public static function find(string $key): ?array
    {
        $key = trim(mb_strtolower($key));

        foreach (self::getAll() as $icon) {
            if (mb_strtolower((string) $icon['key']) === $key) {
                return $icon;
            }
        }

        return null;
    }

    public static function search(string $term = ''): array
    {
        $term = trim(mb_strtolower($term));
        $icons = self::getAll();

        if ($term === '') {
            return $icons;
        }

        return array_values(array_filter($icons, function ($icon) use ($term) {
            $haystack = [
                $icon['key'] ?? '',
                $icon['label'] ?? '',
                implode(' ', $icon['tags'] ?? []),
            ];

            $text = mb_strtolower(implode(' ', $haystack));
            return mb_strpos($text, $term) !== false;
        }));
    }

    public static function render(string $key, string $class = '', array $attrs = []): string
    {
        $icon = self::find($key);

        if (!$icon) {
            self::avisarAusente($key);
            return '';
        }

        $svg = trim((string) $icon['svg']);

        if ($svg === '') {
            return '';
        }

        if ($class !== '' || !empty($attrs)) {
            $svg = self::injectAttributes($svg, $class, $attrs);
        }

        return $svg;
    }

    public static function getOptionsHtml(?string $selected = null): string
    {
        $selected = (string) $selected;
        $html = '<option value="">Selecione um ícone</option>';

        foreach (self::getAll() as $icon) {
            $key = htmlspecialchars((string) $icon['key'], ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string) ($icon['label'] ?? $icon['key']), ENT_QUOTES, 'UTF-8');
            $isSelected = $selected === ($icon['key'] ?? '') ? ' selected' : '';

            $html .= "<option value=\"{$key}\"{$isSelected}>{$label} ({$key})</option>";
        }

        return $html;
    }

    private static function injectAttributes(string $svg, string $class = '', array $attrs = []): string
    {
        $extra = [];

        if ($class !== '') {
            $extra['class'] = $class;
        }

        foreach ($attrs as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $extra[$name] = $value;
        }

        if (empty($extra)) {
            return $svg;
        }

        $attrString = '';
        foreach ($extra as $name => $value) {
            $attrString .= ' ' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8')
                . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return preg_replace('/<svg\b([^>]*)>/i', '<svg$1' . $attrString . '>', $svg, 1) ?? $svg;
    }
    /** A chave existe no acervo? Util para diagnostico e testes. */
    public static function has(string $key): bool
    {
        return self::find($key) !== null;
    }

    /**
     * Monta o sprite <symbol> das chaves informadas.
     *
     * Renderizado uma unica vez por pagina, permite que o JS (que nao alcanca o
     * PHP) referencie qualquer icone por <use href="#i-chave"> sem duplicar SVG
     * nem repetir markup a cada linha de tabela renderizada.
     */
    public static function sprite(array $keys, string $prefix = 'i-'): string
    {
        $simbolos = [];

        foreach (array_unique($keys) as $key) {
            $icon = self::find($key);

            if (!$icon) {
                self::avisarAusente($key);
                continue;
            }

            $svg = trim((string) $icon['svg']);

            if (!preg_match('/<svg\b([^>]*)>(.*)<\/svg>/is', $svg, $m)) {
                continue;
            }

            $viewBox = preg_match('/viewBox="([^"]*)"/i', $m[1], $vb) ? $vb[1] : '0 -960 960 960';

            $simbolos[] = '<symbol id="' . self::idDe($key, $prefix)
                . '" viewBox="' . htmlspecialchars($viewBox, ENT_QUOTES, 'UTF-8') . '">'
                . $m[2] . '</symbol>';
        }

        if (empty($simbolos)) {
            return '';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"'
            . ' style="position:absolute;width:0;height:0;overflow:hidden">'
            . implode('', $simbolos) . '</svg>';
    }

    /**
     * Referencia um simbolo do sprite. Mesma saida que o helper JS produz, para
     * que markup vindo do PHP e do JS sejam indistinguiveis.
     */
    public static function ref(string $key, string $class = 'log_ico', string $prefix = 'i-'): string
    {
        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true" focusable="false">'
            . '<use href="#' . self::idDe($key, $prefix) . '"></use></svg>';
    }

    private static function idDe(string $key, string $prefix): string
    {
        return htmlspecialchars($prefix . preg_replace('/[^a-z0-9_-]/i', '', $key), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Chave inexistente e um erro silencioso: render() devolve string vazia e o
     * icone some da tela sem aviso. Registrar evita que passe batido (foi assim
     * que 'carrinho', 'manifesto' e 'localizacao' ficaram invisiveis).
     */
    private static function avisarAusente(string $key): void
    {
        static $vistos = [];

        if (isset($vistos[$key])) {
            return;
        }

        $vistos[$key] = true;

        if (class_exists('LogService')) {
            LogService::warning('IconLibrary: icone inexistente', ['chave' => $key]);
        }
    }


     
// ════════════════════════════════════════════════════════
// app/helpers/IconLibrary.php
//
// SVGs inline das principais bandeiras. Zero dependências
// externas, sem requests adicionais, sem FOUC.
//
// USO:
//   echo IconLibrary::logo('visa');
//   echo IconLibrary::logo('mastercard', 38, 24);
//   echo IconLibrary::badge('elo');       // versão menor (pill)
//   echo IconLibrary::name('visa');       // "Visa"
//
// Bandeiras suportadas:
//   visa | mastercard | amex | elo | hipercard | diners | discover | pix
// ════════════════════════════════════════════════════════

    /**
     * Normaliza o nome da bandeira para chave interna.
     */
    public static function normalize(string $brand): string {
        return strtolower(preg_replace('/[^a-z]/i', '', $brand));
    }
 
    /**
     * Nome legível da bandeira.
     */
    public static function name(string $brand): string {
        return match (self::normalize($brand)) {
            'visa'        => 'Visa',
            'mastercard',
            'master'      => 'Mastercard',
            'amex',
            'americanexpress' => 'American Express',
            'elo'         => 'Elo',
            'hipercard',
            'hiper'       => 'Hipercard',
            'diners',
            'dinersclub'  => 'Diners Club',
            'discover'    => 'Discover',
            'pix'         => 'Pix',
            default       => ucfirst($brand),
        };
    }
 
    /**
     * Retorna o SVG completo (para exibir no card preview, cartões salvos, etc.)
     *
     * @param string $brand  Nome da bandeira
     * @param int    $w      Largura (default 38)
     * @param int    $h      Altura  (default 24)
     */
    public static function logo(string $brand, int $w = 38, int $h = 24): string {
        $b = self::normalize($brand);
        return match ($b) {
            'visa'                    => self::visa($w, $h),
            'mastercard', 'master'    => self::mastercard($w, $h),
            'amex','americanexpress'  => self::amex($w, $h),
            'elo'                     => self::elo($w, $h),
            'hipercard', 'hiper'      => self::hipercard($w, $h),
            'diners', 'dinersclub'    => self::diners($w, $h),
            'discover'                => self::discover($w, $h),
            'pix'                     => self::pix($w, $h),
            default                   => self::generic($w, $h, $brand),
        };
    }
 
    /**
     * Versão pill/badge pequena (para listas de cartões aceitos, etc.)
     */
    public static function badge(string $brand): string {
        return '<span class="card-brand-badge card-brand-badge--' . self::normalize($brand) . '">'
             . self::logo($brand, 34, 22)
             . '</span>';
    }
 
    /**
     * Row de bandeiras aceitas (ex: rodapé da seção de pagamento).
     * @param string[] $brands
     */
    public static function acceptedRow(array $brands = ['visa','mastercard','amex','elo','hipercard','diners']): string {
        $html = '<div class="card-brands-row">';
        foreach ($brands as $b) {
            $html .= '<span class="card-brands-row-item" title="' . htmlspecialchars(self::name($b)) . '">'
                   . self::logo($b, 38, 24)
                   . '</span>';
        }
        $html .= '</div>';
        return $html;
    }
 
    // ════════════════════════════════════════════════════
    // SVGs das bandeiras
    // ════════════════════════════════════════════════════
 
    // ── Visa ─────────────────────────────────────────────
    private static function visa(int $w, int $h): string {
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" width="{$w}" height="{$h}" role="img" aria-label="Visa">
  <rect width="38" height="24" rx="4" fill="#1A1F71"/>
  <!-- Stripe dourada clássica -->
  <rect y="8" width="38" height="8" fill="#1A1F71"/>
  <!-- Letras VISA -->
  <text x="19.5" y="16.5" text-anchor="middle"
        font-family="Arial,sans-serif" font-size="11" font-weight="900"
        font-style="italic" fill="white" letter-spacing="0.5">VISA</text>
  <!-- Detalhe dourado esquerdo (marca registrada) -->
  <path d="M6 9.5 L7.5 15 L9 9.5" stroke="#F7B731" stroke-width="0" fill="#F7B731" opacity="0.7"/>
</svg>
SVG;
    }
 
    // ── Mastercard ───────────────────────────────────────
    private static function mastercard(int $w, int $h): string {
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" width="{$w}" height="{$h}" role="img" aria-label="Mastercard">
  <rect width="38" height="24" rx="4" fill="#252525"/>
  <!-- Círculo vermelho -->
  <circle cx="15" cy="12" r="7.5" fill="#EB001B"/>
  <!-- Círculo laranja -->
  <circle cx="23" cy="12" r="7.5" fill="#F79E1B"/>
  <!-- Sobreposição central -->
  <path d="M19 5.75 C21.12 7.18 22.5 9.45 22.5 12 C22.5 14.55 21.12 16.82 19 18.25 C16.88 16.82 15.5 14.55 15.5 12 C15.5 9.45 16.88 7.18 19 5.75Z" fill="#FF5F00"/>
</svg>
SVG;
    }
 
    // ── American Express ─────────────────────────────────
    private static function amex(int $w, int $h): string {
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" width="{$w}" height="{$h}" role="img" aria-label="American Express">
  <rect width="38" height="24" rx="4" fill="#2E77BC"/>
  <!-- Textura sutil -->
  <rect width="38" height="24" rx="4" fill="url(#amex-grad)" opacity="0.3"/>
  <defs>
    <linearGradient id="amex-grad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#fff;stop-opacity:.15"/>
      <stop offset="100%" style="stop-color:#000;stop-opacity:.05"/>
    </linearGradient>
  </defs>
  <!-- "AMEX" -->
  <text x="19" y="15" text-anchor="middle"
        font-family="Arial,sans-serif" font-size="9.5" font-weight="900"
        fill="white" letter-spacing="1.5">AMEX</text>
  <!-- Centurion simplificado -->
  <rect x="6" y="6" width="3" height="5" rx="1" fill="rgba(255,255,255,.25)"/>
  <rect x="10" y="7" width="2" height="4" rx="1" fill="rgba(255,255,255,.15)"/>
</svg>
SVG;
    }
 
    // ── Elo ──────────────────────────────────────────────
    private static function elo(int $w, int $h): string {
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" width="{$w}" height="{$h}" role="img" aria-label="Elo">
  <rect width="38" height="24" rx="4" fill="#1C1C1C"/>
  <!-- E amarelo -->
  <rect x="6" y="8" width="6" height="8" rx="1" fill="#FFCB05"/>
  <rect x="6" y="8" width="6" height="2" rx="0.5" fill="#FFCB05"/>
  <rect x="6" y="15" width="6" height="1.5" rx="0.5" fill="#FFCB05"/>
  <rect x="6" y="11" width="5" height="1.5" rx="0.5" fill="#1C1C1C"/>
  <!-- L branco -->
  <rect x="14.5" y="8" width="2" height="8" rx="0.5" fill="white"/>
  <rect x="14.5" y="14.5" width="5" height="1.5" rx="0.5" fill="white"/>
  <!-- O azul -->
  <circle cx="27.5" cy="12" r="4" fill="none" stroke="#00AEEF" stroke-width="2"/>
  <!-- Detalhe laranja na bola -->
  <path d="M29.5 9 L31 12 L29.5 15" fill="none" stroke="#EF7921" stroke-width="1.5" stroke-linecap="round"/>
</svg>
SVG;
    }
 
    // ── Hipercard ────────────────────────────────────────
    private static function hipercard(int $w, int $h): string {
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" width="{$w}" height="{$h}" role="img" aria-label="Hipercard">
  <rect width="38" height="24" rx="4" fill="#B3131B"/>
  <defs>
    <linearGradient id="hiper-grad" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" style="stop-color:#D42027"/>
      <stop offset="100%" style="stop-color:#8B0F14"/>
    </linearGradient>
  </defs>
  <rect width="38" height="24" rx="4" fill="url(#hiper-grad)"/>
  <!-- H -->
  <rect x="6" y="8" width="2" height="8" rx="0.5" fill="white"/>
  <rect x="10" y="8" width="2" height="8" rx="0.5" fill="white"/>
  <rect x="6" y="11" width="6" height="2" rx="0.5" fill="white"/>
  <!-- IPER -->
  <text x="23" y="15.5" text-anchor="middle"
        font-family="Arial,sans-serif" font-size="6.5" font-weight="900"
        fill="white" letter-spacing="0.8">IPER</text>
</svg>
SVG;
    }
 
    // ── Diners Club ──────────────────────────────────────
    private static function diners(int $w, int $h): string {
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" width="{$w}" height="{$h}" role="img" aria-label="Diners Club">
  <rect width="38" height="24" rx="4" fill="#F5F5F5" stroke="#E0E0E0" stroke-width="0.5"/>
  <!-- Dois círculos sobrepostos do Diners -->
  <circle cx="16" cy="12" r="6" fill="none" stroke="#004A97" stroke-width="1.5"/>
  <circle cx="22" cy="12" r="6" fill="none" stroke="#004A97" stroke-width="1.5"/>
  <!-- Linha divisória interna -->
  <path d="M19 6.5 C20.5 7.8 21.5 9.8 21.5 12 C21.5 14.2 20.5 16.2 19 17.5 C17.5 16.2 16.5 14.2 16.5 12 C16.5 9.8 17.5 7.8 19 6.5Z" fill="#004A97"/>
</svg>
SVG;
    }
 
    // ── Discover ─────────────────────────────────────────
    private static function discover(int $w, int $h): string {
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" width="{$w}" height="{$h}" role="img" aria-label="Discover">
  <rect width="38" height="24" rx="4" fill="#FFFFFF" stroke="#E0E0E0" stroke-width="0.5"/>
  <!-- Bola laranja característica -->
  <circle cx="28" cy="12" r="9" fill="#F76F20" opacity="0.9"/>
  <circle cx="26" cy="12" r="7" fill="#F76F20"/>
  <!-- Texto DISC -->
  <text x="10" y="15" text-anchor="middle"
        font-family="Arial,sans-serif" font-size="6" font-weight="900"
        fill="#231F20" letter-spacing="0.3">DISC</text>
</svg>
SVG;
    }
 
    // ── Pix ──────────────────────────────────────────────
    private static function pix(int $w, int $h): string {
        $attr = [ 'width'=> '24px', 'height'=> '24px' , 'style' => 'color:#e11d48', 'viewBox'=> '0 0 24 24'];
        return self::render('pix-main', '', $attr);
    }
 
    // ── Genérico ─────────────────────────────────────────
    /**
     * Marca de uma adquirente, para as telas do painel.
     *
     * NAO e o logo oficial: e um monograma na cor da marca. Desenhar o logo
     * de terceiro a mao sai errado e ainda entra em terreno de marca
     * registrada — o monograma identifica igual, numa grade consistente, e
     * qualquer adquirente nova ja nasce com aparencia decente.
     */
    public static function adquirente(string $codigo, int $tamanho = 44): string
    {
        $mapa = [
            'mercadopago' => ['MP', '#00A6E0'],
            'safrapay'    => ['SA', '#0B2C5B'],
            'cielo'       => ['CI', '#0067B1'],
            'malga'       => ['ML', '#6D28D9'],
            'stone'       => ['ST', '#00A868'],
            'pagseguro'   => ['PS', '#F5A623'],
            'rede'        => ['RE', '#CC0033'],
            'fake'        => ['FK', '#94A3B8'],
        ];

        $c = strtolower(trim($codigo));
        [$sigla, $cor] = $mapa[$c] ?? [mb_strtoupper(mb_substr($c, 0, 2)), '#64748B'];

        $sigla = htmlspecialchars($sigla, ENT_QUOTES, 'UTF-8');
        $fonte = $tamanho * 0.36;

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 44 44" width="{$tamanho}" height="{$tamanho}" role="img" aria-label="{$sigla}">
  <rect width="44" height="44" rx="11" fill="{$cor}"/>
  <text x="22" y="27.5" text-anchor="middle"
        font-family="-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif"
        font-size="15" font-weight="700" fill="#fff" letter-spacing="0.4">{$sigla}</text>
</svg>
SVG;
    }

    private static function generic(int $w, int $h, string $brand): string {
        $label = strtoupper(substr($brand, 0, 4));
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" width="{$w}" height="{$h}">
  <rect width="38" height="24" rx="4" fill="#64748B"/>
  <text x="19" y="15.5" text-anchor="middle"
        font-family="Arial,sans-serif" font-size="7.5" font-weight="700"
        fill="white" letter-spacing="0.5">{$label}</text>
</svg>
SVG;
    }
}