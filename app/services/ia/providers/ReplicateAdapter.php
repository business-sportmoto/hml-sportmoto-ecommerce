<?php
/**
 * ReplicateAdapter — Fase 1 traz apenas o teste de conexão; predictions de
 * imagem/remoção de fundo/upscale entram na Fase 2 (worker + webhook).
 */
class ReplicateAdapter extends IAProviderBase
{
    public function codigo(): string
    {
        return 'replicate';
    }

    public function testarConexao(): IAResultado
    {
        $resp = $this->httpJson('GET', '/account', null, 20);

        if ($resp['status'] === 200 && is_array($resp['corpo'])) {
            $conta = (string) ($resp['corpo']['username'] ?? $resp['corpo']['name'] ?? 'conta verificada');
            $r = IAResultado::sucesso('Conexão OK — conta: ' . $conta . '.');
            $r->tempoMs = $resp['tempo_ms'];
            return $r;
        }

        if ($resp['status'] === 0) {
            return IAResultado::falha('rede', 'Sem resposta do provedor: ' . ($resp['erro'] ?? 'falha de rede'));
        }

        [$codigo, $msg] = $this->extrairErro($resp['corpo'], $resp['status']);
        return IAResultado::falha($codigo, $msg, false);
    }

    /**
     * Imagem ASSÍNCRONA: cria a prediction e devolve pendente(id).
     * A conclusão chega por webhook (se IA_WEBHOOK_BASE definida) e/ou
     * pela varredura do worker — os dois caminhos são idempotentes.
     */
    public function gerarImagem(array $job): IAResultado
    {
        $input = [
            'prompt'       => (string) $job['prompt'],
            'aspect_ratio' => $this->proporcaoAceita($job),
        ];

        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        foreach ($params as $chave => $valor) {
            if (!array_key_exists($chave, $input)) {
                $input[$chave] = $valor;
            }
        }

        // Foto do produto como referência (FLUX.2 aceita imagens de entrada).
        // O nome do input vem de params_padrao.ia.ref_param — o bloco `ia` já
        // chega separado, então não há mais o que remover do payload.
        if (!empty($job['imagem_referencia'])) {
            $refParam = (string) ($job['meta']['ref_param'] ?? 'input_images');
            $input[$refParam] = [(string) $job['imagem_referencia']];
        }

        $payload = ['input' => $input];

        // Webhook é otimização de latência; sem a constante, a varredura resolve (dev/Laragon).
        if (defined('IA_WEBHOOK_BASE') && IA_WEBHOOK_BASE !== '') {
            $payload['webhook']               = rtrim((string) IA_WEBHOOK_BASE, '/') . '/webhooks/ia/replicate';
            $payload['webhook_events_filter'] = ['completed'];
        }

        // Modelos oficiais: POST /models/{owner}/{name}/predictions
        $resp = $this->httpJson('POST', '/models/' . $job['modelo_codigo'] . '/predictions', $payload, 30);

        if ($resp['status'] === 0) {
            $r = IAResultado::falha('rede', 'Sem resposta do provedor: ' . ($resp['erro'] ?? 'falha de rede'));
            $r->tempoMs = $resp['tempo_ms'];
            return $r;
        }

        if (!in_array($resp['status'], [200, 201], true) || !is_array($resp['corpo']) || empty($resp['corpo']['id'])) {
            [$codigo, $msg] = $this->extrairErro($resp['corpo'], $resp['status']);
            $r = IAResultado::falha($codigo, $msg, true);
            $r->tempoMs = $resp['tempo_ms'];
            $r->respostaBruta = $resp['corpo_bruto'];
            return $r;
        }

        $r = IAResultado::pendente((string) $resp['corpo']['id']);
        $r->tempoMs = $resp['tempo_ms'];
        return $r;
    }

    /**
     * Remoção de fundo (bria) — assíncrona como toda prediction.
     * A imagem de origem PRECISA ser uma URL pública que o Replicate
     * consiga baixar (IA_PRODUTO_IMG_BASE + produto_imagens.arquivo).
     */
    public function removerFundo(array $job): IAResultado
    {
        $origem = trim((string) ($job['imagem_origem'] ?? ''));
        if ($origem === '' || strpos($origem, 'http') !== 0) {
            return IAResultado::falha('imagem_origem_invalida', 'URL pública da imagem de origem ausente ou inválida.', false);
        }

        $input = ['image' => $origem];

        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        foreach ($params as $chave => $valor) {
            if (!array_key_exists($chave, $input)) {
                $input[$chave] = $valor;
            }
        }

        $payload = ['input' => $input];
        if (defined('IA_WEBHOOK_BASE') && IA_WEBHOOK_BASE !== '') {
            $payload['webhook']               = rtrim((string) IA_WEBHOOK_BASE, '/') . '/webhooks/ia/replicate';
            $payload['webhook_events_filter'] = ['completed'];
        }

        $resp = $this->httpJson('POST', '/models/' . $job['modelo_codigo'] . '/predictions', $payload, (int) max(30, $job['timeout_s'] ?? 30));

        if ($resp['status'] === 0) {
            $r = IAResultado::falha('rede', 'Sem resposta do provedor: ' . ($resp['erro'] ?? 'falha de rede'));
            $r->tempoMs = $resp['tempo_ms'];
            return $r;
        }

        if (!in_array($resp['status'], [200, 201], true) || !is_array($resp['corpo']) || empty($resp['corpo']['id'])) {
            [$codigo, $msg] = $this->extrairErro($resp['corpo'], $resp['status']);
            $r = IAResultado::falha($codigo, $msg, true);
            $r->tempoMs = $resp['tempo_ms'];
            $r->respostaBruta = $resp['corpo_bruto'];
            return $r;
        }

        $r = IAResultado::pendente((string) $resp['corpo']['id']);
        $r->tempoMs = $resp['tempo_ms'];
        return $r;
    }

    /**
     * Consulta o estado atual de uma prediction (varredura do worker).
     * Retorna o corpo cru do provedor: status, output, error, metrics…
     */
    public function consultarPrediction(string $externalId): array
    {
        $resp = $this->httpJson('GET', '/predictions/' . rawurlencode($externalId), null, 20);

        if ($resp['status'] !== 200 || !is_array($resp['corpo'])) {
            return ['status' => 'consulta_falhou', 'error' => $resp['erro'] ?? ('HTTP ' . $resp['status'])];
        }
        return $resp['corpo'];
    }

    /** Normaliza o output (string ou array de URLs) numa lista de URLs. */
    public function extrairUrlsSaida($output): array
    {
        if (is_string($output) && $output !== '') {
            return [$output];
        }
        if (is_array($output)) {
            return array_values(array_filter($output, fn($u) => is_string($u) && $u !== ''));
        }
        return [];
    }

    /** Baixa uma URL de entrega (expira em ~1h — chamar IMEDIATAMENTE). */
    public function baixarSaida(string $url): array
    {
        return $this->httpBinario($url, 90);
    }

    /**
     * Proporção que ESTE modelo aceita.
     *
     * A lista vinha cravada como ['1:1','3:2','2:3'], que é o que o FLUX.2
     * aceita — modelos com outro conjunto (imagen-4-ultra quer 9:16/16:9)
     * recebiam um valor inválido e devolviam HTTP 422. Agora cada modelo
     * declara o seu em params_padrao.ia.proporcoes; pedido fora da lista cai
     * na primeira aceita, em vez de estourar a prediction.
     */
    private function proporcaoAceita(array $job): string
    {
        $aceitas = $job['meta']['proporcoes'] ?? ['1:1'];
        $pedida  = (string) ($job['proporcao'] ?? '1:1');

        return in_array($pedida, $aceitas, true) ? $pedida : (string) reset($aceitas);
    }
}
