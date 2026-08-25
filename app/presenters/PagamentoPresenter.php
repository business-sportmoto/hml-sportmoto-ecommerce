<?php
// app/presenters/PagamentoPresenter.php
// O bloco de pagamento de um pedido, do jeito que a tela precisa.
//
// Um formato só serve para a resposta do finalizar e para a consulta de status,
// para o app não manter dois parsers do mesmo assunto.
//
// `acao` é a chave central: diz o que a tela deve FAZER agora, em vez de deixar
// o app deduzir isso de uma combinação de status, método e campos nulos.

final class PagamentoPresenter
{
    /** Status em que ainda vale a pena consultar de novo. */
    private const ABERTOS = ['pendente', 'aguardando'];

    private const ROTULOS = [
        'pendente'   => 'Aguardando pagamento',
        'aguardando' => 'Aguardando pagamento',
        'aprovado'   => 'Pagamento aprovado',
        'recusado'   => 'Pagamento recusado',
        'estornado'  => 'Pagamento estornado',
        'reembolsado'=> 'Pagamento reembolsado',
        'erro'       => 'Falha no pagamento',
        'falhou'     => 'Falha no pagamento',
    ];

    public static function de(array $p, PresenterContext $ctx): array
    {
        $status = (string)($p['status_pagamento'] ?? 'pendente');
        $metodo = (string)($p['forma_pagamento'] ?? '');
        $aberto = in_array($status, self::ABERTOS, true);

        return [
            'codigo'   => $p['codigo'] ?? null,
            'pedido_id'=> isset($p['id']) ? (int)$p['id'] : null,

            'status'   => [
                'codigo'   => $status,
                'rotulo'   => self::ROTULOS[$status] ?? ucfirst(str_replace('_', ' ', $status)),
                'aprovado' => $status === 'aprovado',
                'tom'      => match ($status) {
                    'aprovado'                              => 'sucesso',
                    'recusado', 'erro', 'falhou'            => 'erro',
                    'estornado', 'reembolsado'              => 'atencao',
                    default                                 => 'neutro',
                },
            ],

            'metodo'   => $metodo,
            'parcelas' => (int)($p['parcelas'] ?? 1),
            'total'    => PrecoPresenter::dec($p['total'] ?? 0),
            'credito_utilizado' => PrecoPresenter::dec($p['credito_utilizado'] ?? 0),
            'pago_em'  => self::data($p['pago_em'] ?? null),

            // Só monta o bloco do método que realmente vale para este pedido.
            'pix'    => $metodo === 'pix'    ? self::pix($p)    : null,
            'boleto' => $metodo === 'boleto' ? self::boleto($p) : null,

            /**
             * O que a tela faz agora:
             *   pagar_pix     → mostra QR e copia-e-cola, com contagem regressiva
             *   pagar_boleto  → linha digitável e PDF
             *   aguardar      → cartão em análise; só esperar
             *   concluido     → pagamento aprovado
             *   refazer       → recusado; oferecer outra forma de pagamento
             */
            'acao' => match (true) {
                $status === 'aprovado'                         => 'concluido',
                in_array($status, ['recusado','erro','falhou'], true) => 'refazer',
                $aberto && $metodo === 'pix'                    => 'pagar_pix',
                $aberto && $metodo === 'boleto'                 => 'pagar_boleto',
                $aberto                                        => 'aguardar',
                default                                        => 'concluido',
            },

            // Diz ao app se vale continuar consultando — evita que a tela
            // fique batendo no servidor para sempre num pedido já resolvido.
            'consultar_novamente' => $aberto,
        ];
    }

    private static function pix(array $p): ?array
    {
        $copiaCola = $p['pix_copia_cola'] ?? null;
        $qr        = $p['pix_qr_code'] ?? null;

        if (!$copiaCola && !$qr) {
            return null;
        }

        return [
            // A imagem do QR costuma vir como data URI base64 do gateway; o app
            // renderiza direto, sem baixar nada.
            'qr_code'    => $qr,
            'copia_cola' => $copiaCola,
            'expira_em'  => self::data($p['pix_expira_em'] ?? null),
        ];
    }

    private static function boleto(array $p): ?array
    {
        if (empty($p['boleto_linha_digitavel']) && empty($p['boleto_url'])) {
            return null;
        }

        return [
            'linha_digitavel' => $p['boleto_linha_digitavel'] ?? null,
            'url'             => $p['boleto_url'] ?? null,
            'vencimento'      => self::data($p['boleto_vencimento'] ?? null),
        ];
    }

    private static function data(?string $valor): ?string
    {
        if (!$valor) return null;
        $ts = strtotime($valor);
        return $ts ? date(DATE_ATOM, $ts) : null;
    }
}
