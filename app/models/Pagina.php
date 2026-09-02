<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/Pagina.php
//
// Persistência das páginas de conteúdo (termos, privacidade, trocas…).
//
// Só as páginas de BANCO moram aqui. As landing pages desenhadas continuam em
// /pages/{slug}/index.php, com CSS e JS próprios — juntar as duas coisas numa
// tabela jogaria fora o trabalho que existe nelas. Quem une as duas fontes numa
// lista só, para menu e rodapé, é o PaginaService::todas().
// ════════════════════════════════════════════════════════

class Pagina
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /* =================================================================
       LEITURA
       ================================================================= */

    /** Uma página pelo slug. $somenteAtivas separa a loja do painel. */
    public function porSlug(string $slug, bool $somenteAtivas = true): ?array
    {
        $sql = "SELECT * FROM paginas WHERE slug = ?" . ($somenteAtivas ? " AND ativo = 1" : "") . " LIMIT 1";
        $st  = $this->db->prepare($sql);
        $st->execute([$slug]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function porId(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM paginas WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Listagem do painel, com busca e filtro de status.
     *
     * Traz o tamanho do conteúdo em vez do conteúdo: a lista mostra 50 páginas
     * e nenhuma delas precisa do corpo inteiro trafegando junto.
     */
    public function listar(array $filtros = []): array
    {
        $where = [];
        $par   = [];

        $busca = trim((string) ($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $where[] = "(titulo LIKE :b OR slug LIKE :b)";
            $par[':b'] = '%' . $busca . '%';
        }

        $status = (string) ($filtros['status'] ?? '');
        if ($status === 'ativas')    $where[] = "ativo = 1";
        if ($status === 'rascunho')  $where[] = "ativo = 0";

        $sql = "SELECT id, slug, titulo, menu_label, ativo, ordem_menu, no_menu, no_rodape,
                       noindex, criado_em, atualizado_em, publicado_em,
                       CHAR_LENGTH(COALESCE(conteudo, '')) AS tamanho
                  FROM paginas"
             . ($where ? " WHERE " . implode(' AND ', $where) : "")
             . " ORDER BY ativo DESC, COALESCE(ordem_menu, 999) ASC, titulo ASC";

        $st = $this->db->prepare($sql);
        $st->execute($par);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Páginas publicadas, no formato que o menu e o rodapé consomem. */
    public function publicadas(): array
    {
        $st = $this->db->query(
            "SELECT slug, titulo, menu_label, meta_description, ordem_menu,
                    no_menu, no_rodape, noindex, atualizado_em, publicado_em
               FROM paginas
              WHERE ativo = 1
           ORDER BY COALESCE(ordem_menu, 999) ASC, titulo ASC"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** O slug já é de outra página? Usado antes de gravar. */
    public function slugEmUso(string $slug, int $ignorarId = 0): bool
    {
        $st = $this->db->prepare("SELECT id FROM paginas WHERE slug = ? AND id <> ? LIMIT 1");
        $st->execute([$slug, $ignorarId]);
        return (bool) $st->fetchColumn();
    }

    /* =================================================================
       ESCRITA
       ================================================================= */

    public function criar(array $d): int
    {
        $st = $this->db->prepare(
            "INSERT INTO paginas
                (slug, titulo, menu_label, conteudo, meta_title, meta_description,
                 ativo, ordem_menu, no_menu, no_rodape, noindex,
                 criado_em, atualizado_em, publicado_em)
             VALUES
                (:slug, :titulo, :menu_label, :conteudo, :meta_title, :meta_description,
                 :ativo, :ordem_menu, :no_menu, :no_rodape, :noindex,
                 NOW(), NOW(), :publicado_em)"
        );
        $st->execute($this->parametros($d));
        return (int) $this->db->lastInsertId();
    }

    public function atualizar(int $id, array $d): void
    {
        $st = $this->db->prepare(
            "UPDATE paginas SET
                slug = :slug, titulo = :titulo, menu_label = :menu_label,
                conteudo = :conteudo, meta_title = :meta_title,
                meta_description = :meta_description, ativo = :ativo,
                ordem_menu = :ordem_menu, no_menu = :no_menu, no_rodape = :no_rodape,
                noindex = :noindex, atualizado_em = NOW(), publicado_em = :publicado_em
              WHERE id = :id"
        );
        $st->execute($this->parametros($d) + [':id' => $id]);
    }

    public function excluir(int $id): void
    {
        $this->db->prepare("DELETE FROM paginas WHERE id = ?")->execute([$id]);
    }

    /** Liga/desliga a publicação e devolve o estado novo. */
    public function alternarAtivo(int $id): ?int
    {
        $p = $this->porId($id);
        if (!$p) return null;

        $novo = ((int) $p['ativo']) === 1 ? 0 : 1;

        // A data de publicação é gravada na PRIMEIRA vez que a página vai ao ar
        // e não se mexe depois: republicar não é publicar de novo, e o sitemap
        // usa essa data.
        $st = $this->db->prepare(
            "UPDATE paginas
                SET ativo = :a, atualizado_em = NOW(),
                    publicado_em = CASE WHEN :a2 = 1 AND publicado_em IS NULL THEN NOW() ELSE publicado_em END
              WHERE id = :id"
        );
        $st->execute([':a' => $novo, ':a2' => $novo, ':id' => $id]);
        return $novo;
    }

    private function parametros(array $d): array
    {
        return [
            ':slug'             => $d['slug'],
            ':titulo'           => $d['titulo'],
            ':menu_label'       => $d['menu_label'] !== '' ? $d['menu_label'] : null,
            ':conteudo'         => $d['conteudo'],
            ':meta_title'       => $d['meta_title'] !== '' ? $d['meta_title'] : null,
            ':meta_description' => $d['meta_description'] !== '' ? $d['meta_description'] : null,
            ':ativo'            => (int) $d['ativo'],
            ':ordem_menu'       => $d['ordem_menu'] !== null ? (int) $d['ordem_menu'] : null,
            ':no_menu'          => (int) $d['no_menu'],
            ':no_rodape'        => (int) $d['no_rodape'],
            ':noindex'          => (int) $d['noindex'],
            ':publicado_em'     => $d['publicado_em'],
        ];
    }
}
