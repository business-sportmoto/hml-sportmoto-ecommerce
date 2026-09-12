<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/EstoquePonteController.php
//
// A tela de operação da ponte de estoque Syscar <-> Bling.
//
// REGRA DE OURO DESTA TELA: leitura nunca escreve. O getStockLog() do
// admin.loja reenvia movimentos ao Bling a cada visualização, sem teto e sem
// idempotência — duas pessoas com a tela aberta aplicam o mesmo movimento
// duas vezes. Aqui reenviar é POST explícito, com CSRF e cargo.
//
// Ver docs/sportmoto-os/12-decisoes-tecnicas/estoque-modulo-especificacao.md
// ════════════════════════════════════════════════════════

class EstoquePonteController extends Controller
{
    private EstoquePonteService $ponte;
    private PDO $db;

    public function __construct()
    {
        // Construtor com guard: sem isto a rota serve para qualquer pessoa na
        // internet — o painel não barra anônimo (CLAUDE.md §4.8.6).
        AuthHelper::requirePermissaoOuNivel('estoque', 'super', 'gerente', 'estoque');

        $this->ponte = new EstoquePonteService();
        $this->db    = Database::getInstance()->getConnection();
    }

    // ── GET /admin/estoque/ponte ──────────────────────────
    public function index(): void
    {
        $filtros = [
            'status'  => SecurityHelper::sanitizeString($_GET['status']  ?? ''),
            'direcao' => SecurityHelper::sanitizeString($_GET['direcao'] ?? ''),
            'q'       => SecurityHelper::sanitizeString($_GET['q']       ?? ''),
        ];
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 40;

        $where  = '1=1';
        $params = [];

        if ($filtros['status'] !== '' &&
            in_array($filtros['status'], ['pendente','enviando','confirmado','falhou','ignorado'], true)) {
            $where   .= ' AND m.status = ?';
            $params[] = $filtros['status'];
        }
        if (in_array($filtros['direcao'], ['para_bling','para_syscar'], true)) {
            $where   .= ' AND m.direcao = ?';
            $params[] = $filtros['direcao'];
        }
        if ($filtros['q'] !== '') {
            $where   .= ' AND (m.sku_codigo LIKE ? OR m.pedido_bling_id = ?)';
            $params[] = '%' . $filtros['q'] . '%';
            $params[] = ctype_digit($filtros['q']) ? (int)$filtros['q'] : 0;
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM estoque_movimentos m WHERE {$where}"
        );
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT m.*, c.nome AS canal_nome, p.nome AS produto_nome
               FROM estoque_movimentos m
               LEFT JOIN estoque_canais c ON c.id = m.canal_id
               LEFT JOIN produtos p       ON p.id = m.produto_id
              WHERE {$where}
              ORDER BY m.id DESC
              LIMIT ? OFFSET ?"
        );
        foreach ($params as $i => $v) {
            $stmt->bindValue($i + 1, $v);
        }
        $stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        $this->render('estoque/ponte', [
            'movimentos'   => $stmt->fetchAll(),
            'resumo'       => $this->ponte->resumo(),
            'pernas'       => [
                'a' => $this->ponte->pernaLigada('a'),
                'b' => $this->ponte->pernaLigada('b'),
            ],
            'syscarPronto' => (new SyscarClient())->configurado(),
            'total'        => $total,
            'page'         => $page,
            'totalPaginas' => max(1, (int)ceil($total / $perPage)),
            'filtros'      => $filtros,
            'podeOperar'   => AuthHelper::hasLevel('super', 'gerente', 'estoque'),
        ], 'admin');
    }

    // ── GET /admin/estoque/ponte/movimento/{id} ───────────
    // Conteúdo do drawer. Só leitura.
    public function movimento(int $id): void
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, c.nome AS canal_nome, p.nome AS produto_nome,
                    e.origem AS evento_origem, e.tipo AS evento_tipo,
                    e.payload AS evento_payload, e.criado_em AS evento_criado_em
               FROM estoque_movimentos m
               LEFT JOIN estoque_canais c  ON c.id = m.canal_id
               LEFT JOIN produtos p        ON p.id = m.produto_id
               LEFT JOIN estoque_eventos e ON e.id = m.evento_id
              WHERE m.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $mov = $stmt->fetch();

        if (!$mov) {
            $this->json(['ok' => false, 'msg' => 'Movimento não encontrado.'], 404);
        }

        $this->json(['ok' => true, 'movimento' => $mov]);
    }

    // ── POST /admin/estoque/ponte/reenviar ────────────────
    public function reenviar(): void
    {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super', 'gerente', 'estoque');

        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) {
            $this->json(['ok' => false, 'msg' => 'Movimento inválido.']);
        }

        if (!$this->ponte->reenfileirar($id)) {
            $this->json([
                'ok'  => false,
                'msg' => 'Só dá para reenviar movimento pendente ou que falhou. '
                       . 'Confirmado não se reenvia — seria baixa em dobro.',
            ]);
        }

        LogService::audit('Movimento de estoque reenfileirado', [
            'movimento_id' => $id,
            'autor'        => AuthHelper::usuarioId(),
        ]);

        $this->json([
            'ok'  => true,
            'msg' => 'Movimento devolvido para a fila. O worker envia na próxima rodada.',
        ]);
    }

    // ── GET /admin/estoque/ponte/canais ───────────────────
    public function canais(): void
    {
        AuthHelper::requireAdminLevel('super');

        $this->render('estoque/canais', [
            'canais' => $this->db->query(
                "SELECT c.*,
                        (SELECT COUNT(*) FROM estoque_movimentos m
                          WHERE m.canal_id = c.id) AS movimentos
                   FROM estoque_canais c
                  ORDER BY c.nome ASC"
            )->fetchAll(),
        ], 'admin');
    }

    // ── POST /admin/estoque/ponte/canais ──────────────────
    public function canaisSalvar(): void
    {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super');

        $blingLojaId = SecurityHelper::sanitizeInt($_POST['bling_loja_id'] ?? -1);
        $nome        = trim(SecurityHelper::sanitizeString($_POST['nome'] ?? ''));

        if ($blingLojaId < 0 || $nome === '') {
            $this->json(['ok' => false, 'msg' => 'Informe o id da loja no Bling e um nome.']);
        }

        $this->db->prepare(
            "INSERT INTO estoque_canais (bling_loja_id, nome)
                  VALUES (?, ?)
             ON DUPLICATE KEY UPDATE nome = VALUES(nome)"
        )->execute([$blingLojaId, mb_substr($nome, 0, 80)]);

        LogService::audit('Canal de estoque salvo', [
            'bling_loja_id' => $blingLojaId,
            'nome'          => $nome,
            'autor'         => AuthHelper::usuarioId(),
        ]);

        $this->json(['ok' => true, 'msg' => 'Canal salvo.']);
    }
}
