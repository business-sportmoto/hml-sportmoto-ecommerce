<?php
// app/presenters/BeneficioPresenter.php
//
// A faixa de benefícios da home — frete grátis, garantia, parcelamento.
// Espelha views/partials/benefits-slider.php.
//
// Duas traduções acontecem aqui, e ambas existem porque o cadastro foi feito
// para a web:
//
//  1. `icone` guarda o nome de um SVG da IconLibrary da loja ("person-shield",
//     "add-location-alt"). O app tem Material Symbols e não conhece esses
//     nomes. O mapa abaixo converte; um ícone novo cadastrado no admin cai no
//     genérico em vez de deixar o card sem símbolo.
//
//  2. `css_classe` é um GANCHO DE COMPORTAMENTO disfarçado de estilo: o card do
//     CEP não tem link, tem a classe `btn-open-location`, e o JS da loja usa
//     isso para abrir o seletor de CEP. No app não existe CSS para pendurar
//     comportamento, então a classe vira `acao`, um verbo explícito.

final class BeneficioPresenter
{
    /** Nome do SVG da loja → nome do ícone no app. */
    private const ICONES = [
        'truck'             => 'entrega',
        'shield'            => 'seguro',
        'shield-card'       => 'cartao',
        'credit'            => 'cartao',
        'headset'           => 'suporte',
        'headphones'        => 'suporte',
        'star'              => 'estrela',
        'gift'              => 'presente',
        'tag'               => 'etiqueta',
        'refresh'           => 'devolucao',
        'favorite'          => 'favorito',
        'heart-check'       => 'favorito',
        'person-shield'     => 'conta',
        'add-location-alt'  => 'local',
        'motorcycle'        => 'moto',
    ];

    /**
     * Classes que a loja usa como gancho de JS. Cada uma vira uma ação que o
     * app sabe executar.
     */
    private const ACOES = [
        'btn-open-location' => 'cep',
    ];

    /** @return array<int,array> */
    public static function colecao(array $rows, PresenterContext $ctx): array
    {
        return array_values(array_map(
            static fn(array $b) => self::um($b, $ctx),
            $rows
        ));
    }

    public static function um(array $b, PresenterContext $ctx): array
    {
        $classe = trim((string)($b['css_classe'] ?? ''));
        $acao   = self::ACOES[$classe] ?? null;
        $link   = trim((string)($b['link'] ?? ''));

        return [
            'icone'     => self::ICONES[$b['icone'] ?? ''] ?? 'estrela',
            'titulo'    => trim((string)($b['titulo'] ?? '')),
            'descricao' => trim((string)($b['descricao'] ?? '')),

            // Ação tem precedência sobre link: o card do CEP não tem link
            // nenhum, e é a ação que o torna tocável.
            'acao'      => $acao,
            'destino'   => $acao === null && $link !== '' ? DestinoPresenter::de($link) : null,
        ];
    }
}
