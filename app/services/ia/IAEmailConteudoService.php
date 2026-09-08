<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/ia/IAEmailConteudoService.php
// ════════════════════════════════════════════════════════

/**
 * Conteúdo de e-mail por segmento — Fase 2.
 *
 * Pega o esqueleto da Fase 1, escolhe os produtos por critério, pede à IA os
 * TEXTOS que faltam, e grava um template novo com vitrine e copy embutidas.
 *
 * TRÊS DIVISÕES DE TRABALHO, E ELAS IMPORTAM
 *   Produtos  → SQL. "Quais produtos" é consulta, não interpretação. Usar LLM
 *               aqui seria caro, lento e menos confiável que um ORDER BY.
 *   Loja      → tabela `configuracoes`. Endereço e logo são DADO, e a IA que
 *               inventasse um endereço seria um desastre.
 *   Copy      → IA. Título, subtítulo, texto do botão. É onde ela agrega.
 *
 * POR QUE PRÉ-RENDERIZA EM VEZ DE PASSAR VARIÁVEIS NO ENVIO
 *   Nenhum caminho de campanha alimenta o render() com conteúdo: `$vars` é
 *   cravado no cli/email-worker.php. E o render() tem whitelist de blocos —
 *   `produtos` não está nela, então o bloco da Fase 1 seria APAGADO no envio.
 *   Fazer o conteúdo chegar lá exigiria mexer no caminho de envio, que é o
 *   arquivo de maior raio de impacto do módulo de e-mail.
 *
 *   Aqui a vitrine e a copy entram no HTML, e o worker segue preenchendo só o
 *   que é por destinatário. Efeito colateral desejável: o preço fica congelado
 *   no momento em que a campanha foi montada — o cliente recebe o preço que
 *   foi anunciado.
 */
class IAEmailConteudoService
{
    private const TIPO = 'email_conteudo';

    /** Quem o cli/email-worker.php já injeta por destinatário. Não pedir à IA. */
    private const DO_WORKER = [
        'nome', 'primeiro_nome', 'email', 'cupom', 'site_nome', 'url_site',
        'url_descadastro', 'data_atual',
    ];

    /** Blocos que ESTE service expande. Fora daqui, o marcador fica intacto. */
    private const BLOCOS_PRODUTO = ['produtos', 'produtos_categoria'];

    private const MIN_PRODUTOS = 1;
    private const MAX_PRODUTOS = 12;

    private IAOrchestrator       $orq;
    private IACustoService       $custo;
    private EmailTemplateService $tpl;
    private PDO                  $db;

    public function __construct(?IAOrchestrator $orq = null)
    {
        $this->orq   = $orq ?? new IAOrchestrator();
        $this->custo = new IACustoService();
        $this->tpl   = new EmailTemplateService();
        $this->db    = Database::getInstance()->getConnection();
    }

    /* ══════════════════════════════════════════════════════
       1. Produtos — SQL, não IA
       ══════════════════════════════════════════════════════ */

    /**
     * @param array $criterio modo: todos|aleatorios|categoria|marca|escolhidos
     *                        ids: int[] (categoria/marca/escolhidos)
     *                        limite: 1..12
     *
     * Só produto VENDÁVEL entra: ativo, não apagado e com estoque. Anunciar o
     * que não dá para comprar queima a campanha e o domínio junto.
     */
    public function selecionarProdutos(array $criterio): array
    {
        $modo   = (string) ($criterio['modo'] ?? 'todos');
        $limite = max(self::MIN_PRODUTOS, min(self::MAX_PRODUTOS, (int) ($criterio['limite'] ?? 3)));
        $ids    = array_values(array_filter(array_map('intval', (array) ($criterio['ids'] ?? [])), fn ($i) => $i > 0));

        $base   = 'FROM produtos p WHERE p.ativo = 1 AND p.deleted_at IS NULL AND p.estoque_total > 0';
        $params = [];

        switch ($modo) {
            case 'aleatorios':
                $sql = "SELECT p.id, p.nome, p.slug, p.preco, p.preco_promo {$base} ORDER BY RAND() LIMIT {$limite}";
                break;

            case 'categoria':
            case 'marca':
                if ($ids === []) { return []; }
                $col = $modo === 'categoria' ? 'categoria_id' : 'marca_id';
                $ph  = implode(',', array_fill(0, count($ids), '?'));
                $sql = "SELECT p.id, p.nome, p.slug, p.preco, p.preco_promo {$base}
                          AND p.{$col} IN ({$ph})
                     ORDER BY p.vendidos DESC, p.destaque DESC, p.id DESC LIMIT {$limite}";
                $params = $ids;
                break;

            case 'escolhidos':
                if ($ids === []) { return []; }
                $ids = array_slice($ids, 0, $limite);
                $ph  = implode(',', array_fill(0, count($ids), '?'));
                // FIELD() preserva a ordem em que o usuário escolheu — a vitrine
                // tem hierarquia, e a primeira posição é a que mais converte.
                $sql = "SELECT p.id, p.nome, p.slug, p.preco, p.preco_promo {$base}
                          AND p.id IN ({$ph}) ORDER BY FIELD(p.id, {$ph})";
                $params = array_merge($ids, $ids);
                break;

            case 'todos':
            default:
                $sql = "SELECT p.id, p.nome, p.slug, p.preco, p.preco_promo {$base}
                     ORDER BY p.vendidos DESC, p.destaque DESC, p.id DESC LIMIT {$limite}";
        }

        $st = $this->db->prepare($sql);
        $st->execute($params);

        return array_map(fn ($p) => $this->itemDeVitrine($p), $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Um item da vitrine, servindo AS DUAS convenções de nome que já existem
     * no projeto: `produto_*` (esqueletos gerados pela Fase 1) e
     * `nome/preco/url/imagem_principal` (templates escritos à mão, como o
     * "CATEGORIA VISITADA"). Servir só uma quebraria a outra.
     */
    private function itemDeVitrine(array $p): array
    {
        $preco = (float) $p['preco'];
        $promo = (float) ($p['preco_promo'] ?? 0);
        $vale  = ($promo > 0 && $promo < $preco) ? $promo : $preco;

        $url = (defined('BASE_URL') ? BASE_URL : '') . '/produto/' . $p['slug'];
        $img = class_exists('ImageHelper') ? (string) ImageHelper::getPrincipal((int) $p['id']) : '';

        return [
            'id'               => (int) $p['id'],
            // convenção do projeto
            'nome'             => (string) $p['nome'],
            'preco'            => $this->brl($vale),
            'url'              => $url,
            'imagem_principal' => $img,
            // convenção dos esqueletos da Fase 1
            'produto_nome'     => (string) $p['nome'],
            'produto_preco'    => $this->brl($vale),
            'produto_url'      => $url,
            'produto_imagem'   => $img,
        ];
    }

    private function brl(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    /* ══════════════════════════════════════════════════════
       2. Loja — dado, não invenção
       ══════════════════════════════════════════════════════ */

    /** Constantes da loja para as variáveis que a IA não deve inventar. */
    public function constantesDaLoja(): array
    {
        $cfg = [];
        try {
            foreach ($this->db->query('SELECT chave, valor FROM configuracoes')->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                $cfg[$k] = (string) $v;
            }
        } catch (\Throwable $e) {
            LogService::error('ia_email_conteudo_config_erro', ['erro' => $e->getMessage()]);
        }

        $base = defined('BASE_URL') ? BASE_URL : '';
        $logo = (string) ($cfg['site_logo'] ?? '');
        // O logo vem raiz-relativo em configuracoes; no e-mail ele precisa ser
        // ABSOLUTO — não existe "raiz" dentro do Gmail.
        if ($logo !== '' && !preg_match('#^https?://#i', $logo)) {
            $logo = $base . '/' . ltrim($logo, '/');
        }

        $endereco = trim(implode(', ', array_filter([
            $cfg['endereco_logradouro'] ?? '',
            $cfg['endereco_cidade'] ?? '',
            $cfg['endereco_uf'] ?? '',
        ])));

        return array_filter([
            'logo_loja'      => $logo,
            'site_logo'      => $logo,
            'nome_loja'      => $cfg['site_nome'] ?? '',
            'endereco_loja'  => $endereco,
            'endereco'       => $endereco,
            'site_email'     => $cfg['site_email'] ?? '',
            'site_telefone'  => $cfg['site_telefone'] ?? '',
            'telefone_loja'  => $cfg['site_telefone'] ?? '',
            // URL de CTA é DADO, não copy: um modelo pedindo para "escrever" uma
            // URL inventa um caminho que não existe e o clique cai em 404. O
            // padrão é a home; o humano troca no formulário se for outra página.
            'cta_url'        => $base,
            'url_cta'        => $base,
            'link_cta'       => $base,
        ], fn ($v) => $v !== '');
    }

    /* ══════════════════════════════════════════════════════
       3. Quais variáveis sobram para a IA
       ══════════════════════════════════════════════════════ */

    /**
     * As variáveis do esqueleto que ninguém mais preenche.
     *
     * Descobre a partir do PRÓPRIO template, não de uma lista fixa: o
     * esqueleto declara o que precisa, e o que sobra depois de tirar o que o
     * worker injeta, o que a vitrine preenche e o que a loja fornece é
     * exatamente o buraco que a IA tem de tapar.
     *
     * @return array{copy:string[], loja:string[], blocos:string[]}
     */
    public function classificarVariaveis(string $html): array
    {
        $blocos = [];
        preg_match_all('/\{\{\s*[#?]\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $html, $mb);
        foreach ($mb[1] ?? [] as $b) { $blocos[$b] = true; }

        // Só o que o ITEM da vitrine realmente fornece sai da conta. Excluir
        // tudo que aparece dentro do bloco era largo demais: um {{cta_texto}}
        // no botão de cada produto não é campo de produto, ninguém preenchia,
        // e o marcador CRU chegava ao cliente — três vezes.
        $doItem = array_flip(array_keys($this->itemDeVitrine(
            ['id' => 0, 'nome' => '', 'slug' => '', 'preco' => 0, 'preco_promo' => 0]
        )));

        $loja   = $this->constantesDaLoja();
        $copy   = [];
        $daLoja = [];

        // Nome de bloco que NÃO é vitrine também é decisão de campanha: é o
        // humano (ou a IA) que diz se o selo aparece e o que ele diz. Sem isto
        // o {{?selo_destaque}}…{{/selo_destaque}} sobrevivia inteiro no envio.
        foreach (array_keys($blocos) as $b) {
            if (in_array($b, self::BLOCOS_PRODUTO, true)) { continue; }
            if (in_array($b, self::DO_WORKER, true) || isset($loja[$b])) { continue; }
            $copy[] = $b;
        }

        foreach ($this->tpl->extrairVariaveis($html) as $v) {
            if (in_array($v, self::DO_WORKER, true)) { continue; }
            if (isset($doItem[$v]))  { continue; }
            if (isset($loja[$v]))    { $daLoja[] = $v; continue; }
            $copy[] = $v;
        }

        return [
            'copy'   => array_values(array_unique($copy)),
            'loja'   => array_values(array_unique($daLoja)),
            'blocos' => array_keys($blocos),
        ];
    }

    /* ══════════════════════════════════════════════════════
       4. Geração da copy
       ══════════════════════════════════════════════════════ */

    /**
     * Pede à IA um valor para cada variável de copy.
     *
     * @return array{ok:bool, valores:array, notas:string, _ia:array}
     * @throws RuntimeException com mensagem exibível
     */
    public function gerarConteudo(array $produtos, array $variaveis, string $briefing, array $opcoes = [], ?int $modeloId = null): array
    {
        if ($produtos === []) {
            throw new \RuntimeException('Escolha ao menos um produto para a vitrine.');
        }
        if ($variaveis === []) {
            throw new \RuntimeException('O layout não tem nenhuma variável de texto para preencher.');
        }

        $tipoRow = (new IATipoConteudo())->buscarPorCodigo(self::TIPO);
        if ($tipoRow === null || (int) $tipoRow['ativo'] !== 1) {
            throw new \RuntimeException('Tipo email_conteudo ausente — rode sql/ia/2026-09-08_ia_email_conteudo.sql.');
        }

        $usuarioId = (int) AuthHelper::usuarioId();
        if ($usuarioId <= 0) {
            throw new \RuntimeException('Sessão inválida para registrar a geração.');
        }

        $modeloEscolhido = $modeloId !== null ? $this->validarModeloTexto($modeloId) : null;
        $prompt = $this->montarPrompt($produtos, $variaveis, $briefing, $opcoes);

        $custoEst = $this->custo->estimarTexto(
            $this->custo->custoConfigPrimarioTexto(),
            mb_strlen($prompt) + mb_strlen((string) $tipoRow['instrucoes_sistema']),
            (int) $tipoRow['max_tokens']
        );
        $chk = $this->custo->podeGerar($usuarioId, $custoEst, 1);
        if (!$chk['ok']) {
            throw new \RuntimeException($chk['msg']);
        }

        $uuid = $this->uuidV4();
        $id   = (new IAGeracao())->criar([
            'uuid'                     => $uuid,
            'usuario_id'               => $usuarioId,
            'produto_id'               => null,   // são vários; ficam no contexto
            'campanha_id'              => null,
            'geracao_origem_id'        => null,
            'tipo_conteudo_id'         => (int) $tipoRow['id'],
            'capacidade'               => 'texto',
            'formato'                  => null,
            'angulo'                   => null,
            'prompt_template_id'       => null,
            'prompt_template_snapshot' => null,
            'prompt_final'             => $prompt,
            'contexto'                 => json_encode([
                'briefing'  => mb_substr($briefing, 0, 2000),
                'opcoes'    => $opcoes,
                'produtos'  => array_column($produtos, 'id'),
                'variaveis' => $variaveis,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'chave_dedup'              => hash('sha256', uniqid('email_conteudo|', true)),
            'custo_estimado_usd'       => $custoEst,
            'status'                   => 'processando',
        ]);

        if ($id <= 0) {
            throw new \RuntimeException('Não foi possível registrar a geração do conteúdo.');
        }

        $geracao = [
            'id' => $id, 'uuid' => $uuid, 'usuario_id' => $usuarioId,
            'capacidade' => 'texto', 'prompt_final' => $prompt, 'custo_estimado_usd' => $custoEst,
        ];
        $tipoArr = [
            'instrucoes_sistema' => $tipoRow['instrucoes_sistema'],
            'max_tokens'         => (int) $tipoRow['max_tokens'],
            'modelo_id'          => $modeloEscolhido ?? $tipoRow['modelo_id'],
            'nome'               => $tipoRow['nome'],
            'saida'              => $tipoRow['saida'] ?? 'json',
        ];

        $servico = new IAGeracaoService();

        // Orquestrador que LANÇA deixaria a linha presa em 'processando', e o
        // watchdog a devolveria à fila para o worker regerar o que ninguém
        // espera. Fecha o ciclo de vida.
        try {
            $r = $this->orq->executarTexto($geracao, $tipoArr);
        } catch (\Throwable $e) {
            $servico->falhar($geracao, IAResultado::falha('excecao', mb_substr($e->getMessage(), 0, 500), false));
            throw $e;
        }

        if (!$r->ok) {
            $servico->falhar($geracao, $r);
            throw new \RuntimeException('Conteúdo: ' . ($r->erro ?: 'geração falhou em todos os provedores.'));
        }

        $dados = $this->decodificarJsonTolerante((string) $r->texto);
        if ($dados === null) {
            $servico->falhar($geracao, IAResultado::falha('json_invalido', 'Provedor não devolveu JSON.', false));
            throw new \RuntimeException('O provedor não devolveu um JSON válido.');
        }

        $servico->concluir($geracao, $r);

        // Só as variáveis PEDIDAS entram. Chave extra inventada pelo modelo é
        // descartada — ela não tem lugar no layout e só confundiria a tela.
        $valores = [];
        foreach ($variaveis as $v) {
            $val = $dados[$v] ?? '';
            if (is_array($val) || is_object($val)) { $val = ''; }
            $valores[$v] = mb_substr(trim((string) $val), 0, 500);
        }

        return [
            'ok'      => true,
            'valores' => $valores,
            'notas'   => mb_substr(trim((string) ($dados['_notas'] ?? '')), 0, 1000),
            '_ia' => [
                'geracao_id' => $id,
                'provedor'   => (string) ($r->provedorCodigo ?? ''),
                'modelo'     => (string) ($r->modeloCodigo ?? ''),
                'rotulo'     => $this->rotuloModelo((string) ($r->provedorCodigo ?? ''), (string) ($r->modeloCodigo ?? '')),
                'custo_usd'  => $r->custoRealUsd,
                'tempo_ms'   => $r->tempoMs,
                'trocou'     => $modeloEscolhido !== null && $r->modeloId !== $modeloEscolhido,
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════
       5. O renderizador PARCIAL
       ══════════════════════════════════════════════════════ */

    /**
     * Expande a vitrine e os valores de copy, deixando TUDO O MAIS INTACTO.
     *
     * Não dá para usar o render() compartilhado aqui: ele substitui variável
     * desconhecida por string vazia, o que apagaria {{nome}} e
     * {{url_descadastro}} — justamente o que o worker precisa encontrar no
     * envio. Este método só toca no que recebeu.
     *
     * O escape é o mesmo do render() compartilhado (htmlspecialchars com
     * ENT_QUOTES): o nome do produto vem do banco e vai para dentro de
     * atributo HTML.
     */
    public function montar(string $html, array $produtos, array $valores): string
    {
        // 1. Blocos de produto
        foreach (self::BLOCOS_PRODUTO as $bloco) {
            $html = (string) preg_replace_callback(
                '/\{\{#' . $bloco . '\}\}(.*?)\{\{\/' . $bloco . '\}\}/s',
                function ($m) use ($produtos, $valores) {
                    $saida = '';
                    foreach ($produtos as $item) {
                        if (!is_array($item)) { continue; }
                        $saida .= preg_replace_callback(
                            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
                            function ($v) use ($item, $valores) {
                                // Item primeiro; depois a copy da campanha — um
                                // {{cta_texto}} no botão de cada produto é texto
                                // da campanha, não campo do produto. Só o que
                                // não for de ninguém fica intacto (é do worker).
                                if (array_key_exists($v[1], $item)) {
                                    return htmlspecialchars((string) $item[$v[1]], ENT_QUOTES, 'UTF-8');
                                }
                                if (array_key_exists($v[1], $valores)) {
                                    return htmlspecialchars((string) $valores[$v[1]], ENT_QUOTES, 'UTF-8');
                                }
                                return $v[0];
                            },
                            $m[1]
                        );
                    }
                    return $saida;
                },
                $html
            );
        }

        // 2. Condicionais {{?x}}…{{/x}} — só para as chaves que eu conheço
        $html = (string) preg_replace_callback(
            '/\{\{\?([a-zA-Z_][a-zA-Z0-9_]*)\}\}(.*?)\{\{\/\1\}\}/s',
            function ($m) use ($valores) {
                if (!array_key_exists($m[1], $valores)) { return $m[0]; }   // não é meu
                $v = $valores[$m[1]];
                return ($v === '' || $v === null || $v === false) ? '' : $m[2];
            },
            $html
        );

        // 3. Variáveis simples — só as minhas
        $html = (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            function ($m) use ($valores) {
                return array_key_exists($m[1], $valores)
                    ? htmlspecialchars((string) $valores[$m[1]], ENT_QUOTES, 'UTF-8')
                    : $m[0];
            },
            $html
        );

        return $html;
    }

    /* ══════════════════════════════════════════════════════
       6. Persistência
       ══════════════════════════════════════════════════════ */

    /**
     * Cria o template da campanha — sempre RASCUNHO.
     *
     * @return array{ok:bool, template_id?:int, msg?:string}
     */
    public function salvarComoTemplate(
        string $nome,
        string $html,
        string $assunto,
        string $preheader,
        int $geracaoId,
        ?int $templateBaseId = null,
        array $criterio = [],
        array $produtos = [],
        string $briefing = ''
    ): array {
        $nome    = trim($nome);
        $assunto = trim($assunto);

        if (mb_strlen($nome) < 3)  { return ['ok' => false, 'msg' => 'Dê um nome à campanha (mínimo 3 caracteres).']; }
        if ($assunto === '')       { return ['ok' => false, 'msg' => 'O assunto não pode ficar vazio.']; }

        $avisos = [];
        $html   = $this->tpl->sanitizeHtml($html, $avisos);

        // O que o worker PRECISA encontrar no envio. Se o pré-render tivesse
        // comido esses marcadores, cada destinatário receberia um e-mail sem
        // nome e — pior — sem link de descadastro, que é exigência legal.
        if (!str_contains($html, '{{url_descadastro}}')) {
            return ['ok' => false, 'msg' => 'O HTML perdeu o {{url_descadastro}} — o envio ficaria sem link de descadastro.'];
        }

        // Todo marcador que sobrar tem de ser um que o worker preencha. O que
        // não for chega CRU ao cliente — foi o que aconteceu com um
        // {{cta_texto}} dentro do bloco de produto, três vezes no mesmo e-mail.
        $sobrando = $this->marcadoresOrfaos($html);
        if ($sobrando !== []) {
            return ['ok' => false, 'msg' => 'Sobraram marcadores que ninguém preenche no envio: '
                . implode(', ', array_slice($sobrando, 0, 5)) . '. Preencha o texto correspondente.'];
        }

        if (!$this->geracaoValida($geracaoId)) {
            return ['ok' => false, 'msg' => 'Geração inválida para este template.'];
        }

        try {
            $usuarioId = (int) AuthHelper::usuarioId();
            $this->db->beginTransaction();

            $templateId = (new EmailTemplate())->save([
                'nome'          => mb_substr($nome, 0, 190),
                'tipo'          => 'marketing',
                'formato'       => 'manual',
                'assunto'       => mb_substr($assunto, 0, 255),
                'preheader'     => mb_substr(trim($preheader), 0, 190) ?: null,
                'html'          => $html,
                'texto'         => $this->tpl->htmlToText($html),
                'status'        => 'rascunho',
                'render_status' => $avisos === [] ? 'ok' : 'warning',
                'render_log'    => $avisos === [] ? null : implode(' · ', $avisos),
            ], $usuarioId ?: null);

            $st = $this->db->prepare('UPDATE email_templates SET variaveis_json = :v WHERE id = :id');
            $st->execute([
                ':v'  => json_encode($this->tpl->extrairVariaveis($html), JSON_UNESCAPED_UNICODE),
                ':id' => $templateId,
            ]);

            $st = $this->db->prepare(
                'INSERT INTO ia_email_conteudo_geracao
                    (template_final_id, template_base_id, geracao_id, criterio_json, produtos_ids, briefing, criado_por)
                 VALUES (:tf, :tb, :g, :c, :p, :b, :u)'
            );
            $st->execute([
                ':tf' => $templateId,
                ':tb' => $templateBaseId ?: null,
                ':g'  => $geracaoId,
                ':c'  => $criterio === [] ? null : json_encode($criterio, JSON_UNESCAPED_UNICODE),
                ':p'  => implode(',', array_slice(array_column($produtos, 'id'), 0, 60)) ?: null,
                ':b'  => mb_substr($briefing, 0, 2000) ?: null,
                ':u'  => $usuarioId ?: null,
            ]);

            $this->db->commit();

            LogService::audit('ia_email_conteudo_salvo', [
                'template_id' => $templateId, 'geracao_id' => $geracaoId,
                'produtos'    => count($produtos), 'usuario_id' => $usuarioId,
            ]);

            return ['ok' => true, 'template_id' => $templateId];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            LogService::error('ia_email_conteudo_salvar_erro', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'msg' => 'Não foi possível salvar o template.'];
        }
    }

    /* ══════════════════════════════════════════════════════
       Apoio
       ══════════════════════════════════════════════════════ */

    /**
     * Marcadores que sobreviveram e que NINGUÉM vai preencher no envio.
     *
     * O worker só conhece a lista DO_WORKER. Qualquer outro `{{x}}`, `{{#x}}`
     * ou `{{?x}}` que chegue ao template final vira texto cru na caixa de
     * entrada do cliente.
     *
     * @return string[] nomes, sem repetição
     */
    public function marcadoresOrfaos(string $html): array
    {
        preg_match_all('/\{\{\s*[#?\/]?\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $html, $m);

        $orfaos = [];
        foreach ($m[1] ?? [] as $nome) {
            if (!in_array($nome, self::DO_WORKER, true)) { $orfaos[$nome] = true; }
        }
        return array_keys($orfaos);
    }

    /** Esqueletos disponíveis: templates de marketing com bloco de produto. */
    public function esqueletosDisponiveis(): array
    {
        try {
            $linhas = $this->db->query(
                "SELECT id, nome, status, assunto, preheader, html
                   FROM email_templates
                  WHERE tipo = 'marketing' AND status IN ('rascunho','ativo')
               ORDER BY atualizado_em DESC LIMIT 100"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $saida = [];
        foreach ($linhas as $l) {
            $temBloco = (bool) preg_match(
                '/\{\{#(' . implode('|', self::BLOCOS_PRODUTO) . ')\}\}/', (string) $l['html']
            );
            $saida[] = [
                'id'        => (int) $l['id'],
                'nome'      => (string) $l['nome'],
                'status'    => (string) $l['status'],
                'assunto'   => (string) $l['assunto'],
                'preheader' => (string) ($l['preheader'] ?? ''),
                'vitrine'   => $temBloco,
            ];
        }
        return $saida;
    }

    public function esqueleto(int $id): ?array
    {
        $st = $this->db->prepare('SELECT id, nome, assunto, preheader, html FROM email_templates WHERE id = :id LIMIT 1');
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Categorias e marcas que têm produto vendável — só o que dá vitrine. */
    public function filtrosDisponiveis(): array
    {
        $q = fn (string $sql) => $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [
            'categorias' => $q("SELECT c.id, c.nome, COUNT(p.id) n FROM categorias c
                             INNER JOIN produtos p ON p.categoria_id = c.id
                                    AND p.ativo = 1 AND p.deleted_at IS NULL AND p.estoque_total > 0
                                  GROUP BY c.id, c.nome ORDER BY n DESC, c.nome"),
            'marcas'     => $q("SELECT m.id, m.nome, COUNT(p.id) n FROM marcas m
                             INNER JOIN produtos p ON p.marca_id = m.id
                                    AND p.ativo = 1 AND p.deleted_at IS NULL AND p.estoque_total > 0
                                  GROUP BY m.id, m.nome ORDER BY n DESC, m.nome"),
        ];
    }

    public function modelosDisponiveis(): array
    {
        try {
            $tipoRow = (new IATipoConteudo())->buscarPorCodigo(self::TIPO);
            $pinado  = $tipoRow !== null ? (int) ($tipoRow['modelo_id'] ?? 0) : 0;

            $linhas = $this->db->query(
                "SELECT m.id, m.codigo_modelo, m.nome, p.codigo AS prov
                   FROM ia_modelos m
             INNER JOIN ia_provedores p ON p.id = m.provedor_id AND p.ativo = 1 AND p.api_key_enc IS NOT NULL
                  WHERE m.capacidade = 'texto' AND m.ativo = 1
               ORDER BY m.prioridade ASC, m.id ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(fn ($m) => [
                'id'     => (int) $m['id'],
                'rotulo' => $this->rotuloModelo((string) $m['prov'], (string) $m['codigo_modelo'], (string) $m['nome']),
                'padrao' => (int) $m['id'] === $pinado,
            ], $linhas);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function montarPrompt(array $produtos, array $variaveis, string $briefing, array $opcoes): string
    {
        $p = "BRIEFING\n" . trim($briefing) . "\n\n";
        foreach (['objetivo' => 'Objetivo', 'publico' => 'Público', 'tom' => 'Tom'] as $k => $rot) {
            $v = trim((string) ($opcoes[$k] ?? ''));
            if ($v !== '') { $p .= "{$rot}: {$v}\n"; }
        }

        $p .= "\nPRODUTOS DA VITRINE (use só o que está aqui):\n";
        foreach ($produtos as $i => $item) {
            $p .= ($i + 1) . '. ' . $item['nome'] . ' — ' . $item['preco'] . "\n";
        }

        $p .= "\nVARIÁVEIS A PREENCHER (uma chave para cada, exatamente com este nome):\n";
        foreach ($variaveis as $v) { $p .= "- {$v}\n"; }

        $p .= "\nResponda somente com o JSON.";
        return $p;
    }

    private function validarModeloTexto(int $modeloId): ?int
    {
        if ($modeloId <= 0) { return null; }
        try {
            $st = $this->db->prepare(
                "SELECT m.id FROM ia_modelos m
              INNER JOIN ia_provedores p ON p.id = m.provedor_id AND p.ativo = 1 AND p.api_key_enc IS NOT NULL
                   WHERE m.id = :id AND m.capacidade = 'texto' AND m.ativo = 1 LIMIT 1"
            );
            $st->execute([':id' => $modeloId]);
            $ok = $st->fetchColumn();
            return $ok === false ? null : (int) $ok;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function geracaoValida(int $geracaoId): bool
    {
        if ($geracaoId <= 0) { return false; }
        try {
            $st = $this->db->prepare(
                "SELECT g.id FROM ia_geracoes g
              INNER JOIN ia_tipos_conteudo t ON t.id = g.tipo_conteudo_id
                   WHERE g.id = :id AND t.codigo = :c AND g.status = 'concluida' LIMIT 1"
            );
            $st->execute([':id' => $geracaoId, ':c' => self::TIPO]);
            return $st->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function rotuloModelo(string $provedor, string $modelo, string $nome = ''): string
    {
        $marcas = ['openai' => 'OpenAI', 'gemini' => 'Gemini', 'claude' => 'Claude', 'replicate' => 'Replicate'];
        $marca  = $marcas[$provedor] ?? ($provedor !== '' ? ucfirst($provedor) : 'IA');
        $curto  = $nome !== '' ? $nome : $modelo;
        foreach (['claude-', 'gemini-', 'gpt-', 'Claude ', 'Gemini '] as $pref) {
            if (stripos($curto, $pref) === 0) { $curto = substr($curto, strlen($pref)); break; }
        }
        return trim($marca . ($curto !== '' ? ' · ' . $curto : ''));
    }

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
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
