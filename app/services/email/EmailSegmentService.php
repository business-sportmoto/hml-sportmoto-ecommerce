<?php
/**
 * app/services/email/EmailSegmentService.php
 *
 * Constrói queries SQL parametrizadas a partir de regras JSON.
 * Cada regra é mapeada para um fragmento SQL pré-definido — SEM SQL livre.
 *
 * Formato de regras (esperado em $regras):
 *  [
 *    'match' => 'AND'|'OR',   // como combinar
 *    'regras' => [
 *      ['campo' => 'newsletter_ativa',     'valor' => true],
 *      ['campo' => 'comprou_ultimos_dias', 'valor' => 30],
 *      ['campo' => 'comprou_produto_id',   'valor' => 42],
 *      ...
 *    ]
 *  ]
 *
 * Saída: array com SQL e bindings para usar em email_contatos.
 *
 * As consultas sempre retornam IDs de email_contatos elegíveis (status='ativo').
 */
class EmailSegmentService
{
    /** @var PDO */
    private $db;
    /** @var array */
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->config = require dirname(__DIR__, 2) . '/../config/email-marketing.php';
    }

    public function build(array $regras)
    {
        $whitelist = array_flip($this->config['segment_whitelist'] ?? []);
        $match = strtoupper($regras['match'] ?? 'AND');
        if (!in_array($match, ['AND','OR'], true)) $match = 'AND';

        $clauses = [];
        $params  = [];
        $i = 0;

        foreach (($regras['regras'] ?? []) as $r) {
            $campo = $r['campo'] ?? '';
            if (!isset($whitelist[$campo])) continue;
            $val = $r['valor'] ?? null;
            $i++;

            switch ($campo) {
                case 'newsletter_ativa':
                    if ($val) {
                        $clauses[] = "EXISTS (
                            SELECT 1 FROM newsletter n
                            WHERE n.email = ec.email AND n.ativo = 1
                        )";
                    } else {
                        $clauses[] = "NOT EXISTS (
                            SELECT 1 FROM newsletter n
                            WHERE n.email = ec.email AND n.ativo = 1
                        )";
                    }
                    break;

                case 'email_verificado':
                    $clauses[] = $val ? "ec.email_verificado = 1" : "ec.email_verificado = 0";
                    break;

                case 'comprou_ultimos_dias':
                    $params[":dias_$i"] = max(1, (int)$val);
                    $clauses[] = "EXISTS (
                        SELECT 1 FROM pedidos p
                        WHERE p.cliente_id = ec.cliente_id
                          AND p.status IN ('pago','enviado','entregue','aprovado','faturado')
                          AND p.criado_em >= (NOW() - INTERVAL :dias_$i DAY)
                    )";
                    break;

                case 'nao_compra_ha_dias':
                    $params[":ndias_$i"] = max(1, (int)$val);
                    $clauses[] = "NOT EXISTS (
                        SELECT 1 FROM pedidos p
                        WHERE p.cliente_id = ec.cliente_id
                          AND p.criado_em >= (NOW() - INTERVAL :ndias_$i DAY)
                    )";
                    break;

                case 'comprou_produto_id':
                    $params[":pid_$i"] = (int)$val;
                    $clauses[] = "EXISTS (
                        SELECT 1 FROM pedidos p
                          JOIN pedido_itens pi ON pi.pedido_id = p.id
                        WHERE p.cliente_id = ec.cliente_id
                          AND pi.produto_id = :pid_$i
                    )";
                    break;

                case 'comprou_categoria_id':
                    $params[":cat_$i"] = (int)$val;
                    $clauses[] = "EXISTS (
                        SELECT 1 FROM pedidos p
                          JOIN pedido_itens pi   ON pi.pedido_id = p.id
                          JOIN produto_categorias pc ON pc.produto_id = pi.produto_id
                        WHERE p.cliente_id = ec.cliente_id
                          AND pc.categoria_id = :cat_$i
                    )";
                    break;

                case 'comprou_marca_id':
                    $params[":mrc_$i"] = (int)$val;
                    $clauses[] = "EXISTS (
                        SELECT 1 FROM pedidos p
                          JOIN pedido_itens pi ON pi.pedido_id = p.id
                          JOIN produtos pr     ON pr.id = pi.produto_id
                        WHERE p.cliente_id = ec.cliente_id
                          AND pr.marca_id = :mrc_$i
                    )";
                    break;

                case 'wishlist_produto_id':
                    $params[":wpid_$i"] = (int)$val;
                    $clauses[] = "EXISTS (
                        SELECT 1 FROM wishlist w
                          JOIN wishlist_itens wi ON wi.wishlist_id = w.id
                        WHERE w.cliente_id = ec.cliente_id
                          AND wi.produto_id = :wpid_$i
                    )";
                    break;

                // CORRIGIDO 08/09/2026 — estas duas consultavam h.usuario_id,
                // h.produto_id e h.categoria_id, que NÃO EXISTEM em
                // historico_navegacao. Ela guarda `tipo` ('produto',
                // 'categoria', 'marca', 'busca', 'clip') + `referencia_id`.
                // Qualquer segmento que usasse estes campos estourava com
                // "Unknown column 'h.usuario_id'" — nunca funcionaram.
                case 'visualizou_produto_id':
                    $params[":vpid_$i"] = (int)$val;
                    $clauses[] = "EXISTS (
                        SELECT 1 FROM historico_navegacao h
                        WHERE h.cliente_id = ec.cliente_id
                          AND h.tipo = 'produto'
                          AND h.referencia_id = :vpid_$i
                    )";
                    break;

                case 'visualizou_categoria_id':
                    // Dois placeholders para o MESMO valor: o PDO do projeto roda
                    // com EMULATE_PREPARES = false, e aí reusar `:vcat_$i` nas
                    // duas pernas do OR dá "Invalid parameter number".
                    $params[":vcat_$i"]  = (int)$val;
                    $params[":vcatp_$i"] = (int)$val;
                    // Duas formas de "viu a categoria": abrir a página dela, ou
                    // abrir um produto que pertence a ela. Quem monta a campanha
                    // quer as duas — a segunda é, de longe, a mais comum.
                    $clauses[] = "EXISTS (
                        SELECT 1 FROM historico_navegacao h
                        WHERE h.cliente_id = ec.cliente_id
                          AND (
                                (h.tipo = 'categoria' AND h.referencia_id = :vcat_$i)
                             OR (h.tipo = 'produto' AND EXISTS (
                                    SELECT 1 FROM produtos p
                                    WHERE p.id = h.referencia_id
                                      AND p.categoria_id = :vcatp_$i
                                ))
                          )
                    )";
                    break;

                // Perfil inferido pela Central de IA (Fase 3). Guardado com
                // EXISTS na tabela do módulo de IA, não com JOIN: se a Central
                // não estiver instalada, a regra tem de casar ZERO — e não
                // derrubar a montagem do segmento inteiro com "table doesn't
                // exist". O `intencaoDisponivel()` decide isso uma vez.
                case 'intencao':
                    if (!$this->intencaoDisponivel()) {
                        $clauses[] = '1 = 0';
                        break;
                    }
                    $params[":int_$i"] = mb_substr((string)$val, 0, 60);
                    $clauses[] = "EXISTS (
                        SELECT 1 FROM ia_cliente_intencao ici
                        WHERE ici.cliente_id = ec.cliente_id
                          AND ici.segmento = :int_$i
                    )";
                    break;

                case 'genero':
                    $gen = in_array($val, ['M','F','Outro','NaoInformado'], true) ? $val : 'NaoInformado';
                    $params[":gen_$i"] = $gen;
                    $clauses[] = "ec.genero = :gen_$i";
                    break;

                case 'mes_aniversario':
                    $params[":mes_$i"] = max(1, min(12, (int)$val));
                    $clauses[] = "MONTH(ec.nascimento) = :mes_$i";
                    break;

                case 'valor_comprado_min':
                    $params[":vmin_$i"] = (float)$val;
                    $clauses[] = "(SELECT COALESCE(SUM(p.total),0) FROM pedidos p
                        WHERE p.cliente_id = ec.cliente_id
                          AND p.status IN ('pago','enviado','entregue','aprovado','faturado')
                        ) >= :vmin_$i";
                    break;

                case 'valor_comprado_max':
                    $params[":vmax_$i"] = (float)$val;
                    $clauses[] = "(SELECT COALESCE(SUM(p.total),0) FROM pedidos p
                        WHERE p.cliente_id = ec.cliente_id
                          AND p.status IN ('pago','enviado','entregue','aprovado','faturado')
                        ) <= :vmax_$i";
                    break;

                case 'pedido_status':
                    $st = preg_replace('/[^a-z_]/i', '', (string)$val);
                    $params[":pst_$i"] = $st;
                    $clauses[] = "EXISTS (
                        SELECT 1 FROM pedidos p
                        WHERE p.cliente_id = ec.cliente_id AND p.status = :pst_$i
                    )";
                    break;

                case 'origem':
                    $or = in_array($val, ['cliente','newsletter','checkout','importacao','admin','api','legado'], true) ? $val : 'cliente';
                    $params[":org_$i"] = $or;
                    $clauses[] = "ec.origem = :org_$i";
                    break;

                case 'status_contato':
                    $stc = in_array($val, ['ativo','descadastrado','bounce','complaint','bloqueado','pendente'], true) ? $val : 'ativo';
                    $params[":stc_$i"] = $stc;
                    $clauses[] = "ec.status = :stc_$i";
                    break;
            }
        }

        $where = "ec.status = 'ativo'";
        if ($clauses) {
            $where .= ' AND (' . implode(' ' . $match . ' ', $clauses) . ')';
        }
        $where .= " AND NOT EXISTS (
            SELECT 1 FROM email_supressoes s
            WHERE s.email = ec.email
              AND (s.expira_em IS NULL OR s.expira_em > NOW())
        )";

        $sql = "SELECT ec.id, ec.email, ec.nome, ec.primeiro_nome, ec.token_descadastro
                FROM email_contatos ec
                WHERE $where";

        return ['sql' => $sql, 'params' => $params];
    }

    /** Conta o total estimado de um conjunto de regras. */
    /**
     * A Central de IA está instalada neste banco?
     *
     * O módulo de e-mail não pode depender dela para funcionar: em ambiente
     * sem a Central, a regra `intencao` casa zero em vez de estourar.
     * Memorizado porque `build()` roda por regra, e a consulta é sempre a mesma.
     */
    private ?bool $temIntencao = null;

    private function intencaoDisponivel(): bool
    {
        if ($this->temIntencao !== null) {
            return $this->temIntencao;
        }
        try {
            $st = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $st->execute(['ia_cliente_intencao']);
            $this->temIntencao = (int) $st->fetchColumn() > 0;
        } catch (\Throwable $e) {
            $this->temIntencao = false;
        }
        return $this->temIntencao;
    }

    public function estimar(array $regras)
    {
        $built = $this->build($regras);
        $sql = "SELECT COUNT(*) FROM (" . $built['sql'] . ") t";
        $st = $this->db->prepare($sql);
        $st->execute($built['params']);
        return (int)$st->fetchColumn();
    }

    /** Itera sobre os contatos do segmento em chunks (memory-safe). */
    public function each(array $regras, callable $cb, $chunkSize = 1000)
    {
        $built = $this->build($regras);
        $sql = $built['sql'] . " ORDER BY ec.id ASC LIMIT :lim OFFSET :off";
        $chunkSize = max(100, (int)$chunkSize);
        $offset = 0;

        do {
            $st = $this->db->prepare($sql);
            foreach ($built['params'] as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':lim', $chunkSize, PDO::PARAM_INT);
            $st->bindValue(':off', $offset, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;
            $cb($rows);
            $offset += count($rows);
        } while (count($rows) === $chunkSize);
    }
}
