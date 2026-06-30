<?php
// app/helpers/SlugHelper.php
// Converte texto qualquer em slug para URLs amigáveis.

class SlugHelper {

    /**
     * Converte texto para slug.
     * Ex: "Tênis Nike Air Max!" → "tenis-nike-air-max"
     */
    public static function make(string $text): string {
        // Converte para ASCII (remove acentos)
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s\-]/', '', $text);
        $text = preg_replace('/[\s\-]+/', '-', trim($text));
        return trim($text, '-');
    }

    /**
     * Gera slug único para uma tabela, adicionando sufixo numérico se necessário.
     * Ex: "tenis-nike" já existe → gera "tenis-nike-2"
     */
    public static function unique(string $text, string $table, string $column = 'slug', int $ignoreId = 0): string {
        $db   = Database::getInstance()->getConnection();
        $base = self::make($text);
        $slug = $base;
        $i    = 1;

        do {
            $sql  = "SELECT id FROM {$table} WHERE {$column} = ?";
            $params = [$slug];

            if ($ignoreId > 0) {
                $sql    .= ' AND id != ?';
                $params[] = $ignoreId;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $exists = $stmt->fetchColumn();

            if ($exists) {
                $slug = $base . '-' . (++$i);
            }
        } while ($exists);

        return $slug;
    }
}