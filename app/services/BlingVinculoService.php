<?php
declare(strict_types=1);

/**
 * app/services/BlingVinculoService.php
 *
 * Vincula produtos/SKUs locais aos do Bling preenchendo bling_id,
 * SEM 1-call-por-item. Estratégia: LISTA o catálogo do Bling
 * paginado (~60-260 calls p/ 6k+20k) e casa LOCALMENTE por código.
 *
 * POR QUE ASSIM: perguntar "existe o produto X?" 26.000 vezes
 * estoura o rate limit. Listar tudo (100/página) e reconciliar no
 * banco local é ~100x mais barato — padrão de integração em escala.
 *
 * DEFENSIVO quanto a VARIAÇÕES: não assume se vêm aninhadas no
 * produto-pai ou não. Detecta um campo de variações e processa se
 * existir; loga a estrutura da 1ª página para confirmação empírica.
 *
 * O rate limit (3 req/s) é do BlingApiClient e é ESTÁTICO — então
 * rodar isto junto com a fila de contatos no mesmo processo respeita
 * o mesmo teto automaticamente (sua exigência de limite compartilhado).
 */
final class BlingVinculoService
{
    private BlingApiClient $api;
    private PDO $db;

    // Airbag: teto de páginas por execução. 300 páginas × 100 = 30k
    // produtos — cobre 6k com folga e impede loop infinito de paginação.
    private const MAX_PAGINAS = 300;
    private const POR_PAGINA  = 100;

    public function __construct()
    {
        $this->api = new BlingApiClient();
        $this->db  = Database::getInstance()->getConnection();
    }

    /**
     * @return array{
     *   paginas:int, produtos_bling:int, variacoes_bling:int,
     *   vinculados_produtos:int, vinculados_skus:int,
     *   sem_par_local:int, estrutura_amostra:?array
     * }
     */
    public function vincularTudo(): array
    {
        // Índices código→id montados a partir da LISTAGEM do Bling
        $idxProdutos  = [];   // codigo => bling_id (produtos-pai/simples)
        $idxVariacoes = [];   // codigo => bling_id (variações, se aninhadas)
        $estruturaAmostra = null;

        $pagina = 1;
        $produtosBling = 0;
        $variacoesBling = 0;

        while ($pagina <= self::MAX_PAGINAS) {
            $lista = $this->api->get('/produtos', [
                'pagina' => $pagina,
                'limite' => self::POR_PAGINA,
            ]);

            // Página vazia = fim do catálogo
            if (empty($lista) || !is_array($lista)) {
                break;
            }

            // Guarda a estrutura de UM produto p/ inspeção (só 1ª página)
            if ($estruturaAmostra === null && isset($lista[0])) {
                $estruturaAmostra = $this->chaves($lista[0]);
            }

            foreach ($lista as $prod) {
                $codigo  = trim((string)($prod['codigo'] ?? ''));
                $blingId = (string)($prod['id'] ?? '');
                $idPai   = (string)($prod['idProdutoPai'] ?? '0');

                if ($codigo === '' || $blingId === '') {
                    continue;
                }

                // idProdutoPai distingue os 3 casos direto da LISTAGEM,
                // sem buscar detalhe de cada produto:
                //  == id      → produto simples
                //  == 0/vazio → produto-pai de família
                //  != id, !=0 → variação (linha própria na lista)
                $ehVariacao = ($idPai !== '0' && $idPai !== '' && $idPai !== $blingId);

                if ($ehVariacao) {
                    $idxVariacoes[$codigo] = $blingId;
                    $variacoesBling++;
                } else {
                    $idxProdutos[$codigo] = $blingId;
                    $produtosBling++;
                }
            }

            // Menos que uma página cheia = última página
            if (count($lista) < self::POR_PAGINA) {
                break;
            }
            $pagina++;
        }

        // ── Reconciliação LOCAL (zero API) ──
        $vinProd = $this->casarProdutos($idxProdutos);
        // SKUs: casa tanto com o índice de variações quanto com o de
        // produtos (caso um SKU no site corresponda a um produto simples
        // no Bling — acontece quando a modelagem difere entre os lados).
        $idxSkus = $idxVariacoes + $idxProdutos; // variações têm prioridade
        $vinSkus = $this->casarSkus($idxSkus);

        LogService::info(
            'Vinculação Bling concluída',
            [
                'paginas'             => $pagina,
                'produtos_bling'      => $produtosBling,
                'variacoes_bling'     => $variacoesBling,
                'vinculados_produtos' => $vinProd,
                'vinculados_skus'     => $vinSkus,
                'estrutura_amostra'   => $estruturaAmostra,
            ],
            'bling'
        );

        return [
            'paginas'             => $pagina,
            'produtos_bling'      => $produtosBling,
            'variacoes_bling'     => $variacoesBling,
            'vinculados_produtos' => $vinProd,
            'vinculados_skus'     => $vinSkus,
            'estrutura_amostra'   => $estruturaAmostra,
        ];
    }

    private function casarProdutos(array $idx): int
    {
        if (!$idx) return 0;
        $stmt = $this->db->prepare(
            "UPDATE produtos SET bling_id = ?
             WHERE TRIM(sku_legado) = ? COLLATE utf8mb4_unicode_ci
               AND bling_id IS NULL"
        );
        $n = 0;
        foreach ($idx as $codigo => $blingId) {
            $stmt->execute([$blingId, trim((string)$codigo)]);   // ← (string)
            $n += $stmt->rowCount();
        }
        return $n;
    }

    private function casarSkus(array $idx): int
    {
        if (!$idx) return 0;
        $stmt = $this->db->prepare(
            "UPDATE produto_skus SET bling_id = ?
             WHERE TRIM(sku) = ? COLLATE utf8mb4_unicode_ci
               AND bling_id IS NULL"
        );
        $n = 0;
        foreach ($idx as $codigo => $blingId) {
            $stmt->execute([$blingId, trim((string)$codigo)]);   // ← (string)
            $n += $stmt->rowCount();
        }
        return $n;
    }

    /** Nomes de chave de 1 registro — p/ inspecionar a estrutura. */
    private function chaves(array $registro): array
    {
        $out = [];
        foreach ($registro as $k => $v) {
            $out[$k] = is_array($v) ? '[array:' . count($v) . ']' : gettype($v);
        }
        return $out;
    }
    /**
     * Vincula clientes locais aos contatos do Bling (cliente.bling_id),
     * casando por CPF (numeroDocumento). Mesma estratégia dos produtos:
     * LISTA /contatos paginado e casa LOCAL — sem 1-call-por-cliente.
     *
     * @return array{paginas:int, contatos_bling:int, vinculados:int}
     */
    public function vincularContatos(): array
    {
        $idx = [];   // cpf_limpo => bling_id
        $pagina = 1;
        $contatosBling = 0;

        while ($pagina <= self::MAX_PAGINAS) {
            $lista = $this->api->get('/contatos', [
                'pagina' => $pagina,
                'limite' => self::POR_PAGINA,
            ]);
            if (empty($lista) || !is_array($lista)) break;

            foreach ($lista as $contato) {
                $doc     = preg_replace('/\D/', '', (string)($contato['numeroDocumento'] ?? ''));
                $blingId = (string)($contato['id'] ?? '');
                if ($doc !== '' && $blingId !== '') {
                    $idx[$doc] = $blingId;
                    $contatosBling++;
                }
            }

            if (count($lista) < self::POR_PAGINA) break;
            $pagina++;
        }

        $vinculados = $this->casarContatos($idx);

        LogService::info('Vinculação de contatos Bling concluída', [
            'paginas'        => $pagina,
            'contatos_bling' => $contatosBling,
            'vinculados'     => $vinculados,
        ], 'bling');

        return ['paginas' => $pagina, 'contatos_bling' => $contatosBling, 'vinculados' => $vinculados];
    }

    /** Casa clientes locais (cpf) com o índice do Bling. Normaliza CPF
     *  dos DOIS lados (só dígitos) — cliente.cpf pode ter máscara e o
     *  numeroDocumento do Bling também. (string)$cpf: chave numérica
     *  vira int no PHP → cast evita TypeError. */
    private function casarContatos(array $idx): int
    {
        if (!$idx) return 0;
        $stmt = $this->db->prepare(
            "UPDATE clientes
             SET bling_id = ?, bling_sincronizado_em = NOW()
             WHERE REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),'/','') = ?
               AND bling_id IS NULL"
        );
        $n = 0;
        foreach ($idx as $cpf => $blingId) {
            $stmt->execute([$blingId, (string)$cpf]);
            $n += $stmt->rowCount();
        }
        return $n;
    }

    /**
     * Vincula UM produto e suas variações via detalhe do Bling
     * (GET /produtos/{blingId}), que retorna variacoes[] aninhadas.
     * Eficiente: 1-2 chamadas, não lista o catálogo inteiro.
     *
     * Usado pelo sync individual do painel — corrige o caso de
     * VARIAÇÃO NOVA que o resolverBlingId 1-por-1 não casava.
     *
     * @return array{ok:bool, pai_vinculado:bool, skus_vinculados:int, msg:string}
     */
    public function vincularUmProduto(int $produtoId): array
    {
        // 1. Resolve o bling_id do PAI (se ainda não tem)
        $stmt = $this->db->prepare(
            "SELECT id, sku_legado, bling_id FROM produtos WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$produtoId]);
        $prod = $stmt->fetch();
        if (!$prod) {
            return ['ok'=>false, 'pai_vinculado'=>false, 'skus_vinculados'=>0,
                    'msg'=>'Produto não encontrado.'];
        }

        $blingIdPai = (string)($prod['bling_id'] ?? '');
        $paiVinculado = false;

        if ($blingIdPai === '') {
            // Busca o pai no Bling pelo código (sku_legado)
            $codigo = trim((string)($prod['sku_legado'] ?? ''));
            if ($codigo === '') {
                return ['ok'=>false, 'pai_vinculado'=>false, 'skus_vinculados'=>0,
                        'msg'=>'Produto sem código (sku_legado) para buscar no Bling.'];
            }
            $busca = $this->api->get('/produtos', ['codigo' => $codigo]);
            $blingIdPai = (string)($busca[0]['id'] ?? '');
            if ($blingIdPai === '') {
                return ['ok'=>false, 'pai_vinculado'=>false, 'skus_vinculados'=>0,
                        'msg'=>"Nenhum produto no Bling com o código \"{$codigo}\"."];
            }
            $this->db->prepare("UPDATE produtos SET bling_id = ? WHERE id = ?")
                     ->execute([$blingIdPai, $produtoId]);
            $paiVinculado = true;
        }

        // 2. Busca o DETALHE do produto — traz variacoes[] aninhadas
        $detalhe = $this->api->get("/produtos/{$blingIdPai}");

        // 3. Monta índice das variações: codigo => bling_id
        $idxVariacoes = [];
        $vars = $detalhe['variacoes'] ?? $detalhe['variations'] ?? [];
        if (is_array($vars)) {
            foreach ($vars as $v) {
                $codVar = trim((string)($v['codigo'] ?? ''));
                $idVar  = (string)($v['id'] ?? '');
                if ($codVar !== '' && $idVar !== '') {
                    $idxVariacoes[$codVar] = $idVar;
                }
            }
        }

        // 4. Casa os SKUs locais (mesma lógica da massa, com TRIM/COLLATE/(string))
        $skusVinculados = $this->casarSkusProduto($idxVariacoes, $produtoId);

        LogService::info('Sync individual de produto (Bling)', [
            'produto_id'       => $produtoId,
            'bling_id_pai'     => $blingIdPai,
            'variacoes_bling'  => count($idxVariacoes),
            'skus_vinculados'  => $skusVinculados,
        ], 'bling');

        return [
            'ok'              => true,
            'pai_vinculado'   => $paiVinculado,
            'skus_vinculados' => $skusVinculados,
            'msg'             => ($paiVinculado ? 'Pai vinculado. ' : '')
                               . "{$skusVinculados} variação(ões) vinculada(s).",
        ];
    }

    /** Casa SKUs de UM produto específico (escopo por produto_id,
     *  diferente do casarSkus global). TRIM/COLLATE/(string) — as
     *  mesmas defesas da massa contra espaço/caixa/chave-int. */
    private function casarSkusProduto(array $idx, int $produtoId): int
    {
        if (!$idx) return 0;
        $stmt = $this->db->prepare(
            "UPDATE produto_skus SET bling_id = ?
             WHERE TRIM(sku) = ? COLLATE utf8mb4_unicode_ci
               AND produto_id = ?
               AND bling_id IS NULL"
        );
        $n = 0;
        foreach ($idx as $codigo => $blingId) {
            $stmt->execute([$blingId, trim((string)$codigo), $produtoId]);
            $n += $stmt->rowCount();
        }
        return $n;
    }
}