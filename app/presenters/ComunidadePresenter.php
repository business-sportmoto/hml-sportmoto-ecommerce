<?php
// app/presenters/ComunidadePresenter.php
//
// "A nossa comunidade" — as fotos que os próprios clientes publicaram das
// motos deles. Espelha views/partials/nossos-clientes.php.
//
// A regra de quem aparece é do dono da foto, não nossa: só entra o que foi
// marcado como `publico` E passou por moderação. As duas condições valem
// juntas; publicar a moto de alguém é escolha da pessoa.
//
// O @ do Instagram vem junto quando o cliente preencheu, porque é o que faz a
// seção ser comunidade e não banco de imagens — mas nunca o e-mail, o nome
// completo ou qualquer outro dado de quem postou.

final class ComunidadePresenter
{
    /** @return array<int,array> */
    public static function colecao(array $fotos, PresenterContext $ctx): array
    {
        return array_values(array_map(
            static fn(array $f) => self::uma($f, $ctx),
            $fotos
        ));
    }

    public static function uma(array $f, PresenterContext $ctx): array
    {
        $insta = self::instagram($f['insta_cliente'] ?? null);

        return [
            'id' => (int)$f['id'],
            // A grade usa `thumb`; o visualizador em tela cheia usa `full`.
            // Mandar só a maior faria o aparelho baixar megabytes para
            // desenhar quadradinhos.
            'thumb'  => MotoFotoPresenter::arquivo($f['arquivo_thumb']  ?? null, $ctx),
            'medium' => MotoFotoPresenter::arquivo($f['arquivo_medium'] ?? null, $ctx),
            'full'   => MotoFotoPresenter::arquivo($f['arquivo_full']   ?? null, $ctx),

            'legenda' => self::texto($f['legenda'] ?? null),
            'moto'    => self::moto($f),

            'instagram' => $insta === null ? null : [
                'usuario' => $insta,
                'url'     => 'https://instagram.com/' . rawurlencode($insta),
            ],
        ];
    }

    /**
     * "CB 500F 2023" — apelido quando existe, senão montadora + modelo + ano.
     *
     * A web tem um `nc_moto_label2()` que começa com `return false;`, então
     * hoje o rótulo nunca aparece lá. Aqui ele é montado de verdade: é a
     * informação que faz a foto significar algo para outro motociclista.
     */
    private static function moto(array $f): ?string
    {
        $apelido = self::texto($f['moto_apelido'] ?? null);
        if ($apelido !== null) {
            return $apelido;
        }

        $partes = array_filter([
            self::texto($f['montadora'] ?? null),
            self::texto($f['modelo'] ?? null),
            !empty($f['moto_ano']) ? (string)$f['moto_ano'] : null,
        ]);

        return $partes ? implode(' ', $partes) : null;
    }

    /** Aceita "@fulano", "fulano" ou a URL inteira; devolve só o usuário. */
    private static function instagram($valor): ?string
    {
        $v = trim((string)$valor);
        if ($v === '') {
            return null;
        }

        if (preg_match('#instagram\.com/([^/?#]+)#i', $v, $m)) {
            $v = $m[1];
        }

        $v = ltrim($v, '@');

        // Só o que de fato é um usuário do Instagram. Sem isto, um campo
        // preenchido com "não tenho" viraria um link quebrado.
        return preg_match('/^[A-Za-z0-9._]{1,30}$/', $v) ? $v : null;
    }

    private static function texto($v): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}
