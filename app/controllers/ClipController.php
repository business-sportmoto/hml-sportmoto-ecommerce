<?php 

// ════════════════════════════════════════════════════════
// app/controllers/ClipController.php — v2
// ════════════════════════════════════════════════════════
class ClipController extends Controller {

    private Clip $clip;

    public function __construct() {
        $this->clip = new Clip();
    }

    private function sessionKey(): string {
        if (empty($_SESSION['clip_session'])) {
            $_SESSION['clip_session'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['clip_session'];
    }

    // ── GET /clips/feed?page=1&destaque=1&produto_id=X ───
    public function feed(): void {
        $page      = max(1, (int)($_GET['page']       ?? 1));
        $destaque  = !empty($_GET['destaque']);
        $produtoId = (int)($_GET['produto_id'] ?? 0);

        if ($produtoId > 0) {
            $clips = $this->clip->getPorProduto($produtoId);
            $total = count($clips);
        } else {
            $clips = $this->clip->getFeed($page, 10, $destaque);
            $total = $this->clip->countFeed($destaque);
        }

        $sessao    = $this->sessionKey();
        $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;

        $clips = array_map(function (array $c) use ($sessao, $clienteId): array {
            $c['curtiu']     = $this->clip->jaÇurtiu((int)$c['id'], $clienteId, $sessao);
            $c['video_url']  = UPLOAD_URL . '/clips/' . $c['arquivo_video'];
            $c['poster_url'] = $c['arquivo_poster']
                               ? UPLOAD_URL . '/clips/posters/' . $c['arquivo_poster']
                               : null;
            $c['clip_url']   = BASE_URL . '/clip/' . $c['id'];

            // Formata array de produtos
            $c['produtos'] = array_map(function (array $p): array {
                $p['preco_fmt']       = $p['produto_preco']
                    ? 'R$ ' . number_format((float)$p['produto_preco'], 2, ',', '.')
                    : null;
                $p['preco_promo_fmt'] = $p['produto_preco_promo']
                    ? 'R$ ' . number_format((float)$p['produto_preco_promo'], 2, ',', '.')
                    : null;
                $p['img_url']         = $p['produto_imagem']
                    ? UPLOAD_URL . '/products/' . $p['produto_imagem']
                    : null;
                $p['produto_url']     = BASE_URL . '/produto/' . ($p['produto_slug'] ?? '');
                return $p;
            }, $c['produtos'] ?? []);

            return $c;
        }, $clips);

        $this->json([
            'ok'       => true,
            'clips'    => $clips,
            'total'    => $total,
            'page'     => $page,
            'has_more' => ($page * 10) < $total,
        ]);
    }

    // ── GET /clip/{id} — URL pública compartilhável ──────
    public function publicPage(int $id): void {
        $clip = $this->clip->getComProdutos($id);
        if (!$clip) {
            $this->redirect(BASE_URL);
            return;
        }

        // Registra view
        $this->clip->registrarView(
            $id,
            $this->sessionKey(),
            $_SERVER['REMOTE_ADDR'] ?? ''
        );

        $pageTitle       = View::e($clip['titulo']);
        $autoOpenClipId  = $id;
        $autoOpenClipData = json_encode([
            'id'         => $id,
            'video_url'  => UPLOAD_URL . '/clips/' . $clip['arquivo_video'],
            'poster_url' => $clip['arquivo_poster']
                            ? UPLOAD_URL . '/clips/posters/' . $clip['arquivo_poster']
                            : null,
            'titulo'     => $clip['titulo'],
            'clip_url'   => BASE_URL . '/clip/' . $id,
            'produtos'   => $clip['produtos'],
        ]);

        // Renderiza a home ou uma view dedicada
        $this->render('home/index', [
            'pageTitle'        => $pageTitle,
            'autoOpenClipId'   => $id,
            'autoOpenClipData' => $autoOpenClipData,
        ]);
    }

    // ── POST /clips/view ─────────────────────────────────
    public function view(): void {
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $this->clip->registrarView(
            $id,
            $this->sessionKey(),
            $_SERVER['REMOTE_ADDR'] ?? ''
        );

        // Registra no histórico de navegação (só quando logado).
        // try/catch silencioso: history nunca deve quebrar o player.
        if (Session::isClienteLogado()) {
            try {
                (new History())->record('clip', (int)$id, [], '/clip/' . $id);
            } catch (\Throwable $e) {
                error_log('[ClipController] History::record erro: ' . $e->getMessage());
            }
        }

        $this->json(['ok' => true]);
    }

    // ── POST /clips/like ─────────────────────────────────
    public function like(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!$this->clip->checarRateLimit($ip, 'like', 30)) {
            $this->json(['ok' => false, 'msg' => 'Muitas ações. Aguarde.']);
        }

        $sessao    = $this->sessionKey();
        $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
        $curtiu    = $this->clip->toggleLike($id, $clienteId, $sessao, $ip);

        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT total_likes FROM clips WHERE id=? LIMIT 1"
        );
        $stmt->execute([$id]);
        $total = (int)$stmt->fetchColumn();

        $this->json(['ok' => true, 'curtiu' => $curtiu, 'total' => $total]);
    }

    // ── POST /clips/share ────────────────────────────────
    public function share(): void {
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if ($id) $this->clip->registrarShare($id);
        $this->json(['ok' => true]);
    }

    // ── GET /clips/comentarios?id=X&page=1 ───────────────
    public function comentarios(): void {
        $id   = SecurityHelper::sanitizeInt($_GET['id']   ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        if (!$id) $this->json(['ok' => false]);

        $comentarios = $this->clip->getComentarios($id, $page);
        $this->json(['ok' => true, 'comentarios' => $comentarios]);
    }

    // ── POST /clips/comentar ─────────────────────────────
    public function comentar(): void {
        $this->verifyCsrf();

        $id    = SecurityHelper::sanitizeInt($_POST['id']    ?? 0);
        $nome  = SecurityHelper::sanitizeString($_POST['nome']  ?? '');
        $texto = SecurityHelper::sanitizeString($_POST['texto'] ?? '');

        if (!$id || empty($nome) || empty($texto)) {
            $this->json(['ok' => false, 'msg' => 'Preencha todos os campos.']);
        }
        if (mb_strlen($texto) > 500) {
            $this->json(['ok' => false, 'msg' => 'Comentário muito longo.']);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!$this->clip->checarRateLimit($ip, 'comentario', 5)) {
            $this->json(['ok' => false, 'msg' => 'Muitos comentários. Aguarde.']);
        }

        $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
        $comentario = $this->clip->addComentario($id, $nome, $texto, $clienteId, $ip);

        $msg = $comentario['aprovado']
             ? null  // exibe direto
             : 'Comentário enviado! Aparecerá após moderação.';

        $this->json([
            'ok'         => true,
            'msg'        => $msg,
            'aprovado'   => $comentario['aprovado'],
            'comentario' => $comentario,
        ]);
    }
}

// ════════════════════════════════════════════════════════
// app/controllers/ClipController.php — v2
// ════════════════════════════════════════════════════════
// class ClipController extends Controller {
 
//     private Clip $clip;
 
//     public function __construct() {
//         $this->clip = new Clip();
//     }
 
//     private function sessionKey(): string {
//         if (empty($_SESSION['clip_session'])) {
//             $_SESSION['clip_session'] = bin2hex(random_bytes(16));
//         }
//         return $_SESSION['clip_session'];
//     }
 
//     // ── GET /clips/feed?page=1&destaque=1&produto_id=X ───
//     public function feed(): void {
//         $page      = max(1, (int)($_GET['page']       ?? 1));
//         $destaque  = !empty($_GET['destaque']);
//         $produtoId = (int)($_GET['produto_id'] ?? 0);
 
//         if ($produtoId > 0) {
//             $clips = $this->clip->getPorProduto($produtoId);
//             $total = count($clips);
//         } else {
//             $clips = $this->clip->getFeed($page, 10, $destaque);
//             $total = $this->clip->countFeed($destaque);
//         }
 
//         $sessao    = $this->sessionKey();
//         $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
 
//         $clips = array_map(function (array $c) use ($sessao, $clienteId): array {
//             $c['curtiu']     = $this->clip->jaÇurtiu((int)$c['id'], $clienteId, $sessao);
//             $c['video_url']  = UPLOAD_URL . '/clips/' . $c['arquivo_video'];
//             $c['poster_url'] = $c['arquivo_poster']
//                                ? UPLOAD_URL . '/clips/posters/' . $c['arquivo_poster']
//                                : null;
//             $c['clip_url']   = BASE_URL . '/clip/' . $c['id'];
 
//             // Formata array de produtos
//             $c['produtos'] = array_map(function (array $p): array {
//                 $p['preco_fmt']       = $p['produto_preco']
//                     ? 'R$ ' . number_format((float)$p['produto_preco'], 2, ',', '.')
//                     : null;
//                 $p['preco_promo_fmt'] = $p['produto_preco_promo']
//                     ? 'R$ ' . number_format((float)$p['produto_preco_promo'], 2, ',', '.')
//                     : null;
//                 $p['img_url']         = $p['produto_imagem']
//                     ? UPLOAD_URL . '/products/' . $p['produto_imagem']
//                     : null;
//                 $p['produto_url']     = BASE_URL . '/produto/' . ($p['produto_slug'] ?? '');
//                 return $p;
//             }, $c['produtos'] ?? []);
 
//             return $c;
//         }, $clips);
 
//         $this->json([
//             'ok'       => true,
//             'clips'    => $clips,
//             'total'    => $total,
//             'page'     => $page,
//             'has_more' => ($page * 10) < $total,
//         ]);
//     }
 
//     // ── GET /clip/{id} — URL pública compartilhável ──────
//     public function publicPage(int $id): void {
//         $clip = $this->clip->getComProdutos($id);
//         if (!$clip) {
//             $this->redirect(BASE_URL);
//             return;
//         }
 
//         // Registra view
//         $this->clip->registrarView(
//             $id,
//             $this->sessionKey(),
//             $_SERVER['REMOTE_ADDR'] ?? ''
//         );
 
//         $pageTitle       = View::e($clip['titulo']);
//         $autoOpenClipId  = $id;
//         $autoOpenClipData = json_encode([
//             'id'         => $id,
//             'video_url'  => UPLOAD_URL . '/clips/' . $clip['arquivo_video'],
//             'poster_url' => $clip['arquivo_poster']
//                             ? UPLOAD_URL . '/clips/posters/' . $clip['arquivo_poster']
//                             : null,
//             'titulo'     => $clip['titulo'],
//             'clip_url'   => BASE_URL . '/clip/' . $id,
//             'produtos'   => $clip['produtos'],
//         ]);
 
//         // Renderiza a home ou uma view dedicada
//         $this->render('home/index', [
//             'pageTitle'        => $pageTitle,
//             'autoOpenClipId'   => $id,
//             'autoOpenClipData' => $autoOpenClipData,
//         ]);
//     }
 
//     // ── POST /clips/view ─────────────────────────────────
//     public function view(): void {
//         $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
//         if (!$id) $this->json(['ok' => false]);
 
//         $this->clip->registrarView(
//             $id,
//             $this->sessionKey(),
//             $_SERVER['REMOTE_ADDR'] ?? ''
//         );
//         $this->json(['ok' => true]);
//     }
 
//     // ── POST /clips/like ─────────────────────────────────
//     public function like(): void {
//         $this->verifyCsrf();
//         $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
//         if (!$id) $this->json(['ok' => false]);
 
//         $ip = $_SERVER['REMOTE_ADDR'] ?? '';
//         if (!$this->clip->checarRateLimit($ip, 'like', 30)) {
//             $this->json(['ok' => false, 'msg' => 'Muitas ações. Aguarde.']);
//         }
 
//         $sessao    = $this->sessionKey();
//         $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
//         $curtiu    = $this->clip->toggleLike($id, $clienteId, $sessao, $ip);
 
//         $stmt = Database::getInstance()->getConnection()->prepare(
//             "SELECT total_likes FROM clips WHERE id=? LIMIT 1"
//         );
//         $stmt->execute([$id]);
//         $total = (int)$stmt->fetchColumn();
 
//         $this->json(['ok' => true, 'curtiu' => $curtiu, 'total' => $total]);
//     }
 
//     // ── POST /clips/share ────────────────────────────────
//     public function share(): void {
//         $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
//         if ($id) $this->clip->registrarShare($id);
//         $this->json(['ok' => true]);
//     }
 
//     // ── GET /clips/comentarios?id=X&page=1 ───────────────
//     public function comentarios(): void {
//         $id   = SecurityHelper::sanitizeInt($_GET['id']   ?? 0);
//         $page = max(1, (int)($_GET['page'] ?? 1));
//         if (!$id) $this->json(['ok' => false]);
 
//         $comentarios = $this->clip->getComentarios($id, $page);
//         $this->json(['ok' => true, 'comentarios' => $comentarios]);
//     }
 
//     // ── POST /clips/comentar ─────────────────────────────
//     public function comentar(): void {
//         $this->verifyCsrf();
 
//         $id    = SecurityHelper::sanitizeInt($_POST['id']    ?? 0);
//         $nome  = SecurityHelper::sanitizeString($_POST['nome']  ?? '');
//         $texto = SecurityHelper::sanitizeString($_POST['texto'] ?? '');
 
//         if (!$id || empty($nome) || empty($texto)) {
//             $this->json(['ok' => false, 'msg' => 'Preencha todos os campos.']);
//         }
//         if (mb_strlen($texto) > 500) {
//             $this->json(['ok' => false, 'msg' => 'Comentário muito longo.']);
//         }
 
//         $ip = $_SERVER['REMOTE_ADDR'] ?? '';
//         if (!$this->clip->checarRateLimit($ip, 'comentario', 5)) {
//             $this->json(['ok' => false, 'msg' => 'Muitos comentários. Aguarde.']);
//         }
 
//         $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
//         $comentario = $this->clip->addComentario($id, $nome, $texto, $clienteId, $ip);
 
//         $msg = $comentario['aprovado']
//              ? null  // exibe direto
//              : 'Comentário enviado! Aparecerá após moderação.';
 
//         $this->json([
//             'ok'         => true,
//             'msg'        => $msg,
//             'aprovado'   => $comentario['aprovado'],
//             'comentario' => $comentario,
//         ]);
//     }
// }

// ════════════════════════════════════════════════════════
// app/controllers/ClipController.php
// ════════════════════════════════════════════════════════
// class ClipController extends Controller {

//     private Clip $clip;

//     public function __construct() {
//         $this->clip = new Clip();
//     }

//     private function sessionKey(): string {
//         if (empty($_SESSION['clip_session'])) {
//             $_SESSION['clip_session'] = bin2hex(random_bytes(16));
//         }
//         return $_SESSION['clip_session'];
//     }

//     // ── GET /clips/feed?page=1&destaque=1 ────────────────
//     public function feed(): void {
//         $page      = max(1, (int)($_GET['page']     ?? 1));
//         $destaque  = !empty($_GET['destaque']);
//         $produtoId = (int)($_GET['produto_id'] ?? 0);

//         if ($produtoId > 0) {
//             $clips = $this->clip->getPorProduto($produtoId);
//             $total = count($clips);
//         } else {
//             $clips = $this->clip->getFeed($page, 10, $destaque);
//             $total = $this->clip->countFeed($destaque);
//         }

//         $sessao = $this->sessionKey();
//         $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;

//         $clips = array_map(function (array $c) use ($sessao, $clienteId): array {
//             $c['curtiu']        = $this->clip->jaÇurtiu((int)$c['id'], $clienteId, $sessao);
//             $c['video_url']     = UPLOAD_URL . '/clips/' . $c['arquivo_video'];
//             $c['poster_url']    = $c['arquivo_poster']
//                                   ? UPLOAD_URL . '/clips/posters/' . $c['arquivo_poster']
//                                   : null;
//             $c['produto_preco_fmt'] = $c['produto_preco']
//                                      ? 'R$ ' . number_format((float)$c['produto_preco'], 2, ',', '.')
//                                      : null;
//             $c['produto_preco_promo_fmt'] = $c['produto_preco_promo']
//                 ? 'R$ ' . number_format((float)$c['produto_preco_promo'], 2, ',', '.')
//                 : null;
//             $c['produto_img_url'] = $c['produto_imagem']
//                 ? UPLOAD_URL . '/products/' . $c['produto_imagem']
//                 : null;
//             return $c;
//         }, $clips);

//         $this->json([
//             'ok'       => true,
//             'clips'    => $clips,
//             'total'    => $total,
//             'page'     => $page,
//             'has_more' => ($page * 10) < $total,
//         ]);
//     }

//     // ── POST /clips/view ─────────────────────────────────
//     public function view(): void {
//         $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
//         if (!$id) $this->json(['ok' => false]);

//         $ip      = $_SERVER['REMOTE_ADDR'] ?? '';
//         $sessao  = $this->sessionKey();
//         $this->clip->registrarView($id, $sessao, $ip);
//         $this->json(['ok' => true]);
//     }

//     // ── POST /clips/like ─────────────────────────────────
//     public function like(): void {
//         $this->verifyCsrf();
//         $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
//         if (!$id) $this->json(['ok' => false]);

//         $ip = $_SERVER['REMOTE_ADDR'] ?? '';
//         if (!$this->clip->checarRateLimit($ip, 'like', 30)) {
//             $this->json(['ok' => false, 'msg' => 'Muitas ações. Aguarde.']);
//         }

//         $sessao    = $this->sessionKey();
//         $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
//         $curtiu    = $this->clip->toggleLike($id, $clienteId, $sessao, $ip);

//         $stmt = Database::getInstance()->getConnection()->prepare(
//             "SELECT total_likes FROM clips WHERE id=? LIMIT 1"
//         );
//         $stmt->execute([$id]);
//         $total = (int)$stmt->fetchColumn();

//         $this->json(['ok' => true, 'curtiu' => $curtiu, 'total' => $total]);
//     }

//     // ── POST /clips/share ────────────────────────────────
//     public function share(): void {
//         $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
//         if ($id) $this->clip->registrarShare($id);
//         $this->json(['ok' => true]);
//     }

//     // ── GET /clips/comentarios?id=X&page=1 ───────────────
//     public function comentarios(): void {
//         $id   = SecurityHelper::sanitizeInt($_GET['id']   ?? 0);
//         $page = max(1, (int)($_GET['page'] ?? 1));
//         if (!$id) $this->json(['ok' => false]);

//         $comentarios = $this->clip->getComentarios($id, $page);
//         $this->json(['ok' => true, 'comentarios' => $comentarios]);
//     }

//     // ── POST /clips/comentar ─────────────────────────────
//     public function comentar(): void {
//         $this->verifyCsrf();

//         $id    = SecurityHelper::sanitizeInt($_POST['id']    ?? 0);
//         $nome  = SecurityHelper::sanitizeString($_POST['nome']  ?? '');
//         $texto = SecurityHelper::sanitizeString($_POST['texto'] ?? '');

//         if (!$id || empty($nome) || empty($texto)) {
//             $this->json(['ok' => false, 'msg' => 'Preencha todos os campos.']);
//         }

//         if (mb_strlen($texto) > 500) {
//             $this->json(['ok' => false, 'msg' => 'Comentário muito longo.']);
//         }

//         $ip = $_SERVER['REMOTE_ADDR'] ?? '';
//         if (!$this->clip->checarRateLimit($ip, 'comentario', 5)) {
//             $this->json(['ok' => false, 'msg' => 'Muitos comentários. Aguarde.']);
//         }

//         $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;

//         $this->clip->addComentario($id, $nome, $texto, $clienteId, $ip);

//         $msg = $clienteId
//              ? 'Comentário enviado!'
//              : 'Comentário enviado! Aparecerá após moderação.';

//         $this->json(['ok' => true, 'msg' => $msg]);
//     }
// }