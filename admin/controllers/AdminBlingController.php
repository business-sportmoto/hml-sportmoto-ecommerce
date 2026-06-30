<?php 
// ════════════════════════════════════════════════════════
// app/controllers/AdminBlingController.php
// Painel de configuração da integração Bling
// ════════════════════════════════════════════════════════
class AdminBlingController extends Controller
{
    private BlingAuthService $auth;
 
    public function __construct()
    {
        // parent::__construct();
        AuthHelper::requireAdmin();
        AuthHelper::requireAdminLevel('super');
        $this->auth = new BlingAuthService();
    }
 
    // ── GET /admin/configuracoes/bling ────────────────────
    public function index(): void
    {
        $db          = Database::getInstance()->getConnection();
        $conectado   = $this->auth->estaConectado();
        $tokenInfo   = $db->query("SELECT expires_at, atualizado_em FROM bling_tokens WHERE id = 1 LIMIT 1")->fetch();
        $logs        = $db->query(
            "SELECT * FROM bling_sync_log ORDER BY criado_em DESC LIMIT 50"
        )->fetchAll();
        $ultimaSync  = $db->query(
            "SELECT MAX(criado_em) FROM bling_sync_log WHERE tipo = 'estoque' AND status = 'ok'"
        )->fetchColumn();
 
        $this->render('configuracoes/bling', compact(
            'conectado', 'tokenInfo', 'logs', 'ultimaSync'
        ),'admin');
    }
 
    // ── POST /admin/configuracoes/bling/credenciais ───────
    public function salvarCredenciais(): void
    {
        $this->verifyCsrf();
        $clientId     = SecurityHelper::sanitizeString($_POST['client_id']     ?? '');
        $clientSecret = SecurityHelper::sanitizeString($_POST['client_secret'] ?? '');
 
        if (!$clientId || !$clientSecret) {
            $this->json(['ok' => false, 'msg' => 'Preencha client_id e client_secret.']);
        }
 
        $this->auth->salvarCredenciais($clientId, $clientSecret);
        $this->json(['ok' => true, 'msg' => 'Credenciais salvas. Agora clique em "Conectar".']);
    }
 
    // ── GET /admin/configuracoes/bling/autorizar ──────────
    // Redireciona para o Bling para o usuário autorizar
    public function autorizar(): void
    {
        $url = $this->auth->getAuthUrl();
        header('Location: ' . $url);
        exit;
    }
 
    // ── GET /admin/configuracoes/bling/callback ───────────
    // Bling redireciona aqui após o usuário autorizar
    public function callback(): void
    {
        $code  = $_GET['code']  ?? '';
        $state = $_GET['state'] ?? '';
 
        if (!$code) {
            $this->redirect(ADMIN_URL . '/configuracoes/bling?erro=sem_code');
        }
 
        try {
            $this->auth->exchangeCode($code, $state);
            $this->redirect(ADMIN_URL . '/configuracoes/bling?sucesso=1');
        } catch (\Throwable $e) {
            $this->redirect(ADMIN_URL . '/configuracoes/bling?erro=' . urlencode($e->getMessage()));
        }
    }
 
    // ── POST /admin/configuracoes/bling/desconectar ───────
    public function desconectar(): void
    {
        $this->verifyCsrf();
        $this->auth->desconectar();
        $this->json(['ok' => true, 'msg' => 'Conta Bling desconectada.']);
    }
 
    // ── POST /admin/configuracoes/bling/sync-estoque ──────
    public function syncEstoque(): void
    {
        $this->verifyCsrf();
        try {
            $result = (new BlingEstoqueService())->sincronizarTudo();
            $this->json([
                'ok'  => true,
                'msg' => "Sincronização concluída: {$result['atualizados']} SKUs atualizados, {$result['erros']} erros.",
            ] + $result);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
 
    // ── POST /admin/configuracoes/bling/forcar-sync ───────
    // Força o reenvio de um pedido específico, mesmo se já enviado.
    // Apaga o mapeamento anterior e tenta novamente, retornando
    // erros detalhados para o admin diagnosticar.
    public function forcarSincronizacao(): void
    {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super', 'gerente');
 
        $pedidoId = (int)($_POST['pedido_id'] ?? 0);
        if (!$pedidoId) $this->json(['ok' => false, 'msg' => 'pedido_id inválido.']);
 
        $db = Database::getInstance()->getConnection();
 
        // Remove mapeamento antigo para forçar novo envio
        $db->prepare("DELETE FROM bling_pedidos_map WHERE pedido_id = ?")->execute([$pedidoId]);
 
        try {
            $result = (new BlingOrderService())->enviarPedido($pedidoId);
 
            // Busca logs recentes para retornar ao admin
            $logs = $db->prepare(
                "SELECT tipo, status, msg_erro, criado_em
                 FROM bling_sync_log
                 WHERE referencia_id = ?
                 ORDER BY criado_em DESC LIMIT 5"
            );
            $logs->execute([$pedidoId]);
 
            $this->json([
                'ok'       => true,
                'bling_id' => $result['bling_id'],
                'msg'      => "Pedido enviado ao Bling com sucesso. ID: {$result['bling_id']}",
                'logs'     => $logs->fetchAll(),
                'status'  => $result
            ]);
        } catch (\Throwable $e) {
            // Busca o log do erro registrado pelo BlingApiClient
            $logErro = $db->prepare(
                "SELECT msg_erro, resposta, criado_em
                 FROM bling_sync_log
                 WHERE tipo = 'pedido' AND status = 'erro'
                 ORDER BY criado_em DESC LIMIT 1"
            );
            $logErro->execute();
            $ultimoErro = $logErro->fetch();
 
            $this->json([
                'ok'         => false,
                'msg'        => $e->getMessage(),
                'detalhe'    => $ultimoErro['resposta'] ?? null,
                'criado_em'  => $ultimoErro['criado_em'] ?? null,
            ]);
        }
    }
 
    // ── GET /admin/configuracoes/bling/status-map ─────────
    public function getStatusMap(): void
    {
        $db     = Database::getInstance()->getConnection();
        $mapa   = $db->query(
            "SELECT * FROM bling_status_map ORDER BY CAST(bling_id AS UNSIGNED)"
        )->fetchAll();
        $locais = $db->query(
            "SELECT slug, label FROM pedido_status WHERE ativo = 1 ORDER BY label"
        )->fetchAll();
        $this->json(['ok' => true, 'mapa' => $mapa, 'status_locais' => $locais]);
    }
 
    // ── POST /admin/configuracoes/bling/status-map ────────
    public function salvarStatusMap(): void
    {
        $this->verifyCsrf();
        $itens = json_decode($_POST['mapa'] ?? '[]', true) ?: [];
        if (empty($itens)) {
            $this->json(['ok' => false, 'msg' => 'Nenhum mapeamento enviado.']);
        }
 
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "INSERT INTO bling_status_map (bling_id, bling_label, status_local)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
               bling_label  = VALUES(bling_label),
               status_local = VALUES(status_local)"
        );
 
        $db->beginTransaction();
        try {
            foreach ($itens as $item) {
                $bId = trim($item['bling_id']    ?? '');
                $bLb = trim($item['bling_label'] ?? '');
                $lSt = trim($item['status_local']?? '');
                if ($bId && $lSt) $stmt->execute([$bId, $bLb, $lSt]);
            }
            $db->commit();
            $this->json(['ok' => true, 'msg' => 'Mapeamento salvo com sucesso.']);
        } catch (\Throwable $e) {
            $db->rollBack();
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
 
    // ── GET /admin/configuracoes/bling/situacoes ──────────
    // Busca situações disponíveis na conta Bling conectada
    public function getSituacoesBling(): void
    {
        try {
            $raw  = (new BlingApiClient())->get('/situacoes', ['tipo' => 3]);
            $list = array_map(fn($s) => [
                'id'        => (string)($s['id']       ?? ''),
                'descricao' => (string)($s['descricao'] ?? $s['nome'] ?? ''),
            ], $raw);
            $this->json(['ok' => true, 'situacoes' => array_values(array_filter($list, fn($s) => $s['id']))]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
 
    // ── POST /admin/configuracoes/bling/enviar-pedido ─────
    public function enviarPedido(): void
    {
        $this->verifyCsrf();
        $pedidoId = (int)($_POST['pedido_id'] ?? 0);
        if (!$pedidoId) $this->json(['ok' => false, 'msg' => 'pedido_id inválido.']);
 
        try {
            $result = (new BlingOrderService())->enviarPedido($pedidoId);
            $this->json(['ok' => true, 'msg' => "Pedido enviado ao Bling (ID: {$result['bling_id']})."] + $result);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
}