<?php
declare(strict_types=1);

/**
 * app/services/ConversionService.php
 *
 * Ledger de CONVERSÃO — exporta eventos pra Meta (CAPI) e Google.
 * NÃO confundir com TrackingService (event stream interno pra
 * automações). São coisas diferentes:
 *   - TrackingService → comportamento interno (régua de e-mail etc.)
 *   - ConversionService → exporta conversão pra plataformas de anúncio
 *
 * Só CAPTURA (grava em tracking_events com status pending). O
 * dispatcher (cron) é quem ENVIA. Separar captura de envio mantém
 * o request do cliente rápido.
 *
 * COOPERA com o TrackingService: reusa o MESMO cookie de visitante
 * (sm_vt, 32 hex) — assim um visitante é o mesmo nos dois sistemas,
 * sem fundir as classes. E reusa o ConsentService (Fase 0) pra
 * capturar o consentimento no momento do evento.
 *
 * NUNCA QUEBRA O FLUXO: try/catch engole erro e loga.
 */
final class ConversionService
{
    // MESMO cookie do TrackingService — visitante unificado
    private const COOKIE_VT = 'sm_vt';

    private PDO $db;
    private ConsentService $consent;
    private ?string $ultimoEventId = null;

    // Eventos (nomenclatura Meta/Google)
    public const VIEW_CONTENT      = 'ViewContent';
    public const ADD_TO_CART       = 'AddToCart';
    public const INITIATE_CHECKOUT = 'InitiateCheckout';
    public const PURCHASE          = 'Purchase';
    public const SEARCH            = 'Search';

    public const ADD_TO_WISHLIST = 'AddToWishlist';

    public function __construct()
    {
        $this->db      = Database::getInstance()->getConnection();
        $this->consent = new ConsentService();
    }

    // ══════════════════════════════════════════════════
    // API PÚBLICA — chamada pelos controllers
    // ══════════════════════════════════════════════════

    public function viewContent(array $produto, ?int $clienteId = null): void
    {
        $this->registrar(self::VIEW_CONTENT, [
            'content_type' => 'product',
            'content_ids'  => [(string)($produto['id'] ?? '')],
            'content_name' => $produto['nome'] ?? '',
            // 'content_category'=>'',
            'value'        => (float)($produto['preco'] ?? 0),
            'currency'     => 'BRL',
        ], $clienteId);
    }

    public function addToCart(array $item, ?int $clienteId = null): void
    {
        $this->registrar(self::ADD_TO_CART, [
            'content_type' => 'product',
            'content_ids'  => [(string)($item['produto_id'] ?? '')],
            'content_name' => $item['nome'] ?? '',
            'value'        => (float)($item['preco'] ?? 0) * (int)($item['quantidade'] ?? 1),
            'currency'     => 'BRL',
            'quantity'     => (int)($item['quantidade'] ?? 1),
        ], $clienteId);
    }

    public function initiateCheckout(array $carrinho, ?int $clienteId = null): void
    {
        $this->registrar(self::INITIATE_CHECKOUT, [
            'value'       => (float)($carrinho['total'] ?? 0),
            'currency'    => 'BRL',
            'num_items'   => (int)($carrinho['num_items'] ?? 0),
            'content_ids' => $carrinho['content_ids'] ?? [],
        ], $clienteId);
    }

    /**
     * Compra — a conversão. Valor vem do PEDIDO CONFIRMADO no
     * servidor, NUNCA do client. Chamado do AdminPedidoService/
     * checkout server-side.
     */
    public function purchase(array $pedido, ?int $clienteId = null): void
    {
        $this->registrar(self::PURCHASE, [
            'value'        => (float)($pedido['total'] ?? 0),
            'currency'     => 'BRL',
            'content_ids'  => $pedido['content_ids'] ?? [],
            'content_type' => 'product',
            'num_items'    => (int)($pedido['num_items'] ?? 0),
            'order_id'     => (string)($pedido['id'] ?? ''),
        ], $clienteId, $pedido['event_time'] ?? null);
    }

    public function search(string $termo, ?int $clienteId = null): void
    {
        $this->registrar(self::SEARCH, ['search_string' => $termo], $clienteId);
    }


    /**
     * Visita à HOME. ViewContent com content_type='home' — a Meta
     * trata como evento padrão (cria público, otimiza), e o
     * parâmetro distingue de visualização de produto.
     */
    public function viewHome(?int $clienteId = null): void
    {
        $this->registrar(self::VIEW_CONTENT, [
            'content_type' => 'home',
            'content_name' => 'Home',
        ], $clienteId);
    }

    /**
     * Visita a CATEGORIA. ViewContent com content_type=
     * 'product_group' + id/nome da categoria. Permite público
     * "quem viu a categoria X".
     */
    public function viewCategory(array $categoria, ?int $clienteId = null): void
    {
        $this->registrar(self::VIEW_CONTENT, [
            'content_type' => 'product_group',
            'content_ids'  => [(string)($categoria['id'] ?? '')],
            'content_name' => $categoria['nome'] ?? '',
        ], $clienteId);
    }

    /**
     * AddToWishlist — evento PADRÃO da Meta (otimização e público
     * prontos, entende nativamente).
     */
    public function addToWishlist(array $produto, ?int $clienteId = null): void
    {
        $this->registrar(self::ADD_TO_WISHLIST, [
            'content_type' => 'product',
            'content_ids'  => [(string)($produto['id'] ?? $produto['produto_id'] ?? '')],
            'content_name' => $produto['nome'] ?? '',
            'value'        => (float)($produto['preco'] ?? 0),
            'currency'     => 'BRL',
        ], $clienteId);
    }

    /** event_id do último evento — o front passa ao pixel p/ dedup. */
    public function getUltimoEventId(): ?string
    {
        return $this->ultimoEventId;
    }

    // ══════════════════════════════════════════════════
    // NÚCLEO
    // ══════════════════════════════════════════════════

    private function registrar(
        string $eventName,
        array $payload,
        ?int $clienteId = null,
        ?string $eventTime = null
    ): void {
        try {
            $estado    = $this->consent->estadoAtual();
            $analytics = $estado ? (int)($estado['analytics'] ?? 0) : 0;
            $marketing = $estado ? (int)($estado['marketing'] ?? 0) : 0;

            // Contexto p/ match quality do CAPI (IP e UA vão CRUS)
            $payload['_context'] = [
                'url'        => $this->urlAtual(),
                'ip'         => SecurityHelper::clientIp(),
                'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ];

            $eventId = $this->uuidv4();
            $this->ultimoEventId = $eventId;

            $this->db->prepare(
                "INSERT INTO tracking_events
                 (event_id, event_name, event_time, cliente_id, visitante_token,
                  consent_analytics, consent_marketing, payload, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            )->execute([
                $eventId,
                $eventName,
                $eventTime ?? date('Y-m-d H:i:s'),
                $clienteId ?? $this->clienteId(),
                $this->visitanteToken(),
                $analytics,
                $marketing,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            error_log('[Conversion] falha ao gravar evento: ' . $e->getMessage());
        }
    }

    /** Lê o MESMO cookie sm_vt do TrackingService (32 hex). */
    private function visitanteToken(): ?string
    {
        $t = $_COOKIE[self::COOKIE_VT] ?? '';
        return preg_match('/^[a-f0-9]{32}$/', $t) ? $t : null;
    }

    /** Mesmo padrão do TrackingService pra pegar cliente_id. */
    private function clienteId(): ?int
    {
        try {
            if (class_exists('Session')) {
                $id = (int)(Session::get('cliente_id') ?? 0);
                return $id > 0 ? $id : null;
            }
        } catch (\Throwable $e) {}
        return null;
    }

    private function urlAtual(): string
    {
        $scheme = SecurityHelper::isHttps() ? 'https' : 'http';
        return mb_substr($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '')
             . ($_SERVER['REQUEST_URI'] ?? ''), 0, 1000);
    }

    private function uuidv4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}