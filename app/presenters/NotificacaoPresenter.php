<?php
// app/presenters/NotificacaoPresenter.php
// Notificações do cliente.
//
// NotificacaoService::listar() já devolve `icone` e `cor` prontos para a web —
// mas `icone` é uma chave do IconLibrary ("alerta", "package") e `cor` é um
// hex fixo.
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

    /**
     * @param array<int,string> $codigosDePedido id do pedido => código. Ver
     *                                           resolverPedido() para o porquê.
     * @return array<int,array>
     */
    public static function colecao(array $rows, PresenterContext $ctx, array $codigosDePedido = []): array
    {
        return array_values(array_map(
            static fn(array $n) => self::uma($n, $ctx, $codigosDePedido),
            $rows
        ));
    }

    public static function uma(array $n, PresenterContext $ctx, array $codigosDePedido = []): array
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
            'destino'         => self::resolverPedido(
                DestinoPresenter::de($n['url'] ?? null),
                $codigosDePedido
            ),
        ];
    }

    /**
     * Troca o id do pedido pelo código.
     *
     * As notificações de pedido guardam o ID (`/minha-conta/pedido/227`), e a
     * tela do app abre por CÓDIGO (`/pedido/62399740`). O DestinoPresenter
     * marca esses casos com `pedido_id` e código vazio; aqui o mapa fecha a
     * conta.
     *
     * O mapa vem PRONTO de quem montou a lista — uma consulta para a página
     * inteira. Resolver aqui, notificação por notificação, seria vinte
     * consultas numa lista de vinte, e é justamente o que a regra do projeto
     * proíbe para presenter.
     *
     * Sem código (pedido apagado, ou o id não veio no mapa) o destino cai para
     * a LISTA de pedidos: melhor abrir a lista do que uma tela de pedido que
     * não existe.
     */
    private static function resolverPedido(?array $destino, array $codigos): ?array
    {
        if ($destino === null || ($destino['tipo'] ?? '') !== 'pedido') {
            return $destino;
        }

        $id = (int)($destino['params']['pedido_id'] ?? 0);

        if ($id === 0) {
            return $destino; // já veio por código
        }

        $codigo = $codigos[$id] ?? null;

        if ($codigo === null) {
            return ['tipo' => 'pedidos', 'params' => [], 'url' => $destino['url']];
        }

        return [
            'tipo'   => 'pedido',
            'params' => ['codigo' => $codigo],
            'url'    => $destino['url'],
        ];
    }

    /**
     * Os ids de pedido citados numa lista de notificações.
     *
     * Existe para quem monta a lista poder buscar todos os códigos de uma vez.
     *
     * @param  array<int,array> $rows
     * @return array<int,int>
     */
    public static function idsDePedido(array $rows): array
    {
        $ids = [];

        foreach ($rows as $n) {
            $destino = DestinoPresenter::de($n['url'] ?? null);
            $id      = (int)($destino['params']['pedido_id'] ?? 0);

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private static function data(?string $valor): ?string
    {
        if (!$valor) return null;
        $ts = strtotime($valor);
        return $ts ? date(DATE_ATOM, $ts) : null;
    }
}
