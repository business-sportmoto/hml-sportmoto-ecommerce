<?php
declare(strict_types=1);

class BannersController extends Controller {

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

        $form = $_FILES;

        if (!$zonaId || empty($titulo)) {
            $this->json(['ok' => false, 'msg' => 'Zona e título são obrigatórios.']);
        }
        if (!in_array($tipoMidia, ['imagem','video','video_com_imagem'])) {
            $tipoMidia = 'imagem';
        }

        $db = Database::getInstance()->getConnection();

        // ── Uploads ──────────────────────────────────────
        $uploads = [
            'arquivo_imagem'        => $this->uploadMidia('imagem', 'image'),
            'arquivo_video'         => $this->uploadMidia('video',  'video'),
            'arquivo_imagem_mobile' => $this->uploadMidia('imagem_mobile', 'image'),
            'arquivo_video_mobile'  => $this->uploadMidia('video_mobile',  'video'),
        ];

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
            $this->json(['ok' => true, 'msg' => 'Banner salvo!', 'id' => $id, 'teste'=> $form]);
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

        // Remove arquivos
        if ($b) {
            foreach ($b as $arq) {
                if ($arq) @unlink(UPLOAD_PATH . '/banners/' . $arq);
            }
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

    // ── Helpers ───────────────────────────────────────────
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
}