<?php
// /app/services/SeoIaService.php
declare(strict_types=1);

/**
 * SeoIaService — gera campos de SEO via Gemini.
 * Plugável: aceita qualquer contexto (produto, categoria, marca, página).
 */
class SeoIaService {

    private GeminiService $gemini;

    public function __construct() {
        $this->gemini = new GeminiService();
    }

    /**
     * Gera todos os campos SEO de uma vez.
     *
     * @param string $tipo     — 'produto' | 'categoria' | 'marca' | 'pagina'
     * @param array  $contexto — dados do item (nome, descricao, categoria, etc.)
     * @param string $idioma   — padrão: 'pt-BR'
     */
    public function gerarSeo(string $tipo, array $contexto, string $idioma = 'pt-BR'): array {
        $prompt = $this->buildPrompt($tipo, $contexto, $idioma);

        $resultado = $this->gemini->gerarJson($prompt);

        return [
            'meta_title'       => $this->sanitizar($resultado['meta_title']       ?? '', 90),
            'meta_description' => $this->sanitizar($resultado['meta_description'] ?? '', 256),
            'keywords'         => $this->sanitizarKeywords($resultado['keywords'] ?? ''),
            'google_category'  => $this->sanitizar($resultado['google_category']  ?? '', 200),
        ];
    }

    // ── Prompts por tipo ──────────────────────────────────

    private function buildPrompt(string $tipo, array $ctx, string $idioma): string {
        $base = $this->promptBase($idioma);

        return match ($tipo) {
            'produto'   => $base . $this->promptProduto($ctx),
            'categoria' => $base . $this->promptCategoria($ctx),
            'marca'     => $base . $this->promptMarca($ctx),
            'pagina'    => $base . $this->promptPagina($ctx),
            default     => throw new \InvalidArgumentException("Tipo desconhecido: {$tipo}"),
        };
    }

    private function promptBase(string $idioma): string {
        return <<<PROMPT
        Você é um especialista em SEO para e-commerce brasileiro.
        Responda APENAS com um objeto JSON válido, sem markdown, sem explicações.
        Idioma de saída: {$idioma}.
        
        O nome da minha loja é Sportmoto.
        Meu site é https://www.sportmoto.com.br.
        Parcelamos em até 12x sem juros e no pix tem desconto. Frete grátis acima de R$ 350 * conforme regras (importante).
        Estamos localizados em Porto Alegre, RS, mas enviamos para todo o Brasil.

        Regras obrigatórias:
        - meta_title com no máximo 90 caracteres.
        - meta_description com no máximo 256 caracteres.        
        - keywords com 5 a 10 termos relevantes.        
        - seo_text com 1 a 2 parágrafos, sem exagero, sem promessas falsas.
        - Evite keyword stuffing.
        - Priorize intenção de compra e busca orgânica.
        - Para produtos de moto, use linguagem de ecommerce premium e objetiva.
        - Não invente informações técnicas que não existam no contexto.

        O JSON deve ter exatamente estas chaves:
        {
          "meta_title": "string com até 90 caracteres",
          "meta_description": "string com até 256 caracteres, persuasiva e com CTA",
          "keywords": "palavra1, palavra2, palavra3 (mínimo 5, máximo 10, separadas por vírgula)",
          "google_category": "categoria no formato Google Merchant Center (ex: Vestuário e acessórios > Roupas)"
        }

        Regras obrigatórias:
        - meta_title: inclua o nome principal + benefício/diferencial. Máx 90 chars.
        - meta_description: texto convincente com CTA (ex: "Compre agora", "Frete grátis"). Máx 256 chars.
        - keywords: termos que os usuários realmente buscam. Sem repetição. Lowercase.
        - google_category: use a taxonomia oficial do Google Merchant Center em português.

        PROMPT;
    }

    private function promptProduto(array $ctx): string {
        $nome      = $ctx['nome']          ?? '';
        $descricao = $ctx['descricao']     ?? '';
        $categoria = $ctx['categoria']     ?? '';
        $marca     = $ctx['marca']         ?? '';
        $preco     = $ctx['preco']         ?? '';

        return <<<PROMPT
        Contexto: PRODUTO de e-commerce.

        Nome do produto: {$nome}
        Marca: {$marca}
        Categoria: {$categoria}
        Preço: R$ {$preco}
        Descrição: {$descricao}

        Gere os campos SEO para este produto.
        PROMPT;
    }

    private function promptCategoria(array $ctx): string {
        $nome      = $ctx['nome']      ?? '';
        $descricao = $ctx['descricao'] ?? '';
        $parent    = $ctx['parent']    ?? '';

        return <<<PROMPT
        Contexto: CATEGORIA de e-commerce.

        Nome da categoria: {$nome}
        Categoria pai: {$parent}
        Descrição: {$descricao}

        Gere os campos SEO para esta categoria.
        Para meta_title, use formato: "{$nome} | Nome da Loja"
        Para google_category, use a categoria mais adequada para produtos desta seção.
        PROMPT;
    }

    private function promptMarca(array $ctx): string {
        $nome      = $ctx['nome']      ?? '';
        $descricao = $ctx['descricao'] ?? '';

        return <<<PROMPT
        Contexto: PÁGINA DE MARCA em e-commerce.

        Nome da marca: {$nome}
        Descrição: {$descricao}

        Gere os campos SEO para a página desta marca.
        Para meta_title: "Produtos {$nome} | Nome da Loja"
        Para google_category: use a categoria mais representativa da marca.
        PROMPT;
    }

    private function promptPagina(array $ctx): string {
        $titulo    = $ctx['titulo']    ?? '';
        $conteudo  = $ctx['conteudo'] ?? '';

        return <<<PROMPT
        Contexto: PÁGINA INSTITUCIONAL de e-commerce.

        Título: {$titulo}
        Conteúdo resumido: {$conteudo}

        Gere os campos SEO para esta página.
        Para google_category: use "Sem categoria" se não for aplicável.
        PROMPT;
    }

    // ── Sanitização ───────────────────────────────────────

    private function sanitizar(string $valor, int $maxLen): string {
        $valor = strip_tags($valor);
        $valor = preg_replace('/\s+/', ' ', $valor);
        return mb_substr(trim($valor), 0, $maxLen);
    }

    private function sanitizarKeywords(string $keywords): string {
        $lista = array_map('trim', explode(',', strtolower($keywords)));
        $lista = array_filter($lista);
        $lista = array_unique($lista);
        $lista = array_slice($lista, 0, 10);
        return implode(', ', $lista);
    }
}