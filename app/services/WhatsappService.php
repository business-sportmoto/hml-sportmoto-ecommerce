<?php
/**
 * app/services/WhatsappService.php
 *
 * Interface de alto nível para notificações WhatsApp via DataCrazy.
 *
 * USO RÁPIDO:
 *   WhatsappService::sendSimples($cel, $nome, 'tipo', "Mensagem *negrito*");
 *   WhatsappService::sendPedidoConfirmado($cliente, $pedido);
 *
 * COMPORTAMENTO:
 *   1. Valida e normaliza o telefone (descarta se inválido)
 *   2. Anti-duplicação: bloqueia reenvio idêntico em janela curta (default 5min)
 *   3. Busca conversa no DataCrazy
 *   4. Se achou -> envia | Se não -> cria lead e ignora silenciosamente
 *   5. Registra tudo em whatsapp_log (nunca lança exceção pra cima)
 *
 * GARANTIA: nenhum método público lança exceção. Falhas retornam false e
 * são logadas. Isso evita que um erro de WhatsApp quebre um checkout.
 */
class WhatsappService
{
    /** @var int Janela anti-duplicação em segundos */
    private const DEDUP_JANELA_SEG = 300;

    // =========================================================================
    // GENÉRICO
    // =========================================================================

    /**
     * Envia uma mensagem WhatsApp genérica. Sempre retorna bool, nunca lança.
     *
     * @param array $opts  nome, email, cliente_id, dedup (bool), dedup_chave (string)
     */
    public static function send(string $telefone, string $tipo, string $mensagem, array $opts = []): bool
    {
        $clienteId = isset($opts['cliente_id']) ? (int)$opts['cliente_id'] : null;

        try {
            // ── Validação básica ───────────────────────────────────
            $telefone = trim($telefone);
            $mensagem = trim($mensagem);

            if ($telefone === '') {
                self::log($tipo, $clienteId, '', $mensagem, null, null, 'erro', 'telefone vazio', $opts);
                return false;
            }
            if ($mensagem === '') {
                self::log($tipo, $clienteId, $telefone, '', null, null, 'erro', 'mensagem vazia', $opts);
                return false;
            }

            // ── Instancia o adapter (pode falhar se config ausente) ─
            try {
                $dc = new DataCrazyService();
            } catch (Throwable $e) {
                self::log($tipo, $clienteId, $telefone, $mensagem, null, null, 'erro',
                    'config: ' . $e->getMessage(), $opts);
                return false;
            }

            // ── Telefone inválido após normalização ────────────────
            $numero = $dc->normalizarTelefone($telefone);
            if (!$numero) {
                self::log($tipo, $clienteId, $telefone, $mensagem, null, null, 'erro',
                    'telefone inválido', $opts);
                return false;
            }

            // ── Anti-duplicação ────────────────────────────────────
            $dedup = $opts['dedup'] ?? true;
            if ($dedup) {
                $chave = $opts['dedup_chave'] ?? ($tipo . '|' . $numero . '|' . md5($mensagem));
                if (self::jaEnviadoRecentemente($chave)) {
                    self::log($tipo, $clienteId, $numero, $mensagem, null, null, 'erro',
                        'duplicado (ignorado)', $opts);
                    return false;
                }
            }

            // ── Busca conversa ─────────────────────────────────────
            $conversa = $dc->buscarConversaPorTelefone($numero);

            if (!$conversa) {
                // Sem conversa: cria lead e ignora silenciosamente
                $leadId = null;
                try {
                    $lead = $dc->criarLead(
                        $opts['nome'] ?? 'Cliente',
                        $numero,
                        $opts['email'] ?? null,
                        ['source' => defined('SITE_NAME') ? SITE_NAME : 'SportMoto']
                    );
                    $leadId = $lead['id'] ?? null;
                } catch (Throwable $e) {
                    if (class_exists('LogService')) {
                        try { LogService::warning('whatsapp criarLead: ' . $e->getMessage()); } catch (Throwable $x) {}
                    }
                }
                self::log($tipo, $clienteId, $numero, $mensagem, null, $leadId,
                    $leadId ? 'lead_criado' : 'sem_conversa', null, $opts);
                return false;
            }

            // ── Envia ──────────────────────────────────────────────
            $conversaId = $conversa['id'] ?? '';
            if ($conversaId === '') {
                self::log($tipo, $clienteId, $numero, $mensagem, null, null, 'erro',
                    'conversa sem ID', $opts);
                return false;
            }

            $dc->enviarMensagem($conversaId, $mensagem);
            self::log($tipo, $clienteId, $numero, $mensagem, $conversaId, null, 'enviado', null, $opts);
            return true;

        } catch (Throwable $e) {
            // Rede ininstável (sem conversa de outras causas, dispatch falho, etc.)
            if (class_exists('LogService')) {
                try { LogService::error("whatsapp[$tipo]: " . $e->getMessage()); } catch (Throwable $x) {}
            }
            self::log($tipo, $clienteId, $telefone, $mensagem, null, null, 'erro', $e->getMessage(), $opts);
            return false;
        }
    }

    /**
     * Notificação simples (análogo a MailHelper::sendSimples()).
     */
    public static function sendSimples(string $telefone, string $nome, string $tipo, string $mensagem, ?int $clienteId = null): bool
    {
        return self::send($telefone, $tipo, $mensagem, [
            'nome'       => $nome,
            'cliente_id' => $clienteId,
        ]);
    }

    // =========================================================================
    // EVENTOS DE PEDIDO
    // =========================================================================

    public static function sendPedidoConfirmado(array $cliente, array $pedido): bool
    {
        $nome   = self::primeiroNome($cliente);
        $codigo = self::codigoPedido($pedido);
        $total  = self::moeda($pedido['total'] ?? null);
        $url    = self::urlPedido($pedido);

        $msg = "✅ *Pedido confirmado!*\n\n"
             . "Olá, {$nome}! Recebemos seu pedido.\n\n"
             . "📦 Pedido: *{$codigo}*\n"
             . ($total ? "💰 Total: *{$total}*\n" : '')
             . ($url ? "\nAcompanhe: {$url}" : '');

        return self::send(self::telefone($cliente), 'pedido_confirmado', $msg, self::optsCliente($cliente, $pedido));
    }

    public static function sendPagamentoAprovado(array $cliente, array $pedido): bool
    {
        $nome   = self::primeiroNome($cliente);
        $codigo = self::codigoPedido($pedido);
        $url    = self::urlPedido($pedido);

        $msg = "💳 *Pagamento aprovado!*\n\n"
             . "{$nome}, seu pagamento foi confirmado.\n"
             . "Pedido *{$codigo}* já está sendo preparado! 🏍️"
             . ($url ? "\n\nAcompanhe: {$url}" : '');

        return self::send(self::telefone($cliente), 'pagamento_aprovado', $msg, self::optsCliente($cliente, $pedido));
    }

    public static function sendPixPendente(array $cliente, array $pedido): bool
    {
        $nome   = self::primeiroNome($cliente);
        $codigo = self::codigoPedido($pedido);
        $total  = self::moeda($pedido['total'] ?? null);
        $pix    = $pedido['pix_copia_cola'] ?? ($pedido['pagamento']['copia_cola'] ?? '');
        $url    = self::urlCheckout($pedido);

        $msg = "⏱️ *Seu PIX está prestes a expirar!*\n\n"
             . "{$nome}, o pedido *{$codigo}*" . ($total ? " ({$total})" : '') . " ainda aguarda pagamento.\n\n";
        if ($pix !== '') {
            $msg .= "📋 *Código PIX (copia e cola):*\n{$pix}\n\n";
        }
        if ($url) {
            $msg .= "Ou acesse: {$url}";
        }

        // dedup por pedido (não por mensagem) — o copia-cola muda pouco mas o lembrete é único por pedido+passo
        $opts = self::optsCliente($cliente, $pedido);
        $opts['dedup_chave'] = 'pix_pendente|' . self::telefone($cliente) . '|' . $codigo;
        return self::send(self::telefone($cliente), 'pix_pendente', $msg, $opts);
    }

    public static function sendBoletoPendente(array $cliente, array $pedido): bool
    {
        $nome   = self::primeiroNome($cliente);
        $codigo = self::codigoPedido($pedido);
        $total  = self::moeda($pedido['total'] ?? null);
        $linha  = $pedido['boleto_linha_digitavel'] ?? ($pedido['pagamento']['linha_digitavel'] ?? '');
        $urlBol = $pedido['boleto_url'] ?? ($pedido['pagamento']['url_boleto'] ?? '');

        $msg = "📄 *Boleto aguardando pagamento*\n\n"
             . "{$nome}, seu pedido *{$codigo}*" . ($total ? " ({$total})" : '') . " ainda não foi pago.\n\n";
        if ($linha !== '') {
            $msg .= "🔢 *Linha digitável:*\n{$linha}\n\n";
        }
        if ($urlBol !== '') {
            $msg .= "📥 Baixar boleto: {$urlBol}";
        }

        $opts = self::optsCliente($cliente, $pedido);
        $opts['dedup_chave'] = 'boleto_pendente|' . self::telefone($cliente) . '|' . $codigo;
        return self::send(self::telefone($cliente), 'boleto_pendente', $msg, $opts);
    }

    public static function sendPedidoEnviado(array $cliente, array $pedido): bool
    {
        $nome     = self::primeiroNome($cliente);
        $codigo   = self::codigoPedido($pedido);
        $rastreio = $pedido['rastreio_codigo'] ?? '';
        $urlRast  = $pedido['rastreio_url'] ?? '';
        $url      = self::urlPedido($pedido);

        $msg = "🚚 *Seu pedido foi enviado!*\n\n"
             . "{$nome}, o pedido *{$codigo}* saiu para entrega!\n\n";
        if ($rastreio !== '') {
            $msg .= "📦 Rastreamento: *{$rastreio}*\n";
            if ($urlRast !== '') $msg .= "🔍 Rastrear: {$urlRast}\n";
            $msg .= "\n";
        }
        if ($url) $msg .= "Acompanhe: {$url}";

        return self::send(self::telefone($cliente), 'pedido_enviado', $msg, self::optsCliente($cliente, $pedido));
    }

    public static function sendPedidoEntregue(array $cliente, array $pedido): bool
    {
        $nome   = self::primeiroNome($cliente);
        $codigo = self::codigoPedido($pedido);
        $url    = self::urlPedido($pedido);

        $msg = "🎉 *Pedido entregue!*\n\n"
             . "{$nome}, seu pedido *{$codigo}* foi entregue!"
             . ($url ? "\n\nQue tal avaliar sua compra? {$url}" : '');

        return self::send(self::telefone($cliente), 'pedido_entregue', $msg, self::optsCliente($cliente, $pedido));
    }

    public static function sendPedidoCancelado(array $cliente, array $pedido): bool
    {
        $nome   = self::primeiroNome($cliente);
        $codigo = self::codigoPedido($pedido);
        $motivo = trim($pedido['motivo'] ?? '');

        $msg = "❌ *Pedido cancelado*\n\n"
             . "{$nome}, o pedido *{$codigo}* foi cancelado."
             . ($motivo !== '' ? "\n\nMotivo: {$motivo}" : '')
             . "\n\nDúvidas? Responda esta mensagem.";

        return self::send(self::telefone($cliente), 'pedido_cancelado', $msg, self::optsCliente($cliente, $pedido));
    }

    // =========================================================================
    // HELPERS DE DADOS (tolerantes a campos faltando)
    // =========================================================================

    private static function telefone(array $cliente): string
    {
        foreach (['celular', 'telefone', 'whatsapp', 'phone', 'fone'] as $campo) {
            if (!empty($cliente[$campo])) return (string)$cliente[$campo];
        }
        return '';
    }

    private static function primeiroNome(array $cliente): string
    {
        $nome = trim($cliente['nome'] ?? ($cliente['name'] ?? ''));
        if ($nome === '') return 'Cliente';
        $partes = preg_split('/\s+/', $nome);
        return $partes[0] ?: 'Cliente';
    }

    private static function codigoPedido(array $pedido): string
    {
        if (!empty($pedido['codigo'])) return (string)$pedido['codigo'];
        if (!empty($pedido['id']))     return '#' . $pedido['id'];
        return '#--';
    }

    private static function moeda($valor): string
    {
        if ($valor === null || $valor === '') return '';
        if (is_numeric($valor)) return 'R$ ' . number_format((float)$valor, 2, ',', '.');
        return (string)$valor; // já formatado
    }

    private static function urlPedido(array $pedido): string
    {
        $base = defined('BASE_URL') ? BASE_URL : '';
        if ($base === '' || empty($pedido['id'])) return '';
        return $base . '/minha-conta/pedido/' . $pedido['id'];
    }

    private static function urlCheckout(array $pedido): string
    {
        $base = defined('BASE_URL') ? BASE_URL : '';
        $ref  = $pedido['codigo'] ?? ($pedido['id'] ?? '');
        if ($base === '' || $ref === '') return '';
        return $base . '/checkout/success/' . $ref;
    }

    private static function optsCliente(array $cliente, array $pedido = []): array
    {
        return [
            'nome'       => $cliente['nome'] ?? ($cliente['name'] ?? 'Cliente'),
            'email'      => $cliente['email'] ?? null,
            'cliente_id' => isset($cliente['id']) ? (int)$cliente['id'] : null,
        ];
    }

    // =========================================================================
    // ANTI-DUPLICAÇÃO
    // =========================================================================

    /**
     * Verifica se uma mensagem idêntica foi enviada nos últimos N segundos.
     * Usa a própria whatsapp_log como fonte de verdade (sobrevive a restart).
     */
    private static function jaEnviadoRecentemente(string $chave): bool
    {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT 1 FROM whatsapp_log
                 WHERE dedup_chave = :c
                   AND status IN ('enviado','lead_criado')
                   AND criado_em > DATE_SUB(NOW(), INTERVAL :seg SECOND)
                 LIMIT 1"
            );
            // bindValue pois INTERVAL não aceita placeholder em alguns setups
            $st->bindValue(':c', $chave);
            $st->bindValue(':seg', self::DEDUP_JANELA_SEG, PDO::PARAM_INT);
            $st->execute();
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false; // em dúvida, não bloqueia o envio
        }
    }

    // =========================================================================
    // LOG — substitua o método inteiro no WhatsappService
    // =========================================================================

    private static function log(
        string  $tipo,
        ?int    $clienteId,
        string  $telefone,
        string  $mensagem,
        ?string $conversaId,
        ?string $leadId,
        string  $status,
        ?string $erroDetalhe,
        array   $opts = []
    ): void {
        // ── 1. whatsapp_log (tabela legada — mantém retrocompatibilidade) ──
        try {
            $db = Database::getInstance()->getConnection();
            $contexto = [];
            foreach (['nome', 'email'] as $k) {
                if (!empty($opts[$k])) $contexto[$k] = $opts[$k];
            }

            $chave = $opts['dedup_chave']
                ?? ($tipo . '|' . preg_replace('/\D/', '', $telefone) . '|' . md5($mensagem));

            $db->prepare(
                "INSERT INTO whatsapp_log
                 (tipo, cliente_id, pedido_id, pedido_codigo, telefone, mensagem,
                  conversa_id, lead_id, status, erro_detalhe, dedup_chave, contexto_json)
                 VALUES (:tp,:cid,:pid,:pcod,:tel,:msg,:conv,:lead,:st,:err,:dk,:ctx)"
            )->execute([
                ':tp'   => $tipo,
                ':cid'  => $clienteId,
                ':pid'  => isset($opts['pedido_id'])     ? (int)$opts['pedido_id']        : null,
                ':pcod' => $opts['pedido_codigo']        ?? null,
                ':tel'  => mb_substr($telefone, 0, 30),
                ':msg'  => $mensagem,
                ':conv' => $conversaId,
                ':lead' => $leadId,
                ':st'   => $status,
                ':err'  => $erroDetalhe,
                ':dk'   => mb_substr($chave, 0, 190),
                ':ctx'  => $contexto ? json_encode($contexto, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (Throwable $e) {}

        // ── 2. canal_log (tabela genérica plugável) ────────────────────────
        if (class_exists('CanalLogService')) {
            CanalLogService::gravar('whatsapp', $tipo, [
                'destinatario'    => $telefone,
                'cliente_id'      => $clienteId,
                'pedido_id'       => $opts['pedido_id']     ?? null,
                'pedido_codigo'   => $opts['pedido_codigo'] ?? null,
                'preview'         => $mensagem,
                'status'          => self::mapearStatusCanal($status),
                'provider_msg_id' => $conversaId,
                'erro_detalhe'    => $erroDetalhe,
                'dedup_chave'     => $opts['dedup_chave'] ?? null,
                'via'             => $opts['via'] ?? 'api',
                'contexto'        => array_filter([
                    'lead_id' => $leadId,
                    'nome'    => $opts['nome'] ?? null,
                ]),
            ]);
        }
    }

    /**
     * Mapeia status do WhatsApp para o enum do canal_log.
     */
    private static function mapearStatusCanal(string $status): string
    {
        return match($status) {
            'enviado'      => 'enviado',
            'sem_conversa' => 'sem_canal',
            'lead_criado'  => 'sem_canal',
            'erro'         => 'erro',
            default        => 'enviado',
        };
    }

    // =========================================================================
    // CONSULTAS ADMIN
    // =========================================================================

    public static function logRecente(int $limit = 100, ?string $tipo = null): array
    {
        try {
            $db = Database::getInstance()->getConnection();
            $limit = max(1, min(500, $limit));
            $where = $tipo ? "WHERE tipo = :t" : "";
            $st = $db->prepare("SELECT * FROM whatsapp_log $where ORDER BY id DESC LIMIT $limit");
            if ($tipo) $st->bindValue(':t', $tipo);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function kpis(): array
    {
        try {
            $db = Database::getInstance()->getConnection();
            return $db->query(
                "SELECT
                    COUNT(*) AS total,
                    SUM(status='enviado')      AS enviados,
                    SUM(status='sem_conversa') AS sem_conversa,
                    SUM(status='lead_criado')  AS leads_criados,
                    SUM(status='erro')         AS erros
                 FROM whatsapp_log
                 WHERE criado_em > DATE_SUB(NOW(), INTERVAL 30 DAY)"
            )->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }


    
/**
 * PATCH para app/services/WhatsappService.php
 *
 * Adiciona suporte a templates HSM via Meta Cloud API.
 * Cole estes métodos dentro da classe WhatsappService,
 * antes do último "}" da classe.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FLUXO AUTOMÁTICO (recomendado):
 *
 *   WhatsappService::sendComFallback(
 *       $cliente, $pedido,
 *       'pedido_enviado',          // tipo para log
 *       "🚚 Mensagem texto livre", // texto (janela aberta)
 *       'pedido_enviado_hsm',      // nome do template Meta (janela fechada)
 *       [MetaCloudService::body($nome, $codigo, $rastreio)] // componentes
 *   );
 *
 * O método tenta texto livre via DataCrazy primeiro.
 * Se a conversa não existir → cai para template Meta direto no número.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OU use os métodos específicos que já fazem tudo:
 *
 *   WhatsappService::sendPedidoEnviadoHsm($cliente, $pedido);
 *   WhatsappService::sendPedidoEntregueHsm($cliente, $pedido);
 *   WhatsappService::sendBoletoPendenteHsm($cliente, $pedido);
 *   WhatsappService::sendReengajamento($cliente, $cupom);
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * IMPORTANTE: o nome do template (ex: 'pedido_enviado_sm') deve bater
 * EXATAMENTE com o nome aprovado no Meta Business Manager.
 * Use listarTemplates() para ver os nomes e status.
 */

    // =========================================================================
    // ENVIO COM FALLBACK AUTOMÁTICO (texto livre → template HSM)
    // =========================================================================

    /**
     * Tenta texto livre via DataCrazy. Se sem conversa, envia template HSM
     * diretamente pelo número via Meta Cloud API.
     *
     * @param array  $cliente          Array do cliente (celular, nome, id, email)
     * @param string $tipo             Identificador para o log
     * @param string $mensagemTexto    Mensagem texto livre (janela aberta)
     * @param string $nomeTemplate     Nome exato do template no Meta (janela fechada)
     * @param array  $componentes      Componentes do template (MetaCloudService::body(...))
     * @param string $idioma           Idioma do template (padrão pt_BR)
     */
    public static function sendComFallback(
        array  $cliente,
        string $tipo,
        string $mensagemTexto,
        string $nomeTemplate,
        array  $componentes = [],
        string $idioma = 'pt_BR'
    ): bool {
        $telefone  = self::telefone($cliente);
        $clienteId = isset($cliente['id']) ? (int)$cliente['id'] : null;
        $opts      = self::optsCliente($cliente);

        if ($telefone === '') {
            self::log($tipo, $clienteId, '', $mensagemTexto, null, null, 'erro', 'telefone vazio', $opts);
            return false;
        }

        // Tenta DataCrazy primeiro (texto livre, janela aberta)
        $ok = self::send($telefone, $tipo, $mensagemTexto, array_merge($opts, ['dedup' => false]));
        if ($ok) return true;

        // Fallback: template HSM via Meta Cloud API
        return self::sendTemplate($telefone, $tipo, $nomeTemplate, $componentes, $idioma, $clienteId, $opts);
    }

    /**
     * Envia template HSM diretamente via Meta Cloud API (sem DataCrazy).
     * Use quando souber que está fora da janela de 24h.
     */
    public static function sendTemplate(
        string $telefone,
        string $tipo,
        string $nomeTemplate,
        array  $componentes = [],
        string $idioma = 'pt_BR',
        ?int   $clienteId = null,
        array  $opts = []
    ) {
        $telefone = trim($telefone);
        if ($telefone === '') {
            self::log($tipo, $clienteId, '', '', null, null, 'erro', 'telefone vazio', $opts);
            return false;
        }

        try {
            $meta   = new MetaCloudService();
            $dc     = new DataCrazyService();
            $numero = $dc->normalizarTelefone($telefone);

            if (!$numero) {
                self::log($tipo, $clienteId, $telefone, $nomeTemplate, null, null, 'erro', 'telefone inválido', $opts);
                return false;
            }

            $r        = $meta->enviarTemplate($numero, $nomeTemplate, $idioma, $componentes);
            $msgId    = $r['messages'][0]['id'] ?? null;

            self::log($tipo, $clienteId, $numero, "[template:{$nomeTemplate}]",
                $msgId, null, 'enviado', null,
                array_merge($opts, ['via' => 'meta_template', 'template' => $nomeTemplate])
            );
            return true;

        } catch (Throwable $e) {
            if (class_exists('LogService')) {
                try { LogService::error("whatsapp template[$tipo/$nomeTemplate]: " . $e->getMessage()); } catch (Throwable $x) {}
            }
            self::log($tipo, $clienteId, $telefone, "[template:{$nomeTemplate}]",
                null, null, 'erro', $e->getMessage(),
                array_merge($opts, ['via' => 'meta_template'])
            );
            return $e;;
        }
    }

    // =========================================================================
    // MÉTODOS ESPECÍFICOS COM TEMPLATE HSM
    // =========================================================================

    /**
     * Pedido enviado — usa template (geralmente fora da janela).
     *
     * Template sugerido "pedido_enviado_sm":
     *   Corpo: "Olá, {{1}}! Seu pedido *{{2}}* foi enviado. Rastreio: {{3}}"
     *   Botão URL: "Rastrear" → https://sportmoto.com.br/pedido/{{1}}
     */
    public static function sendPedidoEnviadoHsm(array $cliente, array $pedido): bool
    {
        $nome     = self::primeiroNome($cliente);
        $codigo   = self::codigoPedido($pedido);
        $rastreio = $pedido['rastreio_codigo'] ?? 'Em breve';
        $urlSufixo = (string)($pedido['id'] ?? $pedido['codigo'] ?? '');

        $componentes = [MetaCloudService::body($nome, $codigo, $rastreio)];
        if ($urlSufixo) {
            $componentes[] = MetaCloudService::botaoUrl(0, $urlSufixo);
        }

        return self::sendTemplate(
            self::telefone($cliente),
            'pedido_enviado',
            'pedido_enviado_sm',    // ← troque pelo nome aprovado no Meta
            $componentes,
            'pt_BR',
            isset($cliente['id']) ? (int)$cliente['id'] : null,
            self::optsCliente($cliente)
        );
    }

    /**
     * Pedido entregue com solicitação de avaliação.
     *
     * Template sugerido "pedido_entregue_sm":
     *   Corpo: "Olá, {{1}}! Seu pedido {{2}} foi entregue. Que tal avaliar?"
     *   Botão URL: "Avaliar" → https://sportmoto.com.br/pedido/{{1}}
     */
    public static function sendPedidoEntregueHsm(array $cliente, array $pedido): bool
    {
        $nome      = self::primeiroNome($cliente);
        $codigo    = self::codigoPedido($pedido);
        $urlSufixo = (string)($pedido['id'] ?? '');

        $componentes = [MetaCloudService::body($nome, $codigo)];
        if ($urlSufixo) {
            $componentes[] = MetaCloudService::botaoUrl(0, $urlSufixo);
        }

        return self::sendTemplate(
            self::telefone($cliente),
            'pedido_entregue',
            'pedido_entregue_sm',   // ← troque pelo nome aprovado no Meta
            $componentes,
            'pt_BR',
            isset($cliente['id']) ? (int)$cliente['id'] : null,
            self::optsCliente($cliente)
        );
    }

    /**
     * Boleto vencendo (24h+ após criação — fora da janela).
     *
     * Template sugerido "boleto_pendente_sm":
     *   Corpo: "Olá, {{1}}! Seu boleto do pedido {{2}} vence em breve. Linha digitável: {{3}}"
     *   Botão URL: "Pagar boleto" → https://sportmoto.com.br/boleto/{{1}}
     */
    public static function sendBoletoPendenteHsm(array $cliente, array $pedido): bool
    {
        $nome  = self::primeiroNome($cliente);
        $codigo = self::codigoPedido($pedido);
        $linha  = $pedido['boleto_linha_digitavel'] ?? ($pedido['pagamento']['linha_digitavel'] ?? 'Ver link abaixo');
        $urlSuf = $pedido['codigo'] ?? (string)($pedido['id'] ?? '');

        $componentes = [MetaCloudService::body($nome, $codigo, $linha)];
        if ($urlSuf) {
            $componentes[] = MetaCloudService::botaoUrl(0, $urlSuf);
        }

        return self::sendTemplate(
            self::telefone($cliente),
            'boleto_pendente',
            'boleto_pendente_sm',   // ← troque pelo nome aprovado no Meta
            $componentes,
            'pt_BR',
            isset($cliente['id']) ? (int)$cliente['id'] : null,
            self::optsCliente($cliente)
        );
    }

    /**
     * Reengajamento com cupom (automação — sempre fora da janela).
     *
     * Template sugerido "reengajamento_cupom_sm":
     *   Corpo: "Olá, {{1}}! Temos saudades! Use o cupom {{2}} e ganhe desconto especial."
     *   Botão URL: "Ver ofertas" → https://sportmoto.com.br/
     */
    public static function sendReengajamento(array $cliente, string $cupom): bool
    {
        $nome = self::primeiroNome($cliente);

        return self::sendTemplate(
            self::telefone($cliente),
            'reengajamento',
            'reengajamento_cupom_sm', // ← troque pelo nome aprovado no Meta
            [MetaCloudService::body($nome, $cupom)],
            'pt_BR',
            isset($cliente['id']) ? (int)$cliente['id'] : null,
            self::optsCliente($cliente)
        );
    }

    /**
     * Carrinho abandonado (sempre fora da janela).
     *
     * Template sugerido "carrinho_abandonado_sm":
     *   Corpo: "Olá, {{1}}! Você esqueceu {{2}} item(ns) no carrinho. Total: {{3}}"
     *   Botão URL: "Ver carrinho" → https://sportmoto.com.br/carrinho
     */
    public static function sendCarrinhoAbandonado(array $cliente, array $carrinho): bool
    {
        $nome      = self::primeiroNome($cliente);
        $qtdItens  = (string)count($carrinho['itens'] ?? []);
        $total     = self::moeda($carrinho['total'] ?? null);

        return self::sendTemplate(
            self::telefone($cliente),
            'carrinho_abandonado',
            'carrinho_abandonado_sm', // ← troque pelo nome aprovado no Meta
            [MetaCloudService::body($nome, $qtdItens, $total)],
            'pt_BR',
            isset($cliente['id']) ? (int)$cliente['id'] : null,
            self::optsCliente($cliente)
        );
    }

    // =========================================================================
    // DIAGNÓSTICO
    // =========================================================================

    /**
     * Lista templates aprovados na Meta.
     * Use no terminal para descobrir os nomes exatos:
     *   php -r "require 'bootstrap.php'; print_r(WhatsappService::listarTemplatesMeta());"
     */
    public static function listarTemplatesMeta(?string $status = 'APPROVED'): array
    {
        try {
            $meta = new MetaCloudService();
            return $meta->listarTemplates($status);
        } catch (Throwable $e) {
            return ['erro' => $e->getMessage()];
        }
    }

    public static function testarMetaConexao(): array
    {
        try {
            return (new MetaCloudService())->testarConexao();
        } catch (Throwable $e) {
            return ['ok' => false, 'mensagem' => $e->getMessage()];
        }
    }


    
/**
 * PATCH — WhatsappService.php
 *
 * Adicione estes métodos dentro da classe WhatsappService,
 * antes do último "}" da classe.
 *
 * PRÉ-REQUISITOS:
 *   1. Crie os templates no Meta Business Manager com categoria AUTHENTICATION
 *   2. Aguarde aprovação (geralmente < 1h para AUTHENTICATION)
 *   3. Use os nomes exatos nos métodos abaixo
 *
 * NOMES SUGERIDOS para os templates no Meta:
 *   - codigo_verificacao_sm   (código de 2FA / verificação de login)
 *   - recuperar_senha_sm      (link/código para reset de senha)
 * 
 * // 2FA / verificação de login
* WhatsappService::sendCodigoVerificacao($cliente, '847291', 10);
*
* // Recuperação de senha
* $token = bin2hex(random_bytes(16));
* $url   = BASE_URL . '/senha/redefinir/' . $token;
* WhatsappService::sendRecuperarSenha($cliente, $token, 30, $url);
 *
 * COMPONENTES DO TEMPLATE NO META (ao criar):
 *
 * [codigo_verificacao_sm]
 *   Categoria: AUTHENTICATION
 *   Corpo: "{{1}} é seu código de verificação SportMoto. Válido por {{2}} minutos."
 *   Botão (opcional): "Copiar código" (tipo: COPY_CODE)
 *
 * [recuperar_senha_sm]
 *   Categoria: AUTHENTICATION
 *   Corpo: "Olá, {{1}}! Recebemos uma solicitação para redefinir sua senha SportMoto.
 *           Use o código {{2}} ou acesse o link para criar uma nova senha. Válido por {{3}} minutos."
 *   Botão URL (opcional): "Redefinir senha" → https://sportmoto.com.br/senha/{{1}}
 */

    // =========================================================================
    // AUTENTICAÇÃO — Templates HSM de categoria AUTHENTICATION
    // =========================================================================

    /**
     * Envia código de verificação (2FA, confirmação de login, etc.).
     *
     * @param array  $cliente   Array do cliente (celular, nome, id)
     * @param string $codigo    Código numérico gerado (ex: '847291')
     * @param int    $validadeMin Validade em minutos (padrão 10)
     *
     * Exemplo de uso:
     *   WhatsappService::sendCodigoVerificacao($cliente, '847291', 10);
     */
    public static function sendCodigoVerificacao(
        array  $cliente,
        string $codigo,
        int    $validadeMin = 5  // seu template tem "Expira em 5 minutos" fixo no footer
    ): bool {
        $telefone  = self::telefone($cliente);
        $clienteId = isset($cliente['id']) ? (int)$cliente['id'] : null;

        if ($telefone === '') {
            self::log('codigo_verificacao', $clienteId, '', $codigo, null, null, 'erro', 'telefone vazio');
            return false;
        }

        return self::sendTemplate(
            $telefone,
            'codigo_verificacao',
            'codigo_de_verificacao_2fa',  // ← nome exato do template aprovado
            [
                // Body: {{1}} = código, {{2}} = telefone de contato
                MetaCloudService::body($codigo, ConfigHelper::get('loja_whatsapp_avisos')),

                // Botão URL — sufixo dinâmico substitui {{1}} na URL do botão
                // A URL base é: https://www.whatsapp.com/otp/code/?...&code=otp
                // O sufixo é o próprio código
                MetaCloudService::botaoUrl(0, $codigo),
            ],
            'pt_BR',
            $clienteId,
            self::optsCliente($cliente)
        );
    }

    /**
     * Envia link/código de recuperação de senha.
     *
     * @param array  $cliente      Array do cliente (celular, nome, id)
     * @param string $token        Token ou código de reset
     * @param int    $validadeMin  Validade em minutos (padrão 30)
     * @param string $urlReset     URL completa de reset (com token já embutido)
     *
     * Exemplo de uso:
     *   $token = bin2hex(random_bytes(16));
     *   $url   = BASE_URL . '/senha/redefinir/' . $token;
     *   WhatsappService::sendRecuperarSenha($cliente, $token, 30, $url);
     */
    public static function sendRecuperarSenha(
        array  $cliente,
        string $token,
        int    $validadeMin = 30,
        string $urlReset = ''
    ): bool {
        $telefone  = self::telefone($cliente);
        $nome      = self::primeiroNome($cliente);
        $clienteId = isset($cliente['id']) ? (int)$cliente['id'] : null;

        if ($telefone === '') {
            self::log('recuperar_senha', $clienteId, '', '', null, null, 'erro', 'telefone vazio');
            return false;
        }

        $componentes = [
            MetaCloudService::body(
                $nome,
                $token,
                (string)$validadeMin
            ),
        ];

        // Botão URL de redefinição (sufixo dinâmico = token)
        // Só funciona se o template tiver botão URL configurado no Meta BM
        if ($urlReset !== '') {
            // O Meta espera apenas o SUFIXO da URL (o que vem depois da URL base do template)
            // Ex: se o template tem "https://sportmoto.com.br/senha/", passe só o token
            $componentes[] = MetaCloudService::botaoUrl(0, $token);
        }

        return self::sendTemplate(
            $telefone,
            'recuperar_senha',
            'recuperar_senha_auth',    // ← nome exato no Meta BM
            $componentes,
            'pt_BR',
            $clienteId,
            self::optsCliente($cliente)
        );
    }

}
