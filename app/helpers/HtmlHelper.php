<?php
declare(strict_types=1);

/**
 * app/helpers/HtmlHelper.php
 *
 * Sanitização de HTML RICO (descrições de produto) via HTML Purifier.
 *
 * POR QUE UMA LIB E NÃO UM SANITIZADOR PRÓPRIO:
 * O HTML aqui vai para a página PÚBLICA do produto (com checkout no
 * mesmo domínio). Sanitizador caseiro é vetor clássico de bypass
 * (mutation XSS, encoding, tags malformadas). HTML Purifier é o
 * padrão de mercado, testado contra esses ataques há anos.
 *
 * ESTA É A DEFESA REAL contra stored XSS. O editor no admin é
 * client-side — um atacante faz POST do HTML cru ignorando o editor.
 * Por isso a sanitização mora AQUI, no servidor, no momento de SALVAR.
 *
 * INSTALAÇÃO (uma vez, na raiz do projeto):
 *   composer require ezyang/htmlpurifier
 */
final class HtmlHelper
{
    private static ?\HTMLPurifier $purifier = null;

    /**
     * Limpa HTML rico deixando só tags/atributos seguros.
     * Chamar SEMPRE ao salvar conteúdo que será exibido como HTML.
     */
    public static function sanitizeRich(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        return self::purifier()->purify($html);
    }

    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        $config = \HTMLPurifier_Config::createDefault();

        // Cache em disco acelera a 2ª+ chamada. Cria a pasta se faltar.
        $cacheDir = ROOT_PATH . '/storage/cache/htmlpurifier';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            $config->set('Cache.SerializerPath', $cacheDir);
        } else {
            // Sem cache gravável, roda sem cache (mais lento, mas seguro)
            $config->set('Cache.DefinitionImpl', null);
        }

        // WHITELIST — só o necessário para descrição de produto.
        // Tudo fora daqui (script, iframe, on*, etc.) é REMOVIDO.
        $config->set('HTML.Allowed',
            'p,br,b,strong,i,em,u,s,'
          . 'ul,ol,li,'
          . 'h2,h3,h4,'
          . 'a[href|title],'
          . 'blockquote,'
          . 'span[style],'
          . 'img[src|alt|width|height],'
          . 'table,thead,tbody,tr,th,td'
        );

        // Em style, só cor/alinhamento/peso — nunca position/js/url
        $config->set('CSS.AllowedProperties', 'color,text-align,font-weight,font-style,text-decoration');

        // Links seguros
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('HTML.TargetBlank', true);   // target=_blank + rel=noreferrer
        $config->set('HTML.Nofollow', true);      // rel=nofollow (anti-spam SEO)

        // Imagens: só http(s) (bloqueia data: URI, vetor de XSS)
        $config->set('URI.DisableExternalResources', false);

        self::$purifier = new \HTMLPurifier($config);
        return self::$purifier;
    }
}