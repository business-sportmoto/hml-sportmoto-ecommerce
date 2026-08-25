<?php
// app/presenters/DevolucaoPresenter.php
// Trocas e devoluções.
//
// O que o cliente quer saber, nesta ordem: em que pé está, o que ele precisa
// fazer agora, e quando o dinheiro volta. O payload é montado nessa ordem —
// `acao_necessaria` existe justamente para o app não precisar deduzir, a
// partir do status, se a bola está com o cliente ou com a loja.

final class DevolucaoPresenter
{
    /**
     * Status em que a próxima ação é do CLIENTE. Nos demais, ele espera.
     * Manter isto explícito evita que a tela mande "aguarde" quando na verdade
     * falta o cliente postar o produto.
     */
    private const AGUARDA_CLIENTE = ['pre_aprovado', 'aprovado', 'aguardando_postagem'];

    /** Desfechos: saem da trilha normal de progresso. */
    private const DESFECHOS = ['negado', 'cancelado', 'expirado', 'concluido_reprovado'];

    /** Onde o processo termina bem. */
    private const SUCESSO = ['concluido', 'inspecionado_aprovado'];

    /**
     * Os 14 valores do ENUM de `solicitacoes_devolucao.status`, em português
     * de gente. Sem este mapa, a tela mostrava "Aguardando aprovacao" — o
     * fallback só troca underscore por espaço e não recupera acento.
     */
    private const ROTULOS = [
        'solicitado'              => 'Solicitação enviada',
        'pre_aprovado'            => 'Pré-aprovada',
        'aguardando_aprovacao'    => 'Em análise',
        'aprovado'                => 'Aprovada',
        'negado'                  => 'Negada',
        'aguardando_postagem'     => 'Aguardando postagem',
        'em_transito_reverso'     => 'A caminho da loja',
        'item_recebido'           => 'Recebida pela loja',
        'inspecionado_aprovado'   => 'Inspeção aprovada',
        'inspecionado_reprovado'  => 'Inspeção reprovada',
        'concluido'               => 'Concluída',
        'concluido_reprovado'     => 'Encerrada sem reembolso',
        'cancelado'               => 'Cancelada',
        'expirado'                => 'Prazo expirado',
    ];

    public static function resumo(array $d): array
    {
        $status = (string)($d['status'] ?? 'pendente');

        return [
            'id'        => (int)$d['id'],
            'pedido_id' => (int)($d['pedido_id'] ?? 0),
            'tipo'      => $d['tipo'] ?? 'devolucao',
            'tipo_rotulo' => ($d['tipo'] ?? '') === 'troca' ? 'Troca' : 'Devolução',
            'status'    => self::status($status),
            'valor'     => PrecoPresenter::dec($d['valor_aprovado'] ?? $d['valor_solicitado'] ?? 0),
            'criado_em' => self::data($d['criado_em'] ?? null),
        ];
    }

    public static function detalhe(
        array $d,
        array $itens,
        array $historico,
        PresenterContext $ctx
    ): array {
        $status = (string)($d['status'] ?? 'pendente');

        return [
            'id'        => (int)$d['id'],
            'pedido_id' => (int)($d['pedido_id'] ?? 0),
            'tipo'      => $d['tipo'] ?? 'devolucao',
            'tipo_rotulo' => ($d['tipo'] ?? '') === 'troca' ? 'Troca' : 'Devolução',
            'status'    => self::status($status),
            'descricao' => $d['descricao'] ?? null,
            'motivo'    => $d['motivo_label'] ?? null,

            'criado_em' => self::data($d['criado_em'] ?? null),

            'valores' => [
                'solicitado' => PrecoPresenter::dec($d['valor_solicitado'] ?? 0),
                'aprovado'   => $d['valor_aprovado'] !== null
                    ? PrecoPresenter::dec($d['valor_aprovado'])
                    : null,
                'reembolsado_em' => self::data($d['reembolsado_em'] ?? null),
                'forma' => $d['tipo_reembolso'] ?? null,
            ],

            // O bloco de postagem só faz sentido depois da aprovação — antes
            // dela, mostrar um código vazio confunde.
            'postagem' => empty($d['codigo_postagem_reversa']) ? null : [
                'codigo'          => $d['codigo_postagem_reversa'],
                'validade_dias'   => isset($d['codigo_validade_dias'])
                    ? (int)$d['codigo_validade_dias'] : null,
                'rastreio_reverso'=> $d['codigo_rastreio_reverso'] ?? null,
                'postado_em'      => self::data($d['item_postado_em'] ?? null),
                'recebido_em'     => self::data($d['item_recebido_em'] ?? null),
            ],

            'inspecao' => empty($d['inspecionado_em']) ? null : [
                'resultado'   => $d['inspecao_resultado'] ?? null,
                'observacao'  => $d['inspecao_observacao'] ?? null,
                'concluida_em'=> self::data($d['inspecionado_em']),
            ],

            'negado_motivo' => $d['negado_motivo'] ?? null,

            'itens' => array_values(array_map(static fn(array $i) => [
                'id'         => (int)$i['id'],
                'nome'       => $i['nome_produto'] ?? $i['produto_nome'] ?? '',
                'quantidade' => (int)($i['quantidade'] ?? 1),
                'valor'      => PrecoPresenter::dec($i['valor_unitario'] ?? $i['preco_unitario'] ?? 0),
            ], $itens)),

            'fotos' => self::fotos($d['fotos_json'] ?? null, $ctx),

            'historico' => array_values(array_map(static fn(array $h) => [
                'status'     => $h['status'] ?? $h['status_novo'] ?? null,
                'rotulo'     => self::ROTULOS[$h['status'] ?? $h['status_novo'] ?? ''] ?? null,
                'observacao' => $h['observacao'] ?? null,
                'em'         => self::data($h['criado_em'] ?? null),
            ], $historico)),

            // O que o app deve pedir ao usuário AGORA.
            'acao_necessaria' => self::acao($status, $d),

            // Cancelar só faz sentido antes de o produto sair para a loja.
            // Depois disso quem manda é o fluxo de recebimento e inspeção.
            'pode_cancelar' => in_array($status, [
                'solicitado', 'aguardando_aprovacao', 'pre_aprovado', 'aprovado', 'aguardando_postagem',
            ], true),
        ];
    }

    /* ================================================================= */

    private static function status(string $codigo): array
    {
        return [
            'codigo' => $codigo,
            'rotulo' => self::ROTULOS[$codigo] ?? ucfirst(str_replace('_', ' ', $codigo)),
            'tom'    => match (true) {
                in_array($codigo, self::DESFECHOS, true)       => 'erro',
                in_array($codigo, self::SUCESSO, true)         => 'sucesso',
                in_array($codigo, self::AGUARDA_CLIENTE, true) => 'atencao',
                default                                        => 'neutro',
            },
            'encerrado' => in_array($codigo, [...self::DESFECHOS, 'concluido'], true),
        ];
    }

    /**
     * Instrução acionável, não status. "Aprovada" não diz ao cliente que ele
     * precisa levar o pacote aos Correios — esta chave diz.
     */
    private static function acao(string $status, array $d): ?array
    {
        if (in_array($status, self::AGUARDA_CLIENTE, true)) {
            return empty($d['codigo_postagem_reversa'])
                ? ['tipo' => 'aguardar_codigo', 'texto' => 'Estamos gerando seu código de postagem.']
                : [
                    'tipo'  => 'postar',
                    'texto' => 'Leve o produto a uma agência dos Correios com o código de postagem e depois informe o rastreio aqui.',
                ];
        }

        if ($status === 'em_transito_reverso' && empty($d['codigo_rastreio_reverso'])) {
            return ['tipo' => 'informar_rastreio', 'texto' => 'Informe o código de rastreio da postagem.'];
        }

        return null;
    }

    private static function fotos(?string $json, PresenterContext $ctx): array
    {
        if (!$json) {
            return [];
        }
        $lista = json_decode($json, true);
        if (!is_array($lista)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn($f) => $ctx->url(is_array($f) ? ($f['url'] ?? $f['arquivo'] ?? null) : (string)$f),
            $lista
        )));
    }

    private static function data(?string $valor): ?string
    {
        if (!$valor) return null;
        $ts = strtotime($valor);
        return $ts ? date(DATE_ATOM, $ts) : null;
    }
}
