<?php
/**
 * IAComposicaoService — pipeline do banner como GERAÇÃO ÚNICA com etapas.
 *
 *   na_fila ──worker──▶ etapa recorte (bria)  ──▶ etapa cena (flux/gpt-image)
 *                     └(cache quente pula)──▶┘            │
 *                                          compositor Imagick ──▶ concluida
 *
 * Regras do desenho (Fase 2C):
 *  - UM card, UM polling: a etapa vive em ia_geracoes.etapa e o custo de cada
 *    passo é somado em contexto.custo_acumulado; o rollup é POR ETAPA
 *    (remocao_fundo/imagem) — concluir() pula o rollup para composicao.
 *  - Fallback PÓS-ACEITE (a promessa da 2C): se a prediction falhar no
 *    provedor, a etapa retenta no próximo modelo da capacidade
 *    (modelos_tentados no contexto), inclusive caindo no gpt-image SÍNCRONO.
 *  - Webhook e varredura convergem em processarRetorno() — idempotente pelo
 *    guard de status, como no Bloco A.
 */
class IAComposicaoService
{
    private PDO $db;
    private IAOrchestrator $orq;
    private IACustoService $custo;

    public function __construct(?IAOrchestrator $orq = null)
    {
        $this->db    = Database::getInstance()->getConnection();
        $this->orq   = $orq ?? new IAOrchestrator();
        $this->custo = new IACustoService();
    }

    /* ================================================================ */
    /* Enfileirar                                                        */
    /* ================================================================ */

    /** Chamado pelo IAGeracaoService::enfileirar quando capacidade = composicao. */
    public function enfileirarBanner(array $entrada, array $tipo): array
    {
        $produtoId = (int) ($entrada['produto_id'] ?? 0);
        $usuarioId = (int) ($entrada['usuario_id'] ?? 0);

        $layout = $this->layoutPorCodigo(trim((string) ($entrada['layout'] ?? '')));
        if ($layout === null) {
            return ['ok' => false, 'msg' => 'Escolha um layout válido para o banner.'];
        }

        $contexto = (new IAPromptBuilder())->montarContexto($produtoId);
        if ($contexto === null) {
            return ['ok' => false, 'msg' => 'Produto não encontrado ou removido.'];
        }

        $rec = new IARecorteService();
        $img = $rec->imagemDoProduto($produtoId);
        if ($img === null) {
            return ['ok' => false, 'msg' => 'Produto sem imagem cadastrada — o banner usa a foto real.'];
        }
        if (empty($img['url'])) {
            return ['ok' => false, 'msg' => 'Defina IA_PRODUTO_IMG_BASE no config (URL pública das imagens de produto).'];
        }

        $camadas = json_decode((string) $layout['camadas'], true);
        if (!is_array($camadas) || empty($camadas['produto'])) {
            return ['ok' => false, 'msg' => 'Layout sem configuração de camadas — rode a migration da Fase 2C.'];
        }

        // Cena: prompt do builder + direção de respiro conforme o lado do produto
        $briefing  = is_array($entrada['briefing'] ?? null) ? $entrada['briefing'] : [];
        $prompt    = (new IAPromptBuilder())->montarPromptImagem($contexto, $tipo, $briefing);
        $cx        = (float) ($camadas['produto']['cx'] ?? 0.5);
        $prompt   .= ($cx >= 0.6)
            ? "\nComposição: lado direito do quadro mais limpo (o produto entrará ali)."
            : (($cx <= 0.4) ? "\nComposição: lado esquerdo do quadro mais limpo (o produto entrará ali)."
                            : "\nComposição: centro do quadro com respiro (o produto entrará ali).");

        $headline  = trim((string) ($entrada['banner_headline'] ?? ''));
        $subtitulo = trim((string) ($entrada['banner_subtitulo'] ?? ''));
        $textos = [
            'headline'  => $headline !== '' ? $headline : (string) $contexto['nome'],
            'subtitulo' => $subtitulo,
            'preco_txt' => 'R$ ' . number_format((float) ($contexto['preco'] ?? 0), 2, ',', '.'),
        ];

        $hash        = hash('sha256', (string) $img['arquivo']);
        $cacheQuente = $this->recorteEmCache((int) $img['id'], $hash);

        // Estimativa honesta: cache quente não paga o bria de novo
        $custoRecorte = $cacheQuente ? 0.0 : $this->custo->estimarImagem($this->custo->custoConfigPrimario('remocao_fundo'));
        $custoCena    = $this->custo->estimarImagem($this->custo->custoConfigPrimario('imagem'));
        $custoEst     = round($custoRecorte + $custoCena, 6);

        $chk = $this->custo->podeGerar($usuarioId, $custoEst, 1);
        if (!$chk['ok']) {
            return ['ok' => false, 'msg' => $chk['msg']];
        }

        $ctx = [
            'produto'  => $contexto,
            'briefing' => $briefing,
            'textos'   => $textos,
            'layout'   => (string) $layout['codigo'],
            'recorte'  => [
                'produto_imagem_id' => (int) $img['id'],
                'hash_origem'       => $hash,
                'imagem_origem'     => (string) $img['url'],
            ],
            'cena'     => [
                'prompt'    => $prompt,
                'proporcao' => $this->proporcaoDoLayout((int) $layout['largura'], (int) $layout['altura']),
            ],
            'retry'           => ['recorte' => [], 'cena' => []],
            'custo_acumulado' => 0.0,
        ];

        $uuid = $this->uuidV4();
        $id = (new IAGeracao())->criar([
            'uuid'                     => $uuid,
            'usuario_id'               => $usuarioId,
            'produto_id'               => $produtoId,
            'campanha_id'              => (isset($entrada['campanha_id']) && (int) $entrada['campanha_id'] > 0)
                                              ? (int) $entrada['campanha_id'] : null,
            'geracao_origem_id'        => isset($entrada['origem_id']) ? (int) $entrada['origem_id'] : null,
            'tipo_conteudo_id'         => (int) $tipo['id'],
            'capacidade'               => 'composicao',
            'formato'                  => (string) $layout['codigo'],
            'angulo'                   => null,
            'prompt_template_id'       => null,
            'prompt_template_snapshot' => null,
            'prompt_final'             => $prompt,
            'contexto'                 => json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'chave_dedup'              => !empty($entrada['chave_dedup'])
                ? (string) $entrada['chave_dedup']
                : hash('sha256', implode('|', [
                    'banner', $usuarioId, $produtoId, $layout['codigo'], md5($prompt . $textos['headline']), (int) floor(time() / 60),
                ])),
            'custo_estimado_usd'       => $custoEst,
        ]);

        if ($id === null) {
            return ['ok' => false, 'msg' => 'Banner idêntico já solicitado neste minuto — aguarde ou mude o briefing.'];
        }

        // Cache quente: a primeira etapa já é a cena
        (new IAGeracao())->marcarEtapa($id, $cacheQuente ? 'cena' : 'recorte');

        LogService::audit('ia_banner_enfileirado', [
            'geracao_id' => $id, 'produto_id' => $produtoId,
            'layout' => $layout['codigo'], 'cache_recorte' => $cacheQuente,
        ]);

        return ['ok' => true, 'uuids' => [$uuid], 'custo_estimado_usd' => $custoEst,
                'msg' => $cacheQuente ? 'Banner enfileirado (recorte em cache — custo menor).' : 'Banner enfileirado.'];
    }

    /* ================================================================ */
    /* Worker: estado inicial pós-claim                                  */
    /* ================================================================ */

    /** Retorna string de log: 'aguardando (etapa)' | 'concluida' | 'falhou'. */
    public function processar(array $g): string
    {
        $ctx   = $this->ctx($g);
        $etapa = (string) ($g['etapa'] ?? 'recorte');

        $r = ($etapa === 'cena')
            ? $this->criarEtapa($g, $ctx, 'imagem', $ctx['retry']['cena'] ?? [])
            : $this->criarEtapa($g, $ctx, 'remocao_fundo', $ctx['retry']['recorte'] ?? []);

        return $this->tratarResultadoDeEtapa($g, $ctx, $etapa, $r);
    }

    /* ================================================================ */
    /* Webhook/varredura: retornos assíncronos                           */
    /* ================================================================ */

    /** Mesmo contrato do Bloco A: 'concluida'|'falhou'|'pendente'|'ignorado'. */
    public function processarRetorno(array $g, array $remoto, ReplicateAdapter $adapter): string
    {
        if (($g['status'] ?? '') !== 'aguardando_provedor') {
            return 'ignorado';
        }

        $statusRemoto = (string) ($remoto['status'] ?? '');
        if (in_array($statusRemoto, ['starting', 'processing', 'consulta_falhou', ''], true)) {
            return 'pendente';
        }

        $ctx   = $this->ctx($g);
        $etapa = (string) ($g['etapa'] ?? 'recorte');

        // ── Falha remota: fallback pós-aceite (retenta no próximo modelo) ──
        if (in_array($statusRemoto, ['failed', 'canceled'], true)) {
            $ctx['retry'][$etapa][] = (string) ($g['modelo_codigo'] ?? '');
            LogService::warning('ia_banner_etapa_falhou_remoto', [
                'geracao_id' => (int) $g['id'], 'etapa' => $etapa,
                'modelo' => $g['modelo_codigo'], 'erro' => $remoto['error'] ?? null,
            ]);

            $r = $this->criarEtapa($g, $ctx, $etapa === 'cena' ? 'imagem' : 'remocao_fundo', $ctx['retry'][$etapa]);
            $desfecho = $this->tratarResultadoDeEtapa($g, $ctx, $etapa, $r, (string) ($remoto['error'] ?? 'falha no provedor'));
            return ($desfecho === 'concluida') ? 'concluida' : (($desfecho === 'falhou') ? 'falhou' : 'pendente');
        }

        // ── succeeded: baixar IMEDIATAMENTE ──
        $urls = $adapter->extrairUrlsSaida($remoto['output'] ?? null);
        $dl   = !empty($urls) ? $adapter->baixarSaida($urls[0]) : ['ok' => false, 'erro' => 'sem output'];
        if (empty($dl['ok'])) {
            LogService::warning('ia_banner_download_falhou', ['geracao_id' => (int) $g['id'], 'etapa' => $etapa, 'erro' => $dl['erro'] ?? null]);
            return 'pendente'; // varredura refaz
        }

        $custoEtapa = (float) ($this->custo->custoRealImagemPorModelo(isset($g['modelo_id']) ? (int) $g['modelo_id'] : null) ?? 0);
        $tempoMs    = isset($remoto['metrics']['predict_time']) ? (int) round(((float) $remoto['metrics']['predict_time']) * 1000) : 0;

        if ($etapa === 'recorte') {
            if (!$this->salvarRecorte($g, $ctx, (string) $dl['binario'], $custoEtapa)) {
                (new IAGeracaoService())->falhar($g, IAResultado::falha('recorte_storage', 'Recorte concluído, mas falhou ao gravar/cachear.', false));
                return 'falhou';
            }
            $ctx['custo_acumulado'] = round(((float) $ctx['custo_acumulado']) + $custoEtapa, 6);

            // Avança para a cena
            $r = $this->criarEtapa($g, $ctx, 'imagem', $ctx['retry']['cena'] ?? []);
            $desfecho = $this->tratarResultadoDeEtapa($g, $ctx, 'cena', $r);
            return ($desfecho === 'concluida') ? 'concluida' : (($desfecho === 'falhou') ? 'falhou' : 'pendente');
        }

        // etapa cena: compor e finalizar
        return $this->finalizar($g, $ctx, (string) $dl['binario'], $custoEtapa, $tempoMs,
                                (string) ($g['modelo_codigo'] ?? ''), isset($g['modelo_id']) ? (int) $g['modelo_id'] : null,
                                (string) ($g['provedor_codigo'] ?? 'replicate'))
            ? 'concluida' : 'falhou';
    }

    /* ================================================================ */
    /* Publicar                                                          */
    /* ================================================================ */

    /** Copia a arte final e cria o banner INATIVO na zona compatível. */
    public function publicarBanner(int $geracaoId, int $usuarioId, array $dados = []): array
    {
        $ger = (new IAGeracao())->buscarPorId($geracaoId);
        if ($ger === null || $ger['capacidade'] !== 'composicao' || $ger['status'] !== 'concluida') {
            return ['ok' => false, 'msg' => 'Geração inválida para publicação.'];
        }

        $arquivoId = (new IAGeracao())->arquivoPrincipalDe($geracaoId);
        $arq       = $arquivoId !== null ? (new IAGeracao())->arquivoPorId($arquivoId) : null;
        if ($arq === null || !is_file((string) $arq['caminho'])) {
            return ['ok' => false, 'msg' => 'Arte final não encontrada no storage.'];
        }

        $layout = $this->layoutPorCodigo((string) $ger['formato']);
        $zona   = $this->zonaCompativel($layout, isset($dados['zona_id']) ? (int) $dados['zona_id'] : 0);
        if ($zona === null) {
            return ['ok' => false, 'msg' => 'Nenhuma zona de banners ativa — cadastre uma zona antes de publicar.'];
        }

        // Destino público do arquivo — AJUSTE: alinhe ao diretório real dos banners
        $dir = defined('IA_BANNER_DIR')
            ? rtrim((string) IA_BANNER_DIR, '/')
            : rtrim(dirname(__DIR__, 3), '/') . '/uploads/banners';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'msg' => 'Não foi possível criar o diretório de banners.'];
        }
        $nomeArq = 'ia-' . $ger['uuid'] . '.jpg';
        if (!copy((string) $arq['caminho'], $dir . '/' . $nomeArq)) {
            return ['ok' => false, 'msg' => 'Falha ao copiar a arte para o diretório público.'];
        }
        $valorArquivo = (defined('IA_BANNER_VALOR_PREFIXO') ? (string) IA_BANNER_VALOR_PREFIXO : '') . $nomeArq;

        $ctx    = json_decode((string) $ger['contexto'], true) ?: [];
        $titulo = trim((string) ($dados['titulo'] ?? '')) ?: ('IA — ' . ($ctx['textos']['headline'] ?? ('Banner #' . $geracaoId)));

        try {
            $ordem = (int) $this->db->query('SELECT COALESCE(MAX(ordem),0)+1 FROM banners WHERE zona_id = ' . (int) $zona['id'])->fetchColumn();
            $stmt  = $this->db->prepare(
                "INSERT INTO banners (zona_id, titulo, tipo_midia, arquivo_imagem, alt_text, link_geral, ordem, ativo)
                 VALUES (:zona, :titulo, 'imagem', :arquivo, :alt, :link, :ordem, 0)"
            );
            $stmt->execute([
                ':zona'    => (int) $zona['id'],
                ':titulo'  => mb_substr($titulo, 0, 255),
                ':arquivo' => mb_substr($valorArquivo, 0, 255),
                ':alt'     => mb_substr($titulo, 0, 255),
                ':link'    => !empty($dados['link']) ? mb_substr((string) $dados['link'], 0, 500) : null,
                ':ordem'   => $ordem,
            ]);
            $bannerId = (int) $this->db->lastInsertId();
        } catch (Throwable $e) {
            LogService::error('ia_banner_publicar_erro', ['geracao_id' => $geracaoId, 'erro' => $e->getMessage()]);
            return ['ok' => false, 'msg' => 'Erro ao gravar o banner: ' . $e->getMessage()];
        }

        $this->db->prepare("UPDATE ia_geracoes SET aprovacao = 'aprovado' WHERE id = :id")->execute([':id' => $geracaoId]);

        LogService::audit('ia_banner_publicado', [
            'geracao_id' => $geracaoId, 'banner_id' => $bannerId,
            'zona' => $zona['chave'] ?? $zona['id'], 'usuario_id' => $usuarioId,
        ]);

        return ['ok' => true, 'banner_id' => $bannerId,
                'msg' => 'Banner criado INATIVO na zona "' . ($zona['nome'] ?? $zona['id']) . '" — revise e ative na tela de banners.'];
    }

    /* ================================================================ */
    /* Internos                                                          */
    /* ================================================================ */

    /** Tenta os candidatos da capacidade, pulando modelos já tentados. */
    private function criarEtapa(array $g, array $ctx, string $capacidade, array $excluirModelos): IAResultado
    {
        $candidatos = $this->orq->modelosDaCapacidade($capacidade, null);
        if (empty($candidatos)) {
            return IAResultado::falha('sem_modelos', "Nenhum modelo ativo da capacidade {$capacidade}.", false);
        }

        $ultimo = null;
        foreach ($candidatos as $m) {
            if (in_array((string) $m['codigo_modelo'], $excluirModelos, true)) {
                continue;
            }

            $adapter = $this->orq->adapterPorCodigo((string) $m['prov_codigo']);
            if ($adapter === null) {
                $this->orq->logRoteamento((int) $g['id'], $m, 'pulado', 'sem_adapter', 'Adapter indisponível.', 0);
                continue;
            }

            if ($capacidade === 'remocao_fundo') {
                $r = $adapter->removerFundo([
                    'imagem_origem' => (string) ($ctx['recorte']['imagem_origem'] ?? ''),
                    'modelo_codigo' => (string) $m['codigo_modelo'],
                    'timeout_s'     => (int) $m['timeout_s'],
                    'params'        => json_decode((string) ($m['params_padrao'] ?? ''), true) ?: [],
                ]);
            } else {
                $r = $adapter->gerarImagem([
                    'prompt'        => (string) ($ctx['cena']['prompt'] ?? $g['prompt_final']),
                    'proporcao'     => (string) ($ctx['cena']['proporcao'] ?? '3:2'),
                    'modelo_codigo' => (string) $m['codigo_modelo'],
                    'timeout_s'     => (int) $m['timeout_s'],
                    'params'        => json_decode((string) ($m['params_padrao'] ?? ''), true) ?: [],
                ]);
            }

            $r->modeloId       = (int) $m['id'];
            $r->provedorCodigo = (string) $m['prov_codigo'];
            $r->modeloCodigo   = (string) $m['codigo_modelo'];

            if ($r->aguardando) {
                $this->orq->logRoteamento((int) $g['id'], $m, 'aguardando', null, null, $r->tempoMs);
                return $r;
            }
            if ($r->ok) {
                $this->orq->logRoteamento((int) $g['id'], $m, 'ok', null, null, $r->tempoMs);
                $r->custoRealUsd = $this->custo->custoRealImagemPorModelo((int) $m['id']);
                return $r;
            }

            $this->orq->logRoteamento((int) $g['id'], $m, ($r->erroCodigo === 'rede') ? 'timeout' : 'falha', $r->erroCodigo, $r->erro, $r->tempoMs);
            $ultimo = $r;
            if (!$r->retryable) {
                return $r;
            }
        }

        return $ultimo ?? IAResultado::falha('todos_falharam', "Todos os modelos da etapa falharam ou já foram tentados.", false);
    }

    /** Converte o IAResultado da etapa no próximo estado da geração. */
    private function tratarResultadoDeEtapa(array $g, array $ctx, string $etapa, IAResultado $r, string $motivoAnterior = ''): string
    {
        $ger = new IAGeracao();
        $svc = new IAGeracaoService();

        if ($r->aguardando) {
            $ger->marcarAguardando((int) $g['id'], (string) $r->externalId, $r->modeloId, $r->provedorCodigo, $r->modeloCodigo);
            $ger->marcarEtapa((int) $g['id'], $etapa);
            $ger->atualizarContexto((int) $g['id'], json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return "aguardando ({$etapa} via {$r->modeloCodigo})";
        }

        if ($r->ok && $etapa === 'cena' && !empty($r->imagens)) {
            // Caminho SÍNCRONO (gpt-image) — compõe agora
            $custoCena = (float) ($r->custoRealUsd ?? 0);
            $okFinal = $this->finalizar($g, $ctx, (string) $r->imagens[0]['binario'], $custoCena, (int) $r->tempoMs,
                                        (string) $r->modeloCodigo, $r->modeloId, (string) $r->provedorCodigo);
            return $okFinal ? 'concluida' : 'falhou';
        }

        $msg = $r->erro ?: 'etapa falhou';
        if ($motivoAnterior !== '') {
            $msg = "após [{$motivoAnterior}]: " . $msg;
        }
        $rf = IAResultado::falha('etapa_' . $etapa, $msg, false);
        $rf->provedorCodigo = $r->provedorCodigo;
        $svc->falhar($g, $rf);
        return 'falhou';
    }

    /** Baixou a cena: compõe com o recorte do cache e conclui a geração. */
    private function finalizar(array $g, array $ctx, string $cenaBin, float $custoCena, int $tempoMs,
                               string $modeloCodigo, ?int $modeloId, string $provedorCodigo): bool
    {
        $svc = new IAGeracaoService();

        $recorteBin = $this->recorteBinario($ctx);
        if ($recorteBin === null) {
            $svc->falhar($g, IAResultado::falha('compositor', 'Recorte do produto ausente no cache.', false));
            return false;
        }

        $layout  = $this->layoutPorCodigo((string) ($ctx['layout'] ?? $g['formato']));
        $camadas = $layout !== null ? json_decode((string) $layout['camadas'], true) : null;
        if ($layout === null || !is_array($camadas)) {
            $svc->falhar($g, IAResultado::falha('compositor', 'Layout/camadas indisponíveis.', false));
            return false;
        }

        $final = (new IACompositorService())->compor($layout, $camadas, $cenaBin, $recorteBin, (array) ($ctx['textos'] ?? []));
        if ($final === null) {
            $svc->falhar($g, IAResultado::falha('compositor', 'Falha na composição Imagick (ver logs).', false));
            return false;
        }

        // Rollup da etapa cena (recorte já foi contabilizado na etapa dele)
        $this->custo->registrarRollup((int) $g['usuario_id'], $provedorCodigo, 'imagem', $custoCena, false);

        $r = IAResultado::sucessoImagem([$final]);
        $r->modeloCodigo   = $modeloCodigo;
        $r->modeloId       = $modeloId;
        $r->provedorCodigo = $provedorCodigo;
        $r->tempoMs        = $tempoMs;
        $r->custoRealUsd   = round(((float) ($ctx['custo_acumulado'] ?? 0)) + $custoCena, 6);

        (new IAGeracao())->marcarEtapa((int) $g['id'], null);
        $svc->concluir($g, $r); // concluir pula rollup p/ composicao (etapas já contabilizadas)
        return true;
    }

    /** Grava o PNG do recorte no storage e alimenta o cache oficial. */
    private function salvarRecorte(array $g, array $ctx, string $binario, float $custo): bool
    {
        $base = defined('IA_STORAGE_PATH') ? rtrim(IA_STORAGE_PATH, '/') : rtrim(dirname(__DIR__, 3), '/') . '/storage/ia';
        $dir  = $base . '/recortes';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }
        $caminho = $dir . '/' . $g['uuid'] . '-recorte.png';
        if (file_put_contents($caminho, $binario, LOCK_EX) === false) {
            return false;
        }

        $gFake = $g;
        $gFake['contexto'] = json_encode($ctx['recorte'] ?? [], JSON_UNESCAPED_SLASHES);
        (new IARecorteService())->gravarCache($gFake, $caminho, (string) ($g['modelo_codigo'] ?? null));

        $this->custo->registrarRollup((int) $g['usuario_id'], (string) ($g['provedor_codigo'] ?? 'replicate'), 'remocao_fundo', $custo, false);
        return true;
    }

    private function recorteBinario(array $ctx): ?string
    {
        try {
            $imgId = (int) ($ctx['recorte']['produto_imagem_id'] ?? 0);
            $stmt  = $this->db->prepare('SELECT caminho_png FROM ia_recortes_produto WHERE produto_imagem_id = :i LIMIT 1');
            $stmt->execute([':i' => $imgId]);
            $caminho = $stmt->fetchColumn();
            if (!is_string($caminho) || !is_file($caminho)) {
                return null;
            }
            $bin = file_get_contents($caminho);
            return $bin !== false ? $bin : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function recorteEmCache(int $produtoImagemId, string $hash): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT caminho_png, hash_origem FROM ia_recortes_produto WHERE produto_imagem_id = :i LIMIT 1'
            );
            $stmt->execute([':i' => $produtoImagemId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return $r && $r['hash_origem'] === $hash && is_file((string) $r['caminho_png']);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Layouts prontos para o select do painel de geração. */
    public function listarLayouts(): array
    {
        try {
            return $this->db->query(
                "SELECT codigo, nome, largura, altura FROM ia_layouts
                  WHERE ativo = 1 AND camadas IS NOT NULL AND camadas <> ''
               ORDER BY nome ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function layoutPorCodigo(string $codigo): ?array
    {
        if ($codigo === '') {
            return null;
        }
        try {
            $stmt = $this->db->prepare("SELECT * FROM ia_layouts WHERE codigo = :c AND ativo = 1 LIMIT 1");
            $stmt->execute([':c' => $codigo]);
            $l = $stmt->fetch(PDO::FETCH_ASSOC);
            return $l ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Zona explícita > zona com dimensões ideais iguais ao layout > primeira ativa. */
    private function zonaCompativel(?array $layout, int $zonaId): ?array
    {
        try {
            if ($zonaId > 0) {
                $stmt = $this->db->prepare('SELECT * FROM banner_zonas WHERE id = :i AND ativo = 1 LIMIT 1');
                $stmt->execute([':i' => $zonaId]);
                $z = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($z) {
                    return $z;
                }
            }
            if ($layout !== null) {
                $stmt = $this->db->prepare(
                    'SELECT * FROM banner_zonas WHERE ativo = 1 AND largura_ideal = :w AND altura_ideal = :h ORDER BY ordem, id LIMIT 1'
                );
                $stmt->execute([':w' => (int) $layout['largura'], ':h' => (int) $layout['altura']]);
                $z = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($z) {
                    return $z;
                }
            }
            $z = $this->db->query('SELECT * FROM banner_zonas WHERE ativo = 1 ORDER BY ordem, id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            return $z ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function proporcaoDoLayout(int $w, int $h): string
    {
        $r = $w / max(1, $h);
        if ($r >= 1.15) {
            return '3:2';
        }
        if ($r <= 0.87) {
            return '2:3';
        }
        return '1:1';
    }

    private function ctx(array $g): array
    {
        $ctx = json_decode((string) ($g['contexto'] ?? ''), true);
        return is_array($ctx) ? $ctx : [];
    }

    private function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
