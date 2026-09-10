<?php
// app/presenters/FiltroStatusPresenter.php
//
// Os chips de filtro por status que aparecem no topo de uma lista.
//
// Espelha o comportamento de views/customer/orders.php, que é o que o cliente
// já conhece do site:
//
//   - "Todos" SEMPRE aparece, com o total geral.
//   - Cada status só aparece se o cliente tiver ao menos um registro nele.
//   - O status ATIVO continua visível mesmo zerado — senão o chip selecionado
//     sumiria debaixo do dedo ao filtrar por algo que acabou de esvaziar.
//
// A regra mora aqui, e não na tela, porque as duas listas que usam isto
// (pedidos e devoluções) têm de se comportar igual, e porque "quais status
// existem" é resposta de quem consultou o banco.
final class FiltroStatusPresenter
{
    /**
     * O app fala em `tom` (sucesso/erro/atencao/neutro); o banco e os helpers
     * falam em cor de framework (success/danger/warning/info/...).
     *
     * A tradução é aqui e não no app pelo motivo de sempre: status novo
     * cadastrado no admin com uma cor que o app não conhece tem de cair em
     * neutro, não quebrar a tela.
     */
    private const TONS = [
        'success' => 'sucesso',
        'danger'  => 'erro',
        'warning' => 'atencao',
    ];

    public static function tom(?string $cor): string
    {
        return self::TONS[(string)$cor] ?? 'neutro';
    }

    /**
     * @param array<string,int>   $contagens  slug => quantos, só o que existe
     * @param array<string,array> $definicoes slug => ['label'=>, 'cor'=>], NA
     *                                        ORDEM em que devem aparecer
     * @param string              $ativo      slug selecionado; '' é "Todos"
     *
     * @return array<int,array{slug:string,rotulo:string,tom:string,total:int,ativo:bool}>
     */
    public static function montar(
        array $contagens,
        array $definicoes,
        string $ativo = '',
        string $rotuloTodos = 'Todos'
    ): array {
        $chips = [[
            'slug'   => '',
            'rotulo' => $rotuloTodos,
            'tom'    => 'neutro',
            'total'  => array_sum($contagens),
            'ativo'  => $ativo === '',
        ]];

        foreach ($definicoes as $slug => $info) {
            $total = (int)($contagens[(string)$slug] ?? 0);

            if ($total === 0 && $ativo !== (string)$slug) {
                continue;
            }

            $chips[] = [
                'slug'   => (string)$slug,
                'rotulo' => (string)($info['label'] ?? $info['rotulo'] ?? $slug),
                'tom'    => self::tom($info['cor'] ?? null),
                'total'  => $total,
                'ativo'  => $ativo === (string)$slug,
            ];
        }

        // Status que existe em pedido mas não está no catálogo de definições
        // (slug gravado à mão, status desativado depois de já ter sido usado).
        // Sem isto o cliente teria pedidos invisíveis em qualquer filtro que
        // não fosse "Todos" — e o total de "Todos" não fecharia com a soma dos
        // chips, que é o tipo de coisa que ninguém consegue explicar depois.
        $conhecidos = array_map('strval', array_keys($definicoes));
        foreach ($contagens as $slug => $total) {
            if ($total > 0 && !in_array((string)$slug, $conhecidos, true)) {
                $chips[] = [
                    'slug'   => (string)$slug,
                    'rotulo' => ucfirst(str_replace('_', ' ', (string)$slug)),
                    'tom'    => 'neutro',
                    'total'  => (int)$total,
                    'ativo'  => $ativo === (string)$slug,
                ];
            }
        }

        return $chips;
    }
}
