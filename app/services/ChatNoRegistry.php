<?php
/**
 * app/services/ChatNoRegistry.php
 *
 * Catálogo de blocos do construtor de fluxos conversacionais.
 *
 * Mesma decisão arquitetural do FluxoNoRegistry: TODAS as classes de nó vivem
 * neste arquivo. O acesso é sempre pelo registry, então o spl_autoload
 * (1 classe = 1 arquivo) nunca precisa encontrá-las individualmente.
 *
 * CONTRATO DE UM NÓ:
 *   executar(array &$sessao, array $config, ChatExecCtx $ctx): string
 *     → devolve o NOME DA PORTA de saída, ou uma constante ChatNo::*
 *   Pode escrever em $sessao['contexto'], $sessao['dormir_ate'],
 *   $sessao['aguardando_ate'] e $sessao['erro_detalhe'].
 *
 * DIFERENÇA CENTRAL PARA O MOTOR v2: aqui o fluxo é uma CONVERSA. Nós de
 * pergunta (botões, lista, esperar_resposta) param a execução e só continuam
 * quando o contato responde — por isso existe a constante AGUARDAR e o
 * conceito de "porta de retomada" gravada no contexto.
 *
 * Adicionar um bloco novo = 1 classe aqui + 1 linha no MAPA + metadados no JS.
 */

// ─────────────────────────────────────────────────────────────────────────────
// CONTEXTO DE EXECUÇÃO — evita reinstanciar services em cada nó
// ─────────────────────────────────────────────────────────────────────────────
class ChatExecCtx
{
    public PDO $db;
    public ChatContatoService  $contatos;
    public ChatConversaService $conversas;
    public ChatMensagemService $mensagens;
    public ChatEnvioService    $envio;

    /** @var array contato hidratado da sessão corrente */
    public array $contato = [];
    /** @var array variáveis de interpolação já montadas */
    public array $vars = [];

    public function __construct(?PDO $db = null)
    {
        $this->db        = $db ?? Database::getInstance()->getConnection();
        $this->contatos  = new ChatContatoService($this->db);
        $this->conversas = new ChatConversaService($this->db);
        $this->mensagens = new ChatMensagemService($this->db);
        $this->envio     = new ChatEnvioService($this->db);
    }

    /** Recarrega contato + vars (após uma ação que mudou tag/campo). */
    public function recarregarContato(int $contatoId): void
    {
        $c = $this->contatos->obter($contatoId);
        if ($c) {
            $this->contato = $c;
            $this->vars    = $this->contatos->variaveis($c);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
abstract class ChatNo
{
    /** Retornos especiais (não são portas) */
    public const DORMIR   = '__dormir';    // setou dormir_ate
    public const AGUARDAR = '__aguardar';  // espera resposta do contato
    public const ENCERRAR = '__encerrar';
    public const ERRO     = '__erro';      // setou erro_detalhe
    public const PULAR    = '__pular';     // trocou de fluxo (ir_para_fluxo)

    abstract public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string;

    /** Portas declaradas — validação do grafo e desenho do canvas. */
    abstract public function portas(): array;

    /**
     * Portas que valem para ESTA configuração. Quase sempre é `portas()`;
     * um nó com número variável de saídas (Teste A/B com 3 braços) devolve só
     * as que o operador ligou. O canvas desenha o máximo; o motor e o checker
     * olham estas.
     */
    public function portasAtivas(array $config): array { return $this->portas(); }

    public function ehTrigger(): bool { return false; }

    /** Categoria para agrupar na paleta: trigger|mensagem|logica|condicao|acao */
    public function categoria(): string { return 'acao'; }

    /** Este nó pausa a execução esperando o contato? (o motor usa para validar) */
    public function ehPergunta(): bool { return false; }

    // ── Helpers ──────────────────────────────────────────────────────────────

    protected function ctx(array $sessao): array
    {
        return is_array($sessao['contexto'] ?? null) ? $sessao['contexto'] : [];
    }

    protected function chave(array $sessao): string
    {
        return (string)($sessao['no_atual'] ?? '');
    }

    protected function texto(array $config, string $campo, ChatExecCtx $ctx, string $default = ''): string
    {
        $t = (string)($config[$campo] ?? $default);
        return ChatContatoService::interpolar($t, $ctx->vars);
    }

    /** Marca no contexto que este nó já enviou (evita reenvio ao retomar). */
    protected function jaEnviou(array $sessao, string $sufixo = 'env'): bool
    {
        $c = $this->ctx($sessao);
        return !empty($c['_' . $sufixo . '_' . $this->chave($sessao)]);
    }

    protected function marcarEnviado(array &$sessao, string $sufixo = 'env'): void
    {
        $c = $this->ctx($sessao);
        $c['_' . $sufixo . '_' . $this->chave($sessao)] = 1;
        $sessao['contexto'] = $c;
    }

    protected function limparMarca(array &$sessao, string $sufixo): void
    {
        $c = $this->ctx($sessao);
        unset($c['_' . $sufixo . '_' . $this->chave($sessao)]);
        $sessao['contexto'] = $c;
    }

    /**
     * A resposta que o contato deu a ESTE nó, se já chegou.
     * O motor grava em _resposta_<chave> quando reativa a sessão.
     */
    protected function respostaRecebida(array $sessao): ?array
    {
        $c = $this->ctx($sessao);
        $r = $c['_resposta_' . $this->chave($sessao)] ?? null;
        return is_array($r) ? $r : null;
    }

    protected function segundosDe(array $config, int $default = 0): int
    {
        $s = (int)($config['dias'] ?? 0) * 86400
           + (int)($config['horas'] ?? 0) * 3600
           + (int)($config['minutos'] ?? 0) * 60
           + (int)($config['segundos'] ?? 0);
        return $s > 0 ? $s : $default;
    }

    protected function opts(array $sessao, array $extra = []): array
    {
        $base = [
            'origem'    => 'fluxo',
            'origem_id' => (int)($sessao['fluxo_id'] ?? 0),
        ];

        // Fluxo que nasceu de um comentário do Instagram carrega o id dele. O
        // envio decide se usa: só a primeira mensagem da conversa sai como
        // private reply, que é o que abre a porta com quem nunca mandou DM.
        $comentario = (string)($this->ctx($sessao)['_comment_id'] ?? '');
        if ($comentario !== '') $base['ig_comment_id'] = $comentario;

        return array_merge($base, $extra);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// TRIGGERS — pontos de entrada. Execução é passthrough.
// ═════════════════════════════════════════════════════════════════════════════

abstract class ChatNoTrigger extends ChatNo
{
    public function portas(): array   { return ['saida']; }
    public function ehTrigger(): bool { return true; }
    public function categoria(): string { return 'trigger'; }
    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string { return 'saida'; }
}

/** config: {"palavras":"oi,ola,menu","modo":"contem|exato|comeca|regex"} */
class ChatNoGatilhoPalavra extends ChatNoTrigger {}

/** Primeira mensagem que o contato manda na vida. */
class ChatNoGatilhoBoasVindas extends ChatNoTrigger {}

/** Nada casou — a rede de segurança da conversa. */
class ChatNoGatilhoPadrao extends ChatNoTrigger {}

/** config: {"codigo":"promo-instagram"} — via wa.me/...?text=... ou anúncio CTWA */
class ChatNoGatilhoReferencia extends ChatNoTrigger {}

/** Disparado pelo admin, por campanha ou por API. */
class ChatNoGatilhoManual extends ChatNoTrigger {}

/** config: {"evento":"pedido_criado"} — ponte com a tabela `eventos` da loja. */
class ChatNoGatilhoEventoLoja extends ChatNoTrigger {}

// ═════════════════════════════════════════════════════════════════════════════
// MENSAGENS
// ═════════════════════════════════════════════════════════════════════════════

/** config: {"texto":"...","preview_url":true} */
class ChatNoMsgTexto extends ChatNo
{
    public function portas(): array { return ['saida']; }
    public function categoria(): string { return 'mensagem'; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $texto = $this->texto($config, 'texto', $ctx);
        if (trim($texto) === '') return 'saida';   // nó vazio não é erro, só não faz nada

        $r = $ctx->envio->texto((int)$sessao['contato_id'], $texto, $this->opts($sessao, [
            'preview_url' => !isset($config['preview_url']) || (bool)$config['preview_url'],
        ]));

        return $this->tratarEnvio($r, $sessao);
    }

    /**
     * Falha de envio não é sempre erro fatal: fora da janela o fluxo deve
     * simplesmente parar (a pessoa não pode ser alcançada agora), não explodir.
     */
    protected function tratarEnvio(array $r, array &$sessao): string
    {
        if ($r['ok']) return 'saida';

        if (in_array($r['motivo'] ?? '', [
            ChatEnvioService::MOTIVO_FORA_JANELA,
            ChatEnvioService::MOTIVO_OPTOUT,
            ChatEnvioService::MOTIVO_BLOQUEADO,
        ], true)) {
            $sessao['erro_detalhe'] = $r['motivo'];
            return self::ENCERRAR;
        }

        $sessao['erro_detalhe'] = mb_substr((string)($r['erro'] ?? 'falha no envio'), 0, 200);
        return self::ERRO;
    }
}

/** config: {"tipo_midia":"image","url":"...","legenda":"...","nome_arquivo":"..."} */
class ChatNoMsgMidia extends ChatNoMsgTexto
{
    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $url = $this->texto($config, 'url', $ctx);
        if (trim($url) === '') return 'saida';

        $r = $ctx->envio->midia(
            (int)$sessao['contato_id'],
            (string)($config['tipo_midia'] ?? 'image'),
            $url,
            $this->opts($sessao, [
                'legenda'      => $this->texto($config, 'legenda', $ctx) ?: null,
                'nome_arquivo' => $config['nome_arquivo'] ?? null,
            ])
        );
        return $this->tratarEnvio($r, $sessao);
    }
}

/**
 * Botões de resposta rápida. PARA a execução e ramifica pelo botão clicado.
 *
 * config: {"corpo":"...","rodape":"...","cabecalho":{...},
 *          "botoes":[{"titulo":"Sim"},{"titulo":"Não"}],
 *          "timeout":{"horas":24}, "salvar_em":"escolha"}
 * portas: btn_1, btn_2, btn_3, timeout
 *
 * Os ids enviados à Meta são btn_1..btn_3 — assim a resposta volta com a
 * própria porta no id e o roteamento fica direto, sem tabela de tradução.
 */
class ChatNoMsgBotoes extends ChatNoMsgTexto
{
    public function portas(): array { return ['btn_1', 'btn_2', 'btn_3', 'timeout']; }
    public function ehPergunta(): bool { return true; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        // ── 2ª passada: a resposta chegou ──
        if ($resposta = $this->respostaRecebida($sessao)) {
            $this->limparMarca($sessao, 'env');
            $c = $this->ctx($sessao);
            unset($c['_resposta_' . $this->chave($sessao)]);

            if (($resposta['tipo'] ?? '') === 'timeout') {
                $sessao['contexto'] = $c;
                return 'timeout';
            }

            $porta = (string)($resposta['id'] ?? '');
            if (!empty($config['salvar_em'])) {
                $campo = preg_replace('/[^a-z0-9_]/i', '_', (string)$config['salvar_em']);
                $valor = (string)($resposta['titulo'] ?? $resposta['texto'] ?? '');
                $c[$campo] = $valor;
                $ctx->contatos->setCampo((int)$sessao['contato_id'], $campo, $valor);
            }
            $sessao['contexto'] = $c;

            return in_array($porta, ['btn_1', 'btn_2', 'btn_3'], true) ? $porta : 'timeout';
        }

        // ── 1ª passada: envia e aguarda ──
        if (!$this->jaEnviou($sessao)) {
            $botoes = [];
            foreach (array_slice((array)($config['botoes'] ?? []), 0, 3) as $i => $b) {
                $titulo = trim(ChatContatoService::interpolar((string)($b['titulo'] ?? ''), $ctx->vars));
                if ($titulo === '') continue;
                $botoes[] = ['id' => 'btn_' . ($i + 1), 'titulo' => $titulo];
            }
            if (!$botoes) {
                $sessao['erro_detalhe'] = 'nó de botões sem botões configurados';
                return self::ERRO;
            }

            $r = $ctx->envio->botoes(
                (int)$sessao['contato_id'],
                $this->texto($config, 'corpo', $ctx, 'Escolha uma opção:'),
                $botoes,
                $this->opts($sessao, [
                    'rodape'    => $this->texto($config, 'rodape', $ctx) ?: null,
                    'cabecalho' => $config['cabecalho'] ?? null,
                ])
            );
            if (!$r['ok']) return $this->tratarEnvio($r, $sessao);
            $this->marcarEnviado($sessao);
        }

        $sessao['aguardando_ate'] = date('Y-m-d H:i:s', time() + $this->segundosDe((array)($config['timeout'] ?? []), 86400));
        return self::AGUARDAR;
    }
}

/**
 * Lista de opções (até 10). Mesma mecânica dos botões, portas op_1..op_10.
 * config: {"corpo":"...","texto_botao":"Ver","secoes":[{"titulo":"..","linhas":[{"titulo":"..","descricao":".."}]}]}
 */
class ChatNoMsgLista extends ChatNoMsgTexto
{
    public function portas(): array
    {
        $p = [];
        for ($i = 1; $i <= 10; $i++) $p[] = 'op_' . $i;
        $p[] = 'timeout';
        return $p;
    }
    public function ehPergunta(): bool { return true; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        if ($resposta = $this->respostaRecebida($sessao)) {
            $this->limparMarca($sessao, 'env');
            $c = $this->ctx($sessao);
            unset($c['_resposta_' . $this->chave($sessao)]);

            if (($resposta['tipo'] ?? '') === 'timeout') {
                $sessao['contexto'] = $c;
                return 'timeout';
            }

            $porta = (string)($resposta['id'] ?? '');
            if (!empty($config['salvar_em'])) {
                $campo = preg_replace('/[^a-z0-9_]/i', '_', (string)$config['salvar_em']);
                $valor = (string)($resposta['titulo'] ?? '');
                $c[$campo] = $valor;
                $ctx->contatos->setCampo((int)$sessao['contato_id'], $campo, $valor);
            }
            $sessao['contexto'] = $c;

            return preg_match('/^op_([1-9]|10)$/', $porta) ? $porta : 'timeout';
        }

        if (!$this->jaEnviou($sessao)) {
            $secoes = []; $n = 0;
            foreach ((array)($config['secoes'] ?? []) as $s) {
                $linhas = [];
                foreach ((array)($s['linhas'] ?? []) as $l) {
                    if ($n >= 10) break;
                    $titulo = trim(ChatContatoService::interpolar((string)($l['titulo'] ?? ''), $ctx->vars));
                    if ($titulo === '') continue;
                    $n++;
                    $linhas[] = [
                        'id'        => 'op_' . $n,
                        'titulo'    => $titulo,
                        'descricao' => ChatContatoService::interpolar((string)($l['descricao'] ?? ''), $ctx->vars),
                    ];
                }
                if ($linhas) $secoes[] = ['titulo' => (string)($s['titulo'] ?? 'Opções'), 'linhas' => $linhas];
            }
            if (!$secoes) {
                $sessao['erro_detalhe'] = 'nó de lista sem opções configuradas';
                return self::ERRO;
            }

            $r = $ctx->envio->lista(
                (int)$sessao['contato_id'],
                $this->texto($config, 'corpo', $ctx, 'Escolha uma opção:'),
                $this->texto($config, 'texto_botao', $ctx, 'Ver opções'),
                $secoes,
                $this->opts($sessao, [
                    'rodape'    => $this->texto($config, 'rodape', $ctx) ?: null,
                    'cabecalho' => $config['cabecalho'] ?? null,
                ])
            );
            if (!$r['ok']) return $this->tratarEnvio($r, $sessao);
            $this->marcarEnviado($sessao);
        }

        $sessao['aguardando_ate'] = date('Y-m-d H:i:s', time() + $this->segundosDe((array)($config['timeout'] ?? []), 86400));
        return self::AGUARDAR;
    }
}

/** config: {"nome":"pedido_criado","idioma":"pt_BR","params_body":["{{nome}}"],"param_header":"","param_botao":""} */
class ChatNoMsgTemplate extends ChatNoMsgTexto
{
    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $nome = trim((string)($config['nome'] ?? ''));
        if ($nome === '') {
            $sessao['erro_detalhe'] = 'nó de template sem nome';
            return self::ERRO;
        }

        $componentes = [];

        $header = trim($this->texto($config, 'param_header', $ctx));
        if ($header !== '') {
            $componentes[] = ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => $header]]];
        }

        $params = (array)($config['params_body'] ?? []);
        if ($params) {
            $componentes[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn($v) => ['type' => 'text', 'text' => ChatContatoService::interpolar((string)$v, $ctx->vars)],
                    array_values($params)
                ),
            ];
        }

        $btn = trim($this->texto($config, 'param_botao', $ctx));
        if ($btn !== '') {
            $componentes[] = [
                'type' => 'button', 'sub_type' => 'url', 'index' => '0',
                'parameters' => [['type' => 'text', 'text' => $btn]],
            ];
        }

        $r = $ctx->envio->template(
            (int)$sessao['contato_id'], $nome,
            (string)($config['idioma'] ?? 'pt_BR'), $componentes,
            $this->opts($sessao)
        );
        return $this->tratarEnvio($r, $sessao);
    }
}

/** config: {"corpo":"...","texto_botao":"Abrir","url":"https://..."} */
class ChatNoMsgBotaoUrl extends ChatNoMsgTexto
{
    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $url = trim($this->texto($config, 'url', $ctx));
        if ($url === '') {
            $sessao['erro_detalhe'] = 'nó de botão URL sem URL';
            return self::ERRO;
        }
        $r = $ctx->envio->botaoUrl(
            (int)$sessao['contato_id'],
            $this->texto($config, 'corpo', $ctx, ' '),
            $this->texto($config, 'texto_botao', $ctx, 'Abrir'),
            $url,
            $this->opts($sessao, ['rodape' => $this->texto($config, 'rodape', $ctx) ?: null])
        );
        return $this->tratarEnvio($r, $sessao);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// LÓGICA / FLUXO
// ═════════════════════════════════════════════════════════════════════════════

/** config: {"minutos":0,"horas":1,"dias":0} */
class ChatNoEsperar extends ChatNo
{
    public function portas(): array { return ['saida']; }
    public function categoria(): string { return 'logica'; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $c     = $this->ctx($sessao);
        $marca = '_dormiu_' . $this->chave($sessao);

        if (!empty($c[$marca])) {           // já acordou
            unset($c[$marca]);
            $sessao['contexto'] = $c;
            return 'saida';
        }

        $seg = $this->segundosDe($config, 3600);
        $c[$marca] = 1;
        $sessao['contexto']   = $c;
        $sessao['dormir_ate'] = date('Y-m-d H:i:s', time() + $seg);
        return self::DORMIR;
    }
}

/**
 * Pergunta aberta: espera o contato digitar, valida e guarda a resposta.
 *
 * config: {"pergunta":"Qual seu CEP?","salvar_em":"cep",
 *          "validacao":"texto|numero|email|telefone|cep|cpf",
 *          "mensagem_invalida":"Não entendi...","max_tentativas":3,
 *          "timeout":{"horas":24}}
 * portas: resposta | invalido | timeout
 */
class ChatNoEsperarResposta extends ChatNo
{
    public function portas(): array { return ['resposta', 'invalido', 'timeout']; }
    public function categoria(): string { return 'logica'; }
    public function ehPergunta(): bool { return true; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $chave = $this->chave($sessao);
        $c     = $this->ctx($sessao);

        // ── 2ª passada ──
        if ($resposta = $this->respostaRecebida($sessao)) {
            unset($c['_resposta_' . $chave]);

            if (($resposta['tipo'] ?? '') === 'timeout') {
                unset($c['_env_' . $chave], $c['_tent_' . $chave]);
                $sessao['contexto'] = $c;
                return 'timeout';
            }

            $texto     = trim((string)($resposta['texto'] ?? ''));
            $validacao = (string)($config['validacao'] ?? 'texto');
            $valido    = $this->validar($texto, $validacao);

            if (!$valido) {
                $tent = (int)($c['_tent_' . $chave] ?? 0) + 1;
                $max  = max(1, (int)($config['max_tentativas'] ?? 3));

                if ($tent >= $max) {
                    unset($c['_env_' . $chave], $c['_tent_' . $chave]);
                    $sessao['contexto'] = $c;
                    return 'invalido';
                }

                $c['_tent_' . $chave] = $tent;
                $sessao['contexto'] = $c;

                $msg = $this->texto($config, 'mensagem_invalida', $ctx, 'Não entendi. Pode tentar de novo?');
                $ctx->envio->texto((int)$sessao['contato_id'], $msg, $this->opts($sessao));

                // Reabre a espera sem reenviar a pergunta original
                $sessao['aguardando_ate'] = date('Y-m-d H:i:s', time() + $this->segundosDe((array)($config['timeout'] ?? []), 86400));
                return self::AGUARDAR;
            }

            // Válido: normaliza, guarda no contexto E no contato
            $valor = $this->normalizar($texto, $validacao);
            $campo = preg_replace('/[^a-z0-9_]/i', '_', (string)($config['salvar_em'] ?? 'resposta'));

            $c[$campo] = $valor;
            unset($c['_env_' . $chave], $c['_tent_' . $chave]);
            $sessao['contexto'] = $c;

            $ctx->contatos->setCampo((int)$sessao['contato_id'], $campo, $valor);
            $ctx->recarregarContato((int)$sessao['contato_id']);

            return 'resposta';
        }

        // ── 1ª passada ──
        if (!$this->jaEnviou($sessao)) {
            $pergunta = $this->texto($config, 'pergunta', $ctx);
            if (trim($pergunta) !== '') {
                $r = $ctx->envio->texto((int)$sessao['contato_id'], $pergunta, $this->opts($sessao));
                if (!$r['ok'] && in_array($r['motivo'] ?? '', [
                    ChatEnvioService::MOTIVO_FORA_JANELA,
                    ChatEnvioService::MOTIVO_OPTOUT,
                    ChatEnvioService::MOTIVO_BLOQUEADO,
                ], true)) {
                    $sessao['erro_detalhe'] = $r['motivo'];
                    return self::ENCERRAR;
                }
            }
            $this->marcarEnviado($sessao);
        }

        $sessao['aguardando_ate'] = date('Y-m-d H:i:s', time() + $this->segundosDe((array)($config['timeout'] ?? []), 86400));
        return self::AGUARDAR;
    }

    private function validar(string $t, string $tipo): bool
    {
        if ($t === '') return false;
        return match ($tipo) {
            'numero'   => is_numeric(str_replace([',', '.'], ['.', ''], $t)),
            'email'    => (bool)filter_var($t, FILTER_VALIDATE_EMAIL),
            'telefone' => strlen(preg_replace('/\D/', '', $t) ?? '') >= 10,
            'cep'      => strlen(preg_replace('/\D/', '', $t) ?? '') === 8,
            'cpf'      => class_exists('SecurityHelper') && SecurityHelper::validateCpf($t),
            default    => mb_strlen($t) >= 1,
        };
    }

    private function normalizar(string $t, string $tipo): string
    {
        return match ($tipo) {
            'telefone', 'cep' => preg_replace('/\D/', '', $t) ?? $t,
            'cpf'             => preg_replace('/\D/', '', $t) ?? $t,
            'email'           => mb_strtolower($t),
            default           => $t,
        };
    }
}

/** config: {"peso_a":50} */
/**
 * config: {"variantes":3,"pesos":[50,30,20]}   (2 a 6 braços)
 * Compatível com o formato antigo {"peso_a":70}: vira dois braços 70/30.
 */
class ChatNoSplitAb extends ChatNo
{
    public const MAX = 6;

    public function portas(): array { return array_slice(['a', 'b', 'c', 'd', 'e', 'f'], 0, self::MAX); }
    public function categoria(): string { return 'logica'; }

    public function portasAtivas(array $config): array
    {
        return array_slice($this->portas(), 0, count($this->pesos($config)));
    }

    /** Pesos normalizados, um por braço ativo. Soma pode não dar 100: o sorteio é proporcional. */
    public function pesos(array $config): array
    {
        $n = (int)($config['variantes'] ?? 0);
        $pesos = array_values(array_map('intval', (array)($config['pesos'] ?? [])));

        if ($n < 2) {
            // Formato antigo, ou nada configurado: dois braços
            $a = max(0, min(100, (int)($config['peso_a'] ?? 50)));
            return [$a, 100 - $a];
        }

        $n = min(self::MAX, $n);
        $pesos = array_slice(array_pad($pesos, $n, 0), 0, $n);
        $pesos = array_map(fn($p) => max(0, $p), $pesos);

        // Tudo zero seria "ninguém passa": reparte igual
        if (array_sum($pesos) === 0) $pesos = array_fill(0, $n, 1);
        return $pesos;
    }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $c     = $this->ctx($sessao);
        $marca = '_split_' . $this->chave($sessao);

        // Decisão gravada: reprocessar o nó não pode trocar o braço
        if (!empty($c[$marca])) return (string)$c[$marca];

        $pesos  = $this->pesos($config);
        $portas = $this->portasAtivas($config);

        $sorteio = random_int(1, max(1, array_sum($pesos)));
        $acum = 0;
        $porta = end($portas);
        foreach ($pesos as $i => $p) {
            $acum += $p;
            if ($sorteio <= $acum) { $porta = $portas[$i]; break; }
        }

        $c[$marca] = $porta;
        $sessao['contexto'] = $c;
        return $porta;
    }
}

class ChatNoEncerrar extends ChatNo
{
    public function portas(): array { return []; }
    public function categoria(): string { return 'logica'; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        return self::ENCERRAR;
    }
}

/** config: {"fluxo_id":12} — encerra esta sessão e inicia a do outro fluxo. */
class ChatNoIrParaFluxo extends ChatNo
{
    public function portas(): array { return []; }
    public function categoria(): string { return 'logica'; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $destino = (int)($config['fluxo_id'] ?? 0);
        if ($destino < 1 || $destino === (int)$sessao['fluxo_id']) {
            $sessao['erro_detalhe'] = 'ir_para_fluxo: destino inválido';
            return self::ERRO;
        }
        // O motor lê isto e faz a troca — o nó não inicia nada sozinho para
        // não abrir recursão descontrolada dentro de um passo.
        $c = $this->ctx($sessao);
        $c['_pular_para'] = $destino;
        $sessao['contexto'] = $c;
        return self::PULAR;
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// CONDIÇÕES — portas true/false
// ═════════════════════════════════════════════════════════════════════════════

abstract class ChatNoCondicao extends ChatNo
{
    public function portas(): array { return ['true', 'false']; }
    public function categoria(): string { return 'condicao'; }

    abstract protected function avaliar(array $sessao, array $config, ChatExecCtx $ctx): bool;

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        try {
            return $this->avaliar($sessao, $config, $ctx) ? 'true' : 'false';
        } catch (Throwable $e) {
            // Condição que quebra não pode travar a jornada: segue pelo 'false'
            $sessao['erro_detalhe'] = 'condição falhou: ' . mb_substr($e->getMessage(), 0, 150);
            return 'false';
        }
    }
}

/** config: {"tag_id":3} */
class ChatNoCondTemTag extends ChatNoCondicao
{
    protected function avaliar(array $sessao, array $config, ChatExecCtx $ctx): bool
    {
        $tagId = (int)($config['tag_id'] ?? 0);
        if ($tagId < 1) return false;
        $st = $ctx->db->prepare(
            "SELECT 1 FROM chat_contato_tags WHERE contato_id = :c AND tag_id = :t LIMIT 1"
        );
        $st->execute([':c' => (int)$sessao['contato_id'], ':t' => $tagId]);
        return (bool)$st->fetchColumn();
    }
}

/** config: {"campo":"cidade","operador":"=","valor":"Porto Alegre"} */
class ChatNoCondCampo extends ChatNoCondicao
{
    protected function avaliar(array $sessao, array $config, ChatExecCtx $ctx): bool
    {
        $campo = trim((string)($config['campo'] ?? ''));
        if ($campo === '') return false;

        // Contexto da sessão vence o campo persistido — é o valor "mais fresco"
        $c   = $this->ctx($sessao);
        $val = $c[$campo] ?? $ctx->contatos->getCampo((int)$sessao['contato_id'], $campo);

        $op       = (string)($config['operador'] ?? '=');
        $esperado = ChatContatoService::interpolar((string)($config['valor'] ?? ''), $ctx->vars);

        if ($op === 'existe')      return $val !== null && $val !== '';
        if ($op === 'nao_existe')  return $val === null || $val === '';
        if ($val === null)         return false;

        $a = mb_strtolower(trim((string)$val));
        $b = mb_strtolower(trim($esperado));

        return match ($op) {
            '='        => $a === $b,
            '!='       => $a !== $b,
            'contem'   => $b !== '' && str_contains($a, $b),
            'comeca'   => $b !== '' && str_starts_with($a, $b),
            '>'        => (float)$val >  (float)$esperado,
            '>='       => (float)$val >= (float)$esperado,
            '<'        => (float)$val <  (float)$esperado,
            '<='       => (float)$val <= (float)$esperado,
            default    => false,
        };
    }
}

/** A janela de 24h está aberta? Decide entre texto livre e template. */
class ChatNoCondNaJanela extends ChatNoCondicao
{
    protected function avaliar(array $sessao, array $config, ChatExecCtx $ctx): bool
    {
        $c = $ctx->contatos->obter((int)$sessao['contato_id']);
        return $c ? $ctx->contatos->naJanela($c) : false;
    }
}

/** O contato está vinculado a um cliente da loja? */
class ChatNoCondEhCliente extends ChatNoCondicao
{
    protected function avaliar(array $sessao, array $config, ChatExecCtx $ctx): bool
    {
        $st = $ctx->db->prepare("SELECT cliente_id FROM chat_contatos WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$sessao['contato_id']]);
        return (int)$st->fetchColumn() > 0;
    }
}

/** config: {"operador":">=","valor":500,"janela_dias":null} */
class ChatNoCondComprou extends ChatNoCondicao
{
    protected function avaliar(array $sessao, array $config, ChatExecCtx $ctx): bool
    {
        $st = $ctx->db->prepare("SELECT cliente_id FROM chat_contatos WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$sessao['contato_id']]);
        $clienteId = (int)$st->fetchColumn();
        if ($clienteId < 1) return false;

        $sql    = "SELECT COALESCE(SUM(total), 0) FROM pedidos WHERE cliente_id = :c";
        $params = [':c' => $clienteId];

        $dias = (int)($config['janela_dias'] ?? 0);
        if ($dias > 0) {
            $sql .= " AND criado_em >= DATE_SUB(NOW(), INTERVAL :d DAY)";
            $params[':d'] = $dias;
        }

        $stG = $ctx->db->prepare($sql);
        foreach ($params as $k => $v) $stG->bindValue($k, $v, PDO::PARAM_INT);
        $stG->execute();
        $gasto = (float)$stG->fetchColumn();

        $valor = (float)($config['valor'] ?? 0);
        return match ((string)($config['operador'] ?? '>=')) {
            '>'  => $gasto >  $valor,
            '>=' => $gasto >= $valor,
            '<'  => $gasto <  $valor,
            '<=' => $gasto <= $valor,
            '='  => abs($gasto - $valor) < 0.01,
            default => false,
        };
    }
}

/** config: {"de":8,"ate":18,"dias":[1,2,3,4,5]} — 1=segunda ... 7=domingo */
class ChatNoCondHorario extends ChatNoCondicao
{
    protected function avaliar(array $sessao, array $config, ChatExecCtx $ctx): bool
    {
        $h = (int)date('G');
        $de  = (int)($config['de']  ?? 0);
        $ate = (int)($config['ate'] ?? 24);
        if ($h < $de || $h >= $ate) return false;

        $dias = array_map('intval', (array)($config['dias'] ?? []));
        if ($dias) {
            $hoje = (int)date('N');   // 1=segunda ... 7=domingo
            if (!in_array($hoje, $dias, true)) return false;
        }
        return true;
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// AÇÕES
// ═════════════════════════════════════════════════════════════════════════════

/** config: {"acao":"adicionar|remover","tag_id":3} */
class ChatNoAcaoTag extends ChatNo
{
    public function portas(): array { return ['saida']; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $tagId = (int)($config['tag_id'] ?? 0);
        if ($tagId > 0) {
            if ((string)($config['acao'] ?? 'adicionar') === 'remover') {
                $ctx->contatos->removerTag((int)$sessao['contato_id'], $tagId);
            } else {
                $ctx->contatos->aplicarTag((int)$sessao['contato_id'], $tagId);
            }
        }
        return 'saida';
    }
}

/** config: {"campo":"origem","operacao":"set|incrementar|limpar","valor":"..."} */
class ChatNoAcaoCampo extends ChatNo
{
    public function portas(): array { return ['saida']; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $campo = preg_replace('/[^a-z0-9_]/i', '_', trim((string)($config['campo'] ?? '')));
        if ($campo === '') return 'saida';

        $contatoId = (int)$sessao['contato_id'];
        $operacao  = (string)($config['operacao'] ?? 'set');
        $c         = $this->ctx($sessao);

        if ($operacao === 'limpar') {
            $ctx->contatos->setCampo($contatoId, $campo, null);
            unset($c[$campo]);
        } elseif ($operacao === 'incrementar') {
            $atual = (float)($ctx->contatos->getCampo($contatoId, $campo, 0));
            $novo  = $atual + (float)($config['valor'] ?? 1);
            $ctx->contatos->setCampo($contatoId, $campo, $novo);
            $c[$campo] = $novo;
        } else {
            $valor = ChatContatoService::interpolar((string)($config['valor'] ?? ''), $ctx->vars);
            $ctx->contatos->setCampo($contatoId, $campo, $valor);
            $c[$campo] = $valor;
        }

        $sessao['contexto'] = $c;
        $ctx->recarregarContato($contatoId);
        return 'saida';
    }
}

/**
 * Passa para atendimento humano: pausa o bot, marca a conversa e (opcional)
 * atribui a um agente. É o "escape hatch" que todo bot precisa ter.
 *
 * config: {"atribuir_a":0,"mensagem":"Já te chamo...","pausar_minutos":60,"status":"pendente"}
 */
class ChatNoAcaoHumano extends ChatNo
{
    public function portas(): array { return ['saida']; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $conversaId = (int)($sessao['conversa_id'] ?? 0);
        if ($conversaId < 1) {
            $cv = $ctx->conversas->obterPorContato((int)$sessao['contato_id']);
            $conversaId = (int)($cv['id'] ?? 0);
        }
        if ($conversaId < 1) return 'saida';

        $msg = $this->texto($config, 'mensagem', $ctx);
        if (trim($msg) !== '') {
            $ctx->envio->texto((int)$sessao['contato_id'], $msg, $this->opts($sessao));
        }

        $ctx->conversas->pausarBot($conversaId, (int)($config['pausar_minutos'] ?? 60));
        $ctx->conversas->mudarStatus($conversaId, (string)($config['status'] ?? 'pendente'));

        $agente = (int)($config['atribuir_a'] ?? 0);
        if ($agente > 0) $ctx->conversas->atribuir($conversaId, $agente);

        // Avisa os admins — sem isso o cliente fica esperando no vazio
        if (class_exists('NotificacaoService')) {
            try {
                $nome = $ctx->contato['nome_exibicao'] ?? 'Contato';
                NotificacaoService::criarBroadcast([
                    'categoria' => 'sistema',
                    'tipo'      => 'chat_atendimento',
                    'titulo'    => "Atendimento solicitado — {$nome}",
                    'mensagem'  => 'Um contato pediu para falar com uma pessoa.',
                    'url'       => '/admin/chat/inbox?conversa=' . $conversaId,
                ], 'todos_admins');
            } catch (Throwable $e) {}
        }

        return 'saida';
    }
}

/** config: {"url":"https://...","metodo":"POST","headers":{},"enviar_contexto":true} */
class ChatNoAcaoWebhook extends ChatNo
{
    public function portas(): array { return ['sucesso', 'erro']; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $url = trim(ChatContatoService::interpolar((string)($config['url'] ?? ''), $ctx->vars));

        // Só http(s) e nada de endereço interno: um webhook configurável é um
        // SSRF esperando acontecer se aceitar qualquer coisa.
        if (!preg_match('#^https?://#i', $url) || !$this->destinoPermitido($url)) {
            $sessao['erro_detalhe'] = 'webhook: URL inválida ou não permitida';
            return 'erro';
        }

        $payload = [
            'contato_id' => (int)$sessao['contato_id'],
            'fluxo_id'   => (int)$sessao['fluxo_id'],
            'sessao_id'  => (int)($sessao['id'] ?? 0),
            'wa_id'      => $ctx->contato['wa_id'] ?? null,
            'nome'       => $ctx->contato['nome_exibicao'] ?? null,
            'cliente_id' => $ctx->contato['cliente_id'] ?? null,
        ];
        if (!isset($config['enviar_contexto']) || $config['enviar_contexto']) {
            $payload['contexto'] = array_filter(
                $this->ctx($sessao),
                fn($k) => $k[0] !== '_',      // marcadores internos não vazam
                ARRAY_FILTER_USE_KEY
            );
        }

        $headers = ['Content-Type: application/json'];
        foreach ((array)($config['headers'] ?? []) as $k => $v) {
            if (is_string($k) && is_scalar($v)) $headers[] = $k . ': ' . $v;
        }

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_CUSTOMREQUEST  => strtoupper((string)($config['metodo'] ?? 'POST')),
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_FOLLOWLOCATION => false,   // redirect é rota de fuga do SSRF
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code >= 200 && $code < 300) {
                // Resposta JSON plana vira variável do fluxo
                $dados = json_decode((string)$resp, true);
                if (is_array($dados)) {
                    $c = $this->ctx($sessao);
                    foreach ($dados as $k => $v) {
                        if (is_scalar($v) && preg_match('/^[a-z0-9_]{1,40}$/i', (string)$k)) {
                            $c[$k] = $v;
                        }
                    }
                    $sessao['contexto'] = $c;
                }
                return 'sucesso';
            }

            $sessao['erro_detalhe'] = "webhook HTTP $code";
            return 'erro';
        } catch (Throwable $e) {
            $sessao['erro_detalhe'] = 'webhook: ' . mb_substr($e->getMessage(), 0, 150);
            return 'erro';
        }
    }

    /** Bloqueia loopback, rede privada e link-local. */
    private function destinoPermitido(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return false;

        $ips = @gethostbynamel($host);
        if ($ips === false) {
            // IPv6 ou host que não resolve por A: valida o literal quando for IP
            $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [];
            if (!$ips) return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }
}

/** config: {"titulo":"...","mensagem":"...","url":"..."} */
class ChatNoAcaoNotificarAdmin extends ChatNo
{
    public function portas(): array { return ['saida']; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        if (!class_exists('NotificacaoService')) return 'saida';
        try {
            NotificacaoService::criarBroadcast([
                'categoria' => (string)($config['categoria'] ?? 'sistema'),
                'tipo'      => 'chat_fluxo',
                'titulo'    => $this->texto($config, 'titulo', $ctx, 'Aviso do fluxo de chat'),
                'mensagem'  => $this->texto($config, 'mensagem', $ctx) ?: null,
                'url'       => $this->texto($config, 'url', $ctx) ?: null,
            ], 'todos_admins');
        } catch (Throwable $e) {}
        return 'saida';
    }
}

/** config: {"pct":10,"dias_validade":15,"prefixo":"CHAT","valor_minimo":0} */
class ChatNoAcaoCupom extends ChatNo
{
    public function portas(): array { return ['saida', 'sem_cliente']; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $st = $ctx->db->prepare("SELECT cliente_id FROM chat_contatos WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$sessao['contato_id']]);
        $clienteId = (int)$st->fetchColumn();

        // Cupom é nominal ao cliente; sem cadastro não há a quem amarrar
        if ($clienteId < 1) return 'sem_cliente';

        $c     = $this->ctx($sessao);
        $marca = '_cupom_' . $this->chave($sessao);

        if (!empty($c[$marca])) {           // idempotência ao reprocessar o nó
            $c['cupom_codigo'] = $c[$marca];
            $sessao['contexto'] = $c;
            return 'saida';
        }

        if (!class_exists('AutomacaoCupomService')) {
            $sessao['erro_detalhe'] = 'AutomacaoCupomService indisponível';
            return self::ERRO;
        }

        $pct  = (float)($config['pct'] ?? 10) ?: 10.0;
        $dias = (int)($config['dias_validade'] ?? 15) ?: 15;

        try {
            $r = (new AutomacaoCupomService())->gerarParaFluxo($clienteId, [
                'pct'           => $pct,
                'dias_validade' => $dias,
                'prefixo'       => (string)($config['prefixo'] ?? 'CHAT'),
                'nome'          => (string)($config['nome'] ?? 'Cupom exclusivo WhatsApp'),
                'valor_minimo'  => (float)($config['valor_minimo'] ?? 0),
            ]);
            $codigo = (string)($r['codigo'] ?? '');
            if ($codigo === '') { $sessao['erro_detalhe'] = 'cupom sem código'; return self::ERRO; }

            $pctFmt = rtrim(rtrim(number_format($pct, 1, ',', ''), '0'), ',');
            $c[$marca]             = $codigo;
            $c['cupom_codigo']     = $codigo;
            $c['cupom_valor']      = $pctFmt . '%';
            $c['cupom_validade']   = date('d/m/Y', strtotime("+{$dias} days"));
            $sessao['contexto']    = $c;
            $ctx->vars             = array_merge($ctx->vars, [
                'cupom_codigo'   => $codigo,
                'cupom_valor'    => $pctFmt . '%',
                'cupom_validade' => $c['cupom_validade'],
            ]);
            return 'saida';
        } catch (Throwable $e) {
            $sessao['erro_detalhe'] = 'cupom: ' . mb_substr($e->getMessage(), 0, 150);
            return self::ERRO;
        }
    }
}

/** Registra opt-out a pedido do contato e encerra a jornada. */
class ChatNoAcaoOptout extends ChatNo
{
    public function portas(): array { return ['saida']; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $msg = $this->texto($config, 'mensagem', $ctx);
        if (trim($msg) !== '') {
            $ctx->envio->texto((int)$sessao['contato_id'], $msg, $this->opts($sessao));
        }
        $ctx->contatos->optOut((int)$sessao['contato_id'], 'fluxo de chat');
        return self::ENCERRAR;
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// INSTAGRAM
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Ramifica pelo canal. Existe porque o MESMO fluxo atende WhatsApp e Instagram,
 * e há coisas que só valem em um deles — template HSM só no WhatsApp, resposta
 * pública a comentário só no Instagram.
 *
 * portas: whatsapp | instagram
 */
class ChatNoCondCanal extends ChatNo
{
    public function portas(): array { return ['whatsapp', 'instagram']; }
    public function categoria(): string { return 'condicao'; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        return ($ctx->contato['canal'] ?? 'whatsapp') === 'instagram' ? 'instagram' : 'whatsapp';
    }
}

/**
 * O contato segue a conta no Instagram?
 * Só se sabe disso depois que a pessoa manda DM (é o que a Meta libera), e
 * mesmo assim nem sempre vem. NULL é tratado como "não sei" → porta false.
 */
class ChatNoCondIgSegue extends ChatNoCondicao
{
    protected function avaliar(array $sessao, array $config, ChatExecCtx $ctx): bool
    {
        $st = $ctx->db->prepare(
            "SELECT ig_seguidor FROM chat_contatos WHERE id = :id AND canal = 'instagram' LIMIT 1"
        );
        $st->execute([':id' => (int)$sessao['contato_id']]);
        $v = $st->fetchColumn();
        return $v !== false && $v !== null && (int)$v === 1;
    }
}

/**
 * Card com botões (generic template do Instagram).
 * No WhatsApp cai para uma mensagem com botão de URL, para o mesmo fluxo
 * continuar funcionando nos dois canais em vez de morrer aqui.
 *
 * config: {"titulo","subtitulo","imagem","botao_titulo","botao_url"}
 */
class ChatNoMsgIgCard extends ChatNoMsgTexto
{
    public function portas(): array { return ['saida']; }
    public function categoria(): string { return 'mensagem'; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $titulo = $this->texto($config, 'titulo', $ctx);
        if (trim($titulo) === '') return 'saida';

        $url = trim($this->texto($config, 'botao_url', $ctx));
        $r = $ctx->envio->enviar((int)$sessao['contato_id'], [
            'tipo'        => 'cta_url',
            'corpo'       => $titulo,
            'texto_botao' => $this->texto($config, 'botao_titulo', $ctx, 'Ver'),
            'url'         => $url ?: (defined('BASE_URL') ? BASE_URL : ''),
            'imagem'      => $this->texto($config, 'imagem', $ctx) ?: null,
        ], $this->opts($sessao));

        return $this->tratarEnvio($r, $sessao);
    }
}

/**
 * Responde publicamente ao comentário que originou esta jornada.
 *
 * Só faz sentido em fluxo iniciado por regra de comentário — é de lá que vem
 * o comment_id no contexto. Fora disso, segue sem fazer nada em vez de dar
 * erro: o mesmo fluxo pode ser disparado por outros caminhos.
 *
 * config: {"texto":"..."}  (aceita variações separadas por |)
 */
class ChatNoAcaoIgResponderComentario extends ChatNo
{
    public function portas(): array { return ['saida']; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $c         = $this->ctx($sessao);
        $commentId = (string)($c['_comment_id'] ?? '');
        if ($commentId === '') return 'saida';   // não veio de comentário

        $texto = $this->texto($config, 'texto', $ctx);
        if (trim($texto) === '') return 'saida';

        // Variações separadas por | para o perfil não ficar com 40 respostas iguais
        $opcoes = array_values(array_filter(array_map('trim', explode('|', $texto))));
        $escolhido = $opcoes[random_int(0, max(0, count($opcoes) - 1))] ?? $texto;

        try {
            $svc   = new ChatInstagramService($ctx->db);
            $conta = !empty($ctx->contato['ig_conta_id'])
                ? $svc->conta((int)$ctx->contato['ig_conta_id'])
                : $svc->contaPadrao();

            if ($conta) {
                ChatInstagramClient::daConta($conta)->responderComentario($commentId, $escolhido);
            }
        } catch (Throwable $e) {
            // Comentário pode ter sido apagado entre o gatilho e este passo.
            // Não é motivo para derrubar a jornada inteira.
            if (class_exists('LogService')) {
                try { LogService::warning('ig: resposta pública falhou no fluxo', ['erro' => $e->getMessage()], 'chat'); }
                catch (Throwable $x) {}
            }
        }
        return 'saida';
    }
}


/**
 * Etapa de IA — lê a pergunta do contato e responde a partir do PRODUTO ligado
 * a este bloco.
 *
 * config: {"produto_id":482,"campos":["nome","preco","ficha"],
 *          "responder_publico":true,"tom":"..."}
 * portas: respondeu | nao_sabe
 *
 * A porta `nao_sabe` não é tratamento de erro — é o caminho normal quando a
 * pergunta sai do que o produto responde ("faz frete pro Acre?"). O fluxo
 * decide o que fazer: passar para humano, mandar recado padrão. É o que impede
 * o agente de improvisar sobre o que não sabe.
 */
class ChatNoIaResponder extends ChatNo
{
    public function portas(): array   { return ['respondeu', 'nao_sabe']; }
    public function categoria(): string { return 'acao'; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $c = $this->ctx($sessao);

        // A pergunta é o que a pessoa escreveu: comentário do reel, ou a última
        // mensagem dela no direct quando o bloco roda no meio de uma conversa.
        $pergunta = trim((string)($c['comentario'] ?? $c['ultima_mensagem'] ?? ''));
        if ($pergunta === '') return 'nao_sabe';

        $produtoId = (int)($config['produto_id'] ?? 0);
        if ($produtoId < 1) return 'nao_sabe';

        $agente = $this->agente($ctx);
        $campos = $this->camposDe($config['campos'] ?? null);

        // Produto inativo ou apagado devolve null: melhor calar que falar de
        // algo que saiu do ar.
        $ctxProd = $agente->contextoProduto($produtoId, (array)$campos);
        if ($ctxProd === null) return 'nao_sabe';

        $r = $agente->responder($pergunta, $ctxProd, [
            'publico'   => !empty($config['responder_publico']) && !empty($c['_comment_id']),
            'tom'       => (string)($config['tom'] ?? ''),
            'contato_id'=> (int)($ctx->contato['id'] ?? 0),
            'fluxo_id'  => (int)($sessao['fluxo_id'] ?? 0),
            'limite_dia'=> (int)($config['limite_dia'] ?? 0),
        ]);

        if (!$r['ok']) return 'nao_sabe';

        // ── O que o modelo escreveu vira {{var}}, junto com o produto ──
        // A resposta crua ("R$ 499,90") é a parte que a IA controla. Saudação,
        // convite e link são do operador, no modelo da mensagem — e ficam fora
        // da guarda de números de propósito: a URL tem dígitos e é nossa.
        $precoFmt = isset($ctxProd['preco']['valor'])
            ? 'R$ ' . number_format((float)$ctxProd['preco']['valor'], 2, ',', '.') : '';

        $extra = [
            'resposta'      => (string)$r['direct'],
            'ia_resposta'   => (string)$r['direct'],
            'produto_nome'  => (string)($ctxProd['nome'] ?? ''),
            'produto_url'   => (string)($ctxProd['_url'] ?? ''),
            'produto_preco' => $precoFmt,
        ];
        $vars = array_merge($ctx->vars, $extra);

        $direct  = $this->compor((string)($config['modelo_direct'] ?? ''), $vars);
        $publico = null;
        if (!empty($r['publico'])) {
            $publico = $this->compor((string)($config['modelo_publico'] ?? ''),
                                     array_merge($vars, ['resposta' => (string)$r['publico']]));
        }

        // Resposta pública primeiro: quem comentou espera ver algo no post, e
        // se o direct falhar (janela fechada) o comentário já foi respondido.
        if ($publico !== null && trim($publico) !== '' && !empty($c['_comment_id'])) {
            $this->responderComentario($ctx, (string)$c['_comment_id'], $publico);
        }

        if (trim($direct) !== '') {
            $ctx->envio->texto(
                (int)$ctx->contato['id'], $direct,
                $this->opts($sessao, ['ia' => 1])
            );
        }

        // Chaves públicas do contexto viram {{var}} no passo seguinte: um
        // "Texto" depois deste bloco pode usar {{ia_resposta}} e {{produto_url}}.
        unset($extra['resposta']);
        $c = array_merge($c, $extra);
        $sessao['contexto'] = $c;
        $ctx->vars = array_merge($ctx->vars, $extra);

        return 'respondeu';
    }

    /** Costura para o teste trocar o modelo por um dublê. */
    protected function agente(ChatExecCtx $ctx): ChatIaAgenteService
    {
        return new ChatIaAgenteService($ctx->db);
    }

    /**
     * Aplica o modelo da mensagem. Modelo vazio = só a resposta, que é o
     * comportamento antigo; modelo sem {{resposta}} ganha ela no começo, para
     * um operador que só escreveu a assinatura não mandar a assinatura sozinha.
     */
    private function compor(string $modelo, array $vars): string
    {
        $modelo = trim($modelo);
        if ($modelo === '') return (string)($vars['resposta'] ?? '');
        if (!str_contains($modelo, '{{resposta}}')) $modelo = "{{resposta}}\n\n" . $modelo;
        return trim(ChatContatoService::interpolar($modelo, $vars));
    }

    /**
     * O painel guarda um preset ('todos'|'sem_preco'|'so_ficha'); o agente
     * espera a lista de campos. Sem esta tradução o preset virava `['todos']`,
     * nenhum campo casava, e o bloco respondia "não sei" a tudo — falha
     * silenciosa que só aparecia depois de alguém abrir o painel uma vez.
     */
    private function camposDe($v): array
    {
        if (is_array($v)) return $v ?: ChatIaAgenteService::CAMPOS;

        switch ((string)$v) {
            case 'sem_preco': return ['nome', 'descricao', 'ficha', 'compatibilidade'];
            case 'so_ficha':  return ['nome', 'ficha', 'compatibilidade'];
            default:          return ChatIaAgenteService::CAMPOS;
        }
    }

    private function responderComentario(ChatExecCtx $ctx, string $commentId, string $texto): void
    {
        try {
            $svc   = new ChatInstagramService($ctx->db);
            $conta = !empty($ctx->contato['ig_conta_id'])
                ? $svc->conta((int)$ctx->contato['ig_conta_id'])
                : $svc->contaPadrao();
            if ($conta) {
                ChatInstagramClient::daConta($conta)->responderComentario($commentId, $texto);
            }
        } catch (Throwable $e) {
            // O comentário pode ter sido apagado entre o gatilho e este passo
            if (class_exists('LogService')) {
                try { LogService::warning('ia: resposta pública falhou', ['erro' => $e->getMessage()], 'chat'); }
                catch (Throwable $x) {}
            }
        }
    }
}


/**
 * Oferece um cupom JÁ EXISTENTE que sirva no produto, com botão.
 *
 * config: {"produto_id":482,"texto":"...","rotulo_botao":"Buscar cupom"}
 * portas: pegou | recusou | sem_cupom
 *
 * DIFERENTE do `acao_cupom`: aquele CRIA um cupom nominal e exige cliente
 * cadastrado — quem comentou num reel é um IGSID sem cliente_id, e cairia
 * sempre em `sem_cliente`. Este procura um cupom que a loja já cadastrou e
 * marcou como divulgável.
 *
 * O botão não é enfeite: pedir para a pessoa AGIR antes de receber o código
 * separa quem tem interesse real de quem só passou. E o código só sai depois
 * do toque, então não fica exposto para quem nunca pediu.
 */
class ChatNoAcaoCupomProduto extends ChatNo
{
    public function portas(): array { return ['pegou', 'recusou', 'sem_cupom']; }
    public function ehPergunta(): bool { return true; }
    public function categoria(): string { return 'acao'; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        // ── 2ª passada: a pessoa respondeu ──
        if ($resposta = $this->respostaRecebida($sessao)) {
            $this->limparMarca($sessao, 'env');
            $c = $this->ctx($sessao);
            unset($c['_resposta_' . $this->chave($sessao)]);
            $sessao['contexto'] = $c;

            if (($resposta['id'] ?? '') !== 'btn_1') return 'recusou';
            return $this->entregar($sessao, $config, $ctx);
        }

        // ── 1ª passada: procura e oferece ──
        //
        // O produto pode vir fixo (o caso original: automação de um reel sobre
        // um produto) ou por variável — `{{carrinho_produto_id}}` no fluxo de
        // carrinho abandonado, onde o produto muda a cada evento.
        //
        // Interpolar antes do cast é retrocompatível: um número cru interpola
        // para ele mesmo, então as automações que já existem não mudam.
        $produtoId = (int) ChatContatoService::interpolar(
            (string)($config['produto_id'] ?? ''), $ctx->vars
        );
        if ($produtoId < 1) return 'sem_cupom';

        $cupom = (new ChatCupomCarrinhoService($ctx->db))->cupomParaProduto($produtoId);
        if (!$cupom) return 'sem_cupom';

        if (!$this->jaEnviou($sessao)) {
            // Guarda o cupom no contexto: entre oferecer e a pessoa tocar podem
            // passar horas, e reconsultar poderia trazer OUTRO cupom — ou
            // nenhum, se o primeiro esgotou. Prometer e não entregar é pior.
            $c = $this->ctx($sessao);
            $c['_cupom_oferecido'] = $cupom;
            $sessao['contexto'] = $c;

            $r = $ctx->envio->botoes(
                (int)$sessao['contato_id'],
                $this->texto($config, 'texto', $ctx, 'Tenho um cupom para este produto 👀'),
                [['id' => 'btn_1', 'titulo' => mb_substr(
                    $this->texto($config, 'rotulo_botao', $ctx, 'Buscar cupom'), 0, 20)]],
                $this->opts($sessao)
            );
            if (!$r['ok']) return $this->tratarEnvio($r, $sessao);
            $this->marcarEnviado($sessao);
        }

        $sessao['aguardando_ate'] = date('Y-m-d H:i:s', time() + $this->segundosDe((array)($config['timeout'] ?? []), 86400));
        return self::AGUARDAR;
    }

    /** Manda o código com as regras — prazo e mínimo são o que gera reclamação. */
    private function entregar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $c = $this->ctx($sessao);
        $cupom = is_array($c['_cupom_oferecido'] ?? null) ? $c['_cupom_oferecido'] : null;
        if (!$cupom) return 'sem_cupom';

        $svc = new ChatCupomCarrinhoService($ctx->db);

        $texto = "Seu cupom: *{$cupom['codigo']}*\n\n"
               . ucfirst($svc->descreverCupom($cupom)) . ".\n\n"
               . 'É só aplicar no carrinho antes de finalizar.';

        $ctx->envio->texto((int)$sessao['contato_id'], $texto, $this->opts($sessao));

        $c['cupom_codigo'] = $cupom['codigo'];
        unset($c['_cupom_oferecido']);
        $sessao['contexto'] = $c;

        return 'pegou';
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// MULTICANAL — a mesma pessoa, vários caminhos
// ═════════════════════════════════════════════════════════════════════════════

/**
 * O canal está utilizável para esta PESSOA agora?
 *
 * Uma sessão roda amarrada a um contato, mas a pessoa pode ter WhatsApp,
 * Instagram e e-mail. Este nó olha para todos (via cliente_id) e responde se
 * aquele caminho específico está aberto — existe, tem opt-in e, quando a
 * janela importa, está dentro dela.
 *
 * config: {"canal":"instagram|whatsapp|email","exigir_janela":true}
 * portas: true | false  (herdadas de ChatNoCondicao)
 */
class ChatNoCondCanalDisponivel extends ChatNoCondicao
{
    public function categoria(): string { return 'condicao'; }

    protected function avaliar(array $sessao, array $config, ChatExecCtx $ctx): bool
    {
        $canal = (string)($config['canal'] ?? '');
        if (!in_array($canal, ChatCanalPessoaService::CANAIS, true)) return false;

        // Padrão true: no Instagram, fora da janela a Meta simplesmente
        // recusa, então "existe o canal" sem "dá para falar" seria uma
        // resposta que engana quem desenhou o fluxo.
        $exigir = !isset($config['exigir_janela']) || (bool)$config['exigir_janela'];

        return (new ChatCanalPessoaService($ctx->db))
            ->alcancavel((int)$sessao['contato_id'], $canal, $exigir);
    }
}

/**
 * A pessoa já comprou este produto?
 *
 * O freio de mão da cascata de recuperação: insistir com quem já comprou não
 * é só inútil, é o tipo de mensagem que faz bloquear a loja. Vai ANTES de
 * cada envio, não só no começo — entre uma etapa e outra passam-se dias.
 *
 * config: {"produto_id":"{{carrinho_produto_id}}","desde_horas":0}
 * portas: comprou | nao_comprou
 */
class ChatNoCondProdutoComprado extends ChatNo
{
    public function portas(): array { return ['comprou', 'nao_comprou']; }
    public function categoria(): string { return 'condicao'; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        // Aceita id fixo ou variável — no carrinho abandonado o produto muda
        // a cada evento, e vem em {{carrinho_produto_id}}.
        $produtoId = (int) ChatContatoService::interpolar(
            (string)($config['produto_id'] ?? ''), $ctx->vars
        );
        if ($produtoId < 1) return 'nao_comprou';

        $st = $ctx->db->prepare("SELECT cliente_id FROM chat_contatos WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$sessao['contato_id']]);
        $clienteId = (int)$st->fetchColumn();
        if ($clienteId < 1) return 'nao_comprou';

        $desde = max(0, (int)($config['desde_horas'] ?? 0));

        return (new ChatCanalPessoaService($ctx->db))
            ->comprouProduto($clienteId, $produtoId, $desde)
            ? 'comprou' : 'nao_comprou';
    }
}

/**
 * Manda a mensagem por um canal escolhido — inclusive um que NÃO é o da sessão.
 *
 * WhatsApp e Instagram saem pelo ChatEnvioService, endereçados ao contato
 * daquele canal. E-mail sai pelo MailHelper, que não passa por contato nenhum:
 * o endereço vem de `usuarios`, via `clientes.usuario_id`.
 *
 * POR QUE UM BLOCO SÓ E NÃO TRÊS: o operador desenha "tente aqui, senão ali".
 * Três blocos com a mesma forma e nomes diferentes espalhariam a mesma decisão
 * por três lugares. Aqui o canal é um campo.
 *
 * config: {"canal":"instagram|whatsapp|email","texto":"...",
 *          "assunto":"(e-mail)","botao_texto":"...","botao_url":"..."}
 * portas: enviado | sem_canal | falhou
 */
class ChatNoMsgCanal extends ChatNo
{
    public function portas(): array { return ['enviado', 'sem_canal', 'falhou']; }
    public function categoria(): string { return 'mensagem'; }

    public function executar(array &$sessao, array $config, ChatExecCtx $ctx): string
    {
        $canal = (string)($config['canal'] ?? '');
        if (!in_array($canal, ChatCanalPessoaService::CANAIS, true)) {
            $sessao['erro_detalhe'] = 'canal inválido: ' . $canal;
            return self::ERRO;
        }

        // Reprocessar a sessão não pode remandar o que já saiu
        if ($this->jaEnviou($sessao)) return 'enviado';

        $pessoa  = new ChatCanalPessoaService($ctx->db);
        $destino = $pessoa->destino((int)$sessao['contato_id'], $canal);
        if (!$destino) return 'sem_canal';

        // Template escolhido vence o texto solto: o conteúdo mora na Central
        // de Recuperação e edita-se num lugar só, sem caçar a mesma frase
        // dentro de cinco fluxos diferentes.
        $tpl = $this->template($config, $ctx);
        if ($tpl !== null) {
            $texto  = $tpl['conteudo'];
            $config = array_merge($config, array_filter([
                'assunto' => $tpl['assunto'],
            ], fn($v) => $v !== null && $v !== ''));
        } else {
            $texto = trim($this->texto($config, 'texto', $ctx));
        }

        if ($texto === '') {
            $sessao['erro_detalhe'] = 'mensagem sem texto';
            return self::ERRO;
        }

        $ok = $canal === 'email'
            ? $this->porEmail($destino, $config, $texto, $ctx)
            : $this->porChat($destino, $sessao, $texto, $ctx);

        if (!$ok) return 'falhou';

        $this->marcarEnviado($sessao);
        return 'enviado';
    }

    /**
     * O template de conteúdo escolhido, já renderizado — ou null.
     *
     * Os templates da Central falam `{variavel}` (chave simples); o motor de
     * fluxos fala `{{variavel}}`. Traduzir aqui, e não mudar um dos dois lados,
     * mantém os textos servindo TAMBÉM o envio manual do operador — que é a
     * razão de eles existirem.
     *
     * @return array{conteudo:string, assunto:?string}|null
     */
    private function template(array $config, ChatExecCtx $ctx): ?array
    {
        $id = (int)($config['template_id'] ?? 0);
        if ($id < 1) return null;

        try {
            $st = $ctx->db->prepare(
                "SELECT conteudo, assunto FROM recuperacao_templates
                 WHERE id = :id AND ativo = 1 LIMIT 1"
            );
            $st->execute([':id' => $id]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        if (!$t) return null;   // apagado ou desativado → cai no texto solto

        // O vocabulário da Central mapeado no contexto do fluxo. Sem isto,
        // {valor} e {link} sairiam literais na mensagem.
        $v = $ctx->vars;
        $de = [
            '{nome}'          => $v['nome']              ?? '',
            '{primeiro_nome}' => $v['primeiro_nome']     ?? '',
            '{loja}'          => $v['site_nome']         ?? '',
            '{valor}'         => $v['carrinho_valor']    ?? '',
            '{produtos}'      => $v['carrinho_produtos'] ?? '',
            '{link}'          => $v['carrinho_link']     ?? '',
            '{vendedor}'      => $v['site_nome']         ?? '',
            '{telefone_loja}' => defined('LOJA_TELEFONE') ? LOJA_TELEFONE : '',
            // Só o e-mail do operador monta a tabela HTML; num fluxo não há
            // de onde tirá-la, e deixar a chave crua na mensagem seria pior.
            '{produtos_html}' => $v['carrinho_produtos'] ?? '',
        ];

        return [
            // strtr para o vocabulário da Central; interpolar para o do fluxo —
            // um template pode usar os dois, e os dois resolvem.
            'conteudo' => ChatContatoService::interpolar(strtr((string)$t['conteudo'], $de), $v),
            'assunto'  => $t['assunto'] !== null && $t['assunto'] !== ''
                          ? ChatContatoService::interpolar(strtr((string)$t['assunto'], $de), $v)
                          : null,
        ];
    }

    /** WhatsApp e Instagram: sempre endereçado ao contato DAQUELE canal. */
    private function porChat(array $destino, array $sessao, string $texto, ChatExecCtx $ctx): bool
    {
        $r = $ctx->envio->texto((int)$destino['contato_id'], $texto, $this->opts($sessao));
        return !empty($r['ok']);
    }

    /**
     * E-mail não passa por chat_contatos nem por janela. O botão é opcional
     * e serve ao link de retorno do carrinho.
     */
    private function porEmail(array $destino, array $config, string $texto, ChatExecCtx $ctx): bool
    {
        if (!class_exists('MailHelper')) return false;

        $assunto = trim($this->texto($config, 'assunto', $ctx)) ?: 'Uma mensagem da loja';
        $opcoes  = [];

        $btTexto = trim($this->texto($config, 'botao_texto', $ctx));
        $btUrl   = trim($this->texto($config, 'botao_url', $ctx));
        if ($btTexto !== '' && $btUrl !== '') {
            $opcoes['botao_texto'] = $btTexto;
            $opcoes['botao_url']   = $btUrl;
        }

        try {
            return MailHelper::sendSimples(
                (string)$destino['identidade'],
                (string)($destino['nome'] ?? ''),
                $assunto,
                nl2br(htmlspecialchars($texto, ENT_QUOTES, 'UTF-8')),
                $opcoes
            );
        } catch (Throwable $e) {
            return false;
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// REGISTRY
// ═════════════════════════════════════════════════════════════════════════════

class ChatNoRegistry
{
    /** tipo_no → classe */
    private const MAPA = [
        // triggers
        'gatilho_palavra'      => ChatNoGatilhoPalavra::class,
        'gatilho_boas_vindas'  => ChatNoGatilhoBoasVindas::class,
        'gatilho_padrao'       => ChatNoGatilhoPadrao::class,
        'gatilho_referencia'   => ChatNoGatilhoReferencia::class,
        'gatilho_manual'       => ChatNoGatilhoManual::class,
        'gatilho_evento_loja'  => ChatNoGatilhoEventoLoja::class,
        // mensagens
        'msg_texto'            => ChatNoMsgTexto::class,
        'msg_midia'            => ChatNoMsgMidia::class,
        'msg_botoes'           => ChatNoMsgBotoes::class,
        'msg_lista'            => ChatNoMsgLista::class,
        'msg_template'         => ChatNoMsgTemplate::class,
        'msg_botao_url'        => ChatNoMsgBotaoUrl::class,
        // lógica
        'esperar'              => ChatNoEsperar::class,
        'esperar_resposta'     => ChatNoEsperarResposta::class,
        'split_ab'             => ChatNoSplitAb::class,
        'encerrar'             => ChatNoEncerrar::class,
        'ir_para_fluxo'        => ChatNoIrParaFluxo::class,
        // condições
        'cond_tem_tag'         => ChatNoCondTemTag::class,
        'cond_campo'           => ChatNoCondCampo::class,
        'cond_na_janela'       => ChatNoCondNaJanela::class,
        'cond_eh_cliente'      => ChatNoCondEhCliente::class,
        'cond_comprou'         => ChatNoCondComprou::class,
        'cond_horario'         => ChatNoCondHorario::class,
        // ações
        'acao_tag'             => ChatNoAcaoTag::class,
        'acao_campo'           => ChatNoAcaoCampo::class,
        'acao_humano'          => ChatNoAcaoHumano::class,
        'acao_webhook'         => ChatNoAcaoWebhook::class,
        'acao_notificar_admin' => ChatNoAcaoNotificarAdmin::class,
        'acao_cupom'           => ChatNoAcaoCupom::class,
        'acao_optout'          => ChatNoAcaoOptout::class,
        // instagram
        'cond_canal'                  => ChatNoCondCanal::class,
        'cond_ig_segue'               => ChatNoCondIgSegue::class,
        'msg_ig_card'                 => ChatNoMsgIgCard::class,
        'acao_ig_responder_comentario'=> ChatNoAcaoIgResponderComentario::class,
        // ia
        'ia_responder'                => ChatNoIaResponder::class,
        'acao_cupom_produto'          => ChatNoAcaoCupomProduto::class,
        // multicanal
        'cond_canal_disponivel'       => ChatNoCondCanalDisponivel::class,
        'cond_produto_comprado'       => ChatNoCondProdutoComprado::class,
        'msg_canal'                   => ChatNoMsgCanal::class,
    ];

    /** @var array<string,ChatNo> instâncias stateless reutilizáveis */
    private static array $inst = [];

    public static function existe(string $tipo): bool
    {
        return isset(self::MAPA[$tipo]);
    }

    public static function obter(string $tipo): ?ChatNo
    {
        if (!isset(self::MAPA[$tipo])) return null;
        return self::$inst[$tipo] ??= new (self::MAPA[$tipo])();
    }

    public static function ehTrigger(string $tipo): bool
    {
        $n = self::obter($tipo);
        return $n ? $n->ehTrigger() : false;
    }

    public static function ehPergunta(string $tipo): bool
    {
        $n = self::obter($tipo);
        return $n ? $n->ehPergunta() : false;
    }

    /** Catálogo consumido pelo canvas: tipo → {portas, trigger, categoria, pergunta}. */
    /**
     * Nome legível do bloco, para telas e relatórios do lado PHP.
     *
     * Os rótulos nasceram na paleta do editor (JS) e continuam lá, porque lá
     * eles vêm acompanhados de ícone, descrição e campos. Aqui fica só o nome,
     * que é o que o servidor precisa para dizer "a conversa parou no bloco X"
     * sem cuspir o slug cru. Tipo sem rótulo devolve o próprio slug: é feio,
     * mas é informativo, e não deixa a tela em branco.
     */
    public static function rotulo(string $tipo): string
    {
        static $mapa = [
        'gatilho_palavra'               => 'Palavra-chave',
        'gatilho_boas_vindas'           => 'Primeira mensagem',
        'gatilho_padrao'                => 'Resposta padrão',
        'gatilho_referencia'            => 'Link com código',
        'gatilho_manual'                => 'Disparo manual',
        'gatilho_evento_loja'           => 'Evento da loja',
        'msg_texto'                     => 'Texto',
        'msg_midia'                     => 'Imagem / arquivo',
        'msg_botoes'                    => 'Pergunta com botões',
        'msg_lista'                     => 'Menu em lista',
        'msg_template'                  => 'Template aprovado',
        'msg_botao_url'                 => 'Botão com link',
        'esperar'                       => 'Esperar',
        'esperar_resposta'              => 'Perguntar',
        'split_ab'                      => 'Teste A/B',
        'encerrar'                      => 'Encerrar',
        'ir_para_fluxo'                 => 'Ir para outro fluxo',
        'cond_tem_tag'                  => 'Tem a tag?',
        'cond_campo'                    => 'Valor do campo',
        'cond_na_janela'                => 'Janela 24h aberta?',
        'cond_eh_cliente'               => 'É cliente da loja?',
        'cond_comprou'                  => 'Quanto já comprou',
        'cond_horario'                  => 'Horário / dia',
        'acao_tag'                      => 'Marcar com tag',
        'acao_campo'                    => 'Gravar campo',
        'acao_humano'                   => 'Chamar atendente',
        'acao_webhook'                  => 'Chamar webhook',
        'acao_notificar_admin'          => 'Avisar a equipe',
        'acao_cupom'                    => 'Gerar cupom',
        'acao_optout'                   => 'Descadastrar',
        'cond_canal'                    => 'Qual canal?',
        'cond_ig_segue'                 => 'Segue o perfil?',
        'msg_ig_card'                   => 'Card com botão',
        'ia_responder'                  => 'Etapa de IA',
        'acao_cupom_produto'            => 'Oferecer cupom',
        'acao_ig_responder_comentario'  => 'Responder comentário',
        'cond_canal_disponivel'         => 'Canal disponível?',
        'cond_produto_comprado'         => 'Já comprou o produto?',
        'msg_canal'                     => 'Mensagem por canal',
        ];
        return $mapa[$tipo] ?? $tipo;
    }

    public static function catalogo(): array
    {
        $out = [];
        foreach (array_keys(self::MAPA) as $tipo) {
            $n = self::obter($tipo);
            $out[$tipo] = [
                'portas'    => $n->portas(),
                'trigger'   => $n->ehTrigger(),
                'categoria' => $n->categoria(),
                'pergunta'  => $n->ehPergunta(),
            ];
        }
        return $out;
    }

    public static function tipos(): array
    {
        return array_keys(self::MAPA);
    }
}
