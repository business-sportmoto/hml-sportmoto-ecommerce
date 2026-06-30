<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/PromocaoService.php
//
// Engine de avaliação de promoções automáticas.
// Toda regra de negócio fica aqui — model e controller
// só delegam.
//
// Fluxo principal:
//   avaliarCarrinho() → lista de ResultadoPromocao
//   aplicar()         → persiste no pedido confirmado
// ════════════════════════════════════════════════════════

class PromocaoService {

    private Promocao $model;
    private PDO      $db;

    public function __construct() {
        $this->model = new Promocao();
        $this->db    = Database::getInstance()->getConnection();
    }

    // ══════════════════════════════════════════════════
    // PREVIEW — chamado pelo carrinho para exibir ao cliente
    // ══════════════════════════════════════════════════

    /**
     * Gera cards de preview para exibição no carrinho.
     * Diferente de avaliarCarrinho(): retorna tanto promoções
     * já aplicadas quanto as "disponíveis" (itens elegíveis
     * existem, mas quantidade ainda não atingiu nenhuma faixa)
     * e sempre inclui a próxima faixa quando há uma acima.
     *
     * Cada card tem:
     *   estado: 'aplicada' | 'proxima_faixa' | 'disponivel'
     *   progresso_pct: 0-100 para a barra de progresso
     *   msg: texto motivacional principal
     */
    public function previewCarrinho(
        array  $itens,
        float  $subtotal,
        float  $frete,
        ?int   $clienteId = null,
        array  $contexto  = []
    ): array {
        // Garante que os itens têm marca_id e categoria_id preenchidos.
        // getItensParaCupom() retorna 0 para esses campos quando
        // getItensComVariacoes() não faz JOIN com produtos — sem isso,
        // qualquer escopo de marca/categoria exclui todos os itens.
        $itens     = $this->enriquecerItens($itens);
        $promocoes = $this->model->getAtivasAgora();
        $cards     = [];

        foreach ($promocoes as $promo) {
            // Valida restrições de data/hora e audiência
            if (!$this->validarDiaHora($promo))                         continue;
            if (!$this->validarAudiencia($promo, $clienteId, $contexto)) continue;

            $card = match($promo['tipo']) {
                'desconto_progressivo' => $this->cardProgressivo($promo, $itens, $frete),
                'frete_gratis'         => $this->cardFreteGratis($promo, $subtotal, $frete),
                default                => null,
            };

            if ($card !== null) $cards[] = $card;
        }

        // Aplicadas primeiro, depois disponíveis/quase lá
        usort($cards, function (array $a, array $b): int {
            $ordem = ['aplicada' => 0, 'proxima_faixa' => 1, 'disponivel' => 2];
            return ($ordem[$a['estado']] ?? 9) <=> ($ordem[$b['estado']] ?? 9);
        });

        return $cards;
    }

    // ── Cards por tipo ─────────────────────────────────

    private function cardProgressivo(array $promo, array $itens, float $frete): ?array {
        $cfg    = $promo['configuracao'];
        $faixas = $cfg['faixas'] ?? [];
        if (empty($faixas)) return null;

        // Faixas ordenadas crescente (quantidade mínima)
        usort($faixas, fn($a, $b) => $a['qtd'] <=> $b['qtd']);

        $itensElegiveis = $this->filtrarItensElegiveis($promo, $itens);
        // Sem nenhum item elegível no carrinho — promoção não é relevante
        if (empty($itensElegiveis)) return null;

        $modo       = $cfg['modo_contagem'] ?? 'unidades';
        $tipoDesc   = $cfg['tipo_desconto'] ?? 'percentual';
        $quantidade = $this->contarQuantidade($itensElegiveis, $modo);

        // Encontra faixa atual (maior que o carrinho satisfaz)
        $faixaAtual = null;
        $idxAtual   = -1;
        foreach ($faixas as $idx => $f) {
            if ($quantidade >= $f['qtd']) { $faixaAtual = $f; $idxAtual = $idx; }
        }

        // Próxima faixa (a primeira acima da atual, ou a primeira se nenhuma ativa)
        $proximaFaixa = null;
        if ($idxAtual < count($faixas) - 1) {
            $proximaFaixa = $faixas[$idxAtual + 1];
        } elseif ($faixaAtual === null) {
            $proximaFaixa = $faixas[0]; // ainda não atingiu nem a primeira
        }

        $subtotalElegivel = (float)array_sum(array_map(
            fn($i) => $i['preco'] * $i['qtd'], $itensElegiveis
        ));

        // Desconto atual
        $desconto = 0.0;
        if ($faixaAtual !== null) {
            $desconto = $tipoDesc === 'percentual'
                ? round($subtotalElegivel * ($faixaAtual['pct'] / 100), 2)
                : round(($faixaAtual['valor'] ?? 0) * $quantidade, 2);

            if ($cfg['frete_gratis'] ?? false) {
                $desconto += $frete;
            }
        }

        // Estado
        $estado = match(true) {
            $faixaAtual !== null && $proximaFaixa !== null => 'proxima_faixa',
            $faixaAtual !== null                           => 'aplicada',
            default                                        => 'disponivel',
        };

        // Progresso percentual — usa posição absoluta em relação ao
        // alvo (próxima faixa), não relativa entre faixas.
        // O cálculo anterior (atual-base)/(alvo-base) dava 0% quando
        // o cliente estava exatamente no limiar de uma faixa (atual==base).
        $progresso = 0;
        if ($proximaFaixa !== null) {
            $alvo      = (int)$proximaFaixa['qtd'];
            $progresso = $alvo > 0
                ? min(99, (int)($quantidade / $alvo * 100))
                : 99;
        } elseif ($faixaAtual !== null) {
            $progresso = 100;
        }

        // Mensagem
        $unidade = $modo === 'distintos' ? 'produto distinto' : 'unidade';
        $plural  = fn(int $n, string $s) => $n === 1 ? "1 {$s}" : "{$n} {$s}s";

        if ($estado === 'aplicada') {
            $msg = $this->labelFaixa($faixaAtual, $tipoDesc) . ' de desconto aplicado!';
        } elseif ($proximaFaixa !== null) {
            $falta = $proximaFaixa['qtd'] - $quantidade;
            $label = $this->labelFaixa($proximaFaixa, $tipoDesc);
            $msg   = "Adicione mais {$plural($falta, $unidade)} e ganhe {$label}";
        } else {
            $falta = $faixas[0]['qtd'] - $quantidade;
            $label = $this->labelFaixa($faixas[0], $tipoDesc);
            $msg   = "Adicione {$plural($falta, $unidade)} e ganhe {$label}";
        }

        return [
            'promocao_id'   => $promo['id'],
            'nome'          => $promo['nome'],
            'tipo'          => 'desconto_progressivo',
            'estado'        => $estado,
            'desconto'      => $desconto,
            'desconto_fmt'  => $desconto > 0 ? PriceHelper::format($desconto) : null,
            'faixa_atual'   => $faixaAtual,
            'proxima_faixa' => $proximaFaixa,
            'quantidade'    => $quantidade,
            'falta_qtd'     => $proximaFaixa ? max(0, $proximaFaixa['qtd'] - $quantidade) : 0,
            'progresso_pct' => $progresso,
            'msg'           => $msg,
        ];
    }

    private function cardFreteGratis(array $promo, float $subtotal, float $frete): ?array {
        $cfg     = $promo['configuracao'];
        $minimo  = (float)($cfg['valor_minimo'] ?? 0);

        if ($frete <= 0) return null; // frete já grátis

        $falta     = max(0, $minimo - $subtotal);
        $aplicada  = $subtotal >= $minimo;
        $progresso = $minimo > 0
            ? min(99, (int)($subtotal / $minimo * 100))
            : ($aplicada ? 100 : 0);

        return [
            'promocao_id'    => $promo['id'],
            'nome'           => $promo['nome'],
            'tipo'           => 'frete_gratis',
            'estado'         => $aplicada ? 'aplicada' : 'disponivel',
            'desconto'       => $aplicada ? $frete : 0.0,
            'desconto_fmt'   => $aplicada ? PriceHelper::format($frete) : null,
            'falta_valor'    => $falta,
            'falta_valor_fmt'=> $falta > 0 ? PriceHelper::format($falta) : null,
            'progresso_pct'  => $aplicada ? 100 : $progresso,
            'msg'            => $aplicada
                ? 'Frete grátis aplicado!'
                : 'Falta ' . PriceHelper::format($falta) . ' para frete grátis',
        ];
    }

    private function labelFaixa(array $faixa, string $tipo): string {
        if ($tipo === 'percentual') {
            return number_format((float)$faixa['pct'], 1, ',', '') . '%';
        }
        return 'R$ ' . number_format((float)($faixa['valor'] ?? 0), 2, ',', '.');
    }

    // ══════════════════════════════════════════════════
    // AVALIAÇÃO — chamada pelo carrinho/checkout
    // ══════════════════════════════════════════════════

    /**
     * Avalia todas as promoções ativas contra o carrinho e retorna
     * uma lista de ResultadoPromocao ordenada por prioridade.
     *
     * Lógica de acumulação:
     *   - promoções são avaliadas em ordem de prioridade (maior primeiro)
     *   - se uma promoção não é acumulavel, nenhuma de menor prioridade
     *     será incluída no resultado — a não ser que a próxima também
     *     seja acumulavel (ela se declara compatível com quem veio antes)
     *
     * @param array $itens      formato: [{produto_id, preco, qtd, categoria_id,
     *                                     marca_id, caracteristicas[], em_promocao}]
     * @param float $subtotal   valor total dos itens antes de descontos
     * @param float $frete      valor do frete calculado
     * @param ?int  $clienteId
     * @param array $contexto   dados extras: ['primeira_compra', 'score', ...]
     */
    public function avaliarCarrinho(
        array  $itens,
        float  $subtotal,
        float  $frete,
        ?int   $clienteId = null,
        array  $contexto  = []
    ): array {
        $itens      = $this->enriquecerItens($itens);
        $promocoes  = $this->model->getAtivasAgora();
        $resultados = [];
        $bloqueado  = false; // flag: promoção não-acumulável foi aplicada

        foreach ($promocoes as $promo) {
            if ($bloqueado) break;

            $resultado = $this->avaliarUma($promo, $itens, $subtotal, $frete, $clienteId, $contexto);
            if ($resultado === null) continue; // não elegível

            $resultados[] = $resultado;

            // Promoção não-acumulável: bloqueia todas as de menor prioridade
            if (!$promo['acumulavel']) {
                $bloqueado = true;
            }
        }

        return $resultados;
    }

    /**
     * Calcula o totais de desconto agregando todos os resultados.
     * Retorna: ['desconto_produto', 'desconto_frete', 'brindes', 'total_desconto']
     */
    public function calcularTotais(array $resultados): array {
        $descontoProduto = 0.0;
        $descontoFrete   = 0.0;
        $brindes         = [];

        foreach ($resultados as $r) {
            $descontoProduto += $r['desconto_produto'];
            $descontoFrete   += $r['desconto_frete'];
            foreach ($r['brindes'] as $b) {
                $brindes[] = $b;
            }
        }

        return [
            'desconto_produto' => round($descontoProduto, 2),
            'desconto_frete'   => round($descontoFrete,   2),
            'brindes'          => $brindes,
            'total_desconto'   => round($descontoProduto + $descontoFrete, 2),
        ];
    }

    // ══════════════════════════════════════════════════
    // PERSISTÊNCIA — chamada após confirmação do pedido
    // ══════════════════════════════════════════════════

    /**
     * Registra as promoções aplicadas. Deve ser chamado dentro da
     * mesma transação do processo de criação do pedido.
     * (sem beginTransaction() próprio — mesmo padrão do CouponService)
     */
    public function aplicar(array $resultados, int $pedidoId, ?int $clienteId): void {
        foreach ($resultados as $r) {
            $tipo = 'desconto';
            if ($r['desconto_frete'] > 0 && $r['desconto_produto'] === 0.0) {
                $tipo = 'frete_gratis';
            } elseif (!empty($r['brindes'])) {
                $tipo = 'brinde';
            }

            $this->model->registrarAplicacao(
                promocaoId:      $r['promocao_id'],
                pedidoId:        $pedidoId,
                clienteId:       $clienteId,
                tipoBeneficio:   $tipo,
                valorDesconto:   $r['desconto_produto'] + $r['desconto_frete'],
                produtoBrindeId: $r['brindes'][0]['produto_id'] ?? null,
                qtdBrinde:       $r['brindes'][0]['quantidade'] ?? 0,
                detalhes:        [
                    'desconto_produto' => $r['desconto_produto'],
                    'desconto_frete'   => $r['desconto_frete'],
                    'brindes'          => $r['brindes'],
                    'faixa_aplicada'   => $r['faixa_aplicada'] ?? null,
                    'itens_elegiveis'  => $r['itens_elegiveis'] ?? [],
                ],
            );
        }
    }

    // ══════════════════════════════════════════════════
    // AVALIAÇÃO INDIVIDUAL (privado)
    // ══════════════════════════════════════════════════

    /**
     * Avalia uma promoção específica contra o carrinho.
     * Retorna null se o carrinho não é elegível.
     * Retorna array de resultado se elegível.
     */
    private function avaliarUma(
        array  $promo,
        array  $itens,
        float  $subtotal,
        float  $frete,
        ?int   $clienteId,
        array  $contexto
    ): ?array {
        // ── 1. Restrições temporais ────────────────────
        if (!$this->validarDiaHora($promo)) return null;

        // ── 2. Restrições de audiência ─────────────────
        if (!$this->validarAudiencia($promo, $clienteId, $contexto)) return null;

        // ── 3. Condições do carrinho ───────────────────
        if ($promo['valor_minimo_carrinho'] !== null && $subtotal < $promo['valor_minimo_carrinho']) {
            return null;
        }
        if ($promo['qtd_minima_itens'] !== null) {
            $totalItens = array_sum(array_column($itens, 'qtd'));
            if ($totalItens < $promo['qtd_minima_itens']) return null;
        }

        // ── 4. Filtra itens elegíveis ──────────────────
        $itensElegiveis = $this->filtrarItensElegiveis($promo, $itens);
        if (empty($itensElegiveis)) return null;

        // ── 5. Avalia por tipo ─────────────────────────
        return match($promo['tipo']) {
            'desconto_progressivo' => $this->avaliarProgressivo($promo, $itensElegiveis, $frete),
            'frete_gratis'         => $this->avaliarFreteGratis($promo, $itensElegiveis, $subtotal, $frete),
            default                => null, // tipos ainda não implementados
        };
    }

    // ── Desconto progressivo ───────────────────────────

    private function avaliarProgressivo(array $promo, array $itensElegiveis, float $frete): ?array {
        $cfg = $promo['configuracao'];
        $faixas = $cfg['faixas'] ?? [];

        if (empty($faixas)) return null;

        // Ordena faixas decrescente pra pegar a maior que se aplica
        usort($faixas, fn($a, $b) => $b['qtd'] <=> $a['qtd']);

        // Conta a quantidade segundo o modo configurado
        $modoContagem = $cfg['modo_contagem'] ?? 'unidades';
        $quantidade   = $this->contarQuantidade($itensElegiveis, $modoContagem);

        // Encontra a faixa que se aplica (maior qtd que o carrinho satisfaz)
        $faixaAplicada = null;
        foreach ($faixas as $faixa) {
            if ($quantidade >= $faixa['qtd']) {
                $faixaAplicada = $faixa;
                break;
            }
        }

        if ($faixaAplicada === null) return null; // quantidade insuficiente pra qualquer faixa

        // Calcula desconto — incide APENAS nos itens elegíveis
        $subtotalElegivel = array_sum(array_map(
            fn($i) => $i['preco'] * $i['qtd'],
            $itensElegiveis
        ));

        $tipoDesc = $cfg['tipo_desconto'] ?? 'percentual';
        $desconto = match($tipoDesc) {
            'percentual'    => round($subtotalElegivel * ($faixaAplicada['pct'] / 100), 2),
            'fixo_por_item' => round($faixaAplicada['valor'] * $quantidade, 2),
            default         => 0.0,
        };

        $descontoFrete = ($cfg['frete_gratis'] ?? false) ? $frete : 0.0;

        return [
            'promocao_id'      => $promo['id'],
            'promocao_nome'    => $promo['nome'],
            'tipo'             => 'desconto_progressivo',
            'desconto_produto' => $desconto,
            'desconto_frete'   => $descontoFrete,
            'brindes'          => [],
            'faixa_aplicada'   => $faixaAplicada,
            'quantidade_contada' => $quantidade,
            'itens_elegiveis'  => array_column($itensElegiveis, 'produto_id'),
            'msg'              => $this->msgProgressivo($faixaAplicada, $tipoDesc, $quantidade),
        ];
    }

    private function contarQuantidade(array $itensElegiveis, string $modo): int {
        return match($modo) {
            // Total de unidades (2x o mesmo = 2)
            'unidades'  => (int)array_sum(array_column($itensElegiveis, 'qtd')),
            // Itens distintos (2 produtos diferentes = 2, mesmo que com qtd > 1 cada)
            'distintos' => count($itensElegiveis),
            default     => (int)array_sum(array_column($itensElegiveis, 'qtd')),
        };
    }

    private function msgProgressivo(array $faixa, string $tipo, int $qtd): string {
        if ($tipo === 'percentual') {
            return "{$faixa['pct']}% de desconto ({$qtd} itens elegíveis)";
        }
        return "Desconto de R$ " . number_format($faixa['valor'], 2, ',', '.') . " por item";
    }

    // ── Frete grátis automático ────────────────────────

    private function avaliarFreteGratis(
        array $promo, array $itensElegiveis, float $subtotal, float $frete
    ): ?array {
        $cfg = $promo['configuracao'];
        $minimo = (float)($cfg['valor_minimo'] ?? 0);

        if ($subtotal < $minimo) return null;
        if ($frete <= 0) return null; // já grátis, sem sentido aplicar

        return [
            'promocao_id'      => $promo['id'],
            'promocao_nome'    => $promo['nome'],
            'tipo'             => 'frete_gratis',
            'desconto_produto' => 0.0,
            'desconto_frete'   => $frete,
            'brindes'          => [],
            'faixa_aplicada'   => null,
            'itens_elegiveis'  => [],
            'msg'              => 'Frete grátis',
        ];
    }

    // ══════════════════════════════════════════════════
    // ENRIQUECIMENTO DE ITENS
    // ══════════════════════════════════════════════════

    /**
     * Preenche marca_id e categoria_id ausentes (valor 0) nos itens
     * do carrinho com uma única query IN — evita N+1.
     *
     * getItensParaCupom() retorna 0 para esses campos quando
     * getItensComVariacoes() não faz JOIN com a tabela produtos.
     * Sem esse enriquecimento, qualquer escopo de marca/categoria
     * filtra tudo (in_array(0, [3]) = false → nenhum item elegível).
     */
    private function enriquecerItens(array $itens): array {
        if (empty($itens)) return $itens;

        // Sempre enriquece: categorias_ids precisa vir de produto_categorias
        // (pivot que guarda TODAS as categorias do produto), não de
        // produtos.categoria_id que só guarda a principal.
        // Sem isso, uma categoria especial como "Dia dos Namorados" nunca
        // matcheia porque o produto tem categoria principal "Capacetes".
        $ids = array_unique(array_map('intval',
            array_column($itens, 'produto_id')
        ));
        $ids = array_filter($ids);
        if (empty($ids)) return $itens;

        $in   = implode(',', $ids);

        // Uma única query: marca + TODAS as categorias do produto
        $stmt = $this->db->query(
            "SELECT p.id   AS produto_id,
                    p.marca_id,
                    GROUP_CONCAT(DISTINCT pc.categoria_id ORDER BY pc.principal DESC)
                        AS todas_categorias
             FROM produtos p
             LEFT JOIN produto_categorias pc ON pc.produto_id = p.id
             WHERE p.id IN ({$in})
             GROUP BY p.id, p.marca_id"
        );

        $mapa = [];
        foreach ($stmt->fetchAll() as $row) {
            $categorias = $row['todas_categorias']
                ? array_map('intval', explode(',', $row['todas_categorias']))
                : [];
            $mapa[(int)$row['produto_id']] = [
                'marca_id'       => (int)$row['marca_id'],
                'categorias_ids' => $categorias,
                // mantém categoria_id como a principal (primeiro da lista,
                // já ordenado por principal DESC no GROUP_CONCAT)
                'categoria_id'   => $categorias[0] ?? 0,
            ];
        }

        return array_map(function (array $item) use ($mapa): array {
            $pid = (int)$item['produto_id'];
            if (!isset($mapa[$pid])) return $item;

            if (empty($item['marca_id']))      $item['marca_id']      = $mapa[$pid]['marca_id'];
            if (empty($item['categoria_id']))   $item['categoria_id']  = $mapa[$pid]['categoria_id'];
            // categorias_ids sempre vem daqui — getItensParaCupom() não o fornece
            $item['categorias_ids'] = $mapa[$pid]['categorias_ids'];

            return $item;
        }, $itens);
    }

    // ══════════════════════════════════════════════════
    // FILTROS E VALIDAÇÕES
    // ══════════════════════════════════════════════════

    /**
     * Filtra os itens do carrinho que se enquadram no escopo da promoção.
     * null em qualquer dimensão de escopo = sem restrição nessa dimensão.
     * Múltiplas dimensões preenchidas = item precisa satisfazer TODAS (AND).
     */
    private function filtrarItensElegiveis(array $promo, array $itens): array {
        $temEscopo = !empty($promo['escopo_produtos'])
                  || !empty($promo['escopo_categorias'])
                  || !empty($promo['escopo_marcas'])
                  || !empty($promo['escopo_caracteristicas']);

        // Sem nenhum escopo definido: todos os itens são elegíveis
        if (!$temEscopo) return $itens;

        return array_values(array_filter($itens, function (array $item) use ($promo): bool {
            // Produtos específicos — se definido, o item precisa estar na lista
            if (!empty($promo['escopo_produtos'])) {
                if (!in_array((int)$item['produto_id'], $promo['escopo_produtos'], true)) {
                    return false;
                }
            }
            // Categorias — verifica TODAS as categorias do produto,
            // não só a principal. Permite promoções em categorias
            // especiais (ex: "Dia dos Namorados") sem alterar a
            // categoria principal do produto.
            if (!empty($promo['escopo_categorias'])) {
                $categoriasItem = $item['categorias_ids'] ?? [];
                if (empty($categoriasItem)) {
                    // fallback: usa categoria_id simples se categorias_ids
                    // não foi preenchido (não deveria acontecer após enriquecerItens)
                    $categoriasItem = [(int)($item['categoria_id'] ?? 0)];
                }
                if (empty(array_intersect($categoriasItem, $promo['escopo_categorias']))) {
                    return false;
                }
            }
            // Marcas
            if (!empty($promo['escopo_marcas'])) {
                if (!in_array((int)($item['marca_id'] ?? 0), $promo['escopo_marcas'], true)) {
                    return false;
                }
            }
            // Características: [{id: 1, valor: "Adulto"}, ...] — item precisa ter TODAS
            if (!empty($promo['escopo_caracteristicas'])) {
                $caracItem = $item['caracteristicas'] ?? [];
                foreach ($promo['escopo_caracteristicas'] as $filtro) {
                    $encontrou = false;
                    foreach ($caracItem as $c) {
                        if ((int)$c['id'] === (int)$filtro['id']
                            && strtolower((string)$c['valor']) === strtolower((string)$filtro['valor'])) {
                            $encontrou = true;
                            break;
                        }
                    }
                    if (!$encontrou) return false;
                }
            }
            return true;
        }));
    }

    private function validarDiaHora(array $promo): bool {
        $agora = new \DateTime('now');

        // Dias da semana (0=dom...6=sab)
        if (!empty($promo['dias_semana'])) {
            $diaSemana = (int)$agora->format('w');
            if (!in_array($diaSemana, $promo['dias_semana'], true)) return false;
        }

        // Horário (para promoções relâmpago)
        if ($promo['horario_inicio'] !== null && $promo['horario_fim'] !== null) {
            $horaAtual = $agora->format('H:i:s');
            if ($horaAtual < $promo['horario_inicio'] || $horaAtual > $promo['horario_fim']) {
                return false;
            }
        }

        return true;
    }

    private function validarAudiencia(array $promo, ?int $clienteId, array $contexto): bool {
        // Restrição de clientes específicos
        if (!empty($promo['clientes_ids'])) {
            if ($clienteId === null) return false;
            if (!in_array($clienteId, $promo['clientes_ids'], true)) return false;
        }

        // Apenas primeira compra
        if ($promo['apenas_primeira_compra']) {
            if (!($contexto['primeira_compra'] ?? false)) return false;
        }

        // Score mínimo
        if ($promo['score_minimo'] !== null) {
            $score = (int)($contexto['score'] ?? 0);
            if ($score < $promo['score_minimo']) return false;
        }

        return true;
    }
}