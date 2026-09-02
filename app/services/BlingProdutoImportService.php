<?php
declare(strict_types=1);

/**
 * app/services/BlingProdutoImportService.php
 *
 * Traz produtos do Bling para o site, SOB DEMANDA e um a um.
 *
 * ── Por que não é automático ───────────────────────────────────────
 * O Bling é o cadastro fiscal: muitos produtos nascem lá direto da NF-e,
 * com custo, código e EAN, mas sem nada de vitrine (URL, SEO, categoria,
 * compatibilidade de moto). Importar tudo sozinho encheria o catálogo de
 * produto que não se vende online e geraria URL sem ninguém revisar —
 * exatamente o problema que o travamento de slug resolveu.
 *
 * Então: o admin vê a lista do Bling, escolhe, importa. O produto nasce
 * RASCUNHO (ativo = 0) e alguém completa antes de publicar.
 *
 * ── O que NÃO fazemos ──────────────────────────────────────────────
 *  - Imagens: o Bling tem só uma por produto, e em URL S3 ASSINADA que
 *    expira (~8 dias). Guardar o link daria imagem quebrada na semana
 *    seguinte. As fotos entram manualmente pelo painel.
 *  - Atualização automática: editar no Bling não mexe no site. Quem
 *    decide é o botão "sincronizar" dentro do produto, com diff.
 *
 * ── Onde o Bling guarda o custo ────────────────────────────────────
 * Não existe precoCusto no nível do produto-pai. Ele vive em:
 *    produto simples  -> fornecedor.precoCusto
 *    com variação     -> variacoes[].precoCusto (ou o do fornecedor dela)
 * Por isso, em produto com variação, o custo vai para produto_skus.custo
 * e produtos.preco_custo fica NULL — é a variação que tem custo próprio.
 */
final class BlingProdutoImportService
{
    private const POR_PAGINA = 100;

    private BlingApiClient $api;
    private PDO $db;

    public function __construct()
    {
        $this->api = new BlingApiClient();
        $this->db  = Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // LISTAGEM
    // ════════════════════════════════════════════════════

    /**
     * Lista uma página do catálogo do Bling, marcando o que já existe
     * no site. Consulta ao vivo — sem cópia local para não desatualizar.
     *
     * SÓ PAIS E SIMPLES: a listagem do Bling devolve cada variação como
     * linha própria. Importar o pai já traz as variações junto, então
     * mostrar as filhas seria ruído e convite a importar pela metade.
     * A distinção vem de idProdutoPai (0/vazio/igual ao id = não é filha).
     *
     * @param string $campo 'nome' | 'codigo' | 'ean'
     * @return array{ok:bool, itens:array, pagina:int, tem_proxima:bool,
     *               msg?:string, aviso?:string}
     */
    public function listar(int $pagina = 1, string $termo = '', string $campo = 'nome'): array
    {
        $pagina = max(1, $pagina);
        $termo  = trim($termo);

        if ($campo === 'ean' && $termo !== '') {
            return $this->buscarPorEan($termo);
        }

        $params = ['pagina' => $pagina, 'limite' => self::POR_PAGINA];

        // A API filtra por 'nome' e 'codigo' de verdade. NÃO filtra por
        // 'pesquisa' nem por 'gtin' — os dois são aceitos e ignorados,
        // devolvendo o catálogo inteiro como se fosse resultado. Nunca
        // mande um desses achando que filtra.
        if ($termo !== '') {
            $params[$campo === 'codigo' ? 'codigo' : 'nome'] = $termo;
        }

        try {
            $lista = $this->api->get('/produtos', $params);
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'bling', ['params' => $params]);
            return ['ok' => false, 'itens' => [], 'pagina' => $pagina,
                    'tem_proxima' => false, 'msg' => 'Falha ao consultar o Bling: ' . $e->getMessage()];
        }

        if (!is_array($lista)) $lista = [];
        $temProxima = count($lista) >= self::POR_PAGINA;

        $pais = [];
        foreach ($lista as $p) {
            $id    = (string)($p['id'] ?? '');
            $idPai = (string)($p['idProdutoPai'] ?? '0');
            if ($id === '') continue;
            $ehVariacao = ($idPai !== '0' && $idPai !== '' && $idPai !== $id);
            if ($ehVariacao) continue;
            $pais[] = $p;
        }

        return [
            'ok'          => true,
            'itens'       => $this->marcarExistentes($pais),
            'pagina'      => $pagina,
            'tem_proxima' => $temProxima,
        ];
    }

    /**
     * Busca por EAN — só encontra o que JÁ está no site.
     *
     * A listagem do Bling não devolve o campo gtin nem aceita filtrar por
     * ele (testado: gtin inexistente devolve o catálogo inteiro). A única
     * âncora de EAN disponível é produto_skus.ean, daqui de dentro. Logo,
     * produto que ainda não foi importado é invisível para esta busca.
     * Resolvemos o EAN para o CÓDIGO e perguntamos ao Bling por código.
     */
    private function buscarPorEan(string $ean): array
    {
        $ean = preg_replace('/\D+/', '', $ean) ?? '';
        if ($ean === '') {
            return ['ok' => true, 'itens' => [], 'pagina' => 1, 'tem_proxima' => false];
        }

        $stmt = $this->db->prepare(
            "SELECT ps.sku, p.sku_legado
             FROM produto_skus ps
             JOIN produtos p ON p.id = ps.produto_id
             WHERE ps.ean = ? AND p.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$ean]);
        $achado = $stmt->fetch();

        if (!$achado) {
            return ['ok' => true, 'itens' => [], 'pagina' => 1, 'tem_proxima' => false,
                    'aviso' => 'Nenhum produto no site com este EAN. A API do Bling não permite '
                             . 'buscar por EAN, então produtos ainda não importados não aparecem aqui — '
                             . 'procure pelo código ou pelo nome.'];
        }

        $codigo = (string)($achado['sku_legado'] ?: $achado['sku']);
        $r = $this->listar(1, $codigo, 'codigo');
        $r['aviso'] = "EAN {$ean} resolvido para o código \"{$codigo}\".";
        return $r;
    }

    /**
     * Cruza os produtos do Bling com o catálogo local.
     * Casa por bling_id (vínculo já feito) e, como reserva, por
     * sku_legado — que é a mesma chave do BlingVinculoService.
     */
    private function marcarExistentes(array $produtosBling): array
    {
        if (!$produtosBling) return [];

        $ids     = array_values(array_filter(array_map(fn($p) => (string)($p['id'] ?? ''), $produtosBling)));
        $codigos = array_values(array_filter(array_map(fn($p) => trim((string)($p['codigo'] ?? '')), $produtosBling)));

        $porBlingId = [];
        $porCodigo  = [];

        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $this->db->prepare(
                "SELECT id, nome, ativo, slug, bling_id FROM produtos
                 WHERE bling_id IN ({$ph}) AND deleted_at IS NULL"
            );
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) $porBlingId[(string)$r['bling_id']] = $r;
        }

        if ($codigos) {
            $ph = implode(',', array_fill(0, count($codigos), '?'));
            $st = $this->db->prepare(
                "SELECT id, nome, ativo, slug, sku_legado FROM produtos
                 WHERE sku_legado IN ({$ph}) AND deleted_at IS NULL"
            );
            $st->execute($codigos);
            foreach ($st->fetchAll() as $r) $porCodigo[trim((string)$r['sku_legado'])] = $r;
        }

        $out = [];
        foreach ($produtosBling as $p) {
            $id     = (string)($p['id'] ?? '');
            $codigo = trim((string)($p['codigo'] ?? ''));
            $local  = $porBlingId[$id] ?? $porCodigo[$codigo] ?? null;

            $out[] = [
                'bling_id'    => $id,
                'codigo'      => $codigo,
                'nome'        => (string)($p['nome'] ?? ''),
                'preco'       => (float)($p['preco'] ?? 0),
                'preco_custo' => (float)($p['precoCusto'] ?? 0),
                'saldo'       => (int)($p['estoque']['saldoVirtualTotal'] ?? 0),
                'situacao'    => (string)($p['situacao'] ?? ''),
                // formato V = pai de família (tem variações), S = simples
                'tem_variacao'=> ((string)($p['formato'] ?? 'S')) === 'V',
                'no_site'     => $local !== null,
                'produto_id'  => $local ? (int)$local['id'] : null,
                'produto_ativo'=> $local ? (int)$local['ativo'] : null,
                'produto_slug'=> $local['slug'] ?? null,
            ];
        }
        return $out;
    }

    // ════════════════════════════════════════════════════
    // IMPORTAÇÃO
    // ════════════════════════════════════════════════════

    /**
     * Importa UM produto do Bling como rascunho.
     *
     * @return array{ok:bool, msg:string, produto_id?:int, skus?:int}
     */
    public function importar(string $blingId): array
    {
        $blingId = trim($blingId);
        if ($blingId === '') return ['ok' => false, 'msg' => 'ID do Bling inválido.'];

        // Já existe? Não duplica — devolve o que existe.
        if ($existente = $this->produtoLocalPorBlingId($blingId)) {
            return ['ok' => false, 'produto_id' => (int)$existente['id'],
                    'produto_ativo' => (int)$existente['ativo'],
                    'msg' => "Este produto já está no site (#{$existente['id']} — {$existente['nome']})."];
        }

        try {
            $b = $this->api->get("/produtos/{$blingId}");
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'bling', ['bling_id' => $blingId]);
            return ['ok' => false, 'msg' => 'Falha ao buscar o produto no Bling: ' . $e->getMessage()];
        }

        $codigo = trim((string)($b['codigo'] ?? ''));
        $nome   = trim((string)($b['nome'] ?? ''));
        if ($nome === '') return ['ok' => false, 'msg' => 'Produto sem nome no Bling.'];

        // Mesmo código já cadastrado = o produto existe, só não estava
        // vinculado. Vincula em vez de criar duplicata.
        if ($codigo !== '' && $porCodigo = $this->produtoLocalPorCodigo($codigo)) {
            $this->db->prepare("UPDATE produtos SET bling_id = ? WHERE id = ?")
                     ->execute([$blingId, (int)$porCodigo['id']]);
            return ['ok' => false, 'produto_id' => (int)$porCodigo['id'],
                    'produto_ativo' => (int)$porCodigo['ativo'],
                    'msg' => "Já existia um produto com o código \"{$codigo}\" (#{$porCodigo['id']}). "
                           . "Vinculei ao Bling em vez de duplicar."];
        }

        $variacoes = is_array($b['variacoes'] ?? null) ? $b['variacoes'] : [];
        $temVar    = count($variacoes) > 0;
        $campos    = $this->mapearCampos($b, $temVar);

        $campos['slug']     = SlugHelper::unique($nome, 'produtos');
        $campos['bling_id'] = $blingId;
        // Rascunho: a URL foi gerada pelo nome e ninguém revisou, não há
        // categoria nem SEO. Publicar sozinho seria o mesmo erro que o
        // travamento de slug veio corrigir.
        $campos['ativo']        = 0;
        $campos['tem_variacao'] = $temVar ? 1 : 0;

        try {
            $this->db->beginTransaction();

            $cols = implode(', ', array_keys($campos));
            $ph   = implode(', ', array_fill(0, count($campos), '?'));
            $this->db->prepare("INSERT INTO produtos ({$cols}) VALUES ({$ph})")
                     ->execute(array_values($campos));
            $produtoId = (int)$this->db->lastInsertId();

            $nSkus = $temVar ? $this->importarVariacoes($produtoId, $variacoes) : 0;

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            LogService::exception($e, 'error', 'bling', ['bling_id' => $blingId]);
            return ['ok' => false, 'msg' => 'Erro ao gravar: ' . $e->getMessage()];
        }

        LogService::audit('Produto importado do Bling', [
            'produto_id' => $produtoId, 'bling_id' => $blingId,
            'codigo' => $codigo, 'skus' => $nSkus,
            'usuario_id' => AuthHelper::usuarioId(),
        ]);

        $msg = "Produto importado como rascunho";
        if ($nSkus) $msg .= " com {$nSkus} variação(ões)";
        $msg .= ". Complete URL, SEO, categoria e fotos antes de publicar.";

        return ['ok' => true, 'produto_id' => $produtoId, 'produto_ativo' => 0,
                'skus' => $nSkus, 'msg' => $msg];
    }

    /**
     * Campos do produto-pai. Não inclui slug/ativo/bling_id — quem chama
     * decide isso (importar cria rascunho; sincronizar preserva).
     */
    private function mapearCampos(array $b, bool $temVar): array
    {
        $dim = $b['dimensoes'] ?? [];

        return [
            'nome'            => trim((string)($b['nome'] ?? '')),
            'sku_legado'      => trim((string)($b['codigo'] ?? '')) ?: null,
            'preco'           => max(0.01, (float)($b['preco'] ?? 0)),
            // Produto COM variação não tem custo próprio: cada variação tem
            // o seu, e é lá que ele fica. Gravar aqui daria uma margem média
            // que não corresponde a nenhuma variação real.
            'preco_custo'     => $temVar ? null : ($this->custoDoProduto($b) ?: null),
            'descricao_curta' => $this->texto($b['descricaoCurta'] ?? '') ?: null,
            'descricao'       => $this->texto($b['descricaoComplementar'] ?? '') ?: null,
            'marca_id'        => $this->findOrCreateMarca(trim((string)($b['marca'] ?? ''))),
            'peso_kg'         => $this->positivo($b['pesoLiquido'] ?? $b['pesoBruto'] ?? 0),
            'comprimento_cm'  => $this->positivo($dim['profundidade'] ?? 0),
            'largura_cm'      => $this->positivo($dim['largura'] ?? 0),
            'altura_cm'       => $this->positivo($dim['altura'] ?? 0),
        ];
    }

    /** Custo de produto SIMPLES: só existe sob fornecedor. */
    private function custoDoProduto(array $b): float
    {
        return (float)($b['fornecedor']['precoCusto'] ?? 0);
    }

    /** Custo de VARIAÇÃO: campo próprio, com o do fornecedor como reserva. */
    private function custoDaVariacao(array $v): ?float
    {
        $c = (float)($v['precoCusto'] ?? 0);
        if ($c <= 0) $c = (float)($v['fornecedor']['precoCusto'] ?? 0);
        return $c > 0 ? $c : null;
    }

    /**
     * Cria os produto_skus a partir de variacoes[].
     * O custo da variação vai para produto_skus.custo — nunca para o pai.
     */
    private function importarVariacoes(int $produtoId, array $variacoes): int
    {
        $ins = $this->db->prepare(
            "INSERT INTO produto_skus
             (produto_id, sku, ean, preco, custo, estoque, ativo, ordenacao)
             VALUES (?,?,?,?,?,0,1,?)"
        );
        $n = 0;

        foreach ($variacoes as $i => $v) {
            $sku = trim((string)($v['codigo'] ?? ''));
            if ($sku === '') {
                LogService::warning('Variação do Bling sem código — ignorada', [
                    'produto_id' => $produtoId,
                    'variacao'   => $v['variacao']['nome'] ?? null,
                ], 'bling');
                continue;
            }

            // uk_sku e uk_ean são únicos GLOBAIS. Código repetido é erro de
            // cadastro no Bling: pular e logar preserva o resto do produto.
            if ($this->skuJaExiste($sku)) {
                LogService::warning('SKU do Bling já existe no site — variação ignorada', [
                    'produto_id' => $produtoId, 'sku' => $sku,
                ], 'bling');
                continue;
            }

            $ean = preg_replace('/\D+/', '', (string)($v['gtin'] ?? '')) ?: null;
            if ($ean !== null && $this->eanJaExiste($ean)) {
                LogService::warning('EAN duplicado — variação importada sem EAN', [
                    'produto_id' => $produtoId, 'sku' => $sku, 'ean' => $ean,
                ], 'bling');
                $ean = null;
            }

            $ins->execute([
                $produtoId, $sku, $ean,
                max(0.01, (float)($v['preco'] ?? 0)),
                $this->custoDaVariacao($v),
                $i,
            ]);
            $skuId = (int)$this->db->lastInsertId();

            $this->gravarAtributos($produtoId, $skuId, (string)($v['variacao']['nome'] ?? ''));
            $n++;
        }

        return $n;
    }

    /**
     * Converte o nome da variação do Bling em sku_atributos.
     *
     * Formato: "Tamanho:56/S" ou "Tamanho do capacete:56/S;Desenho:Monocolor"
     * — pares Tipo:Valor separados por ';'. O valor pode conter '/', então
     * o split é no PRIMEIRO ':' apenas.
     */
    private function gravarAtributos(int $produtoId, int $skuId, string $nomeVariacao): void
    {
        $nomeVariacao = trim($nomeVariacao);
        if ($nomeVariacao === '') return;

        $insAttr = $this->db->prepare(
            "INSERT INTO sku_atributos (sku_id, atributo_tipo_id, valor) VALUES (?,?,?)"
        );
        $insTipo = $this->db->prepare(
            "INSERT IGNORE INTO produto_variacao_tipos
             (produto_id, atributo_tipo_id, ordenacao) VALUES (?,?,0)"
        );

        foreach (explode(';', $nomeVariacao) as $par) {
            $par = trim($par);
            if ($par === '' || !str_contains($par, ':')) continue;

            [$tipoNome, $valor] = explode(':', $par, 2);
            $tipoNome = trim($tipoNome);
            $valor    = trim($valor);
            if ($tipoNome === '' || $valor === '') continue;

            $tipoId = $this->findOrCreateAtributoTipo($tipoNome);
            $insTipo->execute([$produtoId, $tipoId]);
            $insAttr->execute([$skuId, $tipoId, $valor]);
        }
    }

    // ════════════════════════════════════════════════════
    // SINCRONIZAÇÃO SOB DEMANDA (diff)
    // ════════════════════════════════════════════════════

    /**
     * Compara o produto do site com o do Bling, campo a campo.
     *
     * Devolve APENAS o que difere, para o admin escolher o que trazer.
     * Sobrescrever tudo em bloco apagaria o trabalho editorial da vitrine
     * (nome ajustado, descrição reescrita) sem ninguém perceber.
     *
     * @return array{ok:bool, msg?:string, campos?:array, produto?:array}
     */
    public function diff(int $produtoId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM produtos WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$produtoId]);
        $local = $stmt->fetch();
        if (!$local)                  return ['ok' => false, 'msg' => 'Produto não encontrado.'];
        if (empty($local['bling_id'])) return ['ok' => false, 'msg' => 'Este produto não está vinculado ao Bling.'];

        try {
            $b = $this->api->get("/produtos/{$local['bling_id']}");
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => 'Falha ao consultar o Bling: ' . $e->getMessage()];
        }

        $temVar = !empty($local['tem_variacao']);
        $novos  = $this->mapearCampos($b, $temVar);

        $rotulos = [
            'nome'            => 'Nome',
            'sku_legado'      => 'Código (SKU)',
            'preco'           => 'Preço',
            'preco_custo'     => 'Custo',
            'descricao_curta' => 'Descrição curta',
            'descricao'       => 'Descrição completa',
            'marca_id'        => 'Marca',
            'peso_kg'         => 'Peso (kg)',
            'comprimento_cm'  => 'Comprimento (cm)',
            'largura_cm'      => 'Largura (cm)',
            'altura_cm'       => 'Altura (cm)',
        ];

        $campos = [];
        foreach ($novos as $k => $novo) {
            // Produto com variação não tem custo no pai — nem compara.
            if ($k === 'preco_custo' && $temVar) continue;

            $atual = $local[$k] ?? null;
            if ($this->iguais($atual, $novo)) continue;

            $campos[] = [
                'campo'      => $k,
                'rotulo'     => $rotulos[$k] ?? $k,
                'atual'      => $this->paraExibir($k, $atual),
                'novo'       => $this->paraExibir($k, $novo),
                'atual_raw'  => $atual,
            ];
        }

        return ['ok' => true, 'campos' => $campos,
                'produto' => ['id' => (int)$local['id'], 'nome' => $local['nome']],
                'tem_variacao' => $temVar];
    }

    /**
     * Aplica só os campos que o admin marcou.
     *
     * @param string[] $campos nomes de coluna vindos do diff
     */
    public function aplicar(int $produtoId, array $campos): array
    {
        if (!$campos) return ['ok' => false, 'msg' => 'Nenhum campo selecionado.'];

        $diff = $this->diff($produtoId);
        if (!$diff['ok']) return $diff;

        // Whitelist: só aceita coluna que o próprio diff ofereceu. Impede
        // que um POST forjado escreva em slug, ativo, bling_id ou preco.
        $permitidos = array_column($diff['campos'], 'campo');
        $aplicar    = array_values(array_intersect($campos, $permitidos));
        if (!$aplicar) return ['ok' => false, 'msg' => 'Nenhum dos campos enviados está disponível para sincronizar.'];

        $stmt = $this->db->prepare(
            "SELECT bling_id, tem_variacao FROM produtos WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$produtoId]);
        $p = $stmt->fetch();

        $b     = $this->api->get("/produtos/{$p['bling_id']}");
        $novos = $this->mapearCampos($b, !empty($p['tem_variacao']));

        $sets = []; $vals = [];
        foreach ($aplicar as $c) { $sets[] = "{$c} = ?"; $vals[] = $novos[$c]; }
        $vals[] = $produtoId;

        $this->db->prepare("UPDATE produtos SET " . implode(', ', $sets) . " WHERE id = ?")
                 ->execute($vals);

        LogService::audit('Produto sincronizado com o Bling', [
            'produto_id' => $produtoId, 'campos' => $aplicar,
            'usuario_id' => AuthHelper::usuarioId(),
        ]);

        return ['ok' => true, 'msg' => count($aplicar) . ' campo(s) atualizados a partir do Bling.',
                'campos' => $aplicar];
    }

    // ════════════════════════════════════════════════════
    // AUXILIARES
    // ════════════════════════════════════════════════════

    private function produtoLocalPorBlingId(string $blingId): ?array
    {
        $st = $this->db->prepare(
            "SELECT id, nome, ativo FROM produtos WHERE bling_id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $st->execute([$blingId]);
        return $st->fetch() ?: null;
    }

    private function produtoLocalPorCodigo(string $codigo): ?array
    {
        $st = $this->db->prepare(
            "SELECT id, nome, ativo FROM produtos WHERE sku_legado = ? AND deleted_at IS NULL LIMIT 1"
        );
        $st->execute([$codigo]);
        return $st->fetch() ?: null;
    }

    private function skuJaExiste(string $sku): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM produto_skus WHERE sku = ? LIMIT 1");
        $st->execute([$sku]);
        return (bool)$st->fetchColumn();
    }

    private function eanJaExiste(string $ean): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM produto_skus WHERE ean = ? LIMIT 1");
        $st->execute([$ean]);
        return (bool)$st->fetchColumn();
    }

    private function findOrCreateMarca(string $nome): ?int
    {
        $nome = trim($nome);
        if ($nome === '') return null;

        $st = $this->db->prepare("SELECT id FROM marcas WHERE nome = ? LIMIT 1");
        $st->execute([$nome]);
        if ($id = $st->fetchColumn()) return (int)$id;

        $slug = SlugHelper::unique($nome, 'marcas');
        $this->db->prepare("INSERT INTO marcas (nome, slug, ativo) VALUES (?,?,1)")
                 ->execute([$nome, $slug]);
        return (int)$this->db->lastInsertId();
    }

    private function findOrCreateAtributoTipo(string $nome): int
    {
        $slug = SlugHelper::make($nome);
        $st = $this->db->prepare("SELECT id FROM atributo_tipos WHERE slug = ? LIMIT 1");
        $st->execute([$slug]);
        if ($id = $st->fetchColumn()) return (int)$id;

        $this->db->prepare(
            "INSERT INTO atributo_tipos (nome, slug, tipo_display, papel, ordenacao)
             VALUES (?,?,'button','variacao',0)"
        )->execute([$nome, $slug]);
        return (int)$this->db->lastInsertId();
    }

    private function texto(?string $html): string
    {
        $html = trim((string)$html);
        if ($html === '') return '';
        // O Bling devolve HTML editado por humano; passa pelo mesmo
        // saneador da descrição digitada no painel.
        return class_exists('HtmlHelper') ? HtmlHelper::sanitizeRich($html) : strip_tags($html);
    }

    private function positivo($v): ?float
    {
        $f = (float)$v;
        return $f > 0 ? $f : null;
    }

    /** Comparação tolerante: NULL, '' e 0 não são "diferença" entre si. */
    private function iguais($a, $b): bool
    {
        if ($a === null && ($b === null || $b === '' || $b === 0.0)) return true;
        if ($b === null && ($a === null || $a === '' || (float)$a === 0.0)) return true;
        if (is_numeric($a) && is_numeric($b)) return abs((float)$a - (float)$b) < 0.005;
        return trim((string)$a) === trim((string)$b);
    }

    private function paraExibir(string $campo, $v): string
    {
        if ($v === null || $v === '') return '—';
        if ($campo === 'marca_id') {
            $st = $this->db->prepare("SELECT nome FROM marcas WHERE id = ? LIMIT 1");
            $st->execute([(int)$v]);
            return (string)($st->fetchColumn() ?: $v);
        }
        if (in_array($campo, ['descricao', 'descricao_curta'], true)) {
            $txt = trim(preg_replace('/\s+/', ' ', strip_tags((string)$v)) ?? '');
            return mb_strlen($txt) > 220 ? mb_substr($txt, 0, 220) . '…' : $txt;
        }
        return (string)$v;
    }
}
