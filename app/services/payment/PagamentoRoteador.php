<?php
declare(strict_types=1);

/**
 * app/services/payment/PagamentoRoteador.php
 *
 * Executa o grafo de pagamento desenhado no Drawflow: percorre os nós, chama
 * adquirentes e devolve o desfecho. Síncrono e em milissegundos — o cliente
 * está esperando no checkout.
 *
 * POR QUE NÃO REUSA O FluxoMotor:
 *   Aquele motor é de jornada de marketing: assíncrono, com dormir_ate,
 *   esperar_evento e worker por cron. Roteamento de pagamento não pode dormir.
 *   O que se reaproveita é o CANVAS e o conceito de nós/portas, não a execução.
 *
 * GARANTIAS:
 *   1. Toda tentativa vira linha em pgto_tentativas ANTES da chamada. Se o
 *      processo morrer no meio, o rastro existe. É a mesma ordem que o webhook
 *      exige para achar o dono da cobrança.
 *   2. Fallback só pelo PagamentoErroClassifier::permiteFallback(). O grafo
 *      pode até ligar uma porta negado_* em outra adquirente — o motor recusa.
 *      A trava é do código, não do desenho.
 *   3. Limite de passos e de tentativas: grafo com ciclo não roda para sempre.
 *   4. Incerteza (timeout) NUNCA cai para outra adquirente sem consultar antes.
 */
class PagamentoRoteador
{
    /** Teto de nós visitados. Protege contra ciclo no grafo. */
    private const MAX_PASSOS = 40;

    /** Teto de adquirentes tentadas no mesmo checkout. */
    private const MAX_TENTATIVAS = 4;

    private PDO $db;

    /** @var callable(string):?AdquirenteInterface */
    private $fabrica;

    private PagamentoNotificador $notificador;

    public function __construct(?PDO $db = null, ?callable $fabrica = null)
    {
        $this->db      = $db ?? Database::getInstance()->getConnection();
        $this->fabrica     = $fabrica ?? [$this, 'adquirentePorCodigo'];
        $this->notificador = new PagamentoNotificador($this->db);
    }

    /**
     * Roteia um pagamento.
     *
     * @param array $ctx metodo, valor_centavos, parcelas, order_id_loja,
     *                   pedido_id, cliente_id, cliente[], cartao[],
     *                   token_temporario, bandeira, session_id, ip_cliente,
     *                   descricao_fatura, vencimento
     */
    public function processar(array $ctx): PagamentoRoteamentoResultado
    {
        $r = new PagamentoRoteamentoResultado();
        $metodo = (string) ($ctx['metodo'] ?? '');

        $fluxo = $this->carregarFluxoPublicado($metodo);
        if (!$fluxo) {
            // Sem fluxo desenhado não há o que rotear. Falhar alto é melhor do
            // que escolher uma adquirente "padrão" que ninguém configurou.
            LogService::error('Pagamento sem fluxo publicado', ['metodo' => $metodo], 'pagamento');
            $r->encerrar(false, 'sem_fluxo', 'Forma de pagamento indisponível no momento.');
            return $r;
        }

        $r->fluxoId     = (int) $fluxo['id'];
        $r->fluxoVersao = (int) $fluxo['versao'];

        $nos      = $this->carregarNos($r->fluxoId);
        $conexoes = $this->carregarConexoes($r->fluxoId);

        $atual = $this->acharEntrada($nos);
        if (!$atual) {
            LogService::error('Fluxo de pagamento sem nó de entrada', ['fluxo_id' => $r->fluxoId], 'pagamento');
            $r->encerrar(false, 'fluxo_invalido', 'Forma de pagamento indisponível no momento.');
            return $r;
        }

        $visitados = [];

        for ($passo = 0; $passo < self::MAX_PASSOS; $passo++) {
            $no     = $nos[$atual] ?? null;
            if (!$no) {
                $r->encerrar(false, 'no_inexistente', 'Não foi possível processar o pagamento.');
                return $r;
            }

            $r->caminho[] = $atual;

            // Ciclo: o mesmo nó duas vezes só é legítimo em tentar_adquirente
            // (retentativa); em condição significa laço.
            $visitados[$atual] = ($visitados[$atual] ?? 0) + 1;
            if ($visitados[$atual] > 2) {
                LogService::error('Ciclo no fluxo de pagamento', [
                    'fluxo_id' => $r->fluxoId, 'no' => $atual,
                ], 'pagamento');
                $r->encerrar(false, 'ciclo', 'Não foi possível processar o pagamento.');
                return $r;
            }

            $config = is_array($no['config'] ?? null) ? $no['config'] : (json_decode((string) ($no['config'] ?? ''), true) ?: []);
            $porta  = $this->executarNo((string) $no['tipo'], $config, $ctx, $r, $atual);

            // Nós terminais encerram aqui.
            if ($porta === '__fim') {
                return $r;
            }

            $proximo = $this->proximoNo($conexoes, $atual, $porta);

            // ── TRAVA DA OPÇÃO 2, no momento de andar ───────────────────
            //
            // Marcar uma flag não bastava: o motor seguia a aresta do mesmo
            // jeito. Se a última classificação proíbe fallback (emissor já
            // julgou) e o destino é outra adquirente, a aresta é RECUSADA
            // aqui — o fluxo encerra com a recusa original.
            //
            // Isso torna impossível criar retentativa de negativa desenhando
            // errado no Drawflow. A regra é do código, não do desenho.
            if ($proximo !== null && $this->arestaProibida($nos, $proximo, $r)) {
                LogService::warning('Aresta de retentativa recusada pelo motor', [
                    'fluxo_id'   => $r->fluxoId,
                    'no_origem'  => $atual,
                    'porta'      => $porta,
                    'no_destino' => $proximo,
                    'classe'     => $r->classificacao?->classeErro,
                ], 'pagamento');

                $r->bloqueouFallback = true;
                $r->encerrar(false, 'fallback_bloqueado:' . $porta,
                    $r->classificacao?->mensagemCliente ?? 'Pagamento não autorizado.');
                return $r;
            }

            if ($proximo === null) {
                // Porta sem aresta: o desenho não previu este desfecho. O
                // resultado da última tentativa é o que vale.
                $r->encerrar(
                    $r->classificacao?->sucesso() ?? false,
                    'porta_sem_destino:' . $porta,
                    $r->classificacao?->mensagemCliente ?? 'Não foi possível processar o pagamento.'
                );
                return $r;
            }
            $atual = $proximo;
        }

        LogService::error('Fluxo de pagamento excedeu o limite de passos', [
            'fluxo_id' => $r->fluxoId, 'passos' => self::MAX_PASSOS,
        ], 'pagamento');
        $r->encerrar(false, 'limite_passos', 'Não foi possível processar o pagamento.');
        return $r;
    }

    // =========================================================================
    // NÓS
    // =========================================================================

    /** @return string porta de saída, ou '__fim' quando o nó encerra o fluxo */
    private function executarNo(string $tipo, array $cfg, array $ctx, PagamentoRoteamentoResultado $r, string $noRef): string
    {
        return match ($tipo) {
            'entrada'           => 'saida',
            'cond_parcelas'     => $this->condFaixa((int) ($ctx['parcelas'] ?? 1), $cfg),
            'cond_valor'        => $this->condFaixa((int) ($ctx['valor_centavos'] ?? 0), $cfg),
            'cond_bandeira'     => $this->condBandeira($ctx, $cfg),
            'tentar_adquirente' => $this->tentarAdquirente($cfg, $ctx, $r, $noRef),
            'antifraude'        => $this->antifraude($cfg, $ctx, $r),
            'aprovar'           => $this->encerrarNo($r, true,  'aprovado', $ctx),
            'recusar'           => $this->encerrarNo($r, false, 'recusado', $ctx),
            'reter_analise'     => $this->reterAnalise($r),
            default             => $this->noDesconhecido($tipo, $r),
        };
    }

    /** min/max inclusivos. Ausente = sem limite daquele lado. */
    private function condFaixa(int $valor, array $cfg): string
    {
        $min = isset($cfg['min']) ? (int) $cfg['min'] : PHP_INT_MIN;
        $max = isset($cfg['max']) ? (int) $cfg['max'] : PHP_INT_MAX;
        return ($valor >= $min && $valor <= $max) ? 'sim' : 'nao';
    }

    private function condBandeira(array $ctx, array $cfg): string
    {
        $bandeira = strtolower((string) ($ctx['bandeira'] ?? $ctx['cartao']['bandeira'] ?? ''));
        $aceitas  = array_map('strtolower', (array) ($cfg['bandeiras'] ?? []));
        return ($bandeira !== '' && in_array($bandeira, $aceitas, true)) ? 'sim' : 'nao';
    }

    /**
     * Chama uma adquirente e devolve a porta correspondente ao resultado.
     * É o único nó que gasta dinheiro e tempo — tudo aqui é registrado.
     */
    private function tentarAdquirente(array $cfg, array $ctx, PagamentoRoteamentoResultado $r, string $noRef): string
    {
        if ($r->tentativas >= self::MAX_TENTATIVAS) {
            LogService::warning('Limite de tentativas atingido no roteamento', [
                'order_id_loja' => $ctx['order_id_loja'] ?? null,
                'tentativas'    => $r->tentativas,
            ], 'pagamento');
            return PagamentoClassificacao::ERRO_TECNICO;
        }

        $codigo = (string) ($cfg['adquirente'] ?? '');
        $adq    = ($this->fabrica)($codigo);

        if (!$adq || !$adq->configurado()) {
            // Adquirente inexistente ou sem credencial: não é falha do cliente.
            // Registra como pulada e segue pela porta de indisponível.
            $this->gravarTentativa($ctx, $r, $noRef, $codigo, null, 'pulado',
                'nao_configurada', 'Adquirente sem credenciais ou inexistente');
            LogService::error('Adquirente do fluxo indisponível', [
                'adquirente' => $codigo, 'fluxo_id' => $r->fluxoId, 'no' => $noRef,
            ], 'pagamento');
            return PagamentoClassificacao::INDISPONIVEL;
        }

        $r->tentativas++;
        $tentativaRef = $this->refTentativa($ctx, $r->tentativas);

        // Linha ANTES da chamada: se o processo morrer, o rastro fica; e o
        // webhook consegue achar o dono mesmo chegando antes da resposta.
        $tentativaId = $this->gravarTentativa($ctx, $r, $noRef, $codigo, $tentativaRef, 'erro', null, null);

        $dados = $ctx + ['tentativa_ref' => $tentativaRef];

        try {
            $c = match ((string) ($ctx['metodo'] ?? '')) {
                'pix'    => $adq->criarPix($dados),
                'boleto' => $adq->criarBoleto($dados),
                default  => $adq->autorizarCartao($dados),
            };
        } catch (\Throwable $e) {
            // Adapter não deveria lançar por falha da adquirente — se lançou,
            // é bug nosso. Não deixa escapar para o checkout.
            LogService::exception($e, 'error', 'pagamento', [
                'adquirente' => $codigo, 'tentativa_ref' => $tentativaRef,
            ]);
            $c = new PagamentoClassificacao();
            $c->porta      = PagamentoClassificacao::ERRO_TECNICO;
            $c->classeErro = 'excecao_adapter';
            $c->mensagemAdquirente = $e->getMessage();
        }

        $r->classificacao    = $c;
        $r->adquirenteUsada  = $codigo;
        $r->tentativaIdAtual = $tentativaId;
        $this->fecharTentativa($tentativaId, $c);

        // ── Incerteza: consultar antes de qualquer decisão ───────────
        if ($c->exigeConsulta && $c->chargeId) {
            $confirmado = $adq->consultar($c->chargeId);
            LogService::warning('Timeout na adquirente — resolvido por consulta', [
                'adquirente'  => $codigo,
                'charge_id'   => $c->chargeId,
                'resultado'   => $confirmado->porta,
            ], 'pagamento');

            if ($confirmado->sucesso()) {
                // Tinha aprovado: seguir para outra adquirente cobraria de novo.
                $r->classificacao = $confirmado;
                $this->fecharTentativa($tentativaId, $confirmado);
                return $confirmado->porta;
            }
            // Confirmado que não passou — agora pode cair para a próxima.
            $c->exigeConsulta     = false;
            $c->podeCairParaOutra = true;
        }

        // Aviso aos admins quando a falha e da INFRAESTRUTURA, nao do
        // cliente. Recusa de emissor e rotina; adquirente fora do ar e
        // problema que alguem precisa ver agora.
        if (in_array($c->porta, [
                PagamentoClassificacao::ERRO_TECNICO,
                PagamentoClassificacao::INDISPONIVEL,
            ], true)) {
            $this->notificador->adquirenteComProblema($codigo, $c, $ctx);
        }

        // ── Trava da opção 2 ─────────────────────────────────────────
        // Se a porta for de recusa do emissor, o motor NÃO deixa seguir para
        // outra adquirente, mesmo que o desenho ligue essa aresta. Isso é o
        // que mantém a operação fora dos programas de retentativa das bandeiras.
        if (!$c->sucesso() && !PagamentoErroClassifier::permiteFallback($c)) {
            $r->bloqueouFallback = true;
        }

        return $c->porta;
    }

    /**
     * Nó de antifraude. Roda DEPOIS da adquirente aprovar — antes disso não
     * há o que analisar, e gastar consulta em pedido que a adquirente vai
     * recusar é dinheiro jogado fora.
     *
     * A execução mora no AntifraudeExecutor; aqui só se conecta o resultado
     * ao grafo e se guarda o desfecho no resultado do roteamento.
     */
    private function antifraude(array $cfg, array $ctx, PagamentoRoteamentoResultado $r): string
    {
        // Sem aprovação da adquirente não faz sentido analisar. Segue pela
        // porta de erro para o desenho decidir (normalmente, Recusar).
        if (!$r->classificacao || !$r->classificacao->sucesso()) {
            LogService::warning('Nó de antifraude alcançado sem aprovação da adquirente', [
                'order_id_loja' => $ctx['order_id_loja'] ?? null,
                'porta_atual'   => $r->classificacao?->porta,
            ], 'pagamento');
            return AntifraudeExecutor::PORTA_ERRO;
        }

        $ctx['modo_antifraude'] = (string) ($cfg['modo'] ?? 'pos_captura');
        $ctx['tentativa_id']    = $r->tentativaIdAtual;

        try {
            $res = (new AntifraudeExecutor($this->db))->executar($ctx, $cfg);
        } catch (\Throwable $e) {
            // Antifraude quebrado não pode derrubar o checkout nem aprovar
            // às cegas: retém.
            LogService::exception($e, 'error', 'pagamento', [
                'acao' => 'executar_antifraude',
                'order_id_loja' => $ctx['order_id_loja'] ?? null,
            ]);
            $r->antifraude = ['porta' => AntifraudeExecutor::PORTA_ANALISE,
                              'motivo' => 'Falha no antifraude — pedido retido.'];
            return AntifraudeExecutor::PORTA_ANALISE;
        }

        $r->antifraude = $res;

        if ($res['porta'] === AntifraudeExecutor::PORTA_ANALISE) {
            $this->notificador->pedidoEmAnalise($ctx, $res);
        }

        LogService::audit('Antifraude decidido', [
            'order_id_loja' => $ctx['order_id_loja'] ?? null,
            'porta'         => $res['porta'],
            'regra'         => $res['regra'],
            'consultou'     => $res['consultou'],
            'clearsale'     => $res['status_clearsale'],
        ]);

        return $res['porta'];
    }

    /**
     * Encerra com o pedido RETIDO. Nao e sucesso nem falha: o dinheiro pode
     * ate estar capturado, mas a mercadoria nao sai ate alguem decidir.
     *
     * NAO dispara notificacao de aprovacao — quem avisa a fila e o proprio
     * no de antifraude, com o motivo.
     */
    private function reterAnalise(PagamentoRoteamentoResultado $r): string
    {
        $r->encerrar(false, 'retido_analise',
            'Estamos confirmando alguns dados do seu pedido. Avisaremos em breve.');
        $r->retido = true;
        return '__fim';
    }

    private function encerrarNo(PagamentoRoteamentoResultado $r, bool $ok, string $motivo, array $ctx = []): string
    {
        $r->encerrar($ok, $motivo, $r->classificacao?->mensagemCliente
            ?? ($ok ? 'Pagamento aprovado.' : 'Não foi possível processar o pagamento.'));

        // Avisa SOMENTE quando o dinheiro entrou de verdade.
        //
        // Um Pix que gerou QR tambem encerra o fluxo com sucesso, mas esta
        // PENDENTE — ninguem pagou ainda. Notificar "pagamento aprovado" ali
        // faria o admin acreditar em dinheiro que nao existe. A confirmacao
        // do Pix chega depois, pelo webhook.
        if ($ok && $r->classificacao?->porta === PagamentoClassificacao::APROVADO) {
            $this->notificador->pedidoAprovado($ctx, $r->classificacao);
        }

        return '__fim';
    }

    private function noDesconhecido(string $tipo, PagamentoRoteamentoResultado $r): string
    {
        LogService::error('Nó de pagamento desconhecido', ['tipo' => $tipo], 'pagamento');
        $r->encerrar(false, 'no_desconhecido:' . $tipo, 'Não foi possível processar o pagamento.');
        return '__fim';
    }

    // =========================================================================
    // GRAFO
    // =========================================================================

    /**
     * Segue a aresta da porta. A trava do fallback mora aqui: se a última
     * classificação proíbe cair para outra adquirente, arestas que levem a
     * tentar_adquirente são ignoradas.
     */
    private function proximoNo(array $conexoes, string $noOrigem, string $porta): ?string
    {
        return $conexoes[$noOrigem][$porta] ?? null;
    }

    /**
     * A aresta levaria a uma nova adquirente depois de um julgamento do
     * emissor? Se sim, é retentativa proibida — independente do desenho.
     *
     * Só bloqueia quando o destino é `tentar_adquirente`. Ligar negado_saldo
     * a um nó de recusa, de log ou de notificação continua liberado: o que
     * não pode é apresentar o mesmo cartão de novo.
     */
    private function arestaProibida(array $nos, string $noDestino, PagamentoRoteamentoResultado $r): bool
    {
        $c = $r->classificacao;
        if (!$c || $c->sucesso()) return false;

        $tipoDestino = (string) ($nos[$noDestino]['tipo'] ?? '');
        if ($tipoDestino !== 'tentar_adquirente') return false;

        return !PagamentoErroClassifier::permiteFallback($c);
    }

    private function acharEntrada(array $nos): ?string
    {
        foreach ($nos as $ref => $no) {
            if (($no['tipo'] ?? '') === 'entrada') return (string) $ref;
        }
        return null;
    }

    private function carregarFluxoPublicado(string $metodo): ?array
    {
        $st = $this->db->prepare(
            "SELECT id, versao FROM pgto_fluxos
              WHERE metodo_codigo = ? AND status = 'publicado'
              ORDER BY versao DESC LIMIT 1"
        );
        $st->execute([$metodo]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function carregarNos(int $fluxoId): array
    {
        $st = $this->db->prepare("SELECT no_ref, tipo, config FROM pgto_fluxo_nos WHERE fluxo_id = ?");
        $st->execute([$fluxoId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $out[$n['no_ref']] = ['tipo' => $n['tipo'], 'config' => json_decode((string) $n['config'], true) ?: []];
        }
        return $out;
    }

    private function carregarConexoes(int $fluxoId): array
    {
        $st = $this->db->prepare(
            "SELECT no_origem, porta_origem, no_destino FROM pgto_fluxo_conexoes WHERE fluxo_id = ?"
        );
        $st->execute([$fluxoId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $out[$c['no_origem']][$c['porta_origem']] = $c['no_destino'];
        }
        return $out;
    }

    // =========================================================================
    // REGISTRO
    // =========================================================================

    /** Referência única por tentativa — vira merchantTransactionId. */
    private function refTentativa(array $ctx, int $seq): string
    {
        return substr((string) ($ctx['order_id_loja'] ?? 'sem-pedido'), 0, 60) . '-t' . $seq;
    }

    private function gravarTentativa(
        array $ctx, PagamentoRoteamentoResultado $r, string $noRef, string $codigo,
        ?string $tentativaRef, string $resultado, ?string $classeErro, ?string $mensagem
    ): int {
        try {
            $gw = null;
            if ($codigo !== '') {
                $st = $this->db->prepare("SELECT id FROM pgto_gateways WHERE codigo = ? LIMIT 1");
                $st->execute([$codigo]);
                $gw = $st->fetchColumn() ?: null;
            }

            $this->db->prepare(
                "INSERT INTO pgto_tentativas
                    (pedido_id, order_id_loja, cliente_id, fluxo_id, fluxo_versao, no_ref, sequencia,
                     gateway_id, adquirente_codigo, metodo, parcelas, valor_centavos, bandeira,
                     idempotency_key, resultado, classe_erro, mensagem_adquirente, criado_em)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
            )->execute([
                $ctx['pedido_id']     ?? null,
                (string) ($ctx['order_id_loja'] ?? ''),
                $ctx['cliente_id']    ?? null,
                $r->fluxoId, $r->fluxoVersao, $noRef, max(1, $r->tentativas),
                $gw, $codigo,
                (string) ($ctx['metodo'] ?? ''),
                max(1, (int) ($ctx['parcelas'] ?? 1)),
                (int) ($ctx['valor_centavos'] ?? 0),
                $ctx['bandeira'] ?? ($ctx['cartao']['bandeira'] ?? null),
                $tentativaRef, $resultado, $classeErro, $mensagem,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (\Throwable $e) {
            // Registro é auditoria: não pode derrubar um pagamento em curso.
            LogService::exception($e, 'error', 'pagamento', ['acao' => 'gravar_tentativa']);
            return 0;
        }
    }

    private function fecharTentativa(int $id, PagamentoClassificacao $c): void
    {
        if ($id === 0) return;
        try {
            $this->db->prepare(
                "UPDATE pgto_tentativas
                    SET resultado = ?, classe_erro = ?, codigo_adquirente = ?,
                        mensagem_adquirente = ?, mensagem_cliente = ?, charge_id = ?,
                        duracao_ms = ?, http_status = ?
                  WHERE id = ?"
            )->execute([
                $c->resultado(), $c->classeErro, $c->codigoAdquirente,
                $c->mensagemAdquirente !== null ? mb_substr($c->mensagemAdquirente, 0, 255) : null,
                mb_substr($c->mensagemCliente, 0, 255), $c->chargeId,
                $c->duracaoMs, $c->httpStatus, $id,
            ]);
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'pagamento', ['acao' => 'fechar_tentativa']);
        }
    }

    /** Resolve o adapter pelo código. Ponto único de crescimento por adquirente. */
    private function adquirentePorCodigo(string $codigo): ?AdquirenteInterface
    {
        return match ($codigo) {
            'safrapay' => new SafraPayAdapter(),
            'fake'     => new FakeAdquirenteAdapter('fake'),
            default    => null,
        };
    }
}
