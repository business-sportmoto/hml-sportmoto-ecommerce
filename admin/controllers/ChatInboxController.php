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
            'midia_tamanho' => $m['midia_tamanho'] ?? null,
            'payload'     => $m['payload'],
            'status'      => $m['status'],
            'erro'        => $m['erro_detalhe'],
            'origem'      => $m['origem'],
            'autor'       => $m['autor_nome'] ?? null,
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
        if (!is_uploaded_file($f['tmp_name'])) {
            $this->json(['ok' => false, 'erro' => 'Upload inválido.']); return;
        }

        $canal   = (string)($cv['canal'] ?? 'whatsapp');
        $legenda = trim((string)($_POST['legenda'] ?? '')) ?: null;
        $tamanho = (int)($f['size'] ?? 0);

        // O FORMATO vem da extensão. mime_content_type() erra demais para isso:
        // mp3 com tag ID3 vira application/octet-stream, m4a vira audio/x-m4a.
        // Usá-lo como whitelist é o que impedia mandar música.
        $ext     = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $arquivo = (string)$f['tmp_name'];
        $veioDeUpload = true;

        // Gravação de voz do navegador: o Chrome só empacota Opus em WebM, e a
        // Meta quer Opus em OGG. Trocar o recipiente é `-c:a copy` — não
        // recodifica, não perde qualidade, leva milissegundos. Sem ffmpeg no
        // servidor a gravação ainda sai, como arquivo para baixar.
        if (!empty($_POST['gravacao']) && in_array($ext, ['weba', 'webm'], true)) {
            $ogg = self::remuxarOgg($arquivo);
            if ($ogg !== null) {
                $arquivo = $ogg; $ext = 'ogg';
                $veioDeUpload = false;
                $tamanho = (int)filesize($ogg);
            } else {
                $ext = 'weba';   // segue como arquivo
            }
        }

        [$tipoWa, $mimeBase, $limiteMb] = self::classificarAnexo($ext, $canal);

        if ($tipoWa === null) {
            $this->json(['ok' => false,
                'erro' => $ext === '' ? 'Arquivo sem extensão.' : "Arquivos .$ext não são aceitos."]);
            return;
        }

        // O sniff decide SEGURANÇA, não formato. O arquivo fica público em
        // uploads/chat/: HTML ali é XSS no nosso domínio, executável é malware
        // com a nossa marca. Renomear para .pdf não engana esta checagem.
        $sniff = function_exists('mime_content_type')
            ? strtolower(trim(explode(';', (string)mime_content_type($arquivo))[0]))
            : '';
        if ($sniff !== '' && in_array($sniff, self::MIME_PERIGOSO, true)) {
            $this->json(['ok' => false,
                'erro' => "O conteúdo do arquivo não confere com .$ext ($sniff). Envio bloqueado."]);
            return;
        }

        // Figurinha tem duas restrições que documento não tem: 100 KB e nenhuma
        // legenda. Nos dois casos o mesmo .webp vale mais como arquivo — chega
        // inteiro e com o texto do atendente junto.
        if ($tipoWa === 'sticker' && ($tamanho > 100 * 1024 || $legenda !== null)) {
            $tipoWa = 'document';
            $limiteMb = 100;
        }

        // Limite por tipo, não um teto único: a Meta recusa imagem acima de 5 MB
        // e aceita documento até 100 MB. Barrar aqui dá mensagem clara; deixar
        // passar dá erro genérico da API depois do upload.
        if ($tamanho > $limiteMb * 1024 * 1024) {
            $rotulo = ['image' => 'Imagem', 'video' => 'Vídeo', 'audio' => 'Áudio',
                       'sticker' => 'Figurinha'][$tipoWa] ?? 'Arquivo';
            $this->json(['ok' => false,
                'erro' => "{$rotulo} pode ter no máximo " . rtrim(rtrim(number_format($limiteMb, 1, ',', ''), '0'), ',') . " MB."]);
            return;
        }

        $rel = 'uploads/chat/' . date('Y/m');
        $dir = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/' . $rel;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->json(['ok' => false, 'erro' => 'Não foi possível criar a pasta de upload.']); return;
        }

        // Nome aleatório: o nome original do usuário nunca vira caminho no disco
        // move_uploaded_file() só aceita o arquivo que veio no POST; o remuxado
        // é nosso, então vai de rename().
        $nomeArquivo = bin2hex(random_bytes(16)) . '.' . $ext;
        $destino     = $dir . '/' . $nomeArquivo;
        $moveu = $veioDeUpload ? move_uploaded_file($arquivo, $destino) : @rename($arquivo, $destino);
        if (!$moveu) {
            if (!$veioDeUpload) @unlink($arquivo);
            $this->json(['ok' => false, 'erro' => 'Falha ao gravar o arquivo.']); return;
        }

        $url = (defined('BASE_URL') ? BASE_URL : '') . '/' . $rel . '/' . $nomeArquivo;

        $opts = [
            'origem'           => 'inbox',
            'autor_usuario_id' => AuthHelper::usuarioId(),
            'pausar_bot'       => true,
            'legenda'          => $legenda,
            // Sempre, não só em documento: o ChatMetaClient já ignora o nome
            // fora de documento, e sem ele o histórico mostra um áudio anônimo
            // em vez de "entrevista-cliente.mp3".
            'nome_arquivo'     => (string)$f['name'],
            'mime'             => $mimeBase,
            'tamanho'          => $tamanho,
        ];

        $r = $this->envio->midia((int)$cv['contato_id'], $tipoWa, $url, $opts);

        // A tabela de tipos é a melhor aposta, não uma promessa: a Meta muda o
        // que aceita sem avisar. Se ela recusou a forma escolhida, reenvia como
        // arquivo — que ela sempre aceita — e apaga a linha falhada, senão a
        // thread mostra duas bolhas do mesmo anexo, uma vermelha.
        if (!$r['ok'] && $r['motivo'] === ChatEnvioService::MOTIVO_API && $tipoWa !== 'document') {
            $this->mensagens->descartarFalha((int)($r['mensagem_id'] ?? 0));
            $r = $this->envio->midia((int)$cv['contato_id'], 'document', $url, $opts);
        }

        if (!$r['ok']) {
            $this->json(['ok' => false, 'erro' => $r['erro'], 'motivo' => $r['motivo'],
                         'fora_janela' => ($r['motivo'] === ChatEnvioService::MOTIVO_FORA_JANELA)]);
            return;
        }

        $msg = $r['mensagem_id'] ? $this->mensagens->obter((int)$r['mensagem_id']) : null;
        $this->json(['ok' => true, 'mensagem' => $msg ? $this->resumoMensagem($msg) : null]);
    }

    /**
     * Extensão → MIME que vamos declarar. É a EXTENSÃO que manda, não o sniff.
     *
     * `mime_content_type()` erra demais para servir de whitelist:
     *   .mp3 com tag ID3 → application/octet-stream   (é a maioria dos mp3)
     *   .m4a             → audio/x-m4a  (apelido, não o nome oficial)
     *   .ogg             → às vezes text/plain
     * Com o sniff como whitelist, mandar música simplesmente não funcionava.
     *
     * O sniff continua sendo usado — mas só como GUARDA, em MIME_PERIGOSO:
     * decide se o conteúdo é perigoso, não qual é o formato.
     */
    private const ANEXO_POR_EXT = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif',  'webp' => 'image/webp',

        'mp4' => 'video/mp4',  '3gp' => 'video/3gpp', 'mov'  => 'video/quicktime',
        'avi' => 'video/x-msvideo', 'webm' => 'video/webm', 'm4v' => 'video/mp4',

        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg',  'oga'  => 'audio/ogg',
        'm4a' => 'audio/mp4',  'aac' => 'audio/aac',  'amr'  => 'audio/amr',
        'wav' => 'audio/wav',  'flac'=> 'audio/flac', 'opus' => 'audio/ogg',
        'weba'=> 'audio/webm',   // gravação do navegador (Opus dentro de WebM)

        'pdf' => 'application/pdf', 'zip' => 'application/zip',
        'txt' => 'text/plain',      'csv' => 'text/csv',
        'doc'  => 'application/msword',
        'xls'  => 'application/vnd.ms-excel',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    /**
     * Conteúdo que nunca sobe, qualquer que seja a extensão.
     *
     * O arquivo fica público em uploads/chat/. Um HTML ali é XSS hospedado no
     * nosso domínio; um executável é distribuição de malware com a nossa marca.
     * Renomear para .pdf não ajuda: quem decide aqui é o sniff do conteúdo.
     */
    private const MIME_PERIGOSO = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'application/x-msdownload', 'application/x-dosexec', 'application/vnd.microsoft.portable-executable',
        'application/x-executable', 'application/x-sharedlib', 'application/x-mach-binary',
        'application/x-php', 'text/x-php', 'application/x-httpd-php',
        'application/javascript', 'text/javascript',
        'application/x-msi', 'application/x-bat', 'application/x-sh', 'text/x-shellscript',
    ];

    /**
     * A melhor forma de enviar este arquivo NESTE canal.
     *
     * As duas plataformas aceitam conjuntos diferentes, e a mesma extensão
     * chega de jeito diferente em cada uma:
     *
     *   .webp → figurinha no WhatsApp · arquivo no Instagram
     *   .gif  → arquivo no WhatsApp   · imagem animada no Instagram
     *   .mov  → arquivo no WhatsApp   · vídeo no Instagram
     *   .wav  → arquivo no WhatsApp   · áudio no Instagram
     *
     * O que a plataforma não reproduz nunca é recusado: cai em documento
     * (WhatsApp) ou file (Instagram), e o cliente baixa. E se mesmo assim a API
     * recusar a forma escolhida, o upload() reenvia como arquivo — a tabela
     * aqui é a melhor aposta, não uma promessa.
     *
     * @param  string $ext extensão SEM ponto, minúscula ("mp3", "jpg")
     * @return array{0:?string,1:string,2:float} [tipo, mime declarado, limite em MB]
     */
    private static function classificarAnexo(string $ext, string $canal = 'whatsapp'): array
    {
        $mime = self::ANEXO_POR_EXT[strtolower(ltrim($ext, '.'))] ?? null;
        if ($mime === null) return [null, '', 0];

        if ($canal === 'instagram') {
            // Limites do Instagram: imagem 8 MB, o resto 25 MB
            $tipo = match (true) {
                in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true) => 'image',
                in_array($mime, ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm'], true) => 'video',
                str_starts_with($mime, 'audio/') && $mime !== 'audio/amr' => 'audio',
                default => 'document',
            };
            return [$tipo, $mime, $tipo === 'image' ? 8 : 25];
        }

        // WhatsApp: a Meta valida o MIME por tipo, então a lista é fechada
        return match (true) {
            in_array($mime, ['image/jpeg', 'image/png'], true)          => ['image',   $mime,   5],
            $mime === 'image/webp'                                      => ['sticker', $mime, 0.1],
            in_array($mime, ['video/mp4', 'video/3gpp'], true)          => ['video',   $mime,  16],
            in_array($mime, ['audio/aac', 'audio/amr', 'audio/mpeg',
                             'audio/mp4', 'audio/ogg'], true)           => ['audio',   $mime,  16],
            // audio/webm a Meta não toca — cai em documento logo abaixo
            default                                                     => ['document', $mime, 100],
        };
    }

    /**
     * Troca o recipiente de WebM para OGG mantendo o Opus intacto.
     *
     * `-c:a copy` é remux, não recodificação: o fluxo de áudio é o mesmo byte a
     * byte. Serve para a Meta, que aceita Opus mas só dentro de OGG.
     *
     * Devolve o caminho do arquivo novo, ou null se não houver ffmpeg — nesse
     * caso quem chama manda a gravação como arquivo, que sempre funciona.
     */
    private static function remuxarOgg(string $origem): ?string
    {
        if (!function_exists('exec')) return null;

        $bin = getenv('FFMPEG_BIN') ?: 'ffmpeg';
        $saida = sys_get_temp_dir() . '/chat-' . bin2hex(random_bytes(8)) . '.ogg';

        $cmd = escapeshellarg($bin) . ' -hide_banner -loglevel error -y'
             . ' -i ' . escapeshellarg($origem)
             . ' -vn -c:a copy -f ogg ' . escapeshellarg($saida) . ' 2>&1';

        $linhas = []; $codigo = 1;
        try { @exec($cmd, $linhas, $codigo); } catch (Throwable $e) { return null; }

        if ($codigo !== 0 || !is_file($saida) || filesize($saida) < 100) {
            @unlink($saida);
            if (class_exists('LogService')) {
                try {
                    LogService::warning('chat: remux para ogg falhou', [
                        'codigo' => $codigo, 'saida' => implode(' | ', array_slice($linhas, 0, 3)),
                    ], 'chat');
                } catch (Throwable $e) {}
            }
            return null;
        }
        return $saida;
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

        // O service filtra o caso "peguei para mim" — quem clicou não precisa
        // ser avisado do próprio clique.
        (new ChatNotificacaoService())->atribuida($id, $agente, AuthHelper::usuarioId());

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
