<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/ClipsController.php — v3
// Adições: index() suporta ?json=1 para scroll infinito
//           gerarPoster() gera thumb via ffmpeg
// ════════════════════════════════════════════════════════
class ClipsController extends Controller {

    use HandlesStreamVideo;

    public function __construct() {
        AuthHelper::requireAdmin();
    }

    // ── GET /admin/clips ─────────────────────────────────
    public function index(): void {
        $db      = Database::getInstance()->getConnection();
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;
        $isJson  = !empty($_GET['json']);

        $busca  = SecurityHelper::sanitizeString($_GET['q']      ?? '');
        $status = SecurityHelper::sanitizeString($_GET['status'] ?? '');
        $ordem  = SecurityHelper::sanitizeString($_GET['ordem']  ?? 'recentes');

        $svc = new ClipService();

        // WHERE dinâmico
        $where  = "1=1";
        $params = [];

        if ($busca) {
            $where   .= " AND c.titulo LIKE ?";
            $params[] = "%{$busca}%";
        }

        switch ($status) {
            case 'ativo':      $where .= " AND c.ativo = 1"; break;
            case 'inativo':    $where .= " AND c.ativo = 0"; break;
            case 'destaque':   $where .= " AND c.destaque = 1"; break;
            case 'sem_poster_custom': $where .= " AND (c.arquivo_poster IS NULL OR c.arquivo_poster = '')"; break;
        }

        // ORDER BY
        $orderSql = match ($ordem) {
            'visualizacoes' => 'c.total_views DESC',
            'likes'         => 'c.total_likes DESC',
            'ordem'         => 'c.ordem ASC, c.criado_em DESC',
            default         => 'c.criado_em DESC',
        };

        // Count total
        $stmtTotal = $db->prepare(
            "SELECT COUNT(*) FROM clips c WHERE {$where}"
        );
        $stmtTotal->execute($params);
        $total = (int)$stmtTotal->fetchColumn();

        // Busca clips com nomes de produtos agrupados
        $stmtClips = $db->prepare(
            "SELECT c.*,
                    GROUP_CONCAT(p.nome ORDER BY cp.ordem SEPARATOR ', ') AS _produto_nomes
             FROM clips c
             LEFT JOIN clip_produtos cp ON cp.clip_id = c.id
             LEFT JOIN produtos p       ON p.id = cp.produto_id
             WHERE {$where}
             GROUP BY c.id
             ORDER BY {$orderSql}
             LIMIT ? OFFSET ?"
        );
        $execParams   = $params;
        $execParams[] = $perPage;
        $execParams[] = $offset;
        $stmtClips->execute($execParams);
        $clips = $stmtClips->fetchAll();

        $clips = array_map(function (array $c) use ($svc): array {
            $uid = (string)($c['arquivo_video'] ?? '');
            $isUid = preg_match('/^[a-f0-9]{32}$/i', $uid);

            // Poster para o card da listagem (custom OU thumbnail do vídeo)
            $c['poster_url'] = $svc->posterFor($c);

            // Flag útil para a view: o poster é personalizado ou automático?
            $c['poster_custom'] = !empty($c['arquivo_poster'])
                && str_starts_with((string)$c['arquivo_poster'], 'http');

            // Preview animado (GIF) para hover no card do admin — opcional
            $c['preview_url'] = $isUid ? $svc->previewUrl($uid) : null;

            // Indica se o vídeo está no Stream (vs legado/ausente)
            $c['tem_video'] = (bool)$isUid;

            return $c;
        }, $clips);

        $hasMore = ($page * $perPage) < $total;

        // Resposta JSON para o scroll infinito
        if ($isJson) {
            $this->json([
                'ok'      => true,
                'clips'   => $clips,
                'page'    => $page,
                'total'   => $total,
                'has_more'=> $hasMore,
            ]);
            return;
        }

        // Resposta HTML (primeira carga)
        $this->render('clips/index', [
            'clips'   => $clips,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'hasMore' => $hasMore,
            'busca'   => $busca,
        ], 'admin');
    }

    // ── GET /admin/clips/form ────────────────────────────
    public function form(): void {
        $id   = SecurityHelper::sanitizeInt($_GET['id'] ?? 0);
        $db   = Database::getInstance()->getConnection();
        $clip = null;

        if ($id) {
            $stmt = $db->prepare("SELECT * FROM clips WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $clip = $stmt->fetch() ?: null;
        }

        $produtos = $db->query(
            "SELECT id, nome FROM produtos
             WHERE ativo=1 AND deleted_at IS NULL
             ORDER BY nome ASC LIMIT 500"
        )->fetchAll();

        $produtosVinculados = $id ? (new Clip())->getProdutosDoClip($id) : [];

        $this->render('clips/form', [
            'clip'               => $clip,
            'produtos'           => $produtos,
            'produtosVinculados' => $produtosVinculados,
        ], 'admin');
    }

    // ── POST /admin/clips/salvar ─────────────────────────
    // public function salvar(): void {
    //     $this->verifyCsrf();

    //     $id        = SecurityHelper::sanitizeInt($_POST['id']          ?? 0);
    //     $titulo    = SecurityHelper::sanitizeString($_POST['titulo']   ?? '');
    //     $descricao = SecurityHelper::sanitizeString($_POST['descricao'] ?? '');
    //     $ctaTxt    = SecurityHelper::sanitizeString($_POST['cta_texto'] ?? '');
    //     $ctaLink   = SecurityHelper::sanitizeString($_POST['cta_link']  ?? '');
    //     $destaque  = isset($_POST['destaque']) ? 1 : 0;
    //     $ativo     = isset($_POST['ativo'])    ? 1 : 0;
    //     $ordem     = SecurityHelper::sanitizeInt($_POST['ordem']       ?? 0);
    //     $hashtags  = SecurityHelper::sanitizeString($_POST['hashtags'] ?? '');

    //     // IDs dos produtos vinculados (array)
    //     $produtoIds = array_values(array_filter(
    //         array_map('intval', (array)($_POST['produto_ids'] ?? []))
    //     ));

    //     if (empty($titulo)) {
    //         $this->json(['ok' => false, 'msg' => 'Título obrigatório.']);
    //     }

    //     $db  = Database::getInstance()->getConnection();
    //     $svc = new ClipService();

    //     $dados = [
    //         'titulo'    => $titulo,
    //         'descricao' => $descricao ?: null,
    //         'cta_texto' => $ctaTxt    ?: null,
    //         'cta_link'  => $ctaLink   ?: null,
    //         'destaque'  => $destaque,
    //         'ativo'     => $ativo,
    //         'ordem'     => $ordem,
    //         'hashtags'  => $hashtags  ?: null,
    //     ];

    //     if (!empty($_FILES['video']['tmp_name'])) {
    //         try {
    //             $resultado   = $svc->processar($_FILES['video']);
    //             $dados       = array_merge($dados, $resultado);
    //             $dados['status'] = 'ativo';
    //         } catch (\Exception $e) {
    //             $this->json(['ok' => false, 'msg' => $e->getMessage()]);
    //         }
    //     } elseif (!$id) {
    //         $this->json(['ok' => false, 'msg' => 'Selecione um vídeo.']);
    //     }

    //     if (!empty($_FILES['poster']['tmp_name'])) {
    //         $img    = new ImageProcessorService();
    //         $result = $img->processar($_FILES['poster'], 'clips/posters');
    //         $dados['arquivo_poster'] = $result['full'];
    //     }

    //     try {
    //         if ($id > 0) {
    //             $sets   = implode(',', array_map(fn($k) => "{$k}=?", array_keys($dados)));
    //             $params = array_values($dados);
    //             $params[] = $id;
    //             $db->prepare("UPDATE clips SET {$sets} WHERE id=?")->execute($params);
    //         } else {
    //             $cols   = implode(',', array_keys($dados));
    //             $vals   = implode(',', array_fill(0, count($dados), '?'));
    //             $db->prepare("INSERT INTO clips ({$cols}) VALUES ({$vals})")
    //                ->execute(array_values($dados));
    //             $id = (int)$db->lastInsertId();
    //         }

    //         (new Clip())->sincronizarProdutos($id, $produtoIds);
    //         $this->json(['ok' => true, 'msg' => 'Clip salvo!', 'id' => $id]);
    //     } catch (\Exception $e) {
    //         $this->json(['ok' => false, 'msg' => $e->getMessage()]);
    //     }
    // }

   public function salvar(): void
    {
        $this->verifyCsrf();
    
        $id        = SecurityHelper::sanitizeInt($_POST['id']          ?? 0);
        $titulo    = SecurityHelper::sanitizeString($_POST['titulo']   ?? '');
        $descricao = SecurityHelper::sanitizeString($_POST['descricao'] ?? '');
        $ctaTxt    = SecurityHelper::sanitizeString($_POST['cta_texto'] ?? '');
        $ctaLink   = SecurityHelper::sanitizeString($_POST['cta_link']  ?? '');
        $destaque  = isset($_POST['destaque']) ? 1 : 0;
        $ativo     = isset($_POST['ativo'])    ? 1 : 0;
        $ordem     = SecurityHelper::sanitizeInt($_POST['ordem']       ?? 0);
        $hashtags  = SecurityHelper::sanitizeString($_POST['hashtags'] ?? '');
    
        $produtoIds = array_filter(
            array_map('intval', (array)($_POST['produto_ids'] ?? []))
        );
    
        if (empty($titulo)) {
            $this->json(['ok' => false, 'msg' => 'Título obrigatório.']);
        }
    
        $db = Database::getInstance()->getConnection();
    
        $dados = [
            'titulo'    => $titulo,
            'descricao' => $descricao ?: null,
            'cta_texto' => $ctaTxt    ?: null,
            'cta_link'  => $ctaLink   ?: null,
            'destaque'  => $destaque,
            'ativo'     => $ativo,
            'ordem'     => $ordem,
            'hashtags'  => $hashtags  ?: null,
        ];
    
        // VIDEO: agora vem como UID do Stream (frontend fez upload direto).
        // O hidden 'arquivo_video' carrega o UID. Valida via trait.
        try {
            $uid = $this->videoUidFromPost('arquivo_video'); // null se nao trocou
        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    
        if ($uid !== null) {
            // Ao trocar o video de um clip existente, guarda o antigo p/ limpar.
            $uidAntigo = null;
            if ($id > 0) {
                $st = $db->prepare("SELECT arquivo_video FROM clips WHERE id=?");
                $st->execute([$id]);
                $uidAntigo = $st->fetchColumn() ?: null;
            }
    
            $dados['arquivo_video'] = $uid;
            $dados['status']        = 'ativo';
            // arquivo_poster deixa de ser arquivo local; o poster vem do UID.
            // Se a coluna existir e for NOT NULL, grave o UID ou uma string vazia.
            $dados['arquivo_poster'] = null;
        } elseif (!$id) {
            $this->json(['ok' => false, 'msg' => 'Selecione um vídeo.']);
        }

        // Poster customizado (opcional). Se não vier, arquivo_poster fica como está
        // (ou null), e o feed usa o thumbnail do Stream.
        try {
            $posterUrl = $this->uploadPosterR2();
        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }

        // Guarda o poster antigo p/ limpar se foi trocado
        $posterAntigo = null;
        if ($id > 0 && $posterUrl !== null) {
            $st = $db->prepare("SELECT arquivo_poster FROM clips WHERE id=?");
            $st->execute([$id]);
            $posterAntigo = $st->fetchColumn() ?: null;
        }

        if ($posterUrl !== null) {
            $dados['arquivo_poster'] = $posterUrl;
        }
        // NOTA: se o vídeo foi trocado e NÃO há poster custom, zere o poster antigo
        // para o fallback pegar o thumbnail do vídeo NOVO:
        if ($uid !== null && $posterUrl === null) {
            $dados['arquivo_poster'] = null;
        }

        // ... após o UPDATE/INSERT bem-sucedido, limpe o antigo:
        if (!empty($posterAntigo) && $posterAntigo !== $posterUrl) {
            $this->deletePosterR2($posterAntigo);
        }
    
        try {
            if ($id > 0) {
                $sets   = implode(',', array_map(fn($k) => "{$k}=?", array_keys($dados)));
                $params = array_values($dados);
                $params[] = $id;
                $db->prepare("UPDATE clips SET {$sets} WHERE id=?")->execute($params);
            } else {
                $cols = implode(',', array_keys($dados));
                $vals = implode(',', array_fill(0, count($dados), '?'));
                $db->prepare("INSERT INTO clips ({$cols}) VALUES ({$vals})")
                ->execute(array_values($dados));
                $id = (int)$db->lastInsertId();
            }
    
            (new Clip())->sincronizarProdutos($id, $produtoIds);
    
            // Limpa o video antigo no Stream, se foi trocado
            if (!empty($uidAntigo) && $uidAntigo !== ($uid ?? null)) {
                $this->deleteVideoStream($uidAntigo);
            }
    
            $this->json(['ok' => true, 'msg' => 'Clip salvo!', 'id' => $id]);
        } catch (\Exception $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── POST /admin/clips/excluir ────────────────────────
    public function excluir(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM clips WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $clip = $stmt->fetch();

        if ($clip) {
            (new ClipService())->deletar($clip);
            $db->prepare("DELETE FROM clips WHERE id=?")->execute([$id]);

            $this->deletePosterR2($clip['arquivo_poster'] ?? null);
        }
        $this->json(['ok' => true]);
    }

    // ── POST /admin/clips/toggle-ativo ───────────────────
    public function toggleAtivo(): void {
        $this->verifyCsrf();
        $id   = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT ativo FROM clips WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $novo = (int)$stmt->fetchColumn() ? 0 : 1;
        $db->prepare("UPDATE clips SET ativo=? WHERE id=?")->execute([$novo, $id]);
        $this->json(['ok' => true, 'ativo' => $novo]);
    }

    // ── POST /admin/clips/toggle-destaque ────────────────
    public function toggleDestaque(): void {
        $this->verifyCsrf();
        $id   = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT destaque FROM clips WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $novo = (int)$stmt->fetchColumn() ? 0 : 1;
        $db->prepare("UPDATE clips SET destaque=? WHERE id=?")->execute([$novo, $id]);
        $this->json(['ok' => true, 'destaque' => $novo]);
    }

    // ── POST /admin/clips/gerar-poster ───────────────────
    /**
     * Gera o poster/thumbnail do vídeo via ffmpeg.
     * Captura no segundo 1 do vídeo.
     * Se ffmpeg não estiver disponível, retorna aviso.
     */
    

    // ── GET /admin/clips/comentarios ────────────────────
    public function comentarios(): void {
        $db     = Database::getInstance()->getConnection();
        $filtro = $_GET['status'] ?? 'pendente';

        $statusValidos = ['pendente', 'aprovado', 'rejeitado'];
        if (!in_array($filtro, $statusValidos)) $filtro = 'pendente';

        $stmt = $db->prepare(
            "SELECT cc.*, c.titulo AS clip_titulo
             FROM clip_comentarios cc
             JOIN clips c ON c.id = cc.clip_id
             WHERE cc.status = ?
             ORDER BY cc.criado_em " . ($filtro === 'pendente' ? 'ASC' : 'DESC') . "
             LIMIT 100"
        );
        $stmt->execute([$filtro]);
        $comentarios = $stmt->fetchAll();

        $pendentes = (int)$db->query(
            "SELECT COUNT(*) FROM clip_comentarios WHERE status='pendente'"
        )->fetchColumn();

        $this->render('clips/comentarios', [
            'comentarios' => $comentarios,
            'pendentes'   => $pendentes,
            'filtro'      => $filtro,
        ], 'admin');
    }

    // ── POST /admin/clips/moderar-comentario ─────────────
    public function moderarComentario(): void {
        $this->verifyCsrf();
        $id     = SecurityHelper::sanitizeInt($_POST['id']     ?? 0);
        $status = $_POST['status'] ?? '';
        if (!$id) $this->json(['ok' => false]);

        $db = Database::getInstance()->getConnection();

        // Exclusão permanente
        if ($status === 'excluir') {
            $db->prepare("DELETE FROM clip_comentarios WHERE id=?")->execute([$id]);
            $this->json(['ok' => true]);
        }

        if (!in_array($status, ['aprovado', 'rejeitado'])) {
            $this->json(['ok' => false, 'msg' => 'Status inválido.']);
        }

        // Ajusta contador
        $stmt = $db->prepare(
            "SELECT status, clip_id FROM clip_comentarios WHERE id=? LIMIT 1"
        );
        $stmt->execute([$id]);
        $c = $stmt->fetch();

        if ($c) {
            if ($c['status'] !== 'aprovado' && $status === 'aprovado') {
                $db->prepare(
                    "UPDATE clips SET total_comentarios = total_comentarios+1 WHERE id=?"
                )->execute([$c['clip_id']]);
            }
            if ($c['status'] === 'aprovado' && $status === 'rejeitado') {
                $db->prepare(
                    "UPDATE clips SET total_comentarios = GREATEST(total_comentarios-1,0) WHERE id=?"
                )->execute([$c['clip_id']]);
            }
        }

        $db->prepare("UPDATE clip_comentarios SET status=? WHERE id=?")->execute([$status, $id]);
        $this->json(['ok' => true]);
    }


    /** Service R2 para o poster customizado (imagem). */
    private function mediaService(): R2MediaService
    {
        static $svc = null;
        if ($svc === null) {
            $svc = new R2MediaService([
                'account_id'      => getenv('R2_ACCOUNT_ID'),
                'access_key'      => getenv('R2_MEDIA_ACCESS_KEY'),
                'secret_key'      => getenv('R2_MEDIA_SECRET_KEY'),
                'bucket'          => getenv('R2_MEDIA_BUCKET'),
                'public_base_url' => getenv('R2_MEDIA_PUBLIC_URL'),
            ]);
        }
        return $svc;
    }

    /**
     * Upload do poster customizado (opcional) para o R2, em WebP 9:16.
     * Retorna a URL pública, ou null se nenhum arquivo foi enviado.
     * @throws \RuntimeException se o arquivo for inválido.
     */
    private function uploadPosterR2(): ?string
    {
        $file = $_FILES['poster'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null; // sem poster custom -> fallback pro thumbnail do Stream
        }

        $processor = new ImageProcessor();
        $processor->validateUpload($file);   // magic bytes + tamanho + dimensão

        // Formato vertical do feed (9:16). Largura 720 -> altura proporcional.
        $variants = $processor->toWebpVariants($file['tmp_name'], ['p' => 720]);

        $key = R2MediaService::generateKey('clips/posters', 'webp');
        return $this->mediaService()->upload($key, $variants['p'], 'image/webp');
    }

    /** Remove um poster do R2 (ao trocar/excluir). Idempotente. */
    private function deletePosterR2(?string $publicUrl): void
    {
        if (empty($publicUrl)) return;
        $base = rtrim((string) getenv('R2_MEDIA_PUBLIC_URL'), '/') . '/';
        if (!str_starts_with($publicUrl, $base)) return; // legado local
        $this->mediaService()->delete(substr($publicUrl, strlen($base)));
    }
}