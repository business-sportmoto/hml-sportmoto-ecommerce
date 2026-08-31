<?php
/**
 * admin/controllers/ChatInboxController.php
 *
 * Live Chat — a caixa de entrada de atendimento.
 *
 * Rotas:
 *   GET  /admin/chat/inbox                    → tela
 *   GET  /admin/chat/inbox/conversas          → lista (JSON, polling)
 *   GET  /admin/chat/inbox/{id}/thread        → mensagens da conversa
 *   GET  /admin/chat/inbox/{id}/novas         → só o que chegou depois de {desde}
 *   POST /admin/chat/inbox/{id}/enviar        → envia mensagem
 *   POST /admin/chat/inbox/{id}/template      → envia template (fora da janela)
 *   POST /admin/chat/inbox/{id}/status        → aberta/pendente/resolvida
 *   POST /admin/chat/inbox/{id}/atribuir      → define o agente
 *   POST /admin/chat/inbox/{id}/lida          → zera não-lidas
 *   POST /admin/chat/inbox/{id}/bot           → pausa/retoma a automação
 *   POST /admin/chat/inbox/{id}/upload        → anexo
 *   POST /admin/chat/inbox/{id}/iniciar-fluxo → dispara fluxo manualmente
 *
 * Permissão: atender é operacional → vendedor entra junto com super/gerente.
 */
class ChatInboxController extends Controller
{
    private ChatConversaService $conversas;
    private ChatMensagemService $mensagens;
    private ChatContatoService  $contatos;
    private ChatEnvioService    $envio;

    public function __construct()
    {
        AuthHelper::requireAdminLevel('super', 'gerente', 'vendedor');

        $this->conversas = new ChatConversaService();
        $this->mensagens = new ChatMensagemService();
        $this->contatos  = new ChatContatoService();
        $this->envio     = new ChatEnvioService();
    }

    // =========================================================================
    // TELA
    // =========================================================================

    public function index(): void
    {
        $this->render('chat/inbox', [
            'titulo'      => 'Chat — Atendimento',
            'contadores'  => $this->conversas->contadores(AuthHelper::usuarioId()),
            'agentes'     => $this->conversas->agentesDisponiveis(),
            'tags'        => $this->contatos->listarTags(),
            'templates'   => (new ChatTemplateService())->aprovados(),
            'fluxos'      => (new ChatFluxoAdminService())->listarPublicados(),
            'meuId'       => AuthHelper::usuarioId(),
            'abrirConversa' => (int)($_GET['conversa'] ?? 0),
            // Permite cair aqui já filtrado, vindo dos atalhos do dashboard
            'canalInicial'  => in_array($_GET['canal'] ?? '', ['whatsapp', 'instagram'], true)
                ? (string)$_GET['canal'] : '',
            'envioOk'     => $this->envio->disponivel(),
            'envioErro'   => $this->envio->erroConfig(),
            // Como as mensagens deste agente vão sair assinadas ('' = sem assinatura)
            'assinatura'  => $this->envio->assinatura(AuthHelper::usuarioId()),
        ], 'admin');
    }

    // =========================================================================
    // LISTAGEM
    // =========================================================================

    public function conversas(): void
    {
        $f = [
            'status'    => (string)($_GET['status'] ?? ''),
            'agente_id' => $_GET['agente'] ?? '',
            'busca'     => (string)($_GET['q'] ?? ''),
            'nao_lidas' => !empty($_GET['nao_lidas']),
            'janela'    => (string)($_GET['janela'] ?? ''),
            'canal'     => (string)($_GET['canal'] ?? ''),
            'tags'      => array_filter(array_map('intval', (array)($_GET['tags'] ?? []))),
        ];
        if ($f['agente_id'] === 'eu') $f['agente_id'] = AuthHelper::usuarioId();

        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $r = $this->conversas->listarInbox($f, $pagina, 25);

        $this->json([
            'ok'         => true,
            'itens'      => array_map([$this, 'resumoConversa'], $r['itens']),
            'total'      => $r['total'],
            'pagina'     => $pagina,
            'contadores' => $this->conversas->contadores(AuthHelper::usuarioId()),
        ]);
    }

    /** Achata a conversa no shape que o JS do inbox espera. */
    private function resumoConversa(array $c): array
    {
        return [
            'id'          => (int)$c['id'],
            'contato_id'  => (int)$c['contato_id'],
            'nome'        => $c['nome_exibicao'],
            'canal'       => $c['canal'] ?? 'whatsapp',
            'canal_rotulo'=> $c['canal_rotulo'] ?? 'WhatsApp',
            // No Instagram é @handle; no WhatsApp, o telefone formatado
            'telefone'    => $c['identificador'] ?? ($c['telefone_exibicao'] ?: $c['wa_id']),
            // IG: fora das 24h ainda dá para responder por 7 dias com a tag humana
            'pode_texto'  => (bool)($c['pode_texto_livre'] ?? $c['na_janela']),
            'janela_humana' => (bool)($c['ig_janela_humana'] ?? false),
            'status'      => $c['status'],
            'nao_lidas'   => (int)$c['nao_lidas'],
            'preview'     => (string)($c['ultima_preview'] ?? ''),
            'direcao'     => $c['ultima_direcao'],
            'quando'      => $this->humanizar($c['ultima_mensagem_em'] ?? null),
            'agente'      => $c['agente_nome'],
            'agente_id'   => $c['atribuido_a'] !== null ? (int)$c['atribuido_a'] : null,
            'na_janela'   => (bool)$c['na_janela'],
            'janela_restante' => $c['janela_restante'],
            'bot_ativo'   => (bool)$c['bot_ativo'],
            'cliente_id'  => $c['cliente_id'] !== null ? (int)$c['cliente_id'] : null,
            'tags'        => $c['tags'] ?? [],
        ];
    }

    // =========================================================================
    // THREAD
    // =========================================================================

    public function thread($id): void
    {
        $id = SecurityHelper::sanitizeInt($id);
        $cv = $this->conversas->obter($id);
        if (!$cv) { $this->json(['ok' => false, 'erro' => 'Conversa não encontrada.'], 404); return; }

        $antesDe = (int)($_GET['antes_de'] ?? 0);
        $msgs    = $this->mensagens->thread($id, 50, $antesDe);

        // Abrir a conversa zera o não-lidas — é o gesto de "eu li"
        if ($antesDe === 0) $this->conversas->marcarLida($id);

        $contato = $this->contatos->obter((int)$cv['contato_id']);

        $this->json([
            'ok'        => true,
            'conversa'  => $this->resumoConversa($cv),
            'contato'   => $contato ? $this->resumoContato($contato) : null,
            'mensagens' => array_map([$this, 'resumoMensagem'], $msgs),
            'tem_mais'  => count($msgs) === 50,
            'notas'     => $antesDe === 0 ? $this->contatos->notas((int)$cv['contato_id'], 20) : [],
        ]);
    }

    /** Polling incremental: só o que é novo desde o último id visto. */
    public function novas($id): void
    {
        $id = SecurityHelper::sanitizeInt($id);
        $cv = $this->conversas->obter($id);
        if (!$cv) { $this->json(['ok' => false, 'erro' => 'não encontrada'], 404); return; }

        $desdeId = (int)($_GET['desde'] ?? 0);
        $novas   = $this->mensagens->novasDesde($id, $desdeId);

        // Status muda sem mensagem nova (entregue → lido); a UI precisa saber
        $desdeTs = (string)($_GET['desde_ts'] ?? date('Y-m-d H:i:s', time() - 120));
        $status  = $this->mensagens->statusAtualizados($id, $desdeTs);

        if ($novas) $this->conversas->marcarLida($id);

        $this->json([
            'ok'        => true,
            'mensagens' => array_map([$this, 'resumoMensagem'], $novas),
            'status'    => $status,
            'conversa'  => $this->resumoConversa($cv),
            'agora'     => date('Y-m-d H:i:s'),
        ]);
    }

    private function resumoMensagem(array $m): array
    {
        return [
            'id'          => (int)$m['id'],
            'direcao'     => $m['direcao'],
            'tipo'        => $m['tipo'],
            'texto'       => $m['texto'],
            'midia_url'   => $m['midia_url'],
            'midia_mime'  => $m['midia_mime'],
            'midia_nome'  => $m['midia_nome'],
            'payload'     => $m['payload'],
            'status'      => $m['status'],
            'erro'        => $m['erro_detalhe'],
            'origem'      => $m['origem'],
            'autor'       => $m['autor_nome'],
            'hora'        => $m['hora'],
            'dia'         => $m['dia'],
            'criado_em'   => $m['criado_em'],
        ];
    }

    private function resumoContato(array $c): array
    {
        return [
            'id'         => (int)$c['id'],
            'nome'       => $c['nome_exibicao'],
            'wa_id'      => $c['wa_id'],
            'telefone'   => $c['telefone_exibicao'],
            'email'      => $c['email'],
            'cliente_id' => $c['cliente_id'] !== null ? (int)$c['cliente_id'] : null,
            'optin'      => (int)$c['optin'],
            'bloqueado'  => (int)$c['bloqueado'],
            'na_janela'  => (bool)$c['na_janela'],
            'campos'     => $c['campos'],
            'tags'       => $c['tags'],
            'criado_em'  => $c['criado_em'],
            'total_entrada' => (int)$c['total_entrada'],
            'total_saida'   => (int)$c['total_saida'],
        ];
    }

    // =========================================================================
    // ENVIO
    // =========================================================================

    public function enviar($id): void
    {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($id);

        $cv = $this->conversas->obter($id);
        if (!$cv) { $this->json(['ok' => false, 'erro' => 'Conversa não encontrada.'], 404); return; }

        $texto = trim((string)($_POST['texto'] ?? ''));
        if ($texto === '') { $this->json(['ok' => false, 'erro' => 'Escreva uma mensagem.']); return; }

        // O prefixo da assinatura entra no mesmo corpo e conta para o limite da
        // Meta — validar só o que foi digitado deixaria a mensagem estourar lá.
        $assinatura = $this->envio->assinatura(
            AuthHelper::usuarioId(), (string)($cv['canal'] ?? 'whatsapp')
        );
        $limite = 4096 - ($assinatura !== '' ? mb_strlen($assinatura) + 1 : 0);
        if (mb_strlen($texto) > $limite) {
            $this->json(['ok' => false, 'erro' => "A mensagem passa de {$limite} caracteres."]); return;
        }

        $r = $this->envio->texto((int)$cv['contato_id'], $texto, [
            'origem'           => 'inbox',
            'autor_usuario_id' => AuthHelper::usuarioId(),
            'pausar_bot'       => true,   // humano assumiu: o bot cala
            'responder_a'      => trim((string)($_POST['responder_a'] ?? '')) ?: null,
            // Interpolação NÃO se aplica: o agente digitou o texto literal
            'vars'             => [],
        ]);

        if (!$r['ok']) {
            $this->json([
                'ok'     => false,
                'erro'   => $r['erro'],
                'motivo' => $r['motivo'],
                // A UI usa isto para oferecer o envio por template
                'fora_janela' => ($r['motivo'] === ChatEnvioService::MOTIVO_FORA_JANELA),
            ]);
            return;
        }

        $msg = $r['mensagem_id'] ? $this->mensagens->obter((int)$r['mensagem_id']) : null;
        $this->json(['ok' => true, 'mensagem' => $msg ? $this->resumoMensagem($msg) : null]);
    }

    /** Fora da janela de 24h só template passa — este é o caminho. */
    public function enviarTemplate($id): void
    {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($id);

        $cv = $this->conversas->obter($id);
        if (!$cv) { $this->json(['ok' => false, 'erro' => 'Conversa não encontrada.'], 404); return; }

        $nome   = trim((string)($_POST['template'] ?? ''));
        $idioma = trim((string)($_POST['idioma'] ?? 'pt_BR'));
        if ($nome === '') { $this->json(['ok' => false, 'erro' => 'Selecione um template.']); return; }

        $tplSvc = new ChatTemplateService();
        $tpl    = $tplSvc->obter($nome, $idioma);
        if (!$tpl) { $this->json(['ok' => false, 'erro' => 'Template não encontrado.']); return; }
        if ($tpl['status'] !== 'APPROVED') {
            $this->json(['ok' => false, 'erro' => "Template não aprovado (status: {$tpl['status']})."]); return;
        }

        $body = array_values(array_filter((array)($_POST['vars'] ?? []), fn($v) => trim((string)$v) !== ''));
        if (count($body) < (int)$tpl['vars_body']) {
            $this->json(['ok' => false, 'erro' => "Este template precisa de {$tpl['vars_body']} variável(is)."]);
            return;
        }

        $contato = $this->contatos->obter((int)$cv['contato_id']);
        $vars    = $contato ? $this->contatos->variaveis($contato) : [];

        $componentes = $tplSvc->montarComponentes([
            'body'   => $body,
            'header' => (string)($_POST['var_header'] ?? ''),
            'botao'  => (string)($_POST['var_botao'] ?? ''),
        ], $vars);

        $r = $this->envio->template((int)$cv['contato_id'], $nome, $idioma, $componentes, [
            'origem'           => 'inbox',
            'autor_usuario_id' => AuthHelper::usuarioId(),
            'pausar_bot'       => true,
        ]);

        if (!$r['ok']) { $this->json(['ok' => false, 'erro' => $r['erro'], 'motivo' => $r['motivo']]); return; }

        $msg = $r['mensagem_id'] ? $this->mensagens->obter((int)$r['mensagem_id']) : null;
        $this->json(['ok' => true, 'mensagem' => $msg ? $this->resumoMensagem($msg) : null]);
    }

    /**
     * Anexo. O arquivo vai para uploads/chat e é enviado por URL pública —
     * a Meta busca o arquivo, então ele precisa estar acessível externamente.
     */
    public function upload($id): void
    {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($id);

        $cv = $this->conversas->obter($id);
        if (!$cv) { $this->json(['ok' => false, 'erro' => 'Conversa não encontrada.'], 404); return; }

        $f = $_FILES['arquivo'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->json(['ok' => false, 'erro' => 'Falha no upload.']); return;
        }
        if (($f['size'] ?? 0) > 16 * 1024 * 1024) {
            $this->json(['ok' => false, 'erro' => 'Arquivo maior que 16 MB.']); return;
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            $this->json(['ok' => false, 'erro' => 'Upload inválido.']); return;
        }

        // MIME real do conteúdo, nunca o que o browser declarou
        $mime = function_exists('mime_content_type')
            ? (mime_content_type($f['tmp_name']) ?: '')
            : (string)($f['type'] ?? '');

        $permitidos = [
            'image/jpeg' => ['image', '.jpg'], 'image/png'  => ['image', '.png'],
            'image/webp' => ['image', '.webp'],
            'video/mp4'  => ['video', '.mp4'], 'video/3gpp' => ['video', '.3gp'],
            'audio/mpeg' => ['audio', '.mp3'], 'audio/ogg'  => ['audio', '.ogg'],
            'audio/mp4'  => ['audio', '.m4a'], 'audio/aac'  => ['audio', '.aac'],
            'application/pdf' => ['document', '.pdf'],
            'application/msword' => ['document', '.doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['document', '.docx'],
            'application/vnd.ms-excel' => ['document', '.xls'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['document', '.xlsx'],
            'text/plain' => ['document', '.txt'],
        ];
        $mimeBase = strtolower(trim(explode(';', $mime)[0]));
        if (!isset($permitidos[$mimeBase])) {
            $this->json(['ok' => false, 'erro' => "Tipo de arquivo não permitido ($mimeBase)."]); return;
        }
        [$tipoWa, $ext] = $permitidos[$mimeBase];

        $rel = 'uploads/chat/' . date('Y/m');
        $dir = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/' . $rel;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->json(['ok' => false, 'erro' => 'Não foi possível criar a pasta de upload.']); return;
        }

        // Nome aleatório: o nome original do usuário nunca vira caminho no disco
        $nomeArquivo = bin2hex(random_bytes(16)) . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nomeArquivo)) {
            $this->json(['ok' => false, 'erro' => 'Falha ao gravar o arquivo.']); return;
        }

        $url = (defined('BASE_URL') ? BASE_URL : '') . '/' . $rel . '/' . $nomeArquivo;

        $r = $this->envio->midia((int)$cv['contato_id'], $tipoWa, $url, [
            'origem'           => 'inbox',
            'autor_usuario_id' => AuthHelper::usuarioId(),
            'pausar_bot'       => true,
            'legenda'          => trim((string)($_POST['legenda'] ?? '')) ?: null,
            'nome_arquivo'     => $tipoWa === 'document' ? (string)$f['name'] : null,
        ]);

        if (!$r['ok']) {
            $this->json(['ok' => false, 'erro' => $r['erro'], 'motivo' => $r['motivo'],
                         'fora_janela' => ($r['motivo'] === ChatEnvioService::MOTIVO_FORA_JANELA)]);
            return;
        }

        $msg = $r['mensagem_id'] ? $this->mensagens->obter((int)$r['mensagem_id']) : null;
        $this->json(['ok' => true, 'mensagem' => $msg ? $this->resumoMensagem($msg) : null]);
    }

    // =========================================================================
    // ESTADO DA CONVERSA
    // =========================================================================

    public function status($id): void
    {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($id);
        $ok = $this->conversas->mudarStatus($id, (string)($_POST['status'] ?? ''), AuthHelper::usuarioId());
        $this->json(['ok' => $ok, 'contadores' => $this->conversas->contadores(AuthHelper::usuarioId())]);
    }

    public function atribuir($id): void
    {
        $this->verifyCsrf();
        $id     = SecurityHelper::sanitizeInt($id);
        $agente = (int)($_POST['agente_id'] ?? 0);

        // "Pegar para mim" não exige escolher a si próprio na lista
        if (($_POST['agente_id'] ?? '') === 'eu') $agente = AuthHelper::usuarioId();

        $this->conversas->atribuir($id, $agente ?: null);
        $this->json(['ok' => true, 'contadores' => $this->conversas->contadores(AuthHelper::usuarioId())]);
    }

    public function marcarLida($id): void
    {
        $this->verifyCsrf();
        $this->conversas->marcarLida(SecurityHelper::sanitizeInt($id));
        $this->json(['ok' => true]);
    }

    public function bot($id): void
    {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($id);

        if ((string)($_POST['acao'] ?? '') === 'retomar') {
            $this->conversas->retomarBot($id);
            $this->json(['ok' => true, 'bot_ativo' => true]);
            return;
        }

        $minutos = isset($_POST['minutos']) ? (int)$_POST['minutos'] : null;
        $this->conversas->pausarBot($id, $minutos);
        $this->json(['ok' => true, 'bot_ativo' => false]);
    }

    /** Dispara um fluxo manualmente nesta conversa. */
    public function iniciarFluxo($id): void
    {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($id);

        $cv = $this->conversas->obter($id);
        if (!$cv) { $this->json(['ok' => false, 'erro' => 'Conversa não encontrada.'], 404); return; }

        $fluxoId = (int)($_POST['fluxo_id'] ?? 0);
        if ($fluxoId < 1) { $this->json(['ok' => false, 'erro' => 'Selecione um fluxo.']); return; }

        // O bot precisa estar liberado para o fluxo poder falar
        $this->conversas->retomarBot($id);

        $sessaoId = (new ChatFluxoMotor())->iniciar(
            $fluxoId, (int)$cv['contato_id'],
            ['_origem' => 'inbox', '_agente' => AuthHelper::usuarioId()],
            $id
        );

        $this->json($sessaoId
            ? ['ok' => true, 'sessao_id' => $sessaoId]
            : ['ok' => false, 'erro' => 'O fluxo não iniciou. Verifique se está publicado e se a regra de reentrada permite.']
        );
    }

    // =========================================================================
    // NOTAS
    // =========================================================================

    public function adicionarNota($id): void
    {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($id);

        $cv = $this->conversas->obter($id);
        if (!$cv) { $this->json(['ok' => false, 'erro' => 'Conversa não encontrada.'], 404); return; }

        $nota = trim((string)($_POST['nota'] ?? ''));
        if ($nota === '') { $this->json(['ok' => false, 'erro' => 'Nota vazia.']); return; }

        $this->contatos->adicionarNota((int)$cv['contato_id'], $nota, AuthHelper::usuarioId());
        $this->json(['ok' => true, 'notas' => $this->contatos->notas((int)$cv['contato_id'], 20)]);
    }

    // =========================================================================
    // AUXILIAR
    // =========================================================================

    private function humanizar(?string $dt): string
    {
        if (!$dt) return '';
        $ts   = strtotime($dt);
        $diff = time() - $ts;

        if ($diff < 60)     return 'agora';
        if ($diff < 3600)   return intdiv($diff, 60) . ' min';
        if ($diff < 86400)  return intdiv($diff, 3600) . 'h';
        if ($diff < 172800) return 'ontem';
        if ($diff < 604800) return intdiv($diff, 86400) . 'd';
        return date('d/m/Y', $ts);
    }
}
