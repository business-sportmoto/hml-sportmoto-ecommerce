<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/AdminUsuarioController.php  (v2)
// Muda o paradigma de criação: em vez de cadastrar nome+e-mail+
// senha, BUSCA um usuário existente (por e-mail/CPF) e o PROMOVE
// a admin — a senha é a que a pessoa já usa. Adiciona código de
// vendedor (tabela `vendedores`) e dashboard de comissão.
// SÓ SUPER (gestão de acesso é escalação de privilégio).
// ════════════════════════════════════════════════════════

final class AdminUsuarioController extends Controller {

    private PDO $db;
    private VendedorAdmin $vend;

    public function __construct() {
        AuthHelper::requireAdminLevel('super');
        $this->db   = Database::getInstance()->getConnection();
        $this->vend = new VendedorAdmin();
    }

    // ══════════════════════════════════════════════════
    // LISTAGEM
    // ══════════════════════════════════════════════════

    public function index(): void {
        $nivel = SecurityHelper::sanitizeString($_GET['nivel'] ?? '');
        $nivel = Cargos::existe($nivel) ? $nivel : null;

        $sql = "SELECT a.id AS admin_id, a.nivel, a.usuario_id,
                       u.nome, u.email, u.ativo, u.ultimo_login,
                       v.codigo AS codigo_vendedor, v.ativo AS vendedor_ativo
                FROM admins a
                JOIN usuarios u   ON u.id = a.usuario_id
                LEFT JOIN vendedores v ON v.usuario_id = a.usuario_id
                WHERE u.deleted_at IS NULL"
             . ($nivel ? " AND a.nivel = ?" : "")
             . " ORDER BY u.ativo DESC, u.nome";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($nivel ? [$nivel] : []);

        $this->render('usuarios/index', [
            'usuarios'     => $stmt->fetchAll(),
            'nivelFiltro'  => $nivel,
            'meuUsuarioId' => AuthHelper::usuarioId(),
            'salvo'        => !empty($_GET['salvo']),
        ], 'admin');
    }

    // ── GET /admin/usuarios/novo ──────────────────────────
    public function novo(): void {
        $this->render('usuarios/form', [
            'usuario' => null,
            'erro'    => null,
        ], 'admin');
    }

    // ══════════════════════════════════════════════════
    // BUSCA AJAX — GET /admin/usuarios/buscar?termo=
    // ══════════════════════════════════════════════════

    public function buscar(): void {
        // Rate limit: busca por CPF/e-mail é enumerável — 30/min/IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (SecurityHelper::rateLimitExceeded('usr_busca_' . md5($ip), 30, 60)) {
            $this->json(['ok' => false, 'msg' => 'Muitas buscas. Aguarde um instante.']);
            return;
        }

        $termo = SecurityHelper::sanitizeString($_GET['termo'] ?? '');
        $u = $this->vend->buscarUsuario($termo);

        if (!$u) {
            $this->json(['ok' => false, 'msg' => 'Nenhum usuário encontrado com este e-mail/CPF.']);
            return;
        }
        if ($u['ja_admin']) {
            $this->json(['ok' => false, 'msg' => 'Este usuário já tem acesso ao painel. Edite-o na lista.']);
            return;
        }

        // Payload mínimo — nunca expõe dados sensíveis
        $this->json([
            'ok' => true,
            'usuario' => [
                'id'           => (int)$u['id'],
                'nome'         => $u['nome'],
                'email'        => $u['email'],
                'ja_vendedor'  => $u['ja_vendedor'], // código antigo, se houver
                'sugestao_cod' => $this->vend->gerarCodigo($u['nome']),
            ],
        ]);
    }

    // ══════════════════════════════════════════════════
    // PROMOVER usuário existente — POST /admin/usuarios/promover
    // ══════════════════════════════════════════════════

    public function promover(): void {
        $this->verifyCsrf();

        $usuarioId = (int)($_POST['usuario_id'] ?? 0);
        $nivel     = SecurityHelper::sanitizeString($_POST['nivel'] ?? '');

        // Revalida TUDO server-side — a busca Ajax é conveniência,
        // não autorização. Um POST forjado não pode pular checagens.
        $stmt = $this->db->prepare(
            "SELECT id, nome FROM usuarios
             WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$usuarioId]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            $this->flashErro('Usuário inválido.'); return;
        }
        if (!Cargos::existe($nivel)) {
            $this->flashErro('Cargo inválido.'); return;
        }
        if ($this->vend->jaEhAdmin($usuarioId)) {
            $this->flashErro('Este usuário já tem acesso ao painel.'); return;
        }

        // Código de vendedor: obrigatório só se cargo = vendedor
        $codigo = null;
        if ($nivel === 'vendedor') {
            $codigo = $this->resolverCodigo($usuarioId, $usuario['nome']);
            if ($codigo === null) { return; } // flashErro já disparado
        }

        try {
            $this->db->beginTransaction();

            $this->db->prepare(
                "INSERT INTO admins (usuario_id, nivel) VALUES (?,?)"
            )->execute([$usuarioId, $nivel]);

            if ($codigo !== null) {
                $this->vend->ativarVendedor($usuarioId, $usuario['nome'], $codigo);
            }

            $this->db->commit();
        } catch (\PDOException $e) {
            $this->db->rollBack();
            $msg = ((int)($e->errorInfo[1] ?? 0) === 1062)
                 ? 'Este usuário já tem acesso ou o código de vendedor já existe.'
                 : 'Erro ao conceder acesso. Tente novamente.';
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                error_log('[AdminUsuario::promover] ' . $e->getMessage());
            }
            $this->flashErro($msg); return;
        }

        AuthLogService::registrar(null, 'admin_create', 'success', 'local', [
            'por_usuario_id'  => AuthHelper::usuarioId(),
            'alvo_usuario_id' => $usuarioId,
            'nivel'           => $nivel,
            'codigo_vendedor' => $codigo,
        ]);

        $this->redirect(ADMIN_URL . '/usuarios?salvo=1');
    }

    // ══════════════════════════════════════════════════
    // EDITAR — GET/POST /admin/usuarios/{id}
    // ══════════════════════════════════════════════════

    public function editar(int $id): void {
        $u = $this->carregar($id);
        if (!$u) { http_response_code(404); $this->render('errors/404', [], 'admin'); return; }
        $u['eh_self'] = (int)$u['usuario_id'] === AuthHelper::usuarioId();

        $this->render('usuarios/form', ['usuario' => $u, 'erro' => null], 'admin');
    }

    public function atualizar(int $id): void {
        $this->verifyCsrf();

        $atual = $this->carregar($id);
        if (!$atual) { http_response_code(404); $this->render('errors/404', [], 'admin'); return; }
        $ehSelf = (int)$atual['usuario_id'] === AuthHelper::usuarioId();

        $nivel = SecurityHelper::sanitizeString($_POST['nivel'] ?? '');
        if (!Cargos::existe($nivel)) { $this->flashErro('Cargo inválido.'); return; }

        // Anti-lockout: self não muda o próprio nível/ativo
        $novoNivel = $ehSelf ? $atual['nivel'] : $nivel;
        $novoAtivo = $ehSelf ? 1 : (isset($_POST['ativo']) ? 1 : 0);

        // Transição de vendedor
        $eraVendedor = $atual['nivel'] === 'vendedor';
        $seraVendedor = $novoNivel === 'vendedor';
        $codigo = null;
        if ($seraVendedor) {
            $codigo = $this->resolverCodigo((int)$atual['usuario_id'], $atual['nome']);
            if ($codigo === null) return;
        }

        try {
            $this->db->beginTransaction();

            $this->db->prepare(
                "UPDATE usuarios SET nome = ?, ativo = ? WHERE id = ?"
            )->execute([
                trim(SecurityHelper::sanitizeString($_POST['nome'] ?? $atual['nome'])),
                $novoAtivo, (int)$atual['usuario_id'],
            ]);

            $this->db->prepare("UPDATE admins SET nivel = ? WHERE id = ?")
                     ->execute([$novoNivel, $id]);

            if ($seraVendedor) {
                $this->vend->ativarVendedor((int)$atual['usuario_id'], $atual['nome'], $codigo);
            } elseif ($eraVendedor && !$seraVendedor) {
                // Rebaixou: desativa (preserva histórico de comissão)
                $this->vend->desativarVendedor((int)$atual['usuario_id']);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('[AdminUsuario::atualizar] ' . $e->getMessage());
            $this->flashErro('Erro ao salvar. Tente novamente.'); return;
        }

        AuthLogService::registrar(null, 'admin_update', 'success', 'local', [
            'por_usuario_id'  => AuthHelper::usuarioId(),
            'alvo_usuario_id' => (int)$atual['usuario_id'],
            'nivel'           => $novoNivel, 'ativo' => $novoAtivo,
            'codigo_vendedor' => $codigo,
        ]);

        $this->redirect(ADMIN_URL . '/usuarios?salvo=1');
    }

    public function toggle(int $id): void {
        $this->verifyCsrf();
        $alvo = $this->carregar($id);
        if (!$alvo) { $this->json(['ok' => false, 'msg' => 'Usuário não encontrado.']); return; }
        if ((int)$alvo['usuario_id'] === AuthHelper::usuarioId()) {
            $this->json(['ok' => false, 'msg' => 'Você não pode desativar a si mesmo.']); return;
        }
        $this->db->prepare("UPDATE usuarios SET ativo = 1 - ativo WHERE id = ?")
                 ->execute([(int)$alvo['usuario_id']]);
        $this->json(['ok' => true]);
    }

    // ══════════════════════════════════════════════════
    // DASHBOARD DE VENDAS / COMISSÃO
    // ══════════════════════════════════════════════════

    // GET /admin/usuarios/vendas — ranking geral (gestor)
    public function vendas(): void {
        [$de, $ate] = $this->periodo();
        $this->render('usuarios/vendas', [
            'ranking' => $this->vend->rankingVendedores($de, $ate),
            'de' => $de, 'ate' => $ate,
        ], 'admin');
    }

    // GET /admin/usuarios/vendas/{id} — detalhe de um vendedor
    public function vendasVendedor(int $id): void {
        $u = $this->carregar($id);
        if (!$u || !$u['codigo_vendedor']) {
            http_response_code(404); $this->render('errors/404', [], 'admin'); return;
        }
        [$de, $ate] = $this->periodo();

        $this->render('usuarios/vendas-detalhe', [
            'usuario' => $u,
            'resumo'  => $this->vend->vendasDoVendedor($u['codigo_vendedor'], $de, $ate),
            'serie'   => $this->vend->seriePorDia($u['codigo_vendedor'], $de, $ate),
            'de' => $de, 'ate' => $ate,
        ], 'admin');
    }

    // ══════════════════════════════════════════════════

    /**
     * Resolve o código de vendedor do POST: gerado (default) ou
     * editado à mão. Valida formato e unicidade. Retorna null +
     * flashErro em caso de problema.
     */
    private function resolverCodigo(int $usuarioId, string $nome): ?string {
        $manual = strtoupper(trim(SecurityHelper::sanitizeString($_POST['codigo_vendedor'] ?? '')));

        if ($manual === '') {
            // Reusa código histórico se existir, senão gera novo
            return $this->vend->codigoVendedorDe($usuarioId) ?? $this->vend->gerarCodigo($nome);
        }
        if (!$this->vend->codigoValido($manual)) {
            $this->flashErro('Código do vendedor: 3 a 12 caracteres, apenas letras e números.');
            return null;
        }
        if ($this->vend->codigoEmUso($manual, $usuarioId)) {
            $this->flashErro('Este código de vendedor já está em uso.');
            return null;
        }
        return $manual;
    }

    private function carregar(int $adminId): ?array {
        $stmt = $this->db->prepare(
            "SELECT a.id AS admin_id, a.nivel, a.usuario_id,
                    u.nome, u.email, u.ativo,
                    v.codigo AS codigo_vendedor, v.ativo AS vendedor_ativo
             FROM admins a
             JOIN usuarios u ON u.id = a.usuario_id
             LEFT JOIN vendedores v ON v.usuario_id = a.usuario_id
             WHERE a.id = ? AND u.deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$adminId]);
        return $stmt->fetch() ?: null;
    }

    private function periodo(): array {
        $de  = SecurityHelper::sanitizeString($_GET['de']  ?? date('Y-m-d', strtotime('-30 days')));
        $ate = SecurityHelper::sanitizeString($_GET['ate'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de))  $de  = date('Y-m-d', strtotime('-30 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) $ate = date('Y-m-d');
        return [$de, $ate];
    }

    private function flashErro(string $msg): void {
        Session::flash('error', $msg);
        $this->redirect(ADMIN_URL . '/usuarios');
    }
}