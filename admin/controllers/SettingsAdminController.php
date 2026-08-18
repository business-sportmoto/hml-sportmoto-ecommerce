<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/SettingsAdminController.php
// ════════════════════════════════════════════════════════

class SettingsAdminController extends Controller {

    private PDO $db;

    public function __construct() {
        // parent::__construct();
        AuthHelper::requireAdmin();
        $this->db = Database::getInstance()->getConnection();
    }

    // ── GET /admin/configuracoes ─────────────────────────
    public function index(): void {
        $grupos = $this->getGruposOrdenados();
        $this->render('configuracoes/index', compact('grupos'), 'admin');
    }

    // ── POST /admin/configuracoes/salvar ─────────────────
    // Salva uma chave por vez (chamado pelo drawer via AJAX)
    public function salvar(): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();

        $chave = SecurityHelper::sanitizeString($_POST['chave'] ?? '');
        $valor = $_POST['valor'] ?? '';

        if (empty($chave)) {
            $this->json(['ok' => false, 'msg' => 'Chave inválida.']);
        }

        // Busca o tipo da configuração para sanitizar corretamente
        $stmt = $this->db->prepare(
            "SELECT tipo FROM configuracoes WHERE chave = ? LIMIT 1"
        );
        $stmt->execute([$chave]);
        $row = $stmt->fetch();

        if (!$row) {
            $this->json(['ok' => false, 'msg' => 'Configuração não encontrada.']);
        }

        // Valida e normaliza o valor conforme o tipo
        $valorFinal = $this->sanitizarValor($valor, $row['tipo']);

        if ($valorFinal === null && $row['tipo'] === 'json') {
            $this->json(['ok' => false, 'msg' => 'JSON inválido.']);
        }

        $this->db->prepare(
            "UPDATE configuracoes SET valor = ? WHERE chave = ?"
        )->execute([$valorFinal, $chave]);

        $this->json([
            'ok'          => true,
            'msg'         => 'Configuração salva.',
            'chave'       => $chave,
            'valor'       => $valorFinal,
            'valor_exibir'=> $this->formatarParaExibir($valorFinal, $row['tipo']),
        ]);
    }

    // ── POST /admin/configuracoes/salvar-grupo ────────────
    // Salva múltiplas chaves de uma vez
    public function salvarGrupo(): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();

        $campos = $_POST['campos'] ?? [];
        if (empty($campos) || !is_array($campos)) {
            $this->json(['ok' => false, 'msg' => 'Nenhum campo enviado.']);
        }

        // Busca tipos de todas as chaves enviadas
        $chaves = array_keys($campos);
        $in     = implode(',', array_fill(0, count($chaves), '?'));
        $stmt   = $this->db->prepare(
            "SELECT chave, tipo FROM configuracoes WHERE chave IN ({$in})"
        );
        $stmt->execute($chaves);
        $tipos = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmt = $this->db->prepare(
            "UPDATE configuracoes SET valor = ? WHERE chave = ?"
        );

        foreach ($campos as $chave => $valor) {
            $tipo = $tipos[$chave] ?? 'string';
            $stmt->execute([$this->sanitizarValor($valor, $tipo), $chave]);
        }

        $this->json(['ok' => true, 'msg' => 'Configurações salvas.']);
    }

    // ── GET /admin/configuracoes/tags ─────────────────────
    public function tags(): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $model = new AdminCliente();
        $lista = $model->getTodasTags();
        $this->json(['ok' => true, 'tags' => $lista]);
    }

    // ── POST /admin/configuracoes/tags/salvar ──────────────
    public function salvarTag(): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();
        $model = new AdminCliente();
        $id    = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $dados = [
            'nome'      => SecurityHelper::sanitizeString($_POST['nome']      ?? ''),
            'cor'       => SecurityHelper::sanitizeString($_POST['cor']       ?? '#64748b'),
            'icone_key' => SecurityHelper::sanitizeString($_POST['icone_key'] ?? ''),
            'ativo'     => (int)($_POST['ativo']     ?? 1),
            'ordenacao' => (int)($_POST['ordenacao'] ?? 0),
        ];
        if (empty($dados['nome'])) $this->json(['ok' => false, 'msg' => 'Nome obrigatório.']);
        $novoId = $model->salvarTagDisponivel($dados, $id);
        $this->json(['ok' => true, 'id' => $novoId]);
    }

    // ── POST /admin/configuracoes/tags/{id}/del ────────────
    public function deleteTag(int $id): void {
        AuthHelper::requireAdminLevel('super');
        $this->verifyCsrf();
        $model = new AdminCliente();
        $this->json($model->deleteTagDisponivel($id));
    }

    // ════════════════════════════════════════════════════
    // PRIVADOS
    // ════════════════════════════════════════════════════

    private function getGruposOrdenados(): array {
        $ordemGrupos = [
            'geral'     => ['label' => 'Geral',           'icon' => 'settings'],
            'loja'      => ['label' => 'Loja',            'icon' => 'shopping-bag'],
            'social'    => ['label' => 'Redes Sociais',   'icon' => 'social'],
            'hour'      => ['label' => 'Horário de atendimento',   'icon' => 'hour'],
            'address'      => ['label' => 'Endereço',   'icon' => 'address'],
            'contato'   => ['label' => 'Contato',         'icon' => 'mail'],
            'frete'     => ['label' => 'Frete',           'icon' => 'truck'],
            'email'     => ['label' => 'E-mail',          'icon' => 'send'],
            'pagamento' => ['label' => 'Pagamento',       'icon' => 'credit-card'],
            'seo'       => ['label' => 'SEO',             'icon' => 'search'],
            'seguranca' => ['label' => 'Segurança',       'icon' => 'shield'],
            'pedidos'   => ['label' => 'Pedidos',         'icon' => 'package'],
            'credito'   => ['label' => 'Crédito / Score', 'icon' => 'star'],
            'clientes'  => ['label' => 'Clientes',        'icon' => 'users'],
        ];

        $rows = $this->db->query(
            "SELECT * FROM configuracoes ORDER BY grupo ASC, id ASC"
        )->fetchAll();

        $grupos = [];
        foreach ($rows as $row) {
            $g = $row['grupo'];
            if (!isset($grupos[$g])) {
                $grupos[$g] = [
                    'label'  => $ordemGrupos[$g]['label'] ?? ucfirst($g),
                    'icon'   => $ordemGrupos[$g]['icon']  ?? 'sliders',
                    'itens'  => [],
                ];
            }
            $row['valor_exibir'] = $this->formatarParaExibir($row['valor'], $row['tipo']);
            $grupos[$g]['itens'][] = $row;
        }

        // Reordena conforme $ordemGrupos
        $ordenado = [];
        foreach (array_keys($ordemGrupos) as $key) {
            if (isset($grupos[$key])) {
                $ordenado[$key] = $grupos[$key];
            }
        }
        // Grupos não mapeados vão no final
        foreach ($grupos as $key => $g) {
            if (!isset($ordenado[$key])) $ordenado[$key] = $g;
        }

        return $ordenado;
    }

    private function sanitizarValor(mixed $valor, string $tipo): ?string {
        return match ($tipo) {
            'bool' => in_array(strtolower((string)$valor), ['1','true','on','yes'], true) ? '1' : '0',
            'int'  => (string)(int)$valor,
            'json' => (json_decode((string)$valor) !== null || $valor === 'null')
                        ? (string)$valor
                        : null,
            default => SecurityHelper::sanitizeString((string)$valor),
        };
    }

    private function formatarParaExibir(?string $valor, string $tipo): string {
        if ($valor === null || $valor === '') return '—';
        return match ($tipo) {
            'bool'  => $valor === '1' ? '✓ Ativo' : '✗ Inativo',
            'json'  => '<code style="font-size:11px;">' . htmlspecialchars(mb_substr($valor, 0, 80)) . (mb_strlen($valor) > 80 ? '…' : '') . '</code>',
            'text'  => mb_strlen($valor) > 100 ? mb_substr($valor, 0, 100) . '…' : $valor,
            default => $valor,
        };
    }
}