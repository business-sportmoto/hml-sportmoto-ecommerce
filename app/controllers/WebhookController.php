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