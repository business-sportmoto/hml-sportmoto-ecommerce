<?php
/**
 * IARecorteService — remoção de fundo da foto REAL do produto, com cache.
 *
 * Fase 2 · Bloco B. A foto de produto_imagens vira um PNG transparente
 * (bria via Replicate) UMA vez por imagem: o resultado fica em
 * ia_recortes_produto e nunca é pago de novo enquanto a foto não mudar
 * (hash_origem = sha256 do nome do arquivo — os uploads usam nome
 * aleatório, então foto nova = arquivo novo = hash novo).
 *
 * AJUSTE (config): defina a base pública das imagens de produto —
 *   define('IA_PRODUTO_IMG_BASE', 'https://SEU-DOMINIO/uploads/produtos');
 * O Replicate baixa a imagem por essa URL, então ela precisa ser
 * alcançável da internet (no Laragon, aponte para a homolog — o dump
 * carrega os mesmos nomes de arquivo).
 */
class IARecorteService
{
    private PDO $db;
    private IACustoService $custo;

    public function __construct()
    {
        $this->db    = Database::getInstance()->getConnection();
        $this->custo = new IACustoService();
    }

    /* ------------------------------------------------------------------ */
    /* Imagem de origem                                                     */
    /* ------------------------------------------------------------------ */

    /** Imagem principal (ou específica) do produto, já com URL pública. */
    public function imagemDoProduto(int $produtoId, ?int $imagemId = null): ?array
    {
        try {
            if ($imagemId !== null && $imagemId > 0) {
                $stmt = $this->db->prepare(
                    'SELECT id, arquivo FROM produto_imagens WHERE id = :i AND produto_id = :p LIMIT 1'
                );
                $stmt->execute([':i' => $imagemId, ':p' => $produtoId]);
            } else {
                $stmt = $this->db->prepare(
                    'SELECT id, arquivo FROM produto_imagens
                      WHERE produto_id = :p
                   ORDER BY principal DESC, ordem ASC, id ASC
                      LIMIT 1'
                );
                $stmt->execute([':p' => $produtoId]);
            }

            $img = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$img) {
                return null;
            }
            $img['url'] = $img['arquivo'];//$this->urlPublica((string) $img['arquivo']);
            // LogService::debug('debug', $img);
            return $img;
        } catch (Throwable $e) {
            LogService::error('ia_recorte_imagem_erro', ['produto_id' => $produtoId, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** URL absoluta que o provedor consegue baixar (null se a base não estiver configurada). */
    public function urlPublica(string $arquivo): ?string
    {
        if (!defined('IA_PRODUTO_IMG_BASE') || IA_PRODUTO_IMG_BASE === '') {
            return null;
        }
        return rtrim((string) IA_PRODUTO_IMG_BASE, '/') . '/' . ltrim($arquivo, '/');
    }

    /* ------------------------------------------------------------------ */
    /* Cache-first                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Devolve o recorte do cache ou enfileira a remoção de fundo.
     * Retorno: ['ok', 'cache', 'uuids', 'msg', 'custo_estimado_usd'?]
     */
    public function obterRecorte(int $produtoId, int $usuarioId, ?int $imagemId = null): array
    {
        $img = $this->imagemDoProduto($produtoId, $imagemId);
        if ($img === null) {
            return ['ok' => false, 'msg' => 'Produto sem imagem cadastrada.'];
        }
        if (empty($img['url'])) {
            return ['ok' => false, 'msg' => 'Defina IA_PRODUTO_IMG_BASE no config (URL pública das imagens de produto).'];
        }

        $hash = hash('sha256', (string) $img['arquivo']);

        // Cache válido: mesma imagem (hash) e PNG ainda no disco
        $cache = $this->cacheDaImagem((int) $img['id']);
        if ($cache !== null && $cache['hash_origem'] === $hash && is_file((string) $cache['caminho_png'])) {
            return [
                'ok'    => true,
                'cache' => true,
                'uuids' => !empty($cache['geracao_uuid']) ? [(string) $cache['geracao_uuid']] : [],
                'msg'   => 'Recorte recuperado do cache — custo zero.',
            ];
        }

        // Cache frio/inválido: enfileira uma geração de sistema
        $tipo = (new IATipoConteudo())->buscarPorCodigo('recorte_produto');
        if ($tipo === null || (int) $tipo['ativo'] !== 1) {
            return ['ok' => false, 'msg' => 'Tipo de sistema recorte_produto ausente — rode a migration da Fase 2B.'];
        }

        $custo = $this->custo->estimarImagem($this->custo->custoConfigPrimario('remocao_fundo'));
        $chk   = $this->custo->podeGerar($usuarioId, $custo, 1);
        if (!$chk['ok']) {
            return ['ok' => false, 'msg' => $chk['msg']];
        }

        $contexto = json_encode([
            'imagem_origem'     => $img['url'],
            'produto_imagem_id' => (int) $img['id'],
            'hash_origem'       => $hash,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $uuid  = $this->uuidV4();
        $dedup = hash('sha256', 'recorte|' . $img['id'] . '|' . $hash . '|' . (int) floor(time() / 60));

        $id = (new IAGeracao())->criar([
            'uuid'                     => $uuid,
            'usuario_id'               => $usuarioId,
            'produto_id'               => $produtoId,
            'campanha_id'              => null,
            'geracao_origem_id'        => null,
            'tipo_conteudo_id'         => (int) $tipo['id'],
            'capacidade'               => 'remocao_fundo',
            'formato'                  => null,
            'angulo'                   => null,
            'prompt_template_id'       => null,
            'prompt_template_snapshot' => null,
            'prompt_final'             => 'Remoção de fundo da imagem do produto: ' . $img['arquivo'],
            'contexto'                 => $contexto,
            'chave_dedup'              => $dedup,
            'custo_estimado_usd'       => $custo,
        ]);

        if ($id === null) {
            return ['ok' => false, 'msg' => 'Recorte já solicitado neste minuto — aguarde a conclusão.'];
        }

        LogService::audit('ia_recorte_enfileirado', [
            'produto_id'        => $produtoId,
            'produto_imagem_id' => (int) $img['id'],
            'geracao_id'        => $id,
        ]);

        return [
            'ok'                 => true,
            'cache'              => false,
            'uuids'              => [$uuid],
            'custo_estimado_usd' => $custo,
            'msg'                => 'Remoção de fundo enfileirada.',
        ];
    }

    /** Recorte pronto de um produto (para o compositor da Fase 2C). */
    public function recorteDoProduto(int $produtoId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT r.* FROM ia_recortes_produto r
              INNER JOIN produto_imagens pi ON pi.id = r.produto_imagem_id
                  WHERE r.produto_id = :p
               ORDER BY pi.principal DESC, pi.ordem ASC
                  LIMIT 1'
            );
            $stmt->execute([':p' => $produtoId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($r && is_file((string) $r['caminho_png'])) ? $r : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Chamado pelo IAGeracaoService::concluir quando capacidade = remocao_fundo.
     * Upsert por produto_imagem_id (UNIQUE) — refazer o recorte atualiza a linha.
     */
    public function gravarCache(array $geracao, string $caminhoPng, ?string $modeloCodigo): void
    {
        $ctx    = json_decode((string) ($geracao['contexto'] ?? ''), true);
        $ctx    = is_array($ctx) ? $ctx : [];
        $imgId  = (int) ($ctx['produto_imagem_id'] ?? 0);
        $hash   = (string) ($ctx['hash_origem'] ?? '');
        $prodId = (int) ($geracao['produto_id'] ?? 0);

        if ($imgId <= 0 || $hash === '' || $prodId <= 0) {
            LogService::warning('ia_recorte_cache_incompleto', ['geracao_id' => (int) ($geracao['id'] ?? 0)]);
            return;
        }

        try {
            $this->db->prepare(
                'INSERT INTO ia_recortes_produto
                    (produto_id, produto_imagem_id, caminho_png, hash_origem, modelo_codigo, geracao_id)
                 VALUES (:p, :i, :c, :h, :m, :g)
                 ON DUPLICATE KEY UPDATE
                    caminho_png = VALUES(caminho_png), hash_origem = VALUES(hash_origem),
                    modelo_codigo = VALUES(modelo_codigo), geracao_id = VALUES(geracao_id),
                    criado_em = NOW()'
            )->execute([
                ':p' => $prodId,
                ':i' => $imgId,
                ':c' => $caminhoPng,
                ':h' => $hash,
                ':m' => $modeloCodigo,
                ':g' => (int) $geracao['id'],
            ]);

            LogService::audit('ia_recorte_cacheado', [
                'produto_id'        => $prodId,
                'produto_imagem_id' => $imgId,
                'geracao_id'        => (int) $geracao['id'],
            ]);
        } catch (Throwable $e) {
            LogService::error('ia_recorte_cache_erro', ['geracao_id' => (int) $geracao['id'], 'erro' => $e->getMessage()]);
        }
    }

    /* ------------------------------------------------------------------ */

    private function cacheDaImagem(int $produtoImagemId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT r.*, g.uuid AS geracao_uuid
                   FROM ia_recortes_produto r
              LEFT JOIN ia_geracoes g ON g.id = r.geracao_id
                  WHERE r.produto_imagem_id = :i
                  LIMIT 1'
            );
            $stmt->execute([':i' => $produtoImagemId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_recorte_cache_leitura_erro', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    private function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
