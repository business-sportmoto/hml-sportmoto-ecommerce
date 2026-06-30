<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/AdminCliente.php
// ════════════════════════════════════════════════════════

class AdminCliente {

    private PDO $db;

    private User                $userModel;
    private Int                 $usuario_id;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();

        $this->userModel     = new User();        
    }

    // ════════════════════════════════════════════════════
    // LISTAGEM
    // ════════════════════════════════════════════════════

    public function listar(array $filtros = [], int $page = 1, int $perPage = 25): array {
        [$where, $params] = $this->buildWhere($filtros);
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT c.id, c.saldo_disponivel,
                    u.nome, u.email, u.ativo, u.criado_em,
                    u.ultimo_login,
                    cl.cpf, cl.telefone, cl.nascimento,
                    cs.score_total, cs.tier,
                    cs.ltv_total,
                    cs.total_pedidos,
                    (SELECT COUNT(*) FROM pedidos p WHERE p.cliente_id = c.id) AS total_pedidos_real,
                    GROUP_CONCAT(DISTINCT t.nome ORDER BY t.ordenacao SEPARATOR '||') AS tags_nomes,
                    GROUP_CONCAT(DISTINCT t.cor  ORDER BY t.ordenacao SEPARATOR '||') AS tags_cores,
                    cl.avatar AS avatar
             FROM clientes c
             JOIN usuarios u ON u.id = c.usuario_id
             LEFT JOIN clientes cl ON cl.id = c.id
             LEFT JOIN clientes_score cs ON cs.cliente_id = c.id
             LEFT JOIN clientes_tags ct  ON ct.cliente_id = c.id
             LEFT JOIN tags_disponiveis t ON t.id = ct.tag_id AND t.ativo = 1
             WHERE {$where}
             GROUP BY c.id
             ORDER BY u.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function contar(array $filtros = []): int {
        [$where, $params] = $this->buildWhere($filtros);
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT c.id)
             FROM clientes c
             JOIN usuarios u ON u.id = c.usuario_id
             LEFT JOIN clientes_score cs ON cs.cliente_id = c.id
             LEFT JOIN clientes_tags ct ON ct.cliente_id = c.id
             WHERE {$where}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // ════════════════════════════════════════════════════
    // PERFIL COMPLETO
    // ════════════════════════════════════════════════════

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT c.id AS cliente_id, c.saldo_disponivel,
                    u.id AS usuario_id, u.nome, u.email, u.ativo,
                    u.criado_em, u.ultimo_login,
                    cl.cpf, cl.telefone, cl.celular,
                    cl.nascimento, cl.genero, cl.newsletter,
                    cl.insta_cliente,
                    cl.avatar AS avatar
             FROM clientes c
             JOIN usuarios u  ON u.id  = c.usuario_id
             LEFT JOIN clientes cl ON cl.id = c.id
             WHERE c.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getStats(int $clienteId): array {
        // Pedidos + LTV
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total_pedidos,
                    COALESCE(SUM(CASE WHEN status_pagamento='aprovado' THEN total END),0) AS ltv,
                    COALESCE(AVG(CASE WHEN status_pagamento='aprovado' THEN total END),0) AS ticket_medio
             FROM pedidos WHERE cliente_id = ?"
        );
        $stmt->execute([$clienteId]);
        $pedidos = $stmt->fetch();

        // Curtidas (wishlist)
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM wishlist_itens wi
             JOIN wishlist w ON w.id = wi.wishlist_id
             WHERE w.cliente_id = ?"
        );
        $stmt->execute([$clienteId]);
        $curtidas = (int)$stmt->fetchColumn();

        $u_data = $this->userModel->getUserComplete($clienteId);

        // Carrinhos compartilhados
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM carrinhos_compartilhados WHERE usuario_id = ?"
        );
        try {

            $stmt->execute([$u_data['usuario_id']]);
            $carrinhos = (int)$stmt->fetchColumn();
        } catch (\Throwable) {
            $carrinhos = 0;
        }

        // Total acessos (sessões históricas)
        // $stmt = $this->db->prepare(
        //     "SELECT COUNT(*) FROM sessoes
        //      WHERE usuario_id = (SELECT usuario_id FROM clientes WHERE id = ? LIMIT 1)"
        // );
        // try {
        //     $stmt->execute([$clienteId]);
        //     $acessos = (int)$stmt->fetchColumn();
        // } catch (\Throwable) {
        //     $acessos = 0;
        // }

        return [
            'total_pedidos'    => (int)$pedidos['total_pedidos'],
            'ltv'              => (float)$pedidos['ltv'],
            'ticket_medio'     => (float)$pedidos['ticket_medio'],
            'curtidas'         => $curtidas,
            'carrinhos_compart'=> $carrinhos,
            // 'acessos'          => $acessos,
        ];
    }

    // ── Seções de dados ──────────────────────────────────

    public function getPedidos(int $clienteId, int $limit = 10): array {
        $stmt = $this->db->prepare(
            "SELECT p.id, p.codigo, p.status_pedido, p.status_pagamento,
                    p.total, p.criado_em,
                    (SELECT COUNT(*) FROM pedido_itens WHERE pedido_id = p.id) AS total_itens
             FROM pedidos p
             WHERE p.cliente_id = ?
             ORDER BY p.criado_em DESC LIMIT ?"
        );
        $stmt->execute([$clienteId, $limit]);
        return $stmt->fetchAll();
    }

    public function getTotalPedidos(int $clienteId): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM pedidos WHERE cliente_id = ?");
        $stmt->execute([$clienteId]);
        return (int)$stmt->fetchColumn();
    }

    public function getDevolucoes(int $clienteId, int $limit = 5): array {
        $stmt = $this->db->prepare(
            "SELECT s.id, s.tipo, s.status, s.valor_solicitado,
                    s.valor_aprovado, s.criado_em,
                    m.label AS motivo_label,
                    p.codigo AS pedido_codigo
             FROM solicitacoes_devolucao s
             JOIN motivos_devolucao m ON m.id = s.motivo_id
             JOIN pedidos p           ON p.id = s.pedido_id
             WHERE s.cliente_id = ?
             ORDER BY s.criado_em DESC LIMIT ?"
        );
        $stmt->execute([$clienteId, $limit]);
        return $stmt->fetchAll();
    }

    public function getEnderecos(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM enderecos WHERE cliente_id = ? ORDER BY principal DESC, id ASC"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    public function getCartoes(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM cartoes_salvos WHERE cliente_id = ? AND ativo = 1 ORDER BY id DESC"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    public function getWishlist(int $clienteId, int $limit = 12): array {
        $stmt = $this->db->prepare(
            "SELECT w.id, w.nome, w.descricao, w.criado_em,
                    COUNT(wi.id) AS total_itens
             FROM wishlist w
             LEFT JOIN wishlist_itens wi ON wi.wishlist_id = w.id
             WHERE w.cliente_id = ?
             GROUP BY w.id
             ORDER BY w.criado_em DESC
             LIMIT ?"
        );
        $stmt->execute([$clienteId, $limit]);
        $listas = $stmt->fetchAll();
 
        // Para cada lista, busca até 6 imagens de preview
        $stmtPrev = $this->db->prepare(
            "SELECT wi.produto_id AS produto_id
             FROM wishlist_itens wi
             JOIN produtos pr ON pr.id = wi.produto_id
             WHERE wi.wishlist_id = ?
             ORDER BY wi.adicionado_em DESC LIMIT 6"
        );
 
        foreach ($listas as &$lista) {
            $stmtPrev->execute([(int)$lista['id']]);
            $lista['preview_imgs'] = array_column($stmtPrev->fetchAll(), 'produto_id');
        }
 
        return $listas;
    }

     public function getWishlistItens(int $wishlistId, int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT pr.id AS produto_id, pr.nome, pr.preco, pr.preco_promo,
                    wi.adicionado_em AS adicionado_em
             FROM wishlist_itens wi
             JOIN wishlist w  ON w.id = wi.wishlist_id
             JOIN produtos pr ON pr.id = wi.produto_id
             WHERE wi.wishlist_id = ? AND w.cliente_id = ?
             ORDER BY wi.adicionado_em DESC"
        );

        $stmt->execute([$wishlistId, $clienteId]);
        $res_wish = $stmt->fetchAll();

        foreach ($res_wish as $item => $value) {            
            $res_wish[$item]['imagem'] = ImageHelper::getCartItemImage($value['produto_id']);;
        }

        return $res_wish;
    }

    public function getAvaliacoes(int $clienteId, int $limit = 10): array {
        $stmt = $this->db->prepare(
            "SELECT a.*, pr.nome AS produto_nome,
                    (SELECT img.arquivo FROM produto_imagens img
                     WHERE img.produto_id = pr.id AND img.principal = 1 LIMIT 1) AS imagem
             FROM avaliacoes a
             JOIN produtos pr ON pr.id = a.produto_id
             WHERE a.cliente_id = ?
             ORDER BY a.criado_em DESC LIMIT ?"
        );
        $stmt->execute([$clienteId, $limit]);
        return $stmt->fetchAll();
    }

    public function getCarrinho(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT ci.id AS item_id, ci.quantidade,
                    pr.nome AS produto_nome, pr.preco, pr.preco_promo,
                    ci.produto_id,
                    ca.status as carrinho_status, ca.atualizado_em AS ultima_atualizacao,
                    (SELECT img.arquivo FROM produto_imagens img
                     WHERE img.produto_id = pr.id AND img.principal = 1 LIMIT 1) AS imagem,
                    ca.atualizado_em AS carrinho_atualizado_em
             FROM carrinhos ca
             JOIN carrinho_itens ci ON ci.carrinho_id = ca.id
             JOIN produtos pr       ON pr.id = ci.produto_id
             WHERE ca.cliente_id = ? AND ca.status IN ('aberto', 'abandonado', 'expirado')
             ORDER BY ci.id DESC"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    public function getCuponsUsados(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT cp.codigo, pc.valor_desconto, p.codigo AS pedido_codigo,
                    p.criado_em
             FROM cupom_usos pc
             JOIN cupons cp  ON cp.id = pc.cupom_id
             JOIN pedidos p  ON p.id  = pc.pedido_id
             WHERE p.cliente_id = ?
             ORDER BY p.criado_em DESC"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    public function getGaragem(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT v.*,
                    m.nome AS montadora_nome,
                    (SELECT f.arquivo_thumb FROM cliente_veiculo_fotos f
                     WHERE f.veiculo_id = v.id AND f.capa = 1 LIMIT 1) AS foto_capa
             FROM cliente_veiculos v
             JOIN moto_montadoras m ON m.id = v.montadora_id
             WHERE v.cliente_id = ?
             ORDER BY v.criado_em DESC, v.id DESC"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    public function getSessoes(int $usuarioId): array {

    
        $stmt = $this->db->prepare(
            "SELECT * FROM auth_logs
             WHERE cliente_id = ?
             ORDER BY id DESC"
        );
        try {
            $stmt->execute([$usuarioId]);
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    public function getEmailsLog(int $clienteId, int $limit = 20): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM emails_log
             WHERE cliente_id = ?
             ORDER BY enviado_em DESC LIMIT ?"
        );
        $stmt->execute([$clienteId, $limit]);
        return $stmt->fetchAll();
    }

    public function getTimeline(int $clienteId, int $usuarioId, int $limit = 30): array {
        $items = [];

        // Pedidos
        $stmt = $this->db->prepare(
            "SELECT 'pedido' AS tipo, id AS ref_id, codigo AS ref_label,
                    status_pedido AS detalhe, criado_em
             FROM pedidos WHERE cliente_id = ? ORDER BY criado_em DESC LIMIT 15"
        );
        $stmt->execute([$clienteId]);
        $items = array_merge($items, $stmt->fetchAll());

        // Devoluções
        $stmt = $this->db->prepare(
            "SELECT 'devolucao' AS tipo, s.id AS ref_id,
                    CONCAT(s.tipo, ' #', s.id) AS ref_label,
                    s.status AS detalhe, s.criado_em
             FROM solicitacoes_devolucao s WHERE s.cliente_id = ?
             ORDER BY s.criado_em DESC LIMIT 10"
        );
        $stmt->execute([$clienteId]);
        $items = array_merge($items, $stmt->fetchAll());

        // Notas internas
        $stmt = $this->db->prepare(
            "SELECT 'nota' AS tipo, n.id AS ref_id,
                    SUBSTRING(n.texto, 1, 60) AS ref_label,
                    u.nome AS detalhe, n.criado_em
             FROM clientes_notas n
             JOIN usuarios u ON u.id = n.admin_id
             WHERE n.cliente_id = ? ORDER BY n.criado_em DESC LIMIT 5"
        );
        $stmt->execute([$clienteId]);
        $items = array_merge($items, $stmt->fetchAll());

        // E-mails enviados
        $stmt = $this->db->prepare(
            "SELECT 'email' AS tipo, id AS ref_id,
                    assunto AS ref_label, status AS detalhe, enviado_em AS criado_em
             FROM emails_log WHERE cliente_id = ?
             ORDER BY enviado_em DESC LIMIT 10"
        );
        $stmt->execute([$clienteId]);
        $items = array_merge($items, $stmt->fetchAll());

        // Ordena por data desc e limita
        usort($items, fn($a, $b) => strtotime($b['criado_em']) <=> strtotime($a['criado_em']));
        return array_slice($items, 0, $limit);
    }

    // ── Tags ────────────────────────────────────────────────

    public function getTags(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT t.* FROM tags_disponiveis t
             JOIN clientes_tags ct ON ct.tag_id = t.id
             WHERE ct.cliente_id = ? AND t.ativo = 1
             ORDER BY t.ordenacao ASC"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    public function getTodasTags(): array {
        return $this->db->query(
            "SELECT * FROM tags_disponiveis WHERE ativo = 1 ORDER BY ordenacao ASC"
        )->fetchAll();
    }

    public function setTags(int $clienteId, array $tagIds, int $adminId): void {
        $this->db->prepare("DELETE FROM clientes_tags WHERE cliente_id = ?")->execute([$clienteId]);
        if (empty($tagIds)) return;
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO clientes_tags (cliente_id, tag_id, admin_id) VALUES (?,?,?)"
        );
        foreach ($tagIds as $tagId) {
            $stmt->execute([$clienteId, (int)$tagId, $adminId]);
        }
    }

    // ── Notas ───────────────────────────────────────────────

    public function getNotas(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT n.*, u.nome AS admin_nome
             FROM clientes_notas n
             JOIN usuarios u ON u.id = n.admin_id
             WHERE n.cliente_id = ?
             ORDER BY n.criado_em DESC"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    public function addNota(int $clienteId, string $texto, int $adminId): int {
        $this->db->prepare(
            "INSERT INTO clientes_notas (cliente_id, texto, admin_id) VALUES (?,?,?)"
        )->execute([$clienteId, $texto, $adminId]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteNota(int $notaId): void {
        $this->db->prepare("DELETE FROM clientes_notas WHERE id = ?")->execute([$notaId]);
    }

    // ── Perfil ──────────────────────────────────────────────

    public function updatePerfil(int $clienteId, int $usuarioId, array $dados): void {
        if (!empty($dados['usuario'])) {
            $u = $dados['usuario'];
            $this->db->prepare(
                "UPDATE usuarios SET nome = ?, email = ? WHERE id = ?"
            )->execute([$u['nome'], $u['email'], $usuarioId]);
        }
        if (!empty($dados['cliente'])) {
            $cl = $dados['cliente'];
            $this->db->prepare(
                "UPDATE clientes SET cpf=?,telefone=?,celular=?,
                 nascimento=?,genero=?,newsletter=?,insta_cliente=?
                 WHERE id = ?"
            )->execute([
                $cl['cpf'], $cl['telefone'], $cl['celular'],
                $cl['nascimento'] ?: null, $cl['genero'],
                (int)$cl['newsletter'], $cl['insta_cliente'],
                $clienteId,
            ]);
        }
    }

    public function toggleAtivo(int $usuarioId, bool $ativo): void {
        $this->db->prepare("UPDATE usuarios SET ativo = ? WHERE id = ?")
                 ->execute([(int)$ativo, $usuarioId]);
    }

    // ── Tags admin (settings) ──────────────────────────────

    public function salvarTagDisponivel(array $dados, ?int $id = null): int {
        if ($id) {
            $this->db->prepare(
                "UPDATE tags_disponiveis SET nome=?,cor=?,icone_key=?,ativo=?,ordenacao=? WHERE id=?"
            )->execute([$dados['nome'],$dados['cor'],$dados['icone_key']??null,$dados['ativo'],$dados['ordenacao'],$id]);
            return $id;
        }
        $this->db->prepare(
            "INSERT INTO tags_disponiveis (nome,cor,icone_key,ativo,ordenacao) VALUES (?,?,?,?,?)"
        )->execute([$dados['nome'],$dados['cor'],$dados['icone_key']??null,$dados['ativo'],$dados['ordenacao']]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteTagDisponivel(int $id): array {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM clientes_tags WHERE tag_id = ?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            return ['ok' => false, 'msg' => 'Esta tag está em uso por clientes. Desative-a em vez de excluir.'];
        }
        $this->db->prepare("DELETE FROM tags_disponiveis WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    }

    // ════════════════════════════════════════════════════
    // PRIVADOS
    // ════════════════════════════════════════════════════

    private function buildWhere(array $f): array {
        $where  = ['1=1'];
        $params = [];

        if (!empty($f['q'])) {
            $like     = '%' . $f['q'] . '%';
            $where[]  = '(u.nome LIKE ? OR u.email LIKE ? OR cl.cpf LIKE ?)';
            $params   = array_merge($params, [$like, $like, $like]);
        }
        if (!empty($f['tier'])) {
            $where[]  = 'cs.tier = ?';
            $params[] = $f['tier'];
        }
        if (!empty($f['tag_id'])) {
            $where[]  = 'EXISTS (SELECT 1 FROM clientes_tags WHERE cliente_id=c.id AND tag_id=?)';
            $params[] = (int)$f['tag_id'];
        }
        if (isset($f['ativo']) && $f['ativo'] !== '') {
            $where[]  = 'u.ativo = ?';
            $params[] = (int)$f['ativo'];
        }
        if (!empty($f['aniversario_mes'])) {
            $where[]  = 'MONTH(cl.nascimento) = ?';
            $params[] = (int)$f['aniversario_mes'];
        }
        return [implode(' AND ', $where), $params];
    }
}