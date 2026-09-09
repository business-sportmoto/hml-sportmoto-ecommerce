<?php
// app/presenters/DestinoPresenter.php
// Traduz uma URL da loja web num destino navegável pelo app.
//
// O backend produz links web em vários lugares (ver_mais_url das seções,
// link_geral e CTAs de banner). O app não tem roteador de URL: ele tem telas.
// Mandar "https://sportmoto.com.br/busca?promocao=1" para o React Native
// obrigaria o cliente a fazer parsing de URL — acoplamento errado e frágil.
//
// Saída: { tipo, params, url }
//   tipo   → a tela do app (catalogo, produto, categoria, marca, clips, ...)
//   params → o que aquela tela precisa
//   url    → preservada para fallback e para compartilhamento
//
// tipo "externo" é o escape: o app abre no navegador.

final class DestinoPresenter
{
    public static function de(?string $url): ?array
    {
        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }

        $base = rtrim(BASE_URL, '/');

        // Link para fora da loja → navegador do sistema.
        if (preg_match('#^https?://#i', $url) && !str_starts_with($url, $base)) {
            return ['tipo' => 'externo', 'params' => [], 'url' => $url];
        }

        $caminho = (string)(parse_url($url, PHP_URL_PATH) ?? '/');
        if ($base !== '' && str_starts_with($url, $base)) {
            $caminho = substr($caminho, strlen((string)parse_url($base, PHP_URL_PATH))) ?: '/';
        }
        $caminho = '/' . trim($caminho, '/');

        parse_str((string)(parse_url($url, PHP_URL_QUERY) ?? ''), $query);

        $segmentos = array_values(array_filter(explode('/', $caminho)));
        $primeiro  = $segmentos[0] ?? '';
        $segundo   = $segmentos[1] ?? null;
        $terceiro  = $segmentos[2] ?? null;

        // ── Pedido: por ID ou por CÓDIGO, e a rota é quem diz qual ──────────
        //
        // Não dá para distinguir pelo formato: o código do pedido TAMBÉM é
        // numérico ("62399744"), então testar dígitos não separa nada. Quem
        // separa é o caminho:
        //
        //   /minha-conta/pedido/{id}   — singular, a rota da loja, recebe ID
        //   /conta/pedidos/{id}        — o que as notificações gravaram, ID
        //   /minha-conta/pedidos/{cod} — plural, o que os links do app usam
        //
        // A tela do app trabalha com CÓDIGO. Quando chega um id, ele sai daqui
        // em `pedido_id` e o código fica vazio: quem monta a lista traduz todos
        // de uma vez, em vez de uma consulta por notificação.
        $pedidoPorId = $terceiro !== null && (
            ($primeiro === 'minha-conta' && $segundo === 'pedido') ||
            ($primeiro === 'conta'       && $segundo === 'pedidos')
        );

        if ($pedidoPorId) {
            return [
                'tipo'   => 'pedido',
                'params' => ['codigo' => '', 'pedido_id' => (int)$terceiro],
                'url'    => $base . $caminho,
            ];
        }

        // `/conta/...` é o prefixo das notificações; `/minha-conta/...` o das
        // rotas da loja. Daqui para baixo os dois são a mesma coisa.
        if ($primeiro === 'conta') {
            $primeiro = 'minha-conta';
        }

        // Notificação de pedido aponta para /minha-conta/pedidos/{codigo}. Sem
        // este caso a notificação mais comum da loja abriria em WebView, com o
        // cliente logado no app e deslogado na página.
        if ($primeiro === 'minha-conta') {
            $destino = match ($segundo) {
                'pedidos'    => $terceiro
                    ? ['tipo' => 'pedido',     'params' => ['codigo' => $terceiro]]
                    : ['tipo' => 'pedidos',    'params' => []],
                'devolucoes' => ['tipo' => 'devolucoes', 'params' => []],
                'favoritos'  => ['tipo' => 'favoritos',  'params' => []],
                'garagem'    => ['tipo' => 'garagem',    'params' => []],
                // /minha-conta sem mais nada é a área do cliente. Sem este
                // caso ela caía em WebView: a conta abria no navegador, com o
                // cliente logado no app e deslogado na página.
                null         => ['tipo' => 'conta',      'params' => []],
                default      => null,
            };

            if ($destino) {
                $destino['url'] = $base . $caminho;
                return $destino;
            }
        }

        $destino = match ($primeiro) {
            'produto'   => $segundo ? ['tipo' => 'produto',   'params' => ['slug' => $segundo]] : null,
            'categoria' => $segundo ? ['tipo' => 'categoria', 'params' => ['slug' => $segundo]] : null,
            'marca'     => $segundo ? ['tipo' => 'marca',     'params' => ['slug' => $segundo]] : null,
            'marcas'    => ['tipo' => 'marcas',   'params' => []],
            'busca'     => ['tipo' => 'catalogo', 'params' => self::filtros($query)],
            'clips'     => ['tipo' => 'clips',    'params' => []],
            'carrinho'  => ['tipo' => 'carrinho', 'params' => []],
            'motos'     => ['tipo' => 'motos',    'params' => []],
            ''          => ['tipo' => 'home',     'params' => []],
            default     => null,
        };

        // Rota da loja que o app ainda não replica (páginas de CMS, ajuda,
        // institucional): abre em WebView em vez de sumir com o link.
        $destino ??= ['tipo' => 'web', 'params' => ['caminho' => $caminho]];

        $destino['url'] = str_starts_with($url, 'http') ? $url : $base . $caminho;

        return $destino;
    }

    /**
     * Normaliza a query string da web para os nomes de filtro que o endpoint
     * /catalogo entende. A loja acumulou dois nomes para ordenação (`ordem` e
     * `ordenar`); aqui converge para um só.
     */
    private static function filtros(array $q): array
    {
        $mapa = [
            'q'          => 'q',
            'ordem'      => 'ordem',
            'ordenar'    => 'ordem',
            'promocao'   => 'em_promocao',
            'lancamento' => 'lancamento',
            'marca'      => 'marca',
            'categoria'  => 'categoria',
            'preco_min'  => 'preco_min',
            'preco_max'  => 'preco_max',
        ];

        $out = [];
        foreach ($q as $chave => $valor) {
            if (isset($mapa[$chave]) && $valor !== '') {
                $out[$mapa[$chave]] = $valor;
            }
        }

        // A home usa ?ordenar=recentes; o catálogo chama isso de novidades.
        if (($out['ordem'] ?? null) === 'recentes') {
            $out['ordem'] = 'novidades';
        }

        return $out;
    }
}
