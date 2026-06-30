<?php
// admin/controllers/BannerAdminController.php

class BannerAdminController extends Controller {

    private PDO $db;

    public function __construct() {
        AuthHelper::requirePermission('banners');
        $this->db = Database::getInstance()->getConnection();
    }

    public function index(): void {
        $prefixes = $this->db->query(
            "SELECT id_prefix, COUNT(*) AS total FROM banners GROUP BY id_prefix ORDER BY id_prefix"
        )->fetchAll();

        $banners = $this->db->query(
            "SELECT * FROM banners ORDER BY id_prefix ASC, ordem ASC"
        )->fetchAll();

        $this->render('banners/index', [
            'banners'  => $banners,
            'prefixes' => $prefixes,
        ], 'admin');
    }

    public function save(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0) ?: null;

        $data = [
            'id_prefix'    => SecurityHelper::sanitizeString($_POST['id_prefix']   ?? ''),
            'titulo'       => SecurityHelper::sanitizeString($_POST['titulo']       ?? ''),
            'subtitulo'    => SecurityHelper::sanitizeString($_POST['subtitulo']    ?? ''),
            'link'         => SecurityHelper::sanitizeString($_POST['link']         ?? ''),
            'link_target'  => $_POST['link_target'] === '_blank' ? '_blank' : '_self',
            'cor_fundo'    => SecurityHelper::sanitizeString($_POST['cor_fundo']    ?? ''),
            'ordem'        => SecurityHelper::sanitizeInt(  $_POST['ordem']        ?? 0),
            'valido_de'    => !empty($_POST['valido_de'])   ? $_POST['valido_de']   : null,
            'valido_ate'   => !empty($_POST['valido_ate'])  ? $_POST['valido_ate']  : null,
            'ativo'        => isset($_POST['ativo']) ? 1 : 0,
        ];

        if (empty($data['id_prefix'])) {
            $this->json(['ok' => false, 'msg' => 'ID prefix obrigatório.']);
        }

        // Upload da imagem principal
        if (!empty($_FILES['imagem']['name'])) {
            if (!SecurityHelper::validateUploadedImage($_FILES['imagem'])) {
                $this->json(['ok' => false, 'msg' => 'Imagem inválida.']);
            }
            $upload = new UploadHelper();
            $arquivo = $upload->saveImage($_FILES['imagem'], 'banners', 1920, 600);
            if ($arquivo) {
                // Remove imagem antiga
                if ($id) {
                    $old = $this->db->prepare("SELECT imagem FROM banners WHERE id=?");
                    $old->execute([$id]);
                    $oldImg = $old->fetchColumn();
                    if ($oldImg) @unlink(UPLOAD_PATH . '/banners/' . $oldImg);
                }
                $data['imagem'] = $arquivo;
            }
        } elseif (!$id) {
            $this->json(['ok' => false, 'msg' => 'Imagem obrigatória para novo banner.']);
        }

        // Upload mobile
        if (!empty($_FILES['imagem_mobile']['name'])) {
            $upload  = new UploadHelper();
            $mobile  = $upload->saveImage($_FILES['imagem_mobile'], 'banners', 800, 600);
            if ($mobile) $data['imagem_mobile'] = $mobile;
        }

        if ($id) {
            $set  = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
            $vals = array_values($data);
            $vals[] = $id;
            $this->db->prepare("UPDATE banners SET {$set} WHERE id=?")->execute($vals);
        } else {
            $cols = implode(', ', array_keys($data));
            $phs  = implode(', ', array_fill(0, count($data), '?'));
            $this->db->prepare("INSERT INTO banners ({$cols}) VALUES ({$phs})")
                      ->execute(array_values($data));
        }

        $this->json(['ok' => true, 'msg' => 'Banner salvo!',
                     'redirect' => ADMIN_URL . '/banners']);
    }

    public function delete(): void {
        $this->verifyCsrf();
        $id  = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $stmt = $this->db->prepare("SELECT imagem, imagem_mobile FROM banners WHERE id=?");
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if ($b) {
            if ($b['imagem'])        @unlink(UPLOAD_PATH . '/banners/' . $b['imagem']);
            if ($b['imagem_mobile']) @unlink(UPLOAD_PATH . '/banners/' . $b['imagem_mobile']);
        }
        $this->db->prepare("DELETE FROM banners WHERE id=?")->execute([$id]);
        $this->json(['ok' => true, 'msg' => 'Banner excluído.']);
    }

    public function toggle(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $this->db->prepare("UPDATE banners SET ativo = 1-ativo WHERE id=?")->execute([$id]);
        $stmt = $this->db->prepare("SELECT ativo FROM banners WHERE id=?");
        $stmt->execute([$id]);
        $this->json(['ok' => true, 'ativo' => (int)$stmt->fetchColumn()]);
    }

    public function reorder(): void {
        $this->verifyCsrf();
        $ids = $_POST['ids'] ?? [];
        foreach ($ids as $ordem => $id) {
            $this->db->prepare("UPDATE banners SET ordem=? WHERE id=?")
                      ->execute([$ordem, (int)$id]);
        }
        $this->json(['ok' => true]);
    }
}