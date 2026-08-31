<?php
// app/presenters/FretePresenter.php
// Frete na vitrine — a cotação de 1 unidade mostrada na página do produto.
//
// FreteVitrineService::cotar() já devolve as opções normalizadas e com
// `valor_fmt` em real formatado. Aqui os valores viram STRING DECIMAL, como
// todo dinheiro nesta API: o app faz aritmética em centavos e formata com
// Intl.NumberFormat('pt-BR'). Mandar "R$ 24,90" pronto obrigaria o cliente a
// desformatar para somar qualquer coisa.
//
// `estimativa` é a informação mais importante do bloco e vem separada de
// propósito: significa que a transportadora não respondeu e o valor veio do
// fallback. A tela precisa dizer isso — prometer prazo de entrega com número
// inventado é o tipo de erro que vira reclamação.

final class FretePresenter
{
    public static function vitrine(array $res, string $cep): array
    {
        $opcoes = array_values(array_map(
            static fn(array $o) => self::opcao($o),
            $res['opcoes'] ?? []
        ));

        return [
            'tem_cep'    => true,
            'cep'        => $cep,
            'cep_fmt'    => CepController::formatCep($cep),
            'localidade' => $res['localidade'] ?? null,
            'uf'         => $res['uf'] ?? null,

            'opcoes'     => $opcoes,

            // O motor já escolheu a mais barata e a mais rápida; a tela mostra
            // essas duas e esconde o resto atrás de "ver todas".
            'destaques'  => array_values(array_map(
                static fn(array $d) => [
                    'tipo'  => (string)($d['tipo'] ?? 'unica'),
                    'opcao' => self::opcao($d['opcao'] ?? []),
                ],
                $res['destaques'] ?? []
            )),

            'cta'        => self::cta($res['cta'] ?? []),

            // true = veio do fallback, não da transportadora.
            'estimativa' => !empty($res['estimativa']),
            'origem'     => $res['origem'] ?? null,
        ];
    }

    private static function opcao(array $o): array
    {
        $gratis = !empty($o['frete_gratis']);
        $valor  = (float)($o['valor'] ?? 0);
        $prazo  = (int)($o['prazo_dias'] ?? 0);

        return [
            'transportadora' => $o['transportadora'] ?? null,
            'servico'        => $o['servico'] ?? null,
            'servico_codigo' => $o['servico_codigo'] ?? null,
            'categoria'      => $o['categoria'] ?? 'padrao',
            'prazo_dias'     => $prazo,
            // Frete grátis é zero, e não "o valor que seria cobrado": o app
            // soma isto no total.
            'valor'          => PrecoPresenter::dec($gratis ? 0 : $valor),
            'frete_gratis'   => $gratis,
            'mais_barato'    => !empty($o['mais_barato']),
            'mais_rapido'    => !empty($o['mais_rapido']),

            // A DATA, não só a contagem de dias. "Chega quinta-feira" responde
            // à pergunta que a pessoa realmente tem; "até 3 dias úteis" obriga
            // cada cliente a contar no calendário — e a contar errado, porque
            // sábado e domingo não são dias úteis.
            //
            // O cálculo mora aqui porque é o servidor que sabe o que é dia útil
            // neste negócio. O app só escolhe como escrever a data.
            'data_entrega'   => self::diaUtil($prazo),
        ];
    }

    /**
     * Data de entrega a partir do prazo em dias ÚTEIS.
     *
     * Conta a partir de amanhã: um pedido fechado hoje não é postado hoje.
     * Feriados nacionais ficam de fora — a loja não mantém esse calendário, e
     * inventar um daria uma data errada com cara de precisa.
     */
    private static function diaUtil(int $prazoDias): ?string
    {
        if ($prazoDias <= 0) {
            return null;
        }

        $data  = new DateTimeImmutable('tomorrow');
        $uteis = 0;

        // Teto de segurança: um prazo absurdo vindo da transportadora não pode
        // virar laço infinito dentro de um request.
        for ($i = 0; $i < 400; $i++) {
            if ((int)$data->format('N') <= 5) {
                $uteis++;
                if ($uteis >= $prazoDias) {
                    break;
                }
            }
            $data = $data->modify('+1 day');
        }

        return $data->format('Y-m-d');
    }

    /**
     * O empurrão de frete grátis. `tipo` diz o que a tela desenha:
     *   ja_tem  → selo verde        falta → "faltam R$ X"
     *   ganha   → "adicione e ganha"  nenhum → não desenha nada
     */
    private static function cta(array $cta): ?array
    {
        $tipo = (string)($cta['tipo'] ?? 'nenhum');
        if ($tipo === 'nenhum' && !isset($cta['limiar'])) {
            return null;
        }

        return [
            'tipo'     => $tipo,
            'mensagem' => $cta['mensagem'] ?? null,
            'faltam'   => isset($cta['faltam']) ? PrecoPresenter::dec($cta['faltam']) : null,
            'limiar'   => isset($cta['limiar']) ? PrecoPresenter::dec($cta['limiar']) : null,
        ];
    }
}
