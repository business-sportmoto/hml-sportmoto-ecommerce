<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════════════════
// app/services/FamiliaService.php
//
// Família de produtos: o grupo de produtos que são o MESMO produto em
// versões diferentes (cor, estampa), cada um com sua URL.
//
// Na loja, a página do produto mostra os outros membros da família como
// opções — é o seletor de cor que atravessa produtos distintos
// (ProductVariation::getProductData → `produtos_familia`).
//
// ── POR QUE UM SERVICE ─────────────────────────────────────────────
//
// Até 12/09/2026 a família só existia dentro do formulário de produto: o
// FamiliasController tinha criar/renomear/excluir/vincular chamados por Ajax
// e NENHUMA listagem. Não havia como ver quantas famílias existem, quem está
// em cada uma, nem quais estão sozinhas (família de um membro só não faz
// nada na loja — é trabalho perdido).
//
// A regra de SUGESTÃO mora aqui pelo mesmo motivo: decidir "esta família
// serve para este produto" é regra de negócio, não enfeite de tela.
// ════════════════════════════════════════════════════════════════════

final class FamiliaService
{
    /** Família com menos que isto não agrupa nada na loja. */
    public const MINIMO_UTIL = 2;

    /**
     * Palavras que não distinguem um produto do outro.
     *
     * Sem esta lista, "Capacete" casaria todo capacete com toda família de
     * capacete, e a sugestão viraria ruído. Cor e tamanho saem porque são
     * justamente o que MUDA entre membros da mesma família.
     */
    private const IGNORAR = [
        'de','da','do','das','dos','com','sem','para','por','em','e','ou','a','o','as','os','um','uma',
        'kit','par','original','novo','nova','linha','modelo','produto','tamanho',
        'preto','preta','branco','branca','vermelho','vermelha','azul','verde','amarelo','amarela',
        'cinza','prata','dourado','rosa','roxo','laranja','marrom','bege','fosco','fosca','brilhante',
        'pp','p','m','g','gg','xg','xgg',
    ];

    /**
     * Qualificadores de linha: aparecem em modelo de meia loja.
     *
     * Não entram no IGNORAR porque fazem parte do nome da família ("FF358
     * Pro Monocolor") — só não PROVAM parentesco sozinhos. Sem esta lista,
     * "Capacete Alfatron Pro" casava com "FF358 Pro Monocolor" pelo "pro".
     */
    private const QUALIFICADORES = [
        'pro', 'plus', 'max', 'evo', 'premium', 'sport', 'light', 'tech', 'top', 'mini',
    ];

    private PDO $db;

    /** Palavras que são nome de categoria ou de marca — carregadas uma vez. */
    private ?array $fracas = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // LISTAGEM
    // ════════════════════════════════════════════════════

    /**
     * @param array{busca?:string, situacao?:string} $filtros
     *        situacao: 'sozinhas' (1 membro), 'vazias' (0), 'inativas'
     * @return array{itens:array, total:int}
     */
    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 20): array
    {
        $where  = ['1=1'];
        $params = [];

        $busca = trim((string) ($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $where[]  = '(f.nome LIKE ? OR f.slug LIKE ?)';
            $params[] = '%' . $busca . '%';
            $params[] = '%' . $busca . '%';
        }
        if (($filtros['situacao'] ?? '') === 'inativas') {
            $where[] = 'f.ativo = 0';
        }

        $tendo = match ($filtros['situacao'] ?? '') {
            'vazias'   => 'HAVING total_membros = 0',
            'sozinhas' => 'HAVING total_membros = 1',
            default    => '',
        };

        $sqlBase = "FROM familia_produtos f
               LEFT JOIN produtos p ON p.familia_id = f.id AND p.deleted_at IS NULL
                   WHERE " . implode(' AND ', $where) . "
                GROUP BY f.id {$tendo}";

        // Total: o HAVING filtra por agregado, então conta o resultado do grupo.
        $stTotal = $this->db->prepare("SELECT COUNT(*) FROM (SELECT f.id, COUNT(p.id) total_membros {$sqlBase}) x");
        $stTotal->execute($params);
        $total = (int) $stTotal->fetchColumn();

        $offset = max(0, ($pagina - 1) * $porPagina);
        $st = $this->db->prepare(
            "SELECT f.id, f.nome, f.slug, f.descricao, f.ativo, f.criado_em,
                    COUNT(p.id) AS total_membros,
                    SUM(p.ativo = 1) AS membros_ativos,
                    GROUP_CONCAT(p.nome ORDER BY p.nome SEPARATOR ' · ') AS membros
             {$sqlBase}
             ORDER BY total_membros DESC, f.nome ASC
             LIMIT {$porPagina} OFFSET {$offset}"
        );
        $st->execute($params);

        $itens = array_map(static function (array $f): array {
            $f['total_membros']  = (int) $f['total_membros'];
            $f['membros_ativos'] = (int) $f['membros_ativos'];
            $f['membros']        = $f['membros'] ? explode(' · ', (string) $f['membros']) : [];
            return $f;
        }, $st->fetchAll(PDO::FETCH_ASSOC));

        return ['itens' => $itens, 'total' => $total];
    }

    /** Números do topo da tela — o que precisa de atenção. */
    public function resumo(): array
    {
        $sql = "SELECT
                  COUNT(*) AS familias,
                  SUM(n = 0) AS vazias,
                  SUM(n = 1) AS sozinhas,
                  SUM(ativo = 0) AS inativas,
                  SUM(n) AS produtos_vinculados
                FROM (
                  SELECT f.id, f.ativo, COUNT(p.id) AS n
                    FROM familia_produtos f
                    LEFT JOIN produtos p ON p.familia_id = f.id AND p.deleted_at IS NULL
                   GROUP BY f.id, f.ativo
                ) x";
        $r = $this->db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'familias'            => (int) ($r['familias'] ?? 0),
            'vazias'              => (int) ($r['vazias'] ?? 0),
            'sozinhas'            => (int) ($r['sozinhas'] ?? 0),
            'inativas'            => (int) ($r['inativas'] ?? 0),
            'produtos_vinculados' => (int) ($r['produtos_vinculados'] ?? 0),
            'produtos_sem_familia'=> (int) $this->db->query(
                "SELECT COUNT(*) FROM produtos WHERE familia_id IS NULL AND deleted_at IS NULL AND ativo = 1"
            )->fetchColumn(),
        ];
    }

    // ════════════════════════════════════════════════════
    // DETALHE
    // ════════════════════════════════════════════════════

    public function detalhe(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM familia_produtos WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $familia = $st->fetch(PDO::FETCH_ASSOC);
        if (!$familia) return null;

        $st = $this->db->prepare(
            "SELECT p.id, p.nome, p.slug, p.sku_legado, p.preco, p.ativo, p.estoque_total,
                    m.nome AS marca,
                    (SELECT pi.arquivo FROM produto_imagens pi
                      WHERE pi.produto_id = p.id
                   ORDER BY pi.principal DESC, pi.ordem ASC, pi.id ASC LIMIT 1) AS imagem
               FROM produtos p
          LEFT JOIN marcas m ON m.id = p.marca_id
              WHERE p.familia_id = ? AND p.deleted_at IS NULL
           ORDER BY p.ativo DESC, p.nome ASC"
        );
        $st->execute([$id]);
        $familia['membros'] = $st->fetchAll(PDO::FETCH_ASSOC);

        // Atributos que agrupam a família (cor, estampa…), se configurados.
        $st = $this->db->prepare(
            "SELECT at.id, at.nome, fa.obrigatorio
               FROM familia_atributos_agrupadores fa
               JOIN atributo_tipos at ON at.id = fa.atributo_tipo_id
              WHERE fa.familia_id = ? ORDER BY fa.ordenacao"
        );
        $st->execute([$id]);
        $familia['agrupadores'] = $st->fetchAll(PDO::FETCH_ASSOC);

        return $familia;
    }

    // ════════════════════════════════════════════════════
    // GRAVAÇÃO
    // ════════════════════════════════════════════════════

    /**
     * @return array{ok:bool, msg?:string}
     */
    public function salvar(int $id, array $dados): array
    {
        $nome = trim((string) ($dados['nome'] ?? ''));
        if ($nome === '') return ['ok' => false, 'msg' => 'O nome da família é obrigatório.'];

        $st = $this->db->prepare("SELECT id FROM familia_produtos WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        if (!$st->fetchColumn()) return ['ok' => false, 'msg' => 'Família não encontrada.'];

        // Nome repetido confunde na hora de vincular: duas "Capacete XYZ" na
        // busca do produto são indistinguíveis.
        $st = $this->db->prepare("SELECT nome FROM familia_produtos WHERE nome = ? AND id <> ? LIMIT 1");
        $st->execute([$nome, $id]);
        if ($st->fetchColumn()) {
            return ['ok' => false, 'msg' => 'Já existe outra família com este nome.'];
        }

        // O SLUG NÃO É REGERADO no rename — mesma regra da URL de produto
        // (ver 12-decisoes-tecnicas/catalogo-url-canonica): renomear é ajuste
        // de texto, e trocar o slug quebraria quem já aponta para ele.
        $this->db->prepare(
            "UPDATE familia_produtos
                SET nome = ?, descricao = ?, ativo = ?, atualizado_em = NOW()
              WHERE id = ?"
        )->execute([
            $nome,
            trim((string) ($dados['descricao'] ?? '')) ?: null,
            !empty($dados['ativo']) ? 1 : 0,
            $id,
        ]);

        return ['ok' => true, 'msg' => 'Família salva.'];
    }

    /**
     * Exclui a família e desvincula os produtos.
     *
     * Produto NUNCA é apagado junto: a família é um agrupamento, não o dono
     * do produto. Quem sobra fica sem família, e a loja simplesmente deixa
     * de mostrar o seletor de versões.
     */
    public function excluir(int $id): array
    {
        $familia = $this->detalhe($id);
        if (!$familia) return ['ok' => false, 'msg' => 'Família não encontrada.'];

        $n = count($familia['membros']);
        try {
            $this->db->beginTransaction();
            $this->db->prepare("UPDATE produtos SET familia_id = NULL WHERE familia_id = ?")->execute([$id]);
            $this->db->prepare("DELETE FROM familia_atributos_agrupadores WHERE familia_id = ?")->execute([$id]);
            $this->db->prepare("DELETE FROM familia_produtos WHERE id = ?")->execute([$id]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            LogService::exception($e, 'error', 'app', ['acao' => 'excluir_familia', 'familia_id' => $id]);
            return ['ok' => false, 'msg' => 'Não foi possível excluir: ' . $e->getMessage()];
        }

        LogService::audit('Família de produtos excluída', [
            'familia_id' => $id, 'nome' => $familia['nome'],
            'produtos_desvinculados' => $n, 'por' => AuthHelper::usuarioId(),
        ]);

        return ['ok' => true, 'msg' => $n > 0
            ? "Família excluída. {$n} produto(s) ficaram sem família."
            : 'Família excluída.'];
    }

    // ════════════════════════════════════════════════════
    // SUGESTÃO
    // ════════════════════════════════════════════════════

    /**
     * Que famílias combinam com este produto.
     *
     * ── COMO DECIDE ──────────────────────────────────────────────────
     *
     * Compara as palavras do nome do produto com o nome da família E com o
     * nome de quem já está nela. "Capacete LS2 FF358 Pro Monocolor Preto"
     * casa com a família "FF358 Pro Monocolor" por três palavras — e casaria
     * de novo com o irmão "…Monocolor Branco", porque cor é palavra ignorada
     * (é justamente o que muda entre membros).
     *
     * Marca igual pesa, categoria igual pesa menos. Não é busca textual do
     * banco: é comparação de palavras, para "FF358" casar mesmo no meio de
     * nomes diferentes.
     *
     * @param array{nome?:string, marca_id?:int, categoria_id?:int, produto_id?:int} $ctx
     * @return array<int, array{id:int,nome:string,total_membros:int,score:float,motivo:string,exemplos:array}>
     */
    public function sugerir(array $ctx, int $limite = 4): array
    {
        $palavras = $this->palavras((string) ($ctx['nome'] ?? ''));
        $marcaId  = (int) ($ctx['marca_id'] ?? 0);
        $catId    = (int) ($ctx['categoria_id'] ?? 0);
        $produtoId= (int) ($ctx['produto_id'] ?? 0);

        // Sem palavra útil e sem marca não há o que sugerir — devolver as
        // famílias "mais cheias" seria chute com cara de recomendação.
        if (!$palavras && !$marcaId) return [];

        $st = $this->db->query(
            "SELECT f.id, f.nome,
                    COUNT(p.id) AS total_membros,
                    GROUP_CONCAT(DISTINCT p.nome ORDER BY p.nome SEPARATOR '||') AS nomes,
                    GROUP_CONCAT(DISTINCT p.marca_id)     AS marcas,
                    GROUP_CONCAT(DISTINCT p.categoria_id) AS categorias
               FROM familia_produtos f
          LEFT JOIN produtos p ON p.familia_id = f.id AND p.deleted_at IS NULL
              WHERE f.ativo = 1
           GROUP BY f.id"
        );

        $saida = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $nomes    = $f['nomes'] ? explode('||', (string) $f['nomes']) : [];
            $marcas   = array_filter(array_map('intval', explode(',', (string) $f['marcas'])));
            $cats     = array_filter(array_map('intval', explode(',', (string) $f['categorias'])));

            $doNome    = $this->palavras((string) $f['nome']);
            $dosMembros= [];
            foreach ($nomes as $n) $dosMembros = array_merge($dosMembros, $this->palavras($n));
            $dosMembros = array_unique($dosMembros);

            $mesmaMarca = $marcaId > 0 && in_array($marcaId, $marcas, true);
            $mesmaCat   = $catId   > 0 && in_array($catId,   $cats,   true);

            // CATEGORIA DIFERENTE ELIMINA. Uma jaqueta não entra em família
            // de capacete por acaso de nome — e foi exatamente o que a
            // primeira versão sugeriu.
            if ($catId > 0 && $cats && !$mesmaCat) continue;

            $casaNome    = array_values(array_intersect($palavras, $doNome));
            $casaMembros = array_values(array_diff(array_intersect($palavras, $dosMembros), $casaNome));

            // Palavra que é nome de categoria ("capacete") ou de marca
            // ("ls2") não identifica NADA: todo capacete casaria com toda
            // família de capacete. Ela quase não pontua; quem pontua é a
            // palavra que distingue o modelo ("ff358", "monocolor").
            $forteNome    = array_values(array_diff($casaNome,    $this->palavrasFracas()));
            $forteMembros = array_values(array_diff($casaMembros, $this->palavrasFracas()));

            // Peso: o nome da família vale mais que o nome de um membro —
            // ele é a intenção declarada de quem criou o grupo.
            $score = count($forteNome) * 3
                   + (count($casaNome) - count($forteNome)) * 0.5
                   + min(count($forteMembros), 4) * 1.0;

            // PARENTESCO PRIMEIRO: ou o nome da família bate, ou dois
            // produtos dela batem. Uma palavra só, vinda de um membro, é
            // coincidência — era o que fazia um "FF358 Monocolor" ser
            // oferecido a uma família de Norisk por causa de "monocolor".
            if (!$forteNome && count($forteMembros) < 2) continue;

            // Marca e categoria DESEMPATAM, não carregam a sugestão: só
            // somam depois que o nome já provou parentesco.
            if ($score > 0) {
                if ($mesmaMarca) $score += 2;
                if ($mesmaCat)   $score += 1;
            }

            // Abaixo disto é coincidência: uma sugestão errada custa mais
            // que nenhuma, porque ensina a ignorar o bloco inteiro.
            if ($score < 3.5) continue;

            $motivo = [];
            if ($forteNome)    $motivo[] = 'o nome bate em ' . implode(', ', array_slice($forteNome, 0, 3));
            if ($forteMembros) $motivo[] = 'produtos dela têm ' . implode(', ', array_slice($forteMembros, 0, 2));
            if ($mesmaMarca)  $motivo[] = 'mesma marca';
            if ($mesmaCat && !$mesmaMarca) $motivo[] = 'mesma categoria';

            $saida[] = [
                'id'            => (int) $f['id'],
                'nome'          => (string) $f['nome'],
                'total_membros' => (int) $f['total_membros'],
                'score'         => round($score, 1),
                'motivo'        => ucfirst(implode(' · ', $motivo)),
                'exemplos'      => array_slice(array_map(
                    static fn(string $n) => mb_strimwidth($n, 0, 60, '…'), $nomes
                ), 0, 2),
            ];
        }

        usort($saida, static fn(array $a, array $b) => $b['score'] <=> $a['score'] ?: strcmp($a['nome'], $b['nome']));

        // O próprio produto já está numa família? Ela não é sugestão.
        if ($produtoId > 0) {
            $st = $this->db->prepare("SELECT familia_id FROM produtos WHERE id = ? LIMIT 1");
            $st->execute([$produtoId]);
            $atual = (int) ($st->fetchColumn() ?: 0);
            if ($atual > 0) {
                $saida = array_values(array_filter($saida, static fn(array $s) => $s['id'] !== $atual));
            }
        }

        return array_slice($saida, 0, max(1, $limite));
    }

    /**
     * Palavras que NÃO distinguem: nome de categoria, de marca e os
     * qualificadores de linha.
     *
     * As de categoria e marca vêm do banco, não de uma lista no código —
     * categoria e marca novas entram sozinhas. São duas tabelas pequenas,
     * lidas uma vez por chamada.
     *
     * @return string[]
     */
    private function palavrasFracas(): array
    {
        if ($this->fracas !== null) return $this->fracas;

        $this->fracas = array_fill_keys(self::QUALIFICADORES, true);
        try {
            $sql = "SELECT nome FROM categorias WHERE ativo = 1
                    UNION ALL
                    SELECT nome FROM marcas WHERE ativo = 1";
            foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $nome) {
                foreach ($this->palavras((string) $nome) as $p) $this->fracas[$p] = true;
            }
            $this->fracas = array_keys($this->fracas);
        } catch (Throwable $e) {
            // Banco fora não pode tirar os qualificadores: sem eles a
            // sugestão volta a casar por "pro" e "max".
            LogService::exception($e, 'warning', 'app', ['acao' => 'palavras_fracas_familia']);
            $this->fracas = self::QUALIFICADORES;
        }
        return $this->fracas;
    }

    /**
     * Palavras que identificam o produto: sem acento, minúsculas, sem as
     * genéricas e sem cor/tamanho. Número de modelo ("ff358") conta.
     *
     * @return string[]
     */
    private function palavras(string $texto): array
    {
        $t = mb_strtolower(trim($texto));
        $t = strtr($t, [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','ç'=>'c',
        ]);
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? '';

        $out = [];
        foreach (preg_split('/\s+/', trim($t)) ?: [] as $p) {
            if ($p === '' || in_array($p, self::IGNORAR, true)) continue;
            // Palavra de 1 caractere não identifica nada; número de modelo sim.
            if (mb_strlen($p) < 2) continue;
            $out[$p] = true;
        }
        return array_keys($out);
    }
}
