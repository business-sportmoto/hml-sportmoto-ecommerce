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
        $itens = $this->enriquecerItens($itens);

        // Recalcula subtotal dos itens enriquecidos (preco × qtd).
        // O subtotal externo pode ter sido calculado com keys diferentes
        // (valor_unitario × quantidade via calcularTotais do Cart), gerando
        // valor 0 e fazendo gatilhos de valor sempre dispararem ou nunca.
        $subtotal  = $this->calcularSubtotalItens($itens);
        $promocoes = $this->model->getAtivasAgora();
        $cards     = [];

        foreach ($promocoes as $promo) {
            if (!$this->validarDiaHora($promo))                         continue;
            if (!$this->validarAudiencia($promo, $clienteId, $contexto)) continue;

            // Filtra itens elegíveis pelo escopo da promoção UMA VEZ.
            // Se não houver itens elegíveis, pula — não mostra card.
            // Consistente com avaliarUma() que faz o mesmo guard.
            $itensElegiveis = $this->filtrarItensElegiveis($promo, $itens);
            if (empty($itensElegiveis)) continue;

            $card = match($promo['tipo']) {
                'desconto_progressivo' => $this->cardProgressivo($promo, $itensElegiveis, $frete),
                'frete_gratis'         => $this->cardFreteGratis($promo, $itensElegiveis, $subtotal, $frete),
                'brinde'               => $this->cardBrinde($promo, $itensElegiveis, $subtotal),
                'compre_ganhe'         => $this->cardCompraGanhe($promo, $itensElegiveis),
                'cashback'             => $this->cardCashback($promo, $itensElegiveis, $subtotal),
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

    private function cardProgressivo(array $promo, array $itensElegiveis, float $frete): ?array {
        $cfg    = $promo['configuracao'];
        $faixas = $cfg['faixas'] ?? [];
        if (empty($faixas)) return null;

        // Faixas ordenadas crescente (quantidade mínima)
        usort($faixas, fn($a, $b) => $a['qtd'] <=> $b['qtd']);

        // itensElegiveis já filtrados por previewCarrinho() — sem refiltrar aqui

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

    private function cardFreteGratis(array $promo, array $itensElegiveis, float $subtotal, float $frete): ?array {
        $cfg    = $promo['configuracao'];
        $minimo = (float)($cfg['valor_minimo'] ?? 0);

        if ($frete <= 0) return null; // frete já grátis

        // Usa subtotal dos itens elegíveis do escopo, não o carrinho inteiro.
        // Ex: "frete grátis em compras de R$200 em capacetes" → conta só capacetes.
        $subtotalElegivel = $this->calcularSubtotalItens($itensElegiveis);
        $falta            = max(0, $minimo - $subtotalElegivel);
        $aplicada         = $subtotalElegivel >= $minimo;
        $progresso        = $minimo > 0
            ? min(99, (int)($subtotalElegivel / $minimo * 100))
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
        $itens             = $this->enriquecerItens($itens);
        $subtotal          = $this->calcularSubtotalItens($itens); // preco × qtd real
        $promocoes         = $this->model->getAtivasAgora();
        $resultados        = [];
        $bloqueado         = false;
        $descontoAcumulado = 0.0;

        foreach ($promocoes as $promo) {
            if ($bloqueado) break;

            $resultado = $this->avaliarUma(
                $promo, $itens, $subtotal, $frete, $clienteId, $contexto, $descontoAcumulado
            );
            if ($resultado === null) continue;

            $resultados[]       = $resultado;
            $descontoAcumulado += ($resultado['desconto_produto'] ?? 0.0)
                                + ($resultado['desconto_frete']   ?? 0.0);

            if (!$promo['acumulavel']) {
                $bloqueado = true;
            }
        }

        // ── Detecção de anomalia ──────────────────────────
        // Desconto acumulado > subtotal indica promoções mal configuradas
        // (ou tentativa de exploit por stacking). O checkout já protege o
        // total com max(0, ...), mas isso mascara o problema — o log
        // estruturado permite alertar antes de virar prejuízo em escala.
        if ($descontoAcumulado > $subtotal && $subtotal > 0) {
            error_log(sprintf(
                '[PROMO_ANOMALY] desconto_acumulado=%.2f > subtotal=%.2f | cliente=%s | promocoes=%s',
                $descontoAcumulado,
                $subtotal,
                $clienteId ?? 'anon',
                implode(',', array_column($resultados, 'promocao_id'))
            ));
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
            // Guard anti double-apply: retry de checkout ou chamada duplicada
            // não pode registrar a mesma promoção duas vezes no mesmo pedido.
            if ($this->model->jaAplicada((int)$r['promocao_id'], $pedidoId)) {
                continue;
            }

            // Classificação do tipo de benefício.
            // CRÍTICO: cashback tem desconto_produto=0 e desconto_frete=0 —
            // sem esta verificação ele cairia em 'desconto' e o
            // CashbackService (que filtra tipo_beneficio='cashback')
            // NUNCA o encontraria para creditar.
            $tipo = 'desconto';
            if (isset($r['cashback_valor']) && (float)$r['cashback_valor'] > 0) {
                $tipo = 'cashback';
            } elseif (!empty($r['brindes'])) {
                $tipo = 'brinde';
            } elseif ($r['desconto_frete'] > 0 && $r['desconto_produto'] === 0.0) {
                $tipo = 'frete_gratis';
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
                    'desconto_produto'       => $r['desconto_produto'],
                    'desconto_frete'         => $r['desconto_frete'],
                    'brindes'                => $r['brindes'],
                    'faixa_aplicada'         => $r['faixa_aplicada'] ?? null,
                    'itens_elegiveis'        => $r['itens_elegiveis'] ?? [],
                    'itens_desconto'         => $r['itens_desconto']  ?? [],
                    // campos de cashback — lidos por CashbackService
                    'cashback_pct'           => $r['cashback_pct']           ?? null,
                    'cashback_base'          => $r['cashback_base']          ?? null,
                    'cashback_valor'         => $r['cashback_valor']         ?? null,
                    'cashback_validade_dias' => $r['cashback_validade_dias'] ?? null,
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
        array  $contexto,
        float  $descontoAcumulado = 0.0  // descontos já aplicados por promoções anteriores
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
            $totalItens = $this->somarQtd($itens);
            if ($totalItens < $promo['qtd_minima_itens']) return null;
        }

        // ── 4. Filtra itens elegíveis ──────────────────
        $itensElegiveis = $this->filtrarItensElegiveis($promo, $itens);
        if (empty($itensElegiveis)) return null;

        // ── 5. Avalia por tipo ─────────────────────────
        return match($promo['tipo']) {
            'desconto_progressivo' => $this->avaliarProgressivo($promo, $itensElegiveis, $frete),
            'frete_gratis'         => $this->avaliarFreteGratis($promo, $itensElegiveis, $subtotal, $frete),
            'brinde'               => $this->avaliarBrinde($promo, $itensElegiveis, $subtotal),
            'compre_ganhe'         => $this->avaliarCompraGanhe($promo, $itensElegiveis),
            'cashback'             => $this->avaliarCashback($promo, $itensElegiveis, $subtotal, $descontoAcumulado),
            default                => null,
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
            'unidades'  => $this->somarQtd($itensElegiveis),
            // Itens distintos (2 produtos diferentes = 2, mesmo que com qtd > 1 cada)
            'distintos' => count($itensElegiveis),
            default     => $this->somarQtd($itensElegiveis),
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
        $cfg    = $promo['configuracao'];
        $minimo = (float)($cfg['valor_minimo'] ?? 0);

        // Usa subtotal elegível: "frete grátis em R$200 de capacetes"
        // não deve disparar por outros produtos no carrinho
        $subtotalElegivel = $this->calcularSubtotalItens($itensElegiveis);
        if ($subtotalElegivel < $minimo) return null;
        if ($frete <= 0) return null;

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

    // ── Brinde ─────────────────────────────────────────

    /**
     * Avalia elegibilidade do brinde e retorna resultado para o checkout.
     * Sem estoque no produto brinde → retorna null (bloqueia a promoção).
     * desconto_produto = preço do brinde × qtd (será coberto como desconto
     * no pedido — o item entra com o valor real, zerando o custo ao cliente).
     */
    private function avaliarBrinde(array $promo, array $itensElegiveis, float $subtotal): ?array {
        $cfg             = $promo['configuracao'];
        $produtoBrindeId = (int)($cfg['produto_brinde_id'] ?? 0);
        $qtdBrinde       = max(1, (int)($cfg['quantidade_brinde'] ?? 1));

        if (!$produtoBrindeId) return null;

        // Sem estoque → bloqueia a promoção inteira
        if (!$this->brindeTemEstoque($produtoBrindeId)) return null;

        // Verifica gatilho
        if (!$this->gatilhoBrindeAtingido($cfg, $itensElegiveis, $subtotal)) return null;

        $produto = $this->getBrindeProduto($produtoBrindeId);
        if (!$produto) return null;

        $precoBrinde   = (float)$produto['preco'];
        $totalDesconto = round($precoBrinde * $qtdBrinde, 2);

        return [
            'promocao_id'      => $promo['id'],
            'promocao_nome'    => $promo['nome'],
            'tipo'             => 'brinde',
            'desconto_produto' => $totalDesconto,
            'desconto_frete'   => 0.0,
            'brindes'          => [[
                'produto_id' => $produtoBrindeId,
                'nome'       => $produto['nome'],
                'slug'       => $produto['slug'],
                'preco'      => $precoBrinde,
                'quantidade' => $qtdBrinde,
                'imagem'     => $produto['imagem'],
            ]],
            'faixa_aplicada'   => null,
            'itens_elegiveis'  => [],
            'msg'              => '🎁 Brinde: ' . $produto['nome'],
        ];
    }

    /**
     * Card de preview do brinde para o carrinho.
     * Retorna card mesmo quando ainda não atingido (estado='disponivel')
     * mostrando o progresso até o gatilho.
     */
    private function cardBrinde(array $promo, array $itensElegiveis, float $subtotal): ?array {
        $cfg             = $promo['configuracao'];
        $produtoBrindeId = (int)($cfg['produto_brinde_id'] ?? 0);
        $qtdBrinde       = max(1, (int)($cfg['quantidade_brinde'] ?? 1));

        if (!$produtoBrindeId) return null;
        if (!$this->brindeTemEstoque($produtoBrindeId)) return null;

        $produto = $this->getBrindeProduto($produtoBrindeId);
        if (!$produto) return null;

        // itensElegiveis já filtrados por previewCarrinho() — sem refiltrar aqui
        $gatilho  = $cfg['gatilho'] ?? 'valor';
        $atingido = $this->gatilhoBrindeAtingido($cfg, $itensElegiveis, $subtotal);

        // Calcula progresso e mensagem
        [$progresso, $msg] = $this->progressoBrinde($cfg, $gatilho, $itensElegiveis, $subtotal, $atingido, $produto['nome']);

        $precoBrinde = (float)$produto['preco'];
        $imagemUrl   = !empty($produto['imagem'])
            ? (defined('UPLOAD_URL') ? UPLOAD_URL : BASE_URL . '/uploads')
              . '/produtos/' . $produto['imagem']
            : null;

        return [
            'promocao_id'    => $promo['id'],
            'promocao_nome'  => $promo['nome'],
            'tipo'           => 'brinde',
            'estado'         => $atingido ? 'aplicada' : 'disponivel',
            'desconto'       => $atingido ? round($precoBrinde * $qtdBrinde, 2) : 0.0,
            'desconto_fmt'   => $atingido ? PriceHelper::format(round($precoBrinde * $qtdBrinde, 2)) : null,
            'brindes'        => $atingido ? [[
                'produto_id' => $produtoBrindeId,
                'nome'       => $produto['nome'],
                'slug'       => $produto['slug'] ?? '',
                'quantidade' => $qtdBrinde,
                'preco'      => $precoBrinde,
                'preco_fmt'  => PriceHelper::format($precoBrinde),
                'imagem_url' => $imagemUrl,
            ]] : [],
            'progresso_pct'  => $progresso,
            'msg'            => $msg,
        ];
    }

    private function gatilhoBrindeAtingido(array $cfg, array $itensElegiveis, float $subtotal): bool {
        $gatilho = $cfg['gatilho'] ?? 'valor';

        $valorOk = true;
        $qtdOk   = true;

        if (in_array($gatilho, ['valor', 'ambos'], true)) {
            $valorMin = (float)($cfg['valor_minimo'] ?? 0);

            // Usa subtotal APENAS dos itens elegíveis do escopo.
            // Sem isso, um brinde de "marca ASX ≥ R$200" dispara pelo
            // valor total do carrinho, incluindo produtos de outras marcas.
            $subtotalElegivel = $this->calcularSubtotalItens($itensElegiveis);
            $valorOk          = $subtotalElegivel >= $valorMin;
        }

        if (in_array($gatilho, ['quantidade', 'ambos'], true)) {
            $qtdMin = (int)($cfg['qtd_minima'] ?? 1);
            $modo   = $cfg['modo_contagem'] ?? 'unidades';
            $qtdOk  = $this->contarQuantidade($itensElegiveis, $modo) >= $qtdMin;
        }

        return match($gatilho) {
            'valor'      => $valorOk,
            'quantidade' => $qtdOk,
            'ambos'      => $valorOk && $qtdOk,
            default      => false,
        };
    }

    /**
     * Retorna [progresso_pct, msg] para o card de preview do brinde.
     */
    private function progressoBrinde(
        array  $cfg,
        string $gatilho,
        array  $itensElegiveis,
        float  $subtotal,
        bool   $atingido,
        string $nomeBrinde
    ): array {
        if ($atingido) {
            return [100, '🎁 Brinde: ' . $nomeBrinde . ' incluído!'];
        }

        // Para gatilho "ambos", usa o critério mais próximo de ser atingido
        if ($gatilho === 'valor' || $gatilho === 'ambos') {
            $valorMin         = (float)($cfg['valor_minimo'] ?? 0);
            $subtotalElegivel = $this->calcularSubtotalItens($itensElegiveis);
            if ($valorMin > 0) {
                $pct   = min(99, (int)($subtotalElegivel / $valorMin * 100));
                $falta = PriceHelper::format(max(0, $valorMin - $subtotalElegivel));
                if ($gatilho === 'valor') {
                    return [$pct, '🎁 Falta ' . $falta . ' para ganhar: ' . $nomeBrinde];
                }
            }
        }

        if ($gatilho === 'quantidade' || $gatilho === 'ambos') {
            $qtdMin = (int)($cfg['qtd_minima'] ?? 1);
            $modo   = $cfg['modo_contagem'] ?? 'unidades';
            $atual  = $this->contarQuantidade($itensElegiveis, $modo);
            $pct    = $qtdMin > 0 ? min(99, (int)($atual / $qtdMin * 100)) : 0;
            $falta  = max(0, $qtdMin - $atual);
            $un     = $modo === 'distintos' ? 'produto distinto' : 'unidade';
            $plural = $falta === 1 ? "1 {$un}" : "{$falta} {$un}s";
            return [$pct, '🎁 Adicione mais ' . $plural . ' para ganhar: ' . $nomeBrinde];
        }

        return [0, '🎁 Ganhe: ' . $nomeBrinde];
    }

    /**
     * Verifica se o produto brinde tem saldo disponível em estoque.
     * Usa estoque_saldo (ledger) com prioridade sobre produtos.estoque_total.
     * Retorna false se sem estoque → bloqueia a promoção.
     */
    private function brindeTemEstoque(int $produtoId): bool {
        $stmt = $this->db->prepare(
            "SELECT CASE
                WHEN EXISTS (SELECT 1 FROM estoque_saldo WHERE produto_id = ?)
                THEN (SELECT SUM(saldo) > 0 FROM estoque_saldo WHERE produto_id = ?)
                ELSE (SELECT estoque_total > 0 FROM produtos WHERE id = ?)
             END AS tem_estoque"
        );
        $stmt->execute([$produtoId, $produtoId, $produtoId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Busca dados do produto brinde para compor o resultado.
     * Usa preco_promo quando ativo — o desconto cobre o preço real do produto.
     */
    private function getBrindeProduto(int $produtoId): ?array {
        // COALESCE simples: usa preco_promo se preenchido, senão preco.
        // Evita referências a colunas opcionais (promo_inicio/promo_fim)
        // que podem não existir em todas as instalações.
        $stmt = $this->db->prepare(
            "SELECT p.id, p.nome, p.slug,
                    COALESCE(NULLIF(p.preco_promo, 0), p.preco) AS preco,
                    pi.arquivo AS imagem
             FROM   produtos p
             LEFT   JOIN produto_imagens pi
                    ON  pi.produto_id = p.id AND pi.principal = 1
             WHERE  p.id = ? AND p.ativo = 1 AND p.deleted_at IS NULL
             LIMIT  1"
        );
        $stmt->execute([$produtoId]);
        return $stmt->fetch() ?: null;
    }

    // ── Compre X leve Y ────────────────────────────────

    /**
     * Avalia "compre X leve Y": itens elegíveis são ordenados pelo
     * preço unitário (mais barato primeiro), e os Y mais baratos
     * recebem `desconto_pct`% de desconto — 100% = grátis.
     *
     * Aplica apenas uma vez (sem repetição): se o carrinho tiver 6
     * itens e a regra for "compre 3 pague 2", apenas 1 item ganha
     * o desconto (não 2).
     *
     * Os itens entram no pedido com seu valor original; o desconto
     * é injetado em desconto_produto e reduz o total do pedido.
     * Os detalhes salvos em promocao_aplicacoes identificam quais
     * produtos receberam o desconto para o sistema de NF-e aplicar
     * o CFOP correto.
     */
    private function avaliarCompraGanhe(array $promo, array $itensElegiveis): ?array {
        $cfg     = $promo['configuracao'];
        $comprar = max(2, (int)($cfg['comprar']    ?? 2));
        $levar   = max(1, (int)($cfg['levar']      ?? 1));
        $pctDesc = min(100, max(0, (float)($cfg['desconto_pct'] ?? 100)));

        if ($levar >= $comprar) return null; // configuração inválida

        // Conta unidades elegíveis — precisa de ao menos X
        $totalQtd = $this->somarQtd($itensElegiveis);
        if ($totalQtd < $comprar) return null;

        // Seleciona os Y itens de MENOR preço sem usort.
        // usort com float/string do PDO pode ordenar errado em alguns
        // ambientes; seleção por mínimo explícito é sempre confiável.
        $unitarios        = $this->expandirEmUnidades($itensElegiveis);
        $itensComDesconto = $this->selecionarMaisBaratos($unitarios, $levar);
        $totalDesconto    = round(
            array_sum(array_map(fn($u) => $u['preco'] * ($pctDesc / 100), $itensComDesconto)),
            2
        );

        if ($totalDesconto <= 0) return null;

        return [
            'promocao_id'      => $promo['id'],
            'promocao_nome'    => $promo['nome'],
            'tipo'             => 'compre_ganhe',
            'desconto_produto' => $totalDesconto,
            'desconto_frete'   => 0.0,
            'brindes'          => [],
            'faixa_aplicada'   => null,
            'itens_elegiveis'  => array_unique(array_column($itensElegiveis, 'produto_id')),
            // snapshot dos itens que receberam desconto → usado pelo sistema de NF-e
            'itens_desconto'   => $itensComDesconto,
            'msg'              => $this->msgCompraGanhe($comprar, $levar, $pctDesc, $itensComDesconto),
        ];
    }

    /**
     * Card de preview do "Compre X leve Y" para o carrinho.
     * Mostra progresso (itens no carrinho / X necessários) e quais
     * itens ganhariam o benefício se aplicado agora.
     */
    private function cardCompraGanhe(array $promo, array $itensElegiveis): ?array {
        $cfg     = $promo['configuracao'];
        $comprar = max(2, (int)($cfg['comprar']    ?? 2));
        $levar   = max(1, (int)($cfg['levar']      ?? 1));
        $pctDesc = min(100, max(0, (float)($cfg['desconto_pct'] ?? 100)));

        if ($levar >= $comprar) return null;

        // itensElegiveis já filtrados por previewCarrinho() — sem refiltrar aqui

        $totalQtd  = $this->somarQtd($itensElegiveis);
        $atingido  = $totalQtd >= $comprar;
        $progresso = min(99, $comprar > 0 ? (int)($totalQtd / $comprar * 100) : 0);

        $desconto = 0.0;
        $itensComDesconto = [];
        if ($atingido) {
            $unitarios        = $this->expandirEmUnidades($itensElegiveis);
            $itensComDesconto = $this->selecionarMaisBaratos($unitarios, $levar);
            $desconto         = round(
                array_sum(array_map(fn($u) => $u['preco'] * ($pctDesc / 100), $itensComDesconto)),
                2
            );
        }

        return [
            'promocao_id'     => $promo['id'],
            'promocao_nome'   => $promo['nome'],
            'tipo'            => 'compre_ganhe',
            'estado'          => $atingido ? 'aplicada' : 'disponivel',
            'desconto'        => $desconto,
            'desconto_fmt'    => $desconto > 0 ? PriceHelper::format($desconto) : null,
            'desconto_pct'    => $pctDesc,
            'brindes'         => [],
            'progresso_pct'   => $atingido ? 100 : $progresso,
            'msg'             => $this->msgCompraGanhe($comprar, $levar, $pctDesc, $itensComDesconto, $totalQtd),
            // Itens que recebem o desconto, agregados por produto_id.
            // O JS usa isso para: (a) badge no item certo do carrinho e
            // (b) mostrar "desconto aplicado em X" no card de preview.
            'itens_desconto'  => $this->agregarItensDesconto($itensComDesconto, $pctDesc),
        ];
    }

    /**
     * Expande itens do carrinho em unidades individuais.
     * Item com qtd=3 a R$50 vira 3 entradas de R$50.
     * Usado para ordenar por preço e aplicar o desconto nos Y mais baratos.
     */
    /**
     * Seleciona os Y itens de menor preço de uma lista de unidades expandidas.
     *
     * Usa seleção por mínimo explícito em vez de usort para evitar
     * comportamento inconsistente com floats que vieram do PDO como string.
     * O(N × Y) — Y é tipicamente 1-3, então praticamente O(N).
     *
     * Exemplo: items = [500, 200, 100], Y = 1 → retorna [100]
     */
    private function selecionarMaisBaratos(array $unitarios, int $quantidade): array {
        $selecionados = [];
        $pool         = $unitarios; // cópia mutável

        for ($i = 0; $i < $quantidade && !empty($pool); $i++) {
            $minPreco = PHP_FLOAT_MAX;
            $minKey   = null;

            foreach ($pool as $k => $u) {
                $preco = (float)$u['preco'];
                if ($preco < $minPreco) {
                    $minPreco = $preco;
                    $minKey   = $k;
                }
            }

            if ($minKey !== null) {
                $selecionados[] = $pool[$minKey];
                unset($pool[$minKey]); // remove para não selecionar duas vezes
            }
        }

        return $selecionados;
    }

    /**
     * Agrega unidades descontadas por produto_id.
     * expandirEmUnidades() cria uma entrada por unidade — 3 unidades
     * do produto A viram 3 entradas. Aqui consolida em:
     * [{produto_id, qtd_desconto, preco, desconto_unitario}]
     * para o JS saber exatamente qual produto e quantas unidades
     * receberam o desconto.
     */
    private function agregarItensDesconto(array $itensComDesconto, float $pctDesc): array {
        $mapa = [];
        foreach ($itensComDesconto as $u) {
            $pid = (int)$u['produto_id'];
            if (!isset($mapa[$pid])) {
                $mapa[$pid] = [
                    'produto_id'       => $pid,
                    'qtd_desconto'     => 0,
                    'preco'            => (float)$u['preco'],
                    'desconto_unitario'=> round((float)$u['preco'] * $pctDesc / 100, 2),
                ];
            }
            $mapa[$pid]['qtd_desconto']++;
        }
        return array_values($mapa);
    }

    /**
     * Calcula o subtotal de um array de itens usando preco × qtd.
     * Aceita 'qtd' ou 'quantidade' como chave de quantidade.
     *
     * Substitui chamadas a $cart->calcularTotais() que usam keys
     * diferentes (valor_unitario × quantidade) e retornam 0 quando
     * os itens já foram enriquecidos com a chave 'preco'.
     */
    private function calcularSubtotalItens(array $itens): float {
        $total = 0.0;
        foreach ($itens as $item) {
            $preco = (float)($item['preco'] ?? 0);
            $qtd   = (int)($item['qtd'] ?? $item['quantidade'] ?? 0);
            $total += $preco * $qtd;
        }
        return round($total, 2);
    }

    /**
     * Soma a quantidade de itens num array, aceitando tanto 'qtd'
     * (formato de getItensParaCupom) quanto 'quantidade' (outros contextos).
     * Evita retornar 0 quando a chave existe mas com nome diferente.
     */
    private function somarQtd(array $itens): int {
        $total = 0;
        foreach ($itens as $item) {
            $total += (int)($item['qtd'] ?? $item['quantidade'] ?? 0);
        }
        return $total;
    }

    private function expandirEmUnidades(array $itens): array {
        $unitarios = [];
        foreach ($itens as $item) {
            // Aceita 'qtd' (getItensParaCupom) ou 'quantidade' (outros contextos)
            $qtd = (int)($item['qtd'] ?? $item['quantidade'] ?? 1);
            for ($q = 0; $q < $qtd; $q++) {
                $unitarios[] = [
                    'produto_id' => (int)$item['produto_id'],
                    'preco'      => (float)$item['preco'],
                ];
            }
        }
        return $unitarios;
    }

    private function msgCompraGanhe(
        int   $comprar,
        int   $levar,
        float $pct,
        array $itensComDesconto,
        int   $qtdAtual = -1
    ): string {
        $paga  = $comprar - $levar;
        $label = $pct >= 100
            ? ($levar === 1 ? '1 item grátis' : "{$levar} itens grátis")
            : ($levar === 1 ? "1 item com {$pct}% off" : "{$levar} itens com {$pct}% off");

        if (!empty($itensComDesconto)) {
            // Promoção atingida — mostra o desconto
            return "Compre {$comprar} leve {$paga}: {$label} aplicado!";
        }

        // Ainda não atingida — mostra quantos faltam
        $falta = $comprar - max(0, $qtdAtual);
        $un    = $falta === 1 ? 'item' : 'itens';
        return "Adicione mais {$falta} {$un}: {$label} para você!";
    }

    // ── Cashback ────────────────────────────────────────

    /**
     * Cashback: não gera desconto imediato no checkout.
     * Registra o percentual e o valor prometido; o crédito real é
     * concedido via CashbackService 7 dias após o pedido ser entregue.
     *
     * Base de cálculo = subtotal dos itens elegíveis após desconto:
     *   subtotal_elegivel × (1 - descontoAcumulado/subtotal_total)
     * Onde descontoAcumulado = descontos já aplicados por outras promoções
     * na mesma rodada de avaliação, passado pelo loop de avaliarCarrinho().
     */
    private function avaliarCashback(
        array $promo,
        array $itensElegiveis,
        float $subtotal,
        float $descontoAcumulado
    ): ?array {
        $cfg        = $promo['configuracao'];
        $pct        = min(100, max(0.01, (float)($cfg['percentual']    ?? 5)));
        $validaDias = max(1, (int)($cfg['validade_dias'] ?? 90));

        if (empty($itensElegiveis)) return null;

        // Subtotal dos itens elegíveis (bruto)
        $subtotalElegivel = (float)array_sum(array_map(
            fn($i) => $i['preco'] * $i['qtd'],
            $itensElegiveis
        ));

        // Aplica proporção de desconto acumulado sobre os itens elegíveis
        $ratioDesconto    = $subtotal > 0 ? min(1, $descontoAcumulado / $subtotal) : 0;
        $baseCalculo      = round($subtotalElegivel * (1 - $ratioDesconto), 2);
        $cashbackValor    = round($baseCalculo * $pct / 100, 2);

        if ($cashbackValor <= 0) return null;

        return [
            'promocao_id'      => $promo['id'],
            'promocao_nome'    => $promo['nome'],
            'tipo'             => 'cashback',
            'desconto_produto' => 0.0,   // sem desconto no checkout
            'desconto_frete'   => 0.0,
            'brindes'          => [],
            'faixa_aplicada'   => null,
            'itens_elegiveis'  => array_unique(array_column($itensElegiveis, 'produto_id')),
            // campos específicos do cashback — lidos por CashbackService
            'cashback_pct'     => $pct,
            'cashback_base'    => $baseCalculo,
            'cashback_valor'   => $cashbackValor,
            'cashback_validade_dias' => $validaDias,
            'msg'              => number_format($pct, 1, ',', '') . '% de cashback ('
                                  . PriceHelper::format($cashbackValor)
                                  . ' em créditos 7 dias após entrega)',
        ];
    }

    /**
     * Card de preview do cashback para o carrinho.
     * Sempre mostra — o cashback não tem "estado" de atingido/não-atingido
     * como os outros tipos (ele sempre se aplica se os itens são elegíveis),
     * mas mostra se os itens elegíveis existem no carrinho.
     */
    private function cardCashback(array $promo, array $itensElegiveis, float $subtotal): ?array {
        $cfg      = $promo['configuracao'];
        $pct      = min(100, max(0.01, (float)($cfg['percentual'] ?? 5)));
        $validade = max(1, (int)($cfg['validade_dias'] ?? 90));

        // itensElegiveis já filtrados por previewCarrinho() — sem refiltrar aqui
        $subtotalElegivel = $this->calcularSubtotalItens($itensElegiveis);
        $cashbackEstimado = round($subtotalElegivel * $pct / 100, 2);

        return [
            'promocao_id'    => $promo['id'],
            'promocao_nome'  => $promo['nome'],
            'tipo'           => 'cashback',
            'estado'         => 'aplicada',  // se elegível, sempre aplicado
            'desconto'       => 0.0,
            'desconto_fmt'   => null,
            'cashback_valor' => $cashbackEstimado,
            'cashback_fmt'   => PriceHelper::format($cashbackEstimado),
            'cashback_pct'   => $pct,
            'validade_dias'  => $validade,
            'brindes'        => [],
            'progresso_pct'  => 100,
            'msg'            => number_format($pct, 1, ',', '') . '% de volta em créditos · '
                                . PriceHelper::format($cashbackEstimado)
                                . ' disponíveis em 7 dias após entrega · validade '
                                . $validade . ' dias',
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

        $in = implode(',', $ids);

        // Uma única query: marca + TODAS as categorias + preço real do produto.
        // preco_produto é buscado direto do cadastro para garantir que a
        // comparação de preços (selecionarMaisBaratos) use o valor correto —
        // o preco vindo do carrinho (valor_unitario) pode estar incorreto.
        $stmt = $this->db->query(
            "SELECT p.id   AS produto_id,
                    p.preco AS preco_produto,
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
                'preco_produto'  => $row['preco_produto'],
                // mantém categoria_id como a principal (primeiro da lista,
                // já ordenado por principal DESC no GROUP_CONCAT)
                'categoria_id'   => $categorias[0] ?? 0,
            ];
        }

        return array_map(function (array $item) use ($mapa): array {
            $pid = (int)$item['produto_id'];
            if (!isset($mapa[$pid])) return $item;

            if (empty($item['marca_id']))     $item['marca_id']     = $mapa[$pid]['marca_id'];
            if (empty($item['categoria_id'])) $item['categoria_id'] = $mapa[$pid]['categoria_id'];
            // categorias_ids sempre vem daqui — getItensParaCupom() não o fornece
            $item['categorias_ids'] = $mapa[$pid]['categorias_ids'];
            // Sobrescreve com o preço real do cadastro (fix do usuário):
            // valor_unitario no carrinho pode estar incorreto em alguns cenários
            $item['preco'] = $mapa[$pid]['preco_produto'];
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