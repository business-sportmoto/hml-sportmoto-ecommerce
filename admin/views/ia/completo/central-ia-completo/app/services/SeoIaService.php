<?php
// /app/services/SeoIaService.php
declare(strict_types=1);

/**
 * SeoIaService v2 — gera os campos de SEO pela Central de Marketing IA.
 *
 * O CONTRATO PÚBLICO NÃO MUDOU: gerarSeo(tipo, contexto, idioma) → array com
 * meta_title, meta_description, keywords, google_category. O SeoIaController
 * e as telas continuam intactos.
 *
 * O que mudou por dentro:
 *  - Roda pelo IAOrchestrator (tipo de sistema `seo_pacote`, pinado no
 *    gemini-3-flash; se o Google falhar, cai nos GPT — que devolvem JSON
 *    nativo igual, via response_format). O bug do fallback antigo, que
 *    devolvia o envelope cru e salvava SEO em branco, morre aqui.
 *  - Execução SÍNCRONA (o Ajax espera), mas REGISTRADA em ia_geracoes:
 *    custo real no rollup, roteamento logado, auditoria completa.
 *  - Limites de gasto (diário/mensal/por minuto) passam a valer para o SEO.
 *  - Prompt saneado: regra fantasma `seo_text` removida e bloco de regras
 *    consolidado (viviam duplicados no promptBase antigo).
 *  - A chave sai do .env: fica cifrada em ia_provedores (tela de config
 *    ou cli/migrar-chave-gemini.php). O GeminiService está aposentado.
 *
 * Injeção por construtor (padrão do projeto): em produção, new SeoIaService()
 * como sempre; nos testes, injeta-se um orquestrador fake.
 */
class SeoIaService
{
    private IAOrchestrator $orq;
    private IACustoService $custo;

    public function __construct(?IAOrchestrator $orq = null)
    {
        $this->orq   = $orq ?? new IAOrchestrator();
        $this->custo = new IACustoService();
    }

    /**
     * Gera todos os campos SEO de uma vez.
     *
     * @param string $tipo     — 'produto' | 'categoria' | 'marca' | 'pagina'
     * @param array  $contexto — dados do item (nome, descricao, categoria, etc.)
     * @param string $idioma   — padrão: 'pt-BR'
     */
    public function gerarSeo(string $tipo, array $contexto, string $idioma = 'pt-BR'): array
    {
        if (!in_array($tipo, ['produto', 'categoria', 'marca', 'pagina'], true)) {
            throw new \InvalidArgumentException("Tipo desconhecido: {$tipo}");
        }

        $tipoRow = (new IATipoConteudo())->buscarPorCodigo('seo_pacote');
        if ($tipoRow === null || (int) $tipoRow['ativo'] !== 1) {
            throw new \RuntimeException('Tipo seo_pacote ausente — rode a migration Gemini/SEO da Central de Marketing IA.');
        }

        $usuarioId = (int) AuthHelper::usuarioId();
        if ($usuarioId <= 0) {
            throw new \RuntimeException('Sessão inválida para registrar a geração de SEO.');
        }

        $prompt = $this->montarPrompt($tipo, $contexto, $idioma);

        // Limites de gasto valem para o SEO também
        $custoEst = $this->custo->estimarTexto(
            $this->custo->custoConfigPrimarioTexto(),
            mb_strlen($prompt) + mb_strlen((string) $tipoRow['instrucoes_sistema']),
            (int) $tipoRow['max_tokens']
        );
        $chk = $this->custo->podeGerar($usuarioId, $custoEst, 1);
        if (!$chk['ok']) {
            throw new \RuntimeException($chk['msg']);
        }

        // Registro síncrono: nasce 'processando' para o worker NUNCA reivindicar
        $uuid = $this->uuidV4();
        $id   = (new IAGeracao())->criar([
            'uuid'                     => $uuid,
            'usuario_id'               => $usuarioId,
            'produto_id'               => null,
            'campanha_id'              => null,
            'geracao_origem_id'        => null,
            'tipo_conteudo_id'         => (int) $tipoRow['id'],
            'capacidade'               => 'texto',
            'formato'                  => null,
            'angulo'                   => null,
            'prompt_template_id'       => null,
            'prompt_template_snapshot' => null,
            'prompt_final'             => $prompt,
            'contexto'                 => json_encode(
                ['seo_tipo' => $tipo, 'dados' => $contexto, 'idioma' => $idioma],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'chave_dedup'              => hash('sha256', uniqid('seo|' . $tipo . '|', true)),
            'custo_estimado_usd'       => $custoEst,
            'status'                   => 'processando',
        ]);

        if ($id === null) {
            throw new \RuntimeException('Não foi possível registrar a geração de SEO.');
        }

        $geracao = [
            'id'                 => $id,
            'uuid'               => $uuid,
            'usuario_id'         => $usuarioId,
            'capacidade'         => 'texto',
            'prompt_final'       => $prompt,
            'custo_estimado_usd' => $custoEst,
        ];
        $tipoArr = [
            'instrucoes_sistema' => $tipoRow['instrucoes_sistema'],
            'max_tokens'         => (int) $tipoRow['max_tokens'],
            'modelo_id'          => $tipoRow['modelo_id'],
            'nome'               => $tipoRow['nome'],
            'saida'              => $tipoRow['saida'] ?? 'json',
        ];

        $servico = new IAGeracaoService();
        $r = $this->orq->executarTexto($geracao, $tipoArr);

        if (!$r->ok) {
            $servico->falhar($geracao, $r);
            throw new \RuntimeException('SEO IA: ' . ($r->erro ?: 'geração falhou em todos os provedores.'));
        }

        $dados = $this->decodificarJsonTolerante((string) $r->texto);
        if ($dados === null) {
            $servico->falhar($geracao, IAResultado::falha('json_invalido', 'Provedor devolveu JSON inválido para o pacote SEO.', false));
            throw new \RuntimeException('SEO IA: resposta do provedor não é um JSON válido.');
        }

        $servico->concluir($geracao, $r); // resultado_texto = JSON bruto (auditoria) + custo no rollup

        return [
            'meta_title'       => $this->sanitizar($dados['meta_title']       ?? '', 90),
            'meta_description' => $this->sanitizar($dados['meta_description'] ?? '', 256),
            'keywords'         => $this->sanitizarKeywords((string) ($dados['keywords'] ?? '')),
            'google_category'  => $this->sanitizar($dados['google_category']  ?? '', 200),
        ];
    }

    /* ── Prompts por tipo (persona e regras vivem em instrucoes_sistema do tipo) ── */

    private function montarPrompt(string $tipo, array $ctx, string $idioma): string
    {
        $base = <<<PROMPT
        Loja: Sportmoto — https://www.sportmoto.com.br — Porto Alegre/RS, enviamos para todo o Brasil.
        Condições: até 12x sem juros, desconto no Pix, frete grátis acima de R$ 350 conforme regras.
        Idioma de saída: {$idioma}.


        PROMPT;

        return $base . match ($tipo) {
            'produto'   => $this->promptProduto($ctx),
            'categoria' => $this->promptCategoria($ctx),
            'marca'     => $this->promptMarca($ctx),
            'pagina'    => $this->promptPagina($ctx),
        };
    }

    private function promptProduto(array $ctx): string
    {
        $nome      = $ctx['nome']            ?? '';
        $marca     = $ctx['marca']           ?? '';
        $categoria = $ctx['categoria']       ?? '';
        $preco     = $ctx['preco']           ?? '';
        $descricao = $ctx['descricao']       ?? ($ctx['descricao_curta'] ?? '');

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

    private function promptCategoria(array $ctx): string
    {
        $nome      = $ctx['nome']      ?? '';
        $descricao = $ctx['descricao'] ?? '';
        $parent    = $ctx['parent']    ?? '';

        return <<<PROMPT
        Contexto: CATEGORIA de e-commerce.

        Nome da categoria: {$nome}
        Categoria pai: {$parent}
        Descrição: {$descricao}

        Gere os campos SEO para esta categoria.
        Para meta_title, use o formato: "{$nome} | Sportmoto"
        Para google_category, use a categoria mais adequada aos produtos desta seção.
        PROMPT;
    }

    private function promptMarca(array $ctx): string
    {
        $nome      = $ctx['nome']      ?? '';
        $descricao = $ctx['descricao'] ?? '';

        return <<<PROMPT
        Contexto: PÁGINA DE MARCA em e-commerce.

        Nome da marca: {$nome}
        Descrição: {$descricao}

        Gere os campos SEO para a página desta marca.
        Para meta_title: "Produtos {$nome} | Sportmoto"
        Para google_category, use a categoria mais representativa da marca.
        PROMPT;
    }

    private function promptPagina(array $ctx): string
    {
        $titulo   = $ctx['titulo']   ?? '';
        $conteudo = $ctx['conteudo'] ?? '';

        return <<<PROMPT
        Contexto: PÁGINA INSTITUCIONAL de e-commerce.

        Título: {$titulo}
        Conteúdo resumido: {$conteudo}

        Gere os campos SEO para esta página.
        Para google_category: use "Sem categoria" se não for aplicável.
        PROMPT;
    }

    /* ── Sanitização (inalterada) ─────────────────────────── */

    private function sanitizar(string $valor, int $maxLen): string
    {
        $valor = strip_tags($valor);
        $valor = preg_replace('/\s+/', ' ', $valor);
        return mb_substr(trim((string) $valor), 0, $maxLen);
    }

    private function sanitizarKeywords(string $keywords): string
    {
        $lista = array_map('trim', explode(',', mb_strtolower($keywords)));
        $lista = array_filter($lista);
        $lista = array_unique($lista);
        $lista = array_slice($lista, 0, 10);
        return implode(', ', $lista);
    }

    /* ── Internos ─────────────────────────────────────────── */

    /** JSON nativo garante o parse; a tolerância a cercas fica de cinto de segurança. */
    private function decodificarJsonTolerante(string $texto): ?array
    {
        $texto = trim($texto);
        if (strpos($texto, '```') === 0) {
            $texto = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $texto);
        }
        $dec = json_decode((string) $texto, true);
        return is_array($dec) ? $dec : null;
    }

    private function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
