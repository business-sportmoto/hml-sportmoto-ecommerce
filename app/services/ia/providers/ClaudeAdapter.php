<?php
/**
 * ClaudeAdapter — Anthropic Claude (Messages API, /v1/messages) no orquestrador.
 *
 * Mesmo desenho dos demais adapters: HTTP cru pelo httpJson() da base, sem
 * retry interno (quem retenta e faz fallback é o orquestrador, com registro em
 * ia_roteamento_log), custo por token lançado no rollup pelo custo_config.
 *
 * Decisões que vêm da API, não deste código:
 *  - Auth pelo header `x-api-key` + `anthropic-version` (a base manda Bearer;
 *    aqui é sobreposto).
 *  - `thinking` é OMITIDO de propósito: no Opus 5 e no Sonnet 5 o modo
 *    adaptativo já vem ligado por padrão, e o Haiku 4.5 (mais antigo) só
 *    aceita `budget_tokens` — omitir é o único ajuste que vale nos três.
 *  - Sem prefill de assistant (400 nos modelos atuais): a conversa é sempre
 *    um único turno `user`.
 *  - `stop_reason: "refusal"` é resultado, não erro de rede — vira
 *    content_filter NÃO-retryable (outro modelo tende a recusar igual), com a
 *    categoria de stop_details na mensagem.
 *  - Saída JSON: a API tem structured outputs (output_config.format com
 *    json_schema), mas o job só carrega a flag `saida_json`, não um schema.
 *    Por ora reforça a instrução no system prompt e deixa o decodificador
 *    tolerante do consumidor fazer o resto. Plumbar um schema por tipo de
 *    conteúdo é o próximo degrau.
 *
 * params_padrao do catálogo: `effort` vai para output_config.effort (low |
 * medium | high | xhigh | max); o resto entra no topo do payload, e o payload
 * base prevalece. Atenção: `temperature`/`top_p`/`top_k` foram REMOVIDOS no
 * Opus 5 e no Sonnet 5 — configurá-los no catálogo devolve HTTP 400.
 */
class ClaudeAdapter extends IAProviderBase
{
    private const VERSAO_API      = '2023-06-01';
    private const MAX_TOKENS_TETO = 16000;
    private const MAX_TOKENS_PADRAO = 4096;
    private const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function codigo(): string
    {
        return 'claude';
    }

    /** x-api-key + anthropic-version — sobrepõe o Bearer padrão da base. */
    protected function cabecalhos(): array
    {
        $versao = (string) ($this->configExtra['anthropic_version'] ?? self::VERSAO_API);
        return [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . $versao,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /** Lista de modelos: valida chave e conectividade sem gerar nada (sem custo). */
    public function testarConexao(): IAResultado
    {
        $resp = $this->httpJson('GET', '/models', null, 15);

        if ($resp['status'] === 200 && is_array($resp['corpo']['data'] ?? null)) {
            return IAResultado::sucesso('Claude acessível — catálogo de modelos respondendo.');
        }

        if (in_array($resp['status'], [401, 403], true)) {
            return IAResultado::falha('chave_invalida', 'Chave recusada pela Anthropic (401/403).', false);
        }

        [, $msg] = $this->extrairErro($resp['corpo'], (int) $resp['status']);
        return IAResultado::falha('conexao', 'Claude indisponível: ' . ($resp['erro'] ?? $msg), true);
    }

    public function gerarTexto(array $job): IAResultado
    {
        $payload = $this->montarPayload($job);

        $resp = $this->httpJson('POST', '/messages', $payload, (int) ($job['timeout_s'] ?? 60));

        if ($resp['status'] === 0) {
            $r = IAResultado::falha('rede', 'Sem resposta do provedor: ' . ($resp['erro'] ?? 'falha de rede'), true);
            $r->tempoMs = $resp['tempo_ms'];
            return $r;
        }

        if ($resp['status'] !== 200 || !is_array($resp['corpo'])) {
            return $this->falhaHttp($resp);
        }

        $corpo = $resp['corpo'];

        // Recusa por política: HTTP 200 com stop_reason=refusal. Resultado, não
        // falha de transporte — e não adianta reencaminhar para outro modelo.
        if (($corpo['stop_reason'] ?? '') === 'refusal') {
            $categoria = (string) ($corpo['stop_details']['category'] ?? 'não informada');
            $r = IAResultado::falha('content_filter', 'Claude recusou a solicitação (categoria: ' . $categoria . ').', false);
            $r->tempoMs = $resp['tempo_ms'];
            $r->respostaBruta = $resp['corpo_bruto'];
            return $r;
        }

        // content[] é polimórfico: só os blocos `text` interessam (thinking fica de fora).
        $texto = '';
        foreach (($corpo['content'] ?? []) as $bloco) {
            if (($bloco['type'] ?? '') === 'text') {
                $texto .= (string) ($bloco['text'] ?? '');
            }
        }
        $texto = trim($texto);

        if ($texto === '') {
            $r = IAResultado::falha('sem_conteudo', 'Claude retornou texto vazio (stop_reason: ' . ($corpo['stop_reason'] ?? '?') . ').', true);
            $r->tempoMs = $resp['tempo_ms'];
            $r->respostaBruta = $resp['corpo_bruto'];
            return $r;
        }

        $r = IAResultado::sucesso($texto);
        // usage.input_tokens exclui o que veio do cache de prompt; o rollup do
        // módulo é por token plano, então é a contagem honesta do que foi cobrado
        // a preço cheio. Leituras de cache (10% do preço) ficam fora por ora.
        $r->tokensIn      = isset($corpo['usage']['input_tokens'])  ? (int) $corpo['usage']['input_tokens']  : null;
        $r->tokensOut     = isset($corpo['usage']['output_tokens']) ? (int) $corpo['usage']['output_tokens'] : null;
        $r->tempoMs       = $resp['tempo_ms'];
        $r->respostaBruta = $resp['corpo_bruto'];
        return $r;
    }

    /** HTTP ≠ 200 → IAResultado, com a tabela de retentáveis da API. */
    private function falhaHttp(array $resp): IAResultado
    {
        [$tipo, $msg] = $this->extrairErro($resp['corpo'], (int) $resp['status']);
        // Tabela da API: 429/500/529 retentáveis; 400/401/403/404/413 não.
        $codigo = match (true) {
            $resp['status'] === 429                     => 'rate_limit',
            in_array($resp['status'], [401, 403], true) => 'chave_invalida',
            $resp['status'] === 529                     => 'sobrecarga',
            default                                     => 'claude_' . $resp['status'],
        };
        $retryable = in_array($resp['status'], [429, 500, 529], true) || $resp['status'] >= 500;
        $r = IAResultado::falha($codigo, 'Claude (' . $tipo . '): ' . $msg, $retryable);
        $r->tempoMs = $resp['tempo_ms'];
        $r->respostaBruta = $resp['corpo_bruto'];
        return $r;
    }

    /* ------------------------------------------------------------------ */
    /* Tool use — capacidade agente                                        */
    /* ------------------------------------------------------------------ */

    public function suportaFerramentas(): bool
    {
        return true;
    }

    /**
     * Um turno com ferramentas. Quem itera (executa tool_use, monta
     * tool_result, chama de novo) é o orquestrador; aqui é UMA chamada
     * ao /v1/messages devolvendo stop_reason + blocos crus.
     *
     * O que muda em relação a gerarTexto():
     *  - `messages` vem pronto do job (multi-turno, com tool_use/tool_result);
     *  - `tools` com strict:true — o modelo não consegue inventar parâmetro;
     *  - cache de prompt: um breakpoint no `system` (cobre tools + system,
     *    que são estáveis por agente) e outro no último bloco da última
     *    mensagem (a conversa cresce por prefixo — cada rodada reaproveita
     *    a anterior). usage.cache_read_input_tokens diz se pegou;
     *  - `tool_choice: none` quando o loop esgotou as rodadas — força a
     *    resposta em texto sem tirar as definições (a API exige que as
     *    ferramentas dos tool_use do histórico continuem declaradas).
     */
    public function conversar(array $job): IAResultado
    {
        $payload = $this->montarPayloadConversa($job);

        $resp = $this->httpJson('POST', '/messages', $payload, (int) ($job['timeout_s'] ?? 120));

        if ($resp['status'] === 0) {
            $r = IAResultado::falha('rede', 'Sem resposta do provedor: ' . ($resp['erro'] ?? 'falha de rede'), true);
            $r->tempoMs = $resp['tempo_ms'];
            return $r;
        }
        if ($resp['status'] !== 200 || !is_array($resp['corpo'])) {
            return $this->falhaHttp($resp);
        }

        $corpo = $resp['corpo'];

        if (($corpo['stop_reason'] ?? '') === 'refusal') {
            $categoria = (string) ($corpo['stop_details']['category'] ?? 'não informada');
            $r = IAResultado::falha('content_filter', 'Claude recusou a solicitação (categoria: ' . $categoria . ').', false);
            $r->tempoMs = $resp['tempo_ms'];
            $r->respostaBruta = $resp['corpo_bruto'];
            return $r;
        }

        [$blocos, $texto] = $this->blocosDaResposta($corpo);

        $r = IAResultado::sucesso(trim($texto));
        $r->stopReason         = (string) ($corpo['stop_reason'] ?? 'end_turn');
        $r->blocos             = $blocos;
        $r->tokensIn           = isset($corpo['usage']['input_tokens'])  ? (int) $corpo['usage']['input_tokens']  : null;
        $r->tokensOut          = isset($corpo['usage']['output_tokens']) ? (int) $corpo['usage']['output_tokens'] : null;
        $r->tokensCacheLeitura = isset($corpo['usage']['cache_read_input_tokens'])     ? (int) $corpo['usage']['cache_read_input_tokens']     : null;
        $r->tokensCacheCriacao = isset($corpo['usage']['cache_creation_input_tokens']) ? (int) $corpo['usage']['cache_creation_input_tokens'] : null;
        $r->tempoMs            = $resp['tempo_ms'];
        $r->respostaBruta      = $resp['corpo_bruto'];
        return $r;
    }

    /**
     * content[] da resposta → blocos reenviáveis + texto concatenado.
     * Separado para ser testável sem rede.
     *
     * ⚠ `input` de ferramenta SEM parâmetros chega como `{}`; decodificado
     * vira array vazio, e `json_encode([])` reenvia `[]` — a API responde
     * 400 "tool_use.input: Input should be an object". Vazio fica stdClass.
     *
     * @return array{0: array, 1: string}
     */
    protected function blocosDaResposta(array $corpo): array
    {
        $blocos = [];
        $texto  = '';
        foreach (($corpo['content'] ?? []) as $bloco) {
            $tipo = (string) ($bloco['type'] ?? '');
            if ($tipo === 'text') {
                $texto .= (string) ($bloco['text'] ?? '');
                $blocos[] = ['type' => 'text', 'text' => (string) ($bloco['text'] ?? '')];
            } elseif ($tipo === 'tool_use') {
                $input = $bloco['input'] ?? null;
                $blocos[] = ['type' => 'tool_use', 'id' => (string) ($bloco['id'] ?? ''),
                             'name' => (string) ($bloco['name'] ?? ''),
                             'input' => (is_array($input) && $input !== []) ? $input : new stdClass()];
            }
            // thinking fica de fora: não é reenviado nem exibido.
        }
        return [$blocos, $texto];
    }

    /** Payload do turno com ferramentas. Separado para ser testável sem rede. */
    protected function montarPayloadConversa(array $job): array
    {
        $maxTokens = (int) ($job['max_tokens'] ?? 0);
        if ($maxTokens <= 0) {
            $maxTokens = self::MAX_TOKENS_PADRAO;
        }

        $mensagens = is_array($job['mensagens'] ?? null) ? array_values($job['mensagens']) : [];

        // Defesa em profundidade da mesma regra de blocosDaResposta(): um
        // histórico montado por outro caminho (persistido, replay) pode
        // trazer tool_use com input []. Aqui é a última porta antes do JSON.
        foreach ($mensagens as &$msg) {
            if (!is_array($msg['content'] ?? null)) continue;
            foreach ($msg['content'] as &$bl) {
                if (is_array($bl) && ($bl['type'] ?? '') === 'tool_use' && ($bl['input'] ?? null) === []) {
                    $bl['input'] = new stdClass();
                }
            }
            unset($bl);
        }
        unset($msg);

        // Breakpoint de cache no último bloco da última mensagem: a
        // próxima rodada (ou a próxima pergunta) reaproveita tudo até aqui.
        $ultima = count($mensagens) - 1;
        if ($ultima >= 0) {
            $c = $mensagens[$ultima]['content'];
            if (is_string($c)) {
                $mensagens[$ultima]['content'] = [['type' => 'text', 'text' => $c, 'cache_control' => ['type' => 'ephemeral']]];
            } elseif (is_array($c) && $c !== []) {
                $mensagens[$ultima]['content'][count($c) - 1]['cache_control'] = ['type' => 'ephemeral'];
            }
        }

        $payload = [
            'model'      => (string) $job['modelo_codigo'],
            'max_tokens' => max(64, min($maxTokens, self::MAX_TOKENS_TETO)),
            'messages'   => $mensagens,
        ];

        $sistema = trim((string) ($job['instrucoes'] ?? ''));
        if ($sistema !== '') {
            // Bloco com cache_control: cobre tools + system (render order
            // tools → system → messages), o prefixo estável do agente.
            $payload['system'] = [['type' => 'text', 'text' => $sistema, 'cache_control' => ['type' => 'ephemeral']]];
        }

        $ferramentas = is_array($job['ferramentas'] ?? null) ? array_values($job['ferramentas']) : [];
        if ($ferramentas !== []) {
            $payload['tools'] = $ferramentas;
            if (!empty($job['sem_ferramentas'])) {
                $payload['tool_choice'] = ['type' => 'none'];
            }
        }

        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        if (isset($params['effort'])) {
            $effort = strtolower((string) $params['effort']);
            if (in_array($effort, self::EFFORTS, true)) {
                $payload['output_config'] = ['effort' => $effort];
            }
            unset($params['effort']);
        }
        foreach ($params as $chave => $valor) {
            if (!array_key_exists($chave, $payload)) {
                $payload[$chave] = $valor;
            }
        }

        return $payload;
    }

    /**
     * Payload do /v1/messages a partir do job do orquestrador. Separado para
     * ser testável sem rede.
     */
    protected function montarPayload(array $job): array
    {
        $maxTokens = (int) ($job['max_tokens'] ?? 0);
        if ($maxTokens <= 0) {
            $maxTokens = self::MAX_TOKENS_PADRAO;
        }

        $payload = [
            'model'      => (string) $job['modelo_codigo'],
            'max_tokens' => max(64, min($maxTokens, self::MAX_TOKENS_TETO)),
            'messages'   => [
                ['role' => 'user', 'content' => (string) $job['prompt']],
            ],
        ];

        $sistema = trim((string) ($job['instrucoes'] ?? ''));
        if (!empty($job['saida_json'])) {
            $sistema .= ($sistema !== '' ? "\n\n" : '')
                . 'Responda exclusivamente com um objeto JSON válido — sem markdown, sem cercas de código, sem texto antes ou depois.';
        }
        if ($sistema !== '') {
            $payload['system'] = $sistema;
        }

        $params = is_array($job['params'] ?? null) ? $job['params'] : [];

        // effort mora em output_config, não no topo.
        if (isset($params['effort'])) {
            $effort = strtolower((string) $params['effort']);
            if (in_array($effort, self::EFFORTS, true)) {
                $payload['output_config'] = ['effort' => $effort];
            }
            unset($params['effort']);
        }

        // Demais params_padrao entram no topo; o payload base prevalece.
        foreach ($params as $chave => $valor) {
            if (!array_key_exists($chave, $payload)) {
                $payload[$chave] = $valor;
            }
        }

        return $payload;
    }
}
