<?php
declare(strict_types=1);

/**
 * app/services/payment/PagamentoNotificador.php
 *
 * Notificações in-app do fluxo de pagamento, para os admins.
 *
 * DUAS NATUREZAS DIFERENTES, e por isso tratamento diferente:
 *
 *   Evento de PEDIDO (aprovado, em análise) — um por pedido, cada um é um
 *   fato distinto que alguém quer ver. Sai sempre.
 *
 *   Evento de SISTEMA (adquirente fora do ar) — o MESMO fato se repete a cada
 *   tentativa. Uma adquirente caída num pico de vendas dispararia dezenas de
 *   broadcasts idênticos e enterraria todo o resto do sino. Por isso tem
 *   janela de silêncio: o primeiro avisa, os seguintes ficam quietos até a
 *   janela fechar.
 *
 * Nada aqui pode derrubar um pagamento: tudo em try/catch, falha vira log.
 */
class PagamentoNotificador
{
    /** Silêncio por adquirente+problema. 15 min: avisa cedo sem virar spam. */
    private const JANELA_SILENCIO_MIN = 15;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /** Pagamento aprovado. */
    public function pedidoAprovado(array $ctx, ?PagamentoClassificacao $c = null): void
    {
        $codigo = (string) ($ctx['order_id_loja'] ?? '');
        if ($codigo === '') return;

        $this->enviar([
            'categoria' => 'pedido',
            'tipo'      => 'pagamento_aprovado',
            'titulo'    => 'Pagamento aprovado — ' . $codigo,
            'mensagem'  => $this->resumo($ctx)
                         . ($c && $c->bandeira ? ' · ' . $c->bandeira : ''),
            'url'       => $this->urlPedido($ctx),
        ]);
    }

    /**
     * Pedido retido para decisão humana. É o mais urgente dos três: tem
     * cliente esperando e mercadoria parada, então a mensagem já traz o
     * motivo — o admin decide se abre agora sem precisar entrar na fila.
     */
    public function pedidoEmAnalise(array $ctx, array $antifraude): void
    {
        $codigo = (string) ($ctx['order_id_loja'] ?? '');
        if ($codigo === '') return;

        $this->enviar([
            'categoria' => 'pedido',
            'tipo'      => 'pedido_em_analise',
            'titulo'    => 'Pedido em análise — ' . $codigo,
            'mensagem'  => $this->resumo($ctx) . ' · '
                         . mb_substr((string) ($antifraude['motivo'] ?? 'Retido pelo antifraude.'), 0, 150),
            'url'       => BASE_URL . '/admin/pagamentos/analise',
        ]);
    }

    /**
     * Adquirente com erro técnico ou fora do ar.
     *
     * Só o PRIMEIRO de cada combinação adquirente+problema sai dentro da
     * janela. O log continua registrando todas as ocorrências — quem quer o
     * número exato olha o dashboard, não o sino.
     */
    public function adquirenteComProblema(string $adquirente, PagamentoClassificacao $c, array $ctx = []): void
    {
        $tipo = 'adquirente_problema:' . $adquirente . ':' . $c->classeErro;

        if ($this->silenciado($tipo)) return;

        $foraDoAr = $c->porta === PagamentoClassificacao::INDISPONIVEL;

        $this->enviar([
            'categoria' => 'sistema',
            'tipo'      => $tipo,
            'titulo'    => $foraDoAr
                ? 'Adquirente fora do ar — ' . $adquirente
                : 'Erro técnico na adquirente — ' . $adquirente,
            'mensagem'  => trim(
                ($c->mensagemAdquirente ?? $c->classeErro)
                // So mostra o status quando ele explica algo: HTTP 200 numa
                // indisponibilidade confunde mais do que informa.
                . ($c->httpStatus && $c->httpStatus >= 400 ? ' (HTTP ' . $c->httpStatus . ')' : '')
                . ' · Pedido ' . ($ctx['order_id_loja'] ?? '?')
                . ' · Novos avisos deste tipo ficam silenciados por '
                . self::JANELA_SILENCIO_MIN . ' min.'
            ),
            'url'       => BASE_URL . '/admin/payment',
        ]);
    }

    // =========================================================================

    /**
     * Já saiu uma notificação deste mesmo tipo dentro da janela?
     *
     * Usa a própria tabela de notificações como memória — não precisa de
     * tabela de controle nem de cache externo, e sobrevive a reinício.
     */
    private function silenciado(string $tipo): bool
    {
        try {
            $st = $this->db->prepare(
                "SELECT 1 FROM notificacoes
                  WHERE tipo = ?
                    AND criado_em >= (NOW() - INTERVAL ? MINUTE)
                  LIMIT 1"
            );
            $st->execute([$tipo, self::JANELA_SILENCIO_MIN]);
            return (bool) $st->fetchColumn();
        } catch (\Throwable) {
            // Na dúvida, notifica. Perder um alerta é pior do que repetir um.
            return false;
        }
    }

    private function enviar(array $dados): void
    {
        try {
            NotificacaoService::criarBroadcast($dados, 'todos_admins');
        } catch (\Throwable $e) {
            LogService::exception($e, 'warning', 'pagamento', [
                'acao' => 'notificar_admins',
                'tipo' => $dados['tipo'] ?? null,
            ]);
        }
    }

    private function resumo(array $ctx): string
    {
        $valor = (int) ($ctx['valor_centavos'] ?? 0);
        $parc  = max(1, (int) ($ctx['parcelas'] ?? 1));
        $txt   = 'R$ ' . number_format($valor / 100, 2, ',', '.');
        if ($parc > 1) $txt .= ' em ' . $parc . 'x';
        if (!empty($ctx['metodo'])) $txt .= ' · ' . str_replace('_', ' ', (string) $ctx['metodo']);
        return $txt;
    }

    private function urlPedido(array $ctx): string
    {
        $id = (int) ($ctx['pedido_id'] ?? 0);
        return $id > 0
            ? BASE_URL . '/admin/pedidos/' . $id
            : BASE_URL . '/admin/payment/transacoes';
    }
}
