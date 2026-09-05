<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/CartaoSalvo.php
//
// Um cartão salvo é UMA linha em `cartoes_salvos` (final, bandeira, titular)
// e N linhas em `cartoes_salvos_adquirentes` — uma por adquirente onde o
// navegador conseguiu tokenizá-lo ao salvar. Token só vale em quem o emitiu;
// por isso a referência mora ao lado da adquirente, não no cartão.
//
// As colunas `gateway_id / customer_ref / card_ref / token` de `cartoes_salvos`
// são LEGADO: continuam gravadas com a primeira adquirente para quem ainda
// lê de lá, mas a fonte de verdade é a tabela filha.
// ════════════════════════════════════════════════════════

class CartaoSalvo {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ── Leitura ────────────────────────────────────────

    /**
     * Cartões do cliente, cada um com a lista de adquirentes ATIVAS onde ele
     * existe (`adquirentes` => ['mercadopago', 'cielo']). Cartão sem nenhuma
     * adquirente ativa continua na lista — a tela decide o que mostrar —, mas
     * o checkout não consegue cobrá-lo, e é isso que `cobravel` diz.
     *
     * NÃO lista o cartão temporário — aquele em que o cliente desmarcou
     * "salvar para as próximas compras". Ele existe só enquanto a compra
     * acontece; mostrá-lo na conta seria contradizer a escolha dele.
     */
    public function listarPorCliente(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT cs.id, cs.gateway_id, cs.card_ref, cs.bandeira, cs.ultimos_4,
                    cs.nome_titular, cs.validade, cs.principal, cs.apelido,
                    GROUP_CONCAT(DISTINCT g.codigo ORDER BY g.codigo) AS adquirentes
               FROM cartoes_salvos cs
          LEFT JOIN cartoes_salvos_adquirentes a
                 ON a.cartao_id = cs.id AND a.ativo = 1
          LEFT JOIN pgto_gateways g
                 ON g.id = a.gateway_id AND g.ativo = 1
              WHERE cs.cliente_id = :cid AND cs.ativo = 1 AND cs.temporario = 0
           GROUP BY cs.id
           ORDER BY cs.principal DESC, cs.id DESC"
        );
        $stmt->execute([':cid' => $clienteId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $c['adquirentes'] = $c['adquirentes'] !== null && $c['adquirentes'] !== ''
                ? explode(',', (string) $c['adquirentes'])
                : [];
            $c['cobravel'] = $c['adquirentes'] !== [];
            $out[] = $c;
        }
        return $out;
    }

    public function findOwned(int $id, int $clienteId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM cartoes_salvos
              WHERE id = :id AND cliente_id = :cid AND ativo = 1 LIMIT 1"
        );
        $stmt->execute([':id' => $id, ':cid' => $clienteId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Referências do cartão por adquirente, só das adquirentes ativas.
     *
     * @return array<string, array{gateway_id:int, customer_ref:?string, card_ref:string}>
     *         indexado pelo código da adquirente
     */
    public function refsDoCartao(int $cartaoId, int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT g.codigo, a.gateway_id, a.customer_ref, a.card_ref
               FROM cartoes_salvos_adquirentes a
               JOIN cartoes_salvos cs ON cs.id = a.cartao_id
               JOIN pgto_gateways g   ON g.id = a.gateway_id
              WHERE a.cartao_id = :id AND cs.cliente_id = :cid
                AND a.ativo = 1 AND cs.ativo = 1 AND g.ativo = 1"
        );
        $stmt->execute([':id' => $cartaoId, ':cid' => $clienteId]);

        $refs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $refs[(string) $r['codigo']] = [
                'gateway_id'   => (int) $r['gateway_id'],
                'customer_ref' => $r['customer_ref'] !== null ? (string) $r['customer_ref'] : null,
                'card_ref'     => (string) $r['card_ref'],
            ];
        }
        return $refs;
    }

    /** Todas as referências (inclusive de adquirente inativa) — para remoção. */
    public function todasAsRefs(int $cartaoId): array {
        $stmt = $this->db->prepare(
            "SELECT g.codigo, a.gateway_id, a.customer_ref, a.card_ref
               FROM cartoes_salvos_adquirentes a
               JOIN pgto_gateways g ON g.id = a.gateway_id
              WHERE a.cartao_id = :id"
        );
        $stmt->execute([':id' => $cartaoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Escrita ────────────────────────────────────────

    /**
     * Salva o cartão. Retorna o ID inserido.
     *
     * Campos obrigatórios: cliente_id, token, bandeira, ultimos_4
     * Campos opcionais:    nome_titular, validade, apelido, principal,
     *                      temporario, gateway_id, customer_ref, card_ref
     *                      (legado da 1ª adquirente),
     *                      adquirentes => [[gateway_id, customer_ref, card_ref], ...]
     *
     * `temporario` = 1 é o cartão que o cliente NÃO mandou salvar. A linha
     * nasce assim mesmo porque a compra precisa dela: é por `cartao_id` que
     * as referências de cada adquirente ficam penduradas. Ela some depois,
     * junto com os cofres.
     *
     * nome_titular e validade são opcionais porque com hosted fields esses
     * dados não saem do iframe. Quando a adquirente devolve (o Mercado Pago
     * devolve), vêm preenchidos; quando não, ficam NULOS — nunca um valor
     * inventado que a tela exibiria como se fosse do cartão.
     */
    public function salvar(array $data): int {
        $clienteId  = (int) $data['cliente_id'];
        $temporario = !empty($data['temporario']) ? 1 : 0;

        // Cartão que vai ser apagado no fim da compra não pode virar o padrão
        // do cliente: no dia seguinte o padrão apontaria para nada.
        $principal = (!$temporario && !empty($data['principal'])) ? 1 : 0;

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

        $this->db->prepare(
            "INSERT INTO cartoes_salvos
               (cliente_id, gateway_id, token, customer_ref, card_ref,
                bandeira, ultimos_4, nome_titular, apelido, validade, principal,
                temporario)
             VALUES
               (:cliente_id, :gateway_id, :token, :customer_ref, :card_ref,
                :bandeira, :ultimos_4, :nome_titular, :apelido, :validade, :principal,
                :temporario)"
        )->execute([
            ':cliente_id'   => $clienteId,
            // Legado: a PRIMEIRA adquirente. A fonte de verdade é a filha.
            ':gateway_id'   => !empty($data['gateway_id']) ? (int) $data['gateway_id'] : null,
            ':customer_ref' => !empty($data['customer_ref'])
                ? SecurityHelper::sanitizeString((string) $data['customer_ref']) : null,
            ':card_ref'     => !empty($data['card_ref'])
                ? SecurityHelper::sanitizeString((string) $data['card_ref']) : null,
            ':token'        => SecurityHelper::sanitizeString((string) $data['token']),
            ':bandeira'     => SecurityHelper::sanitizeString(strtolower((string) $data['bandeira'])),
            ':ultimos_4'    => $ultimos4,
            // NULO QUANDO NAO SE SABE, nunca um placeholder.
            ':nome_titular' => !empty($data['nome_titular'])
                ? SecurityHelper::sanitizeString(
                      mb_substr(strtoupper((string) $data['nome_titular']), 0, 120)
                  )
                : null,
            ':apelido'      => !empty($data['apelido'])
                ? SecurityHelper::sanitizeString((string) $data['apelido'])
                : null,
            ':validade'     => !empty($data['validade'])
                ? SecurityHelper::sanitizeString(mb_substr((string) $data['validade'], 0, 5))
                : null,
            ':principal'    => $principal,
            ':temporario'   => $temporario,
        ]);

        $cartaoId = (int) $this->db->lastInsertId();

        foreach ((array) ($data['adquirentes'] ?? []) as $a) {
            $this->vincularAdquirente(
                $cartaoId,
                (int) ($a['gateway_id'] ?? 0),
                isset($a['customer_ref']) ? (string) $a['customer_ref'] : null,
                (string) ($a['card_ref'] ?? '')
            );
        }

        return $cartaoId;
    }

    /**
     * Liga o cartão a uma adquirente. Idempotente: repetir atualiza a
     * referência em vez de duplicar — o `uk_cartao_gateway` garante.
     */
    public function vincularAdquirente(int $cartaoId, int $gatewayId, ?string $customerRef, string $cardRef): void {
        if ($cartaoId <= 0 || $gatewayId <= 0 || trim($cardRef) === '') return;

        $this->db->prepare(
            "INSERT INTO cartoes_salvos_adquirentes (cartao_id, gateway_id, customer_ref, card_ref, ativo)
             VALUES (:c, :g, :cust, :card, 1)
             ON DUPLICATE KEY UPDATE
                customer_ref = VALUES(customer_ref), card_ref = VALUES(card_ref), ativo = 1"
        )->execute([
            ':c'    => $cartaoId,
            ':g'    => $gatewayId,
            ':cust' => $customerRef !== null && $customerRef !== ''
                ? SecurityHelper::sanitizeString($customerRef) : null,
            ':card' => SecurityHelper::sanitizeString($cardRef),
        ]);
    }

    /**
     * Promove um cartão temporário a permanente.
     *
     * É o "mudei de ideia": o cliente desmarcou salvar na tela do cartão e
     * marcou de novo no resumo, antes de finalizar. Nada muda nas adquirentes
     * — o cartão já está nos cofres —, só o destino da linha.
     */
    public function marcarPermanente(int $cartaoId, int $clienteId): bool {
        $stmt = $this->db->prepare(
            "UPDATE cartoes_salvos SET temporario = 0
              WHERE id = :id AND cliente_id = :cid AND temporario = 1"
        );
        $stmt->execute([':id' => $cartaoId, ':cid' => $clienteId]);
        return $stmt->rowCount() > 0;
    }

    /** O cartão é temporário? (usado para decidir a limpeza pós-compra) */
    public function ehTemporario(int $cartaoId, int $clienteId): bool {
        $stmt = $this->db->prepare(
            "SELECT temporario FROM cartoes_salvos
              WHERE id = :id AND cliente_id = :cid LIMIT 1"
        );
        $stmt->execute([':id' => $cartaoId, ':cid' => $clienteId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * Temporários que passaram da janela — o que o cron recolhe.
     *
     * A limpeza normal acontece no fim da compra. Esta é a rede embaixo: o
     * cliente fechou o navegador no desafio 3DS, o PHP morreu no meio, a
     * compra virou pendente e ninguém voltou. Sem ela o cartão fica nos
     * cofres das adquirentes para sempre, contra a vontade de quem digitou.
     *
     * @param int $minutos idade mínima. Tem de ser maior que a validade de um
     *                     desafio 3DS, senão o cron apaga o cartão de uma
     *                     compra que ainda está acontecendo.
     */
    public function temporariosExpirados(int $minutos = 60, int $limite = 200): array {
        $minutos = max(15, $minutos);
        $limite  = max(1, min(1000, $limite));

        $stmt = $this->db->prepare(
            "SELECT id, cliente_id, criado_em
               FROM cartoes_salvos
              WHERE temporario = 1
                AND criado_em < (NOW() - INTERVAL :min MINUTE)
           ORDER BY criado_em
              LIMIT {$limite}"
        );
        $stmt->bindValue(':min', $minutos, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
