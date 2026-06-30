<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/AdminApiController.php
//
// Endpoints AJAX leves para autocompletar campos no admin.
// Todos exigem autenticação admin e retornam JSON.
// ════════════════════════════════════════════════════════

class AdminApiController extends Controller {

    private PDO $db;

    public function __construct() {
        AuthHelper::requireAdmin(); // bloqueia se não for admin
        $this->db = Database::getInstance()->getConnection();
    }

    // ── GET /admin/api/buscar-marcas?q=asx ───────────────
    // Retorna: { items: [{id, nome, slug, logo_url}] }
    public function buscarMarcas(): void {
        $q     = SecurityHelper::sanitizeSearch($_GET['q'] ?? '');
        $limit = min(15, max(1, (int)($_GET['limit'] ?? 10)));

        if (mb_strlen($q) < 1) {
            $this->json(['items' => []]);
        }

        $stmt = $this->db->prepare(
            "SELECT id, nome, slug, logo
             FROM marcas
             WHERE nome LIKE ?
               AND ativo = 1
               
             ORDER BY nome ASC
             LIMIT ?"
        );
        $stmt->execute(['%' . $q . '%', $limit]);

        $items = array_map(function (array $row): array {
            return [
                'id'      => (int)$row['id'],
                'nome'    => $row['nome'],
                'slug'    => $row['slug'],
                'logo_url'=> !empty($row['logo'])
                             ? UPLOAD_URL . '/brands/' . $row['logo']
                             : null,
            ];
        }, $stmt->fetchAll());

        $this->json(['items' => $items]);
    }

    // ── GET /admin/api/buscar-categorias?q=capacete ──────
    // Retorna: { items: [{id, nome, slug, path}] }
    // path = "Capacetes > Fechados" (breadcrumb da categoria)
    public function buscarCategorias(): void {
        $q     = SecurityHelper::sanitizeSearch($_GET['q'] ?? '');
        $limit = min(15, max(1, (int)($_GET['limit'] ?? 10)));

        if (mb_strlen($q) < 1) {
            $this->json(['items' => []]);
        }

        $stmt = $this->db->prepare(
            "SELECT c.id, c.nome, c.slug, p.nome AS pai_nome
             FROM categorias c
             LEFT JOIN categorias p ON p.id = c.parent_id
             WHERE c.nome LIKE ?
               AND c.ativo = 1
               
             ORDER BY c.nome ASC
             LIMIT ?"
        );
        $stmt->execute(['%' . $q . '%', $limit]);

        $items = array_map(function (array $row): array {
            return [
                'id'   => (int)$row['id'],
                'nome' => $row['nome'],
                'slug' => $row['slug'],
                // Mostra "Pai > Filho" se tiver hierarquia,
                // só o nome se for categoria raiz
                'path' => $row['pai_nome']
                          ? $row['pai_nome'] . ' › ' . $row['nome']
                          : $row['nome'],
            ];
        }, $stmt->fetchAll());

        $this->json(['items' => $items]);
    }

    // ── GET /admin/api/buscar-produtos?q=capacete ────────
    // Retorna: { items: [{id, nome, slug, sku_legado, marca, imagem_url}] }
    public function buscarProdutos(): void {
        $q          = SecurityHelper::sanitizeSearch($_GET['q'] ?? '');
        $marcaId    = (int)($_GET['marca_id']    ?? 0);
        $categoriaId= (int)($_GET['categoria_id'] ?? 0);
        $limit      = min(15, max(1, (int)($_GET['limit'] ?? 10)));

        if (mb_strlen($q) < 1) {
            $this->json(['items' => []]);
        }

        $where  = ['p.ativo = 1', 'p.deleted_at IS NULL'];
        $params = [];

        // Busca por nome OU SKU legado
        $where[]  = "(p.nome LIKE ? OR p.sku_legado LIKE ?)";
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';

        // Filtros opcionais — úteis quando o tag input já tem escopo
        // de marca/categoria selecionado e quer refinar produto dentro
        if ($marcaId > 0) {
            $where[]  = "p.marca_id = ?";
            $params[] = $marcaId;
        }
        if ($categoriaId > 0) {
            $where[]  = "p.categoria_id = ?";
            $params[] = $categoriaId;
        }

        $params[] = $limit;

        $stmt = $this->db->prepare(
            "SELECT p.id, p.nome, p.slug, p.sku_legado, p.preco,
                    m.nome AS marca_nome,
                    pi.arquivo AS imagem
             FROM produtos p
             LEFT JOIN marcas m ON m.id = p.marca_id
             LEFT JOIN produto_imagens pi
                    ON pi.produto_id = p.id AND pi.principal = 1
             WHERE " . implode(' AND ', $where) . "
             ORDER BY p.nome ASC
             LIMIT ?"
        );
        $stmt->execute($params);

        $items = array_map(function (array $row): array {
            // Label rico: "Capacete ASX Eagle • ASX • R$ 299,90"
            $label = $row['nome'];
            if ($row['marca_nome']) $label .= ' • ' . $row['marca_nome'];
            if ($row['sku_legado']) $label .= ' (' . $row['sku_legado'] . ')';

            return [
                'id'        => (int)$row['id'],
                'nome'      => $row['nome'],  // nome limpo para o chip
                'label'     => $label,         // label rico para o dropdown
                'slug'      => $row['slug'],
                'sku_legado'=> $row['sku_legado'],
                'marca'     => $row['marca_nome'],
                'preco_fmt' => PriceHelper::format((float)$row['preco']),
                'imagem_url'=> !empty($row['imagem'])
                               ? UPLOAD_URL . '/products/' . $row['imagem']
                               : null,
            ];
        }, $stmt->fetchAll());

        $this->json(['items' => $items]);
    }

    // ── GET /admin/api/buscar-caracteristicas?q=adulto ───
    // Retorna: { items: [{id, nome, tipo, opcoes}] }
    // Para o seletor de escopo por características no form de promoções.
    public function buscarCaracteristicas(): void {
        $q     = SecurityHelper::sanitizeSearch($_GET['q'] ?? '');
        $limit = min(20, max(1, (int)($_GET['limit'] ?? 15)));

        $where  = ['ativo = 1'];
        $params = [];

        if (mb_strlen($q) >= 1) {
            $where[]  = "(nome LIKE ? OR slug LIKE ?)";
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $params[] = $limit;

        $stmt = $this->db->prepare(
            "SELECT id, nome, slug, tipo, opcoes, unidade
             FROM caracteristicas
             WHERE " . implode(' AND ', $where) . "
             ORDER BY nome ASC
             LIMIT ?"
        );
        $stmt->execute($params);

        $items = array_map(function (array $row): array {
            $opcoes = $row['opcoes'] ? json_decode($row['opcoes'], true) : [];
            return [
                'id'     => (int)$row['id'],
                'nome'   => $row['nome'],
                'slug'   => $row['slug'],
                'tipo'   => $row['tipo'],
                'opcoes' => $opcoes,   // valores possíveis para select/boolean
                'unidade'=> $row['unidade'],
            ];
        }, $stmt->fetchAll());

        $this->json(['items' => $items]);
    }
}