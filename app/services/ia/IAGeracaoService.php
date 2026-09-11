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

    /** Frase que prende o vídeo ao produto quando a foto é o primeiro quadro. */
    private const FRASE_PRIMEIRO_QUADRO = 'Animate the provided first frame. Keep the product identical to it: same shape, colors, graphics and logos.';

    /** Orquestrador injetável (testes) — a reserva de vídeo reenvia por ele. */
    private ?IAOrchestrator $orq;

    public function __construct(?IAOrchestrator $orq = null)
    {
        $this->modelo  = new IAGeracao();
        $this->builder = new IAPromptBuilder();
        $this->custo   = new IACustoService();
        $this->orq     = $orq;
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

        if (!in_array($capacidade, ['texto', 'imagem', 'video'], true)) {
            return ['ok' => false, 'msg' => 'Esta capacidade de mídia chega nas próximas fases.'];
        }

        // IA escolhida na tela: vai na FRENTE da fila (o pino do tipo e a
        // prioridade viram reserva). O id vem do navegador — revalidado:
        // ativo, provedor ativo com chave, mesma capacidade do tipo.
        $escolhido   = null;
        $escolhidoId = (int) ($entrada['modelo_id'] ?? 0);
        if ($escolhidoId > 0) {
            $escolhido = (new IAModelo())->usavel($escolhidoId, $capacidade);
            if ($escolhido === null) {
                return ['ok' => false, 'msg' => 'A IA escolhida não está disponível para este tipo de conteúdo — escolha outra ou deixe no automático.'];
            }
            $tipo['modelo_id'] = (int) $escolhido['id'];
        }

        // Proporção (só imagem) — vai para ia_geracoes.formato.
        // A lista aceita é a que o modelo PRIMÁRIO declara, não mais um trio
        // cravado: cadastrar um modelo com outro conjunto fazia a prediction
        // voltar em HTTP 422. Fora da lista, cai na primeira aceita.
        $proporcao = null;
        if ($capacidade === 'imagem') {
            $aceitas   = $escolhido !== null
                ? IAModelo::meta($escolhido['params_padrao'] ?? null)['proporcoes']
                : (new IAModelo())->proporcoesDaCapacidade('imagem');
            $proporcao = (string) ($entrada['proporcao'] ?? '');
            if (!in_array($proporcao, $aceitas, true)) {
                $proporcao = (string) reset($aceitas);
            }
            if ($variacoes > 3) {
                return ['ok' => false, 'msg' => 'Para imagem, gere no máximo 3 variações por vez.'];
            }
        }

        // Vídeo: parâmetros pedidos, já ajustados ao modelo PRIMÁRIO (é o que
        // a tela ofereceu). O orquestrador reajusta se cair no fallback.
        $videoPedido = null;
        if ($capacidade === 'video') {
            if ($variacoes !== 1) {
                return ['ok' => false, 'msg' => 'Vídeo é gerado um por vez.'];
            }
            $opcVideo = (new IAModelo())->opcoesVideo();
            if ($opcVideo === null) {
                return ['ok' => false, 'msg' => 'Nenhum modelo de vídeo ativo com provedor configurado.'];
            }
            // Com IA escolhida, o pedido se ajusta a ELA (o Veo não faz 5 s).
            $metaVideo = $escolhido !== null
                ? IAModelo::metaVideo($escolhido['params_padrao'] ?? null)
                : $opcVideo['meta'];
            $ajuste = IAModelo::ajustarVideo(
                $metaVideo,
                (int) ($entrada['duracao'] ?? 0),
                (string) ($entrada['resolucao'] ?? ''),
                (string) ($entrada['proporcao_video'] ?? '')
            );
            $videoPedido = $ajuste + ['audio' => !empty($entrada['audio']) && $metaVideo['audio']];
            $proporcao   = $ajuste['proporcao'];
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

        // Prompt salvo da biblioteca: é um prompt COMPLETO — ocupa o lugar da
        // montagem automática. O id vem do navegador, então é revalidado aqui
        // (ativo, natureza prompt, mesma capacidade do tipo).
        $salvo   = null;
        $salvoId = (int) ($entrada['prompt_salvo_id'] ?? 0);
        if ($salvoId > 0) {
            $salvo = (new IAPromptTemplate())->buscarPromptUsavel($salvoId, $capacidade);
            if ($salvo === null) {
                return ['ok' => false, 'msg' => 'Prompt salvo inválido, desativado ou de outro tipo de geração.'];
            }
            // A tela já copiou o corpo para o campo de prompt, e a pessoa pode
            // ter editado. Campo vazio = usa o prompt salvo como está.
            if ($custom === '') {
                $custom = (string) $salvo['corpo'];
            }
        }

        // Prompt final: custom do usuário (com placeholders resolvidos) ou montagem automática
        if ($custom !== '') {
            $promptFinal = $this->builder->substituirPlaceholders($custom, $contexto);
        } elseif ($capacidade === 'imagem') {
            $promptFinal = $this->builder->montarPromptImagem($contexto, $tipo, $briefing);
        } elseif ($capacidade === 'video') {
            $promptFinal = $this->builder->montarPromptVideo($contexto, $tipo, $briefing);
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

        // Foto do produto: na imagem (FLUX.2) é referência; no vídeo é o
        // PRIMEIRO QUADRO — é o que prende o clipe ao produto que está à venda.
        $imagemReferencia = null;
        $querFoto = ($capacidade === 'imagem' && !empty($entrada['usar_referencia']))
                 || ($capacidade === 'video' && !empty($entrada['usar_foto']));
        if ($querFoto) {
            $imgRef = (new IARecorteService())->imagemDoProduto($produtoId);
            if ($imgRef === null) {
                return ['ok' => false, 'msg' => 'Produto sem imagem cadastrada para usar como referência.'];
            }
            if (empty($imgRef['url'])) {
                return ['ok' => false, 'msg' => 'A foto do produto não tem URL pública — não dá para usá-la como referência.'];
            }
            $imagemReferencia = (string) $imgRef['url'];
            if ($capacidade === 'video') {
                // Em inglês, como o resto do prompt de vídeo. Uma refação já
                // traz a frase no prompt anterior — não repete.
                if (!str_contains($promptFinal, self::FRASE_PRIMEIRO_QUADRO)) {
                    $promptFinal .= "\n" . self::FRASE_PRIMEIRO_QUADRO;
                }
            } else {
                $promptFinal .= "\nUse a imagem de referência fornecida: mantenha o produto idêntico ao da foto (forma, cores, rótulos, proporções).";
            }
        }

        // Custo estimado e limites — barra ANTES de gastar
        if ($capacidade === 'video') {
            $custoUnitario = $this->estimarVideoConservador($videoPedido, $tipo);
            if ($custoUnitario === null) {
                return ['ok' => false, 'msg' => 'O modelo de vídeo principal não tem preço cadastrado para '
                    . $videoPedido['resolucao'] . ' — sem preço o teto de gasto não consegue barrar.'];
            }
        } elseif ($capacidade === 'imagem') {
            $custoUnitario = $this->custo->estimarImagem($escolhido !== null
                ? $this->custo->custoConfigDoModelo((int) $escolhido['id'])
                : $this->custo->custoConfigPrimario('imagem'));
        } else {
            $custoUnitario = $this->custo->estimarTexto(
                $escolhido !== null
                    ? $this->custo->custoConfigDoModelo((int) $escolhido['id'])
                    : $this->custo->custoConfigPrimarioTexto(),
                mb_strlen($promptFinal) + mb_strlen((string) ($tipo['instrucoes_sistema'] ?? '')),
                isset($tipo['max_tokens']) ? (int) $tipo['max_tokens'] : null
            );
        }

        $chk = ($capacidade === 'video')
            ? $this->custo->podeGerarVideo($usuarioId, $custoUnitario)
            : $this->custo->podeGerar($usuarioId, $custoUnitario * $variacoes, $variacoes);
        if (!$chk['ok']) {
            return ['ok' => false, 'msg' => $chk['msg']];
        }

        // Snapshot de contexto (o que o modelo viu) + briefing para refazer/variações
        $snapshot = [
            'produto'           => $contexto,
            'briefing'          => $briefing,
            'sistema'           => $tipo['instrucoes_sistema'] ?? null,
            'imagem_referencia' => $imagemReferencia,
        ];
        if ($videoPedido !== null) {
            $snapshot['video'] = $videoPedido; // o orquestrador grava o efetivo ao lado
        }
        if ($escolhido !== null) {
            $snapshot['modelo_escolhido'] = (int) $escolhido['id']; // o worker põe na frente da fila
        }
        $contextoJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
                (int) ($escolhido['id'] ?? 0), // outra IA = outro pedido (comparar é legítimo)
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
                // Procedência do prompt: o salvo da biblioteca prevalece (foi ele
                // que ocupou o lugar da montagem); senão o ângulo, como antes.
                'prompt_template_id'       => $salvo !== null ? (int) $salvo['id']
                                            : ($template !== null ? (int) $template['id'] : null),
                'prompt_template_snapshot' => $salvo !== null ? (string) $salvo['corpo']
                                            : ($template !== null ? (string) $template['corpo'] : null),
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

        if ($salvo !== null) {
            (new IAPromptTemplate())->registrarUso((int) $salvo['id']);
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

        // A IA escolhida volta junto — se ainda puder rodar. Desativada desde
        // então, a refação cai no automático em vez de falhar.
        $escolha = (int) ($contexto['modelo_escolhido'] ?? 0);
        if ($escolha > 0 && (new IAModelo())->usavel($escolha, (string) $g['capacidade']) === null) {
            $escolha = 0;
        }

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
            // Vídeo refeito com os MESMOS parâmetros e a mesma foto — sem isto
            // a refação caía nos padrões e perdia o primeiro quadro.
            'duracao'           => (int) ($contexto['video']['duracao'] ?? 0),
            'resolucao'         => (string) ($contexto['video']['resolucao'] ?? ''),
            'proporcao_video'   => (string) ($contexto['video']['proporcao'] ?? ''),
            'audio'             => !empty($contexto['video']['audio']),
            'usar_foto'         => ($g['capacidade'] ?? '') === 'video' && !empty($contexto['imagem_referencia']),
            'modelo_id'         => $escolha,
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

        if ($capacidade === 'video') {
            $caminhos = empty($r->videos) ? [] : $this->salvarVideos($geracao, $r->videos);
            if (empty($caminhos)) {
                // Gerado e COBRADO: a falha é nossa (storage). O gasto entra no
                // rollup mesmo assim — falhar() lança custo zero, e o teto de
                // vídeo deixaria de ver dinheiro que já saiu.
                $this->modelo->marcarFalha((int) $geracao['id'],
                    '[salvar_arquivo] Vídeo gerado, mas falhou ao gravar no storage.', $r->tempoMs);
                $this->custo->registrarRollup(
                    (int) $geracao['usuario_id'],
                    (string) ($r->provedorCodigo ?? 'replicate'),
                    'video',
                    (float) ($r->custoRealUsd ?? $geracao['custo_estimado_usd'] ?? 0),
                    true
                );
                return;
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
     * Retorna: 'concluida' | 'falhou' | 'pendente' | 'ignorado' | 'reenviada'
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

            // Vídeo: a recusa costuma ser de POLÍTICA do modelo — em 11/09 o
            // Seedance aceitou, rodou 102 s e barrou por "possível restrição de
            // direitos autorais". A reserva existe para isso; sem ela o Refazer
            // mandava de novo para o mesmo modelo, que recusava igual.
            if (($geracao['capacidade'] ?? '') === 'video' && $statusRemoto === 'failed') {
                $re = $this->reenviarVideo($geracao, $erro);
                if ($re !== null) {
                    return $re;
                }
            }

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

        if (($geracao['capacidade'] ?? '') === 'video') {
            return $this->concluirVideoDoProvedor($geracao, $remoto, $adapter, (string) $urls[0]);
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

    /**
     * Reserva PÓS-aceite de vídeo: registra a recusa do modelo que rodou e
     * submete ao próximo da cadeia ainda não tentado NESTA geração.
     *
     * Devolve 'reenviada', 'ignorado' (o retorno é de uma prediction que já
     * foi substituída) ou null (não sobrou modelo — quem chamou falha a
     * geração com o erro original).
     *
     * O teto não é checado de novo: a estimativa do enfileiramento já é o
     * maior custo da cadeia inteira.
     */
    private function reenviarVideo(array $geracao, string $erro): ?string
    {
        // Relê a linha: a varredura e o webhook podem trazer uma cópia velha.
        // Se o external_id mudou, esta recusa é da prediction ANTIGA e a
        // reserva já foi acionada — reenviar de novo pagaria duas vezes.
        $atual = $this->modelo->buscarPorId((int) $geracao['id']);
        if ($atual === null || $atual['status'] !== 'aguardando_provedor'
            || (string) $atual['external_id'] !== (string) ($geracao['external_id'] ?? '')) {
            return 'ignorado';
        }

        $orq    = $this->orq ?? new IAOrchestrator();
        $recusou = (string) ($atual['modelo_codigo'] ?? '');
        $orq->logRoteamento((int) $atual['id'], [
            'prov_codigo'   => (string) ($atual['provedor_codigo'] ?? 'replicate'),
            'codigo_modelo' => $recusou,
        ], 'falha', 'recusa_pos_aceite', $erro, 0);

        $tipo = (new IATipoConteudo())->buscar((int) $atual['tipo_conteudo_id']);
        if ($tipo === null) {
            return null;
        }

        $r = $orq->executarVideo($atual, $tipo, $this->modelo->modelosTentados((int) $atual['id']));
        if (!$r->aguardando) {
            return null;
        }

        $this->aguardar($atual, $r);
        $this->modelo->renovarInicio((int) $atual['id']);
        LogService::warning('ia_video_reserva_acionada', [
            'geracao_id' => (int) $atual['id'],
            'recusou'    => $recusou,
            'reserva'    => $r->modeloCodigo,
            'motivo'     => mb_substr($erro, 0, 200),
        ]);
        return 'reenviada';
    }

    /**
     * Conclusão de VÍDEO vinda do provedor (varredura ou webhook). O custo
     * real sai dos parâmetros EFETIVOS gravados na submissão — o modelo que
     * rodou pode ter ajustado a duração pedida.
     */
    private function concluirVideoDoProvedor(array $geracao, array $remoto, ReplicateAdapter $adapter, string $url): string
    {
        $dl = $adapter->baixarSaida($url);
        if (!$dl['ok']) {
            LogService::warning('ia_download_video_falhou', [
                'geracao_id' => (int) $geracao['id'],
                'erro'       => $dl['erro'],
            ]);
            return 'pendente'; // não marca nada — a varredura refaz o download
        }

        $ctx = json_decode((string) ($geracao['contexto'] ?? ''), true);
        $ef  = is_array($ctx['video_efetivo'] ?? null) ? $ctx['video_efetivo'] : [];
        $dur = (int) ($ef['duracao'] ?? 0);

        $r = IAResultado::sucessoVideo([[
            'binario'   => $dl['binario'],
            'mime'      => $dl['mime'],
            'extensao'  => $dl['extensao'],
            'duracao_s' => $dur > 0 ? $dur : null,
        ]]);
        $r->modeloId       = isset($geracao['modelo_id']) ? (int) $geracao['modelo_id'] : null;
        $r->provedorCodigo = (string) ($geracao['provedor_codigo'] ?? 'replicate');
        $r->modeloCodigo   = (string) ($geracao['modelo_codigo'] ?? '');
        $r->custoRealUsd   = $this->custo->custoRealVideoPorModelo(
            $r->modeloId, $dur, (string) ($ef['resolucao'] ?? ''), !empty($ef['audio'])
        );
        $r->tempoMs = isset($remoto['metrics']['predict_time'])
            ? (int) round(((float) $remoto['metrics']['predict_time']) * 1000)
            : 0;

        $this->concluir($geracao, $r);
        return 'concluida';
    }

    /**
     * Estimativa de vídeo para BARRAR antes de gastar: o maior custo entre os
     * modelos que podem rodar, cada um com o pedido ajustado a ele — o
     * fallback pode cair num mais caro. null se o PRINCIPAL não tem preço.
     */
    private function estimarVideoConservador(array $pedido, array $tipo): ?float
    {
        $pino    = (int) ($tipo['modelo_id'] ?? 0) > 0 ? (int) $tipo['modelo_id'] : null;
        $modelos = (new IAOrchestrator())->modelosDaCapacidade('video', $pino);
        $maior   = null;

        foreach (array_values($modelos) as $i => $m) {
            $meta = IAModelo::metaVideo($m['params_padrao'] ?? null);
            $aj   = IAModelo::ajustarVideo($meta, (int) $pedido['duracao'], (string) $pedido['resolucao'], (string) $pedido['proporcao']);
            $cfg  = json_decode((string) ($m['custo_config'] ?? ''), true);
            $est  = $this->custo->estimarVideo(is_array($cfg) ? $cfg : null, $aj['duracao'], $aj['resolucao'],
                                               !empty($pedido['audio']) && $meta['audio']);
            if ($i === 0 && $est === null) {
                return null;
            }
            if ($est !== null && ($maior === null || $est > $maior)) {
                $maior = $est;
            }
        }
        return $maior;
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

    /**
     * Grava o vídeo em IA_STORAGE_PATH/videos/AAAA/MM e indexa em ia_arquivos
     * com a duração. Extensão e mime são forçados para vídeo: se a URL de
     * entrega viesse com Content-Type errado, o arquivo continuaria tocável.
     */
    private function salvarVideos(array $geracao, array $videos): array
    {
        try {
            $base = defined('IA_STORAGE_PATH')
                ? rtrim(IA_STORAGE_PATH, '/')
                : rtrim(dirname(__DIR__, 3), '/') . '/storage/ia';

            $dir = $base . '/videos/' . date('Y/m');
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                LogService::error('ia_storage_videos_indisponivel', ['dir' => $dir]);
                return [];
            }

            $caminhos = [];
            foreach (array_values($videos) as $i => $v) {
                if (empty($v['binario'])) {
                    continue;
                }
                $ext     = in_array($v['extensao'] ?? '', ['mp4', 'webm', 'mov'], true) ? $v['extensao'] : 'mp4';
                $mime    = str_starts_with((string) ($v['mime'] ?? ''), 'video/') ? (string) $v['mime'] : 'video/mp4';
                $sufixo  = (count($videos) > 1) ? '-' . ($i + 1) : '';
                $caminho = $dir . '/' . $geracao['uuid'] . $sufixo . '.' . $ext;

                if (file_put_contents($caminho, $v['binario'], LOCK_EX) === false) {
                    LogService::error('ia_gravar_video_falhou', ['caminho' => $caminho]);
                    continue;
                }

                $this->modelo->registrarArquivo(
                    (int) $geracao['id'],
                    'video',
                    $caminho,
                    $mime,
                    strlen($v['binario']),
                    hash('sha256', $v['binario']),
                    isset($v['duracao_s']) ? (int) $v['duracao_s'] : null
                );
                $caminhos[] = $caminho;
            }

            return $caminhos;
        } catch (Throwable $e) {
            LogService::error('ia_salvar_videos_erro', ['geracao_id' => (int) $geracao['id'], 'erro' => $e->getMessage()]);
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
