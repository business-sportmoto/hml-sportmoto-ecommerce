<?php
// config/database.php
// Conexão única com o banco usando PDO e Singleton pattern

class Database {
    private static ?Database $instance = null;
    private PDO $connection;

    private function __construct() {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // prepared statements reais
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Nunca expõe detalhes de conexão ao usuário final
            if (APP_DEBUG) {
                throw new RuntimeException('Falha na conexão: ' . $e->getMessage());
            }
            throw new RuntimeException('Serviço temporariamente indisponível.');
        }
    }

    // Retorna a instância única (Singleton)
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Retorna o objeto PDO para uso nos Models
    public function getConnection(): PDO {
        return $this->connection;
    }

    // Bloqueia clonagem e deserialização (padrão Singleton seguro)
    private function __clone() {}
    public function __wakeup(): never {
        throw new RuntimeException('Cannot unserialize singleton.');
    }
}