<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/ia/IAEmailLayoutService.php
// ════════════════════════════════════════════════════════

/**
 * Gerador de layout de e-mail pela Central de Marketing IA — Fase 1.
 *
 * A IA produz o ESQUELETO HTML de um template, com os blocos repetíveis que
 * o EmailTemplateService já sabe renderizar. Um humano revisa, testa e ativa;
 * daí em diante o template serve N campanhas e o custo de IA por e-mail
 * enviado é ZERO.
 *
 * O contrário — IA escrevendo HTML a cada envio — quebraria no Outlook (motor
 * do Word: sem flex, sem grid, tudo em tabela) e só apareceria depois do
 * disparo, com a reputação do domínio já gasta.
 *
 * GERAR ≠ SALVAR. `gerarLayout()` não grava template nenhum: devolve o HTML
 * saneado, os avisos e as variáveis para a tela mostrar. Só
 * `salvarComoTemplate()` cria a linha em email_templates — e sempre como
 * RASCUNHO. Ativar continua sendo ato humano na tela de templates, depois de
 * um envio de teste. Mesmo princípio do SEO (ver SeoIaService).
 *
 * TRÊS PORTÕES antes de qualquer HTML virar template:
 *   1. sanitizeHtml()   — remove script, on*, javascript:, data: não-imagem
 *   2. marcadores       — {{#x}} sem {{/x}} quebra o render EM MASSA depois
 *   3. tamanho          — HTML absurdo é sintoma de modelo alucinando
 */
class IAEmailLayoutService
{
    /** Código do tipo de sistema na Central. */
    private const TIPO = 'email_layout';

    /** Gmail corta o e-mail em ~102 KB; acima disso o rodapé some. */
    private const HTML_MAX_BYTES = 100000;

    /** Abaixo disso não é um layout, é uma frase. */
    private const HTML_MIN_BYTES = 400;

    private IAOrchestrator      $orq;
    private IACustoService      $custo;
    private EmailTemplateService $tpl;

    public function __construct(?IAOrchestrator $orq = null)
    {
        $this->orq   = $orq ?? new IAOrchestrator();
        $this->custo = new IACustoService();
        $this->tpl   = new EmailTemplateService();
    }

    /* ══════════════════════════════════════════════════════
       Geração
       ══════════════════════════════════════════════════════ */

    /**
     * Gera o esqueleto. NÃO grava template — devolve para a tela revisar.
     *
     * @param array $opcoes objetivo, publico, tom, paleta (campos_briefing)
     * @param ?int  $modeloId  escolha da tela; SEMPRE revalidada aqui, porque
     *                         o id vem do navegador e não pode ser confiado.
     *
     * @return array{ok:bool, html:string, assunto:string, preheader:string,
     *               variaveis:array, notas:string, avisos:array, _ia:array}
     * @throws RuntimeException com mensagem exibível ao usuário
     */
    public function gerarLayout(string $briefing, array $opcoes = [], ?int $modeloId = null): array
    {
        $briefing = trim($briefing);
        if (mb_strlen($briefing) < 15) {
            throw new \RuntimeException('Descreva o layout com um pouco mais de detalhe (mínimo 15 caracteres).');
        }

        $tipoRow = (new IATipoConteudo())->buscarPorCodigo(self::TIPO);
        if ($tipoRow === null || (int) $tipoRow['ativo'] !== 1) {
            throw new \RuntimeException('Tipo email_layout ausente ou inativo — rode sql/ia/2026-09-08_ia_email_layout.sql.');
        }

        $usuarioId = (int) AuthHelper::usuarioId();
        if ($usuarioId <= 0) {
            throw new \RuntimeException('Sessão inválida para registrar a geração.');
        }

        $modeloEscolhido = $modeloId !== null ? $this->validarModeloTexto($modeloId) : null;
        $prompt          = $this->montarPrompt($briefing, $opcoes);

        // Teto de gasto vale aqui como em toda geração da Central.
        $custoEst = $this->custo->estimarTexto(
            $this->custo->custoConfigPrimarioTexto(),
            mb_strlen($prompt) + mb_strlen((string) $tipoRow['instrucoes_sistema']),
            (int) $tipoRow['max_tokens']
        );
        $chk = $this->custo->podeGerar($usuarioId, $custoEst, 1);
        if (!$chk['ok']) {
            throw new \RuntimeException($chk['msg']);
        }

        // Nasce 'processando': execução síncrona (o Ajax espera), então o
        // worker NUNCA pode reivindicar esta linha e gerar de novo.
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
            'contexto'                 => json_encode([
                'briefing' => mb_substr($briefing, 0, 2000),
                'opcoes'   => $opcoes,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'chave_dedup'              => hash('sha256', uniqid('email_layout|', true)),
            'custo_estimado_usd'       => $custoEst,
            'status'                   => 'processando',
        ]);

        // criar() devolve int: id, 0 em erro, -1062 na dedup. Comparar com
        // null nunca casa — o defeito que já apareceu em quatro services.
        if ($id <= 0) {
            throw new \RuntimeException('Não foi possível registrar a geração do layout.');
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

        // Orquestrador que LANÇA deixaria a linha presa em 'processando'
        // para sempre — e o watchdog a devolveria à fila para o worker
        // regerar um layout que ninguém espera. Fecha o ciclo de vida.
        try {
            $r = $this->orq->executarTexto($geracao, $tipoArr);
        } catch (\Throwable $e) {
            $servico->falhar($geracao, IAResultado::falha('excecao', mb_substr($e->getMessage(), 0, 500), false));
            throw $e;
        }

        if (!$r->ok) {
            $servico->falhar($geracao, $r);
            throw new \RuntimeException('Layout: ' . ($r->erro ?: 'geração falhou em todos os provedores.'));
        }

        $dados = $this->decodificarJsonTolerante((string) $r->texto);
        if ($dados === null || !isset($dados['html'])) {
            $servico->falhar($geracao, IAResultado::falha('json_invalido', 'Provedor não devolveu o JSON esperado.', false));
            throw new \RuntimeException('O provedor não devolveu um JSON com o campo html.');
        }

        // Portões 1 a 3. Falhar aqui é falha de CONTEÚDO, não de infra: a
        // geração conclui (o provedor respondeu e cobrou) e o erro sobe para
        // a tela. Marcar falha inflaria a taxa de erro do painel.
        $servico->concluir($geracao, $r);

        $avisos = [];
        $html   = $this->tpl->sanitizeHtml((string) $dados['html'], $avisos);

        $bytes = strlen($html);
        if ($bytes < self::HTML_MIN_BYTES) {
            throw new \RuntimeException("O modelo devolveu HTML curto demais ({$bytes} bytes) — regenere.");
        }
        if ($bytes > self::HTML_MAX_BYTES) {
            throw new \RuntimeException(
                'HTML com ' . number_format($bytes / 1024, 1, ',', '.') . ' KB — o Gmail corta acima de ~102 KB. Regenere pedindo algo mais enxuto.'
            );
        }

        $erroMarcador = $this->validarMarcadores($html);
        if ($erroMarcador !== null) {
            throw new \RuntimeException('Marcador desbalanceado: ' . $erroMarcador . '. Regenere.');
        }

        return [
            'ok'         => true,
            'html'       => $html,
            'assunto'    => mb_substr(trim((string) ($dados['assunto'] ?? '')), 0, 255),
            'preheader'  => mb_substr(trim((string) ($dados['preheader'] ?? '')), 0, 190),
            'variaveis'  => $this->variaveisDe($html),
            'notas'      => mb_substr(trim((string) ($dados['notas'] ?? '')), 0, 1000),
            'avisos'     => $avisos,
            'bytes'      => $bytes,
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

    /* ══════════════════════════════════════════════════════
       Persistência — sempre RASCUNHO
       ══════════════════════════════════════════════════════ */

    /**
     * Cria o template a partir de um layout já gerado e revisado.
     *
     * Sempre `status = rascunho`: ativar é ato humano na tela de templates,
     * depois de um envio de teste. Um layout que nasce ativo pode ir para a
     * base inteira sem nunca ter sido aberto num cliente de e-mail.
     *
     * @return array{ok:bool, template_id?:int, msg?:string}
     */
    public function salvarComoTemplate(
        string $nome,
        string $html,
        string $assunto,
        string $preheader,
        int $geracaoId,
        string $briefing = ''
    ): array {
        $nome    = trim($nome);
        $assunto = trim($assunto);

        if (mb_strlen($nome) < 3) {
            return ['ok' => false, 'msg' => 'Dê um nome ao template (mínimo 3 caracteres).'];
        }
        if ($assunto === '') {
            return ['ok' => false, 'msg' => 'O assunto não pode ficar vazio.'];
        }

        // O HTML volta do navegador: ressaneia. Confiar no que foi devolvido
        // na geração deixaria uma janela para alterar o corpo no meio.
        $avisos = [];
        $html   = $this->tpl->sanitizeHtml($html, $avisos);

        if ($this->validarMarcadores($html) !== null) {
            return ['ok' => false, 'msg' => 'O HTML tem marcador desbalanceado e quebraria o envio.'];
        }
        if (strlen($html) < self::HTML_MIN_BYTES) {
            return ['ok' => false, 'msg' => 'HTML curto demais para virar template.'];
        }

        // A geração precisa existir, ser deste tipo e ter concluído — sem
        // isso a tela carimbaria procedência em cima de um id qualquer.
        if (!$this->geracaoValida($geracaoId)) {
            return ['ok' => false, 'msg' => 'Geração inválida para este template.'];
        }

        try {
            $db        = Database::getInstance()->getConnection();
            $usuarioId = (int) AuthHelper::usuarioId();

            $db->beginTransaction();

            $templateId = (new EmailTemplate())->save([
                'nome'          => mb_substr($nome, 0, 190),
                'tipo'          => 'marketing',
                'formato'       => 'manual',
                'assunto'       => mb_substr($assunto, 0, 255),
                'preheader'     => mb_substr(trim($preheader), 0, 190) ?: null,
                'html'          => $html,
                'texto'         => $this->tpl->htmlToText($html),
                'status'        => 'rascunho',   // NUNCA nasce ativo
                'render_status' => $avisos === [] ? 'ok' : 'warning',
                'render_log'    => $avisos === [] ? null : implode(' · ', $avisos),
            ], $usuarioId ?: null);

            // variaveis_json não passa pelo EmailTemplate::save() — o modelo é
            // compartilhado com todo o módulo de e-mail e não seria correto
            // alterá-lo por causa desta tela. UPDATE dirigido resolve, e de
            // quebra popula um campo que hoje está vazio nos 33 templates.
            $st = $db->prepare('UPDATE email_templates SET variaveis_json = :v WHERE id = :id');
            $st->execute([
                ':v'  => json_encode($this->variaveisDe($html), JSON_UNESCAPED_UNICODE),
                ':id' => $templateId,
            ]);

            $st = $db->prepare(
                'INSERT INTO ia_email_layout_geracao (template_id, geracao_id, briefing, criado_por)
                 VALUES (:t, :g, :b, :u)'
            );
            $st->execute([
                ':t' => $templateId,
                ':g' => $geracaoId,
                ':b' => mb_substr($briefing, 0, 2000) ?: null,
                ':u' => $usuarioId ?: null,
            ]);

            $db->commit();

            LogService::audit('ia_email_layout_salvo', [
                'template_id' => $templateId,
                'geracao_id'  => $geracaoId,
                'usuario_id'  => $usuarioId,
            ]);

            return ['ok' => true, 'template_id' => $templateId];

        } catch (\Throwable $e) {
            if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
            LogService::error('ia_email_layout_salvar_erro', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'msg' => 'Não foi possível salvar o template.'];
        }
    }

    /** Procedência de um template: de qual geração ele saiu. */
    public function procedencia(int $templateId): ?array
    {
        if ($templateId <= 0) { return null; }
        try {
            $st = Database::getInstance()->getConnection()->prepare(
                'SELECT l.geracao_id, l.briefing, l.criado_em, g.provedor_codigo, g.modelo_codigo,
                        g.custo_real_usd, u.nome AS usuario_nome
                   FROM ia_email_layout_geracao l
             INNER JOIN ia_geracoes g ON g.id = l.geracao_id
              LEFT JOIN usuarios u    ON u.id = l.criado_por
                  WHERE l.template_id = :t
               ORDER BY l.id DESC LIMIT 1'
            );
            $st->execute([':t' => $templateId]);
            $l = $st->fetch(PDO::FETCH_ASSOC);
            if (!$l) { return null; }

            return [
                'geracao_id' => (int) $l['geracao_id'],
                'provedor'   => (string) $l['provedor_codigo'],
                'modelo'     => (string) $l['modelo_codigo'],
                'rotulo'     => $this->rotuloModelo((string) $l['provedor_codigo'], (string) $l['modelo_codigo']),
                'briefing'   => (string) ($l['briefing'] ?? ''),
                'criado_em'  => (string) $l['criado_em'],
                'por'        => $l['usuario_nome'] !== null ? (string) $l['usuario_nome'] : null,
            ];
        } catch (\Throwable $e) {
            LogService::error('ia_email_layout_procedencia_erro', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /* ══════════════════════════════════════════════════════
       Portões e helpers
       ══════════════════════════════════════════════════════ */

    /**
     * Confere o pareamento dos blocos {{#x}}…{{/x}} e {{?x}}…{{/x}}.
     *
     * Um bloco aberto e não fechado não quebra nada na hora — quebra no
     * RENDER, quando a campanha já está sendo disparada para a base inteira.
     * É barato conferir aqui e caro descobrir lá.
     *
     * @return ?string null se está tudo pareado; senão o nome do culpado
     */
    public function validarMarcadores(string $html): ?string
    {
        preg_match_all('/\{\{\s*([#?\/])\s*([a-zA-Z0-9_]+)\s*\}\}/', $html, $m, PREG_SET_ORDER);

        $pilha = [];
        foreach ($m as $tag) {
            [$tudo, $sinal, $nome] = $tag;
            if ($sinal === '#' || $sinal === '?') {
                $pilha[] = $nome;
                continue;
            }
            // fechamento
            if ($pilha === []) {
                return "{{/{$nome}}} sem abertura";
            }
            $ultimo = array_pop($pilha);
            if ($ultimo !== $nome) {
                return "{{/{$nome}}} fecha {{#{$ultimo}}}";
            }
        }

        return $pilha === [] ? null : '{{#' . $pilha[0] . '}} sem fechamento';
    }

    /**
     * Todas as variáveis que a campanha precisa fornecer: as simples E os
     * nomes dos blocos.
     *
     * O `extrairVariaveis()` do EmailTemplateService só casa `{{nome}}` — os
     * blocos `{{#produtos}}` ficam de fora. Sem eles, `variaveis_json` diria
     * que o template precisa de nome e preço, e quem montasse a campanha
     * descobriria só no envio que faltava o ARRAY de produtos — o dado mais
     * importante do template.
     *
     * Não alterei o extrairVariaveis() compartilhado: ele serve o módulo de
     * e-mail inteiro e mudar o retorno mexeria em quem já depende dele.
     */
    public function variaveisDe(string $html): array
    {
        $simples = $this->tpl->extrairVariaveis($html);

        preg_match_all('/\{\{\s*[#?]\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $html, $m);
        $blocos = $m[1] ?? [];

        return array_values(array_unique(array_merge($blocos, $simples)));
    }

    /** Modelos de TEXTO oferecidos no seletor: mesmo critério do orquestrador. */
    public function modelosDisponiveis(): array
    {
        try {
            $tipoRow = (new IATipoConteudo())->buscarPorCodigo(self::TIPO);
            $pinado  = $tipoRow !== null ? (int) ($tipoRow['modelo_id'] ?? 0) : 0;

            $linhas = Database::getInstance()->getConnection()->query(
                "SELECT m.id, m.codigo_modelo, m.nome, p.codigo AS prov, p.nome AS prov_nome
                   FROM ia_modelos m
             INNER JOIN ia_provedores p
                     ON p.id = m.provedor_id AND p.ativo = 1 AND p.api_key_enc IS NOT NULL
                  WHERE m.capacidade = 'texto' AND m.ativo = 1
               ORDER BY m.prioridade ASC, m.id ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(fn ($m) => [
                'id'     => (int) $m['id'],
                'rotulo' => $this->rotuloModelo((string) $m['prov'], (string) $m['codigo_modelo'], (string) $m['nome']),
                'padrao' => (int) $m['id'] === $pinado,
            ], $linhas);
        } catch (\Throwable $e) {
            LogService::error('ia_email_layout_modelos_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /** Devolve o id só se for modelo de texto, ativo, com provedor ativo e chave. */
    private function validarModeloTexto(int $modeloId): ?int
    {
        if ($modeloId <= 0) { return null; }
        try {
            $st = Database::getInstance()->getConnection()->prepare(
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
            $st = Database::getInstance()->getConnection()->prepare(
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

    private function montarPrompt(string $briefing, array $opcoes): string
    {
        $objetivo = trim((string) ($opcoes['objetivo'] ?? ''));
        $publico  = trim((string) ($opcoes['publico']  ?? ''));
        $tom      = trim((string) ($opcoes['tom']      ?? ''));
        $paleta   = trim((string) ($opcoes['paleta']   ?? ''));

        $exemplo = $this->exemploEstrutural();

        $p  = "BRIEFING DO LAYOUT\n{$briefing}\n\n";
        if ($objetivo !== '') { $p .= "Objetivo: {$objetivo}\n"; }
        if ($publico  !== '') { $p .= "Público: {$publico}\n"; }
        if ($tom      !== '') { $p .= "Tom: {$tom}\n"; }
        if ($paleta   !== '') { $p .= "Paleta: {$paleta}\n"; }

        if ($exemplo !== '') {
            // Ancora o modelo na estrutura REAL que já é usada na loja, em vez
            // de deixá-lo inventar uma. É o que mais melhora o resultado.
            $p .= "\nEXEMPLO ESTRUTURAL — siga esta forma de tabelas aninhadas,\n"
                . "não copie o conteúdo:\n<<<\n" . $exemplo . "\n>>>\n";
        }

        $p .= "\nMonte o esqueleto com um bloco {{#produtos}}…{{/produtos}} "
            . "para a vitrine de produtos.\nResponda somente com o JSON.";

        return $p;
    }

    /**
     * Um template de marketing ATIVO da loja, como exemplo de estrutura.
     * Limitado a 6 KB: exemplo maior consome a janela sem melhorar a forma.
     */
    private function exemploEstrutural(): string
    {
        try {
            $html = Database::getInstance()->getConnection()->query(
                "SELECT html FROM email_templates
                  WHERE tipo = 'marketing' AND status = 'ativo' AND CHAR_LENGTH(html) BETWEEN 1500 AND 12000
               ORDER BY CHAR_LENGTH(html) ASC LIMIT 1"
            )->fetchColumn();
            return $html === false ? '' : mb_substr((string) $html, 0, 6000);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function rotuloModelo(string $provedor, string $modelo, string $nome = ''): string
    {
        $marcas = ['openai' => 'OpenAI', 'gemini' => 'Gemini', 'claude' => 'Claude', 'replicate' => 'Replicate'];
        $marca  = $marcas[$provedor] ?? ($provedor !== '' ? ucfirst($provedor) : 'IA');

        $curto = $nome !== '' ? $nome : $modelo;
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
