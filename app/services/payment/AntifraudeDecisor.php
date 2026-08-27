<?php
declare(strict_types=1);

/**
 * app/services/payment/AntifraudeDecisor.php
 *
 * Decide, ANTES de gastar uma consulta na ClearSale, o que fazer com o pedido:
 * aprovar direto, mandar para o antifraude, ou reter para decisão humana.
 *
 * TRÊS DESFECHOS:
 *   APROVAR     — não consulta ninguém. O pedido segue aprovado.
 *   ANTIFRAUDE  — consulta a ClearSale e OBEDECE a resposta dela.
 *   ANALISE     — consulta a ClearSale para ter o parecer, mas retém o pedido
 *                 para decisão humana independente do que ela responder.
 *
 * PRECEDÊNCIA: a primeira regra que casar decide, e a escada é ordenada por
 * RISCO. O atalho de aprovação vem por último de propósito — assim ele nunca
 * ganha de um sinal de risco. Sem essa ordem explícita, as regras colidem:
 * conta nova é sempre bronze, e chargeback costuma derrubar para bronze.
 *
 * O QUE ESTA CLASSE NÃO FAZ: não fala com a ClearSale nem muda status de
 * pedido. Ela só decide. Executar é do motor.
 */
class AntifraudeDecisor
{
    public const APROVAR    = 'aprovar';
    public const ANTIFRAUDE = 'antifraude';
    public const ANALISE    = 'analise';

    /** Recência: sem comprar há mais de 60 dias muda o tratamento por tier. */
    private const DIAS_INATIVO      = 60;
    /** Sem comprar há mais de 6 meses: antifraude para qualquer tier. */
    private const DIAS_MUITO_TEMPO  = 180;
    /** Mais de 1 pedido nesta janela é velocidade suspeita. */
    private const JANELA_VELOCIDADE_HORAS = 24;

    private PDO $db;
    private ScoreService $score;

    public function __construct(?PDO $db = null, ?ScoreService $score = null)
    {
        $this->db    = $db ?? Database::getInstance()->getConnection();
        $this->score = $score ?? new ScoreService();
    }

    /**
     * @param int   $clienteId
     * @param array $ctx  bandeira, valor_centavos, cartao_hash (opcional)
     * @return array{decisao:string, regra:string, motivo:string,
     *               score:int, tier:string, fatores:array}
     */
    public function decidir(int $clienteId, array $ctx = []): array
    {
        $f = $this->coletar($clienteId);

        foreach ($this->escada() as $regra) {
            $r = $regra($f, $ctx);
            if ($r !== null) {
                return $r + ['score' => $f['score'], 'tier' => $f['tier'], 'fatores' => $f];
            }
        }

        // Nenhuma regra casou. Conservador: consulta o antifraude.
        return [
            'decisao' => self::ANTIFRAUDE,
            'regra'   => 'padrao',
            'motivo'  => 'Nenhuma regra de dispensa aplicável.',
            'score'   => $f['score'],
            'tier'    => $f['tier'],
            'fatores' => $f,
        ];
    }

    /**
     * A escada, em ordem de precedência. Cada regra devolve o desfecho ou
     * null para passar adiante.
     *
     * @return array<callable(array,array):?array>
     */
    private function escada(): array
    {
        return [
            // 1. Fraude confirmada antes: trava permanente.
            static function (array $f): ?array {
                if (empty($f['fraude_confirmada'])) return null;
                return ['decisao' => self::ANTIFRAUDE, 'regra' => 'fraude_confirmada',
                        'motivo'  => 'Cliente com fraude confirmada no histórico — sempre verificar.'];
            },

            // 2. Chargeback: o banco já contestou uma compra deste cliente.
            static function (array $f): ?array {
                if ((int) $f['total_chargebacks'] < 1) return null;
                return ['decisao' => self::ANTIFRAUDE, 'regra' => 'chargeback',
                        'motivo'  => $f['total_chargebacks'] . ' chargeback(s) no histórico.'];
            },

            // 3. Inspeção de devolução reprovada: sinal de má-fé em devolução.
            static function (array $f): ?array {
                if ((int) $f['total_reprovadas'] < 1) return null;
                return ['decisao' => self::ANALISE, 'regra' => 'inspecao_reprovada',
                        'motivo'  => $f['total_reprovadas'] . ' devolução(ões) com inspeção reprovada.'];
            },

            // 4. Conta nova, nunca comprou. Vem ANTES da regra de bronze
            //    porque conta nova é sempre bronze — sem esta ordem, todo
            //    cliente novo cairia em análise em vez de antifraude.
            static function (array $f): ?array {
                if ((int) $f['total_pedidos'] > 0) return null;
                return ['decisao' => self::ANTIFRAUDE, 'regra' => 'conta_nova',
                        'motivo'  => 'Primeira compra — sem histórico para avaliar.'];
            },

            // 5. Velocidade: vários pedidos em pouco tempo.
            //    Cartões diferentes agravam — é o padrão clássico de teste
            //    de cartões roubados.
            static function (array $f): ?array {
                if ((int) $f['pedidos_24h'] < 2) return null;
                $extra = (int) $f['cartoes_24h'] > 1
                    ? ' com ' . $f['cartoes_24h'] . ' cartões diferentes'
                    : '';
                return ['decisao' => self::ANALISE, 'regra' => 'velocidade_24h',
                        'motivo'  => $f['pedidos_24h'] . ' pedidos em 24h' . $extra . '.'];
            },

            // 6. Muito tempo parado: qualquer tier vai para o antifraude.
            static function (array $f): ?array {
                $dias = $f['dias_ultimo_pedido'];
                if ($dias === null || $dias <= self::DIAS_MUITO_TEMPO) return null;
                return ['decisao' => self::ANTIFRAUDE, 'regra' => 'inativo_6_meses',
                        'motivo'  => 'Sem comprar há ' . $dias . ' dias.'];
            },

            // 7. Bronze: sempre análise, qualquer que seja o resto.
            static function (array $f): ?array {
                if ($f['tier'] !== 'bronze') return null;
                return ['decisao' => self::ANALISE, 'regra' => 'tier_bronze',
                        'motivo'  => 'Score em Bronze (' . $f['score'] . ' pts).'];
            },

            // 8. Inativo entre 60 dias e 6 meses: o tier decide o rigor.
            static function (array $f): ?array {
                $dias = $f['dias_ultimo_pedido'];
                if ($dias === null || $dias <= self::DIAS_INATIVO) return null;

                return match ($f['tier']) {
                    'silver'   => ['decisao' => self::ANTIFRAUDE, 'regra' => 'inativo_silver',
                                   'motivo'  => 'Silver sem comprar há ' . $dias . ' dias.'],
                    'gold'     => ['decisao' => self::ANALISE, 'regra' => 'inativo_gold',
                                   'motivo'  => 'Gold sem comprar há ' . $dias . ' dias.'],
                    'platinum' => ['decisao' => self::APROVAR, 'regra' => 'inativo_platinum',
                                   'motivo'  => 'Platinum — histórico dispensa verificação.'],
                    default    => null,
                };
            },

            // 9. Atalho de aprovação. ÚLTIMO da escada: qualquer sinal de
            //    risco acima já teria decidido antes de chegar aqui.
            static function (array $f): ?array {
                if ($f['tier'] === 'bronze')                    return null;
                if ((int) $f['total_concluidos'] < 2)           return null;
                if ((int) $f['total_devolucoes'] > 0)           return null;
                return ['decisao' => self::APROVAR, 'regra' => 'cliente_recorrente',
                        'motivo'  => ucfirst($f['tier']) . ' com ' . $f['total_concluidos']
                                   . ' pedidos concluídos e nenhuma devolução.'];
            },
        ];
    }

    // =========================================================================
    // PÓS-CLEARSALE
    // =========================================================================

    /**
     * Traduz a resposta da ClearSale no desfecho final, cruzando com o score
     * local — é onde o cliente bom "compra" tolerância a risco médio.
     *
     * @param string $recomendacao aprovado | reprovado | revisao | fraude
     * @param string $risco        baixo | medio | alto
     */
    public function decidirPosAnalise(string $recomendacao, string $risco, string $tier): array
    {
        $recomendacao = strtolower($recomendacao);
        $risco        = strtolower($risco);

        if ($recomendacao === 'fraude') {
            return ['decisao' => self::ANALISE, 'regra' => 'fraude_confirmada_clearsale',
                    'motivo'  => 'ClearSale confirmou fraude. Score zerado e cliente marcado.'];
        }

        if ($recomendacao === 'reprovado') {
            return ['decisao' => self::ANALISE, 'regra' => 'reprovado_clearsale',
                    'motivo'  => 'ClearSale reprovou o pedido.'];
        }

        // Risco alto retém sempre, mesmo com o cliente sendo bom — é o único
        // caso em que o score local não compra tolerância.
        if ($risco === 'alto') {
            return ['decisao' => self::ANALISE, 'regra' => 'risco_alto',
                    'motivo'  => 'Risco alto apontado pelo antifraude.'];
        }

        if ($risco === 'medio') {
            $acimaDeSilver = in_array($tier, ['gold', 'platinum'], true);
            return $acimaDeSilver
                ? ['decisao' => self::APROVAR, 'regra' => 'risco_medio_cliente_bom',
                   'motivo'  => 'Risco médio, mas cliente ' . ucfirst($tier) . '.']
                : ['decisao' => self::ANALISE, 'regra' => 'risco_medio',
                   'motivo'  => 'Risco médio e cliente ' . ucfirst($tier) . '.'];
        }

        return ['decisao' => self::APROVAR, 'regra' => 'aprovado_clearsale',
                'motivo'  => 'Aprovado pelo antifraude.'];
    }

    // =========================================================================
    // FATORES
    // =========================================================================

    /** Junta o score persistido com os sinais que ele não guarda. */
    private function coletar(int $clienteId): array
    {
        $row = $this->score->getRow($clienteId);

        // Cliente sem score calculado: calcula agora. Decidir com score
        // desatualizado é pior do que a query extra.
        if (!$row) {
            $this->score->recalcular($clienteId);
            $row = $this->score->getRow($clienteId) ?: [];
        }

        $scoreBase = (int) ($row['score_total'] ?? 0);

        // Penalidade só conta enquanto vale. Expirada é ignorada — e não
        // apagada, para o histórico continuar legível.
        $pen = (int) ($row['penalidade_pontos'] ?? 0);
        $exp = $row['penalidade_expira_em'] ?? null;
        if ($pen > 0 && $exp !== null && strtotime((string) $exp) < time()) {
            $pen = 0;
        }

        $scoreEfetivo = max(0, $scoreBase - $pen);

        $recencia = $this->recencia($clienteId);

        return [
            'score'              => $scoreEfetivo,
            'score_base'         => $scoreBase,
            'penalidade'         => $pen,
            'tier'               => $this->score->getTierByScore($scoreEfetivo),
            'fraude_confirmada'  => (int) ($row['fraude_confirmada'] ?? 0),
            'total_pedidos'      => (int) ($row['total_pedidos'] ?? 0),
            'total_concluidos'   => (int) ($row['total_pedidos_concluidos'] ?? 0),
            'total_devolucoes'   => (int) ($row['total_devolucoes'] ?? 0),
            'total_reprovadas'   => (int) ($row['total_reprovadas'] ?? 0),
            'total_chargebacks'  => (int) ($row['total_chargebacks'] ?? 0),
            'dias_ultimo_pedido' => $recencia['dias'],
            'pedidos_24h'        => $recencia['pedidos_24h'],
            'cartoes_24h'        => $recencia['cartoes_24h'],
        ];
    }

    /**
     * Recência e velocidade — não moram no clientes_score porque mudam a cada
     * pedido, e o score é recalculado por cron.
     */
    private function recencia(int $clienteId): array
    {
        $st = $this->db->prepare(
            "SELECT DATEDIFF(NOW(), MAX(criado_em)) AS dias,
                    SUM(CASE WHEN criado_em >= (NOW() - INTERVAL :h HOUR) THEN 1 ELSE 0 END) AS n24
               FROM pedidos
              WHERE cliente_id = :c"
        );
        $st->bindValue(':h', self::JANELA_VELOCIDADE_HORAS, PDO::PARAM_INT);
        $st->bindValue(':c', $clienteId, PDO::PARAM_INT);
        $st->execute();
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        // Cartões distintos na janela: o sinal forte de teste de cartão. Sai
        // de pgto_tentativas, que guarda a bandeira de cada tentativa.
        $cartoes = 0;
        try {
            $st = $this->db->prepare(
                "SELECT COUNT(DISTINCT COALESCE(t.bandeira, t.charge_id)) AS n
                   FROM pgto_tentativas t
                  WHERE t.cliente_id = :c
                    AND t.metodo LIKE 'cartao%'
                    AND t.criado_em >= (NOW() - INTERVAL :h HOUR)"
            );
            $st->bindValue(':h', self::JANELA_VELOCIDADE_HORAS, PDO::PARAM_INT);
            $st->bindValue(':c', $clienteId, PDO::PARAM_INT);
            $st->execute();
            $cartoes = (int) ($st->fetchColumn() ?: 0);
        } catch (\Throwable) {
            // pgto_tentativas pode não existir num ambiente antigo — a
            // decisão continua válida sem este agravante.
        }

        return [
            'dias'        => $r['dias'] !== null ? (int) $r['dias'] : null,
            'pedidos_24h' => (int) ($r['n24'] ?? 0),
            'cartoes_24h' => $cartoes,
        ];
    }
}
