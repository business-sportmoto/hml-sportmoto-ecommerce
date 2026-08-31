<?php
/**
 * app/services/ChatEnvioService.php
 *
 * Fachada única de envio. TODO envio do módulo passa por aqui — inbox, fluxo,
 * campanha e gatilho. Ter um só caminho é o que garante que as regras abaixo
 * não sejam esquecidas em um dos chamadores:
 *
 *   1. opt-out e bloqueio  → nunca envia
 *   2. janela de 24h       → fora dela, só template HSM (a Meta recusa o resto)
 *   3. quiet hours         → só para envio proativo (campanha/fluxo agendado);
 *                            resposta a cliente ativo ignora
 *   4. persistência        → sucesso E falha viram linha em chat_mensagens,
 *                            senão o inbox mente sobre o que foi enviado
 *
 * Nenhum método lança exceção: todos devolvem
 *   ['ok'=>bool, 'mensagem_id'=>?int, 'wamid'=>?string, 'erro'=>?string, 'motivo'=>?string]
 * Um erro de WhatsApp não pode derrubar um checkout nem um worker.
 */
class ChatEnvioService
{
    private PDO $db;
    private ChatContatoService  $contatos;
    private ChatConversaService $conversas;
    private ChatMensagemService $mensagens;
    private ?ChatMetaClient     $meta = null;
    private ?string             $erroConfig = null;
    /** @var array<int,array|null> cache das contas do Instagram por id */
    private array               $contasIg = [];
    /** @var array<int,string|null> cache do nome do agente por usuarios.id */
    private array               $nomesAgente = [];

    /** Motivos de recusa — o chamador ramifica por eles. */
    public const MOTIVO_OPTOUT      = 'optout';
    public const MOTIVO_BLOQUEADO   = 'bloqueado';
    public const MOTIVO_FORA_JANELA = 'fora_janela';
    public const MOTIVO_QUIET_HOURS = 'quiet_hours';
    public const MOTIVO_CONFIG      = 'config';
    public const MOTIVO_API         = 'api';

    public function __construct(?PDO $db = null)
    {
        $this->db        = $db ?? Database::getInstance()->getConnection();
        $this->contatos  = new ChatContatoService($this->db);
        $this->conversas = new ChatConversaService($this->db);
        $this->mensagens = new ChatMensagemService($this->db);

        try {
            $this->meta = new ChatMetaClient();
        } catch (Throwable $e) {
            // Credencial ausente não pode explodir na construção — o admin
            // precisa conseguir abrir a tela de config para arrumar.
            $this->erroConfig = $e->getMessage();
        }
    }

    public function disponivel(): bool { return $this->meta !== null; }
    public function erroConfig(): ?string { return $this->erroConfig; }
    public function cliente(): ?ChatMetaClient { return $this->meta; }

    /**
     * O canal deste contato está operante?
     * WhatsApp depende do .env; Instagram depende de uma conta conectada.
     */
    public function disponivelPara(string $canal): bool
    {
        return $canal === 'instagram'
            ? ($this->contaIg() !== null)
            : ($this->meta !== null);
    }

    /** Conta do Instagram em uso (memoizada por request). */
    private function contaIg(?int $contaId = null): ?array
    {
        $chave = $contaId ?: 0;
        if (array_key_exists($chave, $this->contasIg)) return $this->contasIg[$chave];

        try {
            $svc = new ChatInstagramService($this->db);
            $c   = $contaId ? $svc->conta($contaId) : $svc->contaPadrao();
            // Conta desativada ou sem token não serve para enviar
            if ($c && ((int)$c['ativo'] !== 1 || empty($c['page_token']))) $c = null;
        } catch (Throwable $e) {
            $c = null;
        }
        return $this->contasIg[$chave] = $c;
    }

    // =========================================================================
    // API PÚBLICA — açúcar por tipo
    // =========================================================================

    public function texto(int $contatoId, string $texto, array $opts = []): array
    {
        return $this->enviar($contatoId, ['tipo' => 'texto', 'texto' => $texto], $opts);
    }

    public function midia(int $contatoId, string $tipoMidia, string $origem, array $opts = []): array
    {
        return $this->enviar($contatoId, [
            'tipo'      => 'midia',
            'tipo_midia' => $tipoMidia,
            'origem'    => $origem,
            'legenda'   => $opts['legenda'] ?? null,
            'nome_arquivo' => $opts['nome_arquivo'] ?? null,
        ], $opts);
    }

    public function botoes(int $contatoId, string $corpo, array $botoes, array $opts = []): array
    {
        return $this->enviar($contatoId, [
            'tipo'      => 'botoes',
            'corpo'     => $corpo,
            'botoes'    => $botoes,
            'cabecalho' => $opts['cabecalho'] ?? null,
            'rodape'    => $opts['rodape'] ?? null,
        ], $opts);
    }

    public function lista(int $contatoId, string $corpo, string $textoBotao, array $secoes, array $opts = []): array
    {
        return $this->enviar($contatoId, [
            'tipo'        => 'lista',
            'corpo'       => $corpo,
            'texto_botao' => $textoBotao,
            'secoes'      => $secoes,
            'cabecalho'   => $opts['cabecalho'] ?? null,
            'rodape'      => $opts['rodape'] ?? null,
        ], $opts);
    }

    public function template(int $contatoId, string $nome, string $idioma = 'pt_BR', array $componentes = [], array $opts = []): array
    {
        return $this->enviar($contatoId, [
            'tipo'        => 'template',
            'nome'        => $nome,
            'idioma'      => $idioma,
            'componentes' => $componentes,
        ], $opts);
    }

    public function botaoUrl(int $contatoId, string $corpo, string $textoBotao, string $url, array $opts = []): array
    {
        return $this->enviar($contatoId, [
            'tipo'        => 'cta_url',
            'corpo'       => $corpo,
            'texto_botao' => $textoBotao,
            'url'         => $url,
            'cabecalho'   => $opts['cabecalho'] ?? null,
            'rodape'      => $opts['rodape'] ?? null,
        ], $opts);
    }

    // =========================================================================
    // NÚCLEO
    // =========================================================================

    /**
     * @param array $spec descrição da mensagem (ver métodos acima)
     * @param array $opts origem, origem_id, autor_usuario_id, responder_a,
     *                    proativo (bool), ignorar_janela (bool), pausar_bot (bool),
     *                    vars (array para interpolação)
     */
    public function enviar(int $contatoId, array $spec, array $opts = []): array
    {
        $contato = $this->contatos->obter($contatoId);
        if (!$contato) return $this->falha(self::MOTIVO_CONFIG, 'contato não encontrado');

        $canal = (string)($contato['canal'] ?? 'whatsapp');

        // ── 0. Canal operante? ──
        if ($canal === 'instagram') {
            if (!$this->contaIg($contato['ig_conta_id'] ?? null)) {
                return $this->falha(self::MOTIVO_CONFIG,
                    'nenhuma conta do Instagram conectada e ativa');
            }
        } elseif (!$this->meta) {
            return $this->falha(self::MOTIVO_CONFIG, $this->erroConfig ?? 'WhatsApp não configurado');
        }

        // ── 1. Permissão do contato ──
        if ((int)$contato['bloqueado'] === 1) {
            return $this->falha(self::MOTIVO_BLOQUEADO, 'contato bloqueado');
        }
        // Template de AUTENTICAÇÃO (2FA, senha) é transacional e não obedece
        // opt-out de marketing — mas mesmo assim respeita bloqueio.
        if ((int)$contato['optin'] !== 1 && empty($opts['transacional'])) {
            return $this->falha(self::MOTIVO_OPTOUT, 'contato fez opt-out');
        }

        // ── 2. Janela ──
        // As duas plataformas dão 24h, mas o que libera FORA dela é diferente:
        // no WhatsApp é template HSM; no Instagram é a tag HUMAN_AGENT, que
        // vale 7 dias. Tratar como a mesma regra faria o IG recusar envios
        // perfeitamente válidos.
        if (empty($opts['ignorar_janela'])) {
            if ($canal === 'instagram') {
                if (!$this->contatos->naJanela($contato)) {
                    $ateHumano = $contato['janela_humana_ate'] ?? null;
                    $podeHumano = $ateHumano !== null && strtotime((string)$ateHumano) > time();

                    if (!$podeHumano) {
                        return $this->falha(self::MOTIVO_FORA_JANELA,
                            'janela do Instagram encerrada — passaram-se mais de 7 dias desde a última mensagem do contato');
                    }
                    // Dentro dos 7 dias: segue com a tag de atendimento humano
                    $opts['_tag_ig'] = ChatInstagramClient::TAG_HUMAN_AGENT;
                }
            } else {
                $ehTemplate = ($spec['tipo'] ?? '') === 'template';
                if (!$ehTemplate && !$this->contatos->naJanela($contato)) {
                    return $this->falha(
                        self::MOTIVO_FORA_JANELA,
                        'janela de 24h fechada — só template HSM é aceito pela Meta'
                    );
                }
            }
        }

        // ── 3. Quiet hours (só para envio proativo) ──
        if (!empty($opts['proativo']) && !ChatConfig::dentroDaJanelaHoraria()) {
            return $this->falha(
                self::MOTIVO_QUIET_HOURS,
                'fora do horário permitido (próxima janela: ' . (ChatConfig::proximaJanelaHoraria() ?? '?') . ')'
            );
        }

        // ── 4. Interpolação ──
        $vars = $opts['vars'] ?? $this->contatos->variaveis($contato);
        $spec = $this->interpolarSpec($spec, $vars);

        // ── 4b. Assinatura do atendente ──
        // Depois da interpolação, para que o nome do agente nunca seja lido como
        // variável. Só quando há autor humano: fluxo, gatilho e campanha não têm
        // autor_usuario_id e por isso saem sem assinatura, como devem.
        if (!empty($opts['autor_usuario_id']) && empty($opts['sem_assinatura'])) {
            $prefixo = $this->assinatura((int)$opts['autor_usuario_id'], $canal, $spec['tipo'] ?? 'texto');
            if ($prefixo !== '') $spec = self::aplicarAssinatura($spec, $prefixo);
        }

        // ── 5. Disparo ──
        $conversa = $this->conversas->obterPorContato((int)$contato['id'], $canal)
                 ?: $this->conversas->garantir((int)$contato['id'], $canal);

        $respostaA = $opts['responder_a'] ?? null;
        $wamid = null; $erro = null; $erroCodigo = null;

        try {
            $r = $canal === 'instagram'
                ? $this->despacharInstagram($contato, $spec, $opts['_tag_ig'] ?? null)
                : $this->despachar((string)$contato['wa_id'], $spec, $respostaA);
            $wamid = $r['wamid'] ?? null;
        } catch (ChatMetaException $e) {
            $erro       = $e->getMessage();
            $erroCodigo = $e->metaCode;
        } catch (ChatIgException $e) {
            $erro       = $e->getMessage();
            $erroCodigo = $e->metaCode;
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }

        // ── 6. Persistência (sucesso e falha) ──
        $msgId = $this->mensagens->gravar([
            'conversa_id'      => (int)$conversa['id'],
            'contato_id'       => (int)$contato['id'],
            'direcao'          => 'saida',
            'tipo'             => $this->tipoPersistido($spec),
            'texto'            => $this->textoPersistido($spec),
            'payload'          => $this->payloadPersistido($spec),
            'wamid'            => $wamid,
            'resposta_a'       => $respostaA,
            'status'           => $erro ? 'falhou' : 'enviado',
            'erro_codigo'      => $erroCodigo,
            'erro_detalhe'     => $erro,
            'origem'           => $opts['origem'] ?? 'inbox',
            'origem_id'        => $opts['origem_id'] ?? null,
            'autor_usuario_id' => $opts['autor_usuario_id'] ?? null,
        ]);

        if ($erro) {
            // Erro 131047 significa que a janela fechou entre a checagem e o
            // envio (corrida real em conversa parada há ~24h). Reclassifica
            // para o chamador poder cair no template.
            $motivo = ($erroCodigo === ChatMetaClient::ERRO_FORA_DA_JANELA)
                ? self::MOTIVO_FORA_JANELA : self::MOTIVO_API;

            $this->logar('error', 'chat: falha no envio', [
                'contato_id' => $contato['id'], 'erro' => $erro, 'meta_code' => $erroCodigo,
            ]);
            return ['ok' => false, 'mensagem_id' => $msgId, 'wamid' => null, 'erro' => $erro, 'motivo' => $motivo];
        }

        $this->contatos->registrarSaida((int)$contato['id']);

        // Um humano respondendo cala o bot; automação não pausa nada
        if (!empty($opts['pausar_bot'])) {
            $this->conversas->pausarBot((int)$conversa['id']);
        }

        return ['ok' => true, 'mensagem_id' => $msgId, 'wamid' => $wamid, 'erro' => null, 'motivo' => null];
    }

    /**
     * Traduz a spec para o Instagram.
     *
     * O Instagram não tem listas nem templates HSM. Os mesmos nós de fluxo
     * servem os dois canais porque aqui as diferenças são absorvidas:
     *   · botões  → quick replies (payload = a própria porta: btn_1, op_3...)
     *   · lista   → quick replies (até 13 cabem os 10 op_N)
     *   · cta_url → card com botão de URL
     *   · template→ recusado com mensagem clara (não existe no IG)
     */
    private function despacharInstagram(array $contato, array $spec, ?string $tag): array
    {
        $conta = $this->contaIg($contato['ig_conta_id'] ?? null);
        if (!$conta) throw new ChatIgException('Instagram: nenhuma conta conectada', 0, null, null, true);

        $cli   = ChatInstagramClient::daConta($conta);
        $igsid = (string)$contato['wa_id'];
        $tipo  = (string)($spec['tipo'] ?? 'texto');

        switch ($tipo) {
            case 'texto':
                return $cli->enviarTexto($igsid, (string)$spec['texto'], $tag);

            case 'midia':
                // O IG chama de "file" o que o WhatsApp chama de "document"
                $t = (string)($spec['tipo_midia'] ?? 'image');
                if ($t === 'document') $t = 'file';
                $r = $cli->enviarMidia($igsid, $t, (string)$spec['origem'], $tag);
                // Legenda vira mensagem separada: o IG não tem caption em anexo
                if (!empty($spec['legenda'])) {
                    try { $cli->enviarTexto($igsid, (string)$spec['legenda'], $tag); } catch (Throwable $e) {}
                }
                return $r;

            case 'botoes':
                $ops = [];
                foreach ((array)($spec['botoes'] ?? []) as $i => $b) {
                    $ops[] = [
                        'titulo'  => (string)($b['titulo'] ?? ''),
                        'payload' => (string)($b['id'] ?? ('btn_' . ($i + 1))),
                    ];
                }
                return $cli->enviarRespostasRapidas($igsid, (string)$spec['corpo'], $ops, $tag);

            case 'lista':
                $ops = [];
                foreach ((array)($spec['secoes'] ?? []) as $s) {
                    foreach ((array)($s['linhas'] ?? []) as $l) {
                        $ops[] = [
                            'titulo'  => (string)($l['titulo'] ?? ''),
                            'payload' => (string)($l['id'] ?? ''),
                        ];
                    }
                }
                return $cli->enviarRespostasRapidas($igsid, (string)$spec['corpo'], $ops, $tag);

            case 'cta_url':
                return $cli->enviarCard(
                    $igsid,
                    (string)($spec['corpo'] ?: 'Confira'),
                    null,
                    $spec['imagem'] ?? null,
                    [['tipo' => 'url', 'titulo' => (string)($spec['texto_botao'] ?? 'Abrir'), 'url' => (string)$spec['url']]],
                    $tag
                );

            case 'template':
                throw new ChatIgException(
                    'Instagram não usa template HSM. Neste canal, use um bloco de texto — '
                    . 'a janela é de 24h e a tag de atendimento humano estende para 7 dias.',
                    0, null, null, true
                );

            case 'localizacao':
                throw new ChatIgException('Instagram não aceita envio de localização.', 0, null, null, true);
        }

        throw new InvalidArgumentException('ChatEnvio: tipo desconhecido para Instagram: ' . $tipo);
    }

    /** Traduz a spec no método correto do cliente Meta (WhatsApp). */
    private function despachar(string $waId, array $spec, ?string $respostaA): array
    {
        return match ((string)($spec['tipo'] ?? 'texto')) {
            'texto' => $this->meta->enviarTexto(
                $waId, (string)$spec['texto'],
                !isset($spec['preview_url']) || (bool)$spec['preview_url'],
                $respostaA
            ),
            'midia' => $this->meta->enviarMidia(
                $waId, (string)$spec['tipo_midia'], (string)$spec['origem'],
                $spec['legenda'] ?? null, $spec['nome_arquivo'] ?? null, $respostaA
            ),
            'botoes' => $this->meta->enviarBotoes(
                $waId, (string)$spec['corpo'], (array)$spec['botoes'],
                $spec['cabecalho'] ?? null, $spec['rodape'] ?? null, $respostaA
            ),
            'lista' => $this->meta->enviarLista(
                $waId, (string)$spec['corpo'], (string)($spec['texto_botao'] ?? 'Ver opções'),
                (array)$spec['secoes'], $spec['cabecalho'] ?? null, $spec['rodape'] ?? null, $respostaA
            ),
            'cta_url' => $this->meta->enviarBotaoUrl(
                $waId, (string)$spec['corpo'], (string)($spec['texto_botao'] ?? 'Abrir'),
                (string)$spec['url'], $spec['cabecalho'] ?? null, $spec['rodape'] ?? null
            ),
            'template' => $this->meta->enviarTemplate(
                $waId, (string)$spec['nome'], (string)($spec['idioma'] ?? 'pt_BR'),
                (array)($spec['componentes'] ?? [])
            ),
            'localizacao' => $this->meta->enviarLocalizacao(
                $waId, (float)$spec['lat'], (float)$spec['lng'],
                (string)($spec['nome'] ?? ''), (string)($spec['endereco'] ?? '')
            ),
            default => throw new InvalidArgumentException('ChatEnvio: tipo desconhecido: ' . ($spec['tipo'] ?? '?')),
        };
    }

    // =========================================================================
    // ENVIO POR TELEFONE (sem contato prévio)
    // =========================================================================

    /**
     * Envia para um número que talvez ainda não seja contato. Cria o cadastro.
     * Fora da janela isto só funciona com template — o que é o esperado, já que
     * um número novo nunca tem janela aberta.
     */
    public function enviarParaNumero(string $telefone, array $spec, array $opts = []): array
    {
        $waId = ChatMetaClient::normalizarNumero($telefone);
        if ($waId === '' || strlen($waId) < 12) {
            return $this->falha(self::MOTIVO_CONFIG, "telefone inválido: $telefone");
        }
        try {
            $contato = $this->contatos->garantir($waId, [
                'origem'     => $opts['origem'] ?? 'sistema',
                'nome'       => $opts['nome'] ?? null,
                'cliente_id' => $opts['cliente_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            return $this->falha(self::MOTIVO_CONFIG, $e->getMessage());
        }
        if (empty($contato['id'])) return $this->falha(self::MOTIVO_CONFIG, 'falha ao criar contato');

        return $this->enviar((int)$contato['id'], $spec, $opts);
    }

    // =========================================================================
    // ASSINATURA DO ATENDENTE
    // =========================================================================

    /**
     * Prefixo com o nome de quem está atendendo — '' quando desligado.
     *
     * Assina quem ESCREVEU, não quem está atribuído à conversa. Se a Maria
     * responde uma conversa do Robert, o cliente precisa ler "Maria": assinar
     * com o nome do responsável mandaria para o cliente um registro falso de
     * quem falou com ele.
     *
     * Template HSM fica de fora — o corpo é fixo na Meta e qualquer texto extra
     * seria recusado na aprovação.
     *
     * @param string $canal whatsapp|instagram — o IG não interpreta markdown,
     *                      então lá o negrito viraria asterisco literal na tela.
     */
    public function assinatura(int $usuarioId, string $canal = 'whatsapp', string $tipoSpec = 'texto'): string
    {
        if ($usuarioId < 1 || $tipoSpec === 'template') return '';
        // Ligada por padrão: saber com quem está falando é o comportamento
        // esperado do atendimento, não um extra que alguém precisa descobrir.
        if (!ChatConfig::bool('assinatura_agente', true)) return '';

        $nome = $this->nomeAgente($usuarioId);
        if ($nome === null || $nome === '') return '';

        $nome = self::recortarNome($nome, (string)ChatConfig::get('assinatura_nome', 'dois'));
        if ($nome === '') return '';

        // Sem {nome} o prefixo sairia igual em toda mensagem — volta ao padrão
        $formato = trim((string)ChatConfig::get('assinatura_formato', '*{nome}:*'));
        if ($formato === '' || !str_contains($formato, '{nome}')) $formato = '*{nome}:*';

        $prefixo = trim(str_replace('{nome}', $nome, $formato));

        if ($canal === 'instagram') $prefixo = trim(str_replace(['*', '_', '~'], '', $prefixo));

        return mb_substr($prefixo, 0, 120);
    }

    /** Nome cadastrado em usuarios, memoizado por request. */
    private function nomeAgente(int $usuarioId): ?string
    {
        if (array_key_exists($usuarioId, $this->nomesAgente)) return $this->nomesAgente[$usuarioId];

        try {
            $st = $this->db->prepare("SELECT nome FROM usuarios WHERE id = :id LIMIT 1");
            $st->execute([':id' => $usuarioId]);
            $n    = $st->fetchColumn();
            $nome = $n === false ? null : trim((string)$n);
        } catch (Throwable $e) {
            $nome = null;
        }
        return $this->nomesAgente[$usuarioId] = $nome;
    }

    /** primeiro | dois (padrão) | completo */
    private static function recortarNome(string $nome, string $modo): string
    {
        $partes = preg_split('/\s+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$partes) return '';

        return match ($modo) {
            'primeiro' => $partes[0],
            'completo' => implode(' ', $partes),
            default    => implode(' ', array_slice($partes, 0, 2)),
        };
    }

    /**
     * Prefixa o texto visível da mensagem.
     *
     * Mídia sem legenda fica de fora de propósito: uma foto cuja única legenda
     * é o nome do atendente informa pouco e, no Instagram, viraria uma segunda
     * mensagem contendo só o nome.
     */
    private static function aplicarAssinatura(array $spec, string $prefixo): array
    {
        $campo = match ((string)($spec['tipo'] ?? 'texto')) {
            'texto'                      => 'texto',
            'botoes', 'lista', 'cta_url' => 'corpo',
            'midia'                      => 'legenda',
            default                      => null,
        };
        if ($campo === null) return $spec;

        $atual = trim((string)($spec[$campo] ?? ''));
        if ($atual === '') return $spec;

        $spec[$campo]        = $prefixo . "\n" . $atual;
        $spec['_assinatura'] = $prefixo;
        return $spec;
    }

    // =========================================================================
    // INTERPOLAÇÃO E PERSISTÊNCIA
    // =========================================================================

    /** Aplica {{vars}} em todo texto visível da spec. */
    private function interpolarSpec(array $spec, array $vars): array
    {
        foreach (['texto', 'corpo', 'legenda', 'rodape', 'texto_botao', 'url', 'origem'] as $k) {
            if (isset($spec[$k]) && is_string($spec[$k])) {
                $spec[$k] = ChatContatoService::interpolar($spec[$k], $vars);
            }
        }
        if (isset($spec['cabecalho']['valor']) && is_string($spec['cabecalho']['valor'])) {
            $spec['cabecalho']['valor'] = ChatContatoService::interpolar($spec['cabecalho']['valor'], $vars);
        }
        foreach (($spec['botoes'] ?? []) as $i => $b) {
            if (isset($b['titulo'])) $spec['botoes'][$i]['titulo'] = ChatContatoService::interpolar((string)$b['titulo'], $vars);
        }
        foreach (($spec['secoes'] ?? []) as $si => $s) {
            foreach (($s['linhas'] ?? []) as $li => $l) {
                foreach (['titulo', 'descricao'] as $k) {
                    if (isset($l[$k])) {
                        $spec['secoes'][$si]['linhas'][$li][$k] =
                            ChatContatoService::interpolar((string)$l[$k], $vars);
                    }
                }
            }
        }
        return $spec;
    }

    private function tipoPersistido(array $spec): string
    {
        return match ((string)($spec['tipo'] ?? 'texto')) {
            'texto'       => 'text',
            'midia'       => (string)($spec['tipo_midia'] ?? 'document'),
            'botoes',
            'lista',
            'cta_url'     => 'interactive',
            'template'    => 'template',
            'localizacao' => 'location',
            default       => 'text',
        };
    }

    /** Texto que aparece na bolha do inbox. */
    private function textoPersistido(array $spec): ?string
    {
        return match ((string)($spec['tipo'] ?? 'texto')) {
            'texto'    => (string)($spec['texto'] ?? ''),
            'midia'    => $spec['legenda'] ?? null,
            'botoes',
            'lista',
            'cta_url'  => (string)($spec['corpo'] ?? ''),
            'template' => '[template] ' . (string)($spec['nome'] ?? ''),
            default    => null,
        };
    }

    /** Guarda a estrutura para a UI redesenhar botões/listas na thread. */
    private function payloadPersistido(array $spec): ?array
    {
        $tipo = (string)($spec['tipo'] ?? '');
        $p    = null;

        if (in_array($tipo, ['botoes', 'lista', 'cta_url'], true)) {
            $p = array_intersect_key($spec, array_flip([
                'tipo', 'botoes', 'secoes', 'texto_botao', 'url', 'cabecalho', 'rodape',
            ]));
        } elseif ($tipo === 'template') {
            $p = array_intersect_key($spec, array_flip(['tipo', 'nome', 'idioma', 'componentes']));
        }

        // O inbox usa isto para não repetir o nome do atendente acima da bolha:
        // ele já está dentro do texto, exatamente como o cliente recebeu.
        if (!empty($spec['_assinatura'])) {
            $p = ($p ?? []) + ['assinatura' => (string)$spec['_assinatura']];
        }

        return $p;
    }

    private function falha(string $motivo, string $erro): array
    {
        return ['ok' => false, 'mensagem_id' => null, 'wamid' => null, 'erro' => $erro, 'motivo' => $motivo];
    }

    private function logar(string $nivel, string $msg, array $ctx = []): void
    {
        if (!class_exists('LogService')) return;
        try { LogService::$nivel($msg, $ctx, 'chat'); } catch (Throwable $e) {}
    }
}
