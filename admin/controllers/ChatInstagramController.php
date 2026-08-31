<?php
/**
 * admin/controllers/ChatInstagramController.php
 *
 * Canal Instagram: contas conectadas, publicações e automação de comentários.
 *
 * Rotas:
 *   GET  /admin/chat/instagram                     → visão geral + contas
 *   POST /admin/chat/instagram/conectar            → descobre contas pelo token
 *   POST /admin/chat/instagram/{id}/assinar        → assina o webhook da página
 *   POST /admin/chat/instagram/{id}/sincronizar    → puxa posts e reels
 *   POST /admin/chat/instagram/{id}/ativo          → liga/desliga
 *   POST /admin/chat/instagram/{id}/desconectar
 *   GET  /admin/chat/instagram/comentarios         → log de comentários
 *   GET  /admin/chat/instagram/regras              → automação de comentário
 *   POST /admin/chat/instagram/regras/salvar
 *   POST /admin/chat/instagram/regras/simular
 *   POST /admin/chat/instagram/regras/{id}/ativo
 *   POST /admin/chat/instagram/regras/{id}/excluir
 *
 * Permissão: mexe em conta conectada e responde em público no perfil da
 * marca → super/gerente, igual ao resto da gestão do módulo.
 */
class ChatInstagramController extends Controller
{
    private ChatInstagramService $svc;

    public function __construct()
    {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->svc = new ChatInstagramService();
    }

    // =========================================================================
    // VISÃO GERAL
    // =========================================================================

    public function index(): void
    {
        $base = defined('BASE_URL') ? BASE_URL : '';

        $this->render('chat/instagram', [
            'titulo'     => 'Chat — Instagram',
            'contas'     => $this->svc->contas(),
            'kpis'       => $this->svc->kpis(),
            'regras'     => $this->svc->regras(),
            'webhookUrl' => $base . '/webhooks/whatsapp',
            'diagnostico'=> $this->diagnostico(),
        ], 'admin');
    }

    /**
     * Checa o que falta para o canal funcionar. Existe porque a causa mais
     * comum de "não funciona" aqui é escopo de token faltando — e isso é
     * invisível até alguém ir olhar.
     */
    private function diagnostico(): array
    {
        $token = (string)(getenv('META_ACCESS_TOKEN') ?: ($_ENV['META_ACCESS_TOKEN'] ?? ''));

        $d = [
            'token_definido'  => $token !== '',
            'token_valido'    => false,
            'token_expira'    => null,
            'token_tipo'      => null,
            'escopos'         => [],
            'faltando'        => [],
            'app_secret'      => ChatMetaClient::temAppSecret(),
            'contas'          => count($this->svc->contas(true)),
        ];
        if ($token === '') return $d;

        try {
            $url = 'https://graph.facebook.com/' . (getenv('META_API_VERSION') ?: 'v21.0')
                 . '/debug_token?input_token=' . urlencode($token) . '&access_token=' . urlencode($token);
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
            $r = json_decode((string)curl_exec($ch), true);
            curl_close($ch);

            $dd = $r['data'] ?? [];
            $d['token_valido'] = !empty($dd['is_valid']);
            $d['token_tipo']   = $dd['type'] ?? null;
            $d['escopos']      = $dd['scopes'] ?? [];
            $d['token_expira'] = empty($dd['expires_at'])
                ? 'nunca'
                : date('d/m/Y H:i', (int)$dd['expires_at']);

            // Escopos que o canal exige de fato
            $exigidos = [
                'instagram_basic'            => 'ver a conta do Instagram',
                'instagram_manage_messages'  => 'enviar e receber DM',
                'instagram_manage_comments'  => 'ler e responder comentários',
                'pages_show_list'            => 'descobrir a página vinculada',
                'pages_manage_metadata'      => 'assinar o webhook da página',
                'pages_messaging'            => 'entregar mensagens pela plataforma',
            ];
            foreach ($exigidos as $escopo => $paraQue) {
                if (!in_array($escopo, $d['escopos'], true)) {
                    $d['faltando'][$escopo] = $paraQue;
                }
            }
        } catch (Throwable $e) {
            $d['erro'] = $e->getMessage();
        }
        return $d;
    }

    // =========================================================================
    // CONTAS
    // =========================================================================

    public function conectar(): void
    {
        $this->verifyCsrf();
        $token = trim((string)($_POST['token'] ?? '')) ?: null;
        $this->json($this->svc->conectar($token));
    }

    public function assinar($id): void
    {
        $this->verifyCsrf();
        $this->json($this->svc->assinarWebhook(SecurityHelper::sanitizeInt($id)));
    }

    public function sincronizar($id): void
    {
        $this->verifyCsrf();
        $r = $this->svc->sincronizarMidias(SecurityHelper::sanitizeInt($id));
        $this->json($r['ok']
            ? ['ok' => true, 'msg' => "{$r['total']} publicação(ões) sincronizada(s)."]
            : ['ok' => false, 'erro' => $r['erro'] ?? 'Falha ao sincronizar.']);
    }

    public function alternarAtivo($id): void
    {
        $this->verifyCsrf();
        $this->svc->alternarAtivo(SecurityHelper::sanitizeInt($id));
        $this->json(['ok' => true]);
    }

    public function desconectar($id): void
    {
        $this->verifyCsrf();
        $this->svc->desconectar(SecurityHelper::sanitizeInt($id));
        $this->json(['ok' => true]);
    }

    public function testar($id): void
    {
        $this->verifyCsrf();
        $conta = $this->svc->conta(SecurityHelper::sanitizeInt($id));
        if (!$conta) { $this->json(['ok' => false, 'erro' => 'Conta não encontrada.']); return; }

        try {
            $this->json(['ok' => true, 'resultado' => ChatInstagramClient::daConta($conta)->testarConexao()]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // REGRAS DE COMENTÁRIO
    // =========================================================================

    public function regras(): void
    {
        $this->render('chat/instagram-regras', [
            'titulo' => 'Instagram — Automação de comentários',
            'regras' => $this->svc->regras(),
            'contas' => $this->svc->contas(true),
            'midias' => $this->svc->midias(null, 60),
            'fluxos' => (new ChatFluxoAdminService())->listarPublicados(),
            'tags'   => (new ChatContatoService())->listarTags(),
            'kpis'   => $this->svc->kpis(),
        ], 'admin');
    }

    public function salvarRegra(): void
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0) ?: null;

        $this->json($this->svc->salvarRegra([
            'conta_id'           => $_POST['conta_id']           ?? 0,
            'nome'               => $_POST['nome']               ?? '',
            'escopo'             => $_POST['escopo']             ?? 'todas',
            'midias'             => (array)($_POST['midias']     ?? []),
            'palavras'           => $_POST['palavras']           ?? '',
            'modo_match'         => $_POST['modo_match']         ?? 'contem',
            'ignorar_proprios'   => !empty($_POST['ignorar_proprios']),
            'ignorar_respostas'  => !empty($_POST['ignorar_respostas']),
            'responder_publico'  => !empty($_POST['responder_publico']),
            'resposta_publica'   => $_POST['resposta_publica']   ?? '',
            'enviar_dm'          => !empty($_POST['enviar_dm']),
            'mensagem_dm'        => $_POST['mensagem_dm']        ?? '',
            'fluxo_id'           => $_POST['fluxo_id']           ?? 0,
            'tag_id'             => $_POST['tag_id']             ?? 0,
            'prioridade'         => $_POST['prioridade']         ?? 50,
            'ativo'              => !empty($_POST['ativo']),
            'uma_vez_por_pessoa' => !empty($_POST['uma_vez_por_pessoa']),
        ], $id));
    }

    public function alternarRegra($id): void
    {
        $this->verifyCsrf();
        $this->svc->alternarRegra(SecurityHelper::sanitizeInt($id));
        $this->json(['ok' => true]);
    }

    public function excluirRegra($id): void
    {
        $this->verifyCsrf();
        $this->svc->excluirRegra(SecurityHelper::sanitizeInt($id));
        $this->json(['ok' => true]);
    }

    /** Testa uma frase contra as regras sem publicar nada. */
    public function simular(): void
    {
        $this->verifyCsrf();
        $texto = trim((string)($_POST['texto'] ?? ''));
        if ($texto === '') { $this->json(['ok' => false, 'erro' => 'Escreva um comentário para testar.']); return; }

        $this->json([
            'ok'        => true,
            'resultado' => $this->svc->simular($texto, (string)($_POST['media_id'] ?? '')),
        ]);
    }

    // =========================================================================
    // COMENTÁRIOS
    // =========================================================================

    public function comentarios(): void
    {
        $this->render('chat/instagram-comentarios', [
            'titulo'      => 'Instagram — Comentários',
            'comentarios' => $this->svc->comentariosRecentes([
                'regra_id' => (int)($_GET['regra'] ?? 0),
                'so_dm'    => !empty($_GET['so_dm']),
                'so_erro'  => !empty($_GET['so_erro']),
            ], 100),
            'regras' => $this->svc->regras(),
            'kpis'   => $this->svc->kpis(),
            'filtros' => [
                'regra'   => (int)($_GET['regra'] ?? 0),
                'so_dm'   => !empty($_GET['so_dm']),
                'so_erro' => !empty($_GET['so_erro']),
            ],
        ], 'admin');
    }
}
