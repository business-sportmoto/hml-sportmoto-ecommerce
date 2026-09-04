<?php
/**
 * app/services/ChatWebhookService.php
 *
 * Processa o payload de entrada da Meta. É o ponto onde a conversa real toca
 * o sistema, então ele é escrito para ser paranoico:
 *
 *   · SEMPRE devolve 200 ao chamador. A Meta reenvia em qualquer resposta que
 *     não seja 2xx, e um retry-storm sobre um bug nosso vira mensagem duplicada
 *     no WhatsApp do cliente. Erro interno vira log, não status HTTP.
 *   · Dedup por wamid no INSERT (UNIQUE), não por consulta prévia — só o banco
 *     resolve a corrida entre dois webhooks simultâneos da mesma mensagem.
 *   · A assinatura é conferida ANTES de qualquer escrita.
 *
 * FORMATO (WhatsApp Cloud API v21):
 *   entry[].changes[].value.messages[]  → mensagens recebidas
 *   entry[].changes[].value.statuses[]  → sent/delivered/read/failed
 *   entry[].changes[].value.contacts[]  → profile.name do remetente
 */
class ChatWebhookService
{
    /**
     * Dias que o log de chamadas guarda; o worker apaga o que passar disso.
     *
     * É constante porque a TELA precisa dizer o período. Sem isso, filtrar uma
     * data de três semanas atrás devolve vazio e parece defeito do filtro.
     */
    public const RETENCAO_DIAS = 15;

    private PDO $db;
    private ChatContatoService  $contatos;
    private ChatConversaService $conversas;
    private ChatMensagemService $mensagens;
    private ChatGatilhoService  $gatilhos;
    private ChatNotificacaoService $notif;

    public function __construct(?PDO $db = null)
    {
        $this->db        = $db ?? Database::getInstance()->getConnection();
        $this->contatos  = new ChatContatoService($this->db);
        $this->conversas = new ChatConversaService($this->db);
        $this->mensagens = new ChatMensagemService($this->db);
        $this->gatilhos  = new ChatGatilhoService($this->db);
        $this->notif     = new ChatNotificacaoService($this->db);
    }

    // =========================================================================
    // ENTRADA
    // =========================================================================

    /**
     * @return array{ok:bool, processadas:int, detalhe:string}
     */
    public function processar(string $corpoBruto, ?string $assinatura, ?string $ip = null): array
    {
        $segredo      = ChatMetaClient::qualSegredoAssinou($corpoBruto, $assinatura);
        $assinaturaOk = $segredo !== null;
        $exigir       = ChatConfig::bool('assinatura_obrigatoria', true);

        // Sem app secret configurado a validação nunca passa. Recusar é o
        // default seguro: um webhook aberto deixa qualquer um criar contato e
        // disparar fluxo em nome de um número arbitrário.
        if (!$assinaturaOk && $exigir) {
            $this->logWebhook('recusado', null, $corpoBruto, false,
                $this->motivoDaRecusa($corpoBruto), $ip);
            return ['ok' => false, 'processadas' => 0, 'detalhe' => 'assinatura inválida'];
        }

        $payload = json_decode($corpoBruto, true);
        if (!is_array($payload)) {
            $this->logWebhook('invalido', null, $corpoBruto, $assinaturaOk, 'JSON inválido', $ip);
            return ['ok' => false, 'processadas' => 0, 'detalhe' => 'JSON inválido'];
        }

        // O mesmo endereço recebe os dois canais; `object` diz qual é.
        if (($payload['object'] ?? '') === 'instagram') {
            return $this->processarInstagram($payload, $assinaturaOk, $ip);
        }

        $n = 0;
        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $valor = $change['value'] ?? [];
                $campo = (string)($change['field'] ?? '');

                if ($campo !== '' && $campo !== 'messages') {
                    // template_status_update, account_update, phone_number_quality...
                    $this->logWebhook($campo, null, json_encode($valor), $assinaturaOk, null, $ip);
                    $this->tratarEventoDeConta($campo, $valor);
                    continue;
                }

                $perfis = $this->indexarContatos($valor['contacts'] ?? []);

                foreach (($valor['messages'] ?? []) as $msg) {
                    try {
                        if ($this->processarMensagem($msg, $perfis, $assinaturaOk, $ip)) $n++;
                    } catch (Throwable $e) {
                        $this->logErro('processarMensagem', $e);
                        $this->logWebhook('messages', $msg['id'] ?? null, json_encode($msg),
                                          $assinaturaOk, mb_substr($e->getMessage(), 0, 400), $ip);
                    }
                }

                foreach (($valor['statuses'] ?? []) as $st) {
                    try {
                        $this->processarStatus($st);
                    } catch (Throwable $e) {
                        $this->logErro('processarStatus', $e);
                    }
                }
            }
        }

        return ['ok' => true, 'processadas' => $n, 'detalhe' => "$n mensagem(ns)"];
    }

    // =========================================================================
    // INSTAGRAM
    // =========================================================================

    /**
     * Payload do Instagram. Estrutura diferente do WhatsApp:
     *   entry[].messaging[]  → DMs, cliques em botão, reações, leitura
     *   entry[].changes[]    → comentários (field = comments | live_comments)
     */
    private function processarInstagram(array $payload, bool $assinaturaOk, ?string $ip): array
    {
        if (!ChatConfig::bool('ig_ativo', true)) {
            return ['ok' => true, 'processadas' => 0, 'detalhe' => 'canal Instagram desligado'];
        }

        $ig = new ChatInstagramService($this->db);
        $n  = 0;

        foreach (($payload['entry'] ?? []) as $entry) {
            $igUserId = (string)($entry['id'] ?? '');

            // ── Comentários ──
            foreach (($entry['changes'] ?? []) as $change) {
                $campo = (string)($change['field'] ?? '');
                if ($campo !== 'comments' && $campo !== 'live_comments') {
                    $this->logWebhook('ig_' . $campo, null, json_encode($change), $assinaturaOk, null, $ip);
                    continue;
                }
                try {
                    // `live_comments` roteia para as automações de Live; misturar
                    // com `comments` faria a régua de live responder post comum
                    $r = $ig->processarComentario((array)($change['value'] ?? []), $igUserId, $campo);
                    $this->logWebhook(
                        'ig_comentario',
                        (string)($change['value']['id'] ?? ''),
                        json_encode($change['value'] ?? []),
                        $assinaturaOk,
                        $r['ok'] ? null : $r['motivo'],
                        $ip,
                        $r['ok']
                    );
                    if ($r['ok']) $n++;
                } catch (Throwable $e) {
                    $this->logErro('processarComentario', $e);
                    $this->logWebhook('ig_comentario', null, json_encode($change),
                                      $assinaturaOk, mb_substr($e->getMessage(), 0, 400), $ip);
                }
            }

            // ── Mensagens diretas ──
            foreach (($entry['messaging'] ?? []) as $evt) {
                try {
                    if ($this->processarDmInstagram($evt, $igUserId, $ig, $assinaturaOk, $ip)) $n++;
                } catch (Throwable $e) {
                    $this->logErro('processarDmInstagram', $e);
                }
            }
        }

        return ['ok' => true, 'processadas' => $n, 'detalhe' => "$n evento(s) do Instagram"];
    }

    private function processarDmInstagram(
        array $evt, string $igUserId, ChatInstagramService $ig, bool $assinaturaOk, ?string $ip
    ): bool {
        $remetente = (string)($evt['sender']['id'] ?? '');
        if ($remetente === '') return false;

        // A Meta ecoa de volta o que a própria conta enviou. Processar isso
        // duplicaria a mensagem na thread e — pior — dispararia gatilho em cima
        // da nossa própria fala.
        if (!empty($evt['message']['is_echo'])) return false;
        if ($remetente === $igUserId) return false;

        // Eventos sem conteúdo de conversa
        if (isset($evt['read']) || isset($evt['delivery'])) return false;

        $conta = $ig->contaPorIgUserId($igUserId);

        // ── Extrai o conteúdo ──
        $texto = ''; $tipo = 'text'; $mid = ''; $botaoId = null; $botaoTitulo = null;
        $midiaUrl = null; $midiaTipo = null;

        if (isset($evt['message'])) {
            $m     = $evt['message'];
            $mid   = (string)($m['mid'] ?? '');
            $texto = (string)($m['text'] ?? '');

            // Clique em resposta rápida: o payload É a porta do fluxo
            if (!empty($m['quick_reply']['payload'])) {
                $botaoId     = (string)$m['quick_reply']['payload'];
                $botaoTitulo = $texto;
            }

            foreach (($m['attachments'] ?? []) as $a) {
                $t = (string)($a['type'] ?? '');
                if ($t === 'story_mention') {
                    $tipo  = 'story_mention';
                    $texto = $texto ?: '[mencionou a conta num story]';
                } elseif ($t === 'share') {
                    $tipo  = 'share';
                    $texto = $texto ?: '[compartilhou uma publicação]';
                } elseif (in_array($t, ['image', 'video', 'audio', 'file'], true)) {
                    $tipo      = $t === 'file' ? 'document' : $t;
                    $midiaTipo = $t;
                }
                $midiaUrl = $a['payload']['url'] ?? $midiaUrl;
                break;
            }

            // Resposta a um story da conta
            if (!empty($m['reply_to']['story'])) {
                $tipo = 'story_reply';
            }
        } elseif (isset($evt['postback'])) {
            $mid         = (string)($evt['postback']['mid'] ?? '');
            $botaoId     = (string)($evt['postback']['payload'] ?? '');
            $botaoTitulo = (string)($evt['postback']['title'] ?? '');
            $texto       = $botaoTitulo;
            $tipo        = 'interactive';
        } elseif (isset($evt['reaction'])) {
            $tipo  = 'reaction';
            $texto = (string)($evt['reaction']['emoji'] ?? '');
            $mid   = (string)($evt['reaction']['mid'] ?? '') . ':reaction';
        } else {
            return false;
        }

        // ── Contato + conversa ──
        $contato = $ig->registrarEntradaDm($remetente, '', $conta);
        if (empty($contato['id'])) return false;

        $conversa = $this->conversas->obterPorContato((int)$contato['id'], 'instagram')
                 ?: $this->conversas->garantir((int)$contato['id'], 'instagram');

        // ── Persiste (dedup pelo mid) ──
        $msgId = $this->mensagens->gravar([
            'conversa_id' => (int)$conversa['id'],
            'contato_id'  => (int)$contato['id'],
            'direcao'     => 'entrada',
            'tipo'        => $tipo,
            'texto'       => $texto,
            'midia_url'   => $midiaUrl,
            'payload'     => $evt,
            'wamid'       => $mid ?: null,
            'status'      => 'recebido',
            'origem'      => 'instagram',
            'criado_em'   => !empty($evt['timestamp'])
                ? date('Y-m-d H:i:s', (int)($evt['timestamp'] / 1000))
                : date('Y-m-d H:i:s'),
        ]);
        if ($msgId === null) return false;   // duplicata

        $this->logWebhook('ig_dm', $mid ?: null, json_encode($evt), $assinaturaOk, null, $ip, true);

        // Perfil só na primeira mensagem — economiza chamada de API
        if ((int)($contato['total_entrada'] ?? 0) <= 1 && $conta) {
            $ig->enriquecerPerfil((int)$contato['id'], $remetente, $conta);
        }

        if (ChatConfig::bool('auto_marcar_lida', true) && $conta) {
            try { ChatInstagramClient::daConta($conta)->acaoRemetente($remetente, 'mark_seen'); }
            catch (Throwable $e) {}
        }

        // ── "Já segui!" da receita de crescimento ──
        // Tratado antes do roteamento normal: é resposta a uma pergunta que a
        // própria automação fez, não uma mensagem nova para os gatilhos.
        if ($botaoId && str_starts_with($botaoId, 'ig_segui_')) {
            if ($ig->tratarConfirmacaoSeguidor($botaoId, (int)$contato['id'], $remetente, $conta)) {
                return true;
            }
        }

        // ── Resposta a story: régua própria, antes dos gatilhos gerais ──
        // Chega pelo canal de DM (não por `changes`), então é aqui que a
        // automação de FAQ de stories precisa ser consultada.
        if ($tipo === 'story_reply' && $texto !== '') {
            if ($ig->processarRespostaStory((int)$contato['id'], $texto, $conta)) {
                return true;
            }
        }

        // ── Roteamento: idêntico ao WhatsApp ──
        $botRespondeu = $this->rotear($contato, $conversa, [
            'texto'      => $texto,
            'tipo'       => $tipo,
            'referencia' => '',
            'botao_id'   => $botaoId,
            'titulo'     => $botaoTitulo,
            'wamid'      => $mid,
        ]);

        $this->notif->entrada(
            (int)$conversa['id'], $contato,
            $texto ?: $this->rotuloTipo($tipo), $botRespondeu
        );

        return true;
    }

    // =========================================================================
    // MENSAGEM RECEBIDA (WhatsApp)
    // =========================================================================

    private function processarMensagem(array $msg, array $perfis, bool $assinaturaOk, ?string $ip): bool
    {
        $wamid = (string)($msg['id'] ?? '');
        $waId  = (string)($msg['from'] ?? '');
        if ($wamid === '' || $waId === '') return false;

        $tipo      = (string)($msg['type'] ?? 'text');
        $extraido  = $this->extrairConteudo($msg, $tipo);
        $nomePerfil = $perfis[$waId] ?? '';

        // ── Contato + conversa ──
        $contato = $this->contatos->registrarEntrada($waId, [
            'nome_perfil' => $nomePerfil,
            'origem'      => 'whatsapp',
            'origem_ref'  => $extraido['referencia'] ?: null,
        ]);
        $conversa = $this->conversas->obterPorContato((int)$contato['id'])
                 ?: $this->conversas->garantir((int)$contato['id']);

        // ── Persiste (dedup no UNIQUE de wamid) ──
        $msgId = $this->mensagens->gravar([
            'conversa_id'  => (int)$conversa['id'],
            'contato_id'   => (int)$contato['id'],
            'direcao'      => 'entrada',
            'tipo'         => $tipo,
            'texto'        => $extraido['texto'],
            'midia_id'     => $extraido['midia_id'],
            'midia_mime'   => $extraido['midia_mime'],
            'midia_nome'   => $extraido['midia_nome'],
            'payload'      => $msg,
            'wamid'        => $wamid,
            'resposta_a'   => $msg['context']['id'] ?? null,
            'status'       => 'recebido',
            'origem'       => 'whatsapp',
            'criado_em'    => !empty($msg['timestamp'])
                ? date('Y-m-d H:i:s', (int)$msg['timestamp'])
                : date('Y-m-d H:i:s'),
        ]);

        // Duplicata: a Meta reenviou algo que já processamos. Parar aqui é o
        // ponto inteiro do dedup — seguir dispararia o fluxo de novo.
        if ($msgId === null) return false;

        $this->logWebhook('messages', $wamid, json_encode($msg), $assinaturaOk, null, $ip, true);

        // ── Efeitos colaterais leves ──
        if (ChatConfig::bool('auto_marcar_lida', true)) {
            try { (new ChatMetaClient())->marcarLida($wamid); } catch (Throwable $e) {}
        }
        if ($extraido['midia_id'] && ChatConfig::bool('baixar_midia', true)) {
            $this->baixarMidia($msgId, $extraido['midia_id'], $extraido['midia_mime']);
        }

        // ── Roteamento ──
        $botRespondeu = $this->rotear($contato, $conversa, [
            'texto'      => $extraido['texto'],
            'tipo'       => $tipo,
            'referencia' => $extraido['referencia'],
            'botao_id'   => $extraido['botao_id'],
            'titulo'     => $extraido['botao_titulo'],
            'wamid'      => $wamid,
        ]);

        // ── Sino ──
        // Depois do roteamento porque o aviso depende de o robô ter respondido
        // ou não. Nunca antes: avisaria gente para uma conversa que a automação
        // resolve sozinha em seguida.
        $this->notif->entrada(
            (int)$conversa['id'], $contato,
            $extraido['texto'] ?: $this->rotuloTipo($tipo), $botRespondeu
        );

        return true;
    }

    /** Mensagem sem texto (foto, áudio) precisa de algo legível na notificação. */
    private function rotuloTipo(string $tipo): string
    {
        return [
            'image'    => '📷 Enviou uma imagem',
            'video'    => '🎥 Enviou um vídeo',
            'audio'    => '🎤 Enviou um áudio',
            'voice'    => '🎤 Enviou um áudio',
            'document' => '📎 Enviou um documento',
            'sticker'  => 'Enviou uma figurinha',
            'location' => '📍 Enviou uma localização',
            'contacts' => 'Enviou um contato',
            'share'    => 'Compartilhou uma publicação',
            'story_reply' => 'Respondeu ao seu story',
        ][$tipo] ?? 'Nova mensagem';
    }

    /**
     * Decide quem responde: sessão em andamento, gatilho, ou ninguém.
     *
     * @return bool a automação assumiu a resposta. Quem chama usa isto para
     *              decidir se ainda vale avisar um humano — 'tag' e 'humano'
     *              devolvem false de propósito: a primeira não responde nada e
     *              a segunda existe justamente para chamar gente.
     */
    private function rotear(array $contato, array $conversa, array $entrada): bool
    {
        // 1. Bot desligado globalmente → só inbox
        if (!ChatConfig::bool('bot_ativo', true)) return false;

        // 2. Humano assumiu esta conversa → o bot cala
        $cvAtual = $this->conversas->obter((int)$conversa['id']) ?: $conversa;
        if ($this->conversas->botPausado($cvAtual)) return false;

        $motor     = new ChatFluxoMotor($this->db);
        $contatoId = (int)$contato['id'];

        // 3. Sessão esperando resposta → entrega e continua a jornada
        if ($motor->temSessaoAguardando($contatoId)) {
            $resposta = [
                'tipo'   => $entrada['botao_id'] ? 'botao' : 'texto',
                'texto'  => (string)$entrada['texto'],
                'id'     => $entrada['botao_id'],
                'titulo' => $entrada['titulo'],
            ];

            // Opt-out vale mesmo no meio de um fluxo — é pedido explícito
            if ($entrada['texto'] && $this->gatilhos->ehPalavraDeOptOut((string)$entrada['texto'])) {
                $this->gatilhos->executar(
                    ['acao' => 'optout', 'gatilho' => null], $contato, $entrada
                );
                return true;
            }

            if ($motor->entregarResposta($contatoId, $resposta)) return true;
        }

        // 4. Gatilhos
        $decisao = $this->gatilhos->resolver($contato, $entrada);

        // so_fora_fluxo: não interrompe jornada em andamento
        if ($decisao['gatilho']
            && (int)($decisao['gatilho']['so_fora_fluxo'] ?? 1) === 1
            && $motor->temSessaoAtiva($contatoId)) {
            return true;
        }

        if ($decisao['acao'] !== 'nenhuma') {
            $this->gatilhos->executar($decisao, $contato, $entrada);
            return in_array($decisao['acao'], ['fluxo', 'mensagem', 'optout'], true);
        }

        return false;
    }

    // =========================================================================
    // EXTRAÇÃO DE CONTEÚDO
    // =========================================================================

    /**
     * Achata a variedade de formatos da Meta num shape único.
     * @return array{texto:string, midia_id:?string, midia_mime:?string,
     *               midia_nome:?string, referencia:string, botao_id:?string,
     *               botao_titulo:?string}
     */
    private function extrairConteudo(array $msg, string $tipo): array
    {
        $out = [
            'texto' => '', 'midia_id' => null, 'midia_mime' => null, 'midia_nome' => null,
            'referencia' => '', 'botao_id' => null, 'botao_titulo' => null,
        ];

        switch ($tipo) {
            case 'text':
                $out['texto'] = (string)($msg['text']['body'] ?? '');
                break;

            case 'image': case 'video': case 'audio': case 'document': case 'sticker':
                $m = $msg[$tipo] ?? [];
                $out['midia_id']   = isset($m['id']) ? (string)$m['id'] : null;
                $out['midia_mime'] = $m['mime_type'] ?? null;
                $out['midia_nome'] = $m['filename'] ?? null;
                $out['texto']      = (string)($m['caption'] ?? '');
                break;

            case 'interactive':
                $i = $msg['interactive'] ?? [];
                $sub = (string)($i['type'] ?? '');
                if ($sub === 'button_reply') {
                    $out['botao_id']     = (string)($i['button_reply']['id'] ?? '');
                    $out['botao_titulo'] = (string)($i['button_reply']['title'] ?? '');
                    $out['texto']        = $out['botao_titulo'];
                } elseif ($sub === 'list_reply') {
                    $out['botao_id']     = (string)($i['list_reply']['id'] ?? '');
                    $out['botao_titulo'] = (string)($i['list_reply']['title'] ?? '');
                    $out['texto']        = $out['botao_titulo'];
                } elseif ($sub === 'nfm_reply') {
                    $out['texto'] = (string)($i['nfm_reply']['response_json'] ?? '');
                }
                break;

            case 'button':
                // Botão de template HSM (quick reply) — formato diferente do interactive
                $out['botao_titulo'] = (string)($msg['button']['text'] ?? '');
                $out['botao_id']     = (string)($msg['button']['payload'] ?? '');
                $out['texto']        = $out['botao_titulo'];
                break;

            case 'location':
                $l = $msg['location'] ?? [];
                $out['texto'] = trim(($l['name'] ?? '') . ' ' . ($l['address'] ?? ''))
                             ?: sprintf('%s, %s', $l['latitude'] ?? '', $l['longitude'] ?? '');
                break;

            case 'contacts':
                $nomes = [];
                foreach (($msg['contacts'] ?? []) as $c) {
                    $nomes[] = (string)($c['name']['formatted_name'] ?? '');
                }
                $out['texto'] = implode(', ', array_filter($nomes));
                break;

            case 'reaction':
                $out['texto'] = (string)($msg['reaction']['emoji'] ?? '');
                break;

            case 'order':
                $out['texto'] = 'Pedido do catálogo';
                break;

            default:
                $out['texto'] = '';
        }

        // Referência: link wa.me com texto pré-definido ou anúncio Click-to-WhatsApp
        if (!empty($msg['referral'])) {
            $out['referencia'] = (string)(
                $msg['referral']['source_id']
                ?? $msg['referral']['headline']
                ?? $msg['referral']['source_type']
                ?? ''
            );
        }
        // Formato "#codigo" no começo da mensagem também vale como referência
        if ($out['referencia'] === '' && preg_match('/^\s*#([a-z0-9_-]{2,60})\b/i', $out['texto'], $mm)) {
            $out['referencia'] = $mm[1];
        }

        return $out;
    }

    // =========================================================================
    // STATUS
    // =========================================================================

    private function processarStatus(array $st): void
    {
        $wamid  = (string)($st['id'] ?? '');
        $status = (string)($st['status'] ?? '');
        if ($wamid === '' || $status === '') return;

        $erro = null;
        if (!empty($st['errors'][0])) {
            $e = $st['errors'][0];
            $erro = [
                'code'  => $e['code'] ?? null,
                'title' => $e['title'] ?? ($e['message'] ?? ''),
            ];
        }

        $this->mensagens->atualizarStatusPorWamid($wamid, $status, $erro);

        // Falha por número inexistente marca o contato — insistir queima
        // reputação do número na Meta.
        if ($status === 'failed' && !empty($erro['code']) && (int)$erro['code'] === 131026) {
            try {
                $this->db->prepare(
                    "UPDATE chat_contatos c
                     JOIN chat_mensagens m ON m.contato_id = c.id
                     SET c.bloqueado = 1
                     WHERE m.wamid = :w"
                )->execute([':w' => $wamid]);
            } catch (Throwable $e) {}
        }
    }

    /** Eventos de conta: mudança de status de template, qualidade do número. */
    private function tratarEventoDeConta(string $campo, array $valor): void
    {
        try {
            if ($campo === 'message_template_status_update') {
                $nome   = (string)($valor['message_template_name'] ?? '');
                $status = (string)($valor['event'] ?? '');
                if ($nome !== '' && $status !== '') {
                    $this->db->prepare(
                        "UPDATE chat_templates SET status = :s WHERE nome = :n"
                    )->execute([':s' => $status, ':n' => $nome]);
                }
            }
        } catch (Throwable $e) {}
    }

    // =========================================================================
    // MÍDIA
    // =========================================================================

    /**
     * Baixa a mídia recebida. A URL da Meta expira em ~5 min, então guardar só
     * o media_id significa perder o anexo — precisa baixar na hora.
     */
    private function baixarMidia(int $mensagemId, string $mediaId, ?string $mime): void
    {
        try {
            $cliente = new ChatMetaClient();
            $r = $cliente->baixarMidia($mediaId);

            $limite = 25 * 1024 * 1024;
            if ($r['tamanho'] > $limite) return;

            $ext  = $this->extensaoDe($r['mime'] ?: (string)$mime);
            $dir  = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/uploads/chat/' . date('Y/m');
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;

            $nome    = bin2hex(random_bytes(16)) . $ext;
            $caminho = $dir . '/' . $nome;
            if (@file_put_contents($caminho, $r['conteudo']) === false) return;

            $url = (defined('BASE_URL') ? BASE_URL : '') . '/uploads/chat/' . date('Y/m') . '/' . $nome;

            $this->db->prepare(
                "UPDATE chat_mensagens
                 SET midia_url = :u, midia_mime = :m, midia_tamanho = :t
                 WHERE id = :id"
            )->execute([
                ':u'  => $url,
                ':m'  => $r['mime'],
                ':t'  => $r['tamanho'],
                ':id' => $mensagemId,
            ]);
        } catch (Throwable $e) {
            $this->logErro('baixarMidia', $e);
        }
    }

    /** Extensão a partir do MIME. Whitelist — nada de confiar no filename. */
    private function extensaoDe(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        return match ($mime) {
            'image/jpeg', 'image/jpg' => '.jpg',
            'image/png'               => '.png',
            'image/webp'              => '.webp',
            'image/gif'               => '.gif',
            'video/mp4'               => '.mp4',
            'video/3gpp'              => '.3gp',
            'audio/ogg'               => '.ogg',
            'audio/mpeg'              => '.mp3',
            'audio/mp4', 'audio/aac'  => '.m4a',
            'audio/amr'               => '.amr',
            'application/pdf'         => '.pdf',
            'text/plain'              => '.txt',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'      => '.xlsx',
            default                   => '.bin',
        };
    }

    // =========================================================================
    // AUXILIARES
    // =========================================================================

    /** wa_id → profile.name */
    private function indexarContatos(array $contacts): array
    {
        $out = [];
        foreach ($contacts as $c) {
            $wa = (string)($c['wa_id'] ?? '');
            if ($wa !== '') $out[$wa] = (string)($c['profile']['name'] ?? '');
        }
        return $out;
    }

    /**
     * Por que a assinatura não bateu — em palavras que apontam a correção.
     *
     * O `object` do payload diz o canal antes de qualquer validação. Usá-lo
     * aqui é seguro: ele não decide nada, só escolhe a explicação. A recusa em
     * si já aconteceu.
     *
     * O caso que mais custa tempo: o Instagram configurado por "Casos de uso"
     * assina com a chave do PRÓPRIO produto, não com a do app. Quem só tem
     * `META_APP_SECRET` vê o WhatsApp entrar e o Instagram ser descartado sem
     * nenhuma pista.
     */
    private function motivoDaRecusa(string $corpoBruto): string
    {
        if (!ChatMetaClient::temAppSecret()) {
            return 'META_APP_SECRET não configurado';
        }

        $payload = json_decode($corpoBruto, true);
        $ehIg    = is_array($payload) && ($payload['object'] ?? '') === 'instagram';

        if ($ehIg && !ChatMetaClient::temAppSecretIg()) {
            return 'assinatura inválida — esta chamada é do Instagram, e o Instagram '
                 . 'configurado por "Casos de uso" assina com a chave própria dele. '
                 . 'Configure META_APP_SECRET_IG.';
        }

        return $ehIg
            ? 'assinatura inválida — confira META_APP_SECRET_IG (chave do produto Instagram)'
            : 'assinatura inválida — confira META_APP_SECRET (app → Básico)';
    }

    private function logWebhook(
        ?string $evento, ?string $wamid, ?string $payload,
        bool $assinaturaOk, ?string $erro = null, ?string $ip = null, bool $processado = false
    ): void {
        try {
            $this->db->prepare(
                "INSERT INTO chat_webhook_log
                    (evento, wamid, payload_json, assinatura_ok, processado, erro, ip)
                 VALUES (:e, :w, :p, :a, :pr, :er, :ip)"
            )->execute([
                ':e'  => $evento ? mb_substr($evento, 0, 40) : null,
                ':w'  => $wamid ? mb_substr($wamid, 0, 160) : null,
                ':p'  => $payload !== null ? mb_substr($payload, 0, 60000) : null,
                ':a'  => (int)$assinaturaOk,
                ':pr' => (int)$processado,
                ':er' => $erro ? mb_substr($erro, 0, 400) : null,
                ':ip' => $ip ? mb_substr($ip, 0, 45) : null,
            ]);
        } catch (Throwable $e) {}
    }

    private function logErro(string $onde, Throwable $e): void
    {
        if (!class_exists('LogService')) return;
        try { LogService::error("ChatWebhook::$onde: " . $e->getMessage(), [], 'chat'); }
        catch (Throwable $x) {}
    }

    // =========================================================================
    // MANUTENÇÃO
    // =========================================================================

    /** Limpa log antigo — o payload cru é volumoso e só serve para depurar. */
    public function limparLogAntigo(int $dias = self::RETENCAO_DIAS): int
    {
        try {
            $st = $this->db->prepare(
                "DELETE FROM chat_webhook_log WHERE criado_em < DATE_SUB(NOW(), INTERVAL :d DAY)"
            );
            $st->bindValue(':d', max(1, $dias), PDO::PARAM_INT);
            $st->execute();
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
