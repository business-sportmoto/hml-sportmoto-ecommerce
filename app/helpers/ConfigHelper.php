<?php
// app/helpers/ConfigHelper.php
// Carrega e cacheia as configurações da tabela `configuracoes`.
// Evita múltiplas queries por requisição.

class ConfigHelper {

    private static ?array $cache = null;

    /**
     * Retorna o valor de uma configuração.
     */
    public static function get(string $chave, mixed $default = null): mixed {
        self::load();
        if (!array_key_exists($chave, self::$cache)) {
            return $default;
        }

        $item = self::$cache[$chave];

        return match($item['tipo']) {
            'int'    => (int)    $item['valor'],
            'bool'   => (bool)   (int) $item['valor'],
            'json'   => json_decode($item['valor'], true),
            default  => $item['valor'],
        };
    }

    /**
     * Atualiza uma configuração no banco (usado no painel admin).
     */
    public static function set(string $chave, mixed $valor): void {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "UPDATE configuracoes SET valor = ? WHERE chave = ?"
        );
        $stmt->execute([(string) $valor, $chave]);
        self::$cache = null; // invalida o cache
    }

    /**
     * Invalida o cache em memória.
     *
     * Quem grava por fora do set() — o painel do rodapé grava várias chaves numa
     * transação só — precisa avisar, senão o resto da requisição segue lendo os
     * valores antigos e a tela mostra "salvo" com o conteúdo velho.
     */
    public static function limparCache(): void {
        self::$cache = null;
    }

    /**
     * Carrega todas as configurações do banco uma única vez.
     */
    private static function load(): void {
        if (self::$cache !== null) return;

        try {
            $db   = Database::getInstance()->getConnection();
            $rows = $db->query("SELECT chave, valor, tipo FROM configuracoes")->fetchAll();
            self::$cache = array_column($rows, null, 'chave');
        } catch (Exception) {
            self::$cache = [];
        }
    }

    /**
     * Retorna todas as configurações de um grupo.
     */
    public static function getGroup(string $grupo): array {
        self::load();
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT chave, valor, tipo FROM configuracoes WHERE grupo = ?");
        $stmt->execute([$grupo]);
        return $stmt->fetchAll();
    }

    public static function darken(string $hex, int $percent): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return '#' . $hex;
        $r = max(0, hexdec(substr($hex, 0, 2)) - $percent * 2);
        $g = max(0, hexdec(substr($hex, 2, 2)) - $percent * 2);
        $b = max(0, hexdec(substr($hex, 4, 2)) - $percent * 2);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}