<?php
// app/services/app/AppTokenService.php
// Tokens opacos do app: access, refresh, desafio de 2FA e nonce de tokenização.
//
// Opacos e não JWT de propósito — permitem revogação instantânea (logout
// remoto, conta bloqueada, detecção de reuso) sem gestão de chaves.
//
// Armazenamento copiado de ApiKeyService: prefixo indexado de 16 chars para o
// lookup + sha256 do token completo, comparado com hash_equals (timing-safe).
// O texto puro existe só na resposta HTTP e nunca é persistido.
//
// Rotação de refresh com detecção de reuso: apresentar um refresh que já foi
// consumido revoga a FAMÍLIA inteira. É a mesma política de
// TokenService::checkRotatedToken() usada no cookie ec_remember da web.

class AppTokenService
{
    private PDO $pdo;

    public const TTL_ACCESS  = 3600;      // 60 min
    public const TTL_REFRESH = 7776000;   // 90 dias
    public const TTL_2FA     = 600;       // 10 min
    public const TTL_NONCE   = 600;       // 10 min

    private const PREFIXOS = [
        'access'         => 'at_',
        'refresh'        => 'rt_',
        '2fa_challenge'  => 'ch_',
        'tokenize_nonce' => 'nc_',
    ];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /* =================================================================
       DECISÃO (puro)
       ================================================================= */

    /** Gera token em texto puro + prefixo de lookup + hash a persistir. */
    public static function gerar(string $tipo): array
    {
        $marca = self::PREFIXOS[$tipo] ?? 'tk_';
        try { $rand = bin2hex(random_bytes(20)); }
        catch (\Throwable $e) { $rand = sha1(uniqid('', true) . mt_rand()); }

        $full = $marca . $rand; // 3 + 40 = 43 chars
        return [
            'token'   => $full,
            'prefixo' => substr($full, 0, 16),
            'hash'    => hash('sha256', $full),
        ];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', trim($token));
    }

    /** Aceita "Bearer xxx" ou o token cru — mesmo comportamento de ApiKeyService. */
    public static function extrairToken(?string $authorization): string
    {
        $h = trim((string)$authorization);
        if ($h === '') return '';
        if (stripos($h, 'bearer ') === 0) return trim(substr($h, 7));
        return $h;
    }

    public static function novaFamilia(): string
    {
        try { return bin2hex(random_bytes(16)); }
        catch (\Throwable $e) { return md5(uniqid('', true)); }
    }

    /* =================================================================
       EMISSÃO
       ================================================================= */

    /**
     * Emite um par access + refresh para o dispositivo.
     * $familia null cria uma família nova (login); passar a existente mantém a
     * linhagem viva através das rotações.
     */
    public function emitirPar(
        int $dispositivoId,
        ?int $usuarioId = null,
        ?int $clienteId = null,
        ?string $familia = null
    ): array {
        $familia ??= self::novaFamilia();

        $access  = $this->emitir('access',  $dispositivoId, self::TTL_ACCESS,  $familia, $usuarioId, $clienteId);
        $refresh = $this->emitir('refresh', $dispositivoId, self::TTL_REFRESH, $familia, $usuarioId, $clienteId);

        return [
            'access_token'  => $access['token'],
            'refresh_token' => $refresh['token'],
            'expira_em'     => date(DATE_ATOM, time() + self::TTL_ACCESS),
            'expira_em_s'   => self::TTL_ACCESS,
            'familia'       => $familia,
        ];
    }

    /**
     * Cria um token de tipo arbitrário.
     * @return array{id:int,token:string} O texto puro só existe aqui.
     */
    public function emitir(
        string $tipo,
        int $dispositivoId,
        int $ttl,
        ?string $familia = null,
        ?int $usuarioId = null,
        ?int $clienteId = null,
        array $contexto = []
    ): array {
        $g = self::gerar($tipo);
        $familia ??= self::novaFamilia();

        $st = $this->pdo->prepare(
            "INSERT INTO app_tokens
                (dispositivo_id, tipo, familia, prefixo, token_hash, usuario_id, cliente_id, contexto, expira_em)
             VALUES (:d, :t, :f, :p, :h, :u, :c, :ctx, DATE_ADD(NOW(), INTERVAL :ttl SECOND))"
        );
        $st->bindValue(':d', $dispositivoId, PDO::PARAM_INT);
        $st->bindValue(':t', $tipo);
        $st->bindValue(':f', $familia);
        $st->bindValue(':p', $g['prefixo']);
        $st->bindValue(':h', $g['hash']);
        $st->bindValue(':u', $usuarioId, $usuarioId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':c', $clienteId, $clienteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':ctx', $contexto ? json_encode($contexto, JSON_UNESCAPED_UNICODE) : null);
        $st->bindValue(':ttl', $ttl, PDO::PARAM_INT);
        $st->execute();

        return ['id' => (int)$this->pdo->lastInsertId(), 'token' => $g['token']];
    }

    /* =================================================================
       VALIDAÇÃO
       ================================================================= */

    /**
     * Resolve um token válido (não expirado, não revogado) do tipo pedido.
     * @return array|null A linha de app_tokens, com 'dispositivo' embutido.
     */
    public function validar(string $token, string $tipo): ?array
    {
        $token = trim($token);
        if (strlen($token) < 16) {
            return null;
        }

        try {
            $st = $this->pdo->prepare(
                "SELECT t.*, d.id AS d_id, d.device_uuid, d.plataforma, d.app_versao, d.bloqueado,
                        d.php_session_id, d.cliente_id AS d_cliente_id, d.usuario_id AS d_usuario_id,
                        d.ultimo_acesso AS d_ultimo_acesso
                 FROM app_tokens t
                 INNER JOIN app_dispositivos d ON d.id = t.dispositivo_id
                 WHERE t.prefixo = :p AND t.tipo = :t
                 LIMIT 1"
            );
            $st->execute([':p' => substr($token, 0, 16), ':t' => $tipo]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            LogService::error('Falha ao validar token do app', ['erro' => $e->getMessage()]);
            return null;
        }

        if (!$row) {
            return null;
        }
        if (!hash_equals((string)$row['token_hash'], self::hash($token))) {
            return null;
        }
        if (!empty($row['revogado_em'])) {
            return null;
        }
        if (strtotime((string)$row['expira_em']) < time()) {
            return null;
        }
        if (!empty($row['bloqueado'])) {
            return null;
        }

        $row['contexto_arr'] = $row['contexto'] ? (json_decode((string)$row['contexto'], true) ?: []) : [];
        unset($row['token_hash']);
        return $row;
    }

    /**
     * Troca um refresh por um par novo, rotacionando.
     *
     * Detecção de reuso: se o refresh apresentado já tem usado_em preenchido,
     * significa que alguém está replicando um token antigo — a família inteira
     * é revogada e o dispositivo precisa refazer login.
     *
     * @return array{ok:bool, tokens?:array, erro?:string}
     */
    public function rotacionar(string $refreshToken): array
    {
        $linha = $this->validar($refreshToken, 'refresh');

        if (!$linha) {
            // Pode ser um token já consumido: checa reuso antes de desistir.
            if ($this->detectarReuso($refreshToken)) {
                return ['ok' => false, 'erro' => 'reuso_detectado'];
            }
            return ['ok' => false, 'erro' => 'token_invalido'];
        }

        if (!empty($linha['usado_em'])) {
            $this->revogarFamilia((string)$linha['familia'], 'reuso_detectado');
            LogService::error('Reuso de refresh token detectado', [
                'dispositivo_id' => (int)$linha['dispositivo_id'],
                'familia'        => $linha['familia'],
            ]);
            return ['ok' => false, 'erro' => 'reuso_detectado'];
        }

        $novos = $this->emitirPar(
            (int)$linha['dispositivo_id'],
            $linha['usuario_id'] !== null ? (int)$linha['usuario_id'] : null,
            $linha['cliente_id'] !== null ? (int)$linha['cliente_id'] : null,
            (string)$linha['familia']
        );

        // Marca o refresh consumido e revoga o access antigo da mesma família.
        try {
            $this->pdo->prepare(
                "UPDATE app_tokens
                 SET usado_em = NOW(), revogado_em = NOW(), motivo_revogacao = 'rotacao'
                 WHERE id = :id"
            )->execute([':id' => (int)$linha['id']]);

            $this->pdo->prepare(
                "UPDATE app_tokens
                 SET revogado_em = NOW(), motivo_revogacao = 'rotacao'
                 WHERE familia = :f AND tipo = 'access' AND revogado_em IS NULL"
            )->execute([':f' => (string)$linha['familia']]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao rotacionar refresh token', ['erro' => $e->getMessage()]);
        }

        return ['ok' => true, 'tokens' => $novos, 'dispositivo_id' => (int)$linha['dispositivo_id']];
    }

    /** Um token já consumido reaparecendo é sinal de vazamento. */
    private function detectarReuso(string $token): bool
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT familia, token_hash, dispositivo_id FROM app_tokens
                 WHERE prefixo = :p AND tipo = 'refresh' LIMIT 1"
            );
            $st->execute([':p' => substr(trim($token), 0, 16)]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return false;
        }

        if (!$row || !hash_equals((string)$row['token_hash'], self::hash($token))) {
            return false;
        }

        $this->revogarFamilia((string)$row['familia'], 'reuso_detectado');
        LogService::error('Reuso de refresh token já revogado', [
            'dispositivo_id' => (int)$row['dispositivo_id'],
            'familia'        => $row['familia'],
        ]);
        return true;
    }

    /* =================================================================
       REVOGAÇÃO
       ================================================================= */

    public function revogarFamilia(string $familia, string $motivo = 'logout'): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE app_tokens
                 SET revogado_em = NOW(), motivo_revogacao = :m
                 WHERE familia = :f AND revogado_em IS NULL"
            )->execute([':m' => substr($motivo, 0, 40), ':f' => $familia]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao revogar família de tokens', ['erro' => $e->getMessage()]);
        }
    }

    public function revogarDispositivo(int $dispositivoId, string $motivo = 'logout'): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE app_tokens
                 SET revogado_em = NOW(), motivo_revogacao = :m
                 WHERE dispositivo_id = :d AND revogado_em IS NULL"
            )->execute([':m' => substr($motivo, 0, 40), ':d' => $dispositivoId]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao revogar tokens do dispositivo', ['erro' => $e->getMessage()]);
        }
    }

    /** Revoga todos os tokens de um cliente — usado no logout remoto. */
    public function revogarCliente(int $clienteId, string $motivo = 'logout_remoto'): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE app_tokens
                 SET revogado_em = NOW(), motivo_revogacao = :m
                 WHERE cliente_id = :c AND revogado_em IS NULL"
            )->execute([':m' => substr($motivo, 0, 40), ':c' => $clienteId]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao revogar tokens do cliente', ['erro' => $e->getMessage()]);
        }
    }

    /** Consome um token de uso único (desafio 2FA, nonce de tokenização). */
    public function consumir(int $tokenId, string $motivo = 'consumido'): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE app_tokens
                 SET usado_em = NOW(), revogado_em = NOW(), motivo_revogacao = :m
                 WHERE id = :id"
            )->execute([':m' => substr($motivo, 0, 40), ':id' => $tokenId]);
        } catch (\Throwable $e) { /* silencioso */ }
    }

    /** Limpeza de tokens vencidos. Chamado esporadicamente pelo bootstrap. */
    public function limparExpirados(): int
    {
        try {
            $st = $this->pdo->prepare(
                "DELETE FROM app_tokens
                 WHERE expira_em < DATE_SUB(NOW(), INTERVAL 7 DAY)
                 LIMIT 2000"
            );
            $st->execute();
            return $st->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
