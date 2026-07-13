<?php
declare(strict_types=1);

class BannersController extends Controller {

    use HandlesStreamVideo;

    public function __construct() {
        AuthHelper::requireAdmin();
    }

    // ── Listagem por zonas ────────────────────────────────
    public function index(): void {
        $db = Database::getInstance()->getConnection();

        $zonaId  = SecurityHelper::sanitizeInt($_GET['zona_id'] ?? 0);

        if(!$zonaId){
            $zonas = $db->query(
            "SELECT z.*,
                        COUNT(b.id) AS total_banners,
                        SUM(CASE WHEN b.ativo=1 THEN 1 ELSE 0 END) AS total_ativos
                FROM banner_zonas z
                LEFT JOIN banners b ON b.zona_id = z.id
                WHERE z.ativo = 1
                GROUP BY z.id
                ORDER BY z.ordem ASC, z.nome ASC"
            )->fetchAll();
        }else{
            $stmt = $db->prepare(
                "SELECT z.*,
                        COUNT(b.id) AS total_banners,
                        SUM(CASE WHEN b.ativo=1 THEN 1 ELSE 0 END) AS total_ativos
                FROM banner_zonas z
                LEFT JOIN banners b ON b.zona_id = z.id
                WHERE z.id = ?
                GROUP BY z.id
                ORDER BY z.ordem ASC, z.nome ASC"
            );
            $stmt->execute([$zonaId]);

            $zonas = $stmt->fetchAll();
        }

        // Banners agrupados por zona
        $banners = $db->query(
            "SELECT b.*, z.chave AS zona_chave, z.nome AS zona_nome
             FROM banners b
             JOIN banner_zonas z ON z.id = b.zona_id
             ORDER BY z.ordem ASC, b.ordem ASC, b.id DESC"
        )->fetchAll();

        $bannersPorZona = [];
        foreach ($banners as $b) {
            $bannersPorZona[$b['zona_id']][] = $b;
        }

        $this->render('banners/index', [
            'zonas'          => $zonas,
            'bannersPorZona' => $bannersPorZona,
        ], 'admin');
    }

    // ── Form criar/editar ─────────────────────────────────
    public function form(): void {
        $id      = SecurityHelper::sanitizeInt($_GET['id']      ?? 0);
        $zonaId  = SecurityHelper::sanitizeInt($_GET['zona_id'] ?? 0);

        $db      = Database::getInstance()->getConnection();
        $banner  = null;

        if ($id > 0) {
            $stmt = $db->prepare("SELECT * FROM banners WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $banner = $stmt->fetch();
            if ($banner) $zonaId = (int)$banner['zona_id'];
        }

        $zonas = $db->query(
            "SELECT * FROM banner_zonas WHERE ativo=1 ORDER BY ordem ASC, nome ASC"
        )->fetchAll();

        $this->render('banners/form', [
            'banner' => $banner,
            'zonaId' => $zonaId,
            'zonas'  => $zonas,
        ], 'admin');
    }

    // ── Salvar ────────────────────────────────────────────
    public function salvar(): void {
        $this->verifyCsrf();

        CacheHelper::delete('categoryModelHome');

        $id          = SecurityHelper::sanitizeInt($_POST['id']            ?? 0);
        $zonaId      = SecurityHelper::sanitizeInt($_POST['zona_id']       ?? 0);
        $titulo      = SecurityHelper::sanitizeString($_POST['titulo']      ?? '');
        $tipoMidia   = $_POST['tipo_midia'] ?? 'imagem';

        
        if (!$zonaId || empty($titulo)) {
            $this->json(['ok' => false, 'msg' => 'Zona e título são obrigatórios.']);
        }
        if (!in_array($tipoMidia, ['imagem','video','video_com_imagem'])) {
            $tipoMidia = 'imagem';
        }

        $db = Database::getInstance()->getConnection();

        // Ao EDITAR, guarda valores antigos p/ limpeza (imagens R2 + videos Stream)
        $antigas = [];
        if ($id > 0) {
            $stmt = $db->prepare(
                "SELECT arquivo_imagem, arquivo_imagem_mobile,
                        arquivo_video, arquivo_video_mobile
                 FROM banners WHERE id=?"
            );
            $stmt->execute([$id]);
            $antigas = $stmt->fetch() ?: [];
        }

        $teste = [
                'account_id'      => getenv('R2_ACCOUNT_ID'),
                'access_key'      => getenv('R2_MEDIA_ACCESS_KEY'),
                'secret_key'      => getenv('R2_MEDIA_SECRET_KEY'),
                'bucket'          => getenv('R2_MEDIA_BUCKET'),
                'public_base_url' => getenv('R2_MEDIA_PUBLIC_URL'),
            ];
        $teste = json_encode($teste);

        $this->json(['ok' => false, 'msg' => 'Zona e título são obrigatórios. (stage 3)', 'teste'=> $teste]);
        exit();

        
        try {
            $uploads = [
                // IMAGENS -> R2/WebP (processa no servidor)
                'arquivo_imagem'        => $this->uploadImagemR2('imagem'),
                'arquivo_imagem_mobile' => $this->uploadImagemR2('imagem_mobile'),
                // VIDEOS -> UID do Stream, ja enviado pelo frontend (hidden).
                //          Apenas valida e persiste o UID; nao ha upload aqui.
                'arquivo_video'         => $this->videoUidFromPost('arquivo_video'),
                'arquivo_video_mobile'  => $this->videoUidFromPost('arquivo_video_mobile'),
            ];
        } catch (\RuntimeException $e) {
            error_log('[BANNER-UPLOAD] ' . $e->getMessage());
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }

        

        // Coleta os campos
        $dados = [
            'zona_id'            => $zonaId,
            'titulo'             => $titulo,
            'nome_publico'       => SecurityHelper::sanitizeString($_POST['nome_publico'] ?? '') ?: null,
            'subtitulo'          => SecurityHelper::sanitizeString($_POST['subtitulo']    ?? '') ?: null,
            'descricao'          => SecurityHelper::sanitizeString($_POST['descricao']    ?? '') ?: null,
            'tipo_midia'         => $tipoMidia,
            'video_url_externo'  => SecurityHelper::sanitizeString($_POST['video_url_externo'] ?? '') ?: null,
            'video_autoplay'     => isset($_POST['video_autoplay']) ? 1 : 0,
            'video_loop'         => isset($_POST['video_loop'])     ? 1 : 0,
            'video_mute'         => isset($_POST['video_mute'])     ? 1 : 0,
            'titulo_overlay'     => SecurityHelper::sanitizeString($_POST['titulo_overlay']     ?? '') ?: null,
            'subtitulo_overlay'  => SecurityHelper::sanitizeString($_POST['subtitulo_overlay'] ?? '') ?: null,
            'posicao_texto'      => $_POST['posicao_texto'] ?? 'center',
            'cor_texto'          => $this->validHex($_POST['cor_texto']      ?? ''),
            'cor_overlay'        => $this->validHex($_POST['cor_overlay']    ?? ''),
            'overlay_opacidade'  => max(0, min(100, (int)($_POST['overlay_opacidade'] ?? 0))),
            'cor_fundo'          => $this->validHex($_POST['cor_fundo']      ?? ''),
            'cta1_texto'         => SecurityHelper::sanitizeString($_POST['cta1_texto'] ?? '') ?: null,
            'cta1_link'          => SecurityHelper::sanitizeString($_POST['cta1_link']  ?? '') ?: null,
            'cta1_estilo'        => $_POST['cta1_estilo'] ?? 'primary',
            'cta1_target'        => in_array($_POST['cta1_target'] ?? '', ['_self','_blank']) ? $_POST['cta1_target'] : '_self',
            'cta2_texto'         => SecurityHelper::sanitizeString($_POST['cta2_texto'] ?? '') ?: null,
            'cta2_link'          => SecurityHelper::sanitizeString($_POST['cta2_link']  ?? '') ?: null,
            'cta2_estilo'        => $_POST['cta2_estilo'] ?? 'outline',
            'cta2_target'        => in_array($_POST['cta2_target'] ?? '', ['_self','_blank']) ? $_POST['cta2_target'] : '_self',
            'link_geral'         => SecurityHelper::sanitizeString($_POST['link_geral'] ?? '') ?: null,
            'link_target'        => in_array($_POST['link_target'] ?? '', ['_self','_blank']) ? $_POST['link_target'] : '_self',
            'alt_text'           => SecurityHelper::sanitizeString($_POST['alt_text'] ?? '') ?: null,
            'ordem'              => SecurityHelper::sanitizeInt($_POST['ordem']  ?? 0),
            'ativo'              => isset($_POST['ativo']) ? 1 : 0,
            'data_inicio'        => $this->validDateTime($_POST['data_inicio'] ?? ''),
            'data_fim'           => $this->validDateTime($_POST['data_fim']    ?? ''),
        ];

        // Adiciona uploads (só sobrescreve se vier novo)
        foreach ($uploads as $campo => $valor) {
            if ($valor !== null) $dados[$campo] = $valor;
        }

        try {
            if ($id > 0) {
                $sets   = implode(', ', array_map(fn($k) => "{$k}=?", array_keys($dados)));
                $params = array_values($dados);
                $params[] = $id;
                $db->prepare("UPDATE banners SET {$sets} WHERE id=?")->execute($params);
            } else {
                $cols = implode(',', array_keys($dados));
                $vals = implode(',', array_fill(0, count($dados), '?'));
                $db->prepare("INSERT INTO banners ({$cols}) VALUES ({$vals})")
                   ->execute(array_values($dados));
                $id = (int)$db->lastInsertId();
            }

            // Limpeza de imagens antigas trocadas (só quando editando e veio nova)
            if (!empty($antigas)) {
                // imagens (ja existente)
                if (($uploads['arquivo_imagem'] ?? null) !== null) {
                    $this->deleteImagemR2($antigas['arquivo_imagem'] ?? null);
                }
                if (($uploads['arquivo_imagem_mobile'] ?? null) !== null) {
                    $this->deleteImagemR2($antigas['arquivo_imagem_mobile'] ?? null);
                }
                // videos: se o UID mudou, apaga o antigo do Stream
                if (($uploads['arquivo_video'] ?? null) !== null
                    && !empty($antigas['arquivo_video'])
                    && $antigas['arquivo_video'] !== $uploads['arquivo_video']) {
                    $this->deleteVideoStream($antigas['arquivo_video']);
                }
                if (($uploads['arquivo_video_mobile'] ?? null) !== null
                    && !empty($antigas['arquivo_video_mobile'])
                    && $antigas['arquivo_video_mobile'] !== $uploads['arquivo_video_mobile']) {
                    $this->deleteVideoStream($antigas['arquivo_video_mobile']);
                }
            }

            $this->json(['ok' => true, 'msg' => 'Banner salvo!', 'id' => $id]);
        } catch (\Exception $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── Excluir ───────────────────────────────────────────
    public function excluir(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT arquivo_imagem, arquivo_video, arquivo_imagem_mobile, arquivo_video_mobile FROM banners WHERE id=?");
        $stmt->execute([$id]);
        $b = $stmt->fetch();

        if ($b) {
            // Imagens: agora vivem no R2 (URL). Vídeos: ainda no disco local.
            $this->deleteImagemR2($b['arquivo_imagem'] ?? null);
            $this->deleteImagemR2($b['arquivo_imagem_mobile'] ?? null);

            // Videos agora sao UIDs do Stream:
            $this->deleteVideoStream($b['arquivo_video'] ?? null);
            $this->deleteVideoStream($b['arquivo_video_mobile'] ?? null);
        }

        $db->prepare("DELETE FROM banners WHERE id=?")->execute([$id]);
        $this->json(['ok' => true]);
    }

    // ── Toggle ativo ──────────────────────────────────────
    public function toggleAtivo(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT ativo FROM banners WHERE id=?");
        $stmt->execute([$id]);
        $novo = (int)$stmt->fetchColumn() ? 0 : 1;

        $db->prepare("UPDATE banners SET ativo=? WHERE id=?")->execute([$novo, $id]);
        $this->json(['ok' => true, 'ativo' => $novo]);
    }

    // ── Reordenar ─────────────────────────────────────────
    public function reordenar(): void {
        $this->verifyCsrf();
        $stmt = Database::getInstance()->getConnection()
            ->prepare("UPDATE banners SET ordem=? WHERE id=?");
        foreach ($_POST['ordens'] ?? [] as $ordem => $id) {
            $stmt->execute([(int)$ordem, (int)$id]);
        }
        $this->json(['ok' => true]);
    }

    // ── Mídia: R2 (imagens) ───────────────────────────────

    /** Service R2 instanciado sob demanda (credencial de escrita do .env). */
    private function mediaService(): R2MediaService {
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
     * Upload de UM slot de imagem do banner para o R2, em WebP.
     * 'imagem' -> 1920px (desktop) | 'imagem_mobile' -> 768px.
     * Retorna a URL pública (CDN) ou null se nada foi enviado.
     * Lança RuntimeException (capturada no salvar) se inválido.
     */
    private function uploadImagemR2(string $slot): ?string {
        $file = $_FILES[$slot] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $processor = new ImageProcessor();
        $processor->validateUpload($file); // magic bytes + tamanho + dimensão

        $maxWidth = ($slot === 'imagem_mobile') ? 768 : 1920;
        $variants = $processor->toWebpVariants($file['tmp_name'], ['b' => $maxWidth]);

        $key = R2MediaService::generateKey('banners', 'webp');
        return $this->mediaService()->upload($key, $variants['b'], 'image/webp');
    }

    /**
     * Remove uma imagem do R2 a partir da URL pública salva no banco.
     * Idempotente; ignora valores que não são URL do nosso bucket.
     */
    private function deleteImagemR2(?string $publicUrl): void {
        if (empty($publicUrl)) return;
        $base = rtrim((string) getenv('R2_MEDIA_PUBLIC_URL'), '/') . '/';
        if (!str_starts_with($publicUrl, $base)) return; // legado local / outro storage
        $key = substr($publicUrl, strlen($base));
        $this->mediaService()->delete($key);
    }

    // ── Helpers ───────────────────────────────────────────

    /** Upload de VÍDEO para disco local (Cloudflare Stream fica p/ depois). */
    private function uploadMidia(string $campo, string $tipo): ?string {
        if (empty($_FILES[$campo]['tmp_name'])) return null;

        $allowed = $tipo === 'video'
                   ? ['mp4', 'webm', 'mov']
                   : ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        $maxSize = $tipo === 'video' ? 50 * 1024 * 1024 : 5 * 1024 * 1024;

        $ext = strtolower(pathinfo($_FILES[$campo]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            throw new \RuntimeException("Formato inválido para {$campo}.");
        }
        if ($_FILES[$campo]['size'] > $maxSize) {
            throw new \RuntimeException("Arquivo {$campo} excede o tamanho máximo.");
        }

        $dir = UPLOAD_PATH . '/banners/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $arquivo = 'banner_' . $tipo . '_' . uniqid() . '.' . $ext;
        if (!move_uploaded_file($_FILES[$campo]['tmp_name'], $dir . $arquivo)) {
            throw new \RuntimeException("Falha ao salvar {$campo}.");
        }

        return $arquivo;
    }

    private function validHex(string $color): ?string {
        $color = trim($color);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : null;
    }

    private function validDateTime(string $dt): ?string {
        $dt = trim($dt);
        if (!$dt) return null;
        $ts = strtotime($dt);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }


    // ───────────────────────────────────────────────────────────────────────────
 
    
       
    
}