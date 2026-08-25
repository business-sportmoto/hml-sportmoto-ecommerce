<?php
// app/presenters/NotificacaoPresenter.php
// Notificações do cliente.
//
// NotificacaoService::listar() já devolve `icone` e `cor` prontos para a web —
// mas `icone` é um nome do Bootstrap Icons ("bi-bell") e `cor` é um hex fixo.
// Nenhum dos dois serve aqui: o app tem Material Symbols e um tema que muda com
// o esquema de cor. Então o presenter descarta os dois e manda `categoria`, que
// é o dado real; o app decide como desenha.

final class NotificacaoPresenter
{
    /**
     * Rótulos por categoria. O ENUM da tabela tem 6 valores; qualquer um novo
     * cai no fallback em vez de aparecer em branco na tela.
     */
    private const ROTULOS = [
        'pedido'     => 'Pedido',
        'promocao'   => 'Promoção',
        'sistema'    => 'Aviso',
        'estoque'    => 'Estoque',
        'financeiro' => 'Financeiro',
        'conta'      => 'Conta',
    ];

    /** @return array<int,array> */
    public static function colecao(array $rows, PresenterContext $ctx): array
    {
        return array_values(array_map(
            static fn(array $n) => self::uma($n, $ctx),
            $rows
        ));
    }

    public static function uma(array $n, PresenterContext $ctx): array
    {
        $categoria = (string)($n['categoria'] ?? 'sistema');

        return [
            // `id` é o da notificacao_usuarios (nu_id), não o da notificação:
            // é ele que identifica a CÓPIA deste cliente, e é o que
            // marcarLida() valida contra o destinatário.
            'id'              => (int)($n['nu_id'] ?? $n['id'] ?? 0),
            'categoria'       => $categoria,
            'categoria_rotulo'=> self::ROTULOS[$categoria] ?? 'Aviso',
            'titulo'          => (string)($n['titulo'] ?? ''),
            'mensagem'        => $n['mensagem'] ?? null,
            'imagem'          => $ctx->url($n['imagem_url'] ?? null),
            'lida'            => !empty($n['lida']),
            'em'              => self::data($n['recebido_em'] ?? $n['criado_em'] ?? null),

            // O app não interpreta URL: DestinoPresenter traduz o link da loja
            // para uma rota do app (ou marca como externo).
            'destino'         => DestinoPresenter::de($n['url'] ?? null),
        ];
    }

    private static function data(?string $valor): ?string
    {
        if (!$valor) return null;
        $ts = strtotime($valor);
        return $ts ? date(DATE_ATOM, $ts) : null;
    }
}
