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
 *  - A chave do SEO sai do .env: fica cifrada em ia_provedores (tela de
 *    config ou cli/migrar-chave-gemini.php).
 *
 * ATENÇÃO: o GeminiService legado NÃO foi aposentado neste projeto, ao
 * contrário do que o README do pacote afirma. GeminiQAService (perguntas de
 * produto) e ReviewSummaryService (resumo de avaliações) continuam usando
 * ele, e ambos leem GEMINI_API_KEY / GEMINI_MODEL do .env. Apagar o arquivo
 * ou remover essas variáveis derruba as duas funcionalidades.
 *
 * Injeção por construtor (padrão do projeto): em produção, new SeoIaService()
 * como sempre; nos testes, injeta-se um orquestrador fake.
 */
class SeoIaService
{
    /** Entidades que compartilham o botão de SEO por IA. */
    private const ENTIDADES = ['produto', 'categoria', 'marca', 'pagina'];

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
     * Retorna os 4 campos de SEO mais a chave `_ia`, com a procedência da
     * geração (provedor, modelo, id, custo). O contrato antigo continua
     * valendo: quem só lê meta_title/meta_description/keywords/google_category
     * não percebe diferença.
     *
     * @param string $tipo     — 'produto' | 'categoria' | 'marca' | 'pagina'
     * @param array  $contexto — dados do item (nome, descricao, categoria, etc.)
     * @param string $idioma   — padrão: 'pt-BR'
     * @param ?int   $modeloId — força um modelo específico; null usa o pino do
     *                           tipo seo_pacote. SEMPRE revalidado aqui: o id
     *                           vem do navegador e não pode ser confiado.
     * @param ?array $alvo     — ['entidade' => 'produto', 'id' => 123]; liga a
     *                           geração à entidade para a procedência depois.
     */
    public function gerarSeo(
        string $tipo,
        array $contexto,
        string $idioma = 'pt-BR',
        ?int $modeloId = null,
        ?array $alvo = null
    ): array {
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

        // Alvo (opcional): normalizado aqui, uma vez.
        $alvoEntidade = (string) ($alvo['entidade'] ?? $tipo);
        $alvoId       = (int) ($alvo['id'] ?? 0);
        if (!in_array($alvoEntidade, self::ENTIDADES, true)) {
            $alvoEntidade = $tipo;
        }

        // Modelo escolhido na tela: revalidado contra o catalogo. Um id que nao
        // seja de um modelo de TEXTO ativo, com provedor ativo e com chave, e'
        // descartado em silencio — cai no pino do tipo.
        $modeloEscolhido = $modeloId !== null ? $this->validarModeloTexto($modeloId) : null;

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
            // Liga ao produto quando for um: ia_geracoes tem indice em
            // produto_id e a coluna e' especifica de produto. Categoria, marca
            // e pagina ficam no contexto (abaixo) e na ia_seo_aplicado.
            'produto_id'               => ($alvoEntidade === 'produto' && $alvoId > 0) ? $alvoId : null,
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
                ['seo_tipo' => $tipo, 'dados' => $contexto, 'idioma' => $idioma,
                 'alvo' => $alvoId > 0 ? ['entidade' => $alvoEntidade, 'id' => $alvoId] : null],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'chave_dedup'              => hash('sha256', uniqid('seo|' . $tipo . '|', true)),
            'custo_estimado_usd'       => $custoEst,
            'status'                   => 'processando',
        ]);

        // IAGeracao::criar() devolve int: id, 0 em erro ou -1062 na dedup.
        // Comparar com null nunca casava — um INSERT falho seguia adiante com
        // id 0 e o custo era lançado contra uma geração inexistente.
        if ($id <= 0) {
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
            'modelo_id'          => $modeloEscolhido ?? $tipoRow['modelo_id'],
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
            // Procedência: quem REALMENTE respondeu. Pode diferir do modelo
            // pedido — se o escolhido falhar, o orquestrador cai no próximo da
            // cadeia, e a tela precisa mostrar quem de fato gerou o texto.
            '_ia' => [
                'geracao_id' => $id,
                'provedor'   => (string) ($r->provedorCodigo ?? ''),
                'modelo'     => (string) ($r->modeloCodigo ?? ''),
                'rotulo'     => $this->rotuloModelo((string) ($r->provedorCodigo ?? ''), (string) ($r->modeloCodigo ?? '')),
                'custo_usd'  => $r->custoRealUsd,
                'tempo_ms'   => $r->tempoMs,
                'pedido'     => $modeloEscolhido,
                'trocou'     => $modeloEscolhido !== null && $r->modeloId !== $modeloEscolhido,
            ],
        ];
    }

    /* ── Catálogo, procedência e aplicação ────────────────── */

    /**
     * Modelos de TEXTO oferecidos no seletor da tela: ativos, de provedor ativo
     * e com chave. Mesmo critério do orquestrador — oferecer um modelo que ele
     * puxaria para fora da cadeia seria mentira.
     */
    public function modelosDisponiveis(): array
    {
        try {
            $tipoRow = (new IATipoConteudo())->buscarPorCodigo('seo_pacote');
            $pinado  = $tipoRow !== null ? (int) ($tipoRow['modelo_id'] ?? 0) : 0;

            $sql = "SELECT m.id, m.codigo_modelo, m.nome, m.prioridade, p.codigo AS prov, p.nome AS prov_nome
                      FROM ia_modelos m
                INNER JOIN ia_provedores p
                        ON p.id = m.provedor_id AND p.ativo = 1 AND p.api_key_enc IS NOT NULL
                     WHERE m.capacidade = 'texto' AND m.ativo = 1
                  ORDER BY m.prioridade ASC, m.id ASC";
            $linhas = Database::getInstance()->getConnection()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(fn ($m) => [
                'id'       => (int) $m['id'],
                'rotulo'   => $this->rotuloModelo((string) $m['prov'], (string) $m['codigo_modelo'], (string) $m['nome']),
                'provedor' => (string) $m['prov_nome'],
                'modelo'   => (string) $m['codigo_modelo'],
                'padrao'   => (int) $m['id'] === $pinado,
            ], $linhas);
        } catch (Throwable $e) {
            LogService::error('ia_seo_modelos_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Registra que ESTA geração virou o SEO da entidade. Chamado no clique em
     * "Aplicar": gerar e aplicar são eventos distintos, e só o segundo torna o
     * texto da IA o conteúdo da loja.
     */
    public function registrarAplicacao(int $geracaoId, string $entidade, int $entidadeId, int $usuarioId): bool
    {
        if (!in_array($entidade, self::ENTIDADES, true) || $geracaoId <= 0 || $entidadeId <= 0) {
            return false;
        }

        try {
            $db = Database::getInstance()->getConnection();

            // A geração precisa existir, ser de SEO e ter concluído — sem isso a
            // tela poderia carimbar procedência em cima de um id qualquer.
            $chk = $db->prepare(
                "SELECT g.id FROM ia_geracoes g
              INNER JOIN ia_tipos_conteudo t ON t.id = g.tipo_conteudo_id
                   WHERE g.id = :id AND t.codigo = 'seo_pacote' AND g.status = 'concluida' LIMIT 1"
            );
            $chk->execute([':id' => $geracaoId]);
            if ($chk->fetchColumn() === false) {
                return false;
            }

            $stmt = $db->prepare(
                'INSERT INTO ia_seo_aplicado (entidade, entidade_id, geracao_id, aplicado_por)
                 VALUES (:e, :eid, :g, :u) AS novo
                 ON DUPLICATE KEY UPDATE
                    geracao_id = novo.geracao_id, aplicado_por = novo.aplicado_por,
                    aplicado_em = CURRENT_TIMESTAMP'
            );
            $stmt->execute([':e' => $entidade, ':eid' => $entidadeId, ':g' => $geracaoId, ':u' => $usuarioId ?: null]);

            LogService::audit('ia_seo_aplicado', [
                'entidade'   => $entidade,
                'entidade_id' => $entidadeId,
                'geracao_id' => $geracaoId,
                'usuario_id' => $usuarioId,
            ]);
            return true;
        } catch (Throwable $e) {
            LogService::error('ia_seo_aplicar_erro', ['geracao_id' => $geracaoId, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    /** Procedência do SEO de uma entidade, ou null se nunca veio de IA. */
    public function procedencia(string $entidade, int $entidadeId): ?array
    {
        if (!in_array($entidade, self::ENTIDADES, true) || $entidadeId <= 0) {
            return null;
        }

        try {
            $stmt = Database::getInstance()->getConnection()->prepare(
                'SELECT a.geracao_id, a.aplicado_em, g.provedor_codigo, g.modelo_codigo,
                        g.custo_real_usd, u.nome AS usuario_nome
                   FROM ia_seo_aplicado a
             INNER JOIN ia_geracoes g ON g.id = a.geracao_id
              LEFT JOIN usuarios u ON u.id = a.aplicado_por
                  WHERE a.entidade = :e AND a.entidade_id = :id LIMIT 1'
            );
            $stmt->execute([':e' => $entidade, ':id' => $entidadeId]);
            $l = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$l) {
                return null;
            }

            return [
                'geracao_id'  => (int) $l['geracao_id'],
                'provedor'    => (string) $l['provedor_codigo'],
                'modelo'      => (string) $l['modelo_codigo'],
                'rotulo'      => $this->rotuloModelo((string) $l['provedor_codigo'], (string) $l['modelo_codigo']),
                'aplicado_em' => (string) $l['aplicado_em'],
                'por'         => $l['usuario_nome'] !== null ? (string) $l['usuario_nome'] : null,
            ];
        } catch (Throwable $e) {
            LogService::error('ia_seo_procedencia_erro', ['entidade' => $entidade, 'id' => $entidadeId, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** Devolve o id só se for modelo de texto, ativo, com provedor ativo e chave. */
    private function validarModeloTexto(int $modeloId): ?int
    {
        if ($modeloId <= 0) {
            return null;
        }
        try {
            $stmt = Database::getInstance()->getConnection()->prepare(
                "SELECT m.id FROM ia_modelos m
              INNER JOIN ia_provedores p ON p.id = m.provedor_id AND p.ativo = 1 AND p.api_key_enc IS NOT NULL
                   WHERE m.id = :id AND m.capacidade = 'texto' AND m.ativo = 1 LIMIT 1"
            );
            $stmt->execute([':id' => $modeloId]);
            $ok = $stmt->fetchColumn();
            return $ok === false ? null : (int) $ok;
        } catch (Throwable $e) {
            LogService::error('ia_seo_validar_modelo_erro', ['modelo_id' => $modeloId, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** "Gemini · 3.1 Flash-Lite" — nome curto, para caber num badge. */
    private function rotuloModelo(string $provedor, string $modelo, string $nome = ''): string
    {
        $marcas = ['openai' => 'OpenAI', 'gemini' => 'Gemini', 'claude' => 'Claude', 'replicate' => 'Replicate'];
        $marca  = $marcas[$provedor] ?? ($provedor !== '' ? ucfirst($provedor) : 'IA');

        $curto = $nome !== '' ? $nome : $modelo;
        // Tira o prefixo da marca do nome do modelo para não repetir no badge.
        foreach (['claude-', 'gemini-', 'gpt-', 'Claude ', 'Gemini '] as $pref) {
            if (stripos($curto, $pref) === 0) {
                $curto = substr($curto, strlen($pref));
                break;
            }
        }

        return trim($marca . ($curto !== '' ? ' · ' . $curto : ''));
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
