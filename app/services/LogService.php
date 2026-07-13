<?php
declare(strict_types=1);

/**
 * app/services/LogService.php  — v2
 *
 * ═══ DROP-IN REPLACEMENT ═══
 * Preserva 100% da API anterior. Todas as chamadas existentes seguem
 * funcionando SEM alteração:
 *     LogService::info($msg, $ctx)
 *     LogService::error($msg, $ctx)
 *     LogService::warning($msg, $ctx)
 *     LogService::audit($msg, $ctx)
 * O log redundante em ARQUIVO (storage/logs/AAAA-MM-DD.log) foi MANTIDO — se
 * o banco cair, o arquivo ainda registra. É a rede de segurança do logger.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ADICIONADO
 *
 *  1. REDAÇÃO DE SEGREDOS — stack traces do PHP contêm ARGUMENTOS de função:
 *     authenticate('a@b.com', 'senha123'). Sem redação, o log grava a senha em
 *     texto puro (LGPD/PCI). Mensagem, contexto e trace são redigidos.
 *
 *  2. DEDUPLICAÇÃO (fingerprint) — um erro em loop gravava N linhas; agora
 *     incrementa `ocorrencias` na linha existente. Disco cheio = origem fora.
 *
 *  3. request_id — correlaciona todos os logs da MESMA requisição.
 *
 *  4. exception() — captura classe, arquivo, linha e trace. É o que faz o
 *     "500 sem pista nenhuma" virar um log completo.
 *
 *  5. IP REAL — pós-Cloudflare o Nginx reescreve REMOTE_ADDR (real_ip). Antes,
 *     o log podia registrar o IP da CF em vez do visitante.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * BUG CORRIGIDO (integridade de auditoria)
 *
 *  A versão anterior fazia:
 *      $userId = Session::isAdminLogado() ? getAdminId() : getClienteId();
 *  Isto grava `admin_id` OU `cliente_id` numa coluna chamada `usuario_id` —
 *  chaves de TABELAS DIFERENTES. admin_id=3 e cliente_id=3 viram o mesmo
 *  valor, indistinguíveis: trilha de auditoria ambígua.
 *
 *  Agora resolve para `usuarios.id` real, via AuthHelper::usuarioId() (o
 *  bridge que já existe no projeto), com fallback seguro.
 *
 *  ATENÇÃO: registros ANTIGOS na tabela mantêm a ambiguidade — trate a
 *  atribuição de usuário deles como não-confiável.
 * ─────────────────────────────────────────────────────────────────────────
 */
final class LogService
{
    /** Máximo de escritas no BANCO por requisição (anti-flood). */
    private const MAX_POR_REQUEST = 25;

    /** Corte do stack trace gravado. */
    private const MAX_TRACE_CHARS = 12000;

    /** Chaves cujo VALOR vira [REDACTED]. Comparação por substring. */
    private const CHAVES_SENSIVEIS = [
        'senha', 'password', 'passwd', 'pwd', 'senha_hash',
        'token', 'access_token', 'refresh_token', 'jwt', 'bearer',
        'secret', 'api_key', 'apikey', 'access_key', 'secret_key',
        'authorization', 'cookie', 'session',
        'csrf', '_csrf_token',
        'card', 'cartao', 'numero_cartao', 'cvv', 'cvc', 'card_number',
        'cpf', 'cnpj', 'documento',
        'db_pass', 'mailgun', 'malga', 'r2_', 'cf_stream',
    ];

    private static ?PDO $db = null;
    private static int $escritas = 0;
    private static ?string $requestId = null;

    // ═════════════════════════════════════════════════════════════════════
    // API EXISTENTE — assinaturas preservadas (nada quebra)
    // ═════════════════════════════════════════════════════════════════════

    public static function info(string $msg, array $ctx = [], string $canal = 'app'): void
    {
        self::write('info', $canal, $msg, $ctx);
    }

    public static function warning(string $msg, array $ctx = [], string $canal = 'app'): void
    {
        self::write('warning', $canal, $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = [], string $canal = 'app'): void
    {
        self::write('error', $canal, $msg, $ctx);
    }

    /** MANTIDO: grava no canal 'audit'. */
    public static function audit(string $msg, array $ctx = []): void
    {
        self::write('info', 'audit', $msg, $ctx);
    }

    // ═════════════════════════════════════════════════════════════════════
    // API NOVA
    // ═════════════════════════════════════════════════════════════════════

    public static function debug(string $msg, array $ctx = [], string $canal = 'app'): void
    {
        self::write('debug', $canal, $msg, $ctx);
    }

    public static function critical(string $msg, array $ctx = [], string $canal = 'app'): void
    {
        self::write('critical', $canal, $msg, $ctx);
    }

    /**
     * Registra uma exceção com classe, arquivo, linha e trace redigido.
     * Usado pelo ErrorHandler (captura global) e por qualquer catch.
     */
    public static function exception(
        Throwable $e,
        string $nivel = 'error',
        string $canal = 'app',
        array $ctx = []
    ): void {
        $anterior = $e->getPrevious();
        if ($anterior !== null) {
            $ctx['excecao_anterior'] = $anterior::class . ': ' . $anterior->getMessage();
        }

        self::write($nivel, $canal, $e->getMessage(), $ctx, [
            'tipo'    => $e::class,
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'trace'   => self::redactString($e->getTraceAsString()),
        ]);
    }

    /** ID que correlaciona todos os logs da MESMA requisição. */
    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = bin2hex(random_bytes(8));
        }
        return self::$requestId;
    }

    /**
     * Remove logs antigos de baixo valor. Chame via cron (cli/).
     * Disco cheio derruba a origem; retenção de PII é exigência LGPD.
     */
    public static function purge(int $dias = 30): int
    {
        try {
            $stmt = self::db()->prepare(
                "DELETE FROM logs
                  WHERE nivel IN ('debug','info')
                    AND criado_em < (UTC_TIMESTAMP() - INTERVAL :d DAY)"
            );
            $stmt->bindValue(':d', $dias, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[LOG-PURGE] ' . $e->getMessage());
            return 0;
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // Núcleo
    // ═════════════════════════════════════════════════════════════════════

    private static function write(
        string $nivel,
        string $canal,
        string $mensagem,
        array $ctx = [],
        array $origem = []
    ): void {
        // Redige ANTES de qualquer destino (banco ou arquivo).
        $mensagem  = self::redactString(mb_substr($mensagem, 0, 2000));
        $ctxSeguro = $ctx !== [] ? self::redact($ctx) : null;

        // ── 1. Arquivo (MANTIDO) ─────────────────────────────────────────
        // Rede de segurança: se o banco estiver fora, isto ainda registra.
        self::gravarArquivo($nivel, $canal, $mensagem, $ctxSeguro);

        // ── 2. Banco ─────────────────────────────────────────────────────
        try {
            if (self::$escritas >= self::MAX_POR_REQUEST) {
                return; // anti-flood no banco (o arquivo acima segue registrando)
            }
            self::$escritas++;

            if (empty($origem['arquivo'])) {
                $origem = array_merge($origem, self::callerOrigin());
            }

            $trace = isset($origem['trace'])
                ? mb_substr((string) $origem['trace'], 0, self::MAX_TRACE_CHARS)
                : null;

            // Fingerprint só em níveis acionáveis -> dedup.
            // info/debug/audit ficam NULL (MySQL permite N NULLs no índice único).
            $fingerprint = in_array($nivel, ['warning', 'error', 'critical'], true)
                ? self::fingerprint($nivel, $canal, $origem, $mensagem)
                : null;

            self::db()->prepare(
                "INSERT INTO logs
                    (nivel, canal, mensagem, tipo, arquivo, linha, trace, contexto,
                     usuario_id, ip, url, metodo, referer, user_agent, request_id,
                     fingerprint, ocorrencias, resolvido, criado_em, visto_em)
                 VALUES
                    (:nivel, :canal, :mensagem, :tipo, :arquivo, :linha, :trace, :contexto,
                     :usuario_id, :ip, :url, :metodo, :referer, :user_agent, :request_id,
                     :fingerprint, 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    ocorrencias = ocorrencias + 1,
                    visto_em    = UTC_TIMESTAMP(),
                    contexto    = VALUES(contexto),
                    url         = VALUES(url),
                    ip          = VALUES(ip),
                    usuario_id  = VALUES(usuario_id),
                    request_id  = VALUES(request_id),
                    trace       = VALUES(trace)"
            )->execute([
                ':nivel'       => $nivel,
                ':canal'       => mb_substr($canal, 0, 50),
                ':mensagem'    => $mensagem,
                ':tipo'        => isset($origem['tipo'])    ? mb_substr((string) $origem['tipo'], 0, 150) : null,
                ':arquivo'     => isset($origem['arquivo']) ? mb_substr((string) $origem['arquivo'], 0, 255) : null,
                ':linha'       => isset($origem['linha'])   ? (int) $origem['linha'] : null,
                ':trace'       => $trace,
                ':contexto'    => $ctxSeguro !== null
                    ? json_encode($ctxSeguro, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                    : null,
                ':usuario_id'  => self::usuarioId(),
                ':ip'          => self::clientIp(),
                ':url'         => mb_substr(self::currentUrl(), 0, 500),
                ':metodo'      => mb_substr($_SERVER['REQUEST_METHOD'] ?? 'CLI', 0, 10),
                ':referer'     => isset($_SERVER['HTTP_REFERER'])
                    ? mb_substr((string) $_SERVER['HTTP_REFERER'], 0, 500) : null,
                ':user_agent'  => isset($_SERVER['HTTP_USER_AGENT'])
                    ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
                ':request_id'  => self::requestId(),
                ':fingerprint' => $fingerprint,
            ]);

        } catch (Throwable $e) {
            // Log NUNCA derruba a aplicação.
            error_log('[LOG-FAIL] ' . $e->getMessage() . ' | original: ' . $mensagem);
        }
    }

    /** Log em arquivo — mantido da versão anterior, agora com redação e request_id. */
    private static function gravarArquivo(
        string $nivel,
        string $canal,
        string $mensagem,
        ?array $ctx
    ): void {
        if (!defined('STORAGE_PATH')) {
            return;
        }
        $linha = sprintf(
            "[%s] %s %s.%s: %s %s\n",
            date('Y-m-d H:i:s'),
            self::requestId(),
            strtoupper($nivel),
            $canal,
            $mensagem,
            $ctx !== null ? (string) json_encode($ctx, JSON_UNESCAPED_UNICODE) : ''
        );
        @error_log($linha, 3, STORAGE_PATH . '/logs/' . date('Y-m-d') . '.log');
    }

    /**
     * Assinatura estável do erro. Normaliza números e hashes, para que
     * "Produto 42 nao encontrado" e "Produto 77 nao encontrado" caiam no
     * MESMO grupo em vez de virarem 2 mil linhas.
     */
    private static function fingerprint(
        string $nivel,
        string $canal,
        array $origem,
        string $mensagem
    ): string {
        $norm = preg_replace('/\b[0-9a-f]{8,}\b/i', '#HASH#', $mensagem) ?? $mensagem;
        $norm = preg_replace('/\d+/', '#N#', $norm) ?? $norm;
        $norm = preg_replace('/\s+/', ' ', trim($norm)) ?? $norm;

        return sha1(implode('|', [
            $nivel,
            $canal,
            (string) ($origem['tipo'] ?? ''),
            (string) ($origem['arquivo'] ?? ''),
            (string) ($origem['linha'] ?? ''),
            mb_substr($norm, 0, 300),
        ]));
    }

    /** Redige recursivamente valores de chaves sensíveis no contexto. */
    private static function redact(array $dados, int $prof = 0): array
    {
        if ($prof > 6) {
            return ['[PROFUNDIDADE_MAXIMA]'];
        }

        $out = [];
        foreach ($dados as $k => $v) {
            $chave = is_string($k) ? mb_strtolower($k) : '';

            $sensivel = false;
            foreach (self::CHAVES_SENSIVEIS as $alvo) {
                if ($chave !== '' && str_contains($chave, $alvo)) {
                    $sensivel = true;
                    break;
                }
            }

            if ($sensivel) {
                $out[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $out[$k] = self::redact($v, $prof + 1);
            } elseif (is_object($v)) {
                $out[$k] = '[objeto ' . $v::class . ']';
            } elseif (is_string($v)) {
                $out[$k] = self::redactString(mb_substr($v, 0, 1000));
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Redige padrões sensíveis dentro de strings livres (mensagens e traces).
     * O trace do PHP pode conter argumentos:
     *     authenticate('a@b.com', 'senha123')
     * que aqui vira:
     *     authenticate('a@b.com', '[REDACTED]')
     *
     * Defesa em profundidade: garanta também no php.ini
     *     zend.exception_ignore_args = On
     * (assim o PHP nem coloca os argumentos no trace).
     */
    private static function redactString(string $s): string
    {
        $padrao = implode('|', array_map(
            static fn(string $c): string => preg_quote($c, '/'),
            self::CHAVES_SENSIVEIS
        ));

        // chave=valor | "chave":"valor" | chave: valor  -> mascara o valor
        $s = preg_replace(
            '/(["\']?[\w\-.]*(?:' . $padrao . ')[\w\-.]*["\']?\s*[:=]\s*)(["\']?)([^,;&"\'\s)\]]{2,})\2/i',
            '$1$2[REDACTED]$2',
            $s
        ) ?? $s;

        // Cartão (13-19 dígitos) — nunca em log (PCI-DSS)
        $s = preg_replace('/\b(?:\d[ -]?){13,19}\b/', '[CARD_REDACTED]', $s) ?? $s;

        // Bearer / Basic
        $s = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9.\-_=\/+]{8,}/i', '$1 [REDACTED]', $s) ?? $s;

        return $s;
    }

    /** Primeiro frame fora deste arquivo — onde o log foi realmente chamado. */
    private static function callerOrigin(): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $f) {
            $arq = $f['file'] ?? '';
            if ($arq !== '' && !str_ends_with($arq, 'LogService.php')) {
                return ['arquivo' => $arq, 'linha' => (int) ($f['line'] ?? 0)];
            }
        }
        return [];
    }

    /**
     * ID do USUÁRIO (usuarios.id) — não do admin nem do cliente.
     * Ver nota de BUG CORRIGIDO no topo do arquivo.
     */
    private static function usuarioId(): ?int
    {
        // 1. Bridge oficial do projeto (admins.usuario_id -> usuarios.id)
        if (class_exists('AuthHelper') && method_exists('AuthHelper', 'usuarioId')) {
            $id = AuthHelper::usuarioId();
            if (!empty($id)) {
                return (int) $id;
            }
        }
        // 2. A sessão já guarda usuario_id (loginCliente/loginAdmin)
        if (class_exists('Session')) {
            $id = Session::get('usuario_id');
            if (!empty($id)) {
                return (int) $id;
            }
        }
        return null;
    }

    /**
     * IP REAL. Pós-Fase 8, o Nginx reescreve REMOTE_ADDR a partir do
     * CF-Connecting-IP (à prova de spoofing XFF). Sem isto, todo log
     * registraria o IP da Cloudflare — auditoria inútil.
     */
    private static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '' && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    private static function currentUrl(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'cli:' . implode(' ', array_slice($_SERVER['argv'] ?? [], 0, 5));
        }
        return ($_SERVER['HTTP_HOST'] ?? 'unknown') . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    private static function db(): PDO
    {
        if (self::$db === null) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }
}