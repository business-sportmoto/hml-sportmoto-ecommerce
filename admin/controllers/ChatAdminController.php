<?php
/**
 * admin/controllers/ChatAdminController.php
 *
 * Visão geral, configuração e templates do módulo Chat.
 *
 * Rotas:
 *   GET  /admin/chat                      → dashboard
 *   GET  /admin/chat/dados                → JSON dos gráficos (refresh sem reload)
 *   GET  /admin/chat/config               → configuração + saúde da integração
 *   POST /admin/chat/config/salvar        → grava a config
 *   POST /admin/chat/config/testar        → testa a conexão com a Meta
 *   GET  /admin/chat/templates            → templates HSM
 *   POST /admin/chat/templates/sincronizar→ puxa os templates da Meta
 *
 * Permissão: gestão do canal é decisão de negócio (custo por template,
 * reputação do número) → super/gerente, igual a promoções e cupons.
 */
class ChatAdminController extends Controller
{
    private ChatDashboardService $dash;

    public function __construct()
    {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->dash = new ChatDashboardService();
    }

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    public function index(): void
    {
        $dias = max(7, min(90, (int)($_GET['dias'] ?? 30)));

        $this->render('chat/dashboard', [
            'titulo'      => 'Chat — Visão geral',
            'dias'        => $dias,
            'kpis'        => $this->dash->kpis($dias),
            'serie'       => $this->dash->serie($dias),
            'serieContatos' => $this->dash->serieContatos($dias),
            'porHora'     => $this->dash->porHora(14),
            'topFluxos'   => $this->dash->topFluxos(6),
            'topGatilhos' => $this->dash->topGatilhos(6),
            'porTag'      => $this->dash->porTag(8),
            'tempoResposta' => $this->dash->tempoMedioResposta($dias),
            'falhas'      => $this->dash->ultimasFalhas(6),
            'saude'       => $this->dash->saude(),
            'porCanal'    => $this->dash->porCanal($dias),
            'instagram'   => $this->dash->instagram($dias),
            // Top 5 aqui; a lista completa com abas fica em Chat → Instagram
            'topSeguidores' => (new ChatInstagramService())->topSeguidores(min(30, $dias), 5),
        ], 'admin');
    }

    public function dados(): void
    {
        $dias = max(7, min(90, (int)($_GET['dias'] ?? 30)));
        $this->json([
            'ok'      => true,
            'kpis'    => $this->dash->kpis($dias),
            'serie'   => $this->dash->serie($dias),
            'contatos' => $this->dash->serieContatos($dias),
            'porHora' => $this->dash->porHora(14),
        ]);
    }

    // =========================================================================
    // CONFIGURAÇÃO
    // =========================================================================

    public function config(): void
    {
        $base = defined('BASE_URL') ? BASE_URL : '';

        $this->render('chat/config', [
            'titulo'      => 'Chat — Configuração',
            'config'      => ChatConfig::todos(),
            'saude'       => $this->dash->saude(),
            'webhookUrl'  => $base . '/webhooks/whatsapp',
            'verifyToken' => ChatMetaClient::verifyToken(),
            'temSecret'   => ChatMetaClient::temAppSecret(),
            'ultimosWebhooks' => $this->ultimosWebhooks(),
        ], 'admin');
    }

    public function configSalvar(): void
    {
        $this->verifyCsrf();

        // Whitelist: só estas chaves podem ser gravadas pela tela
        $booleanos = ['bot_ativo', 'quiet_hours_ativo', 'auto_marcar_lida',
                      'assinatura_obrigatoria', 'baixar_midia'];
        $inteiros  = ['quiet_hours_inicio', 'quiet_hours_fim', 'pausa_bot_minutos', 'janela_horas'];

        $pares = [];
        foreach ($booleanos as $c) $pares[$c] = !empty($_POST[$c]) ? '1' : '0';

        foreach ($inteiros as $c) {
            if (!isset($_POST[$c])) continue;
            $v = (int)$_POST[$c];
            $v = match ($c) {
                'quiet_hours_inicio', 'quiet_hours_fim' => max(0, min(23, $v)),
                'janela_horas'                          => max(1, min(24, $v)),
                'pausa_bot_minutos'                     => max(0, min(1440, $v)),
                default                                 => $v,
            };
            $pares[$c] = (string)$v;
        }

        // Quiet hours invertido silenciaria o envio o dia inteiro
        $ini = (int)($pares['quiet_hours_inicio'] ?? ChatConfig::int('quiet_hours_inicio', 8));
        $fim = (int)($pares['quiet_hours_fim']    ?? ChatConfig::int('quiet_hours_fim', 21));
        if ($ini >= $fim) {
            $this->json(['ok' => false, 'erro' => 'O horário inicial precisa ser menor que o final.']);
            return;
        }

        if (isset($_POST['optout_palavras'])) {
            $palavras = array_filter(array_map('trim', explode(',', (string)$_POST['optout_palavras'])));
            $pares['optout_palavras'] = mb_substr(implode(',', $palavras), 0, 1000);
        }

        ChatConfig::setVarios($pares);
        ChatConfig::limparCache();

        if (class_exists('LogService')) {
            try { LogService::audit('chat_config_alterada', ['chaves' => array_keys($pares)]); }
            catch (Throwable $e) {}
        }

        $this->json(['ok' => true, 'msg' => 'Configuração salva.']);
    }

    public function configTestar(): void
    {
        $this->verifyCsrf();
        try {
            $this->json(['ok' => true, 'resultado' => (new ChatMetaClient())->testarConexao()]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    private function ultimosWebhooks(int $limite = 15): array
    {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->query(
                "SELECT id, evento, wamid, assinatura_ok, processado, erro, criado_em
                 FROM chat_webhook_log ORDER BY id DESC LIMIT " . (int)$limite
            );
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    // =========================================================================
    // TEMPLATES
    // =========================================================================

    public function templates(): void
    {
        $svc = new ChatTemplateService();
        $this->render('chat/templates', [
            'titulo'    => 'Chat — Templates',
            'templates' => $svc->listar(),
        ], 'admin');
    }

    public function templatesSincronizar(): void
    {
        $this->verifyCsrf();
        $r = (new ChatTemplateService())->sincronizar();
        $this->json($r['ok']
            ? ['ok' => true, 'msg' => "{$r['total']} template(s) sincronizado(s), {$r['novos']} novo(s)."]
            : ['ok' => false, 'erro' => $r['erro'] ?? 'Falha ao sincronizar.']
        );
    }
}
