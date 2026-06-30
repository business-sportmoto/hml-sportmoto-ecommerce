<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/ReviewSummaryService.php
//
// Gera e cacheia um resumo IA das avaliações de um produto.
//
// Estratégia de invalidação:
//   - Calcula MD5 dos textos aprovados
//   - Compara com o hash armazenado
//   - Diferente = regenera; igual = retorna cache
//
// Nunca é chamado sincronamente no carregamento da página.
// Sempre disparado via AJAX após o render.
// ════════════════════════════════════════════════════════
class ReviewSummaryService {

    private const MIN_AVALIACOES  = 1;    // mínimo para gerar resumo
    private const MAX_AVALIACOES  = 30;   // máximo enviado para IA (economia de tokens)
    private const MAX_CHARS_CADA  = 280;  // trunca cada review (evita prompts gigantes)

    private PDO $db;

    public function __construct(
        private GeminiService $gemini = new GeminiService()
    ) {
        $this->db = Database::getInstance()->getConnection();
    }

    // ── Interface principal ───────────────────────────────
    /**
     * Retorna o resumo para o produto.
     * Se o hash mudou (novos comentários), regenera automaticamente.
     *
     * @return array{
     *   ok:       bool,
     *   resumo:   string|null,
     *   cached:   bool,
     *   total:    int,
     *   insuf:    bool   -- true quando total < MIN
     * }
     */
    public function obter(int $produtoId): array {
        // 1. Busca reviews aprovadas
        $reviews = $this->buscarReviews($produtoId);
        $total   = count($reviews);

        if ($total < self::MIN_AVALIACOES) {
            return ['ok' => true, 'resumo' => null, 'cached' => false,
                    'total' => $total, 'insuf' => true];
        }

        // 2. Calcula hash dos textos atuais
        $hashAtual = $this->calcularHash($reviews);

        // 3. Verifica cache
        $cached = $this->buscarCache($produtoId);

        if ($cached && $cached['hash_comentarios'] === $hashAtual) {
            // Cache válido — retorna direto sem chamar a IA
            return [
                'ok'     => true,
                'resumo' => $cached['resumo'],
                'cached' => true,
                'total'  => $total,
                'insuf'  => false,
            ];
        }

        // 4. Cache desatualizado (novo comentário) ou inexistente → chama Gemini
        try {
            $resultado = $this->gerarComGemini($reviews, $produtoId);

            // 5. Persiste cache
            $this->salvarCache(
                $produtoId,
                $resultado['resumo'],
                $hashAtual,
                $total,
                $resultado['tokens'] ?? 0
            );

            return [
                'ok'     => true,
                'resumo' => $resultado['resumo'],
                'cached' => false,
                'total'  => $total,
                'insuf'  => false,
            ];
        } catch (\Throwable $e) {
            error_log('[ReviewSummary] Gemini error produto ' . $produtoId . ': ' . $e->getMessage());

            // Se falhou mas tinha cache antigo, retorna o cache desatualizado
            // com aviso (melhor que nada)
            if ($cached) {
                return [
                    'ok'     => true,
                    'resumo' => $cached['resumo'],
                    'cached' => true,
                    'total'  => $total,
                    'insuf'  => false,
                    'stale'  => true,  // aviso interno — não exibido ao usuário
                ];
            }

            return ['ok' => false, 'resumo' => null, 'cached' => false,
                    'total' => $total, 'insuf' => false, 'debug'=>$e->getMessage()];
        }
    }

    // ── Gera o resumo via Gemini ──────────────────────────
    private function gerarComGemini(array $reviews, int $produtoId): array {
        // Monta bloco de textos (truncados para economizar tokens)
        $blocos = [];
        foreach (array_slice($reviews, 0, self::MAX_AVALIACOES) as $i => $r) {
            $nota    = (int)$r['nota'];
            $stars   = str_repeat('★', $nota) . str_repeat('☆', 5 - $nota);
            $texto   = mb_substr(trim($r['comentario']), 0, self::MAX_CHARS_CADA);
            $blocos[] = "{$stars} \"{$texto}\"";
        }
        $textoReviews = implode("\n", $blocos);

        $prompt = <<<PROMPT
        Você é um assistente de e-commerce. Analise as avaliações de clientes abaixo e
        escreva um resumo em 2 a 3 frases curtas em português brasileiro.

        REGRAS:
        - Seja objetivo e neutro
        - Mencione pontos positivos que se repetem
        - Mencione pontos negativos se houver padrão (não invente problemas)
        - Não repita números de notas nem diga "os clientes dizem"
        - Tom: direto, informativo, confiante
        - Responda SOMENTE com o resumo — sem introdução, sem saudação, sem conclusão extra

        AVALIAÇÕES:
        {$textoReviews}

        RESUMO:
        PROMPT;

        // Chama a API Gemini
        // $response = $this->chamarGemini($prompt);

        $raw = $this->gemini->gerar($prompt);

        return [
            'resumo' => trim($raw),
            'tokens' => 0,
        ];
    }

    // ── Chamada direta à API Gemini (Flash — mais econômico) ─
    private function chamarGemini(string $prompt): array {
        $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
        if (empty($apiKey)) {
            throw new \RuntimeException('GEMINI_API_KEY não configurada.');
        }

        // Usa gemini-1.5-flash — mais barato e suficiente para resumos
        $url  = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;
        $body = json_encode([
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'maxOutputTokens' => 200,    // resumo curto — economia de tokens
                'temperature'     => 0.3,    // mais determinístico
            ],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || $code !== 200) {
            throw new \RuntimeException("Gemini HTTP {$code}: {$err}");
        }

        $data = json_decode($raw, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (empty($text)) {
            throw new \RuntimeException('Gemini retornou resposta vazia.');
        }

        $tokens = $data['usageMetadata']['totalTokenCount'] ?? 0;

        return ['text' => $text, 'tokens' => $tokens];
    }

    // ── Helpers internos ──────────────────────────────────
    private function buscarReviews(int $produtoId): array {
        $stmt = $this->db->prepare(
            "SELECT nota, comentario
             FROM avaliacoes
             WHERE produto_id = ? AND aprovado = 1
               AND comentario IS NOT NULL AND comentario != ''
             ORDER BY criado_em DESC
             LIMIT " . self::MAX_AVALIACOES
        );
        $stmt->execute([$produtoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function calcularHash(array $reviews): string {
        // Hash dos textos na ordem atual — muda quando nova review entra
        $conteudo = implode('|||', array_column($reviews, 'comentario'));
        return md5($conteudo);
    }

    private function buscarCache(int $produtoId): ?array {
        $stmt = $this->db->prepare(
            "SELECT resumo, hash_comentarios, total_avaliacoes, gerado_em
             FROM produto_review_summary
             WHERE produto_id = ? LIMIT 1"
        );
        $stmt->execute([$produtoId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function salvarCache(
        int    $produtoId,
        string $resumo,
        string $hash,
        int    $total,
        int    $tokens
    ): void {
        $this->db->prepare(
            "INSERT INTO produto_review_summary
             (produto_id, resumo, hash_comentarios, total_avaliacoes, tokens_usados, gerado_em)
             VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE
               resumo           = VALUES(resumo),
               hash_comentarios = VALUES(hash_comentarios),
               total_avaliacoes = VALUES(total_avaliacoes),
               tokens_usados    = VALUES(tokens_usados),
               gerado_em        = NOW()"
        )->execute([$produtoId, $resumo, $hash, $total, $tokens]);
    }
}