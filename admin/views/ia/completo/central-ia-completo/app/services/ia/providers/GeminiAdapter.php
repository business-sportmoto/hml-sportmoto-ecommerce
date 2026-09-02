<?php
/**
 * GeminiAdapter — Google Gemini (Developer API, v1beta) no orquestrador.
 *
 * Diferenças deliberadas em relação ao antigo GeminiService:
 *  - Chave SEMPRE no header x-goog-api-key — nunca na URL (a URL vaza em
 *    access logs e mensagens de erro; o header não).
 *  - Sem retry interno: quem decide retentar/fallback é o orquestrador,
 *    com registro em ia_roteamento_log (o retry cego do service antigo
 *    escondia o problema e, no fallback, devolvia o envelope cru — o bug
 *    do SEO salvo em branco).
 *  - finishReason SAFETY / prompt bloqueado → content_filter NÃO-retryable
 *    (outro modelo tende a bloquear igual).
 */
class GeminiAdapter extends IAProviderBase
{
    public function codigo(): string
    {
        return 'gemini';
    }

    /** Auth por header — sobrepõe o Bearer padrão da base. */
    protected function cabecalhos(): array
    {
        return [
            'x-goog-api-key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    public function testarConexao(): IAResultado
    {
        $resp = $this->httpJson('GET', '/models?pageSize=1', null, 15);

        if ($resp['status'] === 200 && is_array($resp['corpo'])) {
            return IAResultado::sucesso('Gemini acessível — catálogo de modelos respondendo.');
        }

        if (in_array($resp['status'], [401, 403], true)) {
            return IAResultado::falha('chave_invalida', 'Chave recusada pelo Google (401/403).', false);
        }

        $msg = $resp['corpo']['error']['message'] ?? ($resp['erro'] ?? ('HTTP ' . $resp['status']));
        return IAResultado::falha('conexao', 'Gemini indisponível: ' . $msg, true);
    }

    public function gerarTexto(array $job): IAResultado
    {
        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => (string) $job['prompt']]]],
            ],
        ];

        if (!empty($job['instrucoes'])) {
            $payload['systemInstruction'] = ['parts' => [['text' => (string) $job['instrucoes']]]];
        }

        $gen = [];
        $maxTokens = (int) ($job['max_tokens'] ?? 0);
        if ($maxTokens > 0) {
            $gen['maxOutputTokens'] = min($maxTokens, 8192);
        }
        if (!empty($job['saida_json'])) {
            $gen['responseMimeType'] = 'application/json'; // JSON nativo — parse garantido
        }

        // params_padrao do catálogo (temperature etc.) entram no generationConfig
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        foreach ($params as $chave => $valor) {
            if (!array_key_exists($chave, $gen)) {
                $gen[$chave] = $valor;
            }
        }
        if (!empty($gen)) {
            $payload['generationConfig'] = $gen;
        }

        $resp = $this->httpJson(
            'POST',
            '/models/' . $job['modelo_codigo'] . ':generateContent',
            $payload,
            (int) ($job['timeout_s'] ?? 60)
        );

        if ($resp['status'] === 0) {
            $r = IAResultado::falha('rede', 'Sem resposta do provedor: ' . ($resp['erro'] ?? 'falha de rede'), true);
            $r->tempoMs = $resp['tempo_ms'];
            return $r;
        }

        if ($resp['status'] !== 200 || !is_array($resp['corpo'])) {
            $msg = $resp['corpo']['error']['message'] ?? ('HTTP ' . $resp['status']);
            $codigo = match (true) {
                $resp['status'] === 429                       => 'rate_limit',
                in_array($resp['status'], [401, 403], true)   => 'chave_invalida',
                default                                       => 'gemini_' . $resp['status'],
            };
            $retryable = !in_array($resp['status'], [401, 403, 400], true);
            $r = IAResultado::falha($codigo, 'Gemini: ' . $msg, $retryable);
            $r->tempoMs = $resp['tempo_ms'];
            $r->respostaBruta = $resp['corpo_bruto'];
            return $r;
        }

        $corpo = $resp['corpo'];

        if (empty($corpo['candidates'][0])) {
            $motivo = $corpo['promptFeedback']['blockReason'] ?? 'sem candidatos';
            $r = IAResultado::falha('content_filter', 'Gemini bloqueou o prompt: ' . $motivo, false);
            $r->tempoMs = $resp['tempo_ms'];
            $r->respostaBruta = $resp['corpo_bruto'];
            return $r;
        }

        $candidato = $corpo['candidates'][0];

        if (($candidato['finishReason'] ?? '') === 'SAFETY') {
            $r = IAResultado::falha('content_filter', 'Gemini bloqueou a resposta por política de segurança.', false);
            $r->tempoMs = $resp['tempo_ms'];
            $r->respostaBruta = $resp['corpo_bruto'];
            return $r;
        }

        $texto = '';
        foreach (($candidato['content']['parts'] ?? []) as $parte) {
            if (isset($parte['text'])) {
                $texto .= $parte['text'];
            }
        }
        $texto = trim($texto);

        if ($texto === '') {
            $r = IAResultado::falha('sem_conteudo', 'Gemini retornou texto vazio.', true);
            $r->tempoMs = $resp['tempo_ms'];
            $r->respostaBruta = $resp['corpo_bruto'];
            return $r;
        }

        $r = IAResultado::sucesso($texto);
        $r->tokensIn      = isset($corpo['usageMetadata']['promptTokenCount']) ? (int) $corpo['usageMetadata']['promptTokenCount'] : null;
        $r->tokensOut     = isset($corpo['usageMetadata']['candidatesTokenCount']) ? (int) $corpo['usageMetadata']['candidatesTokenCount'] : null;
        $r->tempoMs       = $resp['tempo_ms'];
        $r->respostaBruta = $resp['corpo_bruto'];
        return $r;
    }
}
