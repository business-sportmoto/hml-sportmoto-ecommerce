<?php
/**
 * app/services/ChatConfig.php
 *
 * Config chave/valor do módulo Chat (tabela chat_config), com cache de request.
 * Mesmo papel do fluxo_motor_config no motor de automação v2 — separado porque
 * o worker do chat roda sem sessão e precisa ler config sem carregar o admin.
 */
class ChatConfig
{
    /** @var array<string,string>|null cache por request */
    private static ?array $cache = null;

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }

    private static function carregar(): array
    {
        if (self::$cache !== null) return self::$cache;
        try {
            $st = self::db()->query("SELECT chave, valor FROM chat_config");
            self::$cache = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
            self::$cache = [];
        }
        return self::$cache;
    }

    public static function get(string $chave, $default = null)
    {
        $c = self::carregar();
        return array_key_exists($chave, $c) && $c[$chave] !== null ? $c[$chave] : $default;
    }

    public static function int(string $chave, int $default = 0): int
    {
        $v = self::get($chave, null);
        return $v === null || $v === '' ? $default : (int)$v;
    }

    public static function bool(string $chave, bool $default = false): bool
    {
        $v = self::get($chave, null);
        if ($v === null || $v === '') return $default;
        return in_array(strtolower((string)$v), ['1', 'true', 'sim', 'on', 'yes'], true);
    }

    /** Lista a partir de string separada por vírgula. */
    public static function lista(string $chave, array $default = []): array
    {
        $v = trim((string)self::get($chave, ''));
        if ($v === '') return $default;
        return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
    }

    public static function set(string $chave, $valor): void
    {
        $st = self::db()->prepare(
            "INSERT INTO chat_config (chave, valor) VALUES (:c, :v)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
        );
        $st->execute([':c' => mb_substr($chave, 0, 60), ':v' => (string)$valor]);
        if (self::$cache !== null) self::$cache[$chave] = (string)$valor;
    }

    public static function setVarios(array $pares): void
    {
        foreach ($pares as $k => $v) self::set((string)$k, $v);
    }

    public static function todos(): array
    {
        return self::carregar();
    }

    public static function limparCache(): void
    {
        self::$cache = null;
    }

    // =========================================================================
    // JANELA DE ENVIO (quiet hours)
    // =========================================================================

    /**
     * Estamos dentro do horário permitido para envio proativo?
     * Mensagens de RESPOSTA a um contato ativo ignoram isto — quiet hours só
     * vale para o que a loja inicia (campanha, fluxo agendado).
     */
    public static function dentroDaJanelaHoraria(?int $hora = null): bool
    {
        if (!self::bool('quiet_hours_ativo', true)) return true;
        $ini = self::int('quiet_hours_inicio', 8);
        $fim = self::int('quiet_hours_fim', 21);
        $h   = $hora ?? (int)date('G');
        return $h >= $ini && $h < $fim;
    }

    /** Próximo DATETIME permitido, ou null se agora já está liberado. */
    public static function proximaJanelaHoraria(): ?string
    {
        if (self::dentroDaJanelaHoraria()) return null;
        $ini = self::int('quiet_hours_inicio', 8);
        $fim = self::int('quiet_hours_fim', 21);
        $h   = (int)date('G');
        $dia = ($h >= $fim) ? 'tomorrow' : 'today';
        return date('Y-m-d ', strtotime($dia)) . sprintf('%02d:00:00', $ini);
    }
}
