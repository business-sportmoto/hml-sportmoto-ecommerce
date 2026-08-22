<?php
// app/services/app/AppDeviceService.php
// CRUD de app_dispositivos. Uma linha por instalação do app.

class AppDeviceService
{
    private PDO $pdo;

    /** Plataformas aceitas — espelha o ENUM da coluna. */
    public const PLATAFORMAS = ['android', 'ios', 'web'];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /**
     * Registra o dispositivo ou atualiza os metadados se o device_uuid já existe.
     * Idempotente de propósito: o app chama isto em todo cold start.
     *
     * @return array|null A linha de app_dispositivos, ou null em falha.
     */
    public function registrar(array $dados): ?array
    {
        $uuid = trim((string)($dados['device_uuid'] ?? ''));
        if ($uuid === '' || !preg_match('/^[A-Za-z0-9\-_]{8,36}$/', $uuid)) {
            return null;
        }

        $plataforma = strtolower(trim((string)($dados['plataforma'] ?? '')));
        if (!in_array($plataforma, self::PLATAFORMAS, true)) {
            return null;
        }

        $campos = [
            ':uuid'   => $uuid,
            ':plat'   => $plataforma,
            ':versao' => self::corta($dados['app_versao'] ?? null, 20),
            ':build'  => self::corta($dados['build_numero'] ?? null, 20),
            ':os'     => self::corta($dados['os_versao'] ?? null, 40),
            ':modelo' => self::corta($dados['modelo'] ?? null, 80),
            ':locale' => self::corta($dados['locale'] ?? null, 10),
            ':ip'     => self::corta($dados['ip'] ?? null, 45),
        ];

        try {
            $this->pdo->prepare(
                "INSERT INTO app_dispositivos
                    (device_uuid, plataforma, app_versao, build_numero, os_versao, modelo, locale,
                     ultimo_ip, ultimo_acesso)
                 VALUES (:uuid, :plat, :versao, :build, :os, :modelo, :locale, :ip, NOW())
                 ON DUPLICATE KEY UPDATE
                    plataforma    = VALUES(plataforma),
                    app_versao    = COALESCE(VALUES(app_versao), app_versao),
                    build_numero  = COALESCE(VALUES(build_numero), build_numero),
                    os_versao     = COALESCE(VALUES(os_versao), os_versao),
                    modelo        = COALESCE(VALUES(modelo), modelo),
                    locale        = COALESCE(VALUES(locale), locale),
                    ultimo_ip     = VALUES(ultimo_ip),
                    ultimo_acesso = NOW()"
            )->execute($campos);
        } catch (\Throwable $e) {
            LogService::error('Falha ao registrar dispositivo do app', ['erro' => $e->getMessage()]);
            return null;
        }

        return $this->porUuid($uuid);
    }

    public function porUuid(string $uuid): ?array
    {
        return $this->buscar("device_uuid = :v", $uuid);
    }

    public function porId(int $id): ?array
    {
        return $this->buscar("id = :v", $id);
    }

    private function buscar(string $where, $valor): ?array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM app_dispositivos WHERE {$where} LIMIT 1");
            $st->execute([':v' => $valor]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            LogService::error('Falha ao buscar dispositivo do app', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /** Campos que o app pode atualizar por conta própria (PATCH /dispositivos). */
    public function atualizar(int $id, array $campos): bool
    {
        $permitidos = [
            'push_token'      => 255,
            'push_habilitado' => null,
            'app_versao'      => 20,
            'build_numero'    => 20,
            'os_versao'       => 40,
            'locale'          => 10,
        ];

        $sets = [];
        $vals = [':id' => $id];

        foreach ($permitidos as $campo => $limite) {
            if (!array_key_exists($campo, $campos)) {
                continue;
            }
            $sets[] = "{$campo} = :{$campo}";
            $vals[":{$campo}"] = $campo === 'push_habilitado'
                ? (!empty($campos[$campo]) ? 1 : 0)
                : self::corta($campos[$campo], $limite);
        }

        if (!$sets) {
            return true; // nada a fazer não é erro
        }

        try {
            $this->pdo->prepare(
                "UPDATE app_dispositivos SET " . implode(', ', $sets) . " WHERE id = :id"
            )->execute($vals);
            return true;
        } catch (\Throwable $e) {
            LogService::error('Falha ao atualizar dispositivo do app', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    public function salvarSessionId(int $id, string $sessionId): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE app_dispositivos SET php_session_id = :sid WHERE id = :id"
            )->execute([':sid' => substr($sessionId, 0, 128), ':id' => $id]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao salvar session id do dispositivo', ['erro' => $e->getMessage()]);
        }
    }

    public function vincularCliente(int $id, int $usuarioId, int $clienteId, ?int $sessaoPersistenteId = null): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE app_dispositivos
                 SET usuario_id = :u, cliente_id = :c, sessao_persistente_id = :s
                 WHERE id = :id"
            )->execute([':u' => $usuarioId, ':c' => $clienteId, ':s' => $sessaoPersistenteId, ':id' => $id]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao vincular cliente ao dispositivo', ['erro' => $e->getMessage()]);
        }
    }

    public function desvincularCliente(int $id): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE app_dispositivos
                 SET usuario_id = NULL, cliente_id = NULL, sessao_persistente_id = NULL
                 WHERE id = :id"
            )->execute([':id' => $id]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao desvincular cliente do dispositivo', ['erro' => $e->getMessage()]);
        }
    }

    /**
     * Marca atividade do dispositivo.
     *
     * Escreve no máximo uma vez a cada 5 minutos: `ultimo_acesso` serve para
     * saber se a instalação continua viva, não para cronometrar requests. Sem
     * o throttle, era um UPDATE em toda chamada da API — e o app faz várias
     * por tela.
     */
    public function tocar(int $id, ?string $ip): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE app_dispositivos
                 SET ultimo_acesso = NOW(), ultimo_ip = :ip
                 WHERE id = :id
                   AND (ultimo_acesso IS NULL OR ultimo_acesso < DATE_SUB(NOW(), INTERVAL 5 MINUTE))"
            )->execute([':ip' => self::corta($ip, 45), ':id' => $id]);
        } catch (\Throwable $e) { /* nunca derruba a requisição */ }
    }

    private static function corta($valor, ?int $limite): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $s = trim((string)$valor);
        return $limite ? mb_substr($s, 0, $limite) : $s;
    }
}
