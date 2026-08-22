<?php
// app/services/app/AppSessionHandler.php
// Backend de sessão em MySQL para a API do app.
//
// Por que não usar arquivos:
//   1. session.save_path e session.gc_maxlifetime são frequentemente forçados
//      pelo php.ini do hosting. Com o padrão de 1440s, o estado de checkout do
//      app evaporaria no meio de uma compra.
//   2. O lock de arquivo do PHP serializa requests concorrentes da mesma
//      sessão. A home do app dispara 4-6 chamadas em paralelo; com lock de
//      arquivo elas viram fila.
//
// Este handler NÃO faz lock. Isso é deliberado e seguro aqui porque a API
// segue a disciplina de session_write_close() logo após a leitura em todo GET
// (AppApiController::liberarSessao()), e as escritas de estado do app são
// idempotentes por endpoint. Nunca reutilizar este handler para a loja web.

class AppSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private PDO $pdo;
    private int $ttl;

    /** @param int $ttl Vida da sessão em segundos (padrão 30 dias). */
    public function __construct(?PDO $pdo = null, int $ttl = 2592000)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->ttl = $ttl;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    /** Expiração da sessão lida neste request, para decidir se vale renovar. */
    private ?int $expiraEm = null;

    #[\ReturnTypeWillChange]
    public function read(string $id): string
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT dados, expira_em FROM app_sessoes WHERE id = :id AND expira_em > NOW() LIMIT 1"
            );
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->expiraEm = null;
                return '';
            }

            $this->expiraEm = strtotime((string)$row['expira_em']) ?: null;
            return $row['dados'] === null ? '' : (string)$row['dados'];
        } catch (\Throwable $e) {
            LogService::error('AppSessionHandler::read falhou', ['erro' => $e->getMessage()]);
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $st = $this->pdo->prepare(
                "INSERT INTO app_sessoes (id, dados, expira_em)
                 VALUES (:id, :d, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
                 ON DUPLICATE KEY UPDATE
                    dados = VALUES(dados),
                    expira_em = VALUES(expira_em)"
            );
            $st->bindValue(':id', $id);
            $st->bindValue(':d', $data, PDO::PARAM_LOB);
            $st->bindValue(':ttl', $this->ttl, PDO::PARAM_INT);
            $st->execute();
            return true;
        } catch (\Throwable $e) {
            LogService::error('AppSessionHandler::write falhou', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->pdo->prepare("DELETE FROM app_sessoes WHERE id = :id")->execute([':id' => $id]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    #[\ReturnTypeWillChange]
    public function gc(int $max_lifetime): int
    {
        // Ignora $max_lifetime de propósito: quem manda é a coluna expira_em,
        // calculada com o TTL desta classe e não com o php.ini do servidor.
        try {
            $st = $this->pdo->prepare("DELETE FROM app_sessoes WHERE expira_em < NOW() LIMIT 1000");
            $st->execute();
            return $st->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Chamado pelo PHP com use_strict_mode para saber se o id já existe. */
    public function validateId(string $id): bool
    {
        try {
            $st = $this->pdo->prepare("SELECT 1 FROM app_sessoes WHERE id = :id AND expira_em > NOW() LIMIT 1");
            $st->execute([':id' => $id]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Renova a expiração sem reescrever os dados (sessão lida, não alterada).
     *
     * O PHP chama isto em TODO request que só leu a sessão. Como o TTL é de 30
     * dias, renovar a cada request é um UPDATE desperdiçado no caminho mais
     * quente da API. Só renova quando falta menos de um dia — a sessão continua
     * deslizante, ao custo de um write por dia em vez de um por request.
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        if ($this->expiraEm !== null && ($this->expiraEm - time()) > 86400) {
            return true;
        }

        try {
            $st = $this->pdo->prepare(
                "UPDATE app_sessoes SET expira_em = DATE_ADD(NOW(), INTERVAL :ttl SECOND) WHERE id = :id"
            );
            $st->bindValue(':ttl', $this->ttl, PDO::PARAM_INT);
            $st->bindValue(':id', $id);
            $st->execute();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
