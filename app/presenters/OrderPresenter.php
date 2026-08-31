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

    /**
     * `status_pedido` é varchar, não ENUM: o admin pode gravar o que quiser, e
     * de fato há `aguardando` e `troca_devolucao` no banco além dos cinco da
     * trilha. Este mapa cobre os que existem hoje; qualquer valor novo cai em
     * humanizar() e aparece com tom neutro, em vez de quebrar a tela.
     */
    private const APELIDOS = [
        'aguardando'     => 'aguardando_pagamento',
        'troca_devolucao'=> 'devolvido',
    ];

    private const DESFECHOS = ['cancelado', 'devolvido', 'estornado', 'troca_devolucao'];

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

    /**
     * Pedido completo.
     *
     * @param array|null $rastreio  Saída de RastreioService::porPedido().
     * @param array|null $devolucao Elegibilidade, para o app decidir o botão.
     */
    public static function detalhe(
        array $p,
        array $itens,
        PresenterContext $ctx,
        ?array $rastreio = null,
        ?array $devolucao = null
    ): array {
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

            // O rastreio de verdade, vindo de log_rastreios. `entrega.rastreio`
            // é só o código solto que a tabela `pedidos` guarda; isto aqui é o
            // que a transportadora respondeu.
            'rastreio'  => $rastreio ? self::rastreio($rastreio) : null,

            // Se o botão de devolução aparece — e, quando não aparece, por quê.
            // Vem junto do pedido porque a tela precisa decidir isso ANTES de
            // desenhar o rodapé; numa segunda chamada o botão apareceria depois,
            // empurrando o layout.
            'devolucao' => $devolucao ? [
                'pode'        => !empty($devolucao['pode']),
                'motivo'      => $devolucao['motivo'] ?? null,
                'prazo_dias'  => (int)($devolucao['prazo_dias'] ?? 0),
                'dias_desde'  => $devolucao['dias_desde'] ?? null,
                'entregue_em' => $devolucao['entregue_em'] ?? null,
            ] : null,

            'observacao' => $p['observacao_cliente'] ?? null,
        ];
    }

    /* ================================================================= */

    private static function status(array $p): array
    {
        $codigo = (string)($p['status_pedido'] ?? 'aguardando_pagamento');
        // Para rótulo e tom, o apelido vale: quem vê "aguardando" deve ler
        // "Aguardando pagamento", não "Aguardando".
        $normal = self::APELIDOS[$codigo] ?? $codigo;

        return [
            'codigo'   => $codigo,
            'rotulo'   => self::ETAPAS[$normal] ?? self::humanizar($codigo),
            'encerrado'=> in_array($normal, self::DESFECHOS, true),
            'tom'      => self::tom($normal),
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
        $bruto = (string)($p['status_pedido'] ?? '');
        // `aguardando` é o mesmo que `aguardando_pagamento` para efeito de
        // trilha; sem o apelido ele caía fora do array_search e a régua inteira
        // voltava para o primeiro passo.
        $atual = self::APELIDOS[$bruto] ?? $bruto;

        // Desfecho: a trilha normal não se aplica, mostra só onde parou.
        if (in_array($atual, self::DESFECHOS, true)) {
            return [[
                // O código BRUTO: "troca_devolucao" e "devolvido" são desfechos
                // parecidos mas não iguais, e a tela merece saber qual foi.
                'codigo'    => $bruto,
                'rotulo'    => self::humanizar($bruto),
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
        return array_values(array_map(static function (array $i) use ($ctx): array {
            $original = (float)($i['valor_original'] ?? 0);
            $pago     = (float)($i['preco_unitario'] ?? 0);

            return [
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
                'preco'      => PrecoPresenter::dec($pago),
                'subtotal'   => PrecoPresenter::dec($i['subtotal'] ?? 0),

                // As variações escolhidas — "Cor: Preto", "Tamanho: 58".
                // Order::getItemsWithVariacoes() já resolve isso em três
                // camadas (snapshot da compra, agrupadores do produto,
                // atributos do SKU) e devolve em `atributos`. O presenter lia
                // `variacao_texto`, campo que NÃO existe na consulta: a
                // variação chegava sempre nula no app, mesmo quando o item
                // tinha uma.
                'variacoes'  => self::variacoes($i['atributos'] ?? []),
                'sku'        => self::texto($i['sku'] ?? null),

                // Brinde entra com preço zero e precisa ser dito, senão parece
                // erro de cobrança.
                'brinde'     => !empty($i['is_brinde']),

                // Preço cheio quando houve desconto de cupom no item, para o
                // app poder riscar o valor antigo.
                'preco_original' => $original > $pago ? PrecoPresenter::dec($original) : null,
                'desconto'   => PrecoPresenter::dec($i['desconto_cupom'] ?? 0),
            ];
        }, $rows));
    }

    /**
     * Atributos do item no formato que a tela desenha.
     *
     * `valor_hex` só existe em atributo de cor, e é o que permite mostrar a
     * bolinha da cor em vez de só o nome dela.
     *
     * @return array<int,array{nome:string,valor:string,cor:?string}>
     */
    private static function variacoes($atributos): array
    {
        if (!is_array($atributos)) {
            return [];
        }

        $saida = [];
        foreach ($atributos as $a) {
            if (!is_array($a)) continue;

            $nome  = self::texto($a['nome'] ?? null);
            $valor = self::texto($a['valor'] ?? null);
            if ($nome === null || $valor === null) continue;

            $saida[] = [
                'nome'  => $nome,
                'valor' => $valor,
                'cor'   => self::texto($a['valor_hex'] ?? null),
            ];
        }
        return $saida;
    }

    private static function texto($v): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    private static function pagamento(array $p): array
    {
        $metodo = (string)($p['forma_pagamento'] ?? '');

        $parcelas = isset($p['parcelas']) ? max(1, (int)$p['parcelas']) : 1;
        $total    = (float)($p['total'] ?? 0);
        $estado   = (string)($p['status_pagamento'] ?? '');

        $dados = [
            'metodo'   => $metodo,
            'rotulo'   => match ($metodo) {
                'pix'     => 'Pix',
                'boleto'  => 'Boleto',
                'credito', 'cartao' => 'Cartão de crédito',
                default   => self::humanizar($metodo),
            },
            'status'   => $estado ?: null,
            // O estado do pagamento em português, e o tom para a tela pintar.
            // Sem isto o app mostraria "aprovado" cru ao lado de "pendente".
            // Os oito valores do ENUM de pedidos.status_pagamento, todos.
            // Deixar algum de fora fazia "erro" e "falhou" — 15 pedidos hoje —
            // chegarem ao cliente como "Erro" com tom neutro, que é o pior
            // dos dois mundos: assusta e não explica.
            'status_rotulo' => match ($estado) {
                'aprovado'    => 'Pagamento aprovado',
                'pendente'    => 'Aguardando pagamento',
                'aguardando'  => 'Aguardando confirmação',
                'recusado'    => 'Pagamento recusado',
                'estornado'   => 'Pagamento estornado',
                'reembolsado' => 'Pagamento reembolsado',
                'erro', 'falhou' => 'Não foi possível concluir o pagamento',
                default       => $estado ? self::humanizar($estado) : null,
            },
            'status_tom' => match ($estado) {
                'aprovado'                             => 'sucesso',
                'recusado', 'erro', 'falhou'           => 'erro',
                'estornado', 'reembolsado'             => 'erro',
                'pendente', 'aguardando'               => 'atencao',
                default                                => 'neutro',
            },
            'parcelas' => $parcelas,
            'pago_em'  => self::data($p['pago_em'] ?? null),
        ];

        // "12x de R$ 41,66" — a forma como a pessoa realmente pensa a compra
        // parcelada. O valor sai daqui pronto para não haver duas divisões
        // diferentes (uma no app, outra no site) arredondando de jeitos
        // diferentes no último centavo.
        if ($parcelas > 1 && $total > 0) {
            $dados['parcela_valor'] = PrecoPresenter::dec(round($total / $parcelas, 2));
            $dados['parcelamento_texto'] = $parcelas . 'x de '
                . PriceHelper::format(round($total / $parcelas, 2));
        }

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

    /**
     * O rastreio da encomenda.
     *
     * Duas leituras da mesma coisa, porque a tela precisa das duas:
     *
     *   `etapas`  — a trilha canônica (postado → em trânsito → saiu para
     *               entrega → entregue), com a atual marcada. É o que desenha a
     *               régua horizontal, e ela precisa mostrar TAMBÉM o que ainda
     *               não aconteceu; uma régua só com o passado não diz o que
     *               falta.
     *   `eventos` — o que a transportadora de fato registrou, com data e
     *               cidade. É onde a pessoa vai quando o resumo não basta.
     *
     * `ocorrencia` e `devolucao` saem da trilha: não são um passo antes do fim,
     * são outro desfecho, e forçá-los na régua daria a impressão de que a
     * entrega segue caminhando.
     */
    private static function rastreio(array $r): array
    {
        $status = (string)($r['status_interno'] ?? '');
        $fora   = in_array($status, ['ocorrencia', 'devolucao'], true);

        return [
            'codigo'         => self::texto($r['codigo_rastreio'] ?? null),
            'transportadora' => self::texto($r['transportadora_nome'] ?? null),
            'status'         => $status,
            'status_rotulo'  => (string)($r['status_label'] ?? ''),
            'destino'        => self::texto(trim(
                (string)($r['destino_cidade'] ?? '') .
                ($r['destino_uf'] ?? '' ? '/' . $r['destino_uf'] : '')
            )),
            'previsao'   => self::data($r['previsao_entrega'] ?? null),
            'postado_em' => self::data($r['postado_em'] ?? null),
            'entregue_em'=> self::data($r['entregue_em'] ?? null),

            // Sinalizadores que mudam o tom da tela inteira.
            'atrasado'   => !empty($r['atraso']),
            'ocorrencia' => !empty($r['ocorrencia']) || $status === 'ocorrencia',
            'fora_da_trilha' => $fora,

            'etapas'  => $fora ? [] : self::etapasRastreio($status),
            'eventos' => array_values(array_map(static fn(array $e) => [
                'em'        => self::data($e['data_evento'] ?? null),
                'status'    => $e['status_interno'] ?? null,
                'rotulo'    => (string)($e['status_label'] ?? ''),
                'descricao' => self::texto($e['descricao'] ?? null),
                'local'     => self::texto($e['local'] ?? null),
            ], $r['eventos'] ?? [])),
        ];
    }

    /** Trilha canônica da encomenda, com a etapa atual marcada. */
    private static function etapasRastreio(string $atual): array
    {
        $trilha = [
            'postado'      => 'Postado',
            'em_transito'  => 'Em trânsito',
            'saiu_entrega' => 'Saiu para entrega',
            'entregue'     => 'Entregue',
        ];

        $chaves = array_keys($trilha);
        $indice = array_search($atual, $chaves, true);

        // Status anterior à postagem (aguardando ou etiqueta emitida): nada
        // concluído ainda, e a primeira etapa é a que está por vir.
        if ($indice === false) {
            $indice = -1;
        }

        $saida = [];
        foreach ($chaves as $i => $codigo) {
            $saida[] = [
                'codigo'    => $codigo,
                'rotulo'    => $trilha[$codigo],
                'concluida' => $i <= $indice,
                'atual'     => $i === $indice,
            ];
        }
        return $saida;
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
