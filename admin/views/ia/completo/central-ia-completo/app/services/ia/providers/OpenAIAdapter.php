<?php
/**
 * OpenAIAdapter — texto via /chat/completions (Fase 1).
 * Imagem/edição entram na Fase 2 neste mesmo adapter.
 */
class OpenAIAdapter extends IAProviderBase
{
    public function codigo(): string
    {
        return 'openai';
    }

    public function testarConexao(): IAResultado
    {
        $resp = $this->httpJson('GET', '/models', null, 20);

        if ($resp['status'] === 200 && is_array($resp['corpo'])) {
            $qtd = isset($resp['corpo']['data']) && is_array($resp['corpo']['data'])
                ? count($resp['corpo']['data'])
                : 0;
            $r = IAResultado::sucesso('Conexão OK — ' . $qtd . ' modelos disponíveis na conta.');
            $r->tempoMs = $resp['tempo_ms'];
            return $r;
        }

        if ($resp['status'] === 0) {
            return IAResultado::falha('rede', 'Sem resposta do provedor: ' . ($resp['erro'] ?? 'falha de rede'));
        }

        [$codigo, $msg] = $this->extrairErro($resp['corpo'], $resp['status']);
        return IAResultado::falha($codigo, $msg, false);
    }

    public function gerarTexto(array $job): IAResultado
    {
        $mensagens = [];
        if (!empty($job['instrucoes'])) {
            $mensagens[] = ['role' => 'system', 'content' => (string) $job['instrucoes']];
        }
        $mensagens[] = ['role' => 'user', 'content' => (string) $job['prompt']];

        $payload = [
            'model'    => (string) $job['modelo_codigo'],
            'messages' => $mensagens,
        ];

        $maxTokens = (int) ($job['max_tokens'] ?? 0);
        if ($maxTokens > 0) {
            // Padrão atual da API — modelos recentes rejeitam o antigo max_tokens.
            $payload['max_completion_tokens'] = min($maxTokens, 8000);
        }

        // Saída JSON nativa: o fallback devolve JSON parseável igual ao Gemini.
        if (!empty($job['saida_json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        // params_padrao do catálogo (temperature etc.) — o payload base prevalece.
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        foreach ($params as $chave => $valor) {
            if (!array_key_exists($chave, $payload)) {
                $payload[$chave] = $valor;
            }
        }

        $resp = $this->httpJson('POST', '/chat/completions', $payload, (int) ($job['timeout_s'] ?? 120));

        if ($resp['status'] === 0) {
            $r = IAResultado::falha('rede', 'Sem resposta do provedor: ' . ($resp['erro'] ?? 'falha de rede'));
            $r->tempoMs = $resp['tempo_ms'];
            return $r;
        }

        if ($resp['status'] !== 200 || !is_array($resp['corpo'])) {
            [$codigo, $msg] = $this->extrairErro($resp['corpo'], $resp['status']);
            // 401/403: chave inválida — outros modelos DESTE provedor também falharão,
            // mas modelos de outros provedores podem atender → retryable.
            $r = IAResultado::falha($codigo, $msg, true);
            $r->tempoMs = $resp['tempo_ms'];
            $r->respostaBruta = $resp['corpo_bruto'];
            return $r;
        }

        $corpo   = $resp['corpo'];
        $escolha = $corpo['choices'][0] ?? null;
        $texto   = is_array($escolha) ? ($escolha['message']['content'] ?? null) : null;
        $motivo  = is_array($escolha) ? (string) ($escolha['finish_reason'] ?? '') : '';

        if ($motivo === 'content_filter' || $texto === null || trim((string) $texto) === '') {
            $recusa = is_array($escolha) ? ($escolha['message']['refusal'] ?? null) : null;
            $r = IAResultado::falha(
                'content_filter',
                'O modelo recusou a geração' . ($recusa ? ': ' . mb_substr((string) $recusa, 0, 300) : '.'),
                false // retentar em outro modelo tende a repetir a recusa e gastar à toa
            );
            $r->tempoMs = $resp['tempo_ms'];
            $r->respostaBruta = $resp['corpo_bruto'];
            return $r;
        }

        $r = IAResultado::sucesso(trim((string) $texto));
        $r->tokensIn      = isset($corpo['usage']['prompt_tokens']) ? (int) $corpo['usage']['prompt_tokens'] : null;
        $r->tokensOut     = isset($corpo['usage']['completion_tokens']) ? (int) $corpo['usage']['completion_tokens'] : null;
        $r->tempoMs       = $resp['tempo_ms'];
        $r->respostaBruta = $resp['corpo_bruto'];
        return $r;
    }

    /** Imagem síncrona via /images/generations (gpt-image-1.5 devolve base64 no corpo). */
    public function gerarImagem(array $job): IAResultado
    {
        $tamanhos = ['1:1' => '1024x1024', '3:2' => '1536x1024', '2:3' => '1024x1536'];

        $payload = [
            'model'  => (string) $job['modelo_codigo'],
            'prompt' => (string) $job['prompt'],
            'n'      => 1,
            'size'   => $tamanhos[$job['proporcao'] ?? '1:1'] ?? '1024x1024',
        ];

        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        foreach ($params as $chave => $valor) {
            if (!array_key_exists($chave, $payload)) {
                $payload[$chave] = $valor;
            }
        }

        $resp = $this->httpJson('POST', '/images/generations', $payload, (int) ($job['timeout_s'] ?? 180));

        if ($resp['status'] === 0) {
            $r = IAResultado::falha('rede', 'Sem resposta do provedor: ' . ($resp['erro'] ?? 'falha de rede'));
            $r->tempoMs = $resp['tempo_ms'];
            return $r;
        }

        if ($resp['status'] !== 200 || !is_array($resp['corpo'])) {
            [$codigo, $msg] = $this->extrairErro($resp['corpo'], $resp['status']);
            // Violação de política de conteúdo: retentar noutro modelo tende a repetir.
            $retryable = (stripos($codigo, 'moderation') === false && stripos($codigo, 'safety') === false
                          && stripos((string) $msg, 'safety system') === false);
            $r = IAResultado::falha($codigo, $msg, $retryable);
            $r->tempoMs = $resp['tempo_ms'];
            $r->respostaBruta = $resp['corpo_bruto'];
            return $r;
        }

        $b64 = $resp['corpo']['data'][0]['b64_json'] ?? null;
        if (!is_string($b64) || $b64 === '') {
            $r = IAResultado::falha('sem_imagem', 'Resposta sem imagem no corpo.', true);
            $r->tempoMs = $resp['tempo_ms'];
            $r->respostaBruta = $resp['corpo_bruto'];
            return $r;
        }

        $binario = base64_decode($b64, true);
        if ($binario === false || $binario === '') {
            return IAResultado::falha('b64_invalido', 'Base64 da imagem inválido.', true);
        }

        $formato = strtolower((string) ($payload['output_format'] ?? 'png'));
        $mimes   = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

        $r = IAResultado::sucessoImagem([[
            'binario'  => $binario,
            'mime'     => $mimes[$formato] ?? 'image/png',
            'extensao' => ($formato === 'jpeg') ? 'jpg' : ($mimes[$formato] ?? null ? $formato : 'png'),
        ]]);
        $r->tempoMs = $resp['tempo_ms'];
        // Não guardamos o corpo bruto de imagem (base64 enorme) — o arquivo é a auditoria.
        if (!empty($resp['corpo']['data'][0]['revised_prompt'])) {
            $r->texto = mb_substr((string) $resp['corpo']['data'][0]['revised_prompt'], 0, 1000);
        }
        return $r;
    }
}
