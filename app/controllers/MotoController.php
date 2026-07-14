<?php
class MotoController extends Controller {

    private MotoCompatibilidade $compat;

    public function __construct() {
        $this->compat = new MotoCompatibilidade();
    }

    /**
     * Rota: /montadora/{slug}
     * Rota: /montadora/{slug}/{modelo-slug}-{ano}
     */
    public function catalogo(string $montadoraSlug = '', string $resto = ''): void {
        // $montadoraSlug = 'honda'
        // $resto         = 'cg-160-2017' ou 'cg-160' ou ''

        $modeloSlug = null;
        $ano        = null;

        if ($resto) {
            // Tenta extrair ano dos últimos 4 dígitos
            // 'cg-160-2017' → modelo='cg-160', ano=2017
            if (preg_match('/^(.+)-(\d{4})$/', $resto, $m)) {
                $modeloSlug = $m[1];
                $ano        = (int)$m[2];
            } else {
                // Sem ano: 'cg-160' → só modelo
                $modeloSlug = $resto;
            }
        }

        $resolved = $this->compat->resolveUrl($montadoraSlug, $modeloSlug, $ano);

        if (empty($resolved['montadora'])) {
            $this->notFound();
            return;
        }

        $montadora   = $resolved['montadora'];
        $modelo      = $resolved['modelo']    ?? null;

        // ── Vehicle bar: exibe sempre que a MOTO PRINCIPAL do cliente
        // tiver produtos compatíveis em algum lugar. Quando o cliente já
        // está navegando na página da própria moto principal, a barra
        // continua aparecendo mas em modo "moto atual" (ver $ehMotoAtual),
        // confirmando visualmente que esta é a moto cadastrada — em vez
        // de simplesmente sumir.
        $veiculoAtivo      = $_SESSION['meu_veiculo'] ?? null;
        $mostrarVeiculoBar = false;
        $ehMotoAtual       = false;

        if ($veiculoAtivo && !empty($veiculoAtivo['modelo_id'])) {
            $ehMotoAtual = $modelo && (int)$veiculoAtivo['modelo_id'] === (int)$modelo['id'];

            $db = Database::getInstance()->getConnection();
            $stmtVeiculo = $db->prepare(
                "SELECT 1 FROM produto_compatibilidade pc
                 JOIN produtos p ON p.id = pc.produto_id
                 WHERE pc.modelo_id = ?
                   AND p.ativo = 1 AND p.deleted_at IS NULL
                 LIMIT 1"
            );
            $stmtVeiculo->execute([(int)$veiculoAtivo['modelo_id']]);
            $mostrarVeiculoBar = (bool)$stmtVeiculo->fetchColumn();
        }
        $page        = max(1, (int)($_GET['page'] ?? 1));
        $perPage     = 24;
        $offset      = ($page - 1) * $perPage;
        $filtros     = [
            'categoria_id' => (int)($_GET['categoria_id'] ?? 0),
            'marca_id'     => (int)($_GET['marca_id']     ?? 0),
            'q'            => trim($_GET['q']             ?? ''),
            // 'ordem' chegava até aqui mas nunca era repassado ao
            // model — o select de ordenação mudava a URL sem efeito.
            'ordem'        => $this->sanitizeOrdem($_GET['ordem'] ?? 'relevancia'),
        ];

        $montadoraId = (int)$montadora['id'];
        $modeloId    = $modelo ? (int)$modelo['id'] : null;

        $produtos = $this->compat->getProdutosCompativeis(
            $montadoraId, $modeloId, $ano, $perPage, $offset, $filtros
        );
        $total = $this->compat->countCompativeis(
            $montadoraId, $modeloId, $ano, $filtros
        );

        // Breadcrumb
        $breadcrumb = [
            ['url' => BASE_URL . '/motos', 'label' => 'Motos'],
            ['url' => BASE_URL . '/montadora/' . $montadora['slug'], 'label' => $montadora['nome']],
        ];
        if ($modelo) {
            $breadcrumb[] = [
                'url'   => BASE_URL . '/montadora/' . $montadora['slug'] . '/' . $modelo['slug'],
                'label' => $modelo['nome'],
            ];
        }
        if ($ano) {
            $breadcrumb[] = [
                'url'   => BASE_URL . '/montadora/' . $montadora['slug'] . '/' . $modeloSlug . '-' . $ano,
                'label' => (string)$ano,
            ];
        }

        $seoTitle = $this->buildSeoTitle($montadora, $modelo, $ano);
        $seoDesc  = $this->buildSeoDesc($montadora, $modelo, $ano, $total);

        // Modelos disponíveis (sidebar — quando só tem montadora)
        $modelos = [];
        if (!$modelo) {
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "SELECT mo.id, mo.nome, mo.slug, mo.thumb,
                        COUNT(DISTINCT pc.produto_id) AS total_produtos
                FROM moto_modelos mo
                LEFT JOIN produto_compatibilidade pc ON pc.modelo_id = mo.id
                WHERE mo.montadora_id = ? AND mo.ativo = 1
                GROUP BY mo.id
                HAVING total_produtos > 0
                ORDER BY mo.nome ASC"
            );
            $stmt->execute([$montadoraId]);
            $modelos = $stmt->fetchAll();
        }

        // Anos disponíveis (sidebar — quando tem modelo mas não tem ano)
        $anos = [];
        if ($modeloId && !$ano) {
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "SELECT ma.ano,
                        COUNT(DISTINCT pc.produto_id) AS total_produtos
                FROM moto_anos ma
                LEFT JOIN produto_compatibilidade pc
                        ON pc.modelo_id = ma.modelo_id
                    AND (
                        pc.ano_inicio IS NULL
                        OR pc.ano_inicio <= ma.ano
                    )
                    AND (
                        pc.ano_fim IS NULL
                        OR pc.ano_fim >= ma.ano
                    )
                WHERE ma.modelo_id = ?
                GROUP BY ma.ano
                ORDER BY ma.ano DESC"
            );
            $stmt->execute([$modeloId]);
            $anos = $stmt->fetchAll();
        }

        TrackingService::registrar(
            'catalogo_moto_visto',
            'modelo_moto',
            $modelo ? (int)$modelo['id'] : null,
            [
                'montadora_id' => (int)$montadora['id'],
                'ano'          => $ano,
            ]
        );

        $this->render('moto/catalogo', [
            'montadora'   => $montadora,
            'modelo'      => $modelo,
            'ano'         => $ano,
            'produtos'    => $produtos,
            'total'       => $total,
            'page'        => $page,
            'perPage'     => $perPage,
            'breadcrumb'  => $breadcrumb,
            'seoTitle'    => $seoTitle,
            'seoDesc'     => $seoDesc,
            'modelos'     => $modelos,
            'anos'        => $anos,
            'filtros'     => $filtros,
            'modeloSlug'  => $modeloSlug,
            'mostrarVeiculoBar' => $mostrarVeiculoBar,
            'veiculoAtivo'      => $veiculoAtivo,
            'ehMotoAtual'       => $ehMotoAtual,
        ]);
    }

    /**
     * Listagem de montadoras — /motos
     */
    public function montadoras(): void {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->query(
            "SELECT mm.*,
                    COUNT(DISTINCT pc.produto_id) AS total_produtos,
                    COUNT(DISTINCT mo.id)          AS total_modelos
            FROM moto_montadoras mm
            LEFT JOIN moto_modelos mo ON mo.montadora_id = mm.id AND mo.ativo = 1
            LEFT JOIN produto_compatibilidade pc ON pc.montadora_id = mm.id
            WHERE mm.ativo = 1
            GROUP BY mm.id
            HAVING total_produtos > 0
            ORDER BY mm.nome ASC"
        );
        $montadoras = $stmt->fetchAll();

        $this->render('moto/montadoras', [
            'montadoras' => $montadoras,
            'seoTitle'   => 'Peças por Moto',
            'seoDesc'    => 'Encontre peças compatíveis com a sua moto.',
        ]);
    }

    public function ajaxModelos(): void {
        $montadoraId = SecurityHelper::sanitizeInt($_GET['montadora_id'] ?? 0);
        if (!$montadoraId) $this->json([]);

        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT id, nome, slug FROM moto_modelos
            WHERE montadora_id = ? AND ativo = 1
            ORDER BY nome ASC"
        );
        $stmt->execute([$montadoraId]);
        $this->json($stmt->fetchAll());
    }

    public function ajaxAnos(): void {
        $modeloId = SecurityHelper::sanitizeInt($_GET['modelo_id'] ?? 0);
        if (!$modeloId) $this->json([]);

        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT DISTINCT ano FROM moto_anos
            WHERE modelo_id = ?
            ORDER BY ano DESC"
        );
        $stmt->execute([$modeloId]);
        $this->json($stmt->fetchAll());
    }

    public function ajaxSlugModelo(): void {
        $modeloId = SecurityHelper::sanitizeInt($_GET['modelo_id'] ?? 0);
        if (!$modeloId) $this->json(['slug' => null]);

        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT slug FROM moto_modelos WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$modeloId]);
        $this->json(['slug' => $stmt->fetchColumn() ?: null]);
    }

    public function buscarRedirect(): void {
        $montadoraId = SecurityHelper::sanitizeInt($_GET['montadora_id'] ?? 0);
        $modeloId    = SecurityHelper::sanitizeInt($_GET['modelo_id']    ?? 0);
        $ano         = SecurityHelper::sanitizeInt($_GET['ano']          ?? 0);

        if (!$montadoraId) {
            $this->redirect(BASE_URL . '/motos');
            return;
        }

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT slug FROM moto_montadoras WHERE id = ? LIMIT 1");
        $stmt->execute([$montadoraId]);
        $montSlug = $stmt->fetchColumn();

        if (!$montSlug) {
            $this->redirect(BASE_URL . '/motos');
            return;
        }

        $url = BASE_URL . '/montadora/' . $montSlug;

        if ($modeloId) {
            $stmt = $db->prepare("SELECT slug FROM moto_modelos WHERE id = ? LIMIT 1");
            $stmt->execute([$modeloId]);
            $modSlug = $stmt->fetchColumn();
            if ($modSlug) {
                $url .= '/' . $modSlug;
                if ($ano) $url .= '-' . $ano;
            }
        }

        $this->redirect($url);
    }
    
    /**
     * Valida 'ordem' contra whitelist — mesmos valores que
     * MotoCompatibilidade::getProdutosCompativeis() deve aceitar.
     */
    private function sanitizeOrdem(string $valor): string {
        static $permitidos = [
            'relevancia', 'novidades', 'menor_preco', 'maior_preco',
            'maior_desconto', 'mais_vendidos',
        ];
        return in_array($valor, $permitidos, true) ? $valor : 'relevancia';
    }

    private function buildSeoTitle(array $mont, ?array $mod, ?int $ano): string {
        if ($ano && $mod) {
            return "Peças para {$mont['nome']} {$mod['nome']} {$ano}";
        }
        if ($mod)  return "Peças para {$mont['nome']} {$mod['nome']}";
        return "Peças para {$mont['nome']}";
    }

    private function buildSeoDesc(array $mont, ?array $mod, ?int $ano, int $total): string {
        $moto = $mont['nome'];
        if ($mod) $moto .= ' ' . $mod['nome'];
        if ($ano) $moto .= ' ' . $ano;
        return "{$total} produto(s) compatível(is) com {$moto}. Filtros, frete e parcelamento.";
    }

    
}