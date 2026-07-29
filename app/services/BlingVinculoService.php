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
                if ($codigo !== '' && $blingId !== '') {
                    $idxProdutos[$codigo] = $blingId;
                    $produtosBling++;
                }

                // VARIAÇÕES aninhadas? Detecta os nomes de campo comuns.
                $vars = $prod['variacoes'] ?? $prod['variations'] ?? null;
                if (is_array($vars)) {
                    foreach ($vars as $v) {
                        $codVar   = trim((string)($v['codigo'] ?? ''));
                        $idVar    = (string)($v['id'] ?? '');
                        if ($codVar !== '' && $idVar !== '') {
                            $idxVariacoes[$codVar] = $idVar;
                            $variacoesBling++;
                        }
                    }
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

    /** Casa produtos locais (sku_legado) com o índice do Bling. */
    private function casarProdutos(array $idx): int
    {
        if (!$idx) return 0;
        $stmt = $this->db->prepare(
            "UPDATE produtos SET bling_id = ?
             WHERE sku_legado = ? AND bling_id IS NULL"
        );
        $n = 0;
        foreach ($idx as $codigo => $blingId) {
            $stmt->execute([$blingId, $codigo]);
            $n += $stmt->rowCount();
        }
        return $n;
    }

    /** Casa SKUs locais (produto_skus.sku) com o índice do Bling. */
    private function casarSkus(array $idx): int
    {
        if (!$idx) return 0;
        $stmt = $this->db->prepare(
            "UPDATE produto_skus SET bling_id = ?
             WHERE sku = ? AND bling_id IS NULL"
        );
        $n = 0;
        foreach ($idx as $codigo => $blingId) {
            $stmt->execute([$blingId, $codigo]);
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
}