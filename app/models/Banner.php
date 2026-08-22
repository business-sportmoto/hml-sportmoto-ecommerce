<?php
declare(strict_types=1);

class Banner extends Model {

    protected string $table = 'banners';

    /**
     * Retorna banners ativos de uma zona pela chave.
     * Filtra por data_inicio / data_fim automaticamente.
     *
     * Uso:
     *   $banners = (new Banner())->getBySlot('home_hero');
     *   foreach ($banners as $b) {
     *       View::partial('partials/banner-render', ['b' => $b]);
     *   }
     */
    public function getBySlot($chave) {
        $stmt = $this->db->prepare(
            "SELECT b.*, z.formato, z.chave, z.altura_ideal, z.largura_ideal, z.nome AS zona_nome
             FROM banners b
             JOIN banner_zonas z ON z.id = b.zona_id
             WHERE z.chave = ?
               AND b.ativo = 1
               AND z.ativo = 1
               AND (b.data_inicio IS NULL OR b.data_inicio <= NOW())
               AND (b.data_fim    IS NULL OR b.data_fim    >= NOW())
             ORDER BY b.ordem ASC, b.id DESC"
        );
        $stmt->execute([$chave]);
        return $stmt->fetchAll();
    }

    /**
     * Busca banners ativos de uma zona, considerando agendamento.
     */
    public function getByZona(string $zonaChave): array {
        $stmt = $this->db->prepare(
            "SELECT b.*, z.formato, z.max_banners,
                    z.largura_ideal, z.altura_ideal
             FROM banners b
             JOIN banner_zonas z ON z.id = b.zona_id
             WHERE z.chave = ?
               AND z.ativo = 1
               AND b.ativo = 1
               AND (b.data_inicio IS NULL OR b.data_inicio <= NOW())
               AND (b.data_fim    IS NULL OR b.data_fim    >= NOW())
             ORDER BY b.ordem ASC, b.id ASC
             LIMIT 20"
        );
        $stmt->execute([$zonaChave]);
        return $stmt->fetchAll();
    }

    /**
     * Banners de VÁRIAS zonas numa query só, agrupados por chave de zona.
     *
     * A home do app precisa de 5 zonas de uma vez; chamar getByZona() em laço
     * custaria 5 idas ao banco para trazer, no total, uma dúzia de linhas.
     * Mantém a mesma ordenação e o mesmo filtro de agendamento.
     *
     * @param  string[] $chaves
     * @return array<string, array<int,array>> ['home_hero' => [...], ...]
     */
    public function getByZonas(array $chaves): array {
        $chaves = array_values(array_unique(array_filter(array_map('strval', $chaves))));
        if (!$chaves) return [];

        $in   = implode(',', array_fill(0, count($chaves), '?'));
        $stmt = $this->db->prepare(
            "SELECT b.*, z.chave AS zona_chave, z.formato, z.max_banners,
                    z.largura_ideal, z.altura_ideal
             FROM banners b
             JOIN banner_zonas z ON z.id = b.zona_id
             WHERE z.chave IN ({$in})
               AND z.ativo = 1
               AND b.ativo = 1
               AND (b.data_inicio IS NULL OR b.data_inicio <= NOW())
               AND (b.data_fim    IS NULL OR b.data_fim    >= NOW())
             ORDER BY b.ordem ASC, b.id ASC"
        );
        $stmt->execute($chaves);

        $porZona = array_fill_keys($chaves, []);
        foreach ($stmt->fetchAll() as $row) {
            $porZona[$row['zona_chave']][] = $row;
        }

        return array_filter($porZona);
    }

    public function getZona(string $chave): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM banner_zonas WHERE chave = ? LIMIT 1"
        );
        $stmt->execute([$chave]);
        return $stmt->fetch() ?: null;
    }

    public function registrarImpressao(int $bannerId): void {
        $this->db->prepare(
            "UPDATE banners SET impressoes = impressoes + 1 WHERE id = ?"
        )->execute([$bannerId]);
    }

    public function registrarClique(int $bannerId, ?string $ip = null, ?string $ua = null, ?string $ref = null): void {
        $this->db->prepare(
            "UPDATE banners SET cliques = cliques + 1 WHERE id = ?"
        )->execute([$bannerId]);

        $this->db->prepare(
            "INSERT INTO banner_cliques (banner_id, ip, user_agent, referrer)
             VALUES (?, ?, ?, ?)"
        )->execute([$bannerId, $ip, $ua, $ref]);
    }

    public function getByPrefix(string $prefix): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM banners
             WHERE id_prefix = ?
               AND ativo = 1
               AND (valido_de  IS NULL OR valido_de  <= NOW())
               AND (valido_ate IS NULL OR valido_ate >= NOW())
             ORDER BY ordem ASC"
        );
        $stmt->execute([$prefix]);
        return $stmt->fetchAll();
    }

    public function bannerIsDark(string $hex): bool {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
        return (($r*299 + $g*587 + $b*114) / 1000) < 128;
    }

    // Ícone do botão CTA por estilo
    function bannerCtaIcone(string $estilo): string {
        return match ($estilo) {
            'outline' => '<path d="M5 12h14M12 5l7 7-7 7"/>',
            'ghost'   => '<path d="M9 18l6-6-6-6"/>',
            default   => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        };
    }
}