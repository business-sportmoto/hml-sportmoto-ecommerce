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

        public function uploadMidia(): void {
        $this->verifyCsrf();

        if (empty($_FILES['midia']['tmp_name'])) {
            $this->json(['ok' => false, 'msg' => 'Nenhum arquivo enviado.']);
        }

        $token = SecurityHelper::sanitizeString($_POST['token'] ?? '');
        if (empty($token)) $token = bin2hex(random_bytes(16));

        // Limite de 5 mídias por avaliação aplicado no servidor — o
        // limite no JS (MAX=5) é só conveniência de UX, não proteção;
        // sem isso, alguém poderia chamar o endpoint repetidamente e
        // acumular dezenas de arquivos sob o mesmo token.
        $stmtCount = Database::getInstance()->getConnection()->prepare(
            "SELECT COUNT(*) FROM avaliacao_midias_temp WHERE token = ?"
        );
        $stmtCount->execute([$token]);
        if ((int)$stmtCount->fetchColumn() >= 5) {
            $this->json(['ok' => false, 'msg' => 'Limite de 5 fotos/vídeos por avaliação.']);
        }

        $file      = $_FILES['midia'];
        $isImagem  = strpos($file['type'], 'image/') === 0;
        $isVideo   = strpos($file['type'], 'video/') === 0;

        if (!$isImagem && !$isVideo) {
            $this->json(['ok' => false, 'msg' => 'Formato não permitido.']);
        }

        $maxSize = $isImagem ? 5 * 1024 * 1024 : 30 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            $this->json(['ok' => false, 'msg' => 'Arquivo muito grande.']);
        }

        $dir = UPLOAD_PATH . '/avaliacoes/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp','mp4','webm','mov'];
        if (!in_array($ext, $allowed)) {
            $this->json(['ok' => false, 'msg' => 'Extensão não permitida.']);
        }

        $hash    = bin2hex(random_bytes(8));
        $arquivo = 'rev_' . $hash . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $dir . $arquivo)) {
            $this->json(['ok' => false, 'msg' => 'Erro ao salvar arquivo.']);
        }

        $thumb = null;

        // Para imagem: gera thumb via GD
        if ($isImagem && function_exists('imagecreatefromjpeg')) {
            $thumb = $this->gerarThumb($dir . $arquivo, $dir, $hash, $ext);
        }

        // Para vídeo: extrai frame via ffmpeg
        if ($isVideo) {
            $thumb = $this->gerarThumbVideo($dir . $arquivo, $dir, $hash);
        }

        // Salva temporariamente
        $tipo = $isImagem ? 'imagem' : 'video';
        Database::getInstance()->getConnection()->prepare(
            "INSERT INTO avaliacao_midias_temp (token, tipo, arquivo, thumb, ip)
             VALUES (?,?,?,?,?)"
        )->execute([$token, $tipo, $arquivo, $thumb, $_SERVER['REMOTE_ADDR'] ?? null]);

        $this->json([
            'ok'        => true,
            'token'     => $token,
            'arquivo'   => $arquivo,
            'thumb_url' => $thumb ? UPLOAD_URL . '/avaliacoes/' . $thumb : null,
            'url'       => UPLOAD_URL . '/avaliacoes/' . $arquivo,
            'tipo'      => $tipo,
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

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare(
            "SELECT id, arquivo, thumb FROM avaliacao_midias_temp
             WHERE token = ? AND arquivo = ? AND ip = ?
             LIMIT 1"
        );
        $stmt->execute([$token, $arquivo, $ip]);
        $row = $stmt->fetch();

        if (!$row) {
            // Idempotente: se já foi removida (ou nunca existiu), não é
            // erro — o resultado final desejado (mídia ausente) já é real.
            $this->json(['ok' => true]);
        }

        $dir = UPLOAD_PATH . '/avaliacoes/';
        if (!empty($row['arquivo'])) @unlink($dir . $row['arquivo']);
        if (!empty($row['thumb']))   @unlink($dir . $row['thumb']);

        $db->prepare(
            "DELETE FROM avaliacao_midias_temp WHERE id = ?"
        )->execute([$row['id']]);

        $this->json(['ok' => true]);
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

    private function gerarThumb(string $pathOrig, string $dir, string $hash, string $ext): ?string {
        try {
            $img = match ($ext) {
                'jpg','jpeg' => imagecreatefromjpeg($pathOrig),
                'png'  => imagecreatefrompng($pathOrig),
                'webp' => imagecreatefromwebp($pathOrig),
                default => null,
            };
            if (!$img) return null;

            $w   = imagesx($img);
            $h   = imagesy($img);
            $max = 200;

            if ($w > $max || $h > $max) {
                $r   = min($max/$w, $max/$h);
                $nw  = (int)($w * $r);
                $nh  = (int)($h * $r);
                $dst = imagecreatetruecolor($nw, $nh);
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $dst;
            }

            $thumb = 'th_' . $hash . '.webp';
            imagewebp($img, $dir . $thumb, 80);
            imagedestroy($img);
            return $thumb;
        } catch (\Exception $e) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────
    /**
     * Extrai um frame do vídeo usando ffmpeg e salva como thumb.
     *
     * Tenta capturar no segundo 1 (boa representação do conteúdo).
     * Se o vídeo tiver menos de 1s, captura no frame 0.
     * Salva como .webp (ou .jpg como fallback sem imagewebp).
     *
     * @return string|null  Nome do arquivo thumb ou null se falhou
     */
    private function gerarThumbVideo(
        string $videoPath,
        string $dir,
        string $hash
    ): ?string {
        if (!file_exists($videoPath)) return null;

        // Verifica disponibilidade do ffmpeg
        exec('ffmpeg -version 2>&1', $out, $code);
        if ($code !== 0) {
            error_log('[ReviewController] ffmpeg não encontrado — thumb de vídeo ignorado.');
            return null;
        }

        $ext       = function_exists('imagewebp') ? 'webp' : 'jpg';
        $thumbFile = 'th_' . $hash . '.' . $ext;
        $thumbPath = $dir . $thumbFile;

        // Tenta capturar no segundo 1
        $cmd = sprintf(
            'ffmpeg -y -ss 00:00:01 -i %s -vframes 1 -q:v 2 ' .
            '-vf "scale=320:320:force_original_aspect_ratio=decrease,pad=320:320:(320-iw)/2:(320-ih)/2,setsar=1" ' .
            '%s 2>&1',
            escapeshellarg($videoPath),
            escapeshellarg($thumbPath)
        );
        exec($cmd, $out1, $code1);

        // Fallback: captura no frame 0 (vídeos muito curtos)
        if ($code1 !== 0 || !file_exists($thumbPath) || filesize($thumbPath) < 100) {
            $cmd2 = sprintf(
                'ffmpeg -y -i %s -vframes 1 -q:v 2 ' .
                '-vf "scale=320:320:force_original_aspect_ratio=decrease,pad=320:320:(320-iw)/2:(320-ih)/2,setsar=1" ' .
                '%s 2>&1',
                escapeshellarg($videoPath),
                escapeshellarg($thumbPath)
            );
            exec($cmd2, $out2, $code2);
        }

        if (!file_exists($thumbPath) || filesize($thumbPath) < 100) {
            error_log('[ReviewController] gerarThumbVideo falhou para: ' . basename($videoPath));
            return null;
        }

        return $thumbFile;
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