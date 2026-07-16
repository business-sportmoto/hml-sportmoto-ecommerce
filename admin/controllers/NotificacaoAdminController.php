<?php
/**
 * admin/controllers/NotificacaoAdminController.php
 *
 * Tela de composição e envio manual de notificações.
 *
 * Rotas:
 *   GET  /admin/notificacoes            → tela de composição + histórico
 *   POST /admin/notificacoes/enviar     → cria a notificação
 *   POST /admin/notificacoes/upload-img → upload da imagem (retorna URL interna)
 *   GET  /admin/notificacoes/buscar-destinatarios?q= → autocomplete de clientes/admins
 */
class NotificacaoAdminController extends Controller
{
    public function __construct()
    {
        // parent::__construct();
        $this->requirePermission();
    }

    private function requirePermission(): void
    {
        if (method_exists('AuthHelper', 'requirePermission')) {
            try { AuthHelper::requirePermission('notificacoes'); return; } catch (Throwable $e) {}
        }
        if (method_exists('AuthHelper', 'requireAdminLevel')) {
            AuthHelper::requireAdminLevel(); return;
        }
        AuthHelper::requireAdmin();
    }

    // GET /admin/notificacoes
    public function index(): void
    {
        $db = Database::getInstance()->getConnection();

        // Histórico recente de envios
        $st = $db->query(
            "SELECT n.*,
                    (SELECT COUNT(*) FROM notificacao_usuarios nu
                     WHERE nu.notificacao_id = n.id AND nu.lida = 1) AS total_lidas
             FROM notificacoes n
             ORDER BY n.id DESC
             LIMIT 30"
        );
        $historico = $st->fetchAll(PDO::FETCH_ASSOC);

        $this->render('notificacoes/index', [
            'historico'  => $historico,
            'categorias' => NotificacaoService::LABELS_CATEGORIA,
            'estilos'    => NotificacaoService::ESTILO_CATEGORIA,
            'titulo'     => 'Notificações',
        ], 'admin');
    }

    // POST /admin/notificacoes/enviar
    public function enviar(): void
    {
        $this->verifyCsrf();

        $titulo    = trim($_POST['titulo'] ?? '');
        $mensagem  = trim($_POST['mensagem'] ?? '') ?: null;
        $categoria = $_POST['categoria'] ?? 'sistema';
        $url       = trim($_POST['url'] ?? '') ?: null;
        $imagemUrl = trim($_POST['imagem_url'] ?? '') ?: null;
        $modo      = $_POST['modo_envio'] ?? '';   // 'todos' | 'todos_clientes' | 'todos_admins' | 'selecionados'

        if ($titulo === '') {
            $this->json(['ok' => false, 'erro' => 'Informe o título.']); return;
        }
        if (!in_array($categoria, NotificacaoService::CATEGORIAS, true)) {
            $this->json(['ok' => false, 'erro' => 'Categoria inválida.']); return;
        }

        // Valida URL da imagem: só aceita caminho interno (upload do sistema)
        if ($imagemUrl !== null) {
            $base = defined('BASE_URL') ? BASE_URL : '';
            $ehInterna = strpos($imagemUrl, '/uploads/') === 0
                      || ($base && strpos($imagemUrl, $base . '/uploads/') === 0);
            if (!$ehInterna) {
                $this->json(['ok' => false, 'erro' => 'Use o upload de imagem — URLs externas não são permitidas.']); return;
            }
        }

        $adminId = (int)(Session::get('admin_id') ?? 0);

        $dados = [
            'categoria'       => $categoria,
            'tipo'            => 'aviso_manual',
            'titulo'          => $titulo,
            'mensagem'        => $mensagem,
            'url'             => $url,
            'imagem_url'      => $imagemUrl,
            'criado_por_tipo' => 'admin',
            'criado_por_id'   => $adminId ?: null,
        ];

        // ── Broadcast ──
        if (in_array($modo, ['todos', 'todos_clientes', 'todos_admins'], true)) {
            $id = NotificacaoService::criarBroadcast($dados, $modo);
            if (!$id) { $this->json(['ok' => false, 'erro' => 'Falha ao criar.']); return; }

            if (class_exists('LogService')) {
                try { LogService::audit('notificacao_broadcast', ['id' => $id, 'alvo' => $modo, 'admin_id' => $adminId]); } catch (Throwable $e) {}
            }
            $this->json([
                'ok'  => true,
                'id'  => $id,
                'msg' => 'Notificação criada. O envio em massa será processado em até 1 minuto.',
            ]);
            return;
        }

        // ── Selecionados ──
        if ($modo === 'selecionados') {
            $idsRaw = $_POST['destinatarios'] ?? [];   // ["cliente:55", "admin:3", ...]
            if (!is_array($idsRaw)) $idsRaw = [];

            $destinatarios = [];
            foreach ($idsRaw as $item) {
                $partes = explode(':', (string)$item, 2);
                if (count($partes) !== 2) continue;
                [$t, $i] = $partes;
                if (!in_array($t, ['cliente','admin'], true)) continue;
                $i = (int)$i;
                if ($i <= 0) continue;
                $destinatarios[] = ['tipo' => $t, 'id' => $i];
            }

            if (empty($destinatarios)) {
                $this->json(['ok' => false, 'erro' => 'Selecione ao menos um destinatário.']); return;
            }

            $id = NotificacaoService::criar($dados, $destinatarios);
            if (!$id) { $this->json(['ok' => false, 'erro' => 'Falha ao criar.']); return; }

            if (class_exists('LogService')) {
                try { LogService::audit('notificacao_selecionados', ['id' => $id, 'total' => count($destinatarios), 'admin_id' => $adminId]); } catch (Throwable $e) {}
            }
            $this->json(['ok' => true, 'id' => $id, 'msg' => 'Notificação enviada para ' . count($destinatarios) . ' destinatário(s).']);
            return;
        }

        $this->json(['ok' => false, 'erro' => 'Modo de envio inválido.']);
    }

    // POST /admin/notificacoes/upload-img
    public function uploadImg(): void
    {
        $this->verifyCsrf();

        if (empty($_FILES['imagem']) || $_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['ok' => false, 'erro' => 'Falha no upload.']); return;
        }

        $f = $_FILES['imagem'];

        // Validações
        $maxBytes = 2 * 1024 * 1024; // 2 MB
        if ($f['size'] > $maxBytes) {
            $this->json(['ok' => false, 'erro' => 'Imagem muito grande (máx 2 MB).']); return;
        }

        $mime = mime_content_type($f['tmp_name']);
        $extPorMime = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extPorMime[$mime])) {
            $this->json(['ok' => false, 'erro' => 'Formato inválido. Use JPG, PNG ou WebP.']); return;
        }

        $ext     = $extPorMime[$mime];
        $nome    = 'notif_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destDir = (defined('UPLOAD_PATH') ? UPLOAD_PATH : ROOT_PATH . '/uploads') . '/notificacoes';
        @mkdir($destDir, 0775, true);

        if (!move_uploaded_file($f['tmp_name'], $destDir . '/' . $nome)) {
            $this->json(['ok' => false, 'erro' => 'Erro ao salvar a imagem.']); return;
        }

        $urlRel = '/uploads/notificacoes/' . $nome;
        $this->json(['ok' => true, 'url' => $urlRel]);
    }

    // GET /admin/notificacoes/buscar-destinatarios?q=
    public function buscarDestinatarios(): void
    {
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) { $this->json(['ok' => true, 'itens' => []]); return; }

        $db   = Database::getInstance()->getConnection();
        $like = '%' . $q . '%';
        $itens = [];

        // Clientes (nome/email via usuarios)
        $st = $db->prepare(
            "SELECT c.id, u.nome, u.email
             FROM clientes c
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE (u.nome LIKE :q1 OR u.email LIKE :q2)
               AND c.ativo = '1' AND u.deleted_at IS NULL
             LIMIT 8"
        );
        $st->execute([':q1' => $like, ':q2' => $like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $itens[] = [
                'valor' => 'cliente:' . $c['id'],
                'label' => $c['nome'] . ' — ' . $c['email'],
                'tipo'  => 'cliente',
            ];
        }

        // Admins — AJUSTE à sua estrutura real de admins
        $st = $db->prepare(
            "SELECT id, nome, email FROM usuarios
             WHERE nivel IN ('admin','super')
               AND (nome LIKE :q1 OR email LIKE :q2)
               AND deleted_at IS NULL
             LIMIT 5"
        );
        $st->execute([':q1' => $like, ':q2' => $like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $itens[] = [
                'valor' => 'admin:' . $a['id'],
                'label' => $a['nome'] . ' — ' . $a['email'] . ' (admin)',
                'tipo'  => 'admin',
            ];
        }

        $this->json(['ok' => true, 'itens' => $itens]);
    }
}
