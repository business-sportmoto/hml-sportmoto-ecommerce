<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/ReviewController.php
// ════════════════════════════════════════════════════════
class ReviewController extends Controller {

    private Review $review;

    public function __construct() {
        $this->review = new Review();
    }

    private function sessao(): string {
        if (empty($_SESSION['review_sessao'])) {
            $_SESSION['review_sessao'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['review_sessao'];
    }

    // ── GET /avaliacoes?produto_id=X&page=1&filtro=todas&ordem=recentes ──
    public function listar(): void {
        $produtoId = SecurityHelper::sanitizeInt($_GET['produto_id'] ?? 0);
        $page      = max(1, (int)($_GET['page']   ?? 1));
        $filtro    = $_GET['filtro'] ?? 'todas';
        $ordem     = $_GET['ordem']  ?? 'recentes';
        $perPage   = 4;

        if (!$produtoId) $this->json(['ok' => false]);

        $resumo   = $this->review->getResumo($produtoId);
        $reviews  = $this->review->listar($produtoId, $page, $perPage, $filtro, $ordem);
        $total    = $this->review->countFiltrado($produtoId, $filtro);
        $midias   = ($page === 1 && $filtro === 'todas')
                  ? $this->review->getMidiasGlobal($produtoId)
                  : null;

        $sessao    = $this->sessao();
        $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;

        $reviews = array_map(function (array $r) use ($sessao, $clienteId): array {
            $r['votou']       = $this->review->jaVotou((int)$r['id'], $clienteId, $sessao);
            $r['data_fmt']    = date('d M Y', strtotime($r['criado_em']));
            $r['midias_fmt']  = array_map(fn($m) => [
                'tipo'      => $m['tipo'],
                'url'       => UPLOAD_URL . '/avaliacoes/' . $m['arquivo'],
                'thumb_url' => $m['arquivo_thumb']
                               ? UPLOAD_URL . '/avaliacoes/' . $m['arquivo_thumb']
                               : null,
            ], $r['midias'] ?? []);
            return $r;
        }, $reviews);

        $midiasFmt = $midias ? array_map(fn($m) => [
            'tipo'      => $m['tipo'],
            'url'       => UPLOAD_URL . '/avaliacoes/' . $m['arquivo'],
            'thumb_url' => $m['arquivo_thumb']
                           ? UPLOAD_URL . '/avaliacoes/' . $m['arquivo_thumb']
                           : null,
        ], $midias) : null;

        $this->json([
            'ok'       => true,
            'resumo'   => $resumo,
            'reviews'  => $reviews,
            'midias'   => $midiasFmt,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'has_more' => ($page * $perPage) < $total,
        ]);
    }

    // ── POST /avaliacoes/upload-midia ─────────────────────
    // ── GET /avaliacoes/resumo-ia?produto_id=X ──────────
    // Retorna (ou gera) o resumo IA das avaliações.
    // Chamado via AJAX após o render da página — nunca bloqueia.
    public function resumoIA(): void {
        $produtoId = SecurityHelper::sanitizeInt($_GET['produto_id'] ?? 0);
        if (!$produtoId) $this->json(['ok' => false, 'msg' => 'Produto inválido.']);

        // Rate limit por IP: evita abuse direto no endpoint
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (SecurityHelper::rateLimitExceeded('review_summary_' . md5($ip), 30, 60)) {
            $this->json(['ok' => false, 'msg' => 'Rate limit.']);
        }

        $svc    = new ReviewSummaryService();
        $result = $svc->obter($produtoId);

        $this->json($result);
    }

    // ── POST /avaliacoes/upload-midia ─────────────────────
    // A regra de upload (limite de 5, 5 MB por imagem, 30 MB por vídeo,
    // thumbnail) mora em AvaliacaoMidiaService, porque o app faz o MESMO
    // upload por /api/app/v1/avaliacoes/midias. O contrato JSON desta rota
    // não mudou — o JS da loja continua lendo token, arquivo, url e thumb_url.
    public function uploadMidia(): void {
        $this->verifyCsrf();

        $arquivo = $_FILES['midia'] ?? null;
        if (!is_array($arquivo)) {
            $this->json(['ok' => false, 'msg' => 'Nenhum arquivo enviado.']);
        }

        $r = (new AvaliacaoMidiaService())->guardar(
            $arquivo,
            SecurityHelper::sanitizeString($_POST['token'] ?? ''),
            $_SERVER['REMOTE_ADDR'] ?? null
        );

        if (empty($r['ok'])) {
            $this->json(['ok' => false, 'msg' => $r['erro'] ?? 'Falha no envio.']);
        }

        $m = $r['midia'];

        $this->json([
            'ok'        => true,
            'token'     => $m['token'],
            'arquivo'   => $m['arquivo'],
            'thumb_url' => $m['thumb'] ? UPLOAD_URL . '/avaliacoes/' . $m['thumb'] : null,
            'url'       => UPLOAD_URL . '/avaliacoes/' . $m['arquivo'],
            'tipo'      => $m['tipo'],
        ]);
    }

    // ── POST /avaliacoes/remover-midia ────────────────────
    // Remove de verdade um arquivo enviado antes da avaliação ser
    // publicada (ainda em avaliacao_midias_temp, não vinculado a
    // nenhuma avaliação). Sem isso, clicar no "X" da pré-visualização
    // só removia da tela — a foto continuava sendo anexada na
    // publicação, porque vincularMidias() busca TODAS as linhas do
    // token, incluindo as "removidas" visualmente mas nunca apagadas.
    //
    // Escopo por IP (mesmo padrão de checarRateLimit): avaliação pode
    // ser enviada por visitante anônimo, sem cliente_id para validar
    // posse — o IP de quem fez o upload é o único sinal disponível.
    public function removerMidia(): void {
        $this->verifyCsrf();

        $token   = SecurityHelper::sanitizeString($_POST['token']   ?? '');
        $arquivo = SecurityHelper::sanitizeString($_POST['arquivo'] ?? '');
        if (!$token || !$arquivo) {
            $this->json(['ok' => false]);
        }

        $ok = (new AvaliacaoMidiaService())->remover(
            $token,
            $arquivo,
            $_SERVER['REMOTE_ADDR'] ?? ''
        );

        $this->json(['ok' => $ok]);
    }

    // ── POST /avaliacoes/enviar ───────────────────────────
    public function enviar(): void {
        $this->verifyCsrf();

        $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id']   ?? 0);
        $nota      = SecurityHelper::sanitizeInt($_POST['nota']          ?? 0);
        $titulo    = SecurityHelper::sanitizeString($_POST['titulo']      ?? '');
        $comentario= SecurityHelper::sanitizeString($_POST['comentario'] ?? '');
        $nome      = SecurityHelper::sanitizeString($_POST['nome']        ?? '');
        $token     = SecurityHelper::sanitizeString($_POST['upload_token'] ?? '');

        if (!$produtoId || !$nota || !$comentario) {
            $this->json(['ok' => false, 'msg' => 'Preencha os campos obrigatórios.']);
        }
        if ($nota < 1 || $nota > 5) {
            $this->json(['ok' => false, 'msg' => 'Nota inválida.']);
        }
        if (mb_strlen($comentario) > 2000) {
            $this->json(['ok' => false, 'msg' => 'Comentário muito longo.']);
        }
        // Mínimo de 10 caracteres — evita avaliações de uma palavra
        // só ("bom", "ok") que não ajudam outros clientes a decidir.
        // Ajustável: troque o número abaixo se 10 ficar curto/longo.
        if (mb_strlen($comentario) < 10) {
            $this->json(['ok' => false, 'msg' => 'Conte um pouco mais sobre sua experiência (mínimo 10 caracteres).']);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!$this->review->checarRateLimit($ip)) {
            $this->json(['ok' => false, 'msg' => 'Muitas avaliações recentes. Aguarde.']);
        }

        $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;

        // Verifica duplicidade se logado
        if ($clienteId && $this->review->jaAvaliou($produtoId, $clienteId)) {
            $this->json(['ok' => false, 'msg' => 'Você já avaliou este produto.']);
        }

        // Verifica pedido para compra verificada
        $db        = Database::getInstance()->getConnection();
        $pedidoId  = null;
        if ($clienteId) {
            $stmt = $db->prepare(
                "SELECT ped.id FROM pedidos ped
                 JOIN pedido_itens pi ON pi.pedido_id = ped.id
                 WHERE ped.cliente_id = ? AND pi.produto_id = ?
                   AND ped.status_pagamento = 'aprovado'
                 LIMIT 1"
            );
            $stmt->execute([$clienteId, $produtoId]);
            $pedidoId = $stmt->fetchColumn() ?: null;
        }

        // Aprovação automática se cliente logado com compra verificada
        $aprovado = ($clienteId && $pedidoId) ? 1 : 0;

        $id = $this->review->salvar([
            'produto_id'   => $produtoId,
            'cliente_id'   => $clienteId,
            'pedido_id'    => $pedidoId,
            'cliente_nome' => $clienteId ? null : ($nome ?: 'Visitante'),
            'nota'         => $nota,
            'titulo'       => $titulo ?: null,
            'comentario'   => $comentario,
            'aprovado'     => $aprovado,
            'ip'           => $ip,
        ]);

        // Vincula mídias se enviou
        if ($token) {
            $this->review->vincularMidias($id, $token);
        }

        $msg = $aprovado
             ? 'Avaliação publicada! Obrigado pelo feedback.'
             : 'Avaliação enviada! Será publicada após moderação.';

        $this->json(['ok' => true, 'msg' => $msg, 'aprovado' => $aprovado]);
    }

    // ── POST /avaliacoes/util ─────────────────────────────
    public function util(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $sessao    = $this->sessao();
        $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
        $ip        = $_SERVER['REMOTE_ADDR'] ?? '';

        $votou = $this->review->toggleUtil($id, $clienteId, $sessao, $ip);

        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT util_sim FROM avaliacoes WHERE id=? LIMIT 1"
        );
        $stmt->execute([$id]);
        $total = (int)$stmt->fetchColumn();

        $this->json(['ok' => true, 'votou' => $votou, 'total' => $total]);
    }
}

// ════════════════════════════════════════════════════════
// admin/controllers/AvaliacoesController.php
// ════════════════════════════════════════════════════════
class AvaliacoesController extends Controller {

    public function __construct() {
        AuthHelper::requireAdmin();
    }

    public function index(): void {
        $db      = Database::getInstance()->getConnection();
        $filtro  = $_GET['aprovado'] ?? 'todas';
        $busca   = SecurityHelper::sanitizeString($_GET['q'] ?? '');
        $nota    = SecurityHelper::sanitizeInt($_GET['nota'] ?? 0);
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $offset  = ($page - 1) * $perPage;

        $where  = "1=1";
        $params = [];

        if ($filtro === '0') { $where .= " AND a.aprovado = 0"; }
        elseif ($filtro === '1') { $where .= " AND a.aprovado = 1"; }

        if ($nota) { $where .= " AND a.nota = ?"; $params[] = $nota; }

        if ($busca) {
            $where   .= " AND (a.comentario LIKE ? OR a.cliente_nome LIKE ? OR p.nome LIKE ?)";
            $like     = "%{$busca}%";
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $stmt = $db->prepare(
            "SELECT a.*,
                    COALESCE(a.cliente_nome, u.nome, 'Visitante') AS nome_exibido,
                    p.nome  AS produto_nome,
                    p.slug  AS produto_slug,
                    (SELECT COUNT(*) FROM avaliacao_midias m WHERE m.avaliacao_id = a.id) AS total_midias
             FROM avaliacoes a
             LEFT JOIN clientes c  ON c.id = a.cliente_id
             LEFT JOIN usuarios u  ON u.id = c.usuario_id
             LEFT JOIN produtos p  ON p.id = a.produto_id
             WHERE {$where}
             ORDER BY a.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $params[] = $perPage;
        $params[] = $offset;
        $stmt->execute($params);
        $avaliacoes = $stmt->fetchAll();

        // Contadores pra tabs
        $total       = (int)$db->query("SELECT COUNT(*) FROM avaliacoes")->fetchColumn();
        $pendentes   = (int)$db->query("SELECT COUNT(*) FROM avaliacoes WHERE aprovado=0")->fetchColumn();
        $aprovadas   = (int)$db->query("SELECT COUNT(*) FROM avaliacoes WHERE aprovado=1")->fetchColumn();

        $this->render('avaliacoes/index', [
            'avaliacoes' => $avaliacoes,
            'filtro'     => $filtro,
            'busca'      => $busca,
            'nota'       => $nota,
            'page'       => $page,
            'perPage'    => $perPage,
            'total'      => $total,
            'pendentes'  => $pendentes,
            'aprovadas'  => $aprovadas,
        ], 'admin');
    }

    public function aprovar(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db = Database::getInstance()->getConnection();
        $db->prepare(
            "UPDATE avaliacoes SET aprovado=1, moderado_em=NOW() WHERE id=?"
        )->execute([$id]);

        $this->json(['ok' => true]);
    }

    public function rejeitar(): void {
        $this->verifyCsrf();
        $id     = SecurityHelper::sanitizeInt($_POST['id']     ?? 0);
        $motivo = SecurityHelper::sanitizeString($_POST['motivo'] ?? '');
        if (!$id) $this->json(['ok' => false]);

        $db = Database::getInstance()->getConnection();
        $db->prepare(
            "UPDATE avaliacoes
             SET aprovado=0, motivo_rejeicao=?, moderado_em=NOW()
             WHERE id=?"
        )->execute([$motivo ?: null, $id]);

        $this->json(['ok' => true]);
    }

    public function excluir(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT arquivo, arquivo_thumb FROM avaliacao_midias WHERE avaliacao_id=?"
        );
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $m) {
            foreach ([$m['arquivo'], $m['arquivo_thumb']] as $f) {
                if ($f) @unlink(UPLOAD_PATH . '/avaliacoes/' . $f);
            }
        }

        $db->prepare("DELETE FROM avaliacoes WHERE id=?")->execute([$id]);
        $this->json(['ok' => true]);
    }

    public function toggleDestaque(): void {
        $this->verifyCsrf();
        $id   = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT destaque FROM avaliacoes WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $novo = (int)$stmt->fetchColumn() ? 0 : 1;
        $db->prepare("UPDATE avaliacoes SET destaque=? WHERE id=?")->execute([$novo, $id]);
        $this->json(['ok' => true, 'destaque' => $novo]);
    }

    public function moderarMidia(): void {
        $this->verifyCsrf();
        $id      = SecurityHelper::sanitizeInt($_POST['id']       ?? 0);
        $acao    = $_POST['acao'] ?? '';
        $aprovada = $acao === 'aprovar' ? 1 : 0;

        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE avaliacao_midias SET aprovada=? WHERE id=?")->execute([$aprovada, $id]);
        $this->json(['ok' => true]);
    }
}