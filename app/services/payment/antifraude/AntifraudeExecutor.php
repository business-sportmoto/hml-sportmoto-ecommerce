<?php
declare(strict_types=1);

/**
 * app/services/payment/antifraude/AntifraudeExecutor.php
 *
 * Executa o nó de antifraude: junta a decisão local (score do cliente) com a
 * consulta à ClearSale, grava o registro e devolve a porta do fluxo.
 *
 * ORDEM DE TRABALHO:
 *   1. AntifraudeDecisor decide pelo score — aprovar, antifraude ou analise.
 *   2. Se "aprovar" e o nó permitir, encerra SEM gastar consulta.
 *   3. Nos demais casos consulta a ClearSale.
 *   4. O parecer dela é cruzado com o tier do cliente (risco médio + cliente
 *      bom = aprova).
 *   5. Quando a decisão local era "analise", o pedido fica retido MESMO com
 *      a ClearSale aprovando — foi a escolha de ter o parecer e ainda assim
 *      decidir na mão.
 *
 * FALHA DA CLEARSALE NÃO APROVA E NÃO RECUSA. Cai em "analise": aprovar às
 * cegas é risco de fraude, recusar às cegas é venda perdida por indisponibilidade
 * de terceiro. Reter é o único desfecho honesto quando não se sabe.
 */
class AntifraudeExecutor
{
    public const PORTA_APROVADO  = 'aprovado';
    public const PORTA_ANALISE   = 'analise';
    public const PORTA_REPROVADO = 'reprovado';
    public const PORTA_ERRO      = 'erro';

    private PDO $db;
    private AntifraudeDecisor $decisor;
    private ClearSaleService $clearsale;
    private ScorePenalidadeService $penalidade;

    public function __construct(
        ?PDO $db = null,
        ?AntifraudeDecisor $decisor = null,
        ?ClearSaleService $clearsale = null,
        ?ScorePenalidadeService $penalidade = null
    ) {
        $this->db         = $db ?? Database::getInstance()->getConnection();
        $this->decisor    = $decisor    ?? new AntifraudeDecisor($this->db);
        $this->clearsale  = $clearsale  ?? new ClearSaleService();
        $this->penalidade = $penalidade ?? new ScorePenalidadeService($this->db);
    }

    /**
     * @param array $ctx   contexto do pagamento (cliente_id, order_id_loja, pedido_id...)
     * @param array $cfg   config do nó (modo, pular_se_aprovado_local,
     *                     pular_se_liability_shift)
     * @return array{porta:string, decisao:string, regra:string, motivo:string,
     *               consultou:bool, status_clearsale:?string}
     */
    public function executar(array $ctx, array $cfg = []): array
    {
        $clienteId = (int) ($ctx['cliente_id'] ?? 0);
        $pularSePossivel = ($cfg['pular_se_aprovado_local'] ?? '1') !== '0';

        // ── 0. Autenticado pelo emissor ──────────────────────────────
        //
        // Com liability shift, o chargeback DE FRAUDE é do emissor: gastar
        // consulta para decidir sobre um risco que não é mais nosso é pagar
        // duas vezes pela mesma proteção.
        //
        // ATENÇÃO AO QUE ISTO NÃO COBRE. Liability shift vale para disputa de
        // fraude. "Não recebi" e "não era o que comprei" continuam sendo
        // nossos, e nenhum 3DS os evita. Por isso a chave é POR FLUXO — quem
        // vende item de alto giro de golpe pode querer analisar mesmo assim.
        $comShift = !empty($ctx['liability_shift']);
        $pularSeShift = ($cfg['pular_se_liability_shift'] ?? '0') === '1';

        if ($comShift && $pularSeShift) {
            $motivo = 'Autenticado pelo emissor (3DS) — responsabilidade transferida.';
            $local  = ['decisao' => AntifraudeDecisor::APROVAR,
                       'regra'   => 'liability_shift',
                       'motivo'  => $motivo,
                       'score'   => null];

            $this->registrar($ctx, $local, null, self::PORTA_APROVADO, false, $motivo);

            return [
                'porta'            => self::PORTA_APROVADO,
                'decisao'          => AntifraudeDecisor::APROVAR,
                'regra'            => 'liability_shift',
                'motivo'           => $motivo,
                'consultou'        => false,
                'status_clearsale' => null,
            ];
        }

        // ── 1. Decisão local, sem gastar consulta ────────────────────
        $local = $this->decisor->decidir($clienteId, $ctx);

        if ($local['decisao'] === AntifraudeDecisor::APROVAR && $pularSePossivel) {
            $this->registrar($ctx, $local, null, self::PORTA_APROVADO, false);
            return [
                'porta'            => self::PORTA_APROVADO,
                'decisao'          => $local['decisao'],
                'regra'            => $local['regra'],
                'motivo'           => $local['motivo'],
                'consultou'        => false,
                'status_clearsale' => null,
            ];
        }

        // Entrou no antifraude: penaliza o score. É o comportamento que você
        // pediu — cair aqui já é sinal, mesmo que o pedido acabe aprovado.
        if ($clienteId > 0) {
            $this->penalidade->porAntifraude($clienteId, $local['regra']);
        }

        // ── 2. Consulta ──────────────────────────────────────────────
        if (!$this->clearsale->configurado()) {
            // Sem credencial não se inventa parecer. Retém.
            $motivo = 'Antifraude não configurado — pedido retido para análise.';
            LogService::error('Antifraude sem credenciais', [
                'order_id_loja' => $ctx['order_id_loja'] ?? null,
            ], 'pagamento');
            $this->registrar($ctx, $local, null, self::PORTA_ANALISE, false, $motivo);
            return ['porta' => self::PORTA_ANALISE, 'decisao' => AntifraudeDecisor::ANALISE,
                    'regra' => 'sem_credencial', 'motivo' => $motivo,
                    'consultou' => false, 'status_clearsale' => null];
        }

        $analise = $this->clearsale->analisar($this->montarPedido($ctx));

        // ── 3. Erro da ClearSale → retém, nunca aprova ───────────────
        if ($analise['status'] === 'erro') {
            $motivo = 'Antifraude indisponível — pedido retido. ' . ($analise['motivo'] ?? '');
            $this->registrar($ctx, $local, $analise, self::PORTA_ANALISE, true, $motivo);
            return ['porta' => self::PORTA_ANALISE, 'decisao' => AntifraudeDecisor::ANALISE,
                    'regra' => 'antifraude_indisponivel', 'motivo' => $motivo,
                    'consultou' => true, 'status_clearsale' => 'erro'];
        }

        // ── 4. Fraude confirmada → zera o score e trava ──────────────
        if ($analise['status'] === 'fraude' && $clienteId > 0) {
            $this->penalidade->marcarFraudeConfirmada(
                $clienteId, (string) ($analise['motivo'] ?? 'ClearSale')
            );
        }

        // ── 4b. Sem parecer ainda: NAO decide ────────────────────────
        //
        // A analise da ClearSale e assincrona: o envio responde `NVO` e o
        // veredito nasce minutos depois. `NVO` traduzido vira risco "medio",
        // e a regra de risco medio APROVA cliente acima de Silver — entao um
        // pedido com chargeback no historico saia liberado antes de a
        // ClearSale ter julgado coisa alguma.
        //
        // "Ainda nao sei" nao e "risco medio". Retem, e o worker resolve
        // quando o parecer chegar.
        if (class_exists('ClearSaleService')
            && ClearSaleService::aguardandoParecer($analise['codigo_status'] ?? null)) {

            $motivo = 'Aguardando parecer do antifraude (' . ($analise['codigo_status'] ?? '?') . ').';
            $this->registrar($ctx, $local, $analise, self::PORTA_ANALISE, true, $motivo);

            return ['porta' => self::PORTA_ANALISE, 'decisao' => AntifraudeDecisor::ANALISE,
                    'regra' => 'aguardando_parecer', 'motivo' => $motivo,
                    'consultou' => true, 'status_clearsale' => $analise['status']];
        }

        // ── 5. Parecer cruzado com o tier ────────────────────────────
        $pos = $this->decisor->decidirPosAnalise(
            $analise['status'], $analise['risco'], (string) $local['tier']
        );

        // ── 6. "Analise" local retém mesmo com aprovação ─────────────
        // Foi a escolha: consultar para ter o parecer, mas decidir na mão.
        $porta = match ($pos['decisao']) {
            AntifraudeDecisor::APROVAR => $local['decisao'] === AntifraudeDecisor::ANALISE
                                          ? self::PORTA_ANALISE
                                          : self::PORTA_APROVADO,
            AntifraudeDecisor::ANALISE => self::PORTA_ANALISE,
            default                    => self::PORTA_REPROVADO,
        };

        // Reprovação explícita da ClearSale vence e recusa de vez.
        if (in_array($analise['status'], ['reprovado', 'fraude'], true)) {
            $porta = self::PORTA_REPROVADO;
        }

        $motivo = $pos['motivo']
                . ($local['decisao'] === AntifraudeDecisor::ANALISE && $porta === self::PORTA_ANALISE
                    ? ' Retido pela regra local: ' . $local['motivo']
                    : '');

        $this->registrar($ctx, $local, $analise, $porta, true, $motivo);

        return [
            'porta'            => $porta,
            'decisao'          => $pos['decisao'],
            'regra'            => $pos['regra'],
            'motivo'           => $motivo,
            'consultou'        => true,
            'status_clearsale' => $analise['status'],
        ];
    }

    // =========================================================================

    /**
     * Traduz o contexto do roteamento para o que a ClearSale precisa.
     *
     * O sessionID sai da sessao PHP por padrao: o antifraude roda na MESMA
     * requisicao em que o comprador clicou em pagar, entao o id que o
     * fingerprint recebeu na pagina ja esta ali. Assim o checkout nao precisa
     * lembrar de repassar — e esquecer disso seria silencioso, custando
     * consulta cega em todo pedido.
     */
    private function montarPedido(array $ctx): array
    {
        $sid = (string) ($ctx['session_id'] ?? '');
        if ($sid === '' && class_exists('ClearSaleFingerprint')) {
            $sid = ClearSaleFingerprint::sessionId();
        }

        return [
            'codigo'         => (string) ($ctx['order_id_loja'] ?? ''),
            'session_id'     => $sid,
            'cliente_id'     => (int) ($ctx['cliente_id'] ?? 0),
            'valor_centavos' => (int) ($ctx['valor_centavos'] ?? 0),
            'frete_centavos' => (int) ($ctx['frete_centavos'] ?? 0),
            'parcelas'       => max(1, (int) ($ctx['parcelas'] ?? 1)),
            'metodo'         => (string) ($ctx['metodo'] ?? 'cartao_credito'),
            'ip'             => (string) ($ctx['ip_cliente'] ?? ''),
            'cliente'        => $ctx['cliente'] ?? [],
            'entrega'        => $ctx['entrega'] ?? [],
            'itens'          => $ctx['itens'] ?? [],
            'cartao'         => self::cartaoSeguro($ctx['cartao'] ?? [], $ctx['bandeira'] ?? null),
        ];
    }

    /**
     * BIN e ultimos 4 do cartao, nunca o PAN completo.
     *
     * O BIN diz banco, pais e tipo do cartao — e um dos sinais mais fortes que
     * a analise usa. Derivado em memoria e descartado: continua valendo a
     * premissa de que o numero inteiro nao fica em lugar nenhum nosso.
     */
    private static function cartaoSeguro(array $cartao, ?string $bandeira): array
    {
        if (!$cartao) return [];

        $pan = preg_replace('/\D/', '', (string) ($cartao['numero'] ?? '')) ?? '';

        return array_filter([
            'bin'       => strlen($pan) >= 6 ? substr($pan, 0, 6) : (string) ($cartao['bin'] ?? ''),
            'ultimos4'  => strlen($pan) >= 4 ? substr($pan, -4)   : (string) ($cartao['ultimos4'] ?? ''),
            'validade'  => (string) ($cartao['validade'] ?? ''),
            'titular'   => (string) ($cartao['titular'] ?? ''),
            'documento' => (string) ($cartao['documento'] ?? ''),
            'bandeira'  => (string) ($bandeira ?? $cartao['bandeira'] ?? ''),
        ], static fn($v) => $v !== '');
    }

    /** Grava em pgto_antifraude. Auditoria: nunca derruba o pagamento. */
    private function registrar(
        array $ctx, array $local, ?array $analise, string $porta,
        bool $consultou, ?string $motivoExtra = null
    ): void {
        try {
            $this->db->prepare(
                "INSERT INTO pgto_antifraude
                    (pedido_id, order_id_loja, tentativa_id, provedor, modo, status,
                     score, recomendacao, codigo_status, analise_id, motivo,
                     decisao_pre, regra_aplicada, motivo_pre, score_cliente, tier_cliente,
                     enviado_em, respondido_em, criado_em)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
            )->execute([
                $ctx['pedido_id']     ?? null,
                (string) ($ctx['order_id_loja'] ?? ''),
                $ctx['tentativa_id']  ?? null,
                'clearsale',
                (string) ($ctx['modo_antifraude'] ?? 'pos_captura'),
                $this->statusPersistido($porta, $analise),
                $analise['score']      ?? null,
                $analise['status']     ?? null,
                // Codigo bruto (NVO/AMA/APA...). E o que diz se a ClearSale
                // ainda deve um parecer — `recomendacao` ja vem traduzida e
                // nao distingue "na fila deles" de "julgado suspeito".
                $analise['codigo_status'] ?? null,
                $analise['analise_id'] ?? null,
                $motivoExtra !== null ? mb_substr($motivoExtra, 0, 255) : ($analise['motivo'] ?? null),
                $local['decisao'],
                $local['regra'],
                mb_substr((string) $local['motivo'], 0, 255),
                (int) ($local['score'] ?? 0),
                (string) ($local['tier'] ?? ''),
                $consultou ? date('Y-m-d H:i:s') : null,
                $consultou && $analise ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'pagamento', ['acao' => 'registrar_antifraude']);
        }
    }

    private function statusPersistido(string $porta, ?array $analise): string
    {
        if (($analise['status'] ?? '') === 'erro') return 'erro';
        return match ($porta) {
            self::PORTA_APROVADO  => 'aprovado',
            self::PORTA_REPROVADO => 'reprovado',
            self::PORTA_ANALISE   => 'revisao',
            default               => 'pendente',
        };
    }
}
