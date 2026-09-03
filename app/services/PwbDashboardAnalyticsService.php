<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/PwbDashboardAnalyticsService.php
// ════════════════════════════════════════════════════════

/**
 * Adaptador entre o BiService e o layout que já existia em
 * `admin/views/powerbi/pwb-dashboard.php`.
 *
 * A view referenciava esta classe desde sempre — ela nunca foi
 * escrita, e por isso o dashboard renderizava tabelas vazias. Aqui
 * ela existe, alimentada pelas views `bi_*`.
 *
 * Por que um adaptador em vez de reescrever a view: são 298 linhas de
 * layout pronto (grid, KPIs, tabelas, badges). Reaproveitar custa uma
 * classe de mapeamento; reescrever custaria o frontend inteiro e
 * jogaria fora trabalho que já estava feito.
 *
 * Toda formatação (R$, %, datas) mora aqui. O BiService devolve
 * número cru — misturar apresentação lá tornaria os mesmos valores
 * inúteis para o Power BI e para qualquer outro consumidor.
 */
final class PwbDashboardAnalyticsService
{
    private BiService $bi;

    /** Views que faltam neste ambiente, para a tela avisar. */
    private array $indisponivel = [];

    public function __construct(?BiService $bi = null)
    {
        $this->bi = $bi ?? new BiService();
    }

    public function getDashboardData(string $periodo = '30d'): array
    {
        $p = $this->bi->periodo($periodo);
        $k = $this->protegido(fn() => $this->bi->kpis($p), 'bi_fato_pedido / bi_fato_item', []);

        return [
            'meta'    => $this->meta($p, $periodo),
            'kpis'    => $k ? $this->kpis($k, $p) : [],
            'tables'  => $this->tables($p),
            'metrics' => $this->metrics($p),
            'charts'  => $this->charts($p),
            'saude'   => $this->protegido(fn() => $this->bi->saude(), 'bi_saude_dados', []),
            // Lista o que este ambiente não tem. Vazia = tudo no lugar.
            'indisponivel' => array_values(array_unique($this->indisponivel)),
        ];
    }

    /**
     * Executa um bloco e, se a VIEW não existir neste ambiente,
     * devolve o fallback em vez de derrubar a página.
     *
     * Por que isto existe: a camada semântica ganhou views novas ao
     * longo do projeto. Quem sobe os PHP sem reaplicar o
     * `sql/bi-fase2.sql` fica com o painel INTEIRO em erro 500 — uma
     * view de clips ausente levava a página de faturamento junto, e
     * nada na tela dizia o porquê.
     *
     * Só o erro "table or view not found" (SQLSTATE 42S02) é
     * absorvido. Qualquer outro problema de SQL continua estourando,
     * porque bug de consulta escondido é pior que página quebrada.
     */
    private function protegido(callable $fn, string $recurso, $fallback)
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            if (($e->getCode() ?: '') !== '42S02') throw $e;

            $this->indisponivel[] = $recurso;
            if (class_exists('LogService')) {
                LogService::warning('BI: view ausente no ambiente', [
                    'recurso' => $recurso,
                    'erro'    => $e->getMessage(),
                ], 'bi');
            }
            return $fallback;
        }
    }

    // ── Cabeçalho ────────────────────────────────────────
    private function meta(array $p, string $periodo): array
    {
        return [
            'title'      => 'Visão Geral',
            'subtitle'   => $p['label'] . ' · comparado com o período anterior equivalente',
            'period'     => $periodo,
            'updated_at' => date('d/m/Y H:i'),
        ];
    }

    // ── KPIs ─────────────────────────────────────────────
    // A view consome em dois blocos de 4 (slice 0-4 e 4-4), então a
    // ORDEM importa: os quatro primeiros são os de diretoria.
    private function kpis(array $k, array $p): array
    {
        return [
            $this->kpi('Faturamento',   $this->brl($k['faturamento']['valor']),  $k['faturamento'],  'chart', 'revenue'),
            $this->kpi('Pedidos',       $this->num($k['pedidos']['valor']),      $k['pedidos'],      'cart',  'orders'),
            $this->kpi('Ticket médio',  $this->brl($k['ticket_medio']['valor']), $k['ticket_medio'], 'chart', 'ticket'),
            $this->kpi('Clientes',      $this->num($k['clientes']['valor']),     $k['clientes'],     'users', 'customers'),

            $this->kpi('Itens vendidos', $this->num($k['itens']['valor']),        $k['itens'],       'box',   'items'),
            $this->kpi('Novos clientes', $this->num($k['novos_clientes']['valor']),$k['novos_clientes'],'users','new'),
            $this->kpi('Desconto dado',  $this->brl($k['desconto']['valor']),     $k['desconto'],    'chart', 'discount'),
            $this->lucroKpi($k),
        ];
    }

    /**
     * O KPI de lucro é o único que pode MENTIR, então é o único
     * tratado à parte.
     *
     * Se nenhum item vendido tem custo, "Lucro: R$ 0,00" seria lido
     * como "a loja não lucrou" quando o certo é "não dá para saber".
     * Nesse caso o card mostra um traço e diz por quê.
     */
    private function lucroKpi(array $k): array
    {
        $temCusto = $k['receita_com_custo']['valor'] > 0;

        if (!$temCusto) {
            return [
                'label' => 'Lucro bruto',
                'value' => '—',
                'trend' => null,
                'trend_label' => 'sem custo cadastrado',
                'icon'  => 'chart',
                'type'  => 'profit',
            ];
        }

        $cobertura = round(100 * $k['receita_com_custo']['valor']
                          / max(0.01, $k['faturamento']['valor']));

        return [
            'label' => 'Lucro bruto',
            'value' => $this->brl($k['lucro']['valor']),
            'trend' => $this->trend($k['lucro']),
            'trend_dir' => $this->direcao($k['lucro']),
            'trend_label' => "margem {$k['margem_pct']['valor']}% · sobre {$cobertura}% da receita",
            'icon'  => 'chart',
            'type'  => 'profit',
        ];
    }

    private function kpi(string $label, string $valor, array $m, string $icone, string $tipo): array
    {
        return [
            'label' => $label,
            'value' => $valor,
            'trend' => $this->trend($m),
            'trend_dir' => $this->direcao($m),
            'trend_label' => 'vs. período anterior',
            'icon'  => $icone,
            'type'  => $tipo,
        ];
    }

    /**
     * Direção da variação, para a tela colorir.
     *
     * NULL quando não há base de comparação — nesse caso a variação
     * nem é exibida, e pintar de verde ou vermelho sugeriria um
     * movimento que não existe.
     */
    private function direcao(array $m): ?string
    {
        if ($m['variacao'] === null || (float)$m['variacao'] == 0.0) return null;
        return (float)$m['variacao'] > 0 ? 'up' : 'down';
    }

    /** NULL vira '—': sem base de comparação não existe variação. */
    private function trend(array $m): ?string
    {
        if ($m['variacao'] === null) return null;
        return ($m['variacao'] >= 0 ? '+' : '') . $m['variacao'] . '%';
    }

    // ── Tabelas ──────────────────────────────────────────
    private function tables(array $p): array
    {
        $produtos = [];
        foreach ($this->bi->ranking($p, 'produto', 10) as $r) {
            $produtos[] = [
                'name'    => $r['nome'],
                'sku'     => (string)($r['id'] ?? '—'),
                'sales'   => $this->num((float)$r['qtd']),
                'revenue' => $this->brl((float)$r['receita']),
                // O layout usa este campo também como largura de barra
                // (0-100), então só um percentual cabe aqui — um estoque
                // de 340 unidades estouraria a barra. Vai a cobertura de
                // custo, e o cabeçalho da coluna na view diz isso
                // ("Custo conhecido"), em vez de prometer "Estoque".
                'stock'   => (string)(int)($r['cobertura_custo_pct'] ?? 0),
                'status'  => $r['margem_pct'] === null
                           ? 'Sem custo'
                           : ($r['margem_pct'] < 0 ? 'Prejuízo'
                             : ($r['margem_pct'] < 15 ? 'Margem baixa' : 'Saudável')),
            ];
        }

        $recentes = [];
        foreach ($this->bi->pedidosRecentes(10) as $r) {
            $recentes[] = [
                'order'    => $r['codigo'],
                'customer' => $r['cliente'],
                'value'    => $this->brl((float)$r['total']),
                'payment'  => ucfirst((string)$r['forma_pagamento']),
                'status'   => $this->rotuloClasse((string)$r['classe_bi']),
                'date'     => date('d/m/Y', strtotime((string)$r['criado_em'])),
            ];
        }

        $clientes = [];
        foreach ($this->bi->segmentosCliente($p) as $r) {
            $clientes[] = [
                'segment'   => $r['segmento'],
                'customers' => $this->num((float)$r['clientes']),
                'ticket'    => $this->brl((float)$r['ticket']),
                'retention' => $this->brl((float)$r['receita']),
            ];
        }

        $estoque = [];
        foreach ($this->bi->alertasEstoque(15) as $r) {
            $estoque[] = [
                'product' => $r['produto'] ?? '—',
                'sku'     => $r['sku_codigo'] ?? '—',
                'stock'   => (string)(int)$r['saldo'],
                'minimum' => (string)(int)($r['estoque_minimo'] ?? 0),
                'level'   => $r['situacao'] === 'zerado' ? 'Zerado' : 'Crítico',
            ];
        }

        $rfm = [];
        foreach ($this->bi->rfmResumo() as $r) {
            $rfm[] = [
                'segment'   => $r['segmento'],
                'customers' => $this->num((float)$r['clientes']),
                'revenue'   => $this->brl((float)$r['receita']),
                'ticket'    => $this->brl((float)$r['ticket']),
                'recency'   => (int)$r['recencia_media'] . ' dias',
            ];
        }

        $risco = [];
        foreach ($this->bi->clientesRisco(15) as $r) {
            $risco[] = [
                'name'    => $r['nome'] ?? '—',
                'orders'  => (string)(int)$r['compras'],
                'revenue' => $this->brl((float)$r['receita']),
                'days'    => (int)$r['dias_sem_comprar'] . ' dias',
                // "2,4x o normal" diz mais que "180 dias": o atraso é
                // relativo ao ritmo do próprio cliente.
                'overdue' => number_format((float)$r['vezes_o_normal'], 1, ',', '.') . 'x o normal',
            ];
        }

        // ── Onda C ────────────────────────────────────────
        $pagamentos = [];
        foreach ($this->bi->pagamentoAprovacao($p, 'metodo') as $r) {
            $pagamentos[] = [
                'method'   => ucfirst(str_replace('_', ' ', (string)$r['chave'])),
                'attempts' => $this->num((float)$r['tentativas']),
                'approved' => $this->num((float)$r['aprovadas']),
                'rate'     => number_format((float)$r['taxa_aprovacao'], 1, ',', '.') . '%',
                'value'    => $this->brl((float)$r['valor_aprovado']),
                // Vocabulário próprio: "Prejuízo" (que serve para margem)
                // não significa nada aplicado a taxa de aprovação.
                'level'    => $r['taxa_aprovacao'] >= 90 ? 'Saudável'
                            : ($r['taxa_aprovacao'] >= 70 ? 'Atenção' : 'Crítico'),
            ];
        }

        $cupons = [];
        foreach ($this->protegido(fn() => $this->bi->cupons($p, 15), 'bi_fato_cupom', []) as $r) {
            $cupons[] = [
                'code'     => $r['codigo'],
                'type'     => $r['tipo'],
                'uses'     => $this->num((float)$r['efetivados']),
                'clients'  => $this->num((float)$r['clientes']),
                'discount' => $this->brl((float)$r['desconto']),
                'revenue'  => $this->brl((float)$r['receita']),
                // Retorno < 1 = o desconto custou mais que a venda.
                'roi'      => $r['retorno'] === null
                            ? '—' : number_format((float)$r['retorno'], 1, ',', '.') . 'x',
                'level'    => $r['retorno'] === null ? 'Sem custo'
                            : ((float)$r['retorno'] < 3 ? 'Margem baixa' : 'Saudável'),
            ];
        }

        $giro = [];
        foreach ($this->bi->giroEstoque(90, 25) as $r) {
            $giro[] = [
                'product'  => $r['produto'] ?? '—',
                'brand'    => $r['marca_nome'] ?? '—',
                'stock'    => (string)(int)$r['saldo'],
                'sold'     => (string)(int)$r['vendido'],
                'daily'    => number_format((float)$r['media_diaria'], 2, ',', '.'),
                // Dias de cobertura diz mais que unidades: "40 peças"
                // não significa nada sem o ritmo de saída.
                'coverage' => $r['dias_cobertura'] === null
                            ? '—' : (int)$r['dias_cobertura'] . ' dias',
                'level'    => $r['classificacao'],
            ];
        }

        $parado = [];
        foreach ($this->bi->estoqueParado(90, 20) as $r) {
            $parado[] = [
                'product' => $r['produto'] ?? '—',
                'stock'   => (string)(int)$r['saldo'],
                'value'   => $r['valor_estoque'] === null
                           ? '— (sem custo)' : $this->brl((float)$r['valor_estoque']),
                'since'   => $r['ultima_venda']
                           ? (int)$r['dias_sem_vender'] . ' dias' : 'nunca vendeu',
            ];
        }

        $devol = [];
        foreach ($this->bi->devolucoes($p) as $r) {
            $devol[] = [
                'reason' => $r['motivo'],
                'type'   => ucfirst((string)$r['tipo']),
                'units'  => $this->num((float)$r['unidades']),
                'value'  => $this->brl((float)$r['valor']),
            ];
        }

        $cancel = [];
        foreach ($this->bi->cancelamentos($p) as $r) {
            $cancel[] = [
                'reason' => $r['motivo'],
                'orders' => $this->num((float)$r['pedidos']),
                'value'  => $this->brl((float)$r['valor']),
            ];
        }

        $transp = [];
        foreach ($this->bi->transportadoras($p) as $r) {
            $transp[] = [
                'carrier'  => $r['transportadora'],
                'shipments'=> $this->num((float)$r['envios']),
                'cost'     => $r['custo_real_medio'] === null
                            ? '—' : $this->brl((float)$r['custo_real_medio']),
                'days'     => $r['dias_entrega'] === null
                            ? '—' : number_format((float)$r['dias_entrega'], 1, ',', '.'),
                'delivered'=> number_format((float)$r['pct_entregue'], 0) . '%',
            ];
        }

        // ── Onda D ────────────────────────────────────────
        $abc = [];
        foreach (array_slice($this->bi->curvaABC($p, 'produto', 60), 0, 30) as $r) {
            $abc[] = [
                'position'   => (string)$r['posicao'],
                'name'       => $r['nome'],
                'revenue'    => $this->brl((float)$r['receita']),
                'share'      => number_format((float)$r['pct_receita'], 2, ',', '.') . '%',
                'cumulative' => number_format((float)$r['pct_acumulado'], 1, ',', '.') . '%',
                'profit'     => $r['lucro'] === null ? '— (sem custo)' : $this->brl((float)$r['lucro']),
                'class'      => $r['classe'],
            ];
        }

        $canais = [];
        foreach ($this->bi->ranking($p, 'canal', 10) as $r) {
            $canais[] = [
                'channel'  => $r['nome'],
                'revenue'  => $this->brl((float)$r['receita']),
                'orders'   => $this->num((float)$r['pedidos']),
                'clients'  => $this->num((float)$r['clientes']),
                'ticket'   => $this->brl((float)$r['pedidos'] > 0 ? (float)$r['receita'] / (float)$r['pedidos'] : 0),
                'margin'   => $r['margem_pct'] === null
                            ? '— (sem custo)' : number_format((float)$r['margem_pct'], 1, ',', '.') . '%',
            ];
        }

        $metas = [];
        foreach ($this->bi->metas($p) as $m) {
            $metas[] = [
                'metric'   => $m['metrica'],
                'target'   => $m['alvo_label'] ?? 'Loja inteira',
                'period'   => date('d/m/y', strtotime($m['periodo_ini'])) . ' – '
                            . date('d/m/y', strtotime($m['periodo_fim'])),
                'goal'     => $this->brl((float)$m['valor_meta']),
                'done'     => $this->brl((float)$m['realizado']),
                'pct'      => $m['pct'] === null ? '—' : number_format((float)$m['pct'], 1, ',', '.') . '%',
                'missing'  => $this->brl((float)$m['falta']),
                // Só a dimensão 'loja' tem realizado implementado; as
                // outras devolvem 0 de propósito, e dizer isso evita
                // que "0%" seja lido como "não vendeu nada".
                'level'    => $m['dimensao'] !== 'loja' ? 'Não calculado'
                            : ((float)($m['pct'] ?? 0) >= 100 ? 'Saudável'
                              : ((float)($m['pct'] ?? 0) >= 70 ? 'Atenção' : 'Crítico')),
            ];
        }

        $alta = [];
        foreach ($this->bi->tendenciaProdutos(30, 'alta', 8) as $r) {
            $alta[] = ['name' => $r['nome_produto'],
                       'now' => $this->brl((float)$r['receita_atual']),
                       'before' => $this->brl((float)$r['receita_anterior']),
                       'change' => '+' . number_format((float)$r['variacao_pct'], 0) . '%'];
        }
        $queda = [];
        foreach ($this->bi->tendenciaProdutos(30, 'queda', 8) as $r) {
            $queda[] = ['name' => $r['nome_produto'],
                        'now' => $this->brl((float)$r['receita_atual']),
                        'before' => $this->brl((float)$r['receita_anterior']),
                        'change' => number_format((float)$r['variacao_pct'], 0) . '%'];
        }

        $alertas = [];
        foreach ($this->bi->alertas($p) as $a) {
            $alertas[] = [
                'level'  => ['critico'=>'Crítico','alto'=>'Alto',
                             'medio'=>'Médio','informativo'=>'Informativo'][$a['nivel']] ?? $a['nivel'],
                'title'  => $a['titulo'],
                'detail' => $a['detalhe'],
            ];
        }

        $freteTipo = [];
        foreach ($this->protegido(fn() => $this->bi->fretePorTipo($p), 'bi_fato_frete', []) as $r) {
            $freteTipo[] = [
                'type'    => $r['tipo_frete'],
                'labels'  => $this->num((float)$r['etiquetas']),
                // Avulso sem pedido é o esperado; dizer "0" ali sugeriria
                // falha onde não há.
                'linked'  => $r['tipo_frete'] === 'Avulso'
                           ? 'n/a (frete direto)' : $this->num((float)$r['com_pedido']),
                'tracked' => $this->num((float)$r['com_rastreio']),
                'cost'    => $r['custo_real_medio'] === null
                           ? '— (valor_postado vazio)' : $this->brl((float)$r['custo_real_medio']),
            ];
        }

        $clips = [];
        foreach ($this->protegido(fn() => $this->bi->clips($p, 30), 'bi_fato_clip', []) as $r) {
            $clips[] = [
                'title'    => $r['titulo'],
                'author'   => $r['autor_nome'] ?? '—',
                'product'  => $r['produto'] ?? '—',
                'products' => $this->num((float)$r['produtos_vinculados']),
                'views'    => $this->num((float)$r['views_unicas']),
                'likes'    => $this->num((float)$r['likes']),
                'comments' => $this->num((float)$r['comentarios']),
                // Sem view não existe taxa — 0% ali sugeriria rejeição.
                'rate'     => $r['taxa_like'] === null
                            ? '—' : number_format((float)$r['taxa_like'], 1, ',', '.') . '%',
                'revenue'  => $this->brl((float)$r['receita_produto']),
                'status'   => $r['ativo'] ? ucfirst((string)$r['status']) : 'Inativo',
            ];
        }

        $sharers = [];
        foreach ($this->protegido(fn() => $this->bi->compartilhadores($p, 25), 'bi_fato_compartilhamento', []) as $r) {
            $sharers[] = [
                'name'     => $r['compartilhador'],
                'origin'   => ucfirst((string)$r['origem']),
                'shares'   => $this->num((float)$r['compartilhamentos']),
                'items'    => $this->num((float)$r['itens']),
                'value'    => $this->brl((float)$r['valor_compartilhado']),
                'views'    => $this->num((float)$r['visualizacoes']),
                'carts'    => $this->num((float)$r['carrinhos_criados']),
                'conv'     => $this->num((float)$r['conversoes']),
                // Separadas de propósito: `conversoes` é o evento, que
                // funciona; `pedidos_identificados` depende do
                // uso.pedido_id, que hoje é sempre NULL.
                'orders'   => (int)$r['pedidos_identificados'] > 0
                            ? $this->num((float)$r['pedidos_identificados'])
                            : '— (sem vínculo)',
                'rate'     => $r['taxa_conversao'] === null
                            ? '—' : number_format((float)$r['taxa_conversao'], 1, ',', '.') . '%',
                'level'    => (float)$r['taxa_conversao'] >= 20 ? 'Saudável'
                            : ((float)$r['taxa_conversao'] > 0 ? 'Atenção' : 'Crítico'),
            ];
        }

        $quem = [];
        foreach ($this->protegido(fn() => $this->bi->quemResponde($p, 20), 'bi_fato_pergunta', []) as $r) {
            $quem[] = [
                'who'      => $r['quem'],
                'source'   => $r['fonte'] === 'ia' ? 'IA' : 'Pessoa',
                'answers'  => $this->num((float)$r['respostas']),
                'useful'   => $this->num((float)$r['votos_uteis']),
                'per'      => number_format((float)$r['uteis_por_resposta'], 2, ',', '.'),
                'time'     => $r['minutos_medio'] === null ? '—'
                            : ((int)$r['minutos_medio'] === 0 ? 'imediato' : (int)$r['minutos_medio'] . ' min'),
                'size'     => $this->num((float)$r['tam_medio']) . ' car.',
            ];
        }

        $ia = [];
        foreach ($this->protegido(fn() => $this->bi->iaPorModelo($p, null, 20), 'bi_fato_ia', []) as $r) {
            $ia[] = [
                'provider' => $r['provedor'],
                'model'    => $r['modelo'],
                'type'     => $r['tipo'] ?? '—',
                'runs'     => $this->num((float)$r['execucoes']),
                'ok'       => $this->num((float)$r['concluidas']),
                'fails'    => $this->num((float)$r['falhas']),
                'rate'     => number_format((float)$r['taxa_sucesso'], 1, ',', '.') . '%',
                // Falha não custou: o real é o que saiu da conta.
                'cost'     => 'US$ ' . number_format((float)$r['custo_usd'], 4, ',', '.'),
                'ms'       => $r['ms_medio'] === null ? '—' : $this->num((float)$r['ms_medio']) . ' ms',
                'level'    => (float)$r['taxa_sucesso'] >= 95 ? 'Saudável'
                            : ((float)$r['taxa_sucesso'] >= 70 ? 'Atenção' : 'Crítico'),
            ];
        }

        $perProduto = [];
        foreach ($this->protegido(fn() => $this->bi->perguntasPorProduto($p, 15), 'bi_fato_pergunta', []) as $r) {
            $perProduto[] = [
                'product'  => $r['produto'],
                'brand'    => $r['marca_nome'] ?? '—',
                'asked'    => $this->num((float)$r['perguntas']),
                'answered' => $this->num((float)$r['respondidas']),
                'queue'    => (int)$r['na_fila'] > 0 ? $this->num((float)$r['na_fila']) : '—',
                'useful'   => $this->num((float)$r['votos_uteis']),
            ];
        }

        return [
            'quem_responde' => $quem,
            'ia_modelos'    => $ia,
            'perguntas_produto' => $perProduto,
            'clips'         => $clips,
            'compartilhadores' => $sharers,
            'marcas'        => $this->protegido(fn() => $this->dimensaoTabela($p, 'marca'), 'bi_fato_item (marca)', []),
            'categorias'    => $this->protegido(fn() => $this->dimensaoTabela($p, 'categoria'), 'bi_fato_item (categoria)', []),
            'frete_tipo'    => $freteTipo,
            'abc'           => $abc,
            'canais'        => $canais,
            'metas'         => $metas,
            'alta'          => $alta,
            'queda'         => $queda,
            'alertas'       => $alertas,
            'pagamentos'    => $pagamentos,
            'cupons'        => $cupons,
            'giro'          => $giro,
            'parado'        => $parado,
            'devolucoes'    => $devol,
            'cancelamentos' => $cancel,
            'transportadoras' => $transp,
            'rfm'           => $rfm,
            'risco'         => $risco,
            'geo_uf'        => $this->geo($p, 'uf', 27),
            'geo_cidade'    => $this->geo($p, 'cidade', 20),
            'top_products'  => $produtos,
            'recent_orders' => $recentes,
            'customers'     => $clientes,
            'stock_alerts'  => $estoque,
            // 'faq' não é alimentado: perguntas frequentes não são
            // domínio do BI comercial. A seção fica vazia em vez de
            // receber dado de outro assunto só para não parecer vazia.
            'faq'           => [],
        ];
    }

    // ── Cards de métrica ─────────────────────────────────
    private function metrics(array $p): array
    {
        // A seção "Acessos" do layout recebe o FUNIL — é o mais
        // próximo de tráfego que existe de verdade. Não há tabela de
        // visitas nem GA4, então o funil começa em ViewContent e não
        // em "Visitas": mostrar um total de visitas inventado seria
        // pior que não mostrar nada.
        $funil = [];
        foreach ($this->bi->funil($p) as $f) {
            $funil[] = [
                'label' => $this->rotuloEtapa((string)$f['etapa']),
                'value' => $this->num((float)$f['pessoas']),
                'hint'  => $f['conversao'] === null
                         ? 'topo medido (visitas não são rastreadas)'
                         : $f['conversao'] . '% da etapa anterior',
            ];
        }

        // A seção "Uso de IA" recebe a SAÚDE DO DADO — o indicador
        // mais importante do painel: sobre quanto da operação estes
        // números podem ser afirmados.
        $saude = [];
        foreach ($this->bi->saude() as $s) {
            $saude[] = [
                'label' => $s['descricao'],
                'value' => (($s['pct'] ?? 0) + 0) . '%',
                'hint'  => $s['preenchido'] . ' de ' . $s['total'],
            ];
        }

        $rec = $this->bi->recompra();
        $recompra = [
            [
                'label' => 'Taxa de recompra',
                'value' => number_format($rec['taxa_recompra'], 1, ',', '.') . '%',
                'hint'  => 'de ' . $rec['total_clientes'] . ' clientes',
            ],
            [
                'label' => 'Tempo até a 2ª compra',
                // NULL quando ninguém comprou duas vezes ainda —
                // exibir "0 dias" sugeriria recompra instantânea.
                // 0 dias é resultado legítimo (comprou duas vezes no
                // mesmo dia), mas "0 dias" lê-se como dado faltando.
                'value' => $rec['dias_2a_compra'] === null ? '—'
                         : ((int)$rec['dias_2a_compra'] === 0
                            ? 'mesmo dia'
                            : (int)$rec['dias_2a_compra'] . ' dias'),
                'hint'  => $rec['base_2a_compra'] > 0
                         ? 'média de ' . $rec['base_2a_compra'] . ' clientes'
                         : 'ninguém comprou 2x ainda',
            ],
        ];

        $c   = $this->bi->carrinhos($p);
        $car = [
            ['label' => 'Carrinhos abandonados',
             'value' => $this->num((float)($c['kpi']['carrinhos'] ?? 0)),
             'hint'  => $this->brl((float)($c['kpi']['valor_abandonado'] ?? 0)) . ' em aberto'],
            ['label' => 'Ticket abandonado',
             'value' => $this->brl((float)($c['kpi']['ticket'] ?? 0)),
             'hint'  => 'média por carrinho'],
            ['label' => 'Taxa de recuperação',
             'value' => number_format((float)($c['kpi']['taxa_recuperacao'] ?? 0), 1, ',', '.') . '%',
             'hint'  => (int)($c['kpi']['recuperados'] ?? 0) . ' recuperados'],
            ['label' => 'Receita recuperada',
             'value' => $this->brl((float)($c['kpi']['valor_recuperado'] ?? 0)),
             'hint'  => (float)($c['kpi']['valor_recuperado'] ?? 0) > 0
                      ? 'confirmada' : 'reconciliação nunca marcou nada — ver 04-bugs'],
        ];

        $pr  = $this->bi->projecaoMes();
        $par = $this->bi->pareto($p, 'produto');

        $proj = [
            ['label' => 'Realizado no mês',
             'value' => $this->brl((float)$pr['realizado']),
             'hint'  => $pr['dias_passados'] . ' de ' . $pr['dias_no_mes'] . ' dias'],
            ['label' => 'Cenário conservador',
             'value' => $this->brl((float)$pr['conservador']), 'hint' => 'run-rate menos a variação diária'],
            ['label' => 'Cenário provável',
             'value' => $this->brl((float)$pr['provavel']),
             'hint'  => 'confiança ' . $pr['confianca'] . ' · ' . $pr['aviso']],
            ['label' => 'Cenário otimista',
             'value' => $this->brl((float)$pr['otimista']), 'hint' => 'run-rate mais a variação diária'],
        ];

        $conc = [
            ['label' => 'Concentração 80/20',
             'value' => $par['itens_80'] . ' de ' . $par['itens'],
             'hint'  => $par['pct_itens'] . '% dos produtos fazem 80% da receita'],
        ];

        $rc = $this->protegido(fn() => $this->bi->clipsResumo($p), 'bi_fato_clip', ['views'=>0,'sessoes'=>0,'clips'=>0,'ativos'=>0,'likes'=>0,'comentarios'=>0]);
        $clipKpi = [
            ['label' => 'Views no período', 'value' => $this->num((float)$rc['views']),
             'hint'  => $rc['sessoes'] . ' sessões distintas'],
            ['label' => 'Clips publicados', 'value' => $this->num((float)$rc['clips']),
             'hint'  => $rc['ativos'] . ' ativos'],
            ['label' => 'Curtidas',    'value' => $this->num((float)$rc['likes']),     'hint' => 'no acervo todo'],
            ['label' => 'Comentários', 'value' => $this->num((float)$rc['comentarios']),'hint' => 'no acervo todo'],
        ];

        $cs = $this->protegido(fn() => $this->bi->compartilhamentos($p), 'bi_fato_compartilhamento', ['kpi'=>['compartilhamentos'=>0,'valor_compartilhado'=>0,'visualizacoes'=>0,'conversoes'=>0,'pedidos_identificados'=>0,'receita'=>0],'funil'=>[]]);
        $shareKpi = [
            ['label' => 'Compartilhamentos', 'value' => $this->num((float)$cs['kpi']['compartilhamentos']),
             'hint'  => $this->brl((float)$cs['kpi']['valor_compartilhado']) . ' em carrinhos'],
            ['label' => 'Visualizações', 'value' => $this->num((float)$cs['kpi']['visualizacoes']),
             'hint'  => 'sessões distintas'],
            ['label' => 'Conversões', 'value' => $this->num((float)$cs['kpi']['conversoes']),
             'hint'  => 'eventos de pedido finalizado'],
            ['label' => 'Receita atribuída',
             // Zero aqui não é "não vendeu": é "não dá para saber".
             'value' => (float)$cs['kpi']['receita'] > 0
                      ? $this->brl((float)$cs['kpi']['receita']) : '—',
             'hint'  => (float)$cs['kpi']['receita'] > 0
                      ? (int)$cs['kpi']['pedidos_identificados'] . ' pedidos'
                      : 'uso.pedido_id nunca é gravado — ver 04-bugs'],
        ];

        $q = $this->protegido(fn() => $this->bi->perguntasResumo($p), 'bi_fato_pergunta', []);
        $perguntaKpi = $q ? [
            ['label' => 'Perguntas recebidas', 'value' => $this->num((float)$q['perguntas']),
             // Zero no período com total geral > 0 não é painel quebrado:
             // é filtro. Dizer isso evita a caça a um bug que não existe.
             'hint'  => (int)$q['perguntas'] === 0 && (int)$q['total_geral'] > 0
                      ? (int)$q['total_geral'] . ' no total, entre '
                        . date('d/m/y', strtotime((string)$q['primeira'])) . ' e '
                        . date('d/m/y', strtotime((string)$q['ultima']))
                        . ' — amplie o período'
                      : $q['pct_resposta'] . '% respondidas'],
            ['label' => 'Respondidas por IA', 'value' => $this->num((float)$q['por_ia']),
             'hint'  => $q['pct_ia'] . '% das respostas · ' . (int)$q['por_admin'] . ' pelo time'],
            // A fila é o número que mais importa: pergunta sem resposta
            // é cliente esperando na loja.
            ['label' => 'Na fila da IA', 'value' => $this->num((float)$q['fila_ia']),
             'hint'  => (int)$q['fila_ia'] > 0
                      ? 'aguardando resposta — verifique o worker'
                      : 'fila limpa'],
            ['label' => 'Votos "útil"', 'value' => $this->num((float)$q['votos_uteis']),
             // Não existe voto negativo, então isto NÃO é satisfação.
             'hint'  => 'endosso, não satisfação — não existe voto negativo'],
        ] : [];

        return ['access' => $funil, 'ai' => $saude, 'perguntas' => $perguntaKpi,
                'clips' => $clipKpi, 'share' => $shareKpi,
                'recompra' => $recompra, 'carrinho' => $car,
                'projecao' => $proj, 'concentracao' => $conc];
    }

    /**
     * Marca ou categoria formatada — mesma estrutura para as duas.
     *
     * `share` (participação) é o número que diz se a líder representa
     * 12% ou 70% do negócio, e essa é a diferença entre diversificação
     * e dependência. Um ranking de receita sozinho não conta isso.
     */
    private function dimensaoTabela(array $p, string $dim): array
    {
        $out = [];
        foreach ($this->bi->porMarca($p, $dim, 40) as $r) {
            $cresc = $r['crescimento_pct'];
            $out[] = [
                'id'       => (string)($r['id'] ?? ''),
                'name'     => $r['nome'],
                'revenue'  => $this->brl((float)$r['receita']),
                'share'    => number_format((float)$r['participacao_pct'], 1, ',', '.') . '%',
                'qty'      => $this->num((float)$r['qtd']),
                'orders'   => $this->num((float)$r['pedidos']),
                'clients'  => $this->num((float)$r['clientes']),
                'products' => $this->num((float)$r['produtos']),
                'ticket'   => $this->brl((float)$r['pedidos'] > 0
                                ? (float)$r['receita'] / (float)$r['pedidos'] : 0),
                'margin'   => $r['margem_pct'] === null
                            ? '— (sem custo)' : number_format((float)$r['margem_pct'], 1, ',', '.') . '%',
                // Sem período anterior não houve crescimento: houve
                // estreia. "+100%" ali seria leitura falsa.
                'growth'   => $cresc === null
                            ? '—' : ($cresc >= 0 ? '+' : '') . number_format((float)$cresc, 1, ',', '.') . '%',
                'growth_level' => $cresc === null ? 'Sem base'
                                : ($cresc > 0 ? 'Saudável' : ($cresc < 0 ? 'Crítico' : 'Atenção')),
            ];
        }
        return $out;
    }

    /**
     * Geografia formatada. `trust_level` vira classe de badge para a
     * tela mostrar em cor quando o dado é frágil — geografia vinda do
     * cadastro atual do cliente sofre drift (ver Fase 0).
     */
    private function geo(array $p, string $nivel, int $limite): array
    {
        $out = [];
        foreach ($this->bi->geografia($p, $nivel, $limite) as $r) {
            $conf = (int)($r['confiabilidade_pct'] ?? 0);
            $out[] = [
                'local'      => $r['local'],
                'orders'     => $this->num((float)$r['pedidos']),
                'customers'  => $this->num((float)$r['clientes']),
                'revenue'    => $this->brl((float)$r['receita']),
                'ticket'     => $this->brl((float)$r['ticket']),
                'freight'    => $this->brl((float)$r['frete_medio']),
                'trust'      => $conf . '%',
                'trust_level'=> $conf >= 80 ? 'Saudável' : ($conf >= 30 ? 'Margem baixa' : 'Prejuízo'),
            ];
        }
        return $out;
    }

    // ── Séries para gráfico ──────────────────────────────
    // Publicadas no payload JSON da página. O layout tem os <canvas>
    // mas nenhum JS ainda — quando ele existir, os dados já estão lá.
    private function charts(array $p): array
    {
        return [
            'daily'          => $this->bi->serieDiaria($p),
            'monthly'        => $this->bi->serieMensal(12),
            'by_status'      => $this->bi->porStatus($p),
            'top_brands'     => $this->bi->ranking($p, 'marca', 8),
            'marcas_share'   => $this->protegido(fn() => array_slice($this->bi->porMarca($p, 'marca', 10), 0, 10), 'bi_fato_item (marca)', []),
            'ia_tipos'       => $this->protegido(fn() => $this->bi->iaPorTipo($p), 'bi_fato_ia', []),
            'clips_serie'    => $this->protegido(fn() => $this->bi->clipsSerie($p), 'bi_fato_clip_view', []),
            'clips_top'      => $this->protegido(fn() => $this->bi->clips($p, 8), 'bi_fato_clip', []),
            'share_funil'    => $this->protegido(fn() => $this->bi->compartilhamentos($p)['funil'], 'bi_fato_compartilhamento', []),
            'top_categories' => $this->bi->ranking($p, 'categoria', 8),
            'by_channel'     => $this->bi->ranking($p, 'canal', 8),
            'by_payment'     => $this->bi->porPagamento($p),
            'coorte'         => $this->bi->coorte(12),
            'recompra'       => $this->bi->recompra()['distribuicao'],
            'geo_uf'         => $this->bi->geografia($p, 'uf', 15),
            'parcelas'       => $this->bi->parcelas($p),
            'recusas'        => $this->bi->recusas($p, 8),
            'desconto'       => $this->bi->impactoDesconto($p),
            'devolvidos'     => $this->bi->produtosDevolvidos($p, 10),
            'carrinho_status'=> $this->bi->carrinhos($p)['por_status'],
            'insights'       => $this->bi->insights($p),
        ];
    }

    // ── Formatação ───────────────────────────────────────
    private function brl(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    private function num(float $v): string
    {
        return number_format($v, 0, ',', '.');
    }

    private function rotuloClasse(string $classe): string
    {
        return [
            'venda'        => 'Concluído',
            'pre_venda'    => 'Pendente',
            'cancelamento' => 'Cancelado',
            'devolucao'    => 'Devolvido',
        ][$classe] ?? 'Pendente';
    }

    private function rotuloEtapa(string $e): string
    {
        return [
            'ViewContent'      => 'Viu produto',
            'AddToCart'        => 'Adicionou ao carrinho',
            'InitiateCheckout' => 'Iniciou checkout',
            'Purchase'         => 'Comprou',
        ][$e] ?? $e;
    }
}
