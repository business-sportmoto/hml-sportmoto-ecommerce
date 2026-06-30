<?php
// admin/controllers/BeneficiosController.php

class BeneficiosController extends Controller {

    public function __construct() {
        AuthHelper::requireAdmin();
    }

    public function index(): void {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->query(
            "SELECT * FROM beneficios_slider ORDER BY ordem ASC"
        );
        $beneficios = $stmt->fetchAll();

        $icons = $this->iconsMap();

        $this->render('beneficios/index', [
            'beneficios' => $beneficios,
            'icons'      => $icons,
        ], 'admin');
    }

    public function salvar(): void {
        $this->verifyCsrf();

        $db    = Database::getInstance()->getConnection();
        $items = $_POST['items'] ?? [];

        if (empty($items)) {
            $this->json(['ok' => false, 'msg' => 'Nenhum item enviado.']);
        }

        try {
            $db->beginTransaction();

            $idsEnviados = array_values(array_filter(
                array_column($items, 'id'),
                fn($id) => (int)$id > 0
            ));

            if (!empty($idsEnviados)) {
                $placeholders = implode(',', array_fill(0, count($idsEnviados), '?'));
                $db->prepare(
                    "DELETE FROM beneficios_slider WHERE id NOT IN ({$placeholders})"
                )->execute($idsEnviados);
            } else {
                $db->exec("DELETE FROM beneficios_slider");
            }

            $stmtUpdate = $db->prepare(
                "UPDATE beneficios_slider
                 SET icone = ?, titulo = ?, descricao = ?,
                     link = ?, css_classe = ?, ativo = ?, ordem = ?
                 WHERE id = ?"
            );
            $stmtInsert = $db->prepare(
                "INSERT INTO beneficios_slider
                 (icone, titulo, descricao, link, css_classe, ativo, ordem)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            foreach ($items as $ordem => $item) {
                $id        = (int)($item['id']        ?? 0);
                $icone     = SecurityHelper::sanitizeString($item['icone']      ?? 'star');
                $titulo    = SecurityHelper::sanitizeString($item['titulo']     ?? '');
                $descricao = SecurityHelper::sanitizeString($item['descricao']  ?? '');
                $link      = SecurityHelper::sanitizeString($item['link']       ?? '');
                $classe    = SecurityHelper::sanitizeString($item['css_classe'] ?? '');
                $ativo     = ($item['ativo'] ?? '0') == '1' ? 1 : 0;

                if (empty($titulo)) continue;

                if ($id > 0) {
                    $stmtUpdate->execute([
                        $icone, $titulo, $descricao,
                        $link ?: null, $classe ?: null,
                        $ativo, $ordem + 1, $id,
                    ]);
                } else {
                    $stmtInsert->execute([
                        $icone, $titulo, $descricao,
                        $link ?: null, $classe ?: null,
                        $ativo, $ordem + 1,
                    ]);
                }
            }

            $db->commit();
            CacheHelper::delete('benefits_slider');
            $this->json(['ok' => true, 'msg' => 'Benefícios salvos!']);

        } catch (Exception $e) {
            $db->rollBack();
            $this->json(['ok' => false, 'msg' => 'Erro ao salvar.']);
        }
    }

    private function iconsMap(): array {
        return [
            'truck'   => '<path d="M1 3h15v13H1zm15 5h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
            'shield'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'credit'  => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
            'headset' => '<path d="M3 18v-6a9 9 0 0118 0v6"/><path d="M21 19a2 2 0 01-2 2h-1a2 2 0 01-2-2v-3a2 2 0 012-2h3z"/><path d="M3 19a2 2 0 002 2h1a2 2 0 002-2v-3a2 2 0 00-2-2H3z"/>',
            'star'    => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'gift'    => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/>',
            'tag'     => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
            'refresh' => '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>',
            'favorite' => '<path d="m480-121-41-37q-105.77-97.12-174.88-167.56Q195-396 154-451.5T96.5-552Q80-597 80-643q0-90.15 60.5-150.58Q201-854 290-854q57 0 105.5 27t84.5 78q42-54 89-79.5T670-854q89 0 149.5 60.42Q880-733.15 880-643q0 46-16.5 91T806-451.5Q765-396 695.88-325.56 626.77-255.12 521-158l-41 37Zm0-79q101.24-93 166.62-159.5Q712-426 750.5-476t54-89.14q15.5-39.13 15.5-77.72 0-66.14-42-108.64T670.22-794q-51.52 0-95.37 31.5T504-674h-49q-26-56-69.85-88-43.85-32-95.37-32Q224-794 182-751.5t-42 108.82q0 38.68 15.5 78.18 15.5 39.5 54 90T314-358q66 66 166 158Zm0-297Z"/>',
        ];
    }
}

