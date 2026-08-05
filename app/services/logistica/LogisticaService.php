<?php
/**
 * LogisticaService — núcleo de leitura da Torre de Controle.
 *
 * Concentra as agregações do painel. Todas as queries são defensivas:
 * com o banco vazio, devolvem zero (nunca quebram a tela). Os filtros
 * são sempre parametrizados (PDO prepare/execute).
 *
 * Princípio de fonte única: os números vêm direto das tabelas de
 * operação (log_rastreios, log_etiquetas, log_divergencias, ...), sem
 * colunas de métrica denormalizadas para manter em sincronia.
 */
class LogisticaService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        // Injeção opcional para testabilidade (padrão do projeto).
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /**
     * Payload completo da Torre.
     * @param array $filtros periodo, inicio, fim, transportadora_id, servico, status, uf, canal
     */
    public function torre(array $filtros = []): array
    {
        [$where, $params] = $this->whereRastreios($filtros);

        return [
            'kpis'          => $this->kpis($where, $params, $filtros),
            'distribuicao'  => $this->distribuicaoPrazo($where, $params),
            'alertas'       => $this->alertas(),
            'periodo'       => $this->rotuloPeriodo($filtros),
        ];
    }

    /* ---------------------------------------------------------------
       KPIs
       --------------------------------------------------------------- */
    private function kpis(string $where, array $params, array $filtros): array
    {
        // Bloco sobre rastreios (a maioria dos indicadores operacionais)
        $sql = "
            SELECT
                COUNT(*)                                                              AS total_envios,
                SUM(status_interno = 'entregue')                                      AS entregues,
                SUM(status_interno IN ('em_transito','saiu_entrega'))                 AS em_transito,
                SUM(atraso = 1)                                                        AS atrasados,
                SUM(ocorrencia = 1)                                                    AS ocorrencias,
                SUM(status_interno = 'entregue'
                    AND (previsao_entrega IS NULL OR DATE(entregue_em) <= previsao_entrega)) AS entregues_no_prazo,
                AVG(CASE WHEN status_interno = 'entregue' AND postado_em IS NOT NULL AND entregue_em IS NOT NULL
                         THEN DATEDIFF(entregue_em, postado_em) END)                  AS prazo_medio
            FROM log_rastreios r
            {$where}
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $total       = (int)($r['total_envios'] ?? 0);
        $entregues   = (int)($r['entregues'] ?? 0);
        $noPrazoQtd  = (int)($r['entregues_no_prazo'] ?? 0);
        $noPrazoPct  = $entregues > 0 ? round($noPrazoQtd / $entregues * 100, 1) : 0.0;

        return [
            'total_envios'          => $total,
            'entregues'             => $entregues,
            'em_transito'           => (int)($r['em_transito'] ?? 0),
            'no_prazo_pct'          => $noPrazoPct,
            'atrasados'             => (int)($r['atrasados'] ?? 0),
            'ocorrencias'           => (int)($r['ocorrencias'] ?? 0),
            'etiquetas_aguardando'  => $this->etiquetasAguardando(),
            'reversas_abertas'      => $this->reversasAbertas(),
            'gasto_fretes'          => $this->gastoFretes($filtros),
            'divergencias_valor'    => $this->divergenciasAcumuladas(),
            'prazo_medio'           => $r['prazo_medio'] !== null ? round((float)$r['prazo_medio'], 1) : 0.0,
            'falhas_integracao'     => $this->falhasIntegracao(),
        ];
    }

    private function etiquetasAguardando(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM log_etiquetas
             WHERE status IN ('aguardando_postagem','emitida')"
        );
        return (int)$stmt->fetchColumn();
    }

    private function reversasAbertas(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM log_reversas
             WHERE status NOT IN ('recebida','cancelada')"
        );
        return (int)$stmt->fetchColumn();
    }

    private function gastoFretes(array $filtros): float
    {
        [$ini, $fim] = $this->intervalo($filtros);
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(valor),0) FROM log_etiquetas
             WHERE status <> 'cancelada' AND criado_em BETWEEN :ini AND :fim"
        );
        $stmt->execute([':ini' => $ini, ':fim' => $fim]);
        return round((float)$stmt->fetchColumn(), 2);
    }

    private function divergenciasAcumuladas(): float
    {
        $stmt = $this->pdo->query(
            "SELECT COALESCE(SUM(ABS(diferenca_valor)),0) FROM log_divergencias
             WHERE status IN ('aberta','em_analise')"
        );
        return round((float)$stmt->fetchColumn(), 2);
    }

    private function falhasIntegracao(): int
    {
        // Transportadoras ativas cuja última comunicação recente falhou.
        $stmt = $this->pdo->query(
            "SELECT COUNT(DISTINCT transportadora_id) FROM log_comunicacoes
             WHERE sucesso = 0 AND criado_em >= (NOW() - INTERVAL 1 HOUR)"
        );
        return (int)$stmt->fetchColumn();
    }

    /* ---------------------------------------------------------------
       Distribuição D+0 .. D+7+  (+ coluna de atraso)
       --------------------------------------------------------------- */
    private function distribuicaoPrazo(string $where, array $params): array
    {
        $sql = "
            SELECT
                CASE
                    WHEN DATEDIFF(entregue_em, postado_em) >= 7 THEN 7
                    WHEN DATEDIFF(entregue_em, postado_em) < 0 THEN 0
                    ELSE DATEDIFF(entregue_em, postado_em)
                END AS dbucket,
                COUNT(*) AS qtd
            FROM log_rastreios r
            {$where}
              AND status_interno = 'entregue'
              AND postado_em IS NOT NULL AND entregue_em IS NOT NULL
            GROUP BY dbucket
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $buckets = array_fill(0, 8, 0); // D+0..D+7+
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $buckets[(int)$row['dbucket']] += (int)$row['qtd'];
        }

        // Coluna de atraso (envios marcados como atrasados no recorte)
        $sqlAtraso = "SELECT COUNT(*) FROM log_rastreios r {$where} AND atraso = 1";
        $stmt2 = $this->pdo->prepare($sqlAtraso);
        $stmt2->execute($params);
        $atraso = (int)$stmt2->fetchColumn();

        $out = [];
        foreach ($buckets as $i => $qtd) {
            $out[] = ['rotulo' => 'D+' . $i . ($i === 7 ? '+' : ''), 'qtd' => $qtd, 'atraso' => false];
        }
        $out[] = ['rotulo' => 'Atraso', 'qtd' => $atraso, 'atraso' => true];
        return $out;
    }

    /* ---------------------------------------------------------------
       Alertas operacionais (com atalho para a lista filtrada)
       --------------------------------------------------------------- */
    public function alertas(): array
    {
        $alertas = [];

        $atrasados = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM log_rastreios WHERE atraso = 1 AND status_interno <> 'entregue'"
        )->fetchColumn();
        if ($atrasados > 0) {
            $alertas[] = [
                'nivel' => 'danger', 'icone' => 'bi-exclamation-octagon',
                'titulo' => "{$atrasados} envios atrasados",
                'descricao' => 'Prazo estourado sem entrega confirmada.',
                'link' => '/admin/logistica/rastreios?status=atrasado',
            ];
        }

        $paradas = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM log_etiquetas
             WHERE status IN ('aguardando_postagem','emitida')
               AND criado_em <= (NOW() - INTERVAL 24 HOUR)"
        )->fetchColumn();
        if ($paradas > 0) {
            $alertas[] = [
                'nivel' => 'warn', 'icone' => 'bi-box',
                'titulo' => "{$paradas} etiquetas paradas há +24h",
                'descricao' => 'Emitidas, ainda não postadas.',
                'link' => '/admin/logistica/etiquetas?status=aguardando_postagem',
            ];
        }

        $divAlto = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM log_divergencias
             WHERE status IN ('aberta','em_analise') AND nivel_impacto = 'alto'"
        )->fetchColumn();
        if ($divAlto > 0) {
            $alertas[] = [
                'nivel' => 'warn', 'icone' => 'bi-scale',
                'titulo' => 'Divergências de alto impacto',
                'descricao' => "{$divAlto} em aberto acima do limite.",
                'link' => '/admin/logistica/divergencias?impacto=alto',
            ];
        }

        $falhas = $this->falhasIntegracao();
        if ($falhas > 0) {
            $alertas[] = [
                'nivel' => 'info', 'icone' => 'bi-plug',
                'titulo' => "{$falhas} transportadora(s) com falha de comunicação",
                'descricao' => 'Verifique credenciais e sincronização.',
                'link' => '/admin/logistica/transportadoras',
            ];
        }

        return $alertas;
    }

    /* ---------------------------------------------------------------
       Opções para os selects de filtro
       --------------------------------------------------------------- */
    public function filtrosOpcoes(): array
    {
        $transportadoras = $this->pdo->query(
            "SELECT id, nome FROM log_transportadoras ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ufs = $this->pdo->query(
            "SELECT DISTINCT destino_uf FROM log_rastreios
             WHERE destino_uf IS NOT NULL AND destino_uf <> '' ORDER BY destino_uf"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $canais = $this->pdo->query(
            "SELECT DISTINCT canal FROM log_rastreios WHERE canal <> '' ORDER BY canal"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return [
            'transportadoras' => $transportadoras,
            'ufs'             => $ufs,
            'canais'          => $canais ?: ['site'],
            'status'          => [
                'em_transito' => 'Em trânsito',
                'entregue'    => 'Entregue',
                'atrasado'    => 'Atrasado',
                'ocorrencia'  => 'Com ocorrência',
                'postado'     => 'Postado',
            ],
        ];
    }

    /* ---------------------------------------------------------------
       Helpers de período / WHERE
       --------------------------------------------------------------- */

    /** Devolve [inicio, fim] em 'Y-m-d H:i:s' a partir dos filtros. */
    private function intervalo(array $filtros): array
    {
        if (!empty($filtros['inicio']) && !empty($filtros['fim'])) {
            return [
                (new DateTimeImmutable($filtros['inicio']))->format('Y-m-d 00:00:00'),
                (new DateTimeImmutable($filtros['fim']))->format('Y-m-d 23:59:59'),
            ];
        }
        $dias = match ($filtros['periodo'] ?? '7d') {
            '30d'  => 30,
            'mes'  => (int)date('j'),
            'hoje' => 0,
            default => 7,
        };
        $ini = (new DateTimeImmutable("-{$dias} days"))->format('Y-m-d 00:00:00');
        return [$ini, (new DateTimeImmutable('now'))->format('Y-m-d 23:59:59')];
    }

    /** Monta o WHERE sobre log_rastreios r com base nos filtros. */
    private function whereRastreios(array $filtros): array
    {
        [$ini, $fim] = $this->intervalo($filtros);
        $cond = ['r.criado_em BETWEEN :ini AND :fim'];
        $params = [':ini' => $ini, ':fim' => $fim];

        if (!empty($filtros['transportadora_id'])) {
            $cond[] = 'r.transportadora_id = :tid';
            $params[':tid'] = (int)$filtros['transportadora_id'];
        }
        if (!empty($filtros['uf'])) {
            $cond[] = 'r.destino_uf = :uf';
            $params[':uf'] = strtoupper(substr((string)$filtros['uf'], 0, 2));
        }
        if (!empty($filtros['canal'])) {
            $cond[] = 'r.canal = :canal';
            $params[':canal'] = (string)$filtros['canal'];
        }
        if (!empty($filtros['status'])) {
            $st = (string)$filtros['status'];
            if ($st === 'atrasado') {
                $cond[] = 'r.atraso = 1';
            } elseif ($st === 'ocorrencia') {
                $cond[] = 'r.ocorrencia = 1';
            } elseif ($st === 'em_transito') {
                $cond[] = "r.status_interno IN ('em_transito','saiu_entrega')";
            } else {
                $cond[] = 'r.status_interno = :st';
                $params[':st'] = $st;
            }
        }
        if (!empty($filtros['servico'])) {
            // serviço vive na etiqueta; filtra por subconsulta enxuta
            $cond[] = 'r.etiqueta_id IN (SELECT id FROM log_etiquetas WHERE servico_codigo = :serv)';
            $params[':serv'] = (string)$filtros['servico'];
        }

        return ['WHERE ' . implode(' AND ', $cond), $params];
    }

    private function rotuloPeriodo(array $filtros): string
    {
        return match ($filtros['periodo'] ?? '7d') {
            '30d'  => 'Últimos 30 dias',
            'mes'  => 'Este mês',
            'hoje' => 'Hoje',
            default => 'Últimos 7 dias',
        };
    }

    /**
     * Alertas logísticos acionáveis para o badge do dashboard.
     *
     * Cada contagem é defensiva (try/catch): tabela ainda sem uso simplesmente
     * não gera badge — nada quebra a home. Só retorna itens com count > 0, já
     * na ordem de exibição. Enquanto as fases 4–7 (etiquetas, rastreios,
     * reversas, divergências) não estão ativas, essas contagens ficam em zero
     * e os badges não aparecem; o de falha de integração já funciona hoje
     * (alimentado por log_comunicacoes a cada teste/cotação).
     *
     * @return array<int,array{chave:string,count:int,tom:string,icone:string,url:string,rotulo:string}>
     */
    public function badgesDashboard(): array
    {
        $defs = [
            [
                'chave'  => 'etiquetas',
                'sql'    => "SELECT COUNT(*) FROM log_rastreios WHERE status_interno = 'aguardando_etiqueta'",
                'tom'    => 'warning',
                'icone'  => 'etiqueta',
                'url'    => '/admin/logistica/etiquetas',
                'rotulo' => fn(int $n) => $n . ' etiqueta(s) a emitir',
            ],
            [
                'chave'  => 'ocorrencias',
                'sql'    => "SELECT COUNT(*) FROM log_rastreios WHERE status_interno = 'ocorrencia'",
                'tom'    => 'base',
                'icone'  => 'alerta',
                'url'    => '/admin/logistica/rastreios?status=ocorrencia',
                'rotulo' => fn(int $n) => $n . ' envio(s) com ocorrência',
            ],
            [
                'chave'  => 'atrasados',
                'sql'    => "SELECT COUNT(*) FROM log_rastreios WHERE atraso = 1 AND status_interno NOT IN ('entregue','devolucao','ocorrencia')",
                'tom'    => 'base',
                'icone'  => 'relogio',
                'url'    => '/admin/logistica/rastreios?filtro=atrasados',
                'rotulo' => fn(int $n) => $n . ' envio(s) atrasado(s)',
            ],
            [
                'chave'  => 'reversas',
                'sql'    => "SELECT COUNT(*) FROM log_reversas WHERE status IN ('solicitada','autorizada')",
                'tom'    => 'warning',
                'icone'  => 'reversa',
                'url'    => '/admin/logistica/reversas',
                'rotulo' => fn(int $n) => $n . ' reversa(s) em aberto',
            ],
            [
                'chave'  => 'divergencias',
                'sql'    => "SELECT COUNT(*) FROM log_divergencias WHERE status = 'aberta'",
                'tom'    => 'warning',
                'icone'  => 'divergencia',
                'url'    => '/admin/logistica/divergencias',
                'rotulo' => fn(int $n) => $n . ' divergência(s) de frete',
            ],
            [
                'chave'  => 'integracao',
                'sql'    => "SELECT COUNT(*) FROM log_comunicacoes WHERE sucesso = 0 AND criado_em >= (NOW() - INTERVAL 24 HOUR)",
                'tom'    => 'base',
                'icone'  => 'plug',
                'url'    => '/admin/logistica/transportadoras',
                'rotulo' => fn(int $n) => $n . ' falha(s) de integração (24h)',
            ],
        ];

        $itens = [];
        foreach ($defs as $d) {
            try {
                $n = (int)$this->pdo->query($d['sql'])->fetchColumn();
            } catch (\Throwable $e) {
                $n = 0; // tabela ausente/indisponível — silencioso, não derruba a home
            }
            if ($n > 0) {
                $itens[] = [
                    'chave'  => $d['chave'],
                    'count'  => $n,
                    'tom'    => $d['tom'],
                    'icone'  => $d['icone'],
                    'url'    => $d['url'],
                    'rotulo' => ($d['rotulo'])($n),
                ];
            }
        }
        return $itens;
    }
}
