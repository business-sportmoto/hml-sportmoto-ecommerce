<?php
/**
 * app/services/TrackingService.php
 *
 * Event stream unificado — Fase 0 da Automação v2.
 *
 * REGISTRO (1 linha em qualquer ponto do site):
 *   TrackingService::registrar('produto_visto', 'produto', $produtoId);
 *   TrackingService::registrar('busca', null, null, ['q' => $termo, 'resultados' => $total]);
 *   TrackingService::registrar('banner_click', 'banner', $bannerId);
 *   TrackingService::pagina('politica-trocas');   // atalho p/ páginas nomeadas
 *
 * IDENTIDADE:
 *   - Cookie sm_vt (1 ano) identifica o navegador (anônimo ou não)
 *   - cliente_id vem da sessão quando logado
 *   - No login, chame TrackingService::vincularCliente($clienteId) para
 *     "reivindicar" o histórico anônimo do token (stitching)
 *
 * GARANTIAS:
 *   - NUNCA lança exceção (tracking não pode derrubar página)
 *   - Dedup por tipo (refresh de página não vira 10 eventos)
 *   - Evento sessao_iniciada registrado 1x por sessão com UTM/referer/device
 *
 * LEITURA (usada pelas condições da automação — Fase 1):
 *   TrackingService::contarEventos($clienteId, 'produto_visto', 7, 'produto', $id);
 *   TrackingService::contextoSessao();   // utm/device da sessão corrente
 */
class TrackingService
{
    /** Nome do cookie do visitante */
    private const COOKIE = 'sm_vt';

    /** Validade do cookie em segundos (1 ano) */
    private const COOKIE_TTL = 31536000;

    /**
     * Janela de dedup (segundos) por tipo de evento.
     * 0 = sem dedup (todo evento conta).
     * Tipos não listados usam DEDUP_PADRAO.
     */
    private const DEDUP = [
        'produto_visto'       => 1800,  // 30 min — refresh não conta de novo
        'categoria_vista'     => 1800,
        'catalogo_moto_visto' => 1800,
        'pagina_vista'        => 600,   // 10 min
        'busca'               => 0,     // toda busca importa
        'banner_click'        => 5,     // anti double-click
        'banner_visto'        => 3600,
        'sessao_iniciada'     => 0,     // controlada por flag de sessão, não por janela
    ];
    private const DEDUP_PADRAO = 60;

    /** Tipos permitidos vindos do endpoint client-side (AJAX) */
    public const TIPOS_CLIENT_SIDE = ['banner_click', 'banner_visto'];

    // =========================================================================
    // REGISTRO
    // =========================================================================

    /**
     * Registra um evento no stream. Nunca lança exceção.
     *
     * @param string      $tipo         ex: 'produto_visto'
     * @param string|null $entidadeTipo ex: 'produto' | 'categoria' | 'banner' | 'pagina'
     * @param int|null    $entidadeId
     * @param array       $contexto     dados extras do evento
     * @return int|null   id do evento, ou null (dedup/erro)
     */
    public static function registrar(
        string  $tipo,
        ?string $entidadeTipo = null,
        ?int    $entidadeId = null,
        array   $contexto = []
    ): ?int {
        try {
            // Workers/CLI não geram navegação (contornável em teste via constante)
            if (PHP_SAPI === 'cli' && !defined('TRACKING_PERMITIR_CLI')) return null;

            $tipo = mb_substr(trim($tipo), 0, 40);
            if ($tipo === '') return null;

            $token     = self::visitanteToken();
            $clienteId = self::clienteId();
            $sessaoId  = self::sessaoId();

            // Garante o evento de abertura de sessão antes do primeiro evento real
            if ($tipo !== 'sessao_iniciada') {
                self::garantirSessaoIniciada($token, $clienteId, $sessaoId);
            }

            // Dedup
            $janela = self::DEDUP[$tipo] ?? self::DEDUP_PADRAO;
            if ($janela > 0 && self::duplicadoRecente($token, $tipo, $entidadeTipo, $entidadeId, $janela)) {
                return null;
            }

            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "INSERT INTO eventos
                 (visitante_token, cliente_id, sessao_id, tipo, entidade_tipo, entidade_id, contexto_json)
                 VALUES (:tok, :cid, :sid, :tipo, :etipo, :eid, :ctx)"
            );
            $st->execute([
                ':tok'   => $token,
                ':cid'   => $clienteId,
                ':sid'   => $sessaoId,
                ':tipo'  => $tipo,
                ':etipo' => $entidadeTipo ? mb_substr($entidadeTipo, 0, 30) : null,
                ':eid'   => $entidadeId,
                ':ctx'   => $contexto ? json_encode($contexto, JSON_UNESCAPED_UNICODE) : null,
            ]);
            return (int)$db->lastInsertId() ?: null;

        } catch (Throwable $e) {
            // Tracking jamais quebra a página
            if (class_exists('LogService')) {
                try { LogService::warning('tracking falhou', ['tipo' => $tipo, 'erro' => $e->getMessage()]); } catch (Throwable $x) {}
            }
            return null;
        }
    }

    /**
     * Atalho para páginas nomeadas (institucionais, políticas etc.).
     *   TrackingService::pagina('politica-trocas');
     */
    public static function pagina(string $slug, array $contexto = []): ?int
    {
        $contexto['slug'] = $slug;
        return self::registrar('pagina_vista', 'pagina', null, $contexto);
    }

    /**
     * Vincula o histórico anônimo do token ao cliente (stitching).
     * CHAMAR NO LOGIN, logo após autenticar:
     *   TrackingService::vincularCliente($clienteId);
     */
    public static function vincularCliente(int $clienteId): int
    {
        try {
            if ($clienteId <= 0) return 0;
            $token = $_COOKIE[self::COOKIE] ?? '';
            if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) return 0;

            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "UPDATE eventos SET cliente_id = :cid
                 WHERE visitante_token = :tok AND cliente_id IS NULL"
            );
            $st->execute([':cid' => $clienteId, ':tok' => $token]);
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // =========================================================================
    // LEITURA (consumida pelas condições da automação — Fase 1)
    // =========================================================================

    /**
     * Conta eventos de um cliente num período.
     *   contarEventos($cid, 'produto_visto', 7)                     → visitas de produto em 7d
     *   contarEventos($cid, 'produto_visto', 7, 'produto', 123)     → visitas ao produto 123
     */
    public static function contarEventos(
        int     $clienteId,
        string  $tipo,
        int     $dias,
        ?string $entidadeTipo = null,
        ?int    $entidadeId = null
    ): int {
        try {
            $db = Database::getInstance()->getConnection();
            $sql = "SELECT COUNT(*) FROM eventos
                    WHERE cliente_id = :cid AND tipo = :tipo
                      AND criado_em > DATE_SUB(NOW(), INTERVAL :dias DAY)";
            $params = [':cid' => $clienteId, ':tipo' => $tipo, ':dias' => $dias];

            if ($entidadeTipo !== null) {
                $sql .= " AND entidade_tipo = :etipo";
                $params[':etipo'] = $entidadeTipo;
            }
            if ($entidadeId !== null) {
                $sql .= " AND entidade_id = :eid";
                $params[':eid'] = $entidadeId;
            }

            $st = $db->prepare($sql);
            foreach ($params as $k => $v) {
                $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $st->execute();
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Contexto da sessão corrente (utm, referer, device) — lido do evento
     * sessao_iniciada. Retorna [] se não houver.
     */
    public static function contextoSessao(): array
    {
        try {
            $sessaoId = self::sessaoId();
            if (!$sessaoId) return [];

            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT contexto_json FROM eventos
                 WHERE sessao_id = :sid AND tipo = 'sessao_iniciada'
                 ORDER BY id DESC LIMIT 1"
            );
            $st->execute([':sid' => $sessaoId]);
            $json = $st->fetchColumn();
            if (!$json) return [];
            $ctx = json_decode($json, true);
            return is_array($ctx) ? $ctx : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Token do visitante atual (para uso externo — ex: fila de automação
     * de visitantes anônimos).
     */
    public static function tokenAtual(): ?string
    {
        $t = $_COOKIE[self::COOKIE] ?? '';
        return preg_match('/^[a-f0-9]{32}$/', $t) ? $t : null;
    }

    // =========================================================================
    // INTERNOS
    // =========================================================================

    /** Resolve (ou cria) o token do visitante via cookie de 1 ano. */
    private static function visitanteToken(): string
    {
        $t = $_COOKIE[self::COOKIE] ?? '';
        if (preg_match('/^[a-f0-9]{32}$/', $t)) return $t;

        $t = bin2hex(random_bytes(16));
        // Define o cookie (se os headers ainda não saíram)
        if (!headers_sent()) {
            setcookie(self::COOKIE, $t, [
                'expires'  => time() + self::COOKIE_TTL,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE] = $t; // disponível já nesta request
        return $t;
    }

    private static function clienteId(): ?int
    {
        try {
            if (class_exists('Session')) {
                $id = (int)(Session::get('cliente_id') ?? 0);
                return $id > 0 ? $id : null;
            }
        } catch (Throwable $e) {}
        $id = (int)($_SESSION['cliente_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private static function sessaoId(): ?string
    {
        $sid = session_id();
        return $sid !== '' ? mb_substr($sid, 0, 64) : null;
    }

    /**
     * Registra 'sessao_iniciada' uma única vez por sessão, com UTM, referer
     * e device. Flag em $_SESSION evita SELECT por request.
     */
    private static function garantirSessaoIniciada(string $token, ?int $clienteId, ?string $sessaoId): void
    {
        if (!empty($_SESSION['_trk_sessao_ok'])) return;
        $_SESSION['_trk_sessao_ok'] = 1;

        $ctx = [
            'device'  => self::detectarDevice(),
            'referer' => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 300) ?: null,
        ];
        foreach (['utm_source','utm_medium','utm_campaign','utm_content','utm_term'] as $u) {
            if (!empty($_GET[$u])) $ctx[$u] = mb_substr((string)$_GET[$u], 0, 120);
        }
        $ctx = array_filter($ctx, fn($v) => $v !== null);

        try {
            $db = Database::getInstance()->getConnection();
            $db->prepare(
                "INSERT INTO eventos
                 (visitante_token, cliente_id, sessao_id, tipo, contexto_json)
                 VALUES (:tok, :cid, :sid, 'sessao_iniciada', :ctx)"
            )->execute([
                ':tok' => $token,
                ':cid' => $clienteId,
                ':sid' => $sessaoId,
                ':ctx' => json_encode($ctx, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {}
    }

    private static function detectarDevice(): string
    {
        $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '') return 'desconhecido';
        if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false
            || strpos($ua, 'iphone') !== false) {
            return 'mobile';
        }
        if (strpos($ua, 'ipad') !== false || strpos($ua, 'tablet') !== false) {
            return 'tablet';
        }
        return 'desktop';
    }

    /** Já existe evento igual dentro da janela? (dedup) */
    private static function duplicadoRecente(
        string $token, string $tipo, ?string $etipo, ?int $eid, int $janelaSeg
    ): bool {
        try {
            $db = Database::getInstance()->getConnection();
            $sql = "SELECT 1 FROM eventos
                    WHERE visitante_token = :tok AND tipo = :tipo
                      AND criado_em > DATE_SUB(NOW(), INTERVAL :seg SECOND)";
            $params = [':tok' => $token, ':tipo' => $tipo, ':seg' => $janelaSeg];

            if ($etipo !== null) { $sql .= " AND entidade_tipo = :et"; $params[':et'] = $etipo; }
            else                 { $sql .= " AND entidade_tipo IS NULL"; }

            if ($eid !== null)   { $sql .= " AND entidade_id = :ei"; $params[':ei'] = $eid; }
            else                 { $sql .= " AND entidade_id IS NULL"; }

            $sql .= " LIMIT 1";
            $st = $db->prepare($sql);
            foreach ($params as $k => $v) {
                $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $st->execute();
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false; // na dúvida, registra
        }
    }
}
