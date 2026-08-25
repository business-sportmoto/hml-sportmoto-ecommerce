<?php
// app/presenters/OrderPresenter.php
// Pedidos.
//
// A linha do tempo de status é o que o cliente realmente quer ver — "onde está
// meu pedido" é a pergunta número um do pós-venda. Por isso ela vem pronta do
// servidor, com as etapas já ordenadas e a atual marcada, em vez de o app ter
// que inferir isso a partir de uma string de status.

final class OrderPresenter
{
    /**
     * Etapas do fluxo normal, na ordem. Cancelado e devolvido saem desta
     * trilha e são tratados à parte — não são "um passo antes do fim", são
     * outro desfecho.
     */
    private const ETAPAS = [
        'aguardando_pagamento' => 'Aguardando pagamento',
        'pagamento_aprovado'   => 'Pagamento aprovado',
        'em_separacao'         => 'Em separação',
        'enviado'              => 'Enviado',
        'entregue'             => 'Entregue',
    ];

    private const DESFECHOS = ['cancelado', 'devolvido', 'estornado'];

    /** Resumo para a lista de pedidos. */
    public static function resumo(array $p, PresenterContext $ctx): array
    {
        return [
            'id'        => (int)$p['id'],
            'codigo'    => $p['codigo'],
            'status'    => self::status($p),
            'total'     => PrecoPresenter::dec($p['total'] ?? 0),
            'criado_em' => self::data($p['criado_em'] ?? null),
            'itens_total' => isset($p['itens_total']) ? (int)$p['itens_total'] : null,
            // Produto sem imagem cadastrada não vira `null` no meio da lista:
            // o app desenharia um buraco. Sai da prévia.
            'previa'    => array_values(array_filter(array_map(
                static fn(array $i) => $ctx->url($i['imagem'] ?? null),
                array_slice($p['previa_itens'] ?? [], 0, 3)
            ))),
            'rastreio'  => $p['codigo_rastreio'] ?? null,
        ];
    }

    /** Pedido completo. */
    public static function detalhe(array $p, array $itens, PresenterContext $ctx): array
    {
        return [
            'id'     => (int)$p['id'],
            'codigo' => $p['codigo'],
            'status' => self::status($p),
            'linha_do_tempo' => self::linhaDoTempo($p),

            'criado_em' => self::data($p['criado_em'] ?? null),
            'pago_em'   => self::data($p['pago_em'] ?? null),
            'enviado_em'=> self::data($p['enviado_em'] ?? null),

            'itens' => self::itens($itens, $ctx),

            'totais' => [
                'subtotal' => PrecoPresenter::dec($p['subtotal'] ?? 0),
                'frete'    => PrecoPresenter::dec($p['frete'] ?? 0),
                'desconto' => PrecoPresenter::dec($p['desconto'] ?? 0),
                'credito'  => PrecoPresenter::dec($p['credito_utilizado'] ?? 0),
                'total'    => PrecoPresenter::dec($p['total'] ?? 0),
            ],

            'pagamento' => self::pagamento($p),
            'entrega'   => self::entrega($p),

            'observacao' => $p['observacao_cliente'] ?? null,
        ];
    }

    /* ================================================================= */

    private static function status(array $p): array
    {
        $codigo = (string)($p['status_pedido'] ?? 'aguardando_pagamento');

        return [
            'codigo'   => $codigo,
            'rotulo'   => self::ETAPAS[$codigo] ?? self::humanizar($codigo),
            'encerrado'=> in_array($codigo, self::DESFECHOS, true),
            'tom'      => self::tom($codigo),
        ];
    }

    /**
     * `tom` diz ao app QUAL COR usar sem que ele precise conhecer cada status.
     * Um status novo cadastrado no admin aparece com tom neutro em vez de
     * quebrar a tela.
     */
    private static function tom(string $codigo): string
    {
        if (in_array($codigo, self::DESFECHOS, true))            return 'erro';
        if ($codigo === 'entregue')                              return 'sucesso';
        if ($codigo === 'aguardando_pagamento')                  return 'atencao';
        return 'neutro';
    }

    private static function linhaDoTempo(array $p): array
    {
        $atual = (string)($p['status_pedido'] ?? '');

        // Desfecho: a trilha normal não se aplica, mostra só onde parou.
        if (in_array($atual, self::DESFECHOS, true)) {
            return [[
                'codigo'    => $atual,
                'rotulo'    => self::humanizar($atual),
                'concluida' => true,
                'atual'     => true,
            ]];
        }

        $chaves  = array_keys(self::ETAPAS);
        $indice  = array_search($atual, $chaves, true);
        $indice  = $indice === false ? 0 : $indice;

        $saida = [];
        foreach ($chaves as $i => $codigo) {
            $saida[] = [
                'codigo'    => $codigo,
                'rotulo'    => self::ETAPAS[$codigo],
                'concluida' => $i <= $indice,
                'atual'     => $i === $indice,
            ];
        }
        return $saida;
    }

    private static function itens(array $rows, PresenterContext $ctx): array
    {
        return array_values(array_map(static fn(array $i) => [
            'id'         => (int)$i['id'],
            'produto_id' => isset($i['produto_id']) ? (int)$i['produto_id'] : null,
            'nome'       => $i['produto_nome'] ?? $i['nome_produto'] ?? '',
            'slug'       => $i['produto_slug'] ?? null,
            // O snapshot vem primeiro: é a imagem que o produto tinha NA
            // COMPRA. Se a foto foi trocada depois, o pedido antigo deve
            // continuar mostrando o que a pessoa viu quando comprou.
            'imagem'     => $ctx->url($i['imagem_snapshot'] ?? null)
                            ?? $ctx->url($i['imagem'] ?? $i['imagem_arquivo'] ?? null)
                            ?? $ctx->url('images/placeholder.jpg', 'asset'),
            'quantidade' => (int)($i['quantidade'] ?? 1),
            'preco'      => PrecoPresenter::dec($i['preco_unitario'] ?? 0),
            'subtotal'   => PrecoPresenter::dec($i['subtotal'] ?? 0),
            'variacao'   => $i['variacao_texto'] ?? null,
        ], $rows));
    }

    private static function pagamento(array $p): array
    {
        $metodo = (string)($p['forma_pagamento'] ?? '');

        $dados = [
            'metodo'   => $metodo,
            'rotulo'   => match ($metodo) {
                'pix'     => 'Pix',
                'boleto'  => 'Boleto',
                'credito', 'cartao' => 'Cartão de crédito',
                default   => self::humanizar($metodo),
            },
            'status'   => $p['status_pagamento'] ?? null,
            'parcelas' => isset($p['parcelas']) ? (int)$p['parcelas'] : null,
        ];

        if (!empty($p['cartao_ultimos_4'])) {
            $dados['cartao'] = [
                'bandeira' => $p['cartao_bandeira'] ?? null,
                'final'    => $p['cartao_ultimos_4'],
            ];
        }

        // PIX e boleto só interessam enquanto o pedido não foi pago — depois
        // são lixo na tela, e o copia-e-cola vencido só gera confusão.
        $pago = !empty($p['pago_em']);

        if (!$pago && $metodo === 'pix' && !empty($p['pix_copia_cola'])) {
            $dados['pix'] = [
                'copia_cola' => $p['pix_copia_cola'],
                'qr_code'    => $p['pix_qr_code'] ?? null,
                'expira_em'  => self::data($p['pix_expira_em'] ?? null),
            ];
        }

        if (!$pago && $metodo === 'boleto' && !empty($p['boleto_linha_digitavel'])) {
            $dados['boleto'] = [
                'linha_digitavel' => $p['boleto_linha_digitavel'],
                'url'             => $p['boleto_url'] ?? null,
                'vencimento'      => self::data($p['boleto_vencimento'] ?? null),
            ];
        }

        return $dados;
    }

    private static function entrega(array $p): array
    {
        // O snapshot é a verdade histórica: o endereço pode ter sido editado
        // ou apagado depois da compra, e o pedido tem que continuar mostrando
        // para onde foi de fato.
        $snapshot = null;
        if (!empty($p['endereco_entrega_snapshot'])) {
            $snapshot = json_decode((string)$p['endereco_entrega_snapshot'], true) ?: null;
        }

        $endereco = $snapshot ?? array_filter([
            'logradouro' => $p['ent_logradouro'] ?? null,
            'numero'     => $p['ent_numero'] ?? null,
            'bairro'     => $p['ent_bairro'] ?? null,
            'cidade'     => $p['ent_cidade'] ?? null,
            'estado'     => $p['ent_estado'] ?? null,
            'cep'        => $p['ent_cep'] ?? null,
        ], static fn($v) => $v !== null);

        return [
            'endereco' => $endereco ?: null,
            'servico'  => $p['frete_descricao'] ?? $p['frete_servico'] ?? null,
            'prazo'    => $p['frete_prazo'] ?? ($p['frete_prazo_dias'] ?? null),
            'rastreio' => $p['codigo_rastreio'] ?? null,
        ];
    }

    private static function data(?string $valor): ?string
    {
        if (!$valor) return null;
        $ts = strtotime($valor);
        return $ts ? date(DATE_ATOM, $ts) : null;
    }

    /** aguardando_pagamento → "Aguardando pagamento" */
    private static function humanizar(string $codigo): string
    {
        return ucfirst(str_replace('_', ' ', $codigo));
    }
}
