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
// Não é final de propósito: o gateway dos agentes de IA recebe o
// BiService por injeção, e os testes passam um dublê que estende esta
// classe para simular view ausente e dado sintético sem tocar no banco.
class BiService
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
        // Deriva o período anterior quando o chamador passou só
        // ini/fim. Sem isto, qualquer consumidor que montasse o
        // intervalo à mão (alertas, insights, um relatório novo)
        // quebrava com TypeError em vez de simplesmente comparar.
        if (!isset($p['ini_ant'], $p['fim_ant'])) {
            $ini    = new DateTimeImmutable($p['ini']);
            $fim    = new DateTimeImmutable($p['fim']);
            $dias   = max(1, (int)$ini->diff($fim)->days + 1);
            $fimAnt = $ini->modify('-1 day');
            $p['fim_ant'] = $fimAnt->format('Y-m-d');
            $p['ini_ant'] = $fimAnt->modify('-' . ($dias - 1) . ' days')->format('Y-m-d');
        }

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

    /**
     * O mesmo funil, aberto por dispositivo.
     *
     * Conversão contra a etapa anterior DENTRO de cada dispositivo. A
     * pergunta que responde é "mobile converte pior que desktop, e em
     * qual etapa?" — o corte que mais muda decisão de UX.
     *
     * `dispositivo` vem do user-agent gravado no evento; sem user-agent
     * cai em 'desconhecido' e continua na conta, porque sumir com ele
     * inflaria as taxas dos outros dois.
     */
    public function funilPorDispositivo(array $p): array
    {
        $linhas = $this->todos(
            "SELECT dispositivo, etapa, ordem_funil, COUNT(*) AS eventos,
                    COUNT(DISTINCT COALESCE(visitante_token, CONCAT('c', cliente_id))) AS pessoas
               FROM bi_fato_funil
              WHERE data BETWEEN ? AND ?
              GROUP BY dispositivo, etapa, ordem_funil
              ORDER BY dispositivo, ordem_funil",
            [$p['ini'], $p['fim']]
        );

        $out = [];
        foreach ($linhas as $l) {
            $d = $l['dispositivo'];
            $anterior = isset($out[$d]) ? (int)end($out[$d])['pessoas'] : null;
            $l['conversao'] = ($anterior !== null && $anterior > 0)
                ? round(100 * $l['pessoas'] / $anterior, 1) : null;
            unset($l['dispositivo']);
            $out[$d][] = $l;
        }
        return $out;
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
     * O grão muda com a dimensão, e isso não é detalhe:
     *
     *   loja / vendedor / canal  → grão de PEDIDO  (bi_fato_pedido)
     *   marca / categoria        → grão de ITEM    (bi_fato_item)
     *
     * Um pedido com itens de três marcas não "pertence" a nenhuma
     * delas; só os itens pertencem. Medir meta de marca no grão de
     * pedido contaria o pedido inteiro para cada marca e somaria mais
     * que o faturamento real.
     */
    private function realizadoDaMeta(array $m): float
    {
        $metrica  = (string)$m['metrica'];
        $dimensao = (string)$m['dimensao'];

        // O grão é decidido pela DIMENSÃO e também pela MÉTRICA.
        //
        // Marca e categoria só existem no item — um pedido com itens de
        // três marcas não pertence a nenhuma delas.
        //
        // E quantidade/margem também só existem no item: medir "itens
        // vendidos" da loja no grão de pedido devolveria 0, que se lê
        // como "não vendeu nada" em vez de "essa conta não mora aqui".
        // `bi_fato_item` carrega canal e codigo_vendedor, então o
        // recorte continua possível no grão fino.
        $metricaDeItem = in_array($metrica, ['itens_vendidos', 'margem'], true);
        $grãoItem = in_array($dimensao, ['marca', 'categoria'], true) || $metricaDeItem;

        // Colunas por grão. Whitelist: nada aqui vem do usuário.
        $colunas = $grãoItem ? [
            'faturamento'    => 'COALESCE(SUM(receita),0)',
            'pedidos'        => 'COUNT(DISTINCT pedido_id)',
            'ticket_medio'   => 'COALESCE(SUM(receita) / NULLIF(COUNT(DISTINCT pedido_id),0),0)',
            'clientes'       => 'COUNT(DISTINCT cliente_id)',
            'itens_vendidos' => 'COALESCE(SUM(quantidade),0)',
            // Margem só sobre a parte com custo conhecido — somar NULL
            // como zero inventaria margem que não se sabe se existe.
            'margem'         => 'COALESCE(ROUND(100 * SUM(lucro)
                                    / NULLIF(SUM(CASE WHEN custo_unitario IS NOT NULL
                                                      THEN receita END),0), 2),0)',
        ] : [
            'faturamento'    => 'COALESCE(SUM(total),0)',
            'pedidos'        => 'COUNT(*)',
            'ticket_medio'   => 'COALESCE(AVG(total),0)',
            'clientes'       => 'COUNT(DISTINCT cliente_id)',
            // Estas duas nunca chegam aqui: `$metricaDeItem` já mandou a
            // consulta para bi_fato_item. Ficam explícitas para o dia em
            // que alguém acrescentar uma métrica nova e precisar decidir
            // conscientemente onde ela mora.
            'itens_vendidos' => null,
            'margem'         => null,
        ];

        $col = $colunas[$metrica] ?? null;
        if ($col === null) return 0.0;

        $tabela = $grãoItem ? 'bi_fato_item' : 'bi_fato_pedido';
        $where  = 'venda_valida = 1 AND data BETWEEN ? AND ?';
        $params = [$m['periodo_ini'], $m['periodo_fim']];

        switch ($dimensao) {
            case 'loja':
                break;

            case 'marca':
                $where   .= ' AND marca_id = ?';
                $params[] = (int)$m['dimensao_id'];
                break;

            case 'categoria':
                $where   .= ' AND categoria_id = ?';
                $params[] = (int)$m['dimensao_id'];
                break;

            case 'canal':
                $where   .= ' AND canal = ?';
                $params[] = (string)$m['dimensao_valor'];
                break;

            case 'vendedor':
                // bi_metas guarda vendedores.id, mas o pedido carrega o
                // CÓDIGO. Sem a tradução a meta casaria com o id como
                // se fosse código e devolveria zero em silêncio.
                $where   .= ' AND codigo_vendedor = (SELECT codigo FROM bi_dim_vendedor WHERE vendedor_id = ?)';
                $params[] = (int)$m['dimensao_id'];
                break;

            default:
                return 0.0;
        }

        return (float)$this->valor("SELECT {$col} FROM {$tabela} WHERE {$where}", $params);
    }

    // ════════════════════════════════════════════════════
    // MARCA E CATEGORIA
    // ════════════════════════════════════════════════════

    /**
     * Visão completa por marca (ou categoria — mesma estrutura).
     *
     * Traz participação no faturamento e crescimento contra o período
     * anterior equivalente. Sem a participação, um ranking de receita
     * não diz se a marca líder representa 12% ou 70% do negócio — e
     * essa é a diferença entre diversificação e dependência.
     */
    public function porMarca(array $p, string $dim = 'marca', int $limite = 40): array
    {
        $mapa = [
            'marca'     => ['i.marca_id',     'i.marca_nome'],
            'categoria' => ['i.categoria_id', 'i.categoria_nome'],
        ];
        if (!isset($mapa[$dim])) $dim = 'marca';
        [$idCol, $nomeCol] = $mapa[$dim];

        // Período anterior equivalente, derivado se não veio pronto.
        if (!isset($p['ini_ant'], $p['fim_ant'])) {
            $ini    = new DateTimeImmutable($p['ini']);
            $dias   = max(1, (int)$ini->diff(new DateTimeImmutable($p['fim']))->days + 1);
            $fimAnt = $ini->modify('-1 day');
            $p['fim_ant'] = $fimAnt->format('Y-m-d');
            $p['ini_ant'] = $fimAnt->modify('-' . ($dias - 1) . ' days')->format('Y-m-d');
        }

        $linhas = $this->todos(
            "SELECT {$idCol} AS id, {$nomeCol} AS nome,
                    ROUND(SUM(CASE WHEN i.data BETWEEN ? AND ? THEN i.receita ELSE 0 END),2) AS receita,
                    ROUND(SUM(CASE WHEN i.data BETWEEN ? AND ? THEN i.receita ELSE 0 END),2) AS receita_ant,
                    SUM(CASE WHEN i.data BETWEEN ? AND ? THEN i.quantidade ELSE 0 END)       AS qtd,
                    COUNT(DISTINCT CASE WHEN i.data BETWEEN ? AND ? THEN i.pedido_id END)    AS pedidos,
                    COUNT(DISTINCT CASE WHEN i.data BETWEEN ? AND ? THEN i.cliente_id END)   AS clientes,
                    COUNT(DISTINCT CASE WHEN i.data BETWEEN ? AND ? THEN i.produto_id END)   AS produtos,
                    ROUND(SUM(CASE WHEN i.data BETWEEN ? AND ? THEN i.lucro END),2)          AS lucro,
                    ROUND(100 * SUM(CASE WHEN i.data BETWEEN ? AND ? THEN i.lucro END)
                        / NULLIF(SUM(CASE WHEN i.data BETWEEN ? AND ? AND i.custo_unitario IS NOT NULL
                                          THEN i.receita END),0), 1)                         AS margem_pct,
                    ROUND(100 * SUM(CASE WHEN i.data BETWEEN ? AND ? AND i.custo_unitario IS NOT NULL
                                         THEN i.receita ELSE 0 END)
                        / NULLIF(SUM(CASE WHEN i.data BETWEEN ? AND ? THEN i.receita END),0), 0)
                                                                                             AS cobertura_custo_pct
               FROM bi_fato_item i
              WHERE i.venda_valida = 1
                AND i.data BETWEEN ? AND ?
                AND {$nomeCol} IS NOT NULL
              GROUP BY {$idCol}, {$nomeCol}
             HAVING receita > 0 OR receita_ant > 0
              ORDER BY receita DESC
              LIMIT " . (int)$limite,
            array_merge(
                [$p['ini'], $p['fim']],           // receita
                [$p['ini_ant'], $p['fim_ant']],   // receita_ant
                [$p['ini'], $p['fim']],           // qtd
                [$p['ini'], $p['fim']],           // pedidos
                [$p['ini'], $p['fim']],           // clientes
                [$p['ini'], $p['fim']],           // produtos
                [$p['ini'], $p['fim']],           // lucro
                [$p['ini'], $p['fim']],           // margem num
                [$p['ini'], $p['fim']],           // margem den
                [$p['ini'], $p['fim']],           // cobertura num
                [$p['ini'], $p['fim']],           // cobertura den
                [$p['ini_ant'], $p['fim']]        // janela que cobre os dois períodos
            )
        );

        $total = array_sum(array_map(fn($r) => (float)$r['receita'], $linhas));
        foreach ($linhas as &$l) {
            $l['participacao_pct'] = $total > 0 ? round(100 * (float)$l['receita'] / $total, 1) : 0.0;
            // NULL quando não havia base: sem período anterior, "+100%"
            // seria leitura de crescimento onde só houve estreia.
            $l['crescimento_pct'] = (float)$l['receita_ant'] > 0
                ? round(100 * ((float)$l['receita'] - (float)$l['receita_ant']) / (float)$l['receita_ant'], 1)
                : null;
        }
        return $linhas;
    }

    /** Produtos de uma marca (ou categoria) — o drill da tabela. */
    public function produtosDaMarca(array $p, int $id, string $dim = 'marca', int $limite = 15): array
    {
        $col = $dim === 'categoria' ? 'i.categoria_id' : 'i.marca_id';

        return $this->todos(
            "SELECT i.produto_id, i.nome_produto,
                    SUM(i.quantidade)         AS qtd,
                    ROUND(SUM(i.receita),2)   AS receita,
                    COUNT(DISTINCT i.pedido_id) AS pedidos
               FROM bi_fato_item i
              WHERE i.venda_valida = 1 AND i.data BETWEEN ? AND ? AND {$col} = ?
              GROUP BY i.produto_id, i.nome_produto
              ORDER BY receita DESC
              LIMIT " . (int)$limite,
            [$p['ini'], $p['fim'], $id]
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
     * Volume de etiquetas por tipo.
     *
     * `log_etiquetas.canal` separa venda da plataforma, logística
     * reversa e frete avulso. Somar os três dá um número de "envios"
     * que não corresponde a nenhuma pergunta real de negócio.
     */
    public function fretePorTipo(array $p): array
    {
        return $this->todos(
            "SELECT tipo_frete,
                    COUNT(*)                        AS etiquetas,
                    SUM(pedido_id IS NOT NULL)      AS com_pedido,
                    SUM(codigo_rastreio IS NOT NULL) AS com_rastreio,
                    ROUND(AVG(custo_real),2)        AS custo_real_medio
               FROM bi_fato_frete
              WHERE data BETWEEN ? AND ?
              GROUP BY tipo_frete
              ORDER BY etiquetas DESC",
            [$p['ini'], $p['fim']]
        );
    }

    /**
     * Desempenho por transportadora.
     *
     * ⚠ `custo_real` e a divergência dependem de
     * `log_etiquetas.valor_postado`, que hoje está vazio em todas as
     * linhas — é o cron pós-postagem que o preenche. Prazo e vínculo
     * com o pedido funcionam normalmente.
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

    // ════════════════════════════════════════════════════
    // RENTABILIDADE E CONCENTRAÇÃO
    // ════════════════════════════════════════════════════

    /**
     * Curva ABC por receita acumulada.
     *
     * A = até 80% da receita · B = até 95% · C = a cauda.
     * O corte é sobre o ACUMULADO ordenado, não sobre o valor de cada
     * item: é isso que faz "A" significar "o grupo que sustenta o
     * negócio" em vez de "os itens caros".
     *
     * Por receita, não por lucro, enquanto a cobertura de custo for
     * baixa — ABC por lucro com 0% de custo classificaria tudo como C.
     */
    public function curvaABC(array $p, string $dim = 'produto', int $limite = 200): array
    {
        $linhas = $this->ranking($p, $dim, $limite);
        $total  = array_sum(array_map(fn($r) => (float)$r['receita'], $linhas));
        if ($total <= 0) return [];

        $acum = 0.0;
        foreach ($linhas as $i => &$l) {
            $acum += (float)$l['receita'];
            $pct   = 100 * $acum / $total;
            $l['posicao']       = $i + 1;
            $l['pct_receita']   = round(100 * (float)$l['receita'] / $total, 2);
            $l['pct_acumulado'] = round($pct, 2);
            $l['classe']        = $pct <= 80 ? 'A' : ($pct <= 95 ? 'B' : 'C');
        }
        return $linhas;
    }

    /**
     * Concentração 80/20. Responde "quantos itens fazem 80% da
     * receita" — o número que diz se o negócio está apoiado em poucos
     * produtos (risco) ou espalhado.
     */
    public function pareto(array $p, string $dim = 'produto'): array
    {
        $abc = $this->curvaABC($p, $dim, 500);
        if (empty($abc)) return ['itens' => 0, 'itens_80' => 0, 'pct_itens' => 0.0, 'dimensao' => $dim];

        $n80 = count(array_filter($abc, fn($r) => $r['classe'] === 'A'));
        return [
            'itens'     => count($abc),
            'itens_80'  => $n80,
            'pct_itens' => round(100 * $n80 / count($abc), 1),
            'dimensao'  => $dim,
        ];
    }

    /**
     * Produtos em ascensão ou queda: período recente contra o
     * imediatamente anterior de mesma duração.
     *
     * Exige base mínima no período anterior, senão qualquer produto
     * que vendeu 1 unidade pela primeira vez apareceria com "+100%" e
     * dominaria o ranking de crescimento.
     */
    public function tendenciaProdutos(int $dias = 30, string $direcao = 'alta', int $limite = 10): array
    {
        $ordem = $direcao === 'queda' ? 'ASC' : 'DESC';

        return $this->todos(
            "SELECT produto_id, nome_produto,
                    ROUND(SUM(CASE WHEN data >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                                   THEN receita ELSE 0 END),2) AS receita_atual,
                    ROUND(SUM(CASE WHEN data <  DATE_SUB(CURDATE(), INTERVAL ? DAY)
                                   THEN receita ELSE 0 END),2) AS receita_anterior,
                    ROUND(100 * (
                        SUM(CASE WHEN data >= DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN receita ELSE 0 END)
                      - SUM(CASE WHEN data <  DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN receita ELSE 0 END)
                    ) / NULLIF(SUM(CASE WHEN data < DATE_SUB(CURDATE(), INTERVAL ? DAY)
                                        THEN receita ELSE 0 END),0), 1) AS variacao_pct
               FROM bi_fato_item
              WHERE venda_valida = 1
                AND data >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              GROUP BY produto_id, nome_produto
             HAVING receita_anterior > 0 AND variacao_pct IS NOT NULL
              ORDER BY variacao_pct {$ordem}
              LIMIT " . (int)$limite,
            [$dias, $dias, $dias, $dias, $dias, $dias * 2]
        );
    }

    // ════════════════════════════════════════════════════
    // PROJEÇÃO
    // ════════════════════════════════════════════════════

    /**
     * Projeção do mês corrente por run-rate, com três cenários.
     *
     * A amplitude dos cenários vem do DESVIO PADRÃO das vendas
     * diárias do mês, não de um "±20%" arbitrário: mês irregular
     * merece intervalo largo, mês estável merece intervalo apertado.
     *
     * `confianca` é honesta sobre a base: o histórico começa em
     * dez/2025, então até dez/2026 não existe ano anterior para
     * sazonalidade e a projeção é run-rate puro.
     */
    public function projecaoMes(): array
    {
        $hoje       = new DateTimeImmutable('today');
        $iniMes     = $hoje->modify('first day of this month');
        $fimMes     = $hoje->modify('last day of this month');
        $diasNoMes  = (int)$fimMes->format('d');
        $diasPassados = (int)$hoje->format('d');

        $d = $this->um(
            "SELECT COALESCE(SUM(total),0) AS receita,
                    COUNT(*)               AS pedidos,
                    COALESCE(STDDEV_SAMP(diario),0) AS desvio
               FROM bi_fato_pedido f
               LEFT JOIN (SELECT data AS d2, SUM(total) AS diario
                            FROM bi_fato_pedido
                           WHERE venda_valida = 1 AND data BETWEEN ? AND ?
                           GROUP BY data) s ON s.d2 = f.data
              WHERE f.venda_valida = 1 AND f.data BETWEEN ? AND ?",
            [$iniMes->format('Y-m-d'), $hoje->format('Y-m-d'),
             $iniMes->format('Y-m-d'), $hoje->format('Y-m-d')]
        );

        $receita  = (float)($d['receita'] ?? 0);
        $mediaDia = $diasPassados > 0 ? $receita / $diasPassados : 0.0;
        $restam   = max(0, $diasNoMes - $diasPassados);
        $provavel = $receita + ($mediaDia * $restam);

        // Desvio diário propagado pelos dias que faltam.
        $desvio = (float)($d['desvio'] ?? 0);
        $margem = $desvio * sqrt(max(1, $restam));

        return [
            'mes'            => $hoje->format('Y-m'),
            'dias_passados'  => $diasPassados,
            'dias_no_mes'    => $diasNoMes,
            'realizado'      => round($receita, 2),
            'media_diaria'   => round($mediaDia, 2),
            'conservador'    => round(max(0, $provavel - $margem), 2),
            'provavel'       => round($provavel, 2),
            'otimista'       => round($provavel + $margem, 2),
            'pedidos'        => (int)($d['pedidos'] ?? 0),
            // Menos de 5 dias corridos não sustenta projeção nenhuma.
            'confianca'      => $diasPassados < 5 ? 'baixa'
                              : ($desvio > $mediaDia ? 'baixa' : 'media'),
            'aviso'          => 'Run-rate puro. O histórico começa em dez/2025 — '
                              . 'sem ano anterior, não há ajuste de sazonalidade.',
        ];
    }

    // ════════════════════════════════════════════════════
    // ALERTAS E INSIGHTS
    // ════════════════════════════════════════════════════

    /**
     * Alertas priorizados.
     *
     * Cada alerta só é emitido se o dado que o sustenta existir. Um
     * alerta que não pôde ser avaliado vira um item 'informativo'
     * dizendo isso — silêncio por falta de dado é indistinguível de
     * silêncio por estar tudo bem, e essa ambiguidade é o pior
     * resultado possível numa central de alertas.
     *
     * @return array<int,array{nivel:string,titulo:string,detalhe:string}>
     */
    public function alertas(array $p): array
    {
        $a = [];

        // Ruptura iminente: ≤ 7 dias de cobertura no ritmo atual.
        foreach ($this->giroEstoque(90, 100) as $g) {
            if ($g['dias_cobertura'] !== null && (int)$g['dias_cobertura'] <= 7 && (int)$g['saldo'] > 0) {
                $a[] = ['nivel' => 'critico',
                        'titulo' => 'Ruptura em ' . (int)$g['dias_cobertura'] . ' dias: ' . $g['produto'],
                        'detalhe' => (int)$g['saldo'] . ' em estoque, saída de '
                                   . number_format((float)$g['media_diaria'], 2, ',', '.') . '/dia'];
            }
        }

        // Queda de faturamento contra o período anterior equivalente.
        $k = $this->kpis($p);
        if ($k['faturamento']['variacao'] !== null && $k['faturamento']['variacao'] <= -20) {
            $a[] = ['nivel' => 'critico',
                    'titulo' => 'Faturamento caiu ' . abs($k['faturamento']['variacao']) . '%',
                    'detalhe' => 'contra o período anterior de mesma duração'];
        }

        // Taxa de aprovação de pagamento.
        foreach ($this->pagamentoAprovacao($p, 'metodo') as $m) {
            if ((int)$m['tentativas'] >= 5 && (float)$m['taxa_aprovacao'] < 70) {
                $a[] = ['nivel' => 'alto',
                        'titulo' => 'Aprovação baixa em ' . $m['chave'] . ': ' . $m['taxa_aprovacao'] . '%',
                        'detalhe' => (int)$m['aprovadas'] . ' de ' . (int)$m['tentativas'] . ' tentativas'];
            }
        }

        // Cliente de alto valor parado.
        foreach ($this->clientesRisco(5) as $c) {
            $a[] = ['nivel' => 'alto',
                    'titulo' => 'Cliente parado: ' . ($c['nome'] ?? '—'),
                    'detalhe' => (int)$c['dias_sem_comprar'] . ' dias sem comprar ('
                               . number_format((float)$c['vezes_o_normal'], 1, ',', '.')
                               . 'x o intervalo dele) · ' . $this->moeda((float)$c['receita'])];
        }

        // Estoque parado com valor conhecido.
        $paradoValor = 0.0;
        foreach ($this->estoqueParado(180, 200) as $e) $paradoValor += (float)($e['valor_estoque'] ?? 0);
        if ($paradoValor > 0) {
            $a[] = ['nivel' => 'medio',
                    'titulo' => $this->moeda($paradoValor) . ' parados há 180+ dias',
                    'detalhe' => 'produtos com saldo e sem venda no período'];
        }

        // Lacunas de dado que impedem análise — informativo, nunca silêncio.
        foreach ($this->saude() as $s) {
            if ((float)($s['pct'] ?? 0) < 50) {
                $a[] = ['nivel' => 'informativo',
                        'titulo' => 'Cobertura baixa: ' . $s['descricao'],
                        'detalhe' => ($s['pct'] ?? 0) . '% (' . $s['preenchido'] . ' de ' . $s['total']
                                   . ') — análises que dependem disto ficam incompletas'];
            }
        }

        $ordem = ['critico' => 0, 'alto' => 1, 'medio' => 2, 'informativo' => 3];
        usort($a, fn($x, $y) => $ordem[$x['nivel']] <=> $ordem[$y['nivel']]);
        return $a;
    }

    /**
     * Insights em texto. Só afirma o que o dado sustenta — nada aqui
     * é gerado quando a base é insuficiente.
     */
    public function insights(array $p): array
    {
        $out = [];
        $k   = $this->kpis($p);

        if ($k['faturamento']['variacao'] !== null) {
            $v = $k['faturamento']['variacao'];
            $out[] = ($v >= 0 ? 'Faturamento cresceu ' : 'Faturamento caiu ')
                   . abs($v) . '% contra o período anterior.';
        }
        if ($k['ticket_medio']['variacao'] !== null) {
            $v = $k['ticket_medio']['variacao'];
            $out[] = 'Ticket médio ' . ($v >= 0 ? 'subiu ' : 'caiu ') . abs($v) . '%.';
        }

        $par = $this->pareto($p, 'produto');
        if ($par['itens'] > 0 && $par['itens_80'] > 0) {
            $out[] = $par['itens_80'] . ' de ' . $par['itens'] . ' produtos ('
                   . $par['pct_itens'] . '%) concentram 80% da receita.';
        }

        $rec = $this->recompra();
        if ($rec['total_clientes'] > 0) {
            $out[] = 'Taxa de recompra em ' . $rec['taxa_recompra'] . '% sobre '
                   . $rec['total_clientes'] . ' clientes.';
        }

        $uf = $this->geografia($p, 'uf', 1);
        if (!empty($uf)) {
            $out[] = $uf[0]['local'] . ' lidera com ' . $this->moeda((float)$uf[0]['receita']) . '.';
        }

        $alta = $this->tendenciaProdutos(30, 'alta', 1);
        if (!empty($alta)) {
            $out[] = $alta[0]['nome_produto'] . ' cresceu '
                   . $alta[0]['variacao_pct'] . '% nos últimos 30 dias.';
        }

        // Se a margem não pode ser afirmada, dizer isso É o insight.
        $cob = 0.0;
        foreach ($this->saude() as $s) if ($s['indicador'] === 'custo_item') $cob = (float)$s['pct'];
        if ($cob < 100) {
            $out[] = 'Margem calculável sobre apenas ' . $cob . '% dos itens vendidos — '
                   . 'cadastre o custo para liberar as análises de rentabilidade.';
        }

        return $out;
    }

    /** Formatação mínima usada só nos textos de alerta/insight. */
    private function moeda(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    // ════════════════════════════════════════════════════
    // CLIPS
    // ════════════════════════════════════════════════════

    /**
     * Engajamento por clip, com a receita do produto que ele divulga.
     *
     * A pergunta que essa tabela responde não é "qual clip teve mais
     * view" — é "clip vende?". Por isso a receita do produto no
     * período viaja na mesma linha.
     *
     * ⚠ Correlação, não causalidade: não há como saber se a venda veio
     * do clip. Não existe atribuição de clip → pedido no schema. A
     * coluna serve para achar o padrão, não para creditar receita.
     */
    public function clips(array $p, int $limite = 30): array
    {
        return $this->todos(
            "SELECT c.clip_id, c.titulo, c.status, c.ativo, c.destaque,
                    c.autor_nome, c.produto_id, c.produto, c.marca_nome,
                    c.duracao_segundos, c.data,
                    c.views, c.views_unicas, c.likes, c.comentarios,
                    c.comentarios_aprovados, c.produtos_vinculados,
                    -- Taxas sobre view ÚNICA: sobre a bruta, um replay
                    -- do mesmo visitante derrubaria a taxa sem que o
                    -- engajamento tivesse mudado.
                    ROUND(100 * c.likes       / NULLIF(c.views_unicas,0), 1) AS taxa_like,
                    ROUND(100 * c.comentarios / NULLIF(c.views_unicas,0), 1) AS taxa_comentario,
                    ROUND(COALESCE(v.receita,0),2) AS receita_produto,
                    COALESCE(v.qtd,0)              AS qtd_produto
               FROM bi_fato_clip c
               LEFT JOIN (
                    -- Soma TODOS os produtos do clip, nao so o principal:
                    -- um clip divulga varios, e olhar so o primeiro
                    -- subestima o alcance comercial dele.
                    SELECT cp.clip_id,
                           SUM(i.receita)    AS receita,
                           SUM(i.quantidade) AS qtd
                      FROM bi_fato_clip_produto cp
                      JOIN bi_fato_item i ON i.produto_id = cp.produto_id
                     WHERE i.venda_valida = 1 AND i.data BETWEEN ? AND ?
                     GROUP BY cp.clip_id
               ) v ON v.clip_id = c.clip_id
              ORDER BY c.views_unicas DESC, c.likes DESC
              LIMIT " . (int)$limite,
            [$p['ini'], $p['fim']]
        );
    }

    /** KPIs de clips no período (pelas datas dos EVENTOS de view). */
    public function clipsResumo(array $p): array
    {
        $ev = $this->um(
            "SELECT COUNT(*) AS views,
                    COUNT(DISTINCT session_key) AS sessoes,
                    COUNT(DISTINCT clip_id)     AS clips_vistos
               FROM bi_fato_clip_view WHERE data BETWEEN ? AND ?",
            [$p['ini'], $p['fim']]
        );
        $cat = $this->um(
            "SELECT COUNT(*) AS clips,
                    COALESCE(SUM(ativo = 1),0)   AS ativos,
                    COALESCE(SUM(likes),0)       AS likes,
                    COALESCE(SUM(comentarios),0) AS comentarios,
                    COALESCE(SUM(views_unicas),0) AS views_unicas_total
               FROM bi_fato_clip"
        );
        return array_merge($ev, $cat);
    }

    /** Views de clip por dia, com o calendário como espinha. */
    public function clipsSerie(array $p): array
    {
        return $this->todos(
            "SELECT d.data,
                    COUNT(v.id)                   AS views,
                    COUNT(DISTINCT v.session_key) AS sessoes
               FROM bi_dim_data d
               LEFT JOIN bi_fato_clip_view v ON v.data = d.data
              WHERE d.data BETWEEN ? AND ?
              GROUP BY d.data ORDER BY d.data",
            [$p['ini'], $p['fim']]
        );
    }

    // ════════════════════════════════════════════════════
    // CARRINHO COMPARTILHADO
    // ════════════════════════════════════════════════════

    /**
     * Ranking de quem compartilha — cliente ou vendedor.
     *
     * `conversoes` conta o EVENTO 'finalizou_pedido';
     * `pedidos_identificados` conta o pedido de fato amarrado.
     *
     * As duas existem separadas porque `uso.pedido_id` hoje é sempre
     * NULL: colapsar tudo numa coluna só faria a conversão real
     * desaparecer da tela junto com a receita que não dá para medir.
     */
    public function compartilhadores(array $p, int $limite = 25): array
    {
        return $this->todos(
            "SELECT compartilhador, origem,
                    COUNT(*)                              AS compartilhamentos,
                    COALESCE(SUM(itens),0)                AS itens,
                    ROUND(COALESCE(SUM(total),0),2)       AS valor_compartilhado,
                    ROUND(COALESCE(AVG(total),0),2)       AS ticket_compartilhado,
                    COALESCE(SUM(visualizacoes_unicas),0) AS visualizacoes,
                    COALESCE(SUM(carrinhos_criados),0)    AS carrinhos_criados,
                    COALESCE(SUM(conversoes),0)           AS conversoes,
                    COALESCE(SUM(pedidos_identificados),0) AS pedidos_identificados,
                    ROUND(COALESCE(SUM(receita_identificada),0),2) AS receita,
                    ROUND(100 * COALESCE(SUM(conversoes),0)
                        / NULLIF(SUM(visualizacoes_unicas),0), 1)  AS taxa_conversao
               FROM bi_fato_compartilhamento
              WHERE data BETWEEN ? AND ?
              GROUP BY compartilhador, origem
              ORDER BY conversoes DESC, visualizacoes DESC, valor_compartilhado DESC
              LIMIT " . (int)$limite,
            [$p['ini'], $p['fim']]
        );
    }

    /** Funil e KPIs do compartilhamento no período. */
    public function compartilhamentos(array $p): array
    {
        $k = $this->um(
            "SELECT COUNT(*)                               AS compartilhamentos,
                    ROUND(COALESCE(SUM(total),0),2)        AS valor_compartilhado,
                    ROUND(COALESCE(AVG(total),0),2)        AS ticket,
                    COALESCE(SUM(visualizacoes_unicas),0)  AS visualizacoes,
                    COALESCE(SUM(carrinhos_criados),0)     AS carrinhos_criados,
                    COALESCE(SUM(conversoes),0)            AS conversoes,
                    COALESCE(SUM(pedidos_identificados),0) AS pedidos_identificados,
                    ROUND(COALESCE(SUM(receita_identificada),0),2) AS receita,
                    COALESCE(SUM(expirado = 1),0)          AS expirados
               FROM bi_fato_compartilhamento
              WHERE data BETWEEN ? AND ?",
            [$p['ini'], $p['fim']]
        );

        // As etapas NÃO são estritamente encadeadas: existe conversão
        // registrada sem que 'criou_carrinho' tenha sido gravado antes.
        // Encadear daria "0% e depois 1 pedido", que é aritmética certa
        // e leitura errada.
        //
        // Visualizar é o único pré-requisito real, então carrinho e
        // pedido convertem contra VISUALIZADOS.
        $vis = (int)$k['visualizacoes'];
        $etapas = [
            ['etapa' => 'Compartilhados',   'valor' => (int)$k['compartilhamentos'], 'base' => null],
            ['etapa' => 'Visualizados',     'valor' => $vis,                          'base' => 'dos compartilhados'],
            ['etapa' => 'Viraram carrinho', 'valor' => (int)$k['carrinhos_criados'],  'base' => 'dos visualizados'],
            ['etapa' => 'Viraram pedido',   'valor' => (int)$k['conversoes'],         'base' => 'dos visualizados'],
        ];
        $comp = max(1, (int)$k['compartilhamentos']);
        foreach ($etapas as $i => &$e) {
            if ($i === 0)      { $e['conversao'] = null; }
            elseif ($i === 1)  { $e['conversao'] = round(100 * $e['valor'] / $comp, 1); }
            else               { $e['conversao'] = $vis > 0 ? round(100 * $e['valor'] / $vis, 1) : null; }
        }

        return ['kpi' => $k, 'funil' => $etapas];
    }

    // ════════════════════════════════════════════════════
    // PERGUNTAS DE PRODUTO E USO DE IA
    // ════════════════════════════════════════════════════

    /**
     * KPIs de atendimento às perguntas de produto.
     *
     * A fila (`aguardando_ia`) é o número que mais importa aqui:
     * pergunta sem resposta é cliente esperando na loja.
     */
    public function perguntasResumo(array $p): array
    {
        $k = $this->um(
            "SELECT COUNT(*)                                  AS perguntas,
                    COALESCE(SUM(respondida),0)               AS respondidas,
                    COALESCE(SUM(respondida_por_ia),0)        AS por_ia,
                    COALESCE(SUM(respondida_por_admin),0)     AS por_admin,
                    COALESCE(SUM(status = 'aguardando_ia'),0)    AS fila_ia,
                    COALESCE(SUM(status = 'aguardando_admin'),0) AS fila_admin,
                    COALESCE(SUM(status = 'rejeitada'),0)        AS rejeitadas,
                    COALESCE(SUM(votos_uteis),0)              AS votos_uteis,
                    ROUND(AVG(minutos_ate_responder))         AS minutos_medio
               FROM bi_fato_pergunta
              WHERE data BETWEEN ? AND ?",
            [$p['ini'], $p['fim']]
        );

        // Total FORA da janela também. Sem isto, "0 perguntas" no
        // período fica indistinguível de "o painel não carregou" —
        // foi exatamente a dúvida que motivou esta métrica.
        $k['total_geral'] = (int)$this->valor("SELECT COUNT(*) FROM bi_fato_pergunta");
        $k['primeira']    = $this->valor("SELECT MIN(data) FROM bi_fato_pergunta");
        $k['ultima']      = $this->valor("SELECT MAX(data) FROM bi_fato_pergunta");

        $resp = max(1, (int)$k['respondidas']);
        $k['pct_ia']       = round(100 * (int)$k['por_ia'] / $resp, 1);
        $k['pct_resposta'] = (int)$k['perguntas'] > 0
            ? round(100 * (int)$k['respondidas'] / (int)$k['perguntas'], 1) : 0.0;

        return $k;
    }

    /**
     * Quem responde — IA e cada pessoa do time, lado a lado.
     *
     * `votos_uteis` é ENDOSSO, não satisfação: existe voto "útil" e
     * não existe "não foi útil". Ausência de voto não é insatisfação,
     * e chamar a coluna de "satisfação" faria parecer que é.
     */
    public function quemResponde(array $p, int $limite = 20): array
    {
        // A IA aparece POR MODELO quando a procedência existe
        // (respostas via Central, desde 03/09/2026). As antigas
        // caem em 'modelo não registrado' — separadas de propósito,
        // para o número novo não herdar o passado sem origem.
        return $this->todos(
            "SELECT CASE WHEN resposta_fonte = 'ia'
                         THEN CONCAT('IA · ', COALESCE(ia_modelo, 'modelo não registrado'))
                         ELSE COALESCE(respondida_por_nome, 'Admin sem identificação')
                    END COLLATE utf8mb4_unicode_ci AS quem,
                    COALESCE(resposta_fonte,'—') COLLATE utf8mb4_unicode_ci AS fonte,
                    COUNT(*)                          AS respostas,
                    COALESCE(SUM(votos_uteis),0)      AS votos_uteis,
                    ROUND(AVG(minutos_ate_responder)) AS minutos_medio,
                    ROUND(AVG(tam_resposta))          AS tam_medio,
                    ROUND(COALESCE(SUM(votos_uteis),0) / NULLIF(COUNT(*),0), 2) AS uteis_por_resposta
               FROM bi_fato_pergunta
              WHERE respondida = 1 AND data BETWEEN ? AND ?
              GROUP BY quem, fonte
              ORDER BY respostas DESC
              LIMIT " . (int)$limite,
            [$p['ini'], $p['fim']]
        );
    }

    /** Produtos que mais geram pergunta — sinal de descrição fraca. */
    public function perguntasPorProduto(array $p, int $limite = 15): array
    {
        return $this->todos(
            "SELECT produto_id, COALESCE(produto,'—') COLLATE utf8mb4_unicode_ci AS produto,
                    marca_nome,
                    COUNT(*)                              AS perguntas,
                    COALESCE(SUM(respondida),0)           AS respondidas,
                    COALESCE(SUM(status='aguardando_ia'),0) AS na_fila,
                    COALESCE(SUM(votos_uteis),0)          AS votos_uteis
               FROM bi_fato_pergunta
              WHERE data BETWEEN ? AND ? AND produto_id IS NOT NULL
              GROUP BY produto_id, produto, marca_nome
              ORDER BY perguntas DESC
              LIMIT " . (int)$limite,
            [$p['ini'], $p['fim']]
        );
    }

    /**
     * Uso de IA por provedor/modelo.
     *
     * Cobre tudo que passou pelo roteador de IA: geração de conteúdo,
     * imagens, o atendimento via CHAT e — desde 03/09/2026 — as respostas
     * das perguntas de produto (tipo `qa_produto`).
     *
     * ⚠ As perguntas respondidas ANTES dessa data não aparecem: naquele
     * caminho o GeminiQAService chamava o Gemini direto e não registrava
     * nada. Não há como reconstruir o modelo delas.
     *
     * As falhas entram na conta com provedor '(não roteado)'. Contar
     * só as concluídas esconderia exatamente o que um diagnóstico
     * precisa mostrar.
     */
    public function iaPorModelo(array $p, ?string $tipo = null, int $limite = 20): array
    {
        $where  = 'data BETWEEN ? AND ?';
        $params = [$p['ini'], $p['fim']];
        if ($tipo !== null) { $where .= ' AND tipo = ?'; $params[] = $tipo; }

        return $this->todos(
            "SELECT provedor, modelo, tipo,
                    COUNT(*)                          AS execucoes,
                    COALESCE(SUM(concluida),0)        AS concluidas,
                    COALESCE(SUM(falhou),0)           AS falhas,
                    ROUND(100 * COALESCE(SUM(concluida),0) / NULLIF(COUNT(*),0), 1) AS taxa_sucesso,
                    -- Só o custo REAL entra como gasto. Cair no estimado
                    -- quando o real é NULL fazia geração FALHADA aparecer
                    -- com dinheiro gasto — e falha não custou nada.
                    ROUND(COALESCE(SUM(custo_real_usd),0), 4)      AS custo_usd,
                    ROUND(COALESCE(SUM(custo_estimado_usd),0), 4)  AS custo_estimado_usd,
                    COALESCE(SUM(custo_real_usd IS NOT NULL),0)    AS com_custo_real,
                    COALESCE(SUM(tokens_in),0)        AS tokens_in,
                    COALESCE(SUM(tokens_out),0)       AS tokens_out,
                    ROUND(AVG(NULLIF(tempo_ms,0)))    AS ms_medio
               FROM bi_fato_ia
              WHERE {$where}
              GROUP BY provedor, modelo, tipo
              ORDER BY execucoes DESC
              LIMIT " . (int)$limite,
            $params
        );
    }

    /** Uso de IA agregado por tipo de conteúdo. */
    public function iaPorTipo(array $p): array
    {
        return $this->todos(
            "SELECT COALESCE(tipo_nome, tipo, '(sem tipo)')
                        COLLATE utf8mb4_unicode_ci AS tipo,
                    COUNT(*)                   AS execucoes,
                    COALESCE(SUM(concluida),0) AS concluidas,
                    COALESCE(SUM(falhou),0)    AS falhas,
                    ROUND(COALESCE(SUM(custo_real_usd),0), 4)     AS custo_usd,
                    ROUND(COALESCE(SUM(custo_estimado_usd),0), 4) AS custo_estimado_usd
               FROM bi_fato_ia
              WHERE data BETWEEN ? AND ?
              GROUP BY tipo
              ORDER BY execucoes DESC",
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
