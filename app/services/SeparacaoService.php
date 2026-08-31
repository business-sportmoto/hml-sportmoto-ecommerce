<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/SeparacaoService.php
//
// Painel de separacao (checkout de expedicao).
//
// Cobre o caminho do pedido pago ate a etiqueta:
//   pagamento_aprovado -> imprime a lista de separacao -> em_separacao
//   -> confere os itens bipando o EAN -> emite a etiqueta (so com NF-e).
//
// ── Como o item chega na variacao ──────────────────────────────────────────
// pedido_itens.estoque_id esta vazio em toda a base (109 de 109) e
// pedido_itens.sku NAO guarda o codigo SKU: guarda o produto_skus.id, como
// numero em texto ("2", "4"). Confirmado em 86 de 86 itens numericos.
//
// Toda a resolucao item -> variacao/SKU/EAN passa por skuDoItem() e pelo JOIN
// de itensDoPedido(), para que essa indirecao viva num lugar so. Se um dia
// pedido_itens ganhar um FK de verdade, muda aqui e mais nada.
// ════════════════════════════════════════════════════════

class SeparacaoService
{
    /** Status de onde os pedidos entram no painel. */
    public const STATUS_ORIGEM = 'pagamento_aprovado';
    /** Status para onde vao quando a lista e impressa. */
    public const STATUS_SEPARACAO = 'em_separacao';

    private PDO $db;
    private AdminPedidoService $pedidos;

    public function __construct(?PDO $db = null, ?AdminPedidoService $pedidos = null)
    {
        $this->db      = $db ?? Database::getInstance()->getConnection();
        $this->pedidos = $pedidos ?? new AdminPedidoService();
    }

    /* =================================================================
       FILA
       ================================================================= */

    /**
     * Pedidos aguardando separacao, com o que a fila precisa mostrar:
     * quantos itens, se ja tem NF-e e se ja tem etiqueta.
     */
    public function fila(array $filtros = []): array
    {
        $where  = ['p.status_pedido = :st'];
        $params = [':st' => self::STATUS_ORIGEM];

        if (!empty($filtros['busca'])) {
            $where[] = '(p.codigo LIKE :q OR u.nome LIKE :q OR CAST(p.id AS CHAR) = :qid)';
            $params[':q']   = '%' . $filtros['busca'] . '%';
            $params[':qid'] = (string) $filtros['busca'];
        }

        $sql = "SELECT p.id, p.codigo, p.total, p.criado_em,
                       p.frete_servico, p.codigo_rastreio,
                       u.nome AS cliente_nome,
                       (SELECT COUNT(*)             FROM pedido_itens pi WHERE pi.pedido_id = p.id) AS itens_linhas,
                       (SELECT COALESCE(SUM(pi.quantidade),0) FROM pedido_itens pi WHERE pi.pedido_id = p.id) AS itens_pecas,
                       nf.numero AS nfe_numero,
                       (SELECT e.id FROM log_etiquetas e
                         WHERE e.pedido_id = p.id AND e.status <> 'cancelada'
                         ORDER BY e.id DESC LIMIT 1) AS etiqueta_id
                  FROM pedidos p
             LEFT JOIN clientes c    ON c.id = p.cliente_id
             LEFT JOIN usuarios u    ON u.id = c.usuario_id
             LEFT JOIN pedidos_nfe nf ON nf.pedido_id = p.id
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY p.criado_em ASC";

        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /* =================================================================
       IMPRESSAO
       ================================================================= */

    /**
     * Monta os dados de impressao de um ou mais pedidos.
     * Ids inexistentes ou fora da fila sao ignorados em silencio.
     *
     * @param int[] $ids
     */
    public function paraImpressao(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];

        $in = implode(',', $ids);
        $st = $this->db->query(
            "SELECT p.id, p.codigo, p.total, p.subtotal, p.frete, p.criado_em,
                    p.frete_servico, p.observacao_cliente, p.observacao_interna,
                    u.nome  AS cliente_nome,
                    e.nome_destinatario, e.logradouro, e.numero, e.complemento,
                    e.bairro, e.cidade, e.estado, e.cep, e.observacao_entrega
               FROM pedidos p
          LEFT JOIN clientes c   ON c.id = p.cliente_id
          LEFT JOIN usuarios u   ON u.id = c.usuario_id
          LEFT JOIN enderecos e  ON e.id = p.endereco_entrega_id
              WHERE p.id IN ($in)
           ORDER BY FIELD(p.id, $in)"
        );

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $p['itens'] = $this->itensDoPedido((int) $p['id']);
            $out[] = $p;
        }
        return $out;
    }

    /**
     * Marca como "em separacao" os pedidos que ainda estao na fila.
     *
     * Passa por AdminPedidoService::mudarStatus() de proposito: e ele que grava
     * historico, respeita as flags do status (reserva de estoque, bloqueio de
     * edicao) e decide notificacao. Duplicar o UPDATE aqui furaria tudo isso.
     *
     * @param int[] $ids
     */
    public function marcarEmSeparacao(array $ids, int $adminId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return ['ok' => false, 'msg' => 'Nenhum pedido informado.'];

        $movidos = [];
        $ignorados = [];
        foreach ($ids as $id) {
            $atual = $this->statusDoPedido($id);
            if ($atual === null)                     { $ignorados[$id] = 'não encontrado'; continue; }
            if ($atual === self::STATUS_SEPARACAO)   { $movidos[] = $id; continue; }   // ja estava
            if ($atual !== self::STATUS_ORIGEM)      { $ignorados[$id] = "status '{$atual}'"; continue; }

            $r = $this->pedidos->mudarStatus($id, self::STATUS_SEPARACAO, 'Lista de separação impressa.', $adminId, false);
            if (!empty($r['ok'])) $movidos[] = $id;
            else                  $ignorados[$id] = $r['msg'] ?? 'falha ao mudar status';
        }

        return ['ok' => true, 'movidos' => $movidos, 'ignorados' => $ignorados];
    }

    /* =================================================================
       CONFERENCIA
       ================================================================= */

    /** Pedido completo para a tela de conferencia. */
    public function paraConferencia(int $pedidoId): ?array
    {
        $st = $this->db->prepare(
            "SELECT p.*, u.nome AS cliente_nome, u.email AS cliente_email,
                    c.cpf AS cliente_cpf, c.telefone AS cliente_telefone,
                    e.nome_destinatario, e.logradouro, e.numero, e.complemento,
                    e.bairro, e.cidade, e.estado, e.cep,
                    nf.numero AS nfe_numero, nf.serie AS nfe_serie,
                    nf.chaveAcesso AS nfe_chave, nf.linkDanfe AS nfe_danfe,
                    nf.dataEmissao AS nfe_data
               FROM pedidos p
          LEFT JOIN clientes c    ON c.id = p.cliente_id
          LEFT JOIN usuarios u    ON u.id = c.usuario_id
          LEFT JOIN enderecos e   ON e.id = p.endereco_entrega_id
          LEFT JOIN pedidos_nfe nf ON nf.pedido_id = p.id
              WHERE p.id = ? LIMIT 1"
        );
        $st->execute([$pedidoId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return null;

        $p['itens']    = $this->itensDoPedido($pedidoId);
        $p['etiqueta'] = $this->etiquetaDoPedido($pedidoId);
        $p['nfe_ok']   = $this->temNfe($p);
        return $p;
    }

    /**
     * Resolve um codigo bipado dentro do pedido.
     *
     * Aceita o EAN da variacao e tambem o SKU: o operador digita o SKU quando o
     * item nao tem codigo de barras impresso, e isso e comum aqui (13 de 31
     * itens da fila estao sem EAN cadastrado).
     *
     * Devolve o item que casou — quem conta as pecas conferidas e o front, que
     * conhece o que ja foi bipado na sessao de conferencia.
     */
    public function resolverCodigo(int $pedidoId, string $codigo): array
    {
        $codigo = trim($codigo);
        if ($codigo === '') return ['ok' => false, 'msg' => 'Código vazio.'];

        foreach ($this->itensDoPedido($pedidoId) as $item) {
            $ean = (string) ($item['ean'] ?? '');
            $sku = (string) ($item['sku_real'] ?? '');
            if (($ean !== '' && $ean === $codigo)
             || ($sku !== '' && strcasecmp($sku, $codigo) === 0)) {
                return ['ok' => true, 'item_id' => (int) $item['id'], 'item' => $item];
            }
        }

        return ['ok' => false, 'msg' => 'Código não pertence a este pedido.'];
    }

    /* =================================================================
       ETIQUETA
       ================================================================= */

    /**
     * A etiqueta so libera com NF-e emitida (a nota vem do Bling).
     * Devolve o motivo quando bloqueia, para a tela explicar em vez de so negar.
     */
    public function podeGerarEtiqueta(int $pedidoId): array
    {
        $p = $this->paraConferencia($pedidoId);
        if (!$p) return ['ok' => false, 'msg' => 'Pedido não encontrado.'];

        if (!empty($p['codigo_rastreio'])) {
            return ['ok' => false, 'msg' => 'Este pedido já tem rastreio: ' . $p['codigo_rastreio']];
        }
        if (!$p['nfe_ok']) {
            return ['ok' => false, 'msg' => 'Aguardando a NF-e ser emitida no Bling.'];
        }
        return ['ok' => true];
    }

    /* =================================================================
       INTERNO
       ================================================================= */

    /**
     * Itens com a variacao resolvida.
     *
     * O LEFT JOIN por CAST(pi.sku AS UNSIGNED) e a tal indirecao: pedido_itens
     * guarda o produto_skus.id no campo `sku`. O REGEXP evita casar linhas de
     * teste em que `sku` e texto ("teste"), que virariam id 0.
     */
    private function itensDoPedido(int $pedidoId): array
    {
        $st = $this->db->prepare(
            "SELECT pi.id, pi.produto_id, pi.quantidade, pi.preco_unitario,
                    pi.opcoes_selecionadas, pi.is_brinde,
                    COALESCE(pi.nome_produto, pr.nome) AS nome,
                    pi.imagem_snapshot,
                    ps.sku            AS sku_real,
                    ps.ean            AS ean,
                    ps.variacao_legado AS variacao
               FROM pedido_itens pi
          LEFT JOIN produtos pr     ON pr.id = pi.produto_id
          LEFT JOIN produto_skus ps ON ps.id = CAST(pi.sku AS UNSIGNED)
                                   AND pi.sku REGEXP '^[0-9]+$'
              WHERE pi.pedido_id = ?
           ORDER BY pi.id ASC"
        );
        $st->execute([$pedidoId]);
        $itens = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($itens as &$i) {
            $i['variacao_texto'] = $this->descreverVariacao($i);
            $i['tem_ean']        = !empty($i['ean']);
        }
        unset($i);

        return $itens;
    }

    /** Texto legivel da variacao, do que estiver disponivel no item. */
    private function descreverVariacao(array $item): string
    {
        $op = $item['opcoes_selecionadas'] ?? null;
        if ($op) {
            $dados = is_array($op) ? $op : json_decode((string) $op, true);
            if (is_array($dados) && $dados) {
                $partes = [];
                foreach ($dados as $k => $v) {
                    if (is_scalar($v) && (string) $v !== '') {
                        $partes[] = is_string($k) && !is_numeric($k) ? ($k . ': ' . $v) : (string) $v;
                    }
                }
                if ($partes) return implode(' · ', $partes);
            }
        }
        return (string) ($item['variacao'] ?? '');
    }

    private function statusDoPedido(int $id): ?string
    {
        $st = $this->db->prepare("SELECT status_pedido FROM pedidos WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private function etiquetaDoPedido(int $pedidoId): ?array
    {
        $st = $this->db->prepare(
            "SELECT id, status, codigo_rastreio, url_pdf, servico_nome
               FROM log_etiquetas
              WHERE pedido_id = ? AND status <> 'cancelada'
           ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$pedidoId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Nota emitida = tem numero E chave de acesso. */
    private function temNfe(array $pedido): bool
    {
        return !empty($pedido['nfe_numero']) && !empty($pedido['nfe_chave']);
    }
}
