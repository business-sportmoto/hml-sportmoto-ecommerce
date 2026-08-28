<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/CartaoSalvo.php
// ════════════════════════════════════════════════════════

class CartaoSalvo {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ── Leitura ────────────────────────────────────────

    public function listarPorCliente(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT id, gateway_id, card_ref, bandeira, ultimos_4,
                    nome_titular, validade, principal, apelido
               FROM cartoes_salvos
              WHERE cliente_id = :cid AND ativo = 1
              ORDER BY principal DESC, id DESC"
        );
        $stmt->execute([':cid' => $clienteId]);
        return $stmt->fetchAll();
    }

    public function findOwned(int $id, int $clienteId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM cartoes_salvos
              WHERE id = :id AND cliente_id = :cid AND ativo = 1 LIMIT 1"
        );
        $stmt->execute([':id' => $id, ':cid' => $clienteId]);
        return $stmt->fetch() ?: null;
    }

    // ── Escrita ────────────────────────────────────────

    /**
     * Salva cartão tokenizado. Retorna o ID inserido.
     *
     * Campos obrigatórios: cliente_id, token, bandeira, ultimos_4
     * Campos opcionais:    nome_titular, validade, apelido, principal
     *
     * nome_titular e validade são opcionais porque com hosted fields
     * esses dados não saem mais do iframe da Malga.
     */
    public function salvar(array $data): int {
        $clienteId = (int) $data['cliente_id'];
        $principal = !empty($data['principal']) ? 1 : 0;

        // Campos obrigatórios (apenas o que sempre temos)
        foreach (['token', 'bandeira', 'ultimos_4'] as $campo) {
            if (empty($data[$campo])) {
                throw new InvalidArgumentException("CartaoSalvo: campo obrigatório ausente: '{$campo}'");
            }
        }

        $ultimos4 = preg_replace('/\D/', '', (string) $data['ultimos_4']);
        if (strlen($ultimos4) !== 4) {
            throw new InvalidArgumentException('ultimos_4 deve ter exatamente 4 dígitos.');
        }

        if ($principal) {
            $this->rebaixarPrincipal($clienteId);
        }

        // Named parameters — impossível ter mismatch de count
        $this->db->prepare(
            "INSERT INTO cartoes_salvos
               (cliente_id, gateway_id, token, customer_ref, card_ref,
                bandeira, ultimos_4, nome_titular, apelido, validade, principal)
             VALUES
               (:cliente_id, :gateway_id, :token, :customer_ref, :card_ref,
                :bandeira, :ultimos_4, :nome_titular, :apelido, :validade, :principal)"
        )->execute([
            ':cliente_id'   => $clienteId,
            // De quem e o cartao. Sem isto ele seria apresentado a uma
            // adquirente que nao emitiu o token e recusado sem motivo claro.
            ':gateway_id'   => !empty($data['gateway_id']) ? (int) $data['gateway_id'] : null,
            // Ids permanentes na adquirente. No Mercado Pago sao ELES que
            // permitem reuso — o token da tokenizacao e de uso unico.
            ':customer_ref' => !empty($data['customer_ref'])
                ? SecurityHelper::sanitizeString((string) $data['customer_ref']) : null,
            ':card_ref'     => !empty($data['card_ref'])
                ? SecurityHelper::sanitizeString((string) $data['card_ref']) : null,
            ':token'        => SecurityHelper::sanitizeString((string) $data['token']),
            ':bandeira'     => SecurityHelper::sanitizeString(strtolower((string) $data['bandeira'])),
            ':ultimos_4'    => $ultimos4,
            ':nome_titular' => SecurityHelper::sanitizeString(
                mb_substr(strtoupper((string) ($data['nome_titular'] ?? 'Titular')), 0, 120)
            ),
            ':apelido'      => !empty($data['apelido'])
                ? SecurityHelper::sanitizeString((string) $data['apelido'])
                : null,
            ':validade'     => SecurityHelper::sanitizeString(
                (string) ($data['validade'] ?? '12/99')
            ),
            ':principal'    => $principal,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function desativar(int $id, int $clienteId): bool {
        $stmt = $this->db->prepare(
            "UPDATE cartoes_salvos SET ativo = 0
              WHERE id = :id AND cliente_id = :cid"
        );
        $stmt->execute([':id' => $id, ':cid' => $clienteId]);
        return $stmt->rowCount() > 0;
    }

    // ── Helpers ────────────────────────────────────────

    private function rebaixarPrincipal(int $clienteId): void {
        $this->db->prepare(
            "UPDATE cartoes_salvos SET principal = 0 WHERE cliente_id = :cid"
        )->execute([':cid' => $clienteId]);
    }

    public static function labelBandeira(string $bandeira): string {
        return match (strtolower($bandeira)) {
            'visa'       => 'Visa',
            'mastercard',
            'master'     => 'Mastercard',
            'elo'        => 'Elo',
            'amex'       => 'American Express',
            'hipercard',
            'hiper'      => 'Hipercard',
            'diners'     => 'Diners',
            default      => ucfirst($bandeira),
        };
    }
}