<?php
/**
 * ApiKeyService — chaves de API, autenticação, escopos, rate limit e log.
 *
 * Segurança: só o SHA-256 da chave é gravado (chave_hash); o texto puro é
 * exibido UMA vez na criação. O prefixo (16 chars) identifica a chave sem
 * expor o segredo. Comparação com hash_equals (timing-safe).
 *
 * escopos (JSON) aceita dois formatos (compatível, sem ALTER):
 *   - array legado: ["cotar","etiquetas"]
 *   - objeto:       {"scopes":["cotar",...], "webhook":{"url":...,"secret":...}}
 * Chaves de escopo iniciando com "__" são reservadas e ignoradas.
 *
 * Rate limit: conta log_api_requisicoes da chave nos últimos 60s.
 *
 * Métodos de DECISÃO (gerarChave, hashChave, escopoPermitido, rateLimitExcedido,
 * envelope, escoposDe, webhookDe) são PUROS — testáveis sem banco.
 */
class ApiKeyService
{
    private PDO $pdo;

    public const ESCOPOS_VALIDOS = ['cotar', 'etiquetas', 'rastreio', 'reversa', 'divergencias', '*'];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /* =================================================================
       DECISÃO (puro)
       ================================================================= */

    /** Gera uma chave nova: texto puro + prefixo visível + hash a persistir. */
    public static function gerarChave(): array
    {
        try { $rand = bin2hex(random_bytes(20)); }
        catch (\Throwable $e) { $rand = sha1(uniqid('', true) . mt_rand()); }
        $full = 'sk_live_' . $rand;                 // 8 + 40 = 48 chars
        return ['chave' => $full, 'prefixo' => substr($full, 0, 16), 'hash' => hash('sha256', $full)];
    }

    public static function hashChave(string $full): string { return hash('sha256', trim($full)); }

    public static function escoposDe($raw): array
    {
        $d = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
        if (isset($d['scopes']) && is_array($d['scopes'])) $list = $d['scopes'];
        elseif (array_is_list($d)) $list = $d;
        else $list = [];
        return array_values(array_filter(array_map('strval', $list), static fn($s) => $s !== '' && strpos($s, '__') !== 0 && strpos($s, 'webhook') !== 0));
    }

    public static function webhookDe($raw): array
    {
        $d = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
        $w = $d['webhook'] ?? null;
        return is_array($w) ? ['url' => $w['url'] ?? null, 'secret' => $w['secret'] ?? null] : ['url' => null, 'secret' => null];
    }

    public static function escopoPermitido(array $escopos, string $necessario): bool
    {
        return in_array('*', $escopos, true) || in_array($necessario, $escopos, true);
    }

    public static function rateLimitExcedido(int $contagem, int $limite): bool
    {
        return $limite > 0 && $contagem >= $limite;
    }

    /** Envelope de resposta padrão da API. */
    public static function envelope(bool $ok, $dados = null, ?array $erro = null, array $meta = []): array
    {
        $env = ['ok' => $ok];
        if ($ok) $env['dados'] = $dados;
        else $env['erro'] = $erro ?? ['codigo' => 'erro', 'mensagem' => 'Falha.'];
        if ($meta) $env['meta'] = $meta;
        return $env;
    }

    /* =================================================================
       GESTÃO
       ================================================================= */

    public function criar(string $nome, array $escopos, int $rateLimit = 120, array $webhook = []): array
    {
        $nome = trim($nome);
        if ($nome === '') return ['ok' => false, 'erro' => 'Informe um nome para a chave.'];
        $escopos = array_values(array_intersect(array_map('strval', $escopos), self::ESCOPOS_VALIDOS));
        if (!$escopos) return ['ok' => false, 'erro' => 'Selecione ao menos um escopo.'];

        $g = self::gerarChave();
        $payload = ['scopes' => $escopos];
        if (!empty($webhook['url'])) $payload['webhook'] = ['url' => $webhook['url'], 'secret' => $webhook['secret'] ?? bin2hex(random_bytes(16))];

        try {
            $st = $this->pdo->prepare(
                "INSERT INTO log_api_keys (nome, prefixo, chave_hash, escopos, rate_limit, ativa)
                 VALUES (:n, :p, :h, :e, :r, 1)"
            );
            $st->execute([
                ':n' => $nome, ':p' => $g['prefixo'], ':h' => $g['hash'],
                ':e' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ':r' => max(1, min(100000, $rateLimit)),
            ]);
            $id = (int)$this->pdo->lastInsertId();
        } catch (\Throwable $e) {
            LogService::error('Falha ao criar chave de API', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao criar a chave.'];
        }

        LogService::audit('Chave de API criada', ['api_key_id' => $id, 'nome' => $nome, 'escopos' => $escopos]);
        // A chave em texto puro é retornada AQUI e nunca mais.
        return ['ok' => true, 'id' => $id, 'chave' => $g['chave'], 'prefixo' => $g['prefixo']];
    }

    public function atualizar(int $id, array $campos): array
    {
        $sets = []; $vals = [':id' => $id];
        if (isset($campos['nome']) && trim((string)$campos['nome']) !== '') { $sets[] = 'nome = :n'; $vals[':n'] = trim((string)$campos['nome']); }
        if (isset($campos['rate_limit'])) { $sets[] = 'rate_limit = :r'; $vals[':r'] = max(1, min(100000, (int)$campos['rate_limit'])); }
        if (isset($campos['ativa'])) { $sets[] = 'ativa = :a'; $vals[':a'] = $campos['ativa'] ? 1 : 0; }
        if (isset($campos['escopos']) && is_array($campos['escopos'])) {
            $esc = array_values(array_intersect(array_map('strval', $campos['escopos']), self::ESCOPOS_VALIDOS));
            $payload = ['scopes' => $esc];
            if (!empty($campos['webhook']['url'])) $payload['webhook'] = ['url' => $campos['webhook']['url'], 'secret' => $campos['webhook']['secret'] ?? bin2hex(random_bytes(16))];
            $sets[] = 'escopos = :e'; $vals[':e'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        if (!$sets) return ['ok' => false, 'erro' => 'Nada para atualizar.'];
        try {
            $this->pdo->prepare("UPDATE log_api_keys SET " . implode(', ', $sets) . " WHERE id = :id")->execute($vals);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao atualizar a chave.'];
        }
        LogService::audit('Chave de API atualizada', ['api_key_id' => $id]);
        return ['ok' => true];
    }

    public function revogar(int $id): array
    {
        try {
            $this->pdo->prepare("UPDATE log_api_keys SET ativa = 0 WHERE id = :id")->execute([':id' => $id]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao revogar.'];
        }
        LogService::audit('Chave de API revogada', ['api_key_id' => $id]);
        return ['ok' => true];
    }

    public function listar(): array
    {
        try {
            $rows = $this->pdo->query(
                "SELECT k.id, k.nome, k.prefixo, k.escopos, k.rate_limit, k.ativa, k.ultimo_uso, k.criado_em,
                        (SELECT COUNT(*) FROM log_api_requisicoes r WHERE r.api_key_id = k.id AND r.criado_em >= (NOW() - INTERVAL 24 HOUR)) AS req_24h
                 FROM log_api_keys k ORDER BY k.id DESC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar chaves de API', ['erro' => $e->getMessage()]);
            return [];
        }
        foreach ($rows as &$r) {
            $r['escopos_arr'] = self::escoposDe($r['escopos']);
            $wh = self::webhookDe($r['escopos']);
            $r['webhook_url'] = $wh['url'];
            unset($r['escopos']); // não devolve o objeto cru (segredo do webhook)
        }
        return $rows;
    }

    /* =================================================================
       AUTENTICAÇÃO + LOG
       ================================================================= */

    /** Resolve e valida a chave a partir do header Authorization: Bearer. */
    public function autenticar(?string $authorization): ?array
    {
        $token = self::extrairToken($authorization);
        if ($token === '' || strlen($token) < 16) return null;
        $prefixo = substr($token, 0, 16);

        try {
            $st = $this->pdo->prepare("SELECT * FROM log_api_keys WHERE prefixo = :p AND ativa = 1 LIMIT 1");
            $st->execute([':p' => $prefixo]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            LogService::error('Falha ao autenticar chave de API', ['erro' => $e->getMessage()]);
            return null;
        }
        if (!$row) return null;
        if (!hash_equals((string)$row['chave_hash'], self::hashChave($token))) return null;

        try { $this->pdo->prepare("UPDATE log_api_keys SET ultimo_uso = NOW() WHERE id = :id")->execute([':id' => (int)$row['id']]); }
        catch (\Throwable $e) { /* não bloqueia */ }

        $row['escopos_arr'] = self::escoposDe($row['escopos']);
        $row['webhook'] = self::webhookDe($row['escopos']);
        unset($row['chave_hash']);
        return $row;
    }

    public function contarRequisicoesRecentes(int $apiKeyId, int $segundos = 60): int
    {
        $segundos = max(1, min(3600, $segundos));
        try {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM log_api_requisicoes WHERE api_key_id = :k AND criado_em >= (NOW() - INTERVAL $segundos SECOND)");
            $st->execute([':k' => $apiKeyId]);
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) {
            return 0; // fail-open no rate limit (não derruba a API por erro de contagem)
        }
    }

    public function idempotenciaJaProcessada(?int $apiKeyId, string $rota, ?string $idem): bool
    {
        if (!$idem || !$apiKeyId) return false;
        try {
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM log_api_requisicoes
                 WHERE api_key_id = :k AND rota = :r AND idempotency_key = :i
                   AND status_http BETWEEN 200 AND 299 AND criado_em >= (NOW() - INTERVAL 24 HOUR)"
            );
            $st->execute([':k' => $apiKeyId, ':r' => $rota, ':i' => $idem]);
            return (int)$st->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function registrarRequisicao(?int $apiKeyId, string $metodo, string $rota, string $versao, int $status, ?string $idem, ?string $ip, ?int $duracaoMs): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO log_api_requisicoes (api_key_id, metodo, rota, versao, status_http, idempotency_key, ip, duracao_ms)
                 VALUES (:k, :m, :r, :v, :s, :i, :ip, :d)"
            )->execute([
                ':k' => $apiKeyId, ':m' => substr($metodo, 0, 8), ':r' => substr($rota, 0, 200), ':v' => substr($versao, 0, 8),
                ':s' => $status, ':i' => $idem ? substr($idem, 0, 120) : null, ':ip' => $ip ? substr($ip, 0, 45) : null, ':d' => $duracaoMs,
            ]);
        } catch (\Throwable $e) { /* log nunca derruba a requisição */ }
    }

    /* =================================================================
       Internos
       ================================================================= */

    private static function extrairToken(?string $authorization): string
    {
        $h = trim((string)$authorization);
        if ($h === '') return '';
        if (stripos($h, 'bearer ') === 0) return trim(substr($h, 7));
        return $h; // aceita a chave crua também
    }
}
