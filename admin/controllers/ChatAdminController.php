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
        $base   = defined('BASE_URL') ? BASE_URL : '';
        $logSvc = new ChatWebhookLogService();

        $this->render('chat/config', [
            'titulo'      => 'Chat — Configuração',
            'config'      => ChatConfig::todos(),
            'saude'       => $this->dash->saude(),
            'webhookUrl'  => $base . '/webhooks/whatsapp',
            'verifyToken' => ChatMetaClient::verifyToken(),
            'temSecret'   => ChatMetaClient::temAppSecret(),
            // A tabela é pintada pelo JS inclusive na primeira carga: a
            // página 1 vem resolvida daqui. Dois templates — um em PHP e
            // outro em JS — divergem no primeiro ajuste.
            'logInicial'  => $logSvc->listar([], 1),
            'logResumo'   => $logSvc->resumo([]),
            'logEventos'  => $logSvc->eventos(),
            'logRetencao' => ChatWebhookService::RETENCAO_DIAS,
            // Prévia da assinatura com um nome real, não um exemplo genérico
            'meuNome'     => (string)(AuthHelper::adminDisplay()['nome'] ?? 'Maria Souza'),
        ], 'admin');
    }

    public function configSalvar(): void
    {
        $this->verifyCsrf();

        // Whitelist: só estas chaves podem ser gravadas pela tela
        $booleanos = ['bot_ativo', 'quiet_hours_ativo', 'auto_marcar_lida',
                      'assinatura_obrigatoria', 'baixar_midia', 'assinatura_agente',
                      'notif_conversa_nova', 'notif_mensagem', 'notif_atribuicao',
                      'notif_campanha'];
        $inteiros  = ['quiet_hours_inicio', 'quiet_hours_fim', 'pausa_bot_minutos', 'janela_horas',
                      'notif_silencio_min', 'notif_sem_resposta_min', 'notif_falhas_min',
                      'ia_limite_dia'];

        $pares = [];
        foreach ($booleanos as $c) $pares[$c] = !empty($_POST[$c]) ? '1' : '0';

        foreach ($inteiros as $c) {
            if (!isset($_POST[$c])) continue;
            $v = (int)$_POST[$c];
            $v = match ($c) {
                'quiet_hours_inicio', 'quiet_hours_fim' => max(0, min(23, $v)),
                'janela_horas'                          => max(1, min(24, $v)),
                'pausa_bot_minutos'                     => max(0, min(1440, $v)),
                // 0 desliga o aviso; o teto de 24h evita "avise em 3 dias",
                // que na prática é o mesmo que não avisar
                'notif_sem_resposta_min'                => max(0, min(1440, $v)),
                'notif_silencio_min'                    => max(0, min(1440, $v)),
                'notif_falhas_min'                      => max(0, min(1000, $v)),
                // 0 = sem teto. O teto por PROVEDOR do IACustoService continua
                // valendo por cima deste — são camadas diferentes.
                'ia_limite_dia'                         => max(0, min(100000, $v)),
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

        // Assinatura do atendente — whitelist do recorte do nome
        if (isset($_POST['assinatura_nome'])) {
            $modo = (string)$_POST['assinatura_nome'];
            $pares['assinatura_nome'] = in_array($modo, ['primeiro', 'dois', 'completo'], true)
                ? $modo : 'dois';
        }

        // Sem {nome} o prefixo sairia idêntico em toda mensagem — recusa aqui,
        // com o motivo, em vez de deixar o service cair no padrão em silêncio.
        if (isset($_POST['assinatura_formato'])) {
            $fmt = trim((string)$_POST['assinatura_formato']);
            if ($fmt === '') {
                $fmt = '*{nome}:*';
            } elseif (!str_contains($fmt, '{nome}')) {
                $this->json(['ok' => false, 'erro' => 'O formato da assinatura precisa conter {nome}.']);
                return;
            }
            $pares['assinatura_formato'] = mb_substr($fmt, 0, 60);
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

    /** GET /admin/chat/config/webhook-logs — listagem filtrada e paginada. */
    public function webhookLogs(): void
    {
        $filtros = [
            'de'     => SecurityHelper::sanitizeString($_GET['de']     ?? ''),
            'ate'    => SecurityHelper::sanitizeString($_GET['ate']    ?? ''),
            'evento' => SecurityHelper::sanitizeString($_GET['evento'] ?? ''),
            'canal'  => SecurityHelper::sanitizeString($_GET['canal']  ?? ''),
            'estado' => SecurityHelper::sanitizeString($_GET['estado'] ?? ''),
            'busca'  => SecurityHelper::sanitizeSearch((string)($_GET['busca'] ?? '')),
        ];

        $svc = new ChatWebhookLogService();
        $r   = $svc->listar($filtros, (int)($_GET['pagina'] ?? 1));

        // O resumo acompanha o filtro de período/canal/evento, mas ignora o de
        // estado — senão "recusado: 12 de 12" não informaria nada.
        $this->json(['ok' => true, 'resumo' => $svc->resumo($filtros)] + $r);
    }

    /** GET /admin/chat/config/webhook-logs/{id} — a chamada inteira. */
    public function webhookLogDetalhe($id): void
    {
        $log = (new ChatWebhookLogService())->detalhe(SecurityHelper::sanitizeInt($id));
        if (!$log) { $this->json(['ok' => false, 'erro' => 'Chamada não encontrada.'], 404); return; }

        $this->json(['ok' => true, 'log' => $log]);
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
