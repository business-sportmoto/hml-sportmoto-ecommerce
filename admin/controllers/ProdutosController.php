<?php
// admin/controllers/ProdutosController.php

class ProdutosController extends Controller {

    private ImageUploadService $img;

    public function __construct() {
        AuthHelper::requireAdmin();

        $this->img = ImageUploadService::fromEnv();
    }

    // ── Listagem ──────────────────────────────────────────
    public function index(): void {
        $db      = Database::getInstance()->getConnection();
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $search = trim(SecurityHelper::sanitizeString($_GET['q'] ?? ''));
        $marcaId = SecurityHelper::sanitizeInt(  $_GET['marca_id']     ?? 0);
        $catId   = SecurityHelper::sanitizeInt(  $_GET['categoria_id'] ?? 0);
        $status  = SecurityHelper::sanitizeString($_GET['status']       ?? '');
        $estoque = SecurityHelper::sanitizeString($_GET['estoque']      ?? '');
        $temVar  = $_GET['tem_variacao'] ?? '';

        // Atributos dinâmicos: attr_{tipo_id} => valor
        $atributosFiltros = [];
        foreach ($_GET as $key => $val) {
            if (!str_starts_with($key, 'attr_') || trim((string)$val) === '') continue;
            $tipoId = (int)substr($key, 5);
            if ($tipoId <= 0) continue;

            // Busca o papel deste tipo
            $stmtPapel = $db->prepare(
                "SELECT papel FROM atributo_tipos WHERE id = ? LIMIT 1"
            );
            $stmtPapel->execute([$tipoId]);
            $papel = $stmtPapel->fetchColumn();

            if ($papel) {
                $atributosFiltros[] = [
                    'tipo_id' => $tipoId,
                    'valor'   => SecurityHelper::sanitizeString($val),
                    'papel'   => $papel, // 'variacao' | 'agrupador'
                ];
            }
        }

        // ── WHERE base ────────────────────────────────────────
        $where  = "p.deleted_at IS NULL";
        $params = [];

        foreach ($atributosFiltros as $af) {
            if ($af['papel'] === 'variacao') {
                // Variações: busca em sku_atributos
                $where   .= " AND EXISTS (
                    SELECT 1 FROM produto_skus ps_a
                    JOIN sku_atributos sa_a ON sa_a.sku_id = ps_a.id
                    WHERE ps_a.produto_id       = p.id
                    AND sa_a.atributo_tipo_id = ?
                    AND sa_a.valor            = ?
                    AND ps_a.ativo            = 1
                )";
            } else {
                // Agrupadores: busca em produto_atributos_agrupadores
                $where   .= " AND EXISTS (
                    SELECT 1 FROM produto_atributos_agrupadores paa_f
                    WHERE paa_f.produto_id       = p.id
                    AND paa_f.atributo_tipo_id = ?
                    AND paa_f.valor            = ?
                )";
            }
            $params[] = $af['tipo_id'];
            $params[] = $af['valor'];
        }

        // Status — padrão mostra ativos
        if ($status === 'ativo') {
            $where .= " AND p.ativo = 1";
        } elseif ($status === 'inativo') {
            $where .= " AND p.ativo = 0";
        } elseif ($status === 'destaque') {
            $where .= " AND p.destaque = 1 AND p.ativo = 1";
        } else {
            // $where .= " AND p.ativo = 1";
        }

        // Busca textual
        if ($search !== '') {
            $like     = '%' . $search . '%';
            $where   .= " AND (
                p.nome      LIKE ?
                OR p.sku_legado LIKE ?
                OR EXISTS (
                    SELECT 1 FROM produto_skus ps_s
                    WHERE ps_s.produto_id = p.id
                    AND ps_s.sku LIKE ?
                    AND ps_s.ativo = 1
                )
            )";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // Marca
        if ($marcaId > 0) {
            $where   .= " AND p.marca_id = ?";
            $params[] = $marcaId;
        }

        // Categoria — usa produto_categorias
        if ($catId > 0) {
            $where   .= " AND EXISTS (
                SELECT 1 FROM produto_categorias pc_f
                WHERE pc_f.produto_id = p.id
                AND pc_f.categoria_id = ?
            )";
            $params[] = $catId;
        }

        // Estoque
        if ($estoque === 'ok') {
            $where .= " AND p.estoque_total > COALESCE(p.estoque_minimo, 0)";
        } elseif ($estoque === 'baixo') {
            $where .= " AND p.estoque_total > 0
                        AND p.estoque_total <= COALESCE(p.estoque_minimo, 0)";
        } elseif ($estoque === 'zero') {
            $where .= " AND p.estoque_total = 0";
        }

        // Tipo
        if ($temVar !== '') {
            $where   .= " AND p.tem_variacao = ?";
            $params[] = (int)$temVar;
        }


        // ── Count ─────────────────────────────────────────────
        $stmtCount = $db->prepare(
            "SELECT COUNT(DISTINCT p.id)
            FROM produtos p
            WHERE {$where}"
        );
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // ── Busca paginada ────────────────────────────────────
        $stmt = $db->prepare(
            "SELECT p.id, p.nome, p.slug, p.sku_legado, p.bling_id, 
                    p.preco, p.preco_promo,
                    p.estoque_total, p.estoque_minimo,
                    p.ativo, p.destaque, p.lancamento,
                    p.tem_variacao, p.criado_em,
                    pi.arquivo AS imagem,
                    c.nome     AS categoria_nome,
                    m.nome     AS marca_nome,
                    (SELECT COUNT(*) FROM produto_skus ps
                       WHERE ps.produto_id = p.id) AS total_skus,
                    (SELECT COUNT(*) FROM produto_skus ps
                       WHERE ps.produto_id = p.id AND ps.bling_id IS NOT NULL) AS skus_vinculados                    
            FROM produtos p
            LEFT JOIN produto_imagens pi
                    ON pi.produto_id = p.id AND pi.principal = 1
            LEFT JOIN categorias c ON c.id = p.categoria_id
            LEFT JOIN marcas m     ON m.id = p.marca_id
            WHERE {$where}
            ORDER BY p.criado_em DESC
            LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $produtos = $stmt->fetchAll();

        // ── Suporte para filtros da view ──────────────────────
        $marcas = $db->query(
            "SELECT id, nome FROM marcas WHERE ativo=1 ORDER BY nome ASC"
        )->fetchAll();

        $categorias = $db->query(
            "SELECT id, nome, parent_id FROM categorias WHERE ativo=1
            ORDER BY parent_id ASC, nome ASC"
        )->fetchAll();

        // Só atributos de variação que têm valores cadastrados
        $atributos = $db->query(
            "SELECT at.id, at.nome, at.papel,
                    GROUP_CONCAT(av.valor ORDER BY av.ordem SEPARATOR '||') AS valores
            FROM atributo_tipos at
            JOIN atributo_valores av ON av.atributo_tipo_id = at.id
            GROUP BY at.id
            ORDER BY at.papel ASC, at.ordenacao ASC, at.nome ASC"
        )->fetchAll();

        

        // Monta filtros ativos para exibição de tags
        $filtrosAtivos = array_filter([
            'q'            => $search,
            'marca_id'     => $marcaId   ?: '',
            'categoria_id' => $catId     ?: '',
            'status'       => $status,
            'estoque'      => $estoque,
            'tem_variacao' => $temVar,
            ...(array_map(fn($v) => $v, $atributosFiltros)),
        ]);

        $this->render('produtos/index', [
            'produtos'      => $produtos,
            'total'         => $total,
            'page'          => $page,
            'perPage'       => $perPage,
            'marcas'        => $marcas,
            'categorias'    => $categorias,
            'atributos'     => $atributos,
            'filtrosAtivos' => $filtrosAtivos,
            'totalFiltros'  => count($filtrosAtivos),
        ], 'admin');
    }
    

    // ── Formulário criar ──────────────────────────────────
    public function criar(): void {
        $data = $this->getFormData();
        $this->render('produtos/form', array_merge($data, [
            'produto' => null,
            'titulo'  => 'Novo produto',
            'imagens' => [],
            'skus'    => [],
            'atributos_tipos' => $this->getAtributosTipos(),
        ]), 'admin');
    }

    // ── Formulário editar ─────────────────────────────────
    public function editar(int $id): void {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM produtos WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$id]);
        $produto = $stmt->fetch();

        if (!$produto) {
            Session::flash('error', 'Produto não encontrado.');
            $this->redirect(BASE_URL . '/admin/produtos');
        }

        // Imagens
        $stmt = $db->prepare(
            "SELECT * FROM produto_imagens WHERE produto_id = ? ORDER BY ordem ASC"
        );
        $stmt->execute([$id]);
        $imagens = $stmt->fetchAll();

        // SKUs com atributos
        // Dentro do try/catch, após salvar o produto principal
        // Substitua todo o bloco de SKUs por:

        // admin/controllers/ProdutosController.php — método editar()
        // Substitua a query de SKUs por:

        $stmt = $db->prepare(
            "SELECT ps.*,
                    ps.preco_promo,
                    GROUP_CONCAT(
                        CONCAT(sa.atributo_tipo_id, ':', sa.valor)
                        ORDER BY at.ordenacao SEPARATOR '||'
                    ) AS atributos_raw
            FROM produto_skus ps
            LEFT JOIN sku_atributos sa ON sa.sku_id = ps.id
            LEFT JOIN atributo_tipos at ON at.id = sa.atributo_tipo_id
            WHERE ps.produto_id = ?
            GROUP BY ps.id
            ORDER BY ps.id ASC"
        );
        $stmt->execute([$id]);
        $skusRaw = $stmt->fetchAll();

        // Normaliza os atributos em array [tipo_id => valor]
        $skus = array_map(function ($sku) {
            $attrs = [];
            if (!empty($sku['atributos_raw'])) {
                foreach (explode('||', $sku['atributos_raw']) as $par) {
                    [$tipoId, $valor] = explode(':', $par, 2);
                    $attrs[(int)$tipoId] = $valor;
                }
            }
            $sku['atributos_map'] = $attrs;
            return $sku;
        }, $skusRaw);
        // Atributos agrupadores do produto
        $stmt = $db->prepare(
            "SELECT paa.*, at.nome AS tipo_nome, at.slug AS tipo_slug,
                    at.tipo_display
             FROM produto_atributos_agrupadores paa
             JOIN atributo_tipos at ON at.id = paa.atributo_tipo_id
             WHERE paa.produto_id = ?
             ORDER BY at.ordenacao"
        );
        $stmt->execute([$id]);
        $agrupadores = $stmt->fetchAll();

        $stmt = $db->prepare(
            "SELECT pc.caracteristica_id, pc.valor,
                    c.nome, c.tipo, c.unidade, c.opcoes,
                    c.placeholder, c.obrigatorio,
                    cc.obrigatorio AS cat_obrigatorio,
                    cc.ordem       AS cat_ordem
            FROM produto_caracteristicas pc
            JOIN caracteristicas c ON c.id = pc.caracteristica_id
            LEFT JOIN categoria_caracteristicas cc
                    ON cc.caracteristica_id = pc.caracteristica_id
                AND cc.categoria_id = ?
            WHERE pc.produto_id = ?
            ORDER BY cc.ordem ASC, c.ordem ASC"
        );
        $stmt->execute([$produto['categoria_id'], $id]);
        $valoresCaracteristicas = $stmt->fetchAll();

        // Converte para mapa [id => valor]
        $mapaCaracteristicas = [];
        foreach ($valoresCaracteristicas as $vc) {
            $mapaCaracteristicas[$vc['caracteristica_id']] = $vc['valor'];
        }

        // Busca as características disponíveis da categoria
        // Busca características de TODAS as categorias do produto
        $stmt = $db->prepare(
            "SELECT DISTINCT c.*, cc.obrigatorio AS cat_obrigatorio, cc.ordem AS cat_ordem
            FROM caracteristicas c
            JOIN categoria_caracteristicas cc ON cc.caracteristica_id = c.id
            JOIN produto_categorias pc ON pc.categoria_id = cc.categoria_id
            WHERE pc.produto_id = ? AND c.ativo = 1
            ORDER BY cc.ordem ASC, c.ordem ASC"
        );
        $stmt->execute([$id]);
        $caracteristicasCategoria = $stmt->fetchAll();

        // No método editar() — busca categorias do produto:
        $stmt = $db->prepare(
            "SELECT categoria_id, principal
            FROM produto_categorias
            WHERE produto_id = ?
            ORDER BY principal DESC"
        );
        $stmt->execute([$id]);
        $produtoCategorias = $stmt->fetchAll();
        // Mapa [categoria_id => principal]
        $mapaCategorias = array_column($produtoCategorias, 'principal', 'categoria_id');

        $data = $this->getFormData();
        $this->render('produtos/form', array_merge($data, [
            'produto'           => $produto,
            'titulo'            => 'Editar: ' . $produto['nome'],
            'imagens'           => $imagens,
            'skus'              => $skus,
            'agrupadores'       => $agrupadores,
            'atributos_tipos'   => $this->getAtributosTipos(),
            'mapaCategorias'    => $mapaCategorias,
            'caracteristicasCategoria' => $caracteristicasCategoria,
            'mapaCaracteristicas'      => $mapaCaracteristicas,
            
        ]), 'admin');
    }

    // ── Salvar ────────────────────────────────────────────
    public function salvar(): void {
        $this->verifyCsrf();

        $id          = SecurityHelper::sanitizeInt($_POST['id']              ?? 0);
        $nome        = SecurityHelper::sanitizeString($_POST['nome']         ?? '');
        $catId       = SecurityHelper::sanitizeInt($_POST['categoria_id']    ?? 0);
        $marcaId     = SecurityHelper::sanitizeInt($_POST['marca_id']        ?? 0);
        $familiaId   = SecurityHelper::sanitizeInt($_POST['familia_id']      ?? 0);
        $preco       = (float)str_replace(',', '.', $_POST['preco']          ?? 0);
        $precoPromo  = !empty($_POST['preco_promo']) && (float)$_POST['preco_promo'] > 0
                    ? (float)str_replace(',', '.', $_POST['preco_promo'])
                    : null;
        $promoIn     = !empty($_POST['promo_inicio']) ? $_POST['promo_inicio'] : null;
        $promoFim    = !empty($_POST['promo_fim'])    ? $_POST['promo_fim']    : null;
        // $estoque     = SecurityHelper::sanitizeInt($_POST['estoque_total']   ?? 0);
        $estoqueMin  = SecurityHelper::sanitizeInt($_POST['estoque_minimo']  ?? 0);
        $peso        = !empty($_POST['peso_kg'])         ? (float)$_POST['peso_kg']         : null;
        $comprimento = !empty($_POST['comprimento_cm'])  ? (float)$_POST['comprimento_cm']  : null;
        $largura     = !empty($_POST['largura_cm'])       ? (float)$_POST['largura_cm']      : null;
        $altura      = !empty($_POST['altura_cm'])        ? (float)$_POST['altura_cm']       : null;
        $descCurta   = $_POST['descricao_curta']  ?? '';
        $descricao   = $_POST['descricao']        ?? '';
        $metaTitle   = SecurityHelper::sanitizeString($_POST['meta_title']        ?? '');
        $metaDesc    = SecurityHelper::sanitizeString($_POST['meta_description']  ?? '');
        $metaKw      = SecurityHelper::sanitizeString($_POST['meta_keywords']     ?? '');
        $googleCat   = SecurityHelper::sanitizeString($_POST['google_category']   ?? '');
        $skuLegado   = SecurityHelper::sanitizeString($_POST['sku_legado']        ?? '');
        $ativo       = isset($_POST['ativo'])      && $_POST['ativo']      == '1' ? 1 : 0;
        $destaque    = isset($_POST['destaque'])   && $_POST['destaque']   == '1' ? 1 : 0;
        $lancamento  = isset($_POST['lancamento']) && $_POST['lancamento'] == '1' ? 1 : 0;
        $temVar      = isset($_POST['tem_variacao']) ? 1 : 0;

        $caracteristicas    = $_POST['caracteristicas'] ?? [];
        $categoriasPost     = $_POST['categorias'] ?? [];

        // Após extrair as variáveis, antes do beginTransaction():

        // ── Validações ────────────────────────────────────────────

        if (empty($nome)) {
            $this->json(['ok' => false, 'msg' => 'Nome é obrigatório.']);
        }

        // Preço zerado só é permitido se tem variações (preço vem dos SKUs)
        if (!$temVar && $preco <= 0) {
            $this->json([
                'ok'  => false,
                'msg' => 'O preço do produto não pode ser zero. Informe um valor válido.',
            ]);
        }

        // Preço promocional não pode ser maior ou igual ao preço regular
        if ($precoPromo !== null && $precoPromo >= $preco) {
            $this->json([
                'ok'  => false,
                'msg' => 'O preço promocional deve ser menor que o preço regular.',
            ]);
        }

        // Se tem variações, valida que todos os SKUs têm preço
        if ($temVar) {
            $skus = $_POST['skus'] ?? [];
            foreach ($skus as $key => $sku) {
                $skuCodigo = trim($sku['sku'] ?? '');
                $skuPreco  = (float)str_replace(',', '.', $sku['preco'] ?? 0);
                if (!empty($skuCodigo) && $skuPreco <= 0) {
                    $this->json([
                        'ok'  => false,
                        'msg' => "O SKU \"{$skuCodigo}\" está com preço zero. Informe um valor válido.",
                    ]);
                }
            }
        }

        // Garante que categoria_id principal está incluída
        if ($catId && !in_array((string)$catId, array_keys($categoriasPost))) {
            $categoriasPost[$catId] = ['principal' => 1];
        }

        $db   = Database::getInstance()->getConnection();
        $slug = $id > 0
                ? SlugHelper::unique($nome, 'produtos', (string)$id)
                : SlugHelper::unique($nome, 'produtos');

        $campos = [
            'nome'             => $nome,
            'slug'             => $slug,
            'categoria_id'     => $catId    ?: null,
            'marca_id'         => $marcaId  ?: null,
            'familia_id'       => $familiaId ?: null,
            'sku_legado'       => $skuLegado ?: null,
            'bling_id'         => SecurityHelper::sanitizeString($_POST['bling_id'] ?? '') ?: null,
            'preco'            => $preco,
            'preco_promo'      => $precoPromo,
            'promo_inicio'     => $promoIn,
            'promo_fim'        => $promoFim,
            // 'estoque_total'    => $estoque,
            'estoque_minimo'   => $estoqueMin,
            'peso_kg'          => $peso,
            'comprimento_cm'   => $comprimento,
            'largura_cm'       => $largura,
            'altura_cm'        => $altura,
            'descricao_curta'  => $descCurta  ?: null,
            'descricao'        => $descricao  ?: null,
            'meta_title'       => $metaTitle  ?: null,
            'meta_description' => $metaDesc   ?: null,
            'meta_keywords'    => $metaKw     ?: null,
            'google_category'  => $googleCat  ?: null,
            'ativo'            => $ativo,
            'destaque'         => $destaque,
            'lancamento'       => $lancamento,
            'tem_variacao'     => $temVar,
        ];

        
        try {
            $db->beginTransaction();

            // ── Produto ───────────────────────────────────────
            // if ($id > 0) {
            //     $sets   = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($campos)));
            //     $params = array_values($campos);
            //     $params[] = $id;
            //     $db->prepare("UPDATE produtos SET {$sets} WHERE id = ?")->execute($params);
            // }
            if ($id > 0) {
                // ── GATILHO: captura preço vigente ANTES do update ──
                $gatilho = new ProdutoGatilhoService();
                $precoAntigo = $gatilho->lerPrecoAtual($id);
                // ────────────────────────────────────────────────────

                $sets   = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($campos)));
                $params = array_values($campos);
                $params[] = $id;
                $db->prepare("UPDATE produtos SET {$sets} WHERE id = ?")->execute($params);

                // ── GATILHO: compara e dispara queda de preço ──
                // $precoNovo é o preço vigente novo. Usa a mesma lógica de promoção.
                $precoNovo = ($precoPromo !== null && $precoPromo > 0)
                        ? $precoPromo   // se cadastrou promo, ela é o preço vigente
                        : $preco;
                $gatilho->verificarQuedaPreco($id, $precoAntigo, (float)$precoNovo);
                // ────────────────────────────────────────────────
            }
            
            else {
                $cols   = implode(', ', array_keys($campos));
                $vals   = implode(', ', array_fill(0, count($campos), '?'));
                $db->prepare(
                    "INSERT INTO produtos ({$cols}) VALUES ({$vals})"
                )->execute(array_values($campos));
                $id = (int)$db->lastInsertId();
            }

            // Características (relacionamento)
            // if (!empty($caracteristicas)) {
            //     $stmtDel = $db->prepare(
            //         "DELETE FROM produto_caracteristicas WHERE produto_id = ?"
            //     );
            //     $stmtDel->execute([$id]);

            //     $stmtIns = $db->prepare(
            //         "INSERT INTO produto_caracteristicas
            //         (produto_id, caracteristica_id, valor)
            //         VALUES (?, ?, ?)"
            //     );
            //     foreach ($caracteristicas as $charId => $valor) {
            //         $charId = (int)$charId;
            //         $valor  = trim((string)$valor);
            //         if ($charId && $valor !== '') {
            //             $stmtIns->execute([$id, $charId, $valor]);
            //         }
            //     }
            // }

            // ── Compatibilidades ──────────────────────────────────────
            $compat = new MotoCompatibilidade();
            $itens  = [];

            foreach ($_POST['compatibilidades'] ?? [] as $key => $item) {
                $montId  = SecurityHelper::sanitizeInt($item['montadora_id'] ?? 0);
                if (!$montId) continue;
                $itens[] = [
                    'montadora_id' => $montId,
                    'modelo_id'    => !empty($item['modelo_id']) ? (int)$item['modelo_id'] : null,
                    'ano_inicio'   => !empty($item['ano_inicio']) ? (int)$item['ano_inicio'] : null,
                    'ano_fim'      => !empty($item['ano_fim'])    ? (int)$item['ano_fim']    : null,
                    'observacao'   => $item['observacao'] ?? '',
                ];
            }
            $compat->salvarCompatibildades($id, $itens);

            if (!empty($categoriasPost)) {
                $db->prepare(
                    "DELETE FROM produto_categorias WHERE produto_id = ?"
                )->execute([$id]);

                // ── Garante que existe EXATAMENTE UMA principal ───────
                // 1. Coleta quais marcaram como principal
                $principaisMarcadas = array_filter(
                    $categoriasPost,
                    fn($c) => !empty($c['principal']) && (int)$c['principal'] === 1
                );

                // 2. Se nenhuma marcou → a primeira vira principal
                // 3. Se mais de uma marcou → só a primeira conta
                $idPrincipal = null;
                if (!empty($principaisMarcadas)) {
                    $idPrincipal = (int)array_key_first($principaisMarcadas);
                } else {
                    $idPrincipal = (int)array_key_first($categoriasPost);
                }

                $stmtCat = $db->prepare(
                    "INSERT INTO produto_categorias (produto_id, categoria_id, principal)
                    VALUES (?, ?, ?)"
                );

                foreach ($categoriasPost as $cid => $cfg) {
                    $cid       = (int)$cid;
                    $principal = ($cid === $idPrincipal) ? 1 : 0; // só uma pode ser 1
                    if ($cid) {
                        $stmtCat->execute([$id, $cid, $principal]);
                    }
                }

                // Atualiza categoria_id do produto com a principal
                $db->prepare(
                    "UPDATE produtos SET categoria_id = ? WHERE id = ?"
                )->execute([$idPrincipal, $id]);
            }

            

            // ── Agrupadores ───────────────────────────────────
            $agrupadores = $_POST['agrupadores'] ?? [];

            $db->prepare(
                "DELETE FROM produto_atributos_agrupadores WHERE produto_id = ?"
            )->execute([$id]);

            if (!empty($agrupadores)) {
                $stmtAg = $db->prepare(
                    "INSERT INTO produto_atributos_agrupadores
                    (produto_id, atributo_tipo_id, valor, valor_hex)
                    VALUES (?, ?, ?, ?)"
                );
                foreach ($agrupadores as $ag) {
                    $tipoId = SecurityHelper::sanitizeInt($ag['tipo_id'] ?? 0);
                    $valor  = SecurityHelper::sanitizeString($ag['valor']    ?? '');
                    $hex    = SecurityHelper::sanitizeString($ag['valor_hex']?? '');

                    if (!$tipoId || empty($valor)) continue;
                    if ($hex && !preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) $hex = '';

                    $stmtAg->execute([$id, $tipoId, $valor, $hex ?: null]);
                }
            }

            // ── SKUs ──────────────────────────────────────────
            $skus = $_POST['skus'] ?? [];

            if (!empty($skus)) {
                $stmtSkuUpdate = $db->prepare(
                    "UPDATE produto_skus
                    SET sku=?, preco=?, preco_promo=?, estoque=?, ativo=?
                    WHERE id=? AND produto_id=?"
                );
                $stmtSkuInsert = $db->prepare(
                    "INSERT INTO produto_skus
                    (produto_id, sku, preco, preco_promo, estoque, ativo)
                    VALUES (?,?,?,?,?,?)"
                );
                $stmtDelAttrs  = $db->prepare(
                    "DELETE FROM sku_atributos WHERE sku_id=?"
                );
                $stmtInsAttr   = $db->prepare(
                    "INSERT INTO sku_atributos (sku_id, atributo_tipo_id, valor)
                    VALUES (?,?,?)"
                );

                foreach ($skus as $key => $sku) {
                    $skuCodigo = SecurityHelper::sanitizeString($sku['sku']      ?? '');
                    $skuPreco  = (float)str_replace(',', '.', $sku['preco']      ?? 0);
                    $skuPromo  = !empty($sku['preco_promo']) && (float)$sku['preco_promo'] > 0
                                ? (float)str_replace(',', '.', $sku['preco_promo'])
                                : null;
                    $skuEst    = SecurityHelper::sanitizeInt($sku['estoque']     ?? 0);
                    $skuAtivo  = isset($sku['ativo']) && $sku['ativo'] == '1' ? 1 : 0;
                    $attrs     = $sku['atributos'] ?? [];

                    if (empty($skuCodigo)) continue;

                    // Persiste o SKU
                    if (is_numeric($key) && (int)$key > 0) {
                        $skuId = (int)$key;
                        $stmtSkuUpdate->execute([
                            $skuCodigo, $skuPreco, $skuPromo, $skuEst, $skuAtivo,
                            $skuId, $id,
                        ]);
                    } else {
                        $stmtSkuInsert->execute([
                            $id, $skuCodigo, $skuPreco, $skuPromo, $skuEst, $skuAtivo,
                        ]);
                        $skuId = (int)$db->lastInsertId();
                    }

                    // Salva atributos do SKU
                    $stmtDelAttrs->execute([$skuId]);

                    foreach ($attrs as $tipoId => $valor) {
                        $tipoId = (int)$tipoId;
                        $valor  = SecurityHelper::sanitizeString(trim((string)$valor));
                        if ($tipoId <= 0 || $valor === '') continue;
                        $stmtInsAttr->execute([$skuId, $tipoId, $valor]);
                    }
                }

                // Atualiza estoque total somando SKUs ativos
                // (só quando tem variação — se não tem, usa o campo manual)
                if ($temVar) {
                    $db->prepare(
                        "UPDATE produtos
                        SET estoque_total = COALESCE((
                            SELECT SUM(estoque)
                            FROM produto_skus
                            WHERE produto_id = ? AND ativo = 1
                        ), 0)
                        WHERE id = ?"
                    )->execute([$id, $id]);
                }
            }

            $db->commit();

            $this->json([
                'ok'   => true,
                'msg'  => $id ? 'Produto salvo!' : 'Produto criado!',
                'id'   => $id,
                'slug' => $slug,
            ]);

        } catch (Exception $e) {
            $db->rollBack();
            LogService::error('Erro ao salvar produto: ' . $e->getMessage());
            $this->json(['ok' => false, 'msg' => 'Erro ao salvar: ' . $e->getMessage()]);
        }
    }

    // ── Upload de imagem ──────────────────────────────────
    // public function uploadImagem(): void {
    //     $this->verifyCsrf();
    //     $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
    //     if (!$produtoId) $this->json(['ok' => false, 'msg' => 'Produto inválido.']);

    //     if (empty($_FILES['imagem']['tmp_name'])) {
    //         $this->json(['ok' => false, 'msg' => 'Nenhuma imagem enviada.']);
    //     }

    //     $ext     = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
    //     $allowed = ['jpg','jpeg','png','webp'];
    //     if (!in_array($ext, $allowed)) {
    //         $this->json(['ok' => false, 'msg' => 'Formato inválido.']);
    //     }
    //     if ($_FILES['imagem']['size'] > 5 * 1024 * 1024) {
    //         $this->json(['ok' => false, 'msg' => 'Máximo 5MB por imagem.']);
    //     }

    //     $dir  = UPLOAD_PATH . '/products/';
    //     if (!is_dir($dir)) mkdir($dir, 0755, true);

    //     $arquivo = 'prod_' . $produtoId . '_' . uniqid() . '.' . $ext;
    //     if (!move_uploaded_file($_FILES['imagem']['tmp_name'], $dir . $arquivo)) {
    //         $this->json(['ok' => false, 'msg' => 'Erro ao salvar imagem.']);
    //     }

    //     $db = Database::getInstance()->getConnection();

    //     // Verifica se é a primeira imagem (principal)
    //     $stmt = $db->prepare("SELECT COUNT(*) FROM produto_imagens WHERE produto_id = ?");
    //     $stmt->execute([$produtoId]);
    //     $isPrincipal = (int)$stmt->fetchColumn() === 0 ? 1 : 0;

    //     // Ordem
    //     $stmt = $db->prepare("SELECT COALESCE(MAX(ordem),0)+1 FROM produto_imagens WHERE produto_id = ?");
    //     $stmt->execute([$produtoId]);
    //     $ordem = (int)$stmt->fetchColumn();

    //     $db->prepare(
    //         "INSERT INTO produto_imagens (produto_id, arquivo, principal, ordem)
    //          VALUES (?, ?, ?, ?)"
    //     )->execute([$produtoId, $arquivo, $isPrincipal, $ordem]);

    //     $imgId = (int)$db->lastInsertId();

    //     $this->json([
    //         'ok'        => true,
    //         'id'        => $imgId,
    //         'arquivo'   => $arquivo,
    //         'url'       => UPLOAD_URL . '/products/' . $arquivo,
    //         'principal' => $isPrincipal,
    //     ]);
    // }
    public function uploadImagem(): void
    {
        $this->verifyCsrf();

        $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        if (!$produtoId) {
            $this->json(['ok' => false, 'msg' => 'Produto inválido.']);
        }

        $db = Database::getInstance()->getConnection();

        // [NOVO] Confirma que o produto EXISTE antes de aceitar imagem.
        // Sem isto, um produto_id forjado gera imagem órfã no R2 (lixo + custo).
        $st = $db->prepare("SELECT 1 FROM produtos WHERE id = ? LIMIT 1");
        $st->execute([$produtoId]);
        if (!$st->fetchColumn()) {
            LogService::warning('Upload de imagem para produto inexistente', [
                'produto_id' => $produtoId,
            ], 'media');
            $this->json(['ok' => false, 'msg' => 'Produto não encontrado.']);
        }
    

        try {
            // Valida (magic bytes, não extensão), reprocessa (destrói payload),
            // gera WebP em 2 tamanhos, sobe pro R2, devolve as URLs.
            $urls = $this->img->upload(
                $_FILES['imagem'] ?? [],
                'produtos',
                ['full' => 1200, 'thumb' => 400]   // presets do contexto produto
            );
                    

            if ($urls === null) {
                $this->json(['ok' => false, 'msg' => 'Nenhuma imagem enviada.']);
            }

        } catch (\RuntimeException $e) {
            // Arquivo inválido (formato, tamanho, dimensão, ou não-imagem real)
            LogService::warning('Falha na validação de imagem de produto', [
                'produto_id' => $produtoId,
                'motivo'     => $e->getMessage(),
            ], 'media');
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);

        } catch (\Throwable $e) {            
            // Falha inesperada (R2 fora, GD, etc.) -> log completo, resposta genérica
            LogService::exception($e, 'error', 'media', ['produto_id' => $produtoId]);
            $this->json(['ok' => false, 'msg' => 'Erro ao processar a imagem. debug']);
        }

        // ── Persistência (lógica de principal/ordem PRESERVADA) ──────────
        // Primeira imagem do produto vira a principal.
        $stmt = $db->prepare("SELECT COUNT(*) FROM produto_imagens WHERE produto_id = ?");
        $stmt->execute([$produtoId]);
        $isPrincipal = (int) $stmt->fetchColumn() === 0 ? 1 : 0;

        $stmt = $db->prepare("SELECT COALESCE(MAX(ordem),0)+1 FROM produto_imagens WHERE produto_id = ?");
        $stmt->execute([$produtoId]);
        $ordem = (int) $stmt->fetchColumn();

        // A coluna `arquivo` agora guarda a URL COMPLETA do R2 (full).
        // Guardamos também a thumb. (Ver nota de schema abaixo.)
        $db->prepare(
            "INSERT INTO produto_imagens (produto_id, arquivo, arquivo_thumb, principal, ordem)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$produtoId, $urls['full'], $urls['thumb'], $isPrincipal, $ordem]);

        $imgId = (int) $db->lastInsertId();

        LogService::audit('Imagem de produto enviada', [
            'produto_id' => $produtoId,
            'imagem_id'  => $imgId,
            'principal'  => $isPrincipal,
        ]);

        $this->json([
            'ok'        => true,
            'id'        => $imgId,
            'url'       => $urls['full'] ?? '',   // URL do R2 direto (não mais UPLOAD_URL)
            'thumb'     => $urls['thumb'] ?? '',
            'principal' => $isPrincipal,
        ]);
    }

    // ── Definir imagem principal ──────────────────────────
    public function setPrincipal(): void {
        $this->verifyCsrf();
        $imgId     = SecurityHelper::sanitizeInt($_POST['imagem_id']  ?? 0);
        $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        if (!$imgId || !$produtoId) $this->json(['ok' => false]);

        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE produto_imagens SET principal=0 WHERE produto_id=?")->execute([$produtoId]);
        $db->prepare("UPDATE produto_imagens SET principal=1 WHERE id=?")->execute([$imgId]);

        $this->json(['ok' => true]);
    }

    // ── Remover imagem ────────────────────────────────────
    public function removerImagem(): void {
        $this->verifyCsrf();
        $imgId = SecurityHelper::sanitizeInt($_POST['imagem_id'] ?? 0);
        if (!$imgId) $this->json(['ok' => false]);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM produto_imagens WHERE id = ? LIMIT 1");
        $stmt->execute([$imgId]);
        $img = $stmt->fetch();

        if (!$img) $this->json(['ok' => false, 'msg' => 'Imagem não encontrada.']);

        $file = UPLOAD_PATH . '/products/' . $img['arquivo'];
        if (file_exists($file)) unlink($file);

        $db->prepare("DELETE FROM produto_imagens WHERE id = ?")->execute([$imgId]);

        // Se era principal, define a próxima como principal
        if ($img['principal']) {
            $stmt = $db->prepare(
                "SELECT id FROM produto_imagens WHERE produto_id = ? ORDER BY ordem ASC LIMIT 1"
            );
            $stmt->execute([$img['produto_id']]);
            $proximo = $stmt->fetchColumn();
            if ($proximo) {
                $db->prepare("UPDATE produto_imagens SET principal=1 WHERE id=?")->execute([$proximo]);
            }
        }

        $this->json(['ok' => true]);
    }

    // ── Reordenar imagens ─────────────────────────────────
    public function reordenarImagens(): void {
        $this->verifyCsrf();
        $ordens = $_POST['ordens'] ?? [];
        if (empty($ordens)) $this->json(['ok' => false]);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE produto_imagens SET ordem = ? WHERE id = ?");
        foreach ($ordens as $ordem => $imgId) {
            $stmt->execute([$ordem, (int)$imgId]);
        }
        $this->json(['ok' => true]);
    }

    // ── Excluir produto ───────────────────────────────────
    public function excluir(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db = Database::getInstance()->getConnection();
        $db->prepare(
            "UPDATE produtos SET deleted_at = NOW(), ativo = 0 WHERE id = ?"
        )->execute([$id]);

        $this->json(['ok' => true, 'msg' => 'Produto excluído.']);
    }

    // ── Toggle ativo ──────────────────────────────────────
    public function toggleAtivo(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT ativo FROM produtos WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $novo = (int)$stmt->fetchColumn() ? 0 : 1;

        $db->prepare("UPDATE produtos SET ativo = ? WHERE id = ?")->execute([$novo, $id]);
        $this->json(['ok' => true, 'ativo' => $novo]);
    }

    // ── Helpers privados ──────────────────────────────────
    private function getFormData(): array {
        $db = Database::getInstance()->getConnection();

        $categorias = $db->query(
            "SELECT id, nome, parent_id FROM categorias WHERE ativo=1 ORDER BY parent_id ASC, nome ASC"
        )->fetchAll();

        $marcas = $db->query(
            "SELECT id, nome FROM marcas WHERE ativo=1 ORDER BY nome ASC"
        )->fetchAll();

        $familias = $db->query(
            "SELECT id, nome FROM familia_produtos ORDER BY nome ASC"
        )->fetchAll();

        return compact('categorias', 'marcas', 'familias');
    }

    private function getAtributosTipos(): array {
        return Database::getInstance()->getConnection()->query(
            "SELECT id, nome, slug, tipo_display, papel
             FROM atributo_tipos
             ORDER BY papel ASC, ordenacao ASC, nome ASC"
        )->fetchAll();
    }

    // <?php
    // admin/controllers/ProdutosController.php
    // Adicionar estes dois métodos ao controller existente

    // ── Endpoint: alterar preço do produto (simples ou pai) ───
    public function alterarPreco(): void {
        $this->verifyCsrf();

        $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        $preco     = (float)str_replace(',', '.', $_POST['preco'] ?? 0);
        $modo      = $_POST['modo'] ?? 'simples'; // 'simples' | 'pai' | 'todos'

        if (!$produtoId || $preco <= 0) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }

        $db = Database::getInstance()->getConnection();

        try {
            // ── GATILHO: preço antigo antes ──
            $gatilho = new ProdutoGatilhoService();
            $precoAntigo = $gatilho->lerPrecoAtual($produtoId);
            // ──────────────────────────────────

            $db->beginTransaction();
            $db->prepare("UPDATE produtos SET preco = ? WHERE id = ?")
            ->execute([$preco, $produtoId]);

            // Se modo 'todos' → atualiza todos os SKUs também
            if ($modo === 'todos') {
                $db->prepare(
                    "UPDATE produto_skus SET preco = ? WHERE produto_id = ? AND ativo = 1"
                )->execute([$preco, $produtoId]);
            }

            $db->commit();

            $gatilho->verificarQuedaPreco($produtoId, $precoAntigo, (float)$preco);

            $this->json([
                'ok'  => true,
                'msg' => $modo === 'todos'
                        ? 'Preço atualizado em todas as variações.'
                        : 'Preço atualizado.',
            ]);

        } catch (\Exception $e) {
            $db->rollBack();
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── Endpoint: alterar preço de SKU específico ─────────────
    public function alterarPrecoSku(): void {
        $this->verifyCsrf();

        $skuId     = SecurityHelper::sanitizeInt($_POST['sku_id']     ?? 0);
        $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        $preco     = (float)str_replace(',', '.', $_POST['preco']     ?? 0);
        $todos     = isset($_POST['todos']) && $_POST['todos'] == '1';

        if (!$skuId || !$produtoId || $preco <= 0) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }

        $db = Database::getInstance()->getConnection();

        try {
            $db->beginTransaction();

            if ($todos) {
                // Aplica em todos os SKUs do produto
                $db->prepare(
                    "UPDATE produto_skus SET preco = ? WHERE produto_id = ? AND ativo = 1"
                )->execute([$preco, $produtoId]);
                // Atualiza também o produto pai
                $db->prepare(
                    "UPDATE produtos SET preco = ? WHERE id = ?"
                )->execute([$preco, $produtoId]);
            } else {
                // Só este SKU
                $db->prepare(
                    "UPDATE produto_skus SET preco = ? WHERE id = ? AND produto_id = ?"
                )->execute([$preco, $skuId, $produtoId]);
            }

            $db->commit();

            $this->json([
                'ok'  => true,
                'msg' => $todos ? 'Preço atualizado em todas as variações.' : 'Preço do SKU atualizado.',
            ]);

        } catch (\Exception $e) {
            $db->rollBack();
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── Endpoint: retorna SKUs de um produto para edição ──────
    public function skusParaEdicao(): void {
        $produtoId = SecurityHelper::sanitizeInt($_GET['produto_id'] ?? 0);
        if (!$produtoId) $this->json(['ok' => false, 'skus' => []]);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT ps.id, ps.sku, ps.preco, ps.preco_promo, ps.ativo,
                    COALESCE(
                        (SELECT es.saldo FROM estoque_saldo es
                        WHERE es.sku_id = ps.id AND es.produto_id = ps.produto_id
                        LIMIT 1),
                        ps.estoque
                    ) AS estoque,
                    GROUP_CONCAT(
                        CONCAT(at.nome, ': ', sa.valor)
                        ORDER BY at.ordenacao ASC
                        SEPARATOR ' | '
                    ) AS atributos_str
            FROM produto_skus ps
            LEFT JOIN sku_atributos sa  ON sa.sku_id = ps.id
            LEFT JOIN atributo_tipos at ON at.id = sa.atributo_tipo_id
            WHERE ps.produto_id = ? AND ps.ativo = 1
            GROUP BY ps.id, ps.sku, ps.preco, ps.preco_promo, ps.ativo, ps.estoque, ps.produto_id
            ORDER BY ps.id ASC"
        );
        $stmt->execute([$produtoId]);
        $skus = $stmt->fetchAll();

        $this->json(['ok' => true, 'skus' => $skus]);
    }

    // ── POST /admin/produtos/{id}/sync-bling ──────────────
    // Sincroniza UM produto com o Bling e RETORNA o erro em caso
    // de falha — é a ferramenta de diagnóstico do vínculo.
    public function syncBling(int $id): void
    {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super', 'gerente');
        $db      = Database::getInstance()->getConnection();
        $prod = $db->prepare("SELECT id, sku_legado, bling_id, tem_variacao FROM produtos WHERE id = ? LIMIT 1");
        $prod->execute([$id]);
        $p = $prod->fetch();
        if (!$p) { $this->json(['ok' => false, 'msg' => 'Produto não encontrado.']); }

        $svc = new BlingEstoqueService();

        try {
            // Resolve/atualiza o vínculo do pai
            $blingId = $p['bling_id']
                ?: $svc->resolverBlingIdProduto((string)($p['sku_legado'] ?? ''), $id);

            if (!$blingId) {
                $this->json([
                    'ok'  => false,
                    'msg' => "Nenhum produto no Bling com o código \"{$p['sku_legado']}\". "
                           . "Confira se o SKU/Código do produto bate com o cadastro no Bling.",
                ]);
            }

            // Produto simples: sincroniza o estoque agora e mostra o saldo
            if (!(int)$p['tem_variacao']) {
                $r = $svc->sincronizarProdutoSimples($id);   // ver método abaixo
                $this->json($r);
            }

            // Produto com variação: vínculo do pai OK, estoque vem dos SKUs
            $this->json([
                'ok'  => true,
                'msg' => "Vínculo do produto-pai OK (Bling ID {$blingId}). "
                       . "O estoque das variações sincroniza pelos SKUs.",
                'bling_id' => $blingId,
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => 'Bling: ' . $e->getMessage()]);
        }
    }
}