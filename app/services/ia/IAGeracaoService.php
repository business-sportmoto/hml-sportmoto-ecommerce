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
        if ($tipo['capacidade'] !== 'texto') {
            return ['ok' => false, 'msg' => 'Este tipo usa geração de mídia — disponível na Fase 2.'];
        }

        $contexto = $this->builder->montarContexto($produtoId);
        if ($contexto === null) {
            return ['ok' => false, 'msg' => 'Produto não encontrado ou removido.'];
        }

        $template = null;
        if ($angulo !== '') {
            $template = (new IAPromptTemplate())->buscarPorAngulo($angulo, $tipoId);
            if ($template === null) {
                return ['ok' => false, 'msg' => 'Ângulo criativo inválido.'];
            }
        }

        // Prompt final: custom do usuário (com placeholders resolvidos) ou montagem automática
        if ($custom !== '') {
            $promptFinal = $this->builder->substituirPlaceholders($custom, $contexto);
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

        // Custo estimado (modelo primário) e limites — barra ANTES de gastar
        $custoUnitario = $this->custo->estimarTexto(
            $this->custo->custoConfigPrimarioTexto(),
            mb_strlen($promptFinal) + mb_strlen((string) ($tipo['instrucoes_sistema'] ?? '')),
            isset($tipo['max_tokens']) ? (int) $tipo['max_tokens'] : null
        );

        $chk = $this->custo->podeGerar($usuarioId, $custoUnitario * $variacoes, $variacoes);
        if (!$chk['ok']) {
            return ['ok' => false, 'msg' => $chk['msg']];
        }

        // Snapshot de contexto (o que o modelo viu) + briefing para refazer/variações
        $contextoJson = json_encode([
            'produto'  => $contexto,
            'briefing' => $briefing,
            'sistema'  => $tipo['instrucoes_sistema'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $uuids  = [];
        $minuto = (int) floor(time() / 60);

        for ($i = 1; $i <= $variacoes; $i++) {
            $prompt = $promptFinal;
            if ($variacoes > 1) {
                $prompt .= "\n\nVARIACAO: esta é a variação {$i} de {$variacoes} — entregue uma versão distinta das demais em abertura, estrutura e chamada.";
            }

            $dedup = hash('sha256', implode('|', [
                $usuarioId, $produtoId, $tipoId, $angulo, md5($prompt), $minuto, $i, $variacoes, $origemId,
            ]));

            $id = $this->modelo->criar([
                'uuid'                     => $this->uuidV4(),
                'usuario_id'               => $usuarioId,
                'produto_id'               => $produtoId,
                'campanha_id'              => null,
                'geracao_origem_id'        => $origemId > 0 ? $origemId : null,
                'tipo_conteudo_id'         => $tipoId,
                'capacidade'               => 'texto',
                'angulo'                   => $angulo !== '' ? $angulo : null,
                'prompt_template_id'       => $template !== null ? (int) $template['id'] : null,
                'prompt_template_snapshot' => $template !== null ? (string) $template['corpo'] : null,
                'prompt_final'             => $prompt,
                'contexto'                 => $contextoJson,
                'chave_dedup'              => $dedup,
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
        $this->modelo->marcarConcluida((int) $geracao['id'], [
            'resultado_texto' => (string) $r->texto,
            'modelo_id'       => $r->modeloId,
            'provedor_codigo' => $r->provedorCodigo,
            'modelo_codigo'   => $r->modeloCodigo,
            'tokens_in'       => $r->tokensIn,
            'tokens_out'      => $r->tokensOut,
            'tempo_ms'        => $r->tempoMs,
            'custo_real_usd'  => $r->custoRealUsd,
        ]);

        $this->custo->registrarRollup(
            (int) $geracao['usuario_id'],
            (string) ($r->provedorCodigo ?? 'desconhecido'),
            (string) $geracao['capacidade'],
            (float) ($r->custoRealUsd ?? $geracao['custo_estimado_usd'] ?? 0),
            false
        );

        $this->salvarRespostaBruta($geracao, $r);
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
