<?php
declare(strict_types=1);

/**
 * app/services/payment/antifraude/ClearSaleFingerprint.php
 *
 * Behavior Analytics da ClearSale — a metade que roda no navegador.
 *
 * POR QUE ISTO EXISTE:
 *   O `sessionID` que vai no pedido (ClearSaleService) precisa ser EXATAMENTE
 *   o mesmo que o script de fingerprint recebeu na página. É essa string que
 *   amarra "este pedido" ao dispositivo, ao comportamento de digitação e ao
 *   histórico de navegação coletados. Sem a correspondência, a consulta é
 *   cobrada igual e a ClearSale analisa às cegas.
 *
 * QUEM MANDA NO ID É O SERVIDOR:
 *   Gerar no navegador seria deixar o comprador escolher o próprio
 *   identificador de sessão — quem quer escapar do fingerprint mandaria um id
 *   novo a cada tentativa. Aqui ele nasce no PHP, fica na sessão e só é
 *   renderizado na página.
 *
 * ONDE COLOCAR:
 *   No fim do <body>, em todas as páginas do checkout — quanto mais tempo o
 *   script observa, melhor o sinal. Uma página só, no último passo, coleta
 *   quase nada.
 *
 * A chave de app NÃO é segredo (ela vai para o navegador de qualquer jeito),
 * mas mora no .env como todo o resto: CLEARSALE_APP_KEY.
 */
class ClearSaleFingerprint
{
    private const CHAVE_SESSAO = 'clearsale_fp_sid';

    /** Eles exigem de 6 a 128 caracteres. 32 hex fica folgado e é irrepetível. */
    private const TAMANHO_BYTES = 16;

    /**
     * ID da sessão de fingerprint. Estável enquanto a sessão PHP durar, para
     * que a coleta do navegador e o envio do pedido falem do mesmo evento.
     */
    public static function sessionId(): string
    {
        $sid = (string) Session::get(self::CHAVE_SESSAO, '');

        if ($sid === '' || strlen($sid) < 6) {
            $sid = bin2hex(random_bytes(self::TAMANHO_BYTES));
            Session::set(self::CHAVE_SESSAO, $sid);
        }

        return $sid;
    }

    /**
     * Começa uma sessão de coleta nova.
     *
     * Chame depois de fechar um pedido: reaproveitar o mesmo id no pedido
     * seguinte mistura dois eventos de compra num só rastro de dispositivo.
     */
    public static function renovar(): string
    {
        Session::set(self::CHAVE_SESSAO, bin2hex(random_bytes(self::TAMANHO_BYTES)));
        return (string) Session::get(self::CHAVE_SESSAO, '');
    }

    public static function appKey(): string
    {
        $v = getenv('CLEARSALE_APP_KEY');
        if ($v !== false && $v !== '') return (string) $v;
        if (!empty($_ENV['CLEARSALE_APP_KEY']))    return (string) $_ENV['CLEARSALE_APP_KEY'];
        if (!empty($_SERVER['CLEARSALE_APP_KEY'])) return (string) $_SERVER['CLEARSALE_APP_KEY'];
        if (defined('CLEARSALE_APP_KEY') && is_string(constant('CLEARSALE_APP_KEY'))) {
            return (string) constant('CLEARSALE_APP_KEY');
        }
        return '';
    }

    public static function ativo(): bool
    {
        return self::appKey() !== '';
    }

    /**
     * Snippet pronto para o fim do <body>.
     *
     * Devolve string vazia sem a chave configurada — página sem coleta é
     * melhor do que página com erro de JavaScript no meio do checkout.
     *
     * O <noscript> é a rede: com o JS bloqueado, o pixel ainda registra que
     * houve tentativa, e a ClearSale sabe distinguir "sem dados" de
     * "coleta deliberadamente bloqueada" — que já é um sinal em si.
     */
    public static function script(): string
    {
        $app = self::appKey();
        if ($app === '') return '';

        $sid   = self::sessionId();
        $appJs = json_encode($app, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
        $sidJs = json_encode($sid, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
        $url   = 'https://device.clearsale.com.br/p/fp.png?sid=' . rawurlencode($sid)
               . '&app=' . rawurlencode($app) . '&ns=1';
        $urlHtml = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<script>
(function (a, b, c, d, e, f, g) {
    a['CsdpObject'] = e; a[e] = a[e] || function () {
        (a[e].q = a[e].q || []).push(arguments)
    }, a[e].l = 1 * Date.now(); f = b.createElement(c),
    g = b.getElementsByTagName(c)[0]; f.async = 1; f.src = d;
    g.parentNode.insertBefore(f, g)
})(window, document, 'script', '//device.clearsale.com.br/p/fp.js', 'csdp');
csdp('app', {$appJs});
csdp('sessionid', {$sidJs});
</script>
<noscript><img src="{$urlHtml}" width="1" height="1" alt=""></noscript>
HTML;
    }
}
