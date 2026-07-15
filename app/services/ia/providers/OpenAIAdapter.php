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
}
