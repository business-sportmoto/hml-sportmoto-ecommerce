<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/BiService.php
// ════════════════════════════════════════════════════════

/**
 * Núcleo analítico do BI.
 *
 * Lê EXCLUSIVAMENTE as views `bi_*`. Nenhuma query aqui toca tabela
 * de domínio direto — se precisar de um campo que a view não expõe,
 * o certo é acrescentar na view (sql/bi-fase2.sql), não furar a
 * camada aqui. É essa disciplina que mantém Power BI e painel PHP
 * devolvendo o mesmo número.
 *
 * Faturamento SEMPRE por `venda_valida` (derivado de
 * pedido_status.classe_bi). Nenhum slug de status é escrito aqui.
 */
final class BiService
{
    private PDO $db;

    /** Períodos aceitos → dias. '12m' vira 365. */
    public const PERIODOS = [
        '7d'  => 7,
        '30d' => 30,
        '90d' => 90,
        '12m' => 365,
    ];

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // PERÍODO
    // ════════════════════════════════════════════════════

    /**
     * Resolve o período e o período ANTERIOR EQUIVALENTE.
     *
     * O anterior tem exatamente a mesma duração e termina no dia
     * anterior ao início — comparar 30 dias com "o mês passado"
     * (28 a 31 dias) produziria variação percentual falsa.
     *
     * @return array{ini:string,fim:string,ini_ant:string,fim_ant:string,dias:int,label:string}
     */
    public function periodo(string $chave = '30d'): array
    {
        $dias = self::PERIODOS[$chave] ?? 30;

        $fim = new DateTimeImmutable('today');
        $ini = $fim->modify('-' . ($dias - 1) . ' days');

        $fimAnt = $ini->modify('-1 day');
        $iniAnt = $fimAnt->modify('-' . ($dias - 1) . ' days');

        return [
            'ini'     => $ini->format('Y-m-d'),
            'fim'     => $fim->format('Y-m-d'),
            'ini_ant' => $iniAnt->format('Y-m-d'),
            'fim_ant' => $fimAnt->format('Y-m-d'),
            'dias'    => $dias,
            'label'   => $dias >= 365 ? 'Últimos 12 meses' : "Últimos {$dias} dias",
        ];
    }

    // ════════════════════════════════════════════════════
    // KPIs
    // ════════════════════════════════════════════════════

    /**
     * KPIs do período + variação contra o período anterior.
     *
     * Cada métrica volta como ['valor'=>, 'anterior'=>, 'variacao'=>].
     * `variacao` é NULL quando o período anterior é zero — dividir por
     * zero devolveria "+∞%" ou "+100%", que numa tela de diretoria
     * lê-se como crescimento real quando na verdade é ausência de
     * base de comparação.
     */
    public function kpis(array $p): array
    {
        $atual    = $this->agregados($p['ini'], $p['fim']);
        $anterior = $this->agregados($p['ini_ant'], $p['fim_ant']);

        $out = [];
        foreach ($atual as $k => $v) {
            $ant = (float)($anterior[$k] ?? 0);
            $out[$k] = [
                'valor'    => $v,
                'anterior' => $ant,
                'variacao' => $ant > 0 ? round((($v - $ant) / $ant) * 100, 1) : null,
            ];
        }
        return $out;
    }

    /**
     * Os números crus de um intervalo. Uma passada por fato.
     */
    private function agregados(string $ini, string $fim): array
    {
        // Grão de pedido: faturamento, pedidos, clientes, frete, desconto.
        $ped = $this->um(
            "SELECT
                COALESCE(SUM(total),0)                    AS faturamento,
                COUNT(*)                                  AS pedidos,
                COUNT(DISTINCT cliente_id)                AS clientes,
                COALESCE(SUM(frete_cobrado),0)            AS frete,
                COALESCE(SUM(desconto),0)                 AS desconto,
                COALESCE(AVG(total),0)                    AS ticket_medio
               FROM bi_fato_pedido
              WHERE venda_valida = 1 AND data BETWEEN ? AND ?",
            [$ini, $fim]
        );

        // Grão de item: quantidade, CMV e lucro.
        // O lucro só soma itens COM custo — somar NULL como zero
        // inventaria margem que não se sabe se existe.
        $item = $this->um(
            "SELECT
                COALESCE(SUM(quantidade),0)               AS itens,
                COALESCE(SUM(cmv),0)                      AS cmv,
                COALESCE(SUM(lucro),0)                    AS lucro,
                COALESCE(SUM(CASE WHEN custo_unitario IS NOT NULL
                                  THEN receita ELSE 0 END),0) AS receita_com_custo
               FROM bi_fato_item
              WHERE venda_valida = 1 AND data BETWEEN ? AND ?",
            [$ini, $fim]
        );

        // Cancelamento e devolução saem de classe_bi, não de slug.
        $desf = $this->um(
            "SELECT
                SUM(classe_bi = 'cancelamento')                                AS cancelados,
                SUM(classe_bi = 'devolucao')                                   AS devolvidos,
                COALESCE(SUM(CASE WHEN classe_bi='cancelamento' THEN total END),0) AS valor_cancelado,
                COALESCE(SUM(CASE WHEN classe_bi='devolucao'    THEN total END),0) AS valor_devolvido,
                COUNT(*)                                                       AS total_pedidos
               FROM bi_fato_pedido
              WHERE data BETWEEN ? AND ?",
            [$ini, $fim]
        );

        // Novos = primeira compra dentro do período.
        $novos = (int)$this->valor(
            "SELECT COUNT(*) FROM (
                SELECT cliente_id, MIN(data) AS primeira
                  FROM bi_fato_pedido WHERE venda_valida = 1
                 GROUP BY cliente_id
             ) x WHERE x.primeira BETWEEN ? AND ?",
            [$ini, $fim]
        );

        $totalPed = max(1, (int)$desf['total_pedidos']);
        $clientes = (int)$ped['clientes'];

        return [
            'faturamento'        => (float)$ped['faturamento'],
            'pedidos'            => (int)$ped['pedidos'],
            'itens'              => (int)$item['itens'],
            'clientes'           => $clientes,
            'novos_clientes'     => $novos,
            'recorrentes'        => max(0, $clientes - $novos),
            'ticket_medio'       => (float)$ped['ticket_medio'],
            'frete'              => (float)$ped['frete'],
            'desconto'           => (float)$ped['desconto'],
            'cmv'                => (float)$item['cmv'],
            'lucro'              => (float)$item['lucro'],
            'receita_com_custo'  => (float)$item['receita_com_custo'],
            'margem_pct'         => $item['receita_com_custo'] > 0
                                  ? round(100 * $item['lucro'] / $item['receita_com_custo'], 1) : 0.0,
            'cancelados'         => (int)$desf['cancelados'],
            'devolvidos'         => (int)$desf['devolvidos'],
            'valor_cancelado'    => (float)$desf['valor_cancelado'],
            'valor_devolvido'    => (float)$desf['valor_devolvido'],
            'taxa_cancelamento'  => round(100 * (int)$desf['cancelados'] / $totalPed, 1),
            'taxa_devolucao'     => round(100 * (int)$desf['devolvidos'] / $totalPed, 1),
        ];
    }

    // ════════════════════════════════════════════════════
    // SÉRIES E RANKINGS
    // ════════════════════════════════════════════════════

    /**
     * Série diária. Usa bi_dim_data como espinha para que dias SEM
     * venda existam como zero — sem isso o gráfico "pula" o dia ruim
     * e a linha parece contínua quando não é.
     */
    public function serieDiaria(array $p): array
    {
        return $this->todos(
            "SELECT d.data,
                    COALESCE(SUM(f.total),0) AS faturamento,
                    COUNT(f.pedido_id)       AS pedidos
               FROM bi_dim_data d
               LEFT JOIN bi_fato_pedido f
                      ON f.data = d.data AND f.venda_valida = 1
              WHERE d.data BETWEEN ? AND ?
              GROUP BY d.data
              ORDER BY d.data",
            [$p['ini'], $p['fim']]
        );
    }

    /** Faturamento por mês, para a série longa. */
    public function serieMensal(int $meses = 12): array
    {
        return $this->todos(
            "SELECT DATE_FORMAT(data,'%Y-%m') AS ano_mes,
                    COALESCE(SUM(total),0)    AS faturamento,
                    COUNT(*)                  AS pedidos
               FROM bi_fato_pedido
              WHERE venda_valida = 1
                AND data >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
              GROUP BY ano_mes ORDER BY ano_mes",
            [$meses]
        );
    }

    /** Pedidos por status — todos, não só os de venda. */
    public function porStatus(array $p): array
    {
        return $this->todos(
            "SELECT status_pedido, classe_bi, COUNT(*) AS pedidos,
                    COALESCE(SUM(total),0) AS valor
               FROM bi_fato_pedido
              WHERE data BETWEEN ? AND ?
              GROUP BY status_pedido, classe_bi
              ORDER BY pedidos DESC",
            [$p['ini'], $p['fim']]
        );
    }

    /**
     * Ranking genérico por dimensão do item.
     * $dim aceita apenas chaves conhecidas — nunca interpola input.
     */
    public function ranking(array $p, string $dim = 'produto', int $limite = 10): array
    {
        $mapa = [
            'produto'   => ['i.produto_id',    'i.nome_produto'],
            'marca'     => ['i.marca_id',      'i.marca_nome'],
            'categoria' => ['i.categoria_id',  'i.categoria_nome'],
            'canal'     => ['i.canal',         'i.canal'],
        ];
        if (!isset($mapa[$dim])) $dim = 'produto';
        [$idCol, $nomeCol] = $mapa[$dim];

        return $this->todos(
            "SELECT {$idCol} AS id, {$nomeCol} AS nome,
                    SUM(i.quantidade)              AS qtd,
                    ROUND(SUM(i.receita),2)        AS receita,
                    ROUND(SUM(i.lucro),2)          AS lucro,
                    COUNT(DISTINCT i.pedido_id)    AS pedidos,
                    COUNT(DISTINCT i.cliente_id)   AS clientes,
                    ROUND(SUM(i.desconto),2)       AS desconto,
                    -- Margem só sobre a parte com custo conhecido.
                    ROUND(100 * SUM(i.lucro)
                        / NULLIF(SUM(CASE WHEN i.custo_unitario IS NOT NULL
                                          THEN i.receita END),0), 1) AS margem_pct,
                    ROUND(100 * SUM(CASE WHEN i.custo_unitario IS NOT NULL
                                         THEN i.receita ELSE 0 END)
                              / NULLIF(SUM(i.receita),0), 0)         AS cobertura_custo_pct
               FROM bi_fato_item i
              WHERE i.venda_valida = 1 AND i.data BETWEEN ? AND ?
                AND {$nomeCol} IS NOT NULL
              GROUP BY {$idCol}, {$nomeCol}
              ORDER BY receita DESC
              LIMIT " . (int)$limite,
            [$p['ini'], $p['fim']]
        );
    }

    /** Faturamento por forma de pagamento. */
    public function porPagamento(array $p): array
    {
        return $this->todos(
            "SELECT COALESCE(NULLIF(forma_pagamento,''),'—') AS forma,
                    COUNT(*)                                 AS pedidos,
                    COALESCE(SUM(total),0)                   AS receita,
                    ROUND(AVG(parcelas),1)                   AS parcelas_media
               FROM bi_fato_pedido
              WHERE venda_valida = 1 AND data BETWEEN ? AND ?
              GROUP BY forma ORDER BY receita DESC",
            [$p['ini'], $p['fim']]
        );
    }

    /** Pedidos mais recentes, para a lista da visão geral. */
    public function pedidosRecentes(int $limite = 10): array
    {
        return $this->todos(
            "SELECT f.codigo, f.total, f.forma_pagamento, f.status_pedido,
                    f.classe_bi, f.criado_em, f.canal,
                    COALESCE(c.nome,'—') AS cliente
               FROM bi_fato_pedido f
               LEFT JOIN bi_dim_cliente c ON c.cliente_id = f.cliente_id
              ORDER BY f.criado_em DESC
              LIMIT " . (int)$limite
        );
    }

    /**
     * Segmentação simples por recorrência.
     * RFM completo é módulo próprio (Fase 3, onda B).
     */
    public function segmentosCliente(array $p): array
    {
        return $this->todos(
            "SELECT CASE WHEN compras = 1 THEN 'Primeira compra'
                         WHEN compras = 2 THEN 'Segunda compra'
                         WHEN compras BETWEEN 3 AND 5 THEN 'Recorrente'
                         ELSE 'Fiel (6+)' END          AS segmento,
                    COUNT(*)                           AS clientes,
                    ROUND(AVG(gasto),2)                AS ticket,
                    ROUND(SUM(gasto),2)                AS receita
               FROM (
                    SELECT cliente_id, COUNT(*) AS compras, SUM(total) AS gasto
                      FROM bi_fato_pedido
                     WHERE venda_valida = 1 AND data BETWEEN ? AND ?
                     GROUP BY cliente_id
               ) x
              GROUP BY segmento
              ORDER BY receita DESC",
            [$p['ini'], $p['fim']]
        );
    }

    /** Produtos zerados ou abaixo do mínimo. */
    public function alertasEstoque(int $limite = 15): array
    {
        return $this->todos(
            "SELECT produto, sku_codigo, saldo, disponivel,
                    estoque_minimo, situacao, valor_estoque
               FROM bi_fato_estoque_saldo
              WHERE situacao IN ('zerado','critico')
              ORDER BY FIELD(situacao,'zerado','critico'), saldo ASC
              LIMIT " . (int)$limite
        );
    }

    /**
     * Funil. Começa em ViewContent — NÃO existe topo de funil
     * (visitas), porque não há tabela de page views nem GA4.
     */
    public function funil(array $p): array
    {
        $linhas = $this->todos(
            "SELECT etapa, ordem_funil, COUNT(*) AS eventos,
                    COUNT(DISTINCT COALESCE(visitante_token, CONCAT('c', cliente_id))) AS pessoas
               FROM bi_fato_funil
              WHERE data BETWEEN ? AND ?
              GROUP BY etapa, ordem_funil
              ORDER BY ordem_funil",
            [$p['ini'], $p['fim']]
        );

        // Conversão de cada etapa contra a ANTERIOR (não contra o topo):
        // é o que aponta onde exatamente o cliente desiste.
        $anterior = null;
        foreach ($linhas as &$l) {
            $l['conversao'] = ($anterior !== null && $anterior > 0)
                ? round(100 * $l['pessoas'] / $anterior, 1) : null;
            $anterior = (int)$l['pessoas'];
        }
        return $linhas;
    }

    /** Metas do período com o realizado ao lado. */
    public function metas(array $p): array
    {
        $metas = $this->todos(
            "SELECT * FROM bi_fato_meta
              WHERE periodo_fim >= ? AND periodo_ini <= ?
              ORDER BY periodo_ini DESC",
            [$p['ini'], $p['fim']]
        );

        foreach ($metas as &$m) {
            $m['realizado'] = $this->realizadoDaMeta($m);
            $m['pct']       = $m['valor_meta'] > 0
                ? round(100 * $m['realizado'] / $m['valor_meta'], 1) : null;
            $m['falta']     = max(0, (float)$m['valor_meta'] - $m['realizado']);
        }
        return $metas;
    }

    /**
     * Realizado de uma meta, no período DELA (não no filtro da tela) —
     * senão o "% atingido" muda conforme o filtro e deixa de
     * significar qualquer coisa.
     *
     * Só a dimensão 'loja' está implementada; as demais devolvem 0.0
     * de propósito, para não inventar número.
     */
    private function realizadoDaMeta(array $m): float
    {
        if ($m['dimensao'] !== 'loja') return 0.0;

        $col = [
            'faturamento'    => 'COALESCE(SUM(total),0)',
            'pedidos'        => 'COUNT(*)',
            'ticket_medio'   => 'COALESCE(AVG(total),0)',
            'clientes'       => 'COUNT(DISTINCT cliente_id)',
        ][$m['metrica']] ?? null;

        if ($col === null) return 0.0;

        return (float)$this->valor(
            "SELECT {$col} FROM bi_fato_pedido
              WHERE venda_valida = 1 AND data BETWEEN ? AND ?",
            [$m['periodo_ini'], $m['periodo_fim']]
        );
    }

    // ════════════════════════════════════════════════════
    // CLIENTES — RFM, COORTE, GEOGRAFIA
    // ════════════════════════════════════════════════════

    /**
     * Segmentação RFM.
     *
     * Recência, Frequência e Monetário pontuados de 1 a 5 por QUINTIL
     * (NTILE), não por faixa fixa. Faixa fixa ("gastou mais de R$ 500
     * = nota 5") envelhece: o que era cliente alto no ano passado vira
     * médio quando o ticket sobe, e o segmento muda sem ninguém mexer
     * em nada. Quintil é sempre relativo à própria base.
     *
     * Recência é INVERTIDA (6 - quintil): quem comprou há menos tempo
     * precisa pontuar mais, e o NTILE ordena do menor para o maior.
     *
     * Só entra quem tem compra válida — senão cadastro que nunca
     * comprou cairia em "Perdido" e inflaria o pior segmento.
     */
    public function rfm(): array
    {
        return $this->todos(
            "WITH base AS (
                SELECT cliente_id,
                       DATEDIFF(CURDATE(), MAX(data)) AS recencia,
                       COUNT(*)                       AS frequencia,
                       SUM(total)                     AS monetario,
                       MAX(data)                      AS ultima_compra,
                       MIN(data)                      AS primeira_compra
                  FROM bi_fato_pedido
                 WHERE venda_valida = 1 AND cliente_id IS NOT NULL
                 GROUP BY cliente_id
             ),
             pontuada AS (
                SELECT b.*,
                       6 - NTILE(5) OVER (ORDER BY recencia ASC) AS r,
                       NTILE(5) OVER (ORDER BY frequencia ASC)   AS f,
                       NTILE(5) OVER (ORDER BY monetario ASC)    AS m
                  FROM base b
             )
             SELECT p.*, c.nome, c.tier, c.faixa_etaria,
                    (p.r + p.f + p.m) AS score,
                    CASE
                        WHEN p.r >= 4 AND p.f >= 4 AND p.m >= 4 THEN 'Campeoes'
                        WHEN p.r >= 4 AND p.f >= 3              THEN 'Fieis'
                        WHEN p.r >= 4 AND p.f <= 2              THEN 'Novos'
                        WHEN p.r =  3 AND p.f >= 3              THEN 'Potenciais fieis'
                        WHEN p.r =  3                           THEN 'Precisam de atencao'
                        WHEN p.r =  2 AND p.m >= 4              THEN 'Em risco (alto valor)'
                        WHEN p.r =  2                           THEN 'Em risco'
                        WHEN p.r =  1 AND p.m >= 4              THEN 'Perdidos (alto valor)'
                        ELSE 'Hibernando'
                    END COLLATE utf8mb4_unicode_ci AS segmento
               FROM pontuada p
               LEFT JOIN bi_dim_cliente c ON c.cliente_id = p.cliente_id
              ORDER BY score DESC, monetario DESC"
        );
    }

    /** RFM agregado por segmento — o que a tela mostra primeiro. */
    public function rfmResumo(): array
    {
        $out = [];
        foreach ($this->rfm() as $l) {
            $seg = $l['segmento'];
            if (!isset($out[$seg])) {
                $out[$seg] = ['segmento' => $seg, 'clientes' => 0,
                              'receita' => 0.0, 'ticket' => 0.0, 'recencia_media' => 0.0];
            }
            $out[$seg]['clientes']++;
            $out[$seg]['receita']        += (float)$l['monetario'];
            $out[$seg]['recencia_media'] += (float)$l['recencia'];
        }
        foreach ($out as &$o) {
            $o['ticket']         = round($o['receita'] / max(1, $o['clientes']), 2);
            $o['recencia_media'] = round($o['recencia_media'] / max(1, $o['clientes']));
            $o['receita']        = round($o['receita'], 2);
        }
        usort($out, fn($a, $b) => $b['receita'] <=> $a['receita']);
        return array_values($out);
    }

    /**
     * Coorte de retenção: clientes agrupados pelo mês da PRIMEIRA
     * compra, e em quais meses seguintes voltaram.
     *
     * `mes_offset` 0 é o mês da aquisição (sempre 100%). O que
     * interessa é 1, 2, 3… — a queda entre eles é a retenção real, que
     * a média esconde ao misturar coorte boa com coorte ruim.
     */
    public function coorte(int $meses = 12): array
    {
        return $this->todos(
            "WITH primeira AS (
                SELECT cliente_id, DATE_FORMAT(MIN(data),'%Y-%m') AS coorte
                  FROM bi_fato_pedido
                 WHERE venda_valida = 1 AND cliente_id IS NOT NULL
                 GROUP BY cliente_id
             ),
             atividade AS (
                SELECT p.coorte, f.cliente_id,
                       TIMESTAMPDIFF(MONTH,
                           STR_TO_DATE(CONCAT(p.coorte,'-01'),'%Y-%m-%d'),
                           STR_TO_DATE(CONCAT(DATE_FORMAT(f.data,'%Y-%m'),'-01'),'%Y-%m-%d')
                       ) AS mes_offset
                  FROM bi_fato_pedido f
                  JOIN primeira p ON p.cliente_id = f.cliente_id
                 WHERE f.venda_valida = 1
             ),
             tamanho AS (
                SELECT coorte, COUNT(*) AS tamanho FROM primeira GROUP BY coorte
             )
             SELECT a.coorte, a.mes_offset,
                    COUNT(DISTINCT a.cliente_id) AS clientes,
                    t.tamanho                    AS tamanho_coorte,
                    ROUND(100 * COUNT(DISTINCT a.cliente_id) / NULLIF(t.tamanho,0), 1) AS retencao_pct
               FROM atividade a
               JOIN tamanho t ON t.coorte = a.coorte
              WHERE a.mes_offset BETWEEN 0 AND 11
                AND a.coorte >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH),'%Y-%m')
              GROUP BY a.coorte, a.mes_offset, t.tamanho
              ORDER BY a.coorte DESC, a.mes_offset",
            [$meses]
        );
    }

    /**
     * Recompra: quantos clientes pararam em 1, 2, 3+ compras, e quanto
     * tempo levaram para a segunda.
     */
    public function recompra(): array
    {
        $dist = $this->todos(
            "SELECT CASE WHEN compras = 1 THEN '1 compra'
                         WHEN compras = 2 THEN '2 compras'
                         WHEN compras BETWEEN 3 AND 5 THEN '3 a 5'
                         ELSE '6+' END COLLATE utf8mb4_unicode_ci AS faixa,
                    COUNT(*) AS clientes, ROUND(SUM(gasto),2) AS receita
               FROM (SELECT cliente_id, COUNT(*) AS compras, SUM(total) AS gasto
                       FROM bi_fato_pedido WHERE venda_valida = 1
                      GROUP BY cliente_id) x
              GROUP BY faixa
              ORDER BY FIELD(faixa,'1 compra','2 compras','3 a 5','6+')"
        );

        $tempo = $this->um(
            "SELECT ROUND(AVG(dias)) AS dias_2a_compra, COUNT(*) AS base
               FROM (SELECT cliente_id, DATEDIFF(MIN(seg), MIN(pri)) AS dias
                       FROM (SELECT cliente_id, data AS pri,
                                    LEAD(data) OVER (PARTITION BY cliente_id ORDER BY data) AS seg
                               FROM bi_fato_pedido WHERE venda_valida = 1) y
                      WHERE seg IS NOT NULL
                      GROUP BY cliente_id) z"
        );

        $total  = array_sum(array_column($dist, 'clientes'));
        $umaVez = 0;
        foreach ($dist as $d) if ($d['faixa'] === '1 compra') $umaVez = (int)$d['clientes'];

        return [
            'distribuicao'   => $dist,
            'total_clientes' => $total,
            // Taxa de recompra = quem voltou ao menos uma vez.
            'taxa_recompra'  => $total > 0 ? round(100 * ($total - $umaVez) / $total, 1) : 0.0,
            'dias_2a_compra' => $tempo['dias_2a_compra'] ?? null,
            'base_2a_compra' => (int)($tempo['base'] ?? 0),
        ];
    }

    /**
     * Geografia por UF ou cidade.
     *
     * `confiabilidade_pct` viaja junto porque boa parte do histórico
     * vem do cadastro ATUAL do cliente, que ele pode ter editado
     * depois da compra. É o melhor disponível, não a verdade fiscal.
     */
    public function geografia(array $p, string $nivel = 'uf', int $limite = 20): array
    {
        // Whitelist: o nome da coluna entra na SQL por interpolação.
        $col = $nivel === 'cidade' ? 'cidade' : 'uf';

        return $this->todos(
            "SELECT f.{$col} AS local,
                    COUNT(*)                      AS pedidos,
                    COUNT(DISTINCT f.cliente_id)  AS clientes,
                    ROUND(SUM(f.total),2)         AS receita,
                    ROUND(AVG(f.total),2)         AS ticket,
                    ROUND(AVG(f.frete_cobrado),2) AS frete_medio,
                    ROUND(100 * SUM(f.origem_geografia IN ('snapshot','json_pedido'))
                              / NULLIF(COUNT(*),0), 0) AS confiabilidade_pct
               FROM bi_fato_pedido f
              WHERE f.venda_valida = 1 AND f.data BETWEEN ? AND ?
                AND f.{$col} IS NOT NULL AND f.{$col} <> ''
              GROUP BY f.{$col}
              ORDER BY receita DESC
              LIMIT " . (int)$limite,
            [$p['ini'], $p['fim']]
        );
    }

    /**
     * Clientes em risco: compravam com regularidade e pararam.
     *
     * O corte é RELATIVO ao intervalo médio do próprio cliente, não um
     * "90 dias" fixo. Quem compra todo mês e sumiu há 60 dias está em
     * risco; quem compra a cada 6 meses e sumiu há 60 não está. Corte
     * fixo erra os dois casos.
     */
    public function clientesRisco(int $limite = 20): array
    {
        return $this->todos(
            "SELECT c.nome, b.*,
                    ROUND(b.dias_sem_comprar / NULLIF(b.intervalo_medio,0), 1) AS vezes_o_normal
               FROM (
                 SELECT cliente_id,
                        COUNT(*)                       AS compras,
                        ROUND(SUM(total),2)            AS receita,
                        MAX(data)                      AS ultima_compra,
                        DATEDIFF(CURDATE(), MAX(data)) AS dias_sem_comprar,
                        ROUND(DATEDIFF(MAX(data), MIN(data)) / NULLIF(COUNT(*)-1,0)) AS intervalo_medio
                   FROM bi_fato_pedido
                  WHERE venda_valida = 1 AND cliente_id IS NOT NULL
                  GROUP BY cliente_id
                 HAVING compras >= 2 AND intervalo_medio > 0
                    AND dias_sem_comprar > intervalo_medio * 1.5
               ) b
               LEFT JOIN bi_dim_cliente c ON c.cliente_id = b.cliente_id
              ORDER BY b.receita DESC
              LIMIT " . (int)$limite
        );
    }

    // ════════════════════════════════════════════════════
    // PAGAMENTO
    // ════════════════════════════════════════════════════

    /**
     * Taxa de aprovação por método e por adquirente.
     *
     * Sai de `bi_fato_pagamento`, que tem uma linha por TENTATIVA —
     * inclusive as recusadas. Calcular aprovação só sobre transações
     * bem-sucedidas devolveria sempre 100%, que é a métrica inútil
     * clássica desse relatório.
     */
    public function pagamentoAprovacao(array $p, string $por = 'metodo'): array
    {
        $col = $por === 'adquirente' ? 'adquirente_codigo' : 'metodo';

        return $this->todos(
            "SELECT COALESCE(NULLIF({$col},''),'—') AS chave,
                    COUNT(*)                        AS tentativas,
                    SUM(aprovada)                   AS aprovadas,
                    ROUND(100 * SUM(aprovada) / NULLIF(COUNT(*),0), 1) AS taxa_aprovacao,
                    ROUND(SUM(CASE WHEN aprovada THEN valor ELSE 0 END),2) AS valor_aprovado,
                    ROUND(AVG(duracao_ms))          AS ms_medio
               FROM bi_fato_pagamento
              WHERE data BETWEEN ? AND ?
              GROUP BY chave
              ORDER BY tentativas DESC",
            [$p['ini'], $p['fim']]
        );
    }

    /** Faturamento por número de parcelas. */
    public function parcelas(array $p): array
    {
        return $this->todos(
            "SELECT COALESCE(parcelas,1) AS parcelas,
                    COUNT(*)             AS pedidos,
                    ROUND(SUM(total),2)  AS receita,
                    ROUND(AVG(total),2)  AS ticket
               FROM bi_fato_pedido
              WHERE venda_valida = 1 AND data BETWEEN ? AND ?
                AND forma_pagamento LIKE '%cart%'
              GROUP BY parcelas ORDER BY parcelas",
            [$p['ini'], $p['fim']]
        );
    }

    /** Motivos de recusa mais frequentes. */
    public function recusas(array $p, int $limite = 10): array
    {
        return $this->todos(
            "SELECT COALESCE(NULLIF(classe_erro,''),'não classificado') AS motivo,
                    COALESCE(NULLIF(mensagem_adquirente,''),'—')        AS mensagem,
                    COUNT(*) AS ocorrencias,
                    ROUND(SUM(valor),2) AS valor_perdido
               FROM bi_fato_pagamento
              WHERE aprovada = 0 AND data BETWEEN ? AND ?
              GROUP BY motivo, mensagem
              ORDER BY ocorrencias DESC
              LIMIT " . (int)$limite,
            [$p['ini'], $p['fim']]
        );
    }

    // ════════════════════════════════════════════════════
    // CUPONS E DESCONTOS
    // ════════════════════════════════════════════════════

    /**
     * Desempenho por cupom. Só usos EFETIVADOS entram no desconto —
     * 'reservado' é carrinho que pode nunca virar venda.
     */
    public function cupons(array $p, int $limite = 20): array
    {
        return $this->todos(
            "SELECT codigo, cupom_nome, tipo, campanha_nome,
                    COUNT(*)                                     AS usos,
                    SUM(efetivado)                               AS efetivados,
                    COUNT(DISTINCT cliente_id)                   AS clientes,
                    ROUND(SUM(CASE WHEN efetivado THEN desconto_total ELSE 0 END),2) AS desconto,
                    ROUND(SUM(CASE WHEN efetivado THEN pedido_total ELSE 0 END),2)   AS receita,
                    ROUND(AVG(CASE WHEN efetivado THEN pedido_total END),2)          AS ticket,
                    -- Quanto de receita cada real de desconto trouxe.
                    -- Abaixo de 1 significa desconto maior que a venda.
                    ROUND(SUM(CASE WHEN efetivado THEN pedido_total ELSE 0 END)
                        / NULLIF(SUM(CASE WHEN efetivado THEN desconto_total ELSE 0 END),0), 1) AS retorno
               FROM bi_fato_cupom
              WHERE data BETWEEN ? AND ? AND codigo IS NOT NULL
              GROUP BY cupom_id, codigo, cupom_nome, tipo, campanha_nome
              ORDER BY desconto DESC
              LIMIT " . (int)$limite,
            [$p['ini'], $p['fim']]
        );
    }

    /**
     * Com desconto × sem desconto: o desconto realmente aumentou o
     * ticket, ou só entregou margem?
     */
    public function impactoDesconto(array $p): array
    {
        return $this->todos(
            "SELECT CASE WHEN desconto > 0 THEN 'Com desconto' ELSE 'Sem desconto' END
                        COLLATE utf8mb4_unicode_ci AS grupo,
                    COUNT(*)              AS pedidos,
                    ROUND(SUM(total),2)   AS receita,
                    ROUND(AVG(total),2)   AS ticket,
                    ROUND(SUM(desconto),2) AS desconto_total,
                    ROUND(100 * SUM(desconto) / NULLIF(SUM(subtotal),0),1) AS desconto_pct
               FROM bi_fato_pedido
              WHERE venda_valida = 1 AND data BETWEEN ? AND ?
              GROUP BY grupo",
            [$p['ini'], $p['fim']]
        );
    }

    // ════════════════════════════════════════════════════
    // ESTOQUE
    // ════════════════════════════════════════════════════

    /**
     * Giro: quanto saiu nos últimos N dias contra o saldo atual.
     *
     * `dias_cobertura` é o que importa operacionalmente — "tenho 40
     * unidades" não diz nada; "tenho 4 dias" diz tudo.
     */
    public function giroEstoque(int $dias = 90, int $limite = 30): array
    {
        return $this->todos(
            "SELECT s.produto_id, s.produto, s.sku_codigo, s.marca_nome,
                    s.saldo, s.disponivel, s.valor_estoque,
                    COALESCE(v.qtd,0)                       AS vendido,
                    ROUND(COALESCE(v.qtd,0) / ?, 2)         AS media_diaria,
                    CASE WHEN COALESCE(v.qtd,0) > 0
                         THEN ROUND(s.saldo / (v.qtd / ?), 0) END AS dias_cobertura,
                    CASE WHEN COALESCE(v.qtd,0) = 0        THEN 'Sem giro'
                         WHEN s.saldo <= 0                  THEN 'Ruptura'
                         WHEN s.saldo / (v.qtd / ?) <= 15   THEN 'Giro alto'
                         WHEN s.saldo / (v.qtd / ?) <= 60   THEN 'Giro medio'
                         ELSE 'Giro baixo' END COLLATE utf8mb4_unicode_ci AS classificacao
               FROM bi_fato_estoque_saldo s
               LEFT JOIN (
                    SELECT produto_id, SUM(quantidade) AS qtd
                      FROM bi_fato_item
                     WHERE venda_valida = 1
                       AND data >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                     GROUP BY produto_id
               ) v ON v.produto_id = s.produto_id
              ORDER BY dias_cobertura IS NULL, dias_cobertura ASC
              LIMIT " . (int)$limite,
            [$dias, $dias, $dias, $dias, $dias]
        );
    }

    /**
     * Estoque parado: com saldo, sem venda há X dias.
     * Só faz sentido listar quem TEM saldo — produto zerado sem venda
     * não é dinheiro parado, é produto esgotado.
     */
    public function estoqueParado(int $dias = 90, int $limite = 30): array
    {
        return $this->todos(
            "SELECT s.produto_id, s.produto, s.sku_codigo, s.marca_nome,
                    s.saldo, s.valor_estoque, u.ultima_venda,
                    DATEDIFF(CURDATE(), u.ultima_venda) AS dias_sem_vender
               FROM bi_fato_estoque_saldo s
               LEFT JOIN (
                    SELECT produto_id, MAX(data) AS ultima_venda
                      FROM bi_fato_item WHERE venda_valida = 1
                     GROUP BY produto_id
               ) u ON u.produto_id = s.produto_id
              WHERE s.saldo > 0
                AND (u.ultima_venda IS NULL
                     OR u.ultima_venda < DATE_SUB(CURDATE(), INTERVAL ? DAY))
              ORDER BY s.valor_estoque IS NULL, s.valor_estoque DESC, s.saldo DESC
              LIMIT " . (int)$limite,
            [$dias]
        );
    }

    // ════════════════════════════════════════════════════
    // PÓS-VENDA
    // ════════════════════════════════════════════════════

    /** Devoluções por motivo. */
    public function devolucoes(array $p): array
    {
        return $this->todos(
            "SELECT COALESCE(motivo,'não informado') AS motivo,
                    tipo,
                    COUNT(*)                    AS itens,
                    SUM(quantidade)             AS unidades,
                    ROUND(SUM(valor_devolvido),2) AS valor,
                    COUNT(DISTINCT solicitacao_id) AS solicitacoes
               FROM bi_fato_devolucao
              WHERE data_solicitacao BETWEEN ? AND ?
              GROUP BY motivo, tipo
              ORDER BY valor DESC",
            [$p['ini'], $p['fim']]
        );
    }

    /** Produtos mais devolvidos. */
    public function produtosDevolvidos(array $p, int $limite = 15): array
    {
        return $this->todos(
            "SELECT d.produto_id, pd.nome AS produto, d.marca_nome,
                    SUM(d.quantidade)             AS unidades,
                    ROUND(SUM(d.valor_devolvido),2) AS valor,
                    COUNT(DISTINCT d.solicitacao_id) AS solicitacoes
               FROM bi_fato_devolucao d
               LEFT JOIN bi_dim_produto pd ON pd.produto_id = d.produto_id
              WHERE d.data_solicitacao BETWEEN ? AND ? AND d.produto_id IS NOT NULL
              GROUP BY d.produto_id, pd.nome, d.marca_nome
              ORDER BY valor DESC
              LIMIT " . (int)$limite,
            [$p['ini'], $p['fim']]
        );
    }

    /** Cancelamentos por motivo — o campo nasceu na Fase 1. */
    public function cancelamentos(array $p): array
    {
        return $this->todos(
            "SELECT COALESCE(motivo_cancelamento,'não informado')
                        COLLATE utf8mb4_unicode_ci AS motivo,
                    COUNT(*)            AS pedidos,
                    ROUND(SUM(total),2) AS valor
               FROM bi_fato_pedido
              WHERE classe_bi = 'cancelamento' AND data BETWEEN ? AND ?
              GROUP BY motivo
              ORDER BY pedidos DESC",
            [$p['ini'], $p['fim']]
        );
    }

    /** Carrinho abandonado e recuperação. */
    public function carrinhos(array $p): array
    {
        $kpi = $this->um(
            "SELECT COUNT(*)                                  AS carrinhos,
                    ROUND(SUM(valor_abandonado),2)            AS valor_abandonado,
                    ROUND(AVG(valor_abandonado),2)            AS ticket,
                    SUM(recuperado)                           AS recuperados,
                    ROUND(SUM(COALESCE(valor_recuperado,0)),2) AS valor_recuperado,
                    ROUND(100 * SUM(recuperado) / NULLIF(COUNT(*),0),1) AS taxa_recuperacao
               FROM bi_fato_carrinho
              WHERE data_abandono BETWEEN ? AND ?",
            [$p['ini'], $p['fim']]
        );

        $porStatus = $this->todos(
            "SELECT status, COUNT(*) AS carrinhos,
                    ROUND(SUM(valor_abandonado),2) AS valor
               FROM bi_fato_carrinho
              WHERE data_abandono BETWEEN ? AND ?
              GROUP BY status ORDER BY carrinhos DESC",
            [$p['ini'], $p['fim']]
        );

        return ['kpi' => $kpi, 'por_status' => $porStatus];
    }

    // ════════════════════════════════════════════════════
    // LOGÍSTICA
    // ════════════════════════════════════════════════════

    /**
     * Desempenho por transportadora.
     *
     * ⚠ Hoje devolve pouco ou nada: `log_etiquetas.pedido_id` é NULL
     * em 100% das linhas e `valor_postado` nunca é preenchido. Ver
     * `bi_fato_frete` e o indicador `frete_ligado_ao_pedido` em
     * `bi_saude_dados`. A consulta está pronta para quando o módulo
     * de logística passar a gravar o vínculo.
     */
    public function transportadoras(array $p): array
    {
        return $this->todos(
            "SELECT COALESCE(transportadora,'—') AS transportadora,
                    COUNT(*)                       AS envios,
                    ROUND(AVG(custo_etiqueta),2)   AS custo_medio,
                    ROUND(AVG(custo_real),2)       AS custo_real_medio,
                    ROUND(SUM(divergencia),2)      AS divergencia_total,
                    ROUND(AVG(dias_entrega),1)     AS dias_entrega,
                    SUM(atraso IS NOT NULL AND atraso > 0) AS atrasos,
                    ROUND(100 * SUM(entregue_em IS NOT NULL) / NULLIF(COUNT(*),0),1) AS pct_entregue
               FROM bi_fato_frete
              WHERE data BETWEEN ? AND ?
              GROUP BY transportadora
              ORDER BY envios DESC",
            [$p['ini'], $p['fim']]
        );
    }

    /**
     * Saúde do dado. Toda tela que dependa de um fato com cobertura
     * baixa deve exibir isto junto do número.
     */
    public function saude(): array
    {
        return $this->todos("SELECT * FROM bi_saude_dados");
    }

    // ════════════════════════════════════════════════════
    // ACESSO AO BANCO
    // ════════════════════════════════════════════════════

    private function todos(string $sql, array $params = []): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    private function um(string $sql, array $params = []): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetch() ?: [];
    }

    private function valor(string $sql, array $params = [])
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }
}
