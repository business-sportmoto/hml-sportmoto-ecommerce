<?php
/**
 * app/services/ChatCupomCarrinhoService.php
 *
 * Ofereceu-se pelo direct, clicou, colocou no carrinho e não comprou.
 * Passadas N horas (`recuperacao_config.chat_cupom_h`, 20 por padrão), manda um
 * cupom — se existir um que sirva.
 *
 * POR QUE AQUI E NÃO NO AGENTE: dar cupom a quem pergunta preço desconta também
 * quem compraria pelo preço cheio. Aqui o desconto vai só para quem já mostrou
 * intenção E não converteu — que é o momento em que ele muda alguma coisa.
 *
 * Plugado no que já existe: a detecção de abandono é do CarrinhoRecuperacaoService
 * e o evento entra na trilha do carrinho (`carrinho_recuperacao_eventos`), ao
 * lado de e-mail e WhatsApp. Nada aqui reimplementa recuperação de carrinho.
 */
class ChatCupomCarrinhoService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // =========================================================================
    // CUPOM APLICÁVEL
    // =========================================================================

    /**
     * O cupom que pode ser oferecido para este produto, ou null.
     *
     * `divulgavel = 1` é opt-in e não se discute — mas as condições abaixo
     * valem mesmo assim, como rede de segurança para quem marcou a caixa sem
     * perceber o que o cupom era:
     *
     *   escopo_clientes preenchido  → é de alguém específico
     *   apenas_primeira_compra      → o agente não sabe se a pessoa já comprou
     *   vendedor_id                 → é a comissão de um vendedor
     *   tipo exclusivo/1ª compra/   → não são de distribuição
     *        recuperacao_carrinho
     *   limite_total esgotado       → oferecer o que acabou é frustrar
     */
    public function cupomParaProduto(int $produtoId, float $valorCarrinho = 0): ?array
    {
        $st = $this->db->prepare(
            "SELECT id, codigo, nome, descricao, tipo, valor, valor_maximo,
                    valor_minimo_pedido, data_fim, escopo_produtos, escopo_categorias,
                    escopo_marcas, limite_por_cliente, permite_produto_promo
             FROM cupons
             WHERE ativo = 1
               AND divulgavel = 1
               AND deleted_at IS NULL
               AND (data_inicio IS NULL OR data_inicio <= NOW())
               AND (data_fim    IS NULL OR data_fim    >= NOW())
               AND (escopo_clientes IS NULL OR escopo_clientes = '' OR escopo_clientes = '[]')
               AND apenas_primeira_compra = 0
               AND vendedor_id IS NULL
               AND tipo NOT IN ('exclusivo','primeira_compra','recuperacao_carrinho')
               AND (limite_total IS NULL OR total_usos < limite_total)
             ORDER BY valor DESC"
        );
        $st->execute();

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (!$this->serveNoProduto($c, $produtoId))            continue;
            // Oferecer 10% num carrinho abaixo do mínimo do cupom é prometer o
            // que não vai aplicar no checkout
            if ($valorCarrinho > 0 && $c['valor_minimo_pedido'] !== null
                && $valorCarrinho < (float)$c['valor_minimo_pedido']) continue;

            return $c;
        }
        return null;
    }

    /**
     * O escopo do cupom alcança este produto?
     * Escopo vazio = vale para a loja toda.
     */
    private function serveNoProduto(array $cupom, int $produtoId): bool
    {
        $prods = $this->lista($cupom['escopo_produtos']);
        if ($prods !== []) return in_array($produtoId, $prods, true);

        $cats   = $this->lista($cupom['escopo_categorias']);
        $marcas = $this->lista($cupom['escopo_marcas']);
        if ($cats === [] && $marcas === []) return true;   // loja toda

        if ($cats !== [] && $this->produtoEmCategorias($produtoId, $cats)) return true;
        if ($marcas !== [] && $this->produtoEmMarcas($produtoId, $marcas)) return true;

        return false;
    }

    private function lista($json): array
    {
        if ($json === null || $json === '') return [];
        $a = json_decode((string)$json, true);
        return is_array($a) ? array_values(array_filter(array_map('intval', $a))) : [];
    }

    private function produtoEmCategorias(int $produtoId, array $cats): bool
    {
        $in = implode(',', array_map('intval', $cats));
        $st = $this->db->prepare(
            "SELECT 1 FROM produto_categorias
             WHERE produto_id = :p AND categoria_id IN ($in) LIMIT 1"
        );
        $st->execute([':p' => $produtoId]);
        return (bool)$st->fetchColumn();
    }

    private function produtoEmMarcas(int $produtoId, array $marcas): bool
    {
        $in = implode(',', array_map('intval', $marcas));
        $st = $this->db->prepare("SELECT 1 FROM produtos WHERE id = :p AND marca_id IN ($in) LIMIT 1");
        $st->execute([':p' => $produtoId]);
        return (bool)$st->fetchColumn();
    }

    /** O cupom em palavras, com as regras que o cliente precisa saber. */
    public function descreverCupom(array $c): string
    {
        $desconto = match ($c['tipo']) {
            'percentual'   => rtrim(rtrim(number_format((float)$c['valor'], 2, ',', '.'), '0'), ',') . '% de desconto',
            'fixo'         => 'R$ ' . number_format((float)$c['valor'], 2, ',', '.') . ' de desconto',
            'frete_gratis' => 'frete grátis',
            default        => 'desconto',
        };

        $regras = [];
        if ($c['valor_minimo_pedido'] !== null && (float)$c['valor_minimo_pedido'] > 0) {
            $regras[] = 'em compras a partir de R$ ' . number_format((float)$c['valor_minimo_pedido'], 2, ',', '.');
        }
        if (!empty($c['valor_maximo']) && (float)$c['valor_maximo'] > 0) {
            $regras[] = 'desconto máximo de R$ ' . number_format((float)$c['valor_maximo'], 2, ',', '.');
        }
        if (!empty($c['data_fim'])) {
            $regras[] = 'válido até ' . date('d/m/Y', strtotime((string)$c['data_fim']));
        }

        return $desconto . ($regras ? ' — ' . implode(' · ', $regras) : '');
    }

    // =========================================================================
    // A RÉGUA
    // =========================================================================

    /**
     * Carrinhos abandonados há N horas, cujo dono veio de um link do direct.
     *
     * Duas formas de amarrar o carrinho ao contato do Instagram:
     *   1. sessao_id — o par gravado em chat_visitas no clique do link
     *   2. cliente_id — se a pessoa logou depois, a sessão mudou, mas o
     *      carrinho é dela; o contato do chat guarda cliente_id quando vinculado
     * A primeira pega a maioria; a segunda cobre quem logou no meio do caminho.
     *
     * @return int quantos cupons foram enviados
     */
    public function enviarPendentes(int $limite = 30): int
    {
        $horas = $this->horasConfig();
        if ($horas <= 0) return 0;

        // Subconsulta em vez de JOIN + GROUP BY: além de o only_full_group_by
        // recusar o segundo, o agrupamento escondia uma decisão de verdade —
        // se duas pessoas clicaram da MESMA sessão (computador compartilhado),
        // quem recebe? O clique mais recente, que é quem estava navegando.
        $st = $this->db->prepare(
            "SELECT r.id AS recuperacao_id, r.carrinho_id, r.valor_snapshot,
                    (SELECT v.contato_id FROM chat_visitas v
                     WHERE v.sessao_id = c.sessao_id
                     ORDER BY v.criado_em DESC, v.id DESC LIMIT 1) AS contato_id
             FROM carrinho_recuperacao r
             JOIN carrinhos c ON c.id = r.carrinho_id
             WHERE r.status IN ('novo','abandonado','em_recuperacao')
               AND r.abandonado_em <= DATE_SUB(NOW(), INTERVAL :h HOUR)
               AND r.abandonado_em >  DATE_SUB(NOW(), INTERVAL 6 DAY)
               AND EXISTS (
                     SELECT 1 FROM chat_visitas v2 WHERE v2.sessao_id = c.sessao_id
                   )
               AND NOT EXISTS (
                     SELECT 1 FROM carrinho_recuperacao_eventos e
                     WHERE e.recuperacao_id = r.id AND e.tipo = 'chat_cupom_enviado'
                   )
             ORDER BY r.abandonado_em ASC
             LIMIT :lim"
        );
        $st->bindValue(':h', $horas, PDO::PARAM_INT);
        $st->bindValue(':lim', max(1, min(200, $limite)), PDO::PARAM_INT);
        $st->execute();

        $enviados = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            if ($this->enviarPara($linha)) $enviados++;
        }
        return $enviados;
    }

    /**
     * O teto de 6 dias não é estético: fora dos 7 dias da tag de atendimento
     * humano o Instagram recusa a mensagem, e insistir gastaria chamada à toa.
     */
    private function horasConfig(): int
    {
        try {
            $v = $this->db->query(
                "SELECT valor FROM recuperacao_config WHERE chave = 'chat_cupom_h' LIMIT 1"
            )->fetchColumn();
            return $v === false ? 20 : (int)round((float)$v);
        } catch (Throwable $e) {
            return 20;
        }
    }

    private function enviarPara(array $linha): bool
    {
        $contatoId = (int)$linha['contato_id'];
        $recId     = (int)$linha['recuperacao_id'];
        $valor     = (float)($linha['valor_snapshot'] ?? 0);

        $produtoId = $this->produtoPrincipal((int)$linha['carrinho_id']);
        if ($produtoId < 1) return false;

        $cupom = $this->cupomParaProduto($produtoId, $valor);
        if (!$cupom) {
            // Sem cupom que sirva não há o que oferecer. Marcar o evento evita
            // reprocessar o mesmo carrinho a cada rodada do worker.
            $this->registrarEvento($recId, null, 'nenhum cupom aplicável');
            return false;
        }

        $texto = "Vi que você deixou algo no carrinho 👀\n\n"
               . "Separei um cupom: *{$cupom['codigo']}* — " . $this->descreverCupom($cupom) . ".\n\n"
               . 'É só aplicar no carrinho antes de finalizar.';

        $r = (new ChatEnvioService($this->db))->texto($contatoId, $texto, [
            'origem'    => 'carrinho_cupom',
            'origem_id' => $recId,
        ]);

        if (empty($r['ok'])) {
            // Janela fechada ou opt-out: não marca evento, para tentar de novo
            // na próxima rodada enquanto couber nos 6 dias.
            return false;
        }

        $this->registrarEvento($recId, (int)$cupom['id'], 'cupom ' . $cupom['codigo'] . ' enviado no direct');
        return true;
    }

    /** O item mais caro do carrinho — é o que decide a oferta. */
    private function produtoPrincipal(int $carrinhoId): int
    {
        try {
            $st = $this->db->prepare(
                "SELECT produto_id FROM carrinho_itens
                 WHERE carrinho_id = :c AND produto_id IS NOT NULL
                 ORDER BY (preco_unitario * quantidade) DESC LIMIT 1"
            );
            $st->execute([':c' => $carrinhoId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function registrarEvento(int $recId, ?int $cupomId, string $descricao): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO carrinho_recuperacao_eventos (recuperacao_id, tipo, descricao, meta, criado_em)
                 VALUES (:r, 'chat_cupom_enviado', :d, :m, NOW())"
            )->execute([
                ':r' => $recId,
                ':d' => mb_substr($descricao, 0, 255),
                ':m' => json_encode(['cupom_id' => $cupomId], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {}
    }
}
