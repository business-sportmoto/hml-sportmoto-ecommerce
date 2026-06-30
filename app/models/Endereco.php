<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/Endereco.php
//
// Schema real (confirmado do CheckoutController.php):
//
//   CREATE TABLE enderecos (
//       id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
//       cliente_id        INT UNSIGNED NOT NULL,
//       nome_destinatario VARCHAR(120) NOT NULL,
//       cep               CHAR(8)     NOT NULL,
//       logradouro        VARCHAR(150) NOT NULL,
//       numero            VARCHAR(20)  NOT NULL,
//       complemento       VARCHAR(80)  NULL,
//       bairro            VARCHAR(80)  NOT NULL,
//       cidade            VARCHAR(80)  NOT NULL,
//       estado            CHAR(2)      NOT NULL,
//       telefone_contato  VARCHAR(20)  NULL,
//       principal         TINYINT(1)   NOT NULL DEFAULT 0,
//       apelido           VARCHAR(40)  NULL,            -- ADD IF NOT EXISTS
//       observacao_entrega VARCHAR(200) NULL,           -- ADD IF NOT EXISTS
//       criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
//       atualizado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
//                         ON UPDATE CURRENT_TIMESTAMP,
//       PRIMARY KEY (id),
//       KEY idx_cliente (cliente_id, principal),
//       CONSTRAINT fk_end_cliente FOREIGN KEY (cliente_id)
//           REFERENCES clientes(id) ON DELETE CASCADE
//   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
//
// -- Adicionar colunas novas se não existirem:
// ALTER TABLE enderecos
//     ADD COLUMN IF NOT EXISTS apelido VARCHAR(40) NULL AFTER principal,
//     ADD COLUMN IF NOT EXISTS observacao_entrega VARCHAR(200) NULL AFTER apelido;
// ════════════════════════════════════════════════════════

class Endereco {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // LEITURA
    // ════════════════════════════════════════════════════

    /**
     * Todos os endereços do cliente, principal primeiro.
     */
    public function listarPorCliente(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM enderecos
             WHERE cliente_id = ?
             ORDER BY principal DESC, id DESC"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    /**
     * Busca um endereço garantindo que pertence ao cliente (evita IDOR).
     */
    public function findOwned(int $id, int $clienteId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM enderecos
             WHERE id = ? AND cliente_id = ?
             LIMIT 1"
        );
        $stmt->execute([$id, $clienteId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Endereço principal do cliente, ou null se não houver.
     */
    public function principal(int $clienteId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM enderecos
             WHERE cliente_id = ? AND principal = 1
             LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetch() ?: null;
    }

    // ════════════════════════════════════════════════════
    // ESCRITA
    // ════════════════════════════════════════════════════

    /**
     * Cria novo endereço. Retorna o ID inserido.
     * Se for marcado como principal, rebaixa os outros.
     */
    public function salvar(array $data): int {
        $d = $this->sanitize($data);

        if (!empty($d['principal'])) {
            $this->rebaixarPrincipal((int)$d['cliente_id']);
        }

        $this->db->prepare(
            "INSERT INTO enderecos
             (cliente_id, nome_destinatario, cep, logradouro, numero,
              complemento, bairro, cidade, estado, telefone_contato,
              principal, apelido, observacao_entrega)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            (int)$d['cliente_id'],
            $d['nome_destinatario'],
            $d['cep'],
            $d['logradouro'],
            $d['numero'],
            $d['complemento'] ?? null,
            $d['bairro'],
            $d['cidade'],
            $d['estado'],
            $d['telefone_contato'] ?? $d['telefone'] ?? null,
            empty($d['principal']) ? 0 : 1,
            $d['apelido'] ?? null,
            $d['observacao_entrega'] ?? null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Atualiza endereço existente. Não troca cliente_id.
     */
    public function atualizar(int $id, array $data): void {
        $d = $this->sanitize($data);

        if (!empty($d['principal'])) {
            // Descobre o cliente_id deste endereço para rebaixar os outros
            $stmt = $this->db->prepare("SELECT cliente_id FROM enderecos WHERE id = ?");
            $stmt->execute([$id]);
            $clienteId = (int)($stmt->fetchColumn() ?: 0);
            if ($clienteId) $this->rebaixarPrincipal($clienteId, $id);
        }

        $this->db->prepare(
            "UPDATE enderecos
             SET nome_destinatario = ?,
                 cep               = ?,
                 logradouro        = ?,
                 numero            = ?,
                 complemento       = ?,
                 bairro            = ?,
                 cidade            = ?,
                 estado            = ?,
                 telefone_contato  = ?,
                 apelido           = ?,
                 observacao_entrega= ?
             WHERE id = ?"
        )->execute([
            $d['nome_destinatario'],
            $d['cep'],
            $d['logradouro'],
            $d['numero'],
            $d['complemento'] ?? null,
            $d['bairro'],
            $d['cidade'],
            $d['estado'],
            $d['telefone_contato'] ?? $d['telefone'] ?? null,
            $d['apelido'] ?? null,
            $d['observacao_entrega'] ?? null,
            $id,
        ]);
    }

    /**
     * Torna um endereço o principal do cliente.
     */
    public function tornarPrincipal(int $id, int $clienteId): void {
        $this->rebaixarPrincipal($clienteId, $id);
        $this->db->prepare(
            "UPDATE enderecos SET principal = 1 WHERE id = ? AND cliente_id = ?"
        )->execute([$id, $clienteId]);
    }

    /**
     * Remove endereço (só se pertencer ao cliente).
     */
    public function excluir(int $id, int $clienteId): bool {
        $stmt = $this->db->prepare(
            "DELETE FROM enderecos WHERE id = ? AND cliente_id = ?"
        );
        $stmt->execute([$id, $clienteId]);
        return $stmt->rowCount() > 0;
    }

    // ════════════════════════════════════════════════════
    // HELPERS PRIVADOS
    // ════════════════════════════════════════════════════

    /**
     * Remove o status principal de todos os endereços do cliente,
     * exceto (opcionalmente) um endereço específico.
     */
    private function rebaixarPrincipal(int $clienteId, int $excetoPorId = 0): void {
        if ($excetoPorId) {
            $this->db->prepare(
                "UPDATE enderecos SET principal = 0
                 WHERE cliente_id = ? AND id != ?"
            )->execute([$clienteId, $excetoPorId]);
        } else {
            $this->db->prepare(
                "UPDATE enderecos SET principal = 0 WHERE cliente_id = ?"
            )->execute([$clienteId]);
        }
    }

    /**
     * Normaliza e sanitiza os dados de entrada.
     */
    private function sanitize(array $d): array {
        $d['cep']    = preg_replace('/\D/', '', (string)($d['cep'] ?? ''));
        $d['estado'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($d['estado'] ?? '')), 0, 2));

        // Remove espaços extras em todos os campos de texto
        foreach (['nome_destinatario','logradouro','numero','complemento',
                  'bairro','cidade','apelido','observacao_entrega'] as $key) {
            if (isset($d[$key])) {
                $d[$key] = mb_substr(trim((string)$d[$key]), 0, 200) ?: null;
            }
        }

        // Normaliza telefone (aceita 'telefone' OU 'telefone_contato')
        foreach (['telefone_contato','telefone'] as $key) {
            if (!empty($d[$key])) {
                $d['telefone_contato'] = preg_replace('/\D/', '', (string)$d[$key]);
                break;
            }
        }

        return $d;
    }
}