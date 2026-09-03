<?php
/**
 * IAGeracaoService — regras de negócio da fila de gerações (Fase 1: texto).
 *
 *  enfileirar(): valida tipo/produto, monta contexto+prompt, checa taxa e
 *  tetos de custo, calcula estimativa, gera chave_dedup e insere N variações.
 *
 *  concluir()/falhar(): chamados pelo worker — atualizam a geração, gravam o
 *  rollup de custo e persistem a resposta bruta em storage (auditoria).
 */
class IAGeracaoService
{
    private IAGeracao $modelo;
    private IAPromptBuilder $builder;
    private IACustoService $custo;

    private const MAX_PROMPT_CHARS = 30000;
    private const VARIACOES_PERMITIDAS = [1, 3, 5];

    public function __construct()
    {
        $this->modelo  = new IAGeracao();
        $this->builder = new IAPromptBuilder();
        $this->custo   = new IACustoService();
    }

    /* ------------------------------------------------------------------ */
    /* Enfileiramento                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * $entrada: usuario_id, produto_id, tipo_conteudo_id, angulo(?), briefing[],
     *           prompt_custom(?), variacoes(1|3|5), geracao_origem_id(?)
     * Retorna ['ok'=>bool, 'msg'=>?, 'uuids'=>string[], 'custo_estimado_usd'=>float]
     */
    public function enfileirar(array $entrada): array
    {
        $usuarioId = (int) ($entrada['usuario_id'] ?? 0);
        $produtoId = (int) ($entrada['produto_id'] ?? 0);
        $tipoId    = (int) ($entrada['tipo_conteudo_id'] ?? 0);
        $angulo    = trim((string) ($entrada['angulo'] ?? ''));
        $briefing  = is_array($entrada['briefing'] ?? null) ? $entrada['briefing'] : [];
        $custom    = trim((string) ($entrada['prompt_custom'] ?? ''));
        $variacoes = (int) ($entrada['variacoes'] ?? 1);
        $origemId  = (int) ($entrada['geracao_origem_id'] ?? 0);

        if ($usuarioId <= 0) {
            return ['ok' => false, 'msg' => 'Sessão inválida — faça login novamente.'];
        }
        if (!in_array($variacoes, self::VARIACOES_PERMITIDAS, true)) {
            return ['ok' => false, 'msg' => 'Quantidade de variações inválida.'];
        }

        $tipo = (new IATipoConteudo())->buscar($tipoId);
        if ($tipo === null || (int) $tipo['ativo'] !== 1) {
            return ['ok' => false, 'msg' => 'Tipo de conteúdo inválido ou inativo.'];
        }
        $capacidade = (string) $tipo['capacidade'];

        // Banner: pipeline próprio (recorte -> cena -> compositor), Fase 2C.
        if ($capacidade === 'composicao') {
            return (new IAComposicaoService())->enfileirarBanner($entrada, $tipo);
        }

        if (!in_array($capacidade, ['texto', 'imagem'], true)) {
            return ['ok' => false, 'msg' => 'Esta capacidade de mídia chega nas próximas fases.'];
        }

        // Proporção (só imagem) — vai para ia_geracoes.formato.
        // A lista aceita é a que o modelo PRIMÁRIO declara, não mais um trio
        // cravado: cadastrar um modelo com outro conjunto fazia a prediction
        // voltar em HTTP 422. Fora da lista, cai na primeira aceita.
        $proporcao = null;
        if ($capacidade === 'imagem') {
            $aceitas   = (new IAModelo())->proporcoesDaCapacidade('imagem');
            $proporcao = (string) ($entrada['proporcao'] ?? '');
            if (!in_array($proporcao, $aceitas, true)) {
                $proporcao = (string) reset($aceitas);
            }
            if ($variacoes > 3) {
                return ['ok' => false, 'msg' => 'Para imagem, gere no máximo 3 variações por vez.'];
            }
        }

        $contexto = $this->builder->montarContexto($produtoId);
        if ($contexto === null) {
            return ['ok' => false, 'msg' => 'Produto não encontrado ou removido.'];
        }

        $template = null;
        if ($angulo !== '' && $capacidade === 'texto') {
            $template = (new IAPromptTemplate())->buscarPorAngulo($angulo, $tipoId);
            if ($template === null) {
                return ['ok' => false, 'msg' => 'Ângulo criativo inválido.'];
            }
        }

        // Prompt final: custom do usuário (com placeholders resolvidos) ou montagem automática
        if ($custom !== '') {
            $promptFinal = $this->builder->substituirPlaceholders($custom, $contexto);
        } elseif ($capacidade === 'imagem') {
            $promptFinal = $this->builder->montarPromptImagem($contexto, $tipo, $briefing);
        } else {
            $promptFinal = $this->builder->montarPrompt($contexto, $tipo, $template, $briefing);
        }

        $promptFinal = trim($promptFinal);
        if ($promptFinal === '') {
            return ['ok' => false, 'msg' => 'O prompt não pode ficar vazio.'];
        }
        if (mb_strlen($promptFinal) > self::MAX_PROMPT_CHARS) {
            return ['ok' => false, 'msg' => 'Prompt longo demais (máximo ' . self::MAX_PROMPT_CHARS . ' caracteres).'];
        }

        // Foto do produto como referência (só imagem; FLUX.2 via Replicate)
        $imagemReferencia = null;
        if ($capacidade === 'imagem' && !empty($entrada['usar_referencia'])) {
            $imgRef = (new IARecorteService())->imagemDoProduto($produtoId);
            if ($imgRef === null) {
                return ['ok' => false, 'msg' => 'Produto sem imagem cadastrada para usar como referência.'];
            }
            if (empty($imgRef['url'])) {
                return ['ok' => false, 'msg' => 'A foto do produto não tem URL pública — não dá para usá-la como referência.'];
            }
            $imagemReferencia = (string) $imgRef['url'];
            $promptFinal .= "\nUse a imagem de referência fornecida: mantenha o produto idêntico ao da foto (forma, cores, rótulos, proporções).";
        }

        // Custo estimado (modelo primário da capacidade) e limites — barra ANTES de gastar
        if ($capacidade === 'imagem') {
            $custoUnitario = $this->custo->estimarImagem($this->custo->custoConfigPrimario('imagem'));
        } else {
            $custoUnitario = $this->custo->estimarTexto(
                $this->custo->custoConfigPrimarioTexto(),
                mb_strlen($promptFinal) + mb_strlen((string) ($tipo['instrucoes_sistema'] ?? '')),
                isset($tipo['max_tokens']) ? (int) $tipo['max_tokens'] : null
            );
        }

        $chk = $this->custo->podeGerar($usuarioId, $custoUnitario * $variacoes, $variacoes);
        if (!$chk['ok']) {
            return ['ok' => false, 'msg' => $chk['msg']];
        }

        // Snapshot de contexto (o que o modelo viu) + briefing para refazer/variações
        $contextoJson = json_encode([
            'produto'           => $contexto,
            'briefing'          => $briefing,
            'sistema'           => $tipo['instrucoes_sistema'] ?? null,
            'imagem_referencia' => $imagemReferencia,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $uuids  = [];
        $minuto = (int) floor(time() / 60);

        for ($i = 1; $i <= $variacoes; $i++) {
            $prompt = $promptFinal;
            if ($variacoes > 1) {
                $prompt .= ($capacidade === 'imagem')
                    ? "\nVariação {$i} de {$variacoes}: mude ângulo de câmera, enquadramento e ambientação."
                    : "\n\nVARIACAO: esta é a variação {$i} de {$variacoes} — entregue uma versão distinta das demais em abertura, estrutura e chamada.";
            }

            $dedup = hash('sha256', implode('|', [
                $usuarioId, $produtoId, $tipoId, $angulo, md5($prompt), $minuto, $i, $variacoes, $origemId,
            ]));

            $id = $this->modelo->criar([
                'uuid'                     => $this->uuidV4(),
                'usuario_id'               => $usuarioId,
                'produto_id'               => $produtoId,
                // Vínculo com a campanha (3A): é por ele que o driver conta
                // o que já foi gerado e sabe o que ainda falta do cross join.
                'campanha_id'              => (isset($entrada['campanha_id']) && (int) $entrada['campanha_id'] > 0)
                                                  ? (int) $entrada['campanha_id'] : null,
                'geracao_origem_id'        => $origemId > 0 ? $origemId : null,
                'tipo_conteudo_id'         => $tipoId,
                'capacidade'               => $capacidade,
                'formato'                  => $proporcao,
                'angulo'                   => $angulo !== '' ? $angulo : null,
                'prompt_template_id'       => $template !== null ? (int) $template['id'] : null,
                'prompt_template_snapshot' => $template !== null ? (string) $template['corpo'] : null,
                'prompt_final'             => $prompt,
                'contexto'                 => $contextoJson,
                // A campanha manda a própria chave: determinística por par
                // (campanha|produto|tipo|tentativa), o que torna re-rodar o
                // driver idempotente. Fora dela, vale o dedup por minuto.
                'chave_dedup'              => !empty($entrada['chave_dedup'])
                                                  ? (string) $entrada['chave_dedup'] : $dedup,
                'custo_estimado_usd'       => $custoUnitario,
            ]);

            if ($id === -1062) {
                return ['ok' => false, 'msg' => 'Geração idêntica enviada há instantes — aguarde o resultado no painel abaixo.'];
            }
            if ($id <= 0) {
                return ['ok' => false, 'msg' => 'Erro ao enfileirar a geração.'];
            }

            $uuids[] = $this->modelo->uuidDe($id);
        }

        LogService::audit('ia_geracao_enfileirada', [
            'usuario_id' => $usuarioId,
            'produto_id' => $produtoId,
            'tipo_id'    => $tipoId,
            'angulo'     => $angulo !== '' ? $angulo : null,
            'variacoes'  => $variacoes,
            'custo_estimado_usd' => round($custoUnitario * $variacoes, 6),
        ]);

        return [
            'ok'                 => true,
            'uuids'              => $uuids,
            'custo_estimado_usd' => round($custoUnitario * $variacoes, 6),
            'msg'                => $variacoes > 1 ? "{$variacoes} variações enfileiradas." : 'Geração enfileirada.',
        ];
    }

    /** Refazer/variação a partir de uma geração existente (mantém produto/tipo/briefing). */
    public function refazer(int $geracaoId, int $usuarioId, ?string $promptAjustado): array
    {
        $g = $this->modelo->buscarPorId($geracaoId);
        if ($g === null) {
            return ['ok' => false, 'msg' => 'Geração original não encontrada.'];
        }
        if ($g['produto_id'] === null) {
            return ['ok' => false, 'msg' => 'O produto original foi removido — gere a partir de outro produto.'];
        }

        $contexto = json_decode((string) $g['contexto'], true);
        $briefing = is_array($contexto['briefing'] ?? null) ? $contexto['briefing'] : [];

        return $this->enfileirar([
            'usuario_id'        => $usuarioId,
            'produto_id'        => (int) $g['produto_id'],
            'tipo_conteudo_id'  => (int) $g['tipo_conteudo_id'],
            'angulo'            => (string) ($g['angulo'] ?? ''),
            'briefing'          => $briefing,
            'prompt_custom'     => $promptAjustado !== null && trim($promptAjustado) !== ''
                                       ? $promptAjustado
                                       : (string) $g['prompt_final'],
            'variacoes'         => 1,
            'geracao_origem_id' => $geracaoId,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Conclusão (worker)                                                  */
    /* ------------------------------------------------------------------ */

    public function concluir(array $geracao, IAResultado $r): void
    {
        $capacidade = (string) ($geracao['capacidade'] ?? 'texto');

        // MÍDIA: persiste os binários ANTES de marcar concluída — sem arquivo não há conclusão.
        if (in_array($capacidade, ['imagem', 'remocao_fundo', 'composicao'], true)) {
            $caminhos = empty($r->imagens) ? [] : $this->salvarImagens($geracao, $r->imagens);
            if (empty($caminhos)) {
                $this->falhar($geracao, IAResultado::falha('salvar_arquivo', 'Imagem gerada, mas falhou ao gravar no storage.', false));
                return;
            }

            // Recorte de produto: alimenta o cache (nunca pagar duas vezes)
            if ($capacidade === 'remocao_fundo') {
                (new IARecorteService())->gravarCache($geracao, $caminhos[0], $r->modeloCodigo);
            }
        }

        $this->modelo->marcarConcluida((int) $geracao['id'], [
            'resultado_texto' => ($r->texto !== null && $r->texto !== '') ? (string) $r->texto : null,
            'modelo_id'       => $r->modeloId,
            'provedor_codigo' => $r->provedorCodigo,
            'modelo_codigo'   => $r->modeloCodigo,
            'tokens_in'       => $r->tokensIn,
            'tokens_out'      => $r->tokensOut,
            'tempo_ms'        => $r->tempoMs,
            'custo_real_usd'  => $r->custoRealUsd,
        ]);

        // Composição já lançou o rollup POR ETAPA (remocao_fundo + imagem);
        // lançar de novo aqui contaria o mesmo gasto duas vezes.
        if ($capacidade !== 'composicao') {
            $this->custo->registrarRollup(
                (int) $geracao['usuario_id'],
                (string) ($r->provedorCodigo ?? 'desconhecido'),
                (string) $geracao['capacidade'],
                (float) ($r->custoRealUsd ?? $geracao['custo_estimado_usd'] ?? 0),
                false
            );
        }

        $this->salvarRespostaBruta($geracao, $r);
    }

    /** Provedor assíncrono aceitou o job — a geração espera webhook/varredura. */
    public function aguardar(array $geracao, IAResultado $r): void
    {
        $this->modelo->marcarAguardando(
            (int) $geracao['id'],
            (string) $r->externalId,
            $r->modeloId,
            $r->provedorCodigo,
            $r->modeloCodigo
        );
    }

    /**
     * Caminho ÚNICO de conclusão assíncrona — chamado pelo webhook E pela
     * varredura do worker. Idempotente: só age se a geração ainda estiver
     * em aguardando_provedor (releituras/duplicatas viram no-op).
     *
     * Retorna: 'concluida' | 'falhou' | 'pendente' | 'ignorado'
     */
    public function processarRetornoProvedor(array $geracao, array $remoto, ReplicateAdapter $adapter): string
    {
        // Banner: quem decide o próximo passo da etapa é o pipeline da 2C.
        if (($geracao['capacidade'] ?? '') === 'composicao') {
            return (new IAComposicaoService())->processarRetorno($geracao, $remoto, $adapter);
        }

        if (($geracao['status'] ?? '') !== 'aguardando_provedor') {
            return 'ignorado'; // já resolvida por outro caminho
        }

        $statusRemoto = (string) ($remoto['status'] ?? '');

        if (in_array($statusRemoto, ['starting', 'processing', 'consulta_falhou', ''], true)) {
            return 'pendente'; // ainda rodando (ou consulta instável) — próxima varredura tenta de novo
        }

        if (in_array($statusRemoto, ['failed', 'canceled'], true)) {
            $erro = is_string($remoto['error'] ?? null) ? $remoto['error'] : 'Prediction falhou no provedor.';
            $rf = IAResultado::falha('provedor_' . $statusRemoto, $erro, false);
            $rf->provedorCodigo = (string) ($geracao['provedor_codigo'] ?? 'replicate');
            $this->falhar($geracao, $rf);
            return 'falhou';
        }

        // succeeded — baixar IMEDIATAMENTE (URLs de entrega expiram em ~1h)
        $urls = $adapter->extrairUrlsSaida($remoto['output'] ?? null);
        if (empty($urls)) {
            $rf = IAResultado::falha('sem_saida', 'Prediction concluída sem output.', false);
            $rf->provedorCodigo = (string) ($geracao['provedor_codigo'] ?? 'replicate');
            $this->falhar($geracao, $rf);
            return 'falhou';
        }

        $imagens = [];
        foreach (array_slice($urls, 0, 4) as $url) {
            $dl = $adapter->baixarSaida($url);
            if (!$dl['ok']) {
                LogService::warning('ia_download_saida_falhou', [
                    'geracao_id' => (int) $geracao['id'],
                    'erro'       => $dl['erro'],
                ]);
                return 'pendente'; // não marca nada — a varredura refaz o download
            }
            $imagens[] = ['binario' => $dl['binario'], 'mime' => $dl['mime'], 'extensao' => $dl['extensao']];
        }

        $r = IAResultado::sucessoImagem($imagens);
        $r->modeloId       = isset($geracao['modelo_id']) ? (int) $geracao['modelo_id'] : null;
        $r->provedorCodigo = (string) ($geracao['provedor_codigo'] ?? 'replicate');
        $r->modeloCodigo   = (string) ($geracao['modelo_codigo'] ?? '');
        $r->custoRealUsd   = $this->custo->custoRealImagemPorModelo($r->modeloId);
        $r->tempoMs        = isset($remoto['metrics']['predict_time'])
            ? (int) round(((float) $remoto['metrics']['predict_time']) * 1000)
            : 0;

        $this->concluir($geracao, $r);
        return 'concluida';
    }

    public function falhar(array $geracao, IAResultado $r): void
    {
        $this->modelo->marcarFalha(
            (int) $geracao['id'],
            trim(($r->erroCodigo ? '[' . $r->erroCodigo . '] ' : '') . (string) $r->erro),
            $r->tempoMs
        );

        $this->custo->registrarRollup(
            (int) $geracao['usuario_id'],
            (string) ($r->provedorCodigo ?? 'desconhecido'),
            (string) $geracao['capacidade'],
            0.0,
            true
        );
    }

    /* ------------------------------------------------------------------ */
    /* Consultas                                                           */
    /* ------------------------------------------------------------------ */

    /** Status em lote para o polling (máx. 20 uuids por chamada). */
    public function statusLote(array $uuids): array
    {
        $uuids = array_slice(array_values(array_filter(array_map('trim', $uuids))), 0, 20);
        if (empty($uuids)) {
            return [];
        }
        return $this->modelo->statusPorUuids($uuids);
    }

    /* ------------------------------------------------------------------ */
    /* Internos                                                            */
    /* ------------------------------------------------------------------ */

    /** Grava binários em IA_STORAGE_PATH/imagens/AAAA/MM e indexa em ia_arquivos. Retorna os caminhos gravados. */
    private function salvarImagens(array $geracao, array $imagens): array
    {
        try {
            $base = defined('IA_STORAGE_PATH')
                ? rtrim(IA_STORAGE_PATH, '/')
                : rtrim(dirname(__DIR__, 3), '/') . '/storage/ia';

            $dir = $base . '/imagens/' . date('Y/m');
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                LogService::error('ia_storage_imagens_indisponivel', ['dir' => $dir]);
                return [];
            }

            $caminhos = [];
            foreach (array_values($imagens) as $i => $img) {
                if (empty($img['binario'])) {
                    continue;
                }
                $sufixo  = (count($imagens) > 1) ? '-' . ($i + 1) : '';
                $caminho = $dir . '/' . $geracao['uuid'] . $sufixo . '.' . ($img['extensao'] ?? 'png');

                if (file_put_contents($caminho, $img['binario'], LOCK_EX) === false) {
                    LogService::error('ia_gravar_imagem_falhou', ['caminho' => $caminho]);
                    continue;
                }

                $this->modelo->registrarArquivo(
                    (int) $geracao['id'],
                    'imagem',
                    $caminho,
                    (string) ($img['mime'] ?? 'image/png'),
                    strlen($img['binario']),
                    hash('sha256', $img['binario'])
                );
                $caminhos[] = $caminho;
            }

            return $caminhos;
        } catch (Throwable $e) {
            LogService::error('ia_salvar_imagens_erro', ['geracao_id' => (int) $geracao['id'], 'erro' => $e->getMessage()]);
            return [];
        }
    }

    /** Resposta bruta do provedor vai para storage (fora do webroot) + ia_arquivos. */
    private function salvarRespostaBruta(array $geracao, IAResultado $r): void
    {
        if ($r->respostaBruta === null || $r->respostaBruta === '') {
            return;
        }

        try {
            $base = defined('IA_STORAGE_PATH')
                ? rtrim(IA_STORAGE_PATH, '/')
                : rtrim(dirname(__DIR__, 3), '/') . '/storage/ia'; // AJUSTE: defina IA_STORAGE_PATH no config

            $dir = $base . '/respostas/' . date('Y/m');
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                LogService::warning('ia_storage_indisponivel', ['dir' => $dir]);
                return;
            }

            $caminho = $dir . '/' . $geracao['uuid'] . '.json';
            file_put_contents($caminho, $r->respostaBruta, LOCK_EX);

            $this->modelo->registrarArquivo(
                (int) $geracao['id'],
                'json',
                $caminho,
                'application/json',
                strlen($r->respostaBruta),
                hash('sha256', $r->respostaBruta)
            );
        } catch (Throwable $e) {
            LogService::warning('ia_resposta_bruta_erro', ['geracao_id' => (int) $geracao['id'], 'erro' => $e->getMessage()]);
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
