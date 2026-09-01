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

    public function __construct(?BiService $bi = null)
    {
        $this->bi = $bi ?? new BiService();
    }

    public function getDashboardData(string $periodo = '30d'): array
    {
        $p = $this->bi->periodo($periodo);
        $k = $this->bi->kpis($p);

        return [
            'meta'    => $this->meta($p, $periodo),
            'kpis'    => $this->kpis($k, $p),
            'tables'  => $this->tables($p),
            'metrics' => $this->metrics($p),
            'charts'  => $this->charts($p),
            'saude'   => $this->bi->saude(),
        ];
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
            'trend_label' => 'vs. período anterior',
            'icon'  => $icone,
            'type'  => $tipo,
        ];
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

        return [
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

        return ['access' => $funil, 'ai' => $saude, 'recompra' => $recompra];
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
            'top_categories' => $this->bi->ranking($p, 'categoria', 8),
            'by_channel'     => $this->bi->ranking($p, 'canal', 8),
            'by_payment'     => $this->bi->porPagamento($p),
            'coorte'         => $this->bi->coorte(12),
            'recompra'       => $this->bi->recompra()['distribuicao'],
            'geo_uf'         => $this->bi->geografia($p, 'uf', 15),
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
