<?php
// app/services/LogService.php
// Registra logs no banco e em arquivo para auditoria e debug.

class LogService {

    private static PDO $db;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    public static function info(string $msg, array $ctx = []): void {
        self::write('info', 'app', $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void {
        self::write('error', 'app', $msg, $ctx);
        // Em produção: notifica por e-mail para erros críticos
    }

    public static function warning(string $msg, array $ctx = []): void {
        self::write('warning', 'app', $msg, $ctx);
    }

    public static function audit(string $msg, array $ctx = []): void {
        self::write('info', 'audit', $msg, $ctx);
    }

    private static function write(string $nivel, string $canal,
                                   string $msg, array $ctx = []): void {
        $userId = Session::isAdminLogado()
                  ? Session::getAdminId()
                  : Session::getClienteId();

        // Salva no banco (assíncrono em produção via queue)
        try {
            self::db()->prepare(
                "INSERT INTO logs (nivel, canal, mensagem, contexto, usuario_id, ip, url)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([
                $nivel,
                $canal,
                $msg,
                !empty($ctx) ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : null,
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['REQUEST_URI'] ?? null,
            ]);
        } catch (Exception) { /* Não falha a requisição por erro de log */ }

        // Sempre registra em arquivo
        $line = sprintf("[%s] %s.%s: %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($nivel),
            $canal,
            $msg,
            !empty($ctx) ? json_encode($ctx) : ''
        );

        $logFile = STORAGE_PATH . '/logs/' . date('Y-m-d') . '.log';
        @error_log($line, 3, $logFile);
    }
}