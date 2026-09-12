<?php
/**
 * admin/controllers/EmailProviderAdminController.php
 */
class EmailProviderAdminController extends Controller
{
    /** @var EmailProvider */
    private $model;
    /** @var EmailProviderService */
    private $svc;

    public function __construct()
    {
        // parent::__construct();
        $this->requirePermission();
        $this->model = new EmailProvider();
        $this->svc = new EmailProviderService();
    }

    private function requirePermission(): void
    {
        // A cascata mora no AuthHelper agora — ver o porquê lá.
        AuthHelper::requirePermissaoOuNivel('email_marketing', 'super', 'gerente');
    }

    /**
     * Chaves de credencial em que string vazia é um VALOR, não ausência.
     * Só `encryption` do SMTP, onde "" quer dizer *Nenhuma*.
     */
    private const CRED_ACEITA_VAZIO = ['encryption'];

    public function index()
    {
        $itens = $this->model->all(false);

        // O blob bruto nunca vai para a view. Mas a tela precisa dizer ao
        // usuário QUAIS credenciais já estão guardadas — senão o campo vazio
        // parece "não configurado" e ele digita de novo sem necessidade.
        // Vão só os NOMES das chaves preenchidas, nunca os valores.
        foreach ($itens as &$i) {
            $preenchidas = [];
            try {
                foreach ((array) $this->svc->decryptCreds($i['credenciais'] ?? '') as $k => $v) {
                    if ($v !== '' && $v !== null) $preenchidas[] = $k;
                }
            } catch (Throwable $e) {
                // credencial ilegível não pode derrubar a listagem
            }
            $i['credenciais']      = null;
            $i['cred_preenchidas'] = $preenchidas;
        }
        unset($i);

        $this->render('email-marketing/provedores/index', [
            'itens' => $itens,
            'titulo' => 'Provedores de Email',
        ], 'admin');
    }

    public function salvar()
    {
        $this->verifyCsrf();

        $id = (int)($_POST['id'] ?? 0);
        $tipo = $_POST['tipo'] ?? '';
        if (!in_array($tipo, ['ses','mailgun','sendgrid','brevo','smtp'], true)) {
            return $this->json(['ok' => false, 'erro' => 'Tipo inválido']);
        }

        // ── Credenciais: em branco = MANTÉM, nunca apaga ──────────────────
        // O formulário nasce com estes campos vazios, e faz certo: o index()
        // anula o blob antes de renderizar, então o segredo nunca vai para o
        // browser. O que estava errado era o save tratar vazio como "apagar" —
        // qualquer edição na tela (trocar o nome do remetente, ligar/desligar)
        // destruía api_key, domain e base_url.
        //
        // Agora vale a regra de todo painel que lida com segredo: campo em
        // branco não muda nada; quem quer trocar, digita o valor novo.
        $credIn = $_POST['credenciais'] ?? [];
        if (!is_array($credIn)) $credIn = [];

        $credAtual = [];
        if ($id > 0) {
            $atual = $this->model->find($id);
            if ($atual && !empty($atual['credenciais'])) {
                $credAtual = (array) $this->svc->decryptCreds($atual['credenciais']);

                // Mesmo achatamento do lado de cá: credencial já gravada como
                // array (pelo bug dos três inputs) volta a ser string aqui, e
                // sai normalizada no próximo save.
                foreach ($credAtual as $k => $v) {
                    if (!is_array($v)) continue;
                    $primeiro = '';
                    foreach ($v as $cand) {
                        if (is_string($cand) && trim($cand) !== '') { $primeiro = trim($cand); break; }
                    }
                    $credAtual[$k] = $primeiro;
                }
            }
        }

        $credFinal = $credAtual;
        foreach ($credIn as $k => $v) {
            // Achata array para o primeiro valor não vazio. O formulário tinha
            // três inputs `credenciais[api_key]` (mailgun/sendgrid/brevo) e
            // mandava os três — o JS agora desabilita os não selecionados, mas
            // a defesa fica: é o que normaliza o dado já gravado torto, e o
            // SendGrid não tem o `is_array` que Mailgun e Brevo ganharam.
            if (is_array($v)) {
                $primeiro = '';
                foreach ($v as $cand) {
                    if (is_string($cand) && trim($cand) !== '') { $primeiro = trim($cand); break; }
                }
                $v = $primeiro;
            }

            $v = is_string($v) ? trim($v) : $v;

            // `encryption` é a exceção: "" ali é a opção *Nenhuma*, um valor
            // legítimo. Sem esta linha, escolher "Nenhuma" no SMTP nunca salvava.
            if ($v === '' && !in_array($k, self::CRED_ACEITA_VAZIO, true)) {
                continue;
            }
            $credFinal[$k] = $v;
        }

        $cred = $this->svc->encryptCreds($credFinal);

        $dados = [
            'id'    => $id,
            'nome'  => trim((string)($_POST['nome'] ?? '')),
            'tipo'  => $tipo,
            'remetente_email' => trim((string)($_POST['remetente_email'] ?? '')),
            'remetente_nome'  => trim((string)($_POST['remetente_nome']  ?? '')),
            'reply_to'        => trim((string)($_POST['reply_to'] ?? '')) ?: null,
            'dominio'         => trim((string)($_POST['dominio'] ?? '')) ?: null,
            'regiao'          => trim((string)($_POST['regiao']  ?? '')) ?: null,
            'credenciais'     => $cred,
            'limite_por_minuto' => (int)($_POST['limite_por_minuto'] ?? 60),
            'limite_por_dia'    => (int)($_POST['limite_por_dia'] ?? 50000),
            'webhook_secret'    => trim((string)($_POST['webhook_secret'] ?? '')) ?: null,
            'ativo'  => !empty($_POST['ativo']) ? 1 : 0,
            'padrao' => !empty($_POST['padrao']) ? 1 : 0,
        ];

        try {
            $newId = $this->model->save($dados);
            if (class_exists('LogService')) {
                LogService::audit('email_provider_salvar', ['id' => $newId, 'tipo' => $tipo]);
            }
            return $this->json(['ok' => true, 'id' => $newId]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    /**
     * Liga/desliga pela lista, sem abrir o formulário.
     *
     * Existe porque o caminho antigo — abrir o modal e salvar — era o mesmo
     * que apagava as credenciais. Mesmo com aquilo corrigido, dois cliques e
     * um submit para virar uma flag é atrito à toa.
     */
    public function toggleAtivo()
    {
        $this->verifyCsrf();

        $id = (int)($_POST['id'] ?? 0);
        $p  = $id > 0 ? $this->model->find($id) : null;
        if (!$p) {
            return $this->json(['ok' => false, 'erro' => 'Provedor não encontrado.']);
        }

        $novo = empty($p['ativo']) ? 1 : 0;

        // Desativar o PADRÃO derruba todo o envio em silêncio: o EmailService
        // procura `ativo=1 AND padrao=1` e, não achando, qualquer `ativo=1` —
        // sem nenhum, não há fallback, e a falha só aparece no log.
        if ($novo === 0 && !empty($p['padrao'])) {
            return $this->json([
                'ok'   => false,
                'erro' => 'Este é o provedor padrão. Defina outro como padrão antes de desativá-lo.',
            ]);
        }

        try {
            $this->model->setAtivo($id, $novo);
            if (class_exists('LogService')) {
                LogService::audit('email_provider_toggle', [
                    'id' => $id, 'tipo' => $p['tipo'] ?? null, 'ativo' => $novo,
                ]);
            }
            return $this->json(['ok' => true, 'ativo' => $novo]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function testar()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        $emailDestino = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($emailDestino, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['ok' => false, 'erro' => 'Email destino inválido']);
        }
        try {
            $provider = $this->svc->build($id);
            $cfg = $this->svc->getConfig($id);
            $res = $provider->send([
                'from_email' => $cfg['remetente_email'],
                'from_name'  => $cfg['remetente_nome'] ?? '',
                'reply_to'   => $cfg['reply_to'] ?? null,
                'to_email'   => $emailDestino,
                'to_name'    => 'Teste',
                'subject'    => '[Teste] Provedor ' . $cfg['nome'],
                'html'       => '<p>Este é um email de teste enviado pelo painel administrativo do SportMoto às '
                              . date('d/m/Y H:i') . '.</p>',
                'text'       => 'Teste de provedor ' . $cfg['nome'] . ' em ' . date('d/m/Y H:i'),
                'headers'    => ['X-Email-Test' => '1'],
            ]);
            if ($res->success) {
                return $this->json(['ok' => true, 'message_id' => $res->providerMessageId]);
            }
            return $this->json(['ok' => false, 'erro' => $res->error]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }
}
