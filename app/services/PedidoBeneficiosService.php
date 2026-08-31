<?php
declare(strict_types=1);

/**
 * app/services/PedidoBeneficiosService.php
 *
 * Tudo que abateu do pedido, num lugar só.
 *
 * POR QUE EXISTE: o desconto de um pedido pode vir de quatro origens
 * independentes — cupom (`cupom_usos`), promoção automática
 * (`promocao_aplicacoes`), crédito da carteira (`cliente_saldo_transacoes`) e
 * frete grátis. A tela mostrava uma linha só, "Desconto", com o código do
 * cupom entre parênteses quando havia um. Quem ganhou R$ 40 de promoção e
 * usou R$ 15 de crédito via "Desconto R$ 55" e não tinha como conferir nada.
 *
 * Aqui cada origem vira uma linha nomeada, com o valor dela.
 */
class PedidoBeneficiosService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /**
     * Linhas de benefício do pedido, prontas para exibição.
     *
     * @return array{
     *   linhas: list<array{tipo:string, rotulo:string, detalhe:?string, valor:float}>,
     *   brindes: list<array{nome:string, quantidade:int}>,
     *   cashback: float,
     *   total: float,
     *   divergencia: ?float
     * }
     */
    public function doPedido(int $pedidoId, array $pedido = []): array
    {
        $linhas   = [];
        $brindes  = [];
        $cashback = 0.0;

        [$cup, $freteDe] = $this->cupons($pedidoId);
        foreach ($cup as $l) $linhas[] = $l;

        [$promo, $brindes, $cashback, $fretePromo] = $this->promocoes($pedidoId);
        foreach ($promo as $l) $linhas[] = $l;

        $freteDe = $freteDe ?? $fretePromo;

        foreach ($this->credito($pedidoId) as $l) $linhas[] = $l;

        // FRETE GRATIS NAO SOMA NO DESCONTO.
        //
        // Ele abate o FRETE, que ja entra como 0 no total — somar de novo
        // faria a conta estourar. Aqui a linha existe so para o cliente ver
        // que ganhou, com valor zero. Quando a origem e conhecida (cupom ou
        // promocao), o rotulo diz qual.
        if (isset($pedido['frete']) && (float) $pedido['frete'] === 0.0) {
            $linhas[] = [
                'tipo'    => 'frete',
                'rotulo'  => 'Frete grátis',
                'detalhe' => $freteDe ?? ($pedido['frete_descricao'] ?? null),
                'valor'   => 0.0,
            ];
        }

        $total = 0.0;
        foreach ($linhas as $l) $total += $l['valor'];

        return [
            'linhas'      => $linhas,
            'brindes'     => $brindes,
            'cashback'    => $cashback,
            'total'       => round($total, 2),
            'divergencia' => $this->divergencia($linhas, $pedido),
        ];
    }

    /**
     * O detalhado bate com o total gravado no pedido?
     *
     * Uma origem que a tela não conhece apareceria como diferença — e é
     * melhor mostrar "outros descontos" do que somar errado na cara do
     * cliente. Devolve null quando fecha.
     */
    private function divergencia(array $linhas, array $pedido): ?float
    {
        if (!isset($pedido['desconto'])) return null;

        $gravado = (float) $pedido['desconto'] + (float) ($pedido['credito_utilizado'] ?? 0);

        $somado = 0.0;
        foreach ($linhas as $l) $somado += $l['valor'];

        $dif = round($gravado - $somado, 2);

        return abs($dif) >= 0.01 ? $dif : null;
    }

    /**
     * @return array{0:list<array{tipo:string,rotulo:string,detalhe:?string,valor:float}>, 1:?string}
     *         segundo elemento: quem deu o frete grátis, quando foi um cupom
     */
    private function cupons(int $pedidoId): array
    {
        $st = $this->db->prepare(
            "SELECT c.codigo, c.nome, c.tipo,
                    u.valor_desconto, u.valor_frete_desc
               FROM cupom_usos u
               JOIN cupons c ON c.id = u.cupom_id
              WHERE u.pedido_id = :p
                AND u.status IN ('aplicado', 'confirmado')
              ORDER BY u.id ASC"
        );
        $st->execute([':p' => $pedidoId]);

        $linhas  = [];
        $freteDe = null;

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            // SO O DESCONTO DE PRODUTO ENTRA NA SOMA. O de frete abate outra
            // coisa, e contar os dois juntos era o que fazia o detalhado nao
            // fechar com o `pedidos.desconto` gravado.
            $valor = (float) $c['valor_desconto'];

            if ((float) $c['valor_frete_desc'] > 0 || $c['tipo'] === 'frete_gratis') {
                $freteDe = 'Cupom ' . $c['codigo'];
            }

            if ($valor <= 0) continue;

            $linhas[] = [
                'tipo'    => 'cupom',
                'rotulo'  => 'Cupom ' . $c['codigo'],
                'detalhe' => $c['nome'] ?: null,
                'valor'   => round($valor, 2),
            ];
        }

        return [$linhas, $freteDe];
    }

    /** @return array{0:list<array>, 1:list<array>, 2:float, 3:?string} */
    private function promocoes(int $pedidoId): array
    {
        $st = $this->db->prepare(
            "SELECT a.tipo_beneficio, a.valor_desconto, a.qtd_brinde, a.detalhes,
                    a.produto_brinde_id, p.nome, p.tipo
               FROM promocao_aplicacoes a
               JOIN promocoes p ON p.id = a.promocao_id
              WHERE a.pedido_id = :p
              ORDER BY a.id ASC"
        );
        $st->execute([':p' => $pedidoId]);

        $linhas   = [];
        $brindes  = [];
        $cashback = 0.0;
        $freteDe  = null;

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $det = json_decode((string) $a['detalhes'], true) ?: [];

            // Cashback NÃO abate deste pedido: vira crédito depois. Somar
            // aqui faria o total não fechar e prometeria um desconto que a
            // pessoa não teve agora.
            if ($a['tipo_beneficio'] === 'cashback') {
                $cashback += (float) ($det['cashback_valor'] ?? 0);
                continue;
            }

            if ($a['tipo_beneficio'] === 'brinde') {
                foreach (($det['brindes'] ?? []) as $b) {
                    $brindes[] = [
                        'nome'       => (string) ($b['nome'] ?? $this->nomeProduto((int) ($b['produto_id'] ?? 0))),
                        'quantidade' => (int) ($b['quantidade'] ?? 1),
                    ];
                }
                if (!$brindes && $a['produto_brinde_id']) {
                    $brindes[] = [
                        'nome'       => $this->nomeProduto((int) $a['produto_brinde_id']),
                        'quantidade' => max(1, (int) $a['qtd_brinde']),
                    ];
                }
            }

            if ($a['tipo_beneficio'] === 'frete_gratis') {
                $freteDe = (string) $a['nome'];
                continue;
            }

            // O valor gravado soma produto + frete; so a parte de produto
            // abate o total, pelo mesmo motivo do cupom.
            $valor = (float) ($det['desconto_produto'] ?? $a['valor_desconto']);
            if ((float) ($det['desconto_frete'] ?? 0) > 0) $freteDe = (string) $a['nome'];

            if ($valor <= 0 && $a['tipo_beneficio'] !== 'brinde') continue;

            $linhas[] = [
                'tipo'    => 'promocao',
                'rotulo'  => (string) $a['nome'],
                'detalhe' => self::rotuloTipo((string) $a['tipo_beneficio']),
                'valor'   => round($valor, 2),
            ];
        }

        return [$linhas, $brindes, round($cashback, 2), $freteDe];
    }

    /** @return list<array{tipo:string,rotulo:string,detalhe:?string,valor:float}> */
    private function credito(int $pedidoId): array
    {
        $st = $this->db->prepare(
            "SELECT valor, descricao
               FROM cliente_saldo_transacoes
              WHERE referencia_tipo = 'pedido' AND referencia_id = :p
                AND tipo = 'debito_compra'
              ORDER BY id ASC"
        );
        $st->execute([':p' => $pedidoId]);

        $linhas = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            // Débito é gravado negativo; aqui o que interessa é o abatimento.
            $valor = abs((float) $t['valor']);
            if ($valor <= 0) continue;

            $linhas[] = [
                'tipo'    => 'credito',
                'rotulo'  => 'Crédito da carteira',
                'detalhe' => $t['descricao'] ?: null,
                'valor'   => round($valor, 2),
            ];
        }

        return $linhas;
    }

    private function nomeProduto(int $id): string
    {
        if ($id <= 0) return 'Brinde';
        $st = $this->db->prepare('SELECT nome FROM produtos WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        return (string) ($st->fetchColumn() ?: 'Brinde');
    }

    private static function rotuloTipo(string $t): ?string
    {
        return [
            'desconto'     => 'Promoção automática',
            'frete_gratis' => 'Frete grátis',
            'brinde'       => 'Brinde',
        ][$t] ?? null;
    }
}
