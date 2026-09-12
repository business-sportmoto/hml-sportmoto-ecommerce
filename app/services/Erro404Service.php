<?php
/**
 * app/services/Erro404Service.php
 *
 * Registra todo endereço quebrado da loja e, antes disso, dá a chance de o
 * mapa de redirecionamentos resolver.
 *
 * Ponto de entrada único: `Erro404Service::interceptar()`, chamado pelo
 * `Router::notFound()` (nenhuma rota casou) e por `Controller::naoEncontrado()`
 * (a rota casou, mas o slug não existe). Antes disso o 404 acontecia em
 * silêncio em dez lugares diferentes.
 *
 * Nada aqui pode derrubar a página: a falha é engolida e vai para o
 * LogService. Um erro no rastreador não pode virar erro para o visitante.
 */
class Erro404Service
{
    /** Uma linha por acesso só para visita de gente — ver registrar(). */
    private const GUARDA_ACESSO_DE_ROBO = false;

    /** Robô declarado no user agent. */
    private const ROBOS = '/(bot|crawl|spider|slurp|bingpreview|facebookexternalhit|'
                        . 'whatsapp|telegram|discord|curl|wget|python-requests|http_?client|'
                        . 'headless|semrush|ahrefs|mj12|dotbot|petalbot|yandex|baiduspider|'
                        . 'gptbot|claudebot|ccbot|applebot|archive\.org_bot)/i';

    /** Varredura: ninguém digitou isso, é robô procurando falha. */
    private const RUIDO = '#(^/(wp-|xmlrpc|phpmyadmin|pma|adminer|vendor/|\.env|\.git|'
                        . 'cgi-bin|owa|autodiscover|boaform|hnap1))|'
                        . '(\.(php[0-9]?|asp|aspx|jsp|cgi|env|sql|bak|old|ini|yml|log)$)#i';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?: Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════════════════
    // O ponto de entrada
    // ════════════════════════════════════════════════════════════════

    /**
     * Resolve ou registra o endereço atual.
     *
     * @return int Código HTTP que o chamador deve responder:
     *             404 (não há regra) ou 410 (a regra diz "acabou").
     *             Em 301/302 esta função NÃO retorna: manda o header e sai.
     */
    public static function interceptar(): int
    {
        $caminho = self::caminhoAtual();

        try {
            $redir = new RedirecionamentoService();
            $regra = $redir->resolver($caminho);

            if ($regra) {
                $redir->registrarAcesso((int) $regra['id']);

                if ((int) $regra['tipo'] === 410) {
                    return 410;
                }

                header('Location: ' . self::destinoFinal((string) $regra['destino']),
                       true, (int) $regra['tipo']);
                exit;
            }

            (new self())->registrar($caminho);
        } catch (Throwable $e) {
            // Rastrear não pode quebrar a loja.
            if (class_exists('LogService')) {
                LogService::exception($e, 'error', 'seo', ['caminho' => $caminho]);
            }
        }

        return 404;
    }

    /**
     * Caminho pedido, na mesma forma que o Router enxerga (sem query, sem
     * barra final, sem o subdiretório da instalação).
     */
    public static function caminhoAtual(): string
    {
        $uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

        $base = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
        if ($base !== '' && strpos($uri, $base) === 0) {
            $uri = substr($uri, strlen($base));
        }

        return RedirecionamentoService::normalizar($uri);
    }

    /** Caminho interno vira URL absoluta; a query original é preservada. */
    private static function destinoFinal(string $destino): string
    {
        $destino = trim($destino);
        $query   = self::queryDoVisitante();

        if (!preg_match('#^https?://#i', $destino)) {
            $destino = rtrim(BASE_URL, '/') . '/' . ltrim($destino, '/');
        }

        // Só acrescenta a query se o destino não trouxer a sua — quem
        // escreveu o destino com ?x=1 decidiu o que queria.
        if ($query !== '' && strpos($destino, '?') === false) {
            $destino .= '?' . $query;
        }

        return $destino;
    }

    /**
     * A query que o VISITANTE digitou, sem o `route`.
     *
     * O .htaccess manda tudo que não é arquivo para `index.php?route=$1`, então
     * `QUERY_STRING` sempre traz esse parâmetro interno. Copiá-lo para o
     * destino mandava o visitante para `/produtos?route=pagina-velha` — feio na
     * barra de endereço e confuso para quem compartilha o link. As UTMs, o
     * gclid e o resto seguem junto, que é o que interessa para a campanha.
     */
    private static function queryDoVisitante(): string
    {
        $bruta = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($bruta === '') return '';

        parse_str($bruta, $partes);
        unset($partes['route']);

        return $partes ? http_build_query($partes) : '';
    }

    // ════════════════════════════════════════════════════════════════
    // Registro
    // ════════════════════════════════════════════════════════════════

    /**
     * Uma linha por ENDEREÇO (contador), mais uma linha por ACESSO quando a
     * visita é de gente.
     *
     * O contador usa INSERT … ON DUPLICATE KEY UPDATE com alias de linha
     * (MySQL 8.4; `VALUES()` está obsoleto desde o 8.0.20).
     */
    public function registrar(string $caminho): void
    {
        $caminho = RedirecionamentoService::normalizar($caminho);
        if ($caminho === '' || $caminho === '/') return;

        $ua      = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8');
        $referer = mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500, 'UTF-8');
        $query   = mb_substr(self::queryDoVisitante(), 0, 255, 'UTF-8');
        $robo    = self::ehRobo($ua) ? 1 : 0;
        $ruido   = self::ehRuido($caminho) ? 1 : 0;
        $origem  = self::classificarOrigem($referer);
        $ip      = class_exists('SecurityHelper') ? SecurityHelper::clientIp() : ($_SERVER['REMOTE_ADDR'] ?? '');

        $this->db->prepare(
            "INSERT INTO url_404
               (caminho, caminho_hash, query_exemplo, ocorrencias,
                visitas_humano, visitas_robo, ultima_origem, ultimo_referer,
                ultimo_ip, ultimo_user_agent, eh_robo, ruido, status)
             VALUES (?,?,?,1,?,?,?,?,?,?,?,?, 'novo') AS novo
             ON DUPLICATE KEY UPDATE
                ocorrencias       = url_404.ocorrencias + 1,
                visitas_humano    = url_404.visitas_humano + novo.visitas_humano,
                visitas_robo      = url_404.visitas_robo   + novo.visitas_robo,
                ultima_vez        = NOW(),
                ultima_origem     = novo.ultima_origem,
                ultimo_referer    = novo.ultimo_referer,
                ultimo_ip         = novo.ultimo_ip,
                ultimo_user_agent = novo.ultimo_user_agent,
                eh_robo           = novo.eh_robo,
                query_exemplo     = COALESCE(NULLIF(novo.query_exemplo, ''), url_404.query_exemplo),
                id                = LAST_INSERT_ID(url_404.id)"
        )->execute([
            $caminho, sha1($caminho), $query !== '' ? $query : null,
            $robo ? 0 : 1, $robo ? 1 : 0,
            $origem, $referer !== '' ? $referer : null,
            $ip !== '' ? $ip : null, $ua !== '' ? $ua : null,
            $robo, $ruido,
        ]);

        $urlId = (int) $this->db->lastInsertId();
        if ($urlId <= 0) return;

        // Varredura de robô não vira linha de acesso: são milhares por dia e
        // o contador já registra que aconteceu.
        if ($robo && !self::GUARDA_ACESSO_DE_ROBO) return;
        if ($ruido) return;

        $this->db->prepare(
            "INSERT INTO url_404_acessos (url_404_id, origem, referer, ip, user_agent, eh_robo)
             VALUES (?,?,?,?,?,?)"
        )->execute([
            $urlId, $origem, $referer !== '' ? $referer : null,
            $ip !== '' ? $ip : null, $ua !== '' ? $ua : null, $robo,
        ]);
    }

    // ── Classificação ─────────────────────────────────────────────

    public static function ehRobo(string $userAgent): bool
    {
        return $userAgent === '' || (bool) preg_match(self::ROBOS, $userAgent);
    }

    public static function ehRuido(string $caminho): bool
    {
        return (bool) preg_match(self::RUIDO, $caminho);
    }

    /**
     * De onde veio o clique. É o que responde "de onde está vindo":
     * `interno` aponta uma página NOSSA com link errado — corrige-se a fonte.
     */
    public static function classificarOrigem(string $referer): string
    {
        if ($referer === '') return 'direto';

        $host = mb_strtolower((string) (parse_url($referer, PHP_URL_HOST) ?? ''), 'UTF-8');
        if ($host === '') return 'direto';

        $nosso = mb_strtolower((string) (parse_url(BASE_URL, PHP_URL_HOST) ?? ''), 'UTF-8');
        if ($nosso !== '' && ($host === $nosso || str_ends_with($host, '.' . $nosso))) {
            return 'interno';
        }

        if (preg_match('/(google|bing|duckduckgo|yahoo|yandex|ecosia|brave)\./i', $host)) {
            return 'busca';
        }

        return 'externo';
    }

    // ════════════════════════════════════════════════════════════════
    // Leitura (tela do painel)
    // ════════════════════════════════════════════════════════════════

    /**
     * @param array $f ['busca','status','origem','robos' => 0|1,'ruido' => 0|1]
     */
    public function listar(array $f = [], int $pagina = 1, int $porPagina = 30): array
    {
        [$where, $params] = $this->filtros($f);
        $offset = max(0, ($pagina - 1) * $porPagina);

        $stmt = $this->db->prepare(
            "SELECT u.*, r.destino AS redir_destino, r.tipo AS redir_tipo
               FROM url_404 u
          LEFT JOIN redirecionamentos r ON r.id = u.redirecionamento_id
              {$where}
           ORDER BY u.ocorrencias DESC, u.ultima_vez DESC
              LIMIT ? OFFSET ?"
        );
        foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v);
        $stmt->bindValue(count($params) + 1, $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function contar(array $f = []): int
    {
        [$where, $params] = $this->filtros($f);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM url_404 u {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function filtros(array $f): array
    {
        // Por padrão a tela mostra o que interessa: sem varredura de robô.
        $where  = [];
        $params = [];

        if (empty($f['ruido'])) $where[] = 'u.ruido = 0';
        if (empty($f['robos'])) $where[] = 'u.visitas_humano > 0';

        if (!empty($f['busca'])) {
            $where[]  = 'u.caminho LIKE ?';
            $params[] = '%' . $f['busca'] . '%';
        }
        if (!empty($f['status'])) {
            $where[]  = 'u.status = ?';
            $params[] = (string) $f['status'];
        }
        if (!empty($f['origem'])) {
            $where[]  = 'u.ultima_origem = ?';
            $params[] = (string) $f['origem'];
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    public function porId(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM url_404 WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Os últimos acessos daquele endereço — data, origem, IP, user agent. */
    public function acessos(int $urlId, int $limite = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT criado_em, origem, referer, ip, user_agent, eh_robo
               FROM url_404_acessos
              WHERE url_404_id = ?
           ORDER BY criado_em DESC
              LIMIT ?"
        );
        $stmt->bindValue(1, $urlId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function resumo(): array
    {
        $sql = "SELECT
                    COUNT(*)                                                      AS enderecos,
                    COALESCE(SUM(ocorrencias), 0)                                 AS acessos,
                    COALESCE(SUM(CASE WHEN status = 'novo' AND ruido = 0
                                       AND visitas_humano > 0 THEN 1 ELSE 0 END), 0) AS a_resolver,
                    COALESCE(SUM(CASE WHEN ultima_origem = 'interno' THEN 1 ELSE 0 END), 0) AS internos,
                    COALESCE(SUM(CASE WHEN ultima_origem = 'busca'   THEN 1 ELSE 0 END), 0) AS de_busca
                  FROM url_404";
        return $this->db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function marcarStatus(int $id, string $status, ?int $redirecionamentoId = null): array
    {
        if (!in_array($status, ['novo', 'ignorado', 'resolvido'], true)) {
            return ['ok' => false, 'msg' => 'Status inválido.'];
        }

        $this->db->prepare(
            "UPDATE url_404 SET status = ?, redirecionamento_id = ? WHERE id = ?"
        )->execute([$status, $redirecionamentoId, $id]);

        return ['ok' => true];
    }

    /**
     * Descarte do detalhe (o IP mora aqui). A contagem em `url_404` fica —
     * é ela que sustenta o histórico.
     */
    public function purgarAcessos(int $dias = 90): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM url_404_acessos WHERE criado_em < (NOW() - INTERVAL ? DAY)"
        );
        $stmt->bindValue(1, max(1, $dias), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
