<?php
declare(strict_types=1);

/**
 * WebhookController
 *
 * Endpoint público que recebe webhooks do gateway Malga.
 *
 * Rota: POST /webhooks/malga (sem CSRF, sem auth — é chamada pela Malga)
 *
 * Responsabilidades (NESSA ORDEM, é importante):
 *   1. Capturar o body BRUTO (php://input) — antes de qualquer parsing
 *   2. Validar X-Plug-Date dentro da janela de replay (5min)
 *   3. Validar X-Plug-Signature contra o body bruto + date
 *   4. Verificar idempotência via X-Idempotency-Key
 *   5. Persistir em pgto_webhook_log (sempre, mesmo se assinatura falhar — pra auditoria)
 *   6. Processar SÍNCRONO via MalgaWebhookProcessor
 *   7. Responder 200 dentro do timeout (5s pra retentativas)
 *
 * Política de resposta:
 *   - Assinatura/timestamp inválido → 401 (Malga ainda retentativa)
 *   - Body malformado → 400 (não vai retentativa)
 *   - Erro de processamento de domínio → 200 + log marcado como erro (worker reprocessa)
 *   - Sucesso ou já processado → 200
 *
 * Por que sempre 200 em erros de domínio:
 *   - Erros transitórios (DB offline) seriam OK pra retentativa, mas
 *     erros de validação de payload nunca melhoram em retentativa
 *   - Mais limpo deixar o nosso worker controlar o retry
 *   - A Malga só retenta 6x — não queremos perder eventos
 *
 * O Controller herda da classe base mas NÃO chama verifyCsrf nem requireLogin.
 *
 * Rotas:
 *   POST /webhooks/malga          → malga()
 *   GET  /webhooks/malga/diagnose → diagnose()  (desativar após debug)
 */
class WebhookController extends Controller
{
    /**
     * Tenta gravar no log mesmo para requests inválidas.
     * Não lança exceção — falha silenciosamente.
     */
    private function tentarGravarLogFalha(
        string $motivo,
        string $rawBody,
        string $sig,
        array  $headers,
        ?string $ip,
        string $eventId = ''
    ): void {
        try {
            $fakeEventId = $eventId !== ''
                ? $eventId
                : ('falha-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)));

            $this->persistirLog([
                'event_id'          => $fakeEventId,
                'tipo'              => 'FALHA.' . substr($motivo, 0, 30),
                'charge_id'         => null,
                'payload'           => $rawBody !== '' ? $rawBody : '(vazio)',
                'assinatura_header' => $sig,
                'assinatura_valida' => 0,
                'ip_origem'         => $ip,
            ]);
        } catch (\Throwable $e) {
            if (class_exists('LogService')) {
                LogService::warning('[Webhook] não consegui gravar log de falha: ' . $e->getMessage());
            }
        }
    }
    /**
     * POST /webhooks/malga
     */
    public function malga(): void
    {
        // Restringe a POST
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->respond(405, ['error' => 'Method Not Allowed']);
            return;
        }

        // 1) Body bruto e headers
        $rawBody     = (string) @file_get_contents('php://input');
        $headers     = $this->getRequestHeaders();
        $plugDate    = $headers['X-Plug-Date']       ?? $headers['x-plug-date']       ?? '';
        $plugSig     = $headers['X-Plug-Signature']  ?? $headers['x-plug-signature']  ?? '';
        $idempotency = $headers['X-Idempotency-Key'] ?? $headers['x-idempotency-key'] ?? '';
        $ipOrigem    = $_SERVER['REMOTE_ADDR'] ?? null;

        // 2) Valida headers mínimos
        if ($rawBody === '' || $plugDate === '' || $plugSig === '') {
            // FIX: grava no log mesmo assim — ajuda a ver o que chegou
            $this->tentarGravarLogFalha(
                'headers ou body ausentes',
                $rawBody, $plugSig, $headers, $ipOrigem, $idempotency
            );
            $this->respond(400, ['error' => 'Bad Request: headers or body missing']);
            return;
        }

        // 3) Valida assinatura Ed25519
        $assinaturaValida = 0;
        $motivoRejeicao   = null;
        try {
            $validator = MalgaWebhookSignatureValidator::fromGatewayCodigo('malga');
            $check = $validator->verificar($rawBody, $plugSig, $plugDate);
            if ($check['valid']) {
                $assinaturaValida = 1;
            } else {
                $motivoRejeicao = $check['motivo'];
            }
        } catch (\Throwable $e) {
            // gateway não configurado ou libsodium ausente
            if (class_exists('LogService')) {
                LogService::error('[Webhook] validador falhou: ' . $e->getMessage());
            }
            $this->respond(503, ['error' => 'Webhook not configured']);
            return;
        }

        // 4) Decodifica payload (necessário pra extrair event_id e charge_id)
        $payload = json_decode($rawBody, true);
        $eventId = $idempotency !== ''
            ? $idempotency
            : (is_array($payload) ? ($payload['id'] ?? null) : null);

        // 5) Persiste no log — SEMPRE, independente da assinatura
        //    assinatura_valida = 0  →  chegou mas foi rejeitado
        //    assinatura_valida = 1  →  válido, será processado
        $logId = null;
        try {
            $logId = $this->persistirLog([
                'event_id'          => $eventId ?? ('sem-id-' . bin2hex(random_bytes(4))),
                'tipo'              => is_array($payload)
                    ? (($payload['object'] ?? '?') . '.' . ($payload['event'] ?? '?'))
                    : '?',
                'charge_id'         => is_array($payload) ? ($payload['data']['id'] ?? null) : null,
                'payload'           => $rawBody,
                'assinatura_header' => $plugSig,
                'assinatura_valida' => $assinaturaValida,
                'ip_origem'         => $ipOrigem,
            ]);
        } catch (\PDOException $e) {
            // Conflito UNIQUE (event_id duplicado) = já processamos
            if ($this->isDuplicateKey($e)) {
                if (class_exists('LogService')) {
                    LogService::info('[Webhook] evento duplicado', ['event_id' => $eventId]);
                }
                $this->respond(200, ['status' => 'duplicate', 'event_id' => $eventId]);
                return;
            }
            if (class_exists('LogService')) {
                LogService::error('[Webhook] falha ao persistir log: ' . $e->getMessage());
            }
            $this->respond(500, ['error' => 'Internal error persisting webhook log']);
            return;
        }

        // 6) Rejeita se assinatura inválida (depois de gravar no log!)
        if ($assinaturaValida === 0) {
            if (class_exists('LogService')) {
                LogService::warning('[Webhook] assinatura rejeitada', [
                    'log_id'    => $logId,
                    'motivo'    => $motivoRejeicao,
                    'idade_seg' => null,
                    'ip'        => $ipOrigem,
                    'plug_date' => $plugDate,
                    'sig_len'   => strlen($plugSig),
                    'body_len'  => strlen($rawBody),
                ]);
            }
            $this->respond(401, ['error' => 'Invalid signature', 'log_id' => $logId]);
            return;
        }

        // 7) Body inválido (JSON mal formado)
        if (!is_array($payload)) {
            $this->respond(400, ['error' => 'Invalid JSON']);
            return;
        }

        // 8) Processa
        try {
            $processor = new MalgaWebhookProcessor();
            $resultado = $processor->processarPorLogId($logId);
            $this->respond(200, [
                'status'  => $resultado['ok'] ? 'processed' : 'queued_for_retry',
                'log_id'  => $logId,
                'motivo'  => $resultado['motivo'],
            ]);
        } catch (\Throwable $e) {
            if (class_exists('LogService')) {
                LogService::error('[Webhook] erro pós-validação: ' . $e->getMessage(), [
                    'log_id' => $logId,
                ]);
            }
            $this->respond(200, ['status' => 'received_with_error', 'log_id' => $logId]);
        }
    }

    /**
     * GET /webhooks/malga/diagnose?token=DIAGNOSE_TOKEN
     *
     * Endpoint de diagnóstico temporário. Retorna o que chegou recentemente
     * no log SEM processar nada. Protegido por token estático.
     *
     * Ative definindo WEBHOOK_DIAGNOSE_TOKEN em defines.php:
     *   define('WEBHOOK_DIAGNOSE_TOKEN', 'seu-token-secreto-aqui');
     *
     * Desative (ou remova este método) após resolver o problema.
     */
    public function diagnose(): void
    {
        if (!defined('WEBHOOK_DIAGNOSE_TOKEN') || WEBHOOK_DIAGNOSE_TOKEN === '') {
            $this->respond(404, ['error' => 'Not found']);
            return;
        }

        $token = $_GET['token'] ?? '';
        if (!hash_equals((string) WEBHOOK_DIAGNOSE_TOKEN, $token)) {
            $this->respond(403, ['error' => 'Forbidden']);
            return;
        }

        try {
            $db = Database::getInstance()->getConnection();

            $ultimos = $db->query(
                "SELECT id, event_id, tipo, charge_id,
                        assinatura_valida, processado, erro,
                        tentativas, ip_origem, recebido_em,
                        LEFT(payload, 500) AS payload_preview,
                        LEFT(assinatura_header, 60) AS sig_preview
                   FROM pgto_webhook_log
                  ORDER BY id DESC
                  LIMIT 10"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $saude = [
                'total'              => (int) $db->query("SELECT COUNT(*) FROM pgto_webhook_log")->fetchColumn(),
                'assinatura_invalida'=> (int) $db->query("SELECT COUNT(*) FROM pgto_webhook_log WHERE assinatura_valida = 0")->fetchColumn(),
                'pendentes'          => (int) $db->query("SELECT COUNT(*) FROM pgto_webhook_log WHERE processado = 0 AND assinatura_valida = 1")->fetchColumn(),
                'server_time'        => date('Y-m-d H:i:s'),
                'server_time_ms'     => (int)(microtime(true) * 1000),
            ];

            $this->respond(200, [
                'saude'   => $saude,
                'ultimos' => $ultimos,
                'dica'    => $saude['assinatura_invalida'] > 0
                    ? 'Há requests com assinatura inválida — veja payload_preview e sig_preview pra entender o que chegou'
                    : ($saude['total'] === 0
                        ? 'Tabela vazia — a Malga ainda não enviou nenhum webhook pra este endpoint'
                        : 'Tudo OK'),
            ]);
        } catch (\Throwable $e) {
            $this->respond(500, ['error' => $e->getMessage()]);
        }
    }

    // =================================================================
    // PRIVADOS
    // =================================================================


    /**
     * POST /webhooks/safrapay
     *
     * Recebe as notificações da Safra Pay (EventType 1 Created, 2 Updated).
     * Mesmo esqueleto do webhook da Malga — corpo cru primeiro, autenticação
     * isolada, log com idempotência, resposta rápida.
     *
     * DUAS DECISÕES QUE VALEM EXPLICAR:
     *
     * 1. Responde 200 mesmo em evento duplicado. A Safra exige 2xx em até 30s
     *    e reentrega quando não recebe; devolver erro num duplicado geraria
     *    reentrega infinita de algo que já foi processado.
     *
     * 2. Autenticação recusada devolve 401 SEM processar, mas o log é gravado.
     *    Tentativa forjada é justamente o que se quer ver depois.
     *
     * O efeito financeiro NÃO acontece aqui. O header da Safra é segredo
     * compartilhado, não assinatura do corpo — então quem confirma o pagamento
     * é a reconsulta em GET /v2/charge/{id}, feita pelo processor.
     */
    public function safrapay(): void
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $headers = $this->getRequestHeaders();
        $ip      = $_SERVER['REMOTE_ADDR'] ?? null;

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $this->logFalha('payload nao e JSON', $rawBody, $headers, $ip);
            $this->respond(400, ['ok' => false, 'msg' => 'payload invalido']);
            return;
        }

        $evento    = SafraPayWebhookEvento::daPayload($payload);
        $validacao = (new SafraPayWebhookValidator())->validar($headers);

        try {
            $logId = $this->persistirLog([
                'gateway_codigo'    => 'safrapay',
                'event_id'          => $evento->eventId(),
                'tipo'              => $evento->tipo(),
                'charge_id'         => $evento->chargeId,
                'payload'           => $rawBody,
                'assinatura_header' => 'Authorization: ' . (empty($headers['Authorization']) ? '(ausente)' : '(presente)'),
                'assinatura_valida' => $validacao['valida'] ? 1 : 0,
                'ip_origem'         => $ip,
            ]);
        } catch (\PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                // Reentrega do mesmo evento: já registrado e já tratado.
                LogService::info('[Webhook safrapay] evento duplicado ignorado', [
                    'charge_id' => $evento->chargeId,
                    'tipo'      => $evento->tipo(),
                ], 'pagamento');
                $this->respond(200, ['ok' => true, 'msg' => 'duplicado']);
                return;
            }
            throw $e;
        }

        if (!$validacao['valida']) {
            LogService::critical('[Webhook safrapay] autenticacao recusada', [
                'motivo'    => $validacao['motivo'],
                'charge_id' => $evento->chargeId,
                'ip'        => $ip,
            ], 'pagamento');
            $this->respond(401, ['ok' => false, 'msg' => 'nao autorizado']);
            return;
        }

        LogService::info('[Webhook safrapay] evento recebido', [
            'charge_id'          => $evento->chargeId,
            'pedido'             => $evento->merchantChargeId,
            'tipo'               => $evento->tipo(),
            'metodo'             => $evento->metodo,
            'transaction_status' => $evento->transactionStatus,
            'valor_centavos'     => $evento->valorCentavos,
        ], 'pagamento');

        // Processa de forma SINCRONA: a Safra exige 2xx em ate 30s e o
        // processor reconsulta a cobranca antes de qualquer efeito.
        // Falha aqui NAO vira erro HTTP — o evento ja esta gravado, e
        // devolver erro faria a Safra reentregar algo ja registrado.
        try {
            $r = (new SafraPayWebhookProcessor())->processarPorLogId($logId);
            if (!$r['ok']) {
                LogService::warning('[Webhook safrapay] evento nao aplicado', [
                    'charge_id' => $evento->chargeId,
                    'motivo'    => $r['motivo'],
                ], 'pagamento');
            }
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'pagamento', [
                'charge_id' => $evento->chargeId,
                'acao'      => 'processar_webhook',
            ]);
        }

        $this->respond(200, ['ok' => true]);
    }

    /**
     * POST /webhooks/clearsale[/{token}]
     *
     * Notificacao de mudanca de status da ClearSale.
     *
     * TRES COISAS DEFINEM ESTE ENDPOINT, e todas vem da documentacao deles:
     *
     *  1. A NOTIFICACAO NAO TRAZ O VEREDITO. O corpo e so
     *     {code, date, type} — "mudou alguma coisa neste pedido". O parecer
     *     tem que ser consultado a parte. Entao nada do que chega aqui e
     *     confiado: o unico uso do corpo e descobrir QUAL pedido reconsultar.
     *
     *  2. NAO HA ASSINATURA NEM TOKEN no protocolo deles. Como a URL e
     *     cadastrada por e-mail, da para embutir um segredo no proprio
     *     caminho (CLEARSALE_WEBHOOK_TOKEN). E protecao contra abuso, nao
     *     contra falsificacao de parecer — falsificar e impossivel de
     *     qualquer forma, porque a fonte da verdade e a API deles.
     *
     *  3. ELES RETENTAM ATE RECEBER 200. Qualquer outro codigo vira
     *     retentativa em loop. Por isso este metodo responde 200 em quase
     *     todos os caminhos, inclusive quando recusa: engasgar aqui geraria
     *     tempestade de retentativa sem consertar nada.
     *
     * PERDER UMA NOTIFICACAO NAO PERDE O PARECER: antes de processar, a
     * analise volta para a fila do cron (agendarConsultaImediata). Se este
     * request morrer no meio, o worker pega em seguida. A notificacao encurta
     * a espera; ela nao e a unica via.
     */
    public function clearsale(string $token = ''): void
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $ip      = $_SERVER['REMOTE_ADDR'] ?? null;

        if (!$this->clearsaleTokenValido($token)) {
            LogService::critical('[Webhook clearsale] token do caminho invalido', [
                'ip' => $ip, 'tem_token' => $token !== '',
            ], 'pagamento');
            // 200 de proposito: uma URL mal cadastrada nao pode virar loop
            // infinito de retentativa. O cron cobre o que se perder aqui.
            $this->respond(200, ['ok' => true]);
            return;
        }

        $payload = json_decode($rawBody, true);
        $codigo  = is_array($payload) ? trim((string) ($payload['code'] ?? '')) : '';

        if ($codigo === '') {
            LogService::warning('[Webhook clearsale] payload sem code', [
                'ip' => $ip, 'corpo' => mb_substr($rawBody, 0, 300),
            ], 'pagamento');
            $this->respond(200, ['ok' => true]);
            return;
        }

        $svc  = new ClearSaleParecerService();
        // Uma consulta barata no nosso banco antes de qualquer chamada externa:
        // codigo que nao esta esperando parecer nao gasta request na ClearSale.
        $linha = $svc->pendentePorCodigo($codigo);

        if (!$linha) {
            LogService::info('[Webhook clearsale] notificacao sem analise pendente', [
                'pedido' => $codigo, 'tipo' => $payload['type'] ?? null, 'ip' => $ip,
            ], 'pagamento');
            $this->respond(200, ['ok' => true]);
            return;
        }

        // Devolve para a fila do cron ANTES de processar. Esta e a linha que
        // torna seguro responder 200 logo em seguida.
        $svc->agendarConsultaImediata((int) $linha['id']);

        LogService::info('[Webhook clearsale] notificacao recebida', [
            'pedido' => $codigo, 'tipo' => $payload['type'] ?? null,
            'status_atual' => $linha['codigo_status'] ?? null,
        ], 'pagamento');

        $this->responderEProcessar(static function () use ($svc, $linha, $codigo): void {
            try {
                $r = $svc->processar($linha);
                LogService::info('[Webhook clearsale] parecer aplicado', [
                    'pedido' => $codigo, 'acao' => $r['acao'],
                    'status' => $r['codigo_status'], 'score' => $r['score'],
                ], 'pagamento');
            } catch (\Throwable $e) {
                // Ja respondemos 200 e a analise ja voltou para a fila:
                // o cron reprocessa. Aqui so registra.
                LogService::exception($e, 'error', 'pagamento',
                    ['acao' => 'webhook_clearsale', 'pedido' => $codigo]);
            }
        });
    }

    /**
     * Segredo opcional no caminho da URL.
     *
     * Sem CLEARSALE_WEBHOOK_TOKEN configurado o endpoint fica aberto — e o
     * comportamento correto, porque o protocolo da ClearSale nao preve
     * segredo nenhum e travar por padrao deixaria o webhook morto sem aviso.
     * Com token configurado, a comparacao e em tempo constante.
     */
    private function clearsaleTokenValido(string $token): bool
    {
        $esperado = (string) (getenv('CLEARSALE_WEBHOOK_TOKEN')
                    ?: ($_ENV['CLEARSALE_WEBHOOK_TOKEN'] ?? ''));

        if ($esperado === '') return true;

        return $token !== '' && hash_equals($esperado, $token);
    }

    /**
     * Responde 200 e fecha a conexao antes de trabalhar.
     *
     * A ClearSale so quer o 200; deixa-la esperando a nossa consulta ao
     * endpoint de status (que ja levou 4s em teste) so aumenta a chance de
     * timeout do lado deles e retentativa do nosso. Onde o SAPI nao souber
     * encerrar cedo, processa antes de responder — mais lento, mas correto.
     */
    private function responderEProcessar(callable $trabalho): void
    {
        // Cada SAPI batiza a mesma coisa de um jeito. LiteSpeed (producao)
        // usa litespeed_finish_request; PHP-FPM usa fastcgi_finish_request;
        // Apache com mod_php nao tem nenhum dos dois.
        $encerrar = null;
        foreach (['litespeed_finish_request', 'fastcgi_finish_request'] as $f) {
            if (function_exists($f)) { $encerrar = $f; break; }
        }

        if ($encerrar === null) {
            // Sem como fechar cedo, trabalha antes de responder. Mais lento,
            // e a ClearSale pode estourar o proprio timeout e reenviar — o
            // que e inofensivo: o piso de 30s e o cron cobrem a reentrega.
            $trabalho();
            $this->respond(200, ['ok' => true]);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo json_encode(['ok' => true]);

        $encerrar();
        $trabalho();
        exit;
    }

    /**
     * POST /webhooks/mercadopago
     *
     * Notificacao do Mercado Pago. E ela que fecha o ciclo do Pix e do
     * boleto: os dois terminam o fluxo em `pendente`, com o dinheiro ainda
     * nao recebido, e so esta chamada avisa quando ele entra.
     *
     * TRES REGRAS QUE VEM DO PROTOCOLO DELES:
     *
     *  1. A NOTIFICACAO NAO TRAZ O ESTADO, so o id do recurso —
     *     {id, live_mode, type, action, data:{id}}. O estado tem que ser
     *     consultado. Entao nada do corpo e confiado: ele so diz O QUE
     *     reconsultar, e a fonte da verdade continua sendo a API.
     *
     *  2. ELES ESPERAM 200/201 EM ATE 22 SEGUNDOS e reenviam a cada 15
     *     minutos ate conseguir. Por isso responde cedo e trabalha depois.
     *
     *  3. A ASSINATURA E OPCIONAL DO LADO DELES, mas quando ha segredo
     *     cadastrado ela e verificada — HMAC-SHA256 sobre
     *     "id:<data.id>;request-id:<x-request-id>;ts:<ts>;".
     *
     * Reenvio nao causa dano: reconsultar e reaplicar o mesmo estado e
     * idempotente.
     */
    public function mercadopago(): void
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $headers = $this->getRequestHeaders();
        $ip      = $_SERVER['REMOTE_ADDR'] ?? null;

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            LogService::warning('[Webhook mercadopago] corpo nao e JSON', [
                'ip' => $ip, 'corpo' => mb_substr($rawBody, 0, 200), 'payloaad'=>$payload
            ], 'pagamento');
            $this->respond(200, ['ok' => true]);
            return;
        }

        $tipo     = (string) ($payload['type'] ?? $payload['topic'] ?? '');
        $recurso  = (string) ($payload['data']['id'] ?? $_GET['data.id'] ?? '');
        $acao     = (string) ($payload['action'] ?? '');

        if ($recurso === '') {
            LogService::warning('[Webhook mercadopago] notificacao sem data.id', [
                'ip' => $ip, 'tipo' => $tipo,
            ], 'pagamento');
            $this->respond(200, ['ok' => true]);
            return;
        }

        $cred = PagamentoCredencialService::para('mercadopago');

        LogService::audit('PagamentoCredencialService', $cred);

        if (!$this->mpAssinaturaValida($headers, $recurso, (string) $cred['webhook_secret'])) {
            LogService::critical('[Webhook mercadopago] assinatura invalida', [
                'ip' => $ip, 'recurso' => $recurso, 'tipo' => $tipo, 'header' =>$headers
            ], 'pagamento');
            // 200 de proposito: assinatura errada nao se conserta com
            // retentativa, e 4xx aqui geraria reenvio a cada 15 min para
            // sempre. O log critico e quem avisa.
            $this->respond(200, ['ok' => true]);
            return;
        }

        LogService::info('[Webhook mercadopago] notificacao recebida', [
            'tipo' => $tipo, 'acao' => $acao, 'recurso' => $recurso, 'header' =>$headers
        ], 'pagamento');

        // So `orders` interessa: e o recurso que a Orders API cria. Os demais
        // topicos (merchant_order, chargebacks) tem tratamento proprio.
        if ($tipo !== '' && !str_starts_with($tipo, 'order')) {
            $this->respond(200, ['ok' => true]);
            return;
        }

        $this->responderEProcessar(static function () use ($recurso): void {
            try {
                (new MercadoPagoRetornoService())->aplicar($recurso);
            } catch (\Throwable $e) {
                // Ja respondemos 200. Eles reenviam em 15 min de qualquer
                // forma quando nao processarmos, e reaplicar e idempotente.
                LogService::exception($e, 'error', 'pagamento',
                    ['acao' => 'webhook_mercadopago', 'recurso' => $recurso]);
            }
        });
    }

    /**
     * Assinatura x-signature do Mercado Pago.
     *
     * Sem segredo cadastrado, aceita — a verificacao e opcional no painel
     * deles, e recusar por padrao deixaria o webhook morto sem aviso. Com
     * segredo, a comparacao e em tempo constante.
     *
     * O manifesto omite partes ausentes e usa data.id em minusculas: os ids
     * da Orders API sao maiusculos (ORDTST01...), entao esquecer isso faz
     * TODA assinatura falhar.
     */
    private function mpAssinaturaValida(array $headers, string $recurso, string $segredo): bool
    {
        if ($segredo === '') return true;

        $assinatura = '';
        $requestId  = '';
        foreach ($headers as $k => $v) {
            $k = strtolower($k);
            if ($k === 'x-signature')  $assinatura = (string) $v;
            if ($k === 'x-request-id') $requestId  = (string) $v;
        }

        if ($assinatura === '') return false;

        $ts = '';
        $v1 = '';
        foreach (explode(',', $assinatura) as $parte) {
            $par = explode('=', trim($parte), 2);
            if (count($par) !== 2) continue;
            if ($par[0] === 'ts') $ts = $par[1];
            if ($par[0] === 'v1') $v1 = $par[1];
        }

        if ($v1 === '') return false;

        $manifesto = '';
        if ($recurso  !== '') $manifesto .= 'id:' . strtolower($recurso) . ';';
        if ($requestId !== '') $manifesto .= 'request-id:' . $requestId . ';';
        if ($ts        !== '') $manifesto .= 'ts:' . $ts . ';';

        return hash_equals(hash_hmac('sha256', $manifesto, $segredo), $v1);
    }

    private function getRequestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            if (is_array($h)) return $h;
        }
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $out[$name] = $v;
            }
        }
        return $out;
    }

    private function persistirLog(array $dados): int
    {
        $db = Database::getInstance()->getConnection();

        // Resolve gateway_id pelo codigo da adquirente. Antes era fixo em
        // 'malga'; com multiplas adquirentes o log precisa saber de quem veio.
        $codigo = (string) ($dados['gateway_codigo'] ?? 'malga');
        $stmt = $db->prepare("SELECT id FROM pgto_gateways WHERE codigo = ? LIMIT 1");
        $stmt->execute([$codigo]);
        $gatewayId = (int) ($stmt->fetchColumn() ?: 0);
        if ($gatewayId === 0) {
            throw new RuntimeException("Gateway {$codigo} nao existe em pgto_gateways");
        }

        $db->prepare(
            "INSERT INTO pgto_webhook_log
                (gateway_id, event_id, tipo, charge_id, payload,
                 assinatura_header, assinatura_valida, ip_origem, recebido_em)
             VALUES (:gw, :eid, :tipo, :cid, :payload,
                     :ah, :av, :ip, NOW())"
        )->execute([
            ':gw'      => $gatewayId,
            ':eid'     => $dados['event_id'],
            ':tipo'    => mb_substr($dados['tipo'] ?? '?', 0, 80),
            ':cid'     => $dados['charge_id'] ?? null,
            ':payload' => $dados['payload'],
            ':ah'      => mb_substr($dados['assinatura_header'] ?? '', 0, 512),
            ':av'      => (int) ($dados['assinatura_valida'] ?? 0),
            ':ip'      => $dados['ip_origem'] ?? null,
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Grava uma entrada de log mesmo quando algo falha antes da persistência
     * normal — auditoria forense de tentativas inválidas.
     */
    private function logFalha(string $motivo, string $rawBody, array $headers, ?string $ip, string $eventId = ''): void
    {
        if (!class_exists('LogService')) return;
        LogService::warning('[Webhook] falha: ' . $motivo, [
            'event_id'    => $eventId,
            'ip'          => $ip,
            'body_size'   => strlen($rawBody),
            'has_sig'     => !empty($headers['X-Plug-Signature']) || !empty($headers['x-plug-signature']),
            'has_date'    => !empty($headers['X-Plug-Date']) || !empty($headers['x-plug-date']),
        ]);
    }

    /**
     * Detecta erro de chave duplicada (UNIQUE constraint).
     * SQLSTATE 23000 + errno 1062 = MySQL/MariaDB duplicate entry.
     */
    private function isDuplicateKey(\PDOException $e): bool
    {
        return $e->getCode() === '23000' ||
               (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062);
    }

    /**
     * Resposta padronizada (JSON, status + body).
     */
    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        exit;
    }
}