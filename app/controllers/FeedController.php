<?php
// app/controllers/FeedController.php

class FeedController extends Controller {

    public function googleMerchant(): void {
        // Apenas acesso autenticado ou IP whitelist em produção
        header('Content-Type: application/xml; charset=UTF-8');

        $db    = Database::getInstance()->getConnection();
        $group = new ProductGroup();

        $stmt = $db->query(
            "SELECT p.id
             FROM produtos p
             WHERE p.ativo = 1 AND p.deleted_at IS NULL
             ORDER BY p.id ASC
             LIMIT 50000"
        );
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        echo '<channel>' . "\n";
        echo '<title>' . View::e(ConfigHelper::get('site_nome')) . '</title>' . "\n";
        echo '<link>' . BASE_URL . '</link>' . "\n";

        foreach ($ids as $id) {
            $data = $group->getGoogleMerchantData((int)$id);
            if (empty($data)) continue;

            // Busca imagens
            $imgStmt = $db->prepare(
                "SELECT arquivo FROM produto_imagens
                 WHERE produto_id = ? ORDER BY principal DESC, ordem ASC LIMIT 10"
            );
            $imgStmt->execute([$id]);
            $imgs = $imgStmt->fetchAll(PDO::FETCH_COLUMN);

            echo "<item>\n";
            echo "  <g:id>" . htmlspecialchars($data['id'], ENT_XML1) . "</g:id>\n";

            if ($data['item_group_id']) {
                echo "  <g:item_group_id>" . htmlspecialchars($data['item_group_id'], ENT_XML1) . "</g:item_group_id>\n";
            }

            echo "  <g:title>"        . htmlspecialchars($data['title'],       ENT_XML1) . "</g:title>\n";
            echo "  <g:description>"  . htmlspecialchars($data['description'], ENT_XML1) . "</g:description>\n";
            echo "  <g:link>"         . htmlspecialchars($data['link'],        ENT_XML1) . "</g:link>\n";
            echo "  <g:price>"        . htmlspecialchars($data['price'],       ENT_XML1) . "</g:price>\n";
            echo "  <g:brand>"        . htmlspecialchars($data['brand'],       ENT_XML1) . "</g:brand>\n";
            echo "  <g:condition>"    . htmlspecialchars($data['condition'],   ENT_XML1) . "</g:condition>\n";
            echo "  <g:availability>" . htmlspecialchars($data['availability'],ENT_XML1) . "</g:availability>\n";

            if ($data['color'])    echo "  <g:color>"    . htmlspecialchars($data['color'],    ENT_XML1) . "</g:color>\n";
            if ($data['size'])     echo "  <g:size>"     . htmlspecialchars($data['size'],     ENT_XML1) . "</g:size>\n";
            if ($data['material']) echo "  <g:material>" . htmlspecialchars($data['material'], ENT_XML1) . "</g:material>\n";
            if ($data['gender'])   echo "  <g:gender>"   . htmlspecialchars($data['gender'],   ENT_XML1) . "</g:gender>\n";
            if ($data['age_group'])echo "  <g:age_group>". htmlspecialchars($data['age_group'],ENT_XML1) . "</g:age_group>\n";
            if ($data['pattern'])  echo "  <g:pattern>"  . htmlspecialchars($data['pattern'],  ENT_XML1) . "</g:pattern>\n";

            if (!empty($imgs)) {
                $imgUrl = UPLOAD_URL . '/products/' . $imgs[0];
                echo "  <g:image_link>" . htmlspecialchars($imgUrl, ENT_XML1) . "</g:image_link>\n";
                foreach (array_slice($imgs, 1, 9) as $extra) {
                    echo "  <g:additional_image_link>" . htmlspecialchars(UPLOAD_URL . '/products/' . $extra, ENT_XML1) . "</g:additional_image_link>\n";
                }
            }

            if ($data['shipping_weight']) {
                echo "  <g:shipping_weight>" . htmlspecialchars($data['shipping_weight'], ENT_XML1) . "</g:shipping_weight>\n";
            }

            echo "</item>\n";
        }

        echo "</channel>\n</rss>";
        exit;
    }
}