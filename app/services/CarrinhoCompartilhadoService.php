<?php
declare(strict_types=1);

// app/services/CarrinhoCompartilhadoService.php
//
// Carrinho compartilhado: gerar o link, abrir o link, copiar para o carrinho
// de quem abriu.
//
// Existe como service porque a loja e o app precisam do MESMO comportamento —
// mesma validade, mesmo tratamento de item indisponível, mesma contabilização
// em carrinhos_compartilhados_uso. Duplicar isso seria duplicar a chance de
// dois clientes verem carrinhos diferentes pelo mesmo link.
//
// ── Uma correção que veio junto ──────────────────────────────────────────────
// O snapshot da loja gravava `opcoes` e `sku` lendo `$item['opcoes_snapshot']`
// e `$item['sku_legado']` — dois campos que Cart::getItems() NUNCA devolve
// (as colunas reais são carrinho_itens.opcoes_selecionadas e ci.sku_id). Os
// dois saíam sempre null, e a cópia inseria em carrinho_itens sem sku_id
// nenhum: compartilhar um produto com variação entregava do outro lado o
// produto base — outro tamanho, outra cor, outro preço.
// Aqui o snapshot leva sku_id e a cópia o restaura.

final class CarrinhoCompartilhadoService
{
    /** Validade do link. Mesmo prazo que a loja já praticava. */
    private const DIAS_VALIDADE = 7;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /* =================================================================
       CRIAR
       ================================================================= */

    /**
     * Congela o carrinho num link.
     *
     * O snapshot é uma FOTO: preço e disponibilidade são reconferidos só na
     * cópia. É o que permite o link sobreviver a uma mudança de preço sem
     * cobrar do destinatário um valor que não existe mais.
     *
     * @param int|null $usuarioId  usuarios.id de quem compartilha (não cliente_id).
     * @return array{ok:bool,erro?:string,token?:string,url?:string,expira_em?:string}
     */
    public function criar(int $carrinhoId, ?int $usuarioId, ?string $nomeVisitante = null): array
    {
        $cart   = new Cart();
        $itens  = $cart->getItems($carrinhoId);

        if (empty($itens)) {
            return ['ok' => false, 'erro' => 'Carrinho vazio.'];
        }

        $totais = $cart->getTotals($carrinhoId);

        $snapshot = array_map(static function (array $i): array {
            return [
                'produto_id' => (int)$i['produto_id'],
                // sku_id é o que faz a variação sobreviver ao link.
                'sku_id'     => !empty($i['sku_id']) ? (int)$i['sku_id'] : null,
                'nome'       => $i['nome_produto'] ?? 'Produto',
                'slug'       => $i['produto_slug'] ?? '',
                // imagem_url já vem absoluta e considera a imagem do SKU;
                // `imagem` é só o arquivo da capa do produto base.
                'imagem'     => $i['imagem_url'] ?? $i['imagem'] ?? null,
                'quantidade' => (int)$i['quantidade'],
                'preco'      => (float)($i['preco_unitario'] ?? 0),
                'subtotal'   => (float)($i['subtotal'] ?? 0),
                'sku'        => $i['sku_codigo'] ?? null,
                // Atributos já montados por getItems() — servem para exibir
                // "Tamanho M · Preto" na tela de quem abre o link.
                'opcoes'     => $i['atributos'] ?? $i['opcoes'] ?? null,
            ];
        }, $itens);

        $vendedor = $this->vendedorDoCarrinho($carrinhoId);
        $token    = bin2hex(random_bytes(12));
        $expiraEm = date('Y-m-d H:i:s', time() + (86400 * self::DIAS_VALIDADE));

        $this->db->prepare(
            "INSERT INTO carrinhos_compartilhados
             (token, carrinho_id, itens_snapshot, subtotal, desconto, total,
              vendedor_codigo, vendedor_nome, usuario_id, nome_visitante, expira_em)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $token,
            $carrinhoId,
            json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            (float)($totais['subtotal'] ?? 0),
            (float)($totais['desconto'] ?? 0),
            (float)($totais['total']    ?? 0),
            $vendedor['codigo'],
            $vendedor['nome'],
            $usuarioId,
            $usuarioId ? null : ($nomeVisitante ?: null),
            $expiraEm,
        ]);

        return [
            'ok'        => true,
            'token'     => $token,
            'url'       => rtrim(BASE_URL, '/') . '/carrinho/compartilhado/' . $token,
            'expira_em' => $expiraEm,
        ];
    }

    /* =================================================================
       ABRIR
       ================================================================= */

    /**
     * Lê um link válido. Devolve null quando não existe ou já expirou — os
     * dois casos são a mesma coisa para quem abriu, e distingui-los só
     * confirmaria a existência do token para quem estiver adivinhando.
     */
    public function abrir(string $token): ?array
    {
        $token = $this->limparToken($token);
        if ($token === '') {
            return null;
        }

        $st = $this->db->prepare(
            "SELECT cc.*, u.nome AS usuario_nome
             FROM carrinhos_compartilhados cc
             LEFT JOIN usuarios u ON u.id = cc.usuario_id
             WHERE cc.token = ? AND cc.expira_em > NOW()
             LIMIT 1"
        );
        $st->execute([$token]);
        $row = $st->fetch();

        if (!$row) {
            return null;
        }

        $row['itens'] = json_decode((string)$row['itens_snapshot'], true) ?: [];
        $row['compartilhado_por'] = $row['usuario_nome']
            ?: ($row['nome_visitante'] ?: 'Um cliente');

        return $row;
    }

    /** Contador de visualizações + linha no log de uso. */
    public function registrarVisualizacao(string $token, ?int $clienteId, ?string $ip): void
    {
        $token = $this->limparToken($token);
        if ($token === '') return;

        try {
            $this->db->prepare(
                "UPDATE carrinhos_compartilhados
                 SET visualizacoes = visualizacoes + 1 WHERE token = ?"
            )->execute([$token]);

            (new CartCompartilhado())->registrarUso($token, 'visualizou', $clienteId, null, $ip);
        } catch (\Throwable $e) {
            // Analytics não derruba a leitura do carrinho.
        }
    }

    /* =================================================================
       COPIAR
       ================================================================= */

    /**
     * Copia o snapshot para o carrinho de quem abriu o link.
     *
     * Preço e estoque são os DE AGORA, não os do snapshot: o link vale 7 dias
     * e ninguém deve conseguir congelar um preço promocional guardando a URL.
     *
     * @param string $estrategia 'mesclar' soma quantidades ao que já existe;
     *                           'substituir' esvazia o carrinho antes.
     * @return array{ok:bool,erro?:string,adicionados:int,ignorados:int,itens_ignorados:array}
     */
    public function copiar(
        string $token,
        int    $carrinhoDestinoId,
        string $estrategia = 'mesclar',
        ?int   $clienteId  = null,
        ?string $ip        = null
    ): array {
        $compartilhado = $this->abrir($token);

        if (!$compartilhado) {
            return ['ok' => false, 'erro' => 'Link expirado ou inválido.', 'adicionados' => 0, 'ignorados' => 0, 'itens_ignorados' => []];
        }

        $itens = $compartilhado['itens'];
        if (empty($itens)) {
            return ['ok' => false, 'erro' => 'Carrinho compartilhado vazio.', 'adicionados' => 0, 'ignorados' => 0, 'itens_ignorados' => []];
        }

        $estrategia = $estrategia === 'substituir' ? 'substituir' : 'mesclar';

        if ($estrategia === 'substituir') {
            $this->db->prepare("DELETE FROM carrinho_itens WHERE carrinho_id = ?")
                     ->execute([$carrinhoDestinoId]);
        }

        $adicionados = 0;
        $ignorados   = [];

        foreach ($itens as $item) {
            $produtoId = (int)($item['produto_id'] ?? 0);
            if ($produtoId <= 0) continue;

            $atual = $this->produtoAtual($produtoId, isset($item['sku_id']) ? (int)$item['sku_id'] : null);

            if (!$atual) {
                $ignorados[] = ['nome' => $item['nome'] ?? 'Produto', 'motivo' => 'indisponivel'];
                continue;
            }

            $quantidade = min(
                max(1, (int)($item['quantidade'] ?? 1)),
                $atual['estoque']
            );

            if ($quantidade <= 0) {
                $ignorados[] = ['nome' => $item['nome'] ?? 'Produto', 'motivo' => 'sem_estoque'];
                continue;
            }

            $this->inserirOuSomar($carrinhoDestinoId, $produtoId, $atual, $quantidade);
            $adicionados++;
        }

        if (!empty($compartilhado['vendedor_codigo'])) {
            $this->db->prepare("UPDATE carrinhos SET codigo_vendedor = ? WHERE id = ?")
                     ->execute([$compartilhado['vendedor_codigo'], $carrinhoDestinoId]);
        }

        // Amarra o token ao carrinho para atribuir o pedido a quem compartilhou.
        // COALESCE preserva a primeira atribuição: dois links copiados no mesmo
        // carrinho não roubam o crédito um do outro.
        $this->db->prepare(
            "UPDATE carrinhos
             SET compartilhado_token = COALESCE(compartilhado_token, ?)
             WHERE id = ?"
        )->execute([$this->limparToken($token), $carrinhoDestinoId]);

        try {
            (new CartCompartilhado())->registrarUso(
                $this->limparToken($token), 'criou_carrinho', $clienteId, null, $ip
            );
        } catch (\Throwable $e) { /* analytics */ }

        return [
            'ok'              => true,
            'adicionados'     => $adicionados,
            'ignorados'       => count($ignorados),
            'itens_ignorados' => $ignorados,
        ];
    }

    /* =================================================================
       INTERNOS
       ================================================================= */

    /**
     * Preço e estoque de agora. Quando o item veio com variação, o SKU manda —
     * preço e estoque dele, não os do produto base.
     *
     * @return array{sku_id:?int,preco:float,estoque:int}|null
     */
    private function produtoAtual(int $produtoId, ?int $skuId): ?array
    {
        $st = $this->db->prepare(
            "SELECT id, preco, preco_promo, estoque_total
             FROM produtos
             WHERE id = ? AND ativo = 1 AND deleted_at IS NULL
             LIMIT 1"
        );
        $st->execute([$produtoId]);
        $produto = $st->fetch();

        if (!$produto) {
            return null;
        }

        if ($skuId) {
            $stSku = $this->db->prepare(
                "SELECT id, preco, preco_promo, estoque
                 FROM produto_skus
                 WHERE id = ? AND produto_id = ? AND ativo = 1
                 LIMIT 1"
            );
            $stSku->execute([$skuId, $produtoId]);
            $sku = $stSku->fetch();

            // SKU sumiu (variação descontinuada): o produto base não é
            // substituto — seria entregar outro item. Melhor ignorar.
            if (!$sku) {
                return null;
            }

            return [
                'sku_id'  => (int)$sku['id'],
                'preco'   => (float)($sku['preco_promo'] ?: $sku['preco'] ?: $produto['preco_promo'] ?: $produto['preco']),
                'estoque' => (int)$sku['estoque'],
            ];
        }

        return [
            'sku_id'  => null,
            'preco'   => (float)($produto['preco_promo'] ?: $produto['preco']),
            'estoque' => (int)$produto['estoque_total'],
        ];
    }

    /** @param array{sku_id:?int,preco:float,estoque:int} $atual */
    private function inserirOuSomar(int $carrinhoId, int $produtoId, array $atual, int $quantidade): void
    {
        // A linha existente é a do MESMO sku: dois tamanhos do mesmo produto
        // são itens distintos e não devem somar quantidade.
        if ($atual['sku_id'] !== null) {
            $st = $this->db->prepare(
                "SELECT id, quantidade FROM carrinho_itens
                 WHERE carrinho_id = ? AND produto_id = ? AND sku_id = ? LIMIT 1"
            );
            $st->execute([$carrinhoId, $produtoId, $atual['sku_id']]);
        } else {
            $st = $this->db->prepare(
                "SELECT id, quantidade FROM carrinho_itens
                 WHERE carrinho_id = ? AND produto_id = ? AND sku_id IS NULL LIMIT 1"
            );
            $st->execute([$carrinhoId, $produtoId]);
        }

        $existe = $st->fetch();

        if ($existe) {
            $nova = min((int)$existe['quantidade'] + $quantidade, $atual['estoque']);
            $this->db->prepare("UPDATE carrinho_itens SET quantidade = ? WHERE id = ?")
                     ->execute([$nova, $existe['id']]);
            return;
        }

        $this->db->prepare(
            "INSERT INTO carrinho_itens (carrinho_id, produto_id, sku_id, quantidade, preco_unitario)
             VALUES (?,?,?,?,?)"
        )->execute([$carrinhoId, $produtoId, $atual['sku_id'], $quantidade, $atual['preco']]);
    }

    /** @return array{codigo:?string,nome:?string} */
    private function vendedorDoCarrinho(int $carrinhoId): array
    {
        try {
            $st = $this->db->prepare(
                "SELECT c.codigo_vendedor, u.nome AS vendedor_nome
                 FROM carrinhos c
                 LEFT JOIN vendedores v ON v.codigo = c.codigo_vendedor AND v.ativo = 1
                 LEFT JOIN usuarios u   ON u.id     = v.usuario_id
                 WHERE c.id = ? LIMIT 1"
            );
            $st->execute([$carrinhoId]);
            $row = $st->fetch();

            $codigo = $row['codigo_vendedor'] ?? null;

            return [
                'codigo' => $codigo ?: null,
                'nome'   => ($row['vendedor_nome'] ?? null) ?: ($codigo ?: null),
            ];
        } catch (\Throwable $e) {
            return ['codigo' => null, 'nome' => null];
        }
    }

    /** Token é hex; qualquer outro caractere é ruído ou tentativa. */
    private function limparToken(string $token): string
    {
        return (string)preg_replace('/[^a-f0-9]/i', '', $token);
    }
}
