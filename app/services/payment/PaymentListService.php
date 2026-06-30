<?php
declare(strict_types=1);

/**
 * PaymentListService
 *
 * Listagem com filtros e paginação para pgto_transacoes e pgto_webhook_log.
 *
 * Cada método aceita:
 *   - filtros (allowlist explícita — qualquer chave fora dela é IGNORADA,
 *              isso é por segurança contra injection)
 *   - página (1-indexed) e itens_por_pagina (cap em 100)
 *
 * Retorna:
 *   [ 'itens' => [...], 'total' => int, 'pagina' => int, 'paginas' => int ]
 *
 * Performance: usa LIMIT/OFFSET com índices já criados (idx_metodo_status,
 * idx_criado_em, idx_recebido_em). Pra dataset > 1M linhas, considerar
 * keyset pagination no futuro.
 */
class PaymentListService
{
    /** @var PDO */
    private $db;

    /** Filtros válidos pra transações (allowlist) */
    const FILTROS_TX_PERMITIDOS = [
        'status', 'metodo', 'gateway_codigo', 'provedor_real',
        'order_id_loja', 'charge_id', 'pedido_id',
        'data_de', 'data_ate', 'valor_min', 'valor_max',
        'busca',
    ];

    /** Filtros válidos pra webhooks */
    const FILTROS_WH_PERMITIDOS = [
        'tipo', 'processado', 'assinatura_valida',
        'charge_id', 'event_id',
        'data_de', 'data_ate',
    ];

    const ITENS_PADRAO = 25;
    const ITENS_MAX    = 100;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // =================================================================
    // TRANSAÇÕES
    // =================================================================

    /**
     * @param array $filtros Veja FILTROS_TX_PERMITIDOS
     * @param int   $pagina  1-indexed
     */
    public function listarTransacoes(array $filtros = [], int $pagina = 1, int $porPagina = self::ITENS_PADRAO): array
    {
        $pagina    = max(1, $pagina);
        $porPagina = max(1, min(self::ITENS_MAX, $porPagina));
        $offset    = ($pagina - 1) * $porPagina;

        [$where, $params] = $this->montarWhereTransacoes($filtros);

        // Total
        $sqlCount = "SELECT COUNT(*) FROM pgto_transacoes t {$where}";
        $stmt = $this->db->prepare($sqlCount);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        // Itens da página
        $sql = "
            SELECT t.id, t.charge_id, t.order_id_loja, t.pedido_id, t.cliente_id,
                   t.valor_centavos, t.metodo, t.parcelas, t.status,
                   t.provedor_real, t.declined_code, t.declined_message,
                   t.criado_em, t.pago_em, t.atualizado_em,
                   g.codigo AS gateway_codigo, g.nome AS gateway_nome
              FROM pgto_transacoes t
              JOIN pgto_gateways g ON g.id = t.gateway_id
              {$where}
              ORDER BY t.id DESC
              LIMIT :lim OFFSET :off
        ";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,    PDO::PARAM_INT);
        $stmt->execute();
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'itens'      => $itens,
            'total'      => $total,
            'pagina'     => $pagina,
            'paginas'    => (int) ceil($total / $porPagina),
            'por_pagina' => $porPagina,
        ];
    }

    /**
     * Detalhe (drill-down) de uma transação com tudo que precisa pra view.
     */
    public function detalheTransacao(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, g.codigo AS gateway_codigo, g.nome AS gateway_nome
               FROM pgto_transacoes t
               JOIN pgto_gateways g ON g.id = t.gateway_id
              WHERE t.id = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$tx) return null;

        // Decoda os JSONs pra view não precisar
        foreach (['raw_request', 'raw_response'] as $jsonField) {
            if (!empty($tx[$jsonField])) {
                $decoded = json_decode($tx[$jsonField], true);
                $tx[$jsonField . '_decoded'] = is_array($decoded) ? $decoded : null;
            } else {
                $tx[$jsonField . '_decoded'] = null;
            }
        }

        // Webhooks relacionados
        if (!empty($tx['charge_id'])) {
            $stmt2 = $this->db->prepare(
                "SELECT id, event_id, tipo, processado, erro, tentativas,
                        assinatura_valida, recebido_em, processado_em
                   FROM pgto_webhook_log
                  WHERE charge_id = :cid
                  ORDER BY id DESC"
            );
            $stmt2->execute([':cid' => $tx['charge_id']]);
            $tx['webhooks'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $tx['webhooks'] = [];
        }

        return $tx;
    }

    private function montarWhereTransacoes(array $filtros): array
    {
        $where  = [];
        $params = [];

        // Allowlist
        $filtros = array_intersect_key($filtros, array_flip(self::FILTROS_TX_PERMITIDOS));

        if (!empty($filtros['status'])) {
            $where[] = 't.status = :status';
            $params[':status'] = (string) $filtros['status'];
        }
        if (!empty($filtros['metodo'])) {
            $where[] = 't.metodo = :metodo';
            $params[':metodo'] = (string) $filtros['metodo'];
        }
        if (!empty($filtros['gateway_codigo'])) {
            $where[] = 'g.codigo = :gw';
            $params[':gw'] = (string) $filtros['gateway_codigo'];
        }
        if (!empty($filtros['provedor_real'])) {
            $where[] = 't.provedor_real = :pr';
            $params[':pr'] = (string) $filtros['provedor_real'];
        }
        if (!empty($filtros['order_id_loja'])) {
            $where[] = 't.order_id_loja = :oid';
            $params[':oid'] = (string) $filtros['order_id_loja'];
        }
        if (!empty($filtros['charge_id'])) {
            $where[] = 't.charge_id = :cid';
            $params[':cid'] = (string) $filtros['charge_id'];
        }
        if (!empty($filtros['pedido_id'])) {
            $where[] = 't.pedido_id = :ped';
            $params[':ped'] = (int) $filtros['pedido_id'];
        }
        if (!empty($filtros['data_de'])) {
            $where[] = 't.criado_em >= :dde';
            $params[':dde'] = $this->normalizarData($filtros['data_de'], '00:00:00');
        }
        if (!empty($filtros['data_ate'])) {
            $where[] = 't.criado_em <= :date';
            $params[':date'] = $this->normalizarData($filtros['data_ate'], '23:59:59');
        }
        if (isset($filtros['valor_min']) && $filtros['valor_min'] !== '') {
            $where[] = 't.valor_centavos >= :vmin';
            $params[':vmin'] = (int) round(((float) $filtros['valor_min']) * 100);
        }
        if (isset($filtros['valor_max']) && $filtros['valor_max'] !== '') {
            $where[] = 't.valor_centavos <= :vmax';
            $params[':vmax'] = (int) round(((float) $filtros['valor_max']) * 100);
        }
        if (!empty($filtros['busca'])) {
            // Busca em order_id_loja OU charge_id OU provedor_real
            $where[] = '(t.order_id_loja LIKE :busca OR t.charge_id LIKE :busca OR t.provedor_real LIKE :busca)';
            $params[':busca'] = '%' . str_replace(['%','_'], ['\%','\_'], $filtros['busca']) . '%';
        }

        $clauseWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        return [$clauseWhere, $params];
    }

    // =================================================================
    // WEBHOOKS
    // =================================================================

    public function listarWebhooks(array $filtros = [], int $pagina = 1, int $porPagina = self::ITENS_PADRAO): array
    {
        $pagina    = max(1, $pagina);
        $porPagina = max(1, min(self::ITENS_MAX, $porPagina));
        $offset    = ($pagina - 1) * $porPagina;

        [$where, $params] = $this->montarWhereWebhooks($filtros);

        $sqlCount = "SELECT COUNT(*) FROM pgto_webhook_log {$where}";
        $stmt = $this->db->prepare($sqlCount);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        $sql = "
            SELECT id, event_id, tipo, charge_id, processado, erro,
                   tentativas, assinatura_valida, ip_origem,
                   recebido_em, processado_em
              FROM pgto_webhook_log
              {$where}
              ORDER BY id DESC
              LIMIT :lim OFFSET :off
        ";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,    PDO::PARAM_INT);
        $stmt->execute();

        return [
            'itens'      => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total'      => $total,
            'pagina'     => $pagina,
            'paginas'    => (int) ceil($total / $porPagina),
            'por_pagina' => $porPagina,
        ];
    }

    public function detalheWebhook(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM pgto_webhook_log WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) return null;

        $payload = json_decode($row['payload'] ?? '', true);
        $row['payload_decoded'] = is_array($payload) ? $payload : null;

        return $row;
    }

    private function montarWhereWebhooks(array $filtros): array
    {
        $where  = [];
        $params = [];

        $filtros = array_intersect_key($filtros, array_flip(self::FILTROS_WH_PERMITIDOS));

        if (!empty($filtros['tipo'])) {
            $where[] = 'tipo = :tipo';
            $params[':tipo'] = (string) $filtros['tipo'];
        }
        if (isset($filtros['processado']) && $filtros['processado'] !== '') {
            $where[] = 'processado = :p';
            $params[':p'] = ((int) $filtros['processado']) ? 1 : 0;
        }
        if (isset($filtros['assinatura_valida']) && $filtros['assinatura_valida'] !== '') {
            $where[] = 'assinatura_valida = :av';
            $params[':av'] = ((int) $filtros['assinatura_valida']) ? 1 : 0;
        }
        if (!empty($filtros['charge_id'])) {
            $where[] = 'charge_id = :cid';
            $params[':cid'] = (string) $filtros['charge_id'];
        }
        if (!empty($filtros['event_id'])) {
            $where[] = 'event_id = :eid';
            $params[':eid'] = (string) $filtros['event_id'];
        }
        if (!empty($filtros['data_de'])) {
            $where[] = 'recebido_em >= :dde';
            $params[':dde'] = $this->normalizarData($filtros['data_de'], '00:00:00');
        }
        if (!empty($filtros['data_ate'])) {
            $where[] = 'recebido_em <= :date';
            $params[':date'] = $this->normalizarData($filtros['data_ate'], '23:59:59');
        }

        $clauseWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        return [$clauseWhere, $params];
    }

    // =================================================================
    // HELPERS
    // =================================================================
    private function normalizarData(string $data, string $hora): string
    {
        // Aceita YYYY-MM-DD ou DD/MM/YYYY
        $data = trim($data);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $data, $m)) {
            $data = "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            $data = date('Y-m-d');
        }
        return $data . ' ' . $hora;
    }
}
