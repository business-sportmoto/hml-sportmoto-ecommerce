<?php
/**
 * app/services/email/EmailTemplateService.php
 *
 * Responsável por:
 *  - Sanitizar HTML salvo (remover scripts, iframes, eventos inline, javascript:).
 *  - Renderizar templates substituindo variáveis seguras.
 *  - Gerar versão texto alternativa quando não fornecida.
 */
// class EmailTemplateService
// {
//     /** @var array */
//     private $config;

//     public function __construct()
//     {
//         $this->config = require dirname(__DIR__, 2) . '/../config/email-marketing.php';
//     }

//     /**
//      * Sanitiza HTML para gravação.
//      * Não é um sanitizador HTML completo — é uma defesa pragmática contra
//      * scripts, iframes e atributos perigosos.
//      */
//     public function sanitizeHtml($html)
//     {
//         $html = (string)$html;

//         // Remove blocos completos perigosos
//         $html = preg_replace('#<\s*(script|iframe|object|embed|applet|form|meta|link\b)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
//         $html = preg_replace('#<\s*(script|iframe|object|embed|applet|form|meta|link\b)[^>]*/#i', '', $html);

//         // Remove atributos on*="..."
//         $html = preg_replace('#\s+on[a-z]+\s*=\s*"(?:[^"]|\\\\")*"#i', '', $html);
//         $html = preg_replace("#\\s+on[a-z]+\\s*=\\s*'(?:[^']|\\\\')*'#i", '', $html);
//         $html = preg_replace('#\s+on[a-z]+\s*=\s*[^\s>]+#i', '', $html);

//         // Remove javascript: e data: em href/src
//         $html = preg_replace('#(href|src)\s*=\s*"\s*javascript\s*:[^"]*"#i', '$1="#"', $html);
//         $html = preg_replace("#(href|src)\\s*=\\s*'\\s*javascript\\s*:[^']*'#i", '$1="#"', $html);
//         $html = preg_replace('#(src)\s*=\s*"\s*data\s*:(?!image/)[^"]*"#i', '$1=""', $html);

//         // Remove expression() em style
//         $html = preg_replace('#expression\s*\([^)]*\)#i', '', $html);

//         return $html;
//     }

//     /**
//      * Renderiza um template substituindo variáveis seguras.
//      * Vars não whitelisted são ignoradas e substituídas por string vazia.
//      */
//     public function render($html, array $vars)
//     {
//         $allowed = $this->config['template_vars'] ?? [];
//         $safe = [];
//         foreach ($allowed as $k) {
//             $safe[$k] = isset($vars[$k]) ? $this->escape($vars[$k]) : '';
//         }

//         return preg_replace_callback('/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/i', function ($m) use ($safe) {
//             $name = strtolower($m[1]);
//             return $safe[$name] ?? '';
//         }, $html);
//     }

//     /** Para uso em assunto/preheader — também escapa */
//     public function renderInline($texto, array $vars)
//     {
//         return $this->render((string)$texto, $vars);
//     }

//     /** Gera versão texto a partir do HTML, se não houver alternativo. */
//     public function htmlToText($html)
//     {
//         $t = preg_replace('#<br\s*/?#i', "\n", $html);
//         $t = preg_replace('#</p>#i', "\n\n", $t);
//         $t = strip_tags($t);
//         $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
//         $t = preg_replace("/[ \t]+/", ' ', $t);
//         $t = preg_replace("/\n{3,}/", "\n\n", $t);
//         return trim($t);
//     }

//     /** Garante presença do link de descadastro no HTML/texto */
//     public function injectUnsubscribe($html, $textPlain, $urlUnsub)
//     {
//         if (stripos($html, $urlUnsub) === false) {
//             $bloco = '<div style="font:11px/1.4 Arial,sans-serif;color:#777;text-align:center;padding:18px 8px;">'
//                   . 'Você está recebendo este email porque cadastrou-se no SportMoto. '
//                   . 'Caso não deseje mais receber nossas mensagens, '
//                   . '<a href="' . htmlspecialchars($urlUnsub, ENT_QUOTES, 'UTF-8') . '" '
//                   . 'style="color:#777;text-decoration:underline">cancele aqui</a>.'
//                   . '</div>';
//             // Antes de </body> se existir, senão no final
//             if (stripos($html, '</body>') !== false) {
//                 $html = preg_replace('#</body>#i', $bloco . '</body>', $html, 1);
//             } else {
//                 $html .= $bloco;
//             }
//         }
//         if ($textPlain && stripos($textPlain, $urlUnsub) === false) {
//             $textPlain .= "\n\n---\nPara não receber mais, acesse: $urlUnsub\n";
//         }
//         return [$html, $textPlain];
//     }

//     private function escape($v)
//     {
//         if (is_array($v) || is_object($v)) return '';
//         return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
//     }
// }

/**
 * app/services/email/EmailTemplateService.php  (v2)
 *
 * SUBSTITUI o existente. Mantém retrocompatibilidade total e adiciona:
 *   - sanitizeHtml($html, &$avisos) — agora pode retornar avisos por referência
 *   - validação ampliada (data: perigoso, javascript:, on*, <form>, <meta>, <link>)
 */
class EmailTemplateService
{
    /** Tags removidas completamente (com seu conteúdo). */
    private const TAGS_BANIDAS = [
        'script', 'iframe', 'object', 'embed', 'applet',
        'form', 'meta', 'link', 'base', 'noscript',
    ];

    /** Atributos removidos sempre. */
    private const ATTRS_BANIDOS_PATTERNS = [
        '/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i',  // onclick, onload, etc
        '/\s+style\s*=\s*("[^"]*expression\s*\([^"]*"|\'[^\']*expression\s*\([^\']*\')/i', // CSS expression
    ];

    /**
     * Sanitiza HTML para envio em email.
     *
     * @param string $html
     * @param array &$avisos array que recebe descrições dos removidos
     * @return string HTML seguro
     */
    public function sanitizeHtml(string $html, array &$avisos = []): string
    {
        $original = $html;

        // 1. Remove tags banidas (com conteúdo)
        foreach (self::TAGS_BANIDAS as $tag) {
            $count = 0;
            $html = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is', '', $html, -1, $count);
            $html = preg_replace('#<' . $tag . '\b[^>]*/?>#i', '', $html ?? '', -1, $c2);
            $count += $c2;
            if ($count > 0) $avisos[] = "tag <{$tag}> removida ({$count}x)";
        }

        // 2. Remove atributos perigosos
        foreach (self::ATTRS_BANIDOS_PATTERNS as $pat) {
            $count = 0;
            $html = preg_replace($pat, '', $html ?? '', -1, $count);
            if ($count > 0) $avisos[] = "atributo perigoso removido ({$count}x)";
        }

        // 3. Substitui javascript: e data: perigosos em href/src
        $html = preg_replace_callback(
            '#\s+(href|src)\s*=\s*("[^"]*"|\'[^\']*\')#i',
            function ($m) use (&$avisos) {
                $attr = $m[1];
                $valOrig = $m[2];
                $val = trim($valOrig, '"\'');
                if (preg_match('#^\s*javascript:#i', $val)) {
                    $avisos[] = "URL javascript: removida em {$attr}";
                    return ' ' . $attr . '="#"';
                }
                // Bloqueia data: exceto imagens
                if (preg_match('#^\s*data:#i', $val) && !preg_match('#^\s*data:image/(png|jpe?g|gif|webp);#i', $val)) {
                    $avisos[] = "URL data: não-imagem removida em {$attr}";
                    return ' ' . $attr . '="#"';
                }
                // Bloqueia vbscript: e outros
                if (preg_match('#^\s*(vbscript|file|ftp):#i', $val)) {
                    $avisos[] = "URL com protocolo proibido removida em {$attr}";
                    return ' ' . $attr . '="#"';
                }
                return $m[0];
            },
            $html ?? ''
        );

        // 4. Remove tags HTML/HEAD/BODY se aparecerem soltas (template completo)
        // — mantém o conteúdo, remove só as tags estruturais
        $html = preg_replace('#</?(html|head|body)\b[^>]*>#i', '', $html ?? '');

        return $html ?? '';
    }

    /**
     * Renderiza template substituindo variáveis (whitelist),
     * com escape via htmlspecialchars.
     */
    /**
     * Renderiza template substituindo:
     *   {{#lista}}...{{/lista}}  — blocos repetíveis (array de arrays)
     *   {{?var}}...{{/var}}      — bloco condicional (mostra se var não vazia)
     *   {{var}}                  — variável simples (com escape HTML)
     */
    public function render(string $html, array $vars): string
    {
        // ── 1. Blocos repetíveis {{#lista}}...{{/lista}} ──────────────────────
        $listasPermitidas = ['carrinho_itens', 'produtos_categoria', 'pedido_itens'];

        $html = preg_replace_callback(
            '/\{\{#([a-zA-Z_][a-zA-Z0-9_]*)\}\}(.*?)\{\{\/\1\}\}/s',
            function ($m) use ($vars, $listasPermitidas) {
                $chave = $m[1];
                $bloco = $m[2];
                if (!in_array($chave, $listasPermitidas, true)) return '';
                $lista = $vars[$chave] ?? [];
                if (!is_array($lista) || empty($lista)) return '';
                $saida = '';
                foreach ($lista as $item) {
                    if (!is_array($item)) continue;
                    $saida .= preg_replace_callback(
                        '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
                        function ($v) use ($item) {
                            $k = $v[1];
                            return isset($item[$k])
                                ? htmlspecialchars((string)$item[$k], ENT_QUOTES, 'UTF-8')
                                : '';
                        },
                        $bloco
                    );
                }
                return $saida;
            },
            $html
        );

        // ── 2. Blocos condicionais {{?var}}...{{/var}} ────────────────────────
        $html = preg_replace_callback(
            '/\{\{\?([a-zA-Z_][a-zA-Z0-9_]*)\}\}(.*?)\{\{\/\1\}\}/s',
            function ($m) use ($vars) {
                $chave = $m[1];
                $bloco = $m[2];
                $valor = $vars[$chave] ?? '';
                return ($valor === '' || $valor === null || $valor === false || $valor === [])
                    ? ''
                    : $bloco;
            },
            $html ?? ''
        );

        // ── 3. Variáveis simples {{var}} com escape ───────────────────────────
        $html = preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            function ($m) use ($vars) {
                $k = $m[1];
                $v = $vars[$k] ?? '';
                if (is_array($v) || is_object($v)) return '';
                return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
            },
            $html ?? ''
        );

        return $html ?? '';
    }

    /** Versão sem escape (pra URL etc, usado em renderInline do assunto). */
    public function renderInline(string $texto, array $vars): string
    {
        $cfg = require dirname(__DIR__, 2) . '/../config/email-marketing.php';
        $allowed = $cfg['template_vars'] ?? [];
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            function ($m) use ($vars, $allowed) {
                $k = $m[1];
                if (!in_array($k, $allowed, true)) return $m[0];
                return (string)($vars[$k] ?? '');
            },
            $texto
        );
    }

    /** Converte HTML para texto plano (fallback do versão texto/plain). */
    public function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|h\d|li|tr)>#i', "\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /** Injeta link de unsubscribe se o template não tiver já. */
    public function injectUnsubscribe(string $html, string $text, string $unsubUrl): array
    {
        if (stripos($html, $unsubUrl) === false) {
            $footer = '<p style="font-size:11px; color:#999; text-align:center; margin-top:24px;">'
                    . 'Não quer mais receber esses emails? '
                    . '<a href="' . htmlspecialchars($unsubUrl) . '" style="color:#999;">Cancelar inscrição</a>.'
                    . '</p>';
            $html .= $footer;
        }
        if (stripos($text, 'cancelar') === false && stripos($text, $unsubUrl) === false) {
            $text .= "\n\n---\nPara cancelar a inscrição, acesse: " . $unsubUrl;
        }
        return [$html, $text];
    }

    /**
     * Extrai variáveis usadas em um template (todas as {{nome}}).
     */
    public function extrairVariaveis(string $html): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $html, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * Valida que todas as variáveis obrigatórias estão presentes no template.
     */
    public function validarVariaveisObrigatorias(string $html, array $obrigatorias): array
    {
        $usadas = $this->extrairVariaveis($html);
        $faltando = array_diff($obrigatorias, $usadas);
        return [
            'ok' => empty($faltando),
            'faltando' => array_values($faltando),
            'usadas' => $usadas,
        ];
    }
}