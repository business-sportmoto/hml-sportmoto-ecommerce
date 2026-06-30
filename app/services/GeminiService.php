<?php
declare(strict_types=1);

/**
 * GeminiService — integração com a API Google Gemini.
 * Responsabilidade única: enviar prompt e retornar texto.
 */
class GeminiService {

    private string $apiKey;
    private string $model;
    private string $endpoint;

    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct() {
        $this->apiKey = GEMINI_API_KEY;
        $this->model  = GEMINI_MODEL;

        // v1beta continua correto para a Developer API (AI Studio)
        // O problema era apenas o nome do modelo
        $this->endpoint = "https://generativelanguage.googleapis.com/v1beta/models/"
                        . $this->model
                        . ":generateContent?key=" . $this->apiKey;

        if (empty($this->apiKey)) {
            throw new \RuntimeException('GEMINI_API_KEY não configurada.');
        }
    }

    /**
     * Gera conteúdo a partir de um prompt.
     * Retorna o texto bruto da resposta.
     */
    public function gerar(string $prompt, float $temperature = 0.7): string {
        $payload = json_encode([
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'     => $temperature,
                'maxOutputTokens' => 1024,
            ],
        ]);

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException("Erro cURL: {$curlErr}");
        }
        if ($httpCode !== 200) {
            $msg = json_decode($response, true)['error']['message'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("Gemini API: {$msg}");
        }

        $data = json_decode($response, true);
        return trim(
            $data['candidates'][0]['content']['parts'][0]['text'] ?? ''
        );
    }

    /**
     * Gera e faz parse de JSON retornado pelo modelo.
     * O prompt DEVE instruir o modelo a retornar apenas JSON.
     */
    public function gerarJson(
        string $prompt,
        ?array $schema = null,
        float $temperature = 0.3,
        int $maxOutputTokens = 2048,
        ?string $systemInstruction = null
    ): array {
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature'      => $temperature,
                'maxOutputTokens'  => $maxOutputTokens,
                'responseMimeType' => 'application/json',
            ],
        ];

        if ($schema !== null) {
            $payload['generationConfig']['responseSchema'] = $schema;
        }

        if ($systemInstruction !== null && trim($systemInstruction) !== '') {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ];
        }
        try {
            $data = $this->request($payload);
            $texto = $this->extractText($data);

            $parsed = json_decode($texto, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(
                    'Gemini retornou JSON inválido: ' . json_last_error_msg()
                    . ' | Resposta: ' . substr($texto, 0, 500)
                );
            }
        } catch (\RuntimeException $e) {

            if (str_contains($e->getMessage(), 'high demand')) {

                $this->model = GEMINI_FALLBACK_MODEL; // ex: gemini-2.5-flash

                return $this->request($payload);
            }

            throw $e;
        }

        return $parsed;
    }

    private function request(array $payload, int $attempt = 1): array
    {
        $endpoint = "{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}";

        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        curl_close($ch);

        if ($curlErr) {
            if ($attempt < 3) {
                usleep(300000 * $attempt);
                return $this->request($payload, $attempt + 1);
            }

            throw new RuntimeException("Erro cURL Gemini: {$curlErr}");
        }

        $data = json_decode((string) $response, true);

        if ($httpCode >= 500 || $httpCode === 429) {

            if (
                $httpCode === 429 ||
                str_contains($response, 'high demand')
            ) {
                if ($attempt < 4) {
                    sleep($attempt * 2); // backoff progressivo
                    return $this->request($payload, $attempt + 1);
                }
            }

            if ($attempt < 3) {
                usleep(500000 * $attempt);
                return $this->request($payload, $attempt + 1);
            }
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw new RuntimeException("Gemini API: {$msg}");
        }

        if (!is_array($data)) {
            throw new RuntimeException('Resposta inválida da Gemini API.');
        }

        return $data;
    }

    private function extractText(array $data): string
    {
        if (empty($data['candidates'][0])) {
            $reason = $data['promptFeedback']['blockReason'] ?? 'sem candidatos';
            throw new RuntimeException("Gemini não retornou conteúdo: {$reason}");
        }

        $candidate = $data['candidates'][0];

        if (!empty($candidate['finishReason']) && $candidate['finishReason'] === 'SAFETY') {
            throw new RuntimeException('Gemini bloqueou a resposta por política de segurança.');
        }

        $parts = $candidate['content']['parts'] ?? [];

        $text = '';

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException('Gemini retornou texto vazio.');
        }

        return $text;
    }
}