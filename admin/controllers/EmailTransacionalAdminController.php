<?php
/**
 * admin/controllers/EmailTransacionalAdminController.php
 */
class EmailTransacionalAdminController extends Controller
{
    /** @var EmailTransacionalService */
    private $svc;

    public function __construct()
    {
        AuthHelper::requireAdminLevel('super', 'admin');
        // parent::__construct();
        $this->requirePermission();
        $this->svc = new EmailTransacionalService();
    }

    private function requirePermission(): void
    {
        if (method_exists('AuthHelper', 'requirePermission')) {
            try { AuthHelper::requirePermission('email_marketing'); return; } catch (Throwable $e) {}
        }
        if (method_exists('AuthHelper', 'requireAdminLevel')) { AuthHelper::requireAdminLevel(); return; }
        AuthHelper::requireAdmin();
    }

    /** Dashboard com KPIs + lista de templates transacionais. */
    public function index(): void
    {
        $db = Database::getInstance()->getConnection();
        $templates = $db->query(
            "SELECT * FROM email_templates
             WHERE tipo = 'transacional' AND status = 'ativo'
             ORDER BY nome ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $mapa   = EmailTransacionalService::getMapa();
        $kpis   = $this->svc->kpis();
        $porTipo = $this->svc->kpisPorTipo();

        // Mapeia tipo → nome do template para facilitar na view
        $tipoNome = array_flip($mapa);

        $this->render('email-marketing/transacional/index', [
            'templates' => $templates,
            'mapa'      => $mapa,
            'kpis'      => $kpis,
            'por_tipo'  => $porTipo,
            'titulo'    => 'Emails Transacionais',
        ], 'admin');
    }

    /** Log de envios recentes. */
    public function log(): void
    {
        $tipo = trim($_GET['tipo'] ?? '');
        $itens = $this->svc->logRecente(200, $tipo ?: null);
        $mapa  = EmailTransacionalService::getMapa();

        $this->render('email-marketing/transacional/log', [
            'itens' => $itens,
            'mapa'  => $mapa,
            'tipo'  => $tipo,
            'titulo' => 'Log de Transacionais',
        ], 'admin');
    }

    /** Envia email de teste de um tipo específico. */
    public function testar()
    {
        $this->verifyCsrf();
        $tipo  = trim($_POST['tipo'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (!$tipo || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['ok' => false, 'erro' => 'Tipo ou email inválido']);
        }

        $mapa = EmailTransacionalService::getMapa();
        if (!isset($mapa[$tipo])) {
            return $this->json(['ok' => false, 'erro' => 'Tipo não mapeado']);
        }

        

        // Variáveis de teste por tipo
        $varsTest = [
            'verificacao_email' => ['url_acao' => BASE_URL . '/verificar-email/TOKEN_TESTE'],
            'redefinicao_senha' => ['url_acao' => BASE_URL . '/redefinir-senha/TOKEN_TESTE'],
            'codigo_2fa'        => ['codigo' => '483921'],
            'codigo_login'      => ['codigo' => '728401'],
            'boas_vindas'       => [
                'url_site' => BASE_URL,                 
                'cor_padrao' => '#2563eb', 
                'primeiro_nome' => 'João'
            ],
            'pedido_confirmado' => [
                'pedido_codigo'    => 'SM-TEST-001',
                'pedido_total'     => '349,90',
                'forma_pagamento'  => 'PIX',
                'pedido_url'       => BASE_URL . '/minha-conta/pedidos',
            ],
            'pedido_enviado'    => [
                'pedido_codigo'   => 'SM-TEST-001',
                'pedido_url'      => BASE_URL . '/minha-conta/pedidos',
                'rastreio_codigo' => 'BR123456789BR',
                'rastreio_url'    => 'https://rastreamento.correios.com.br',
            ],
            'pedido_cancelado'  => [
                'pedido_codigo'   => 'SM-TEST-001',
                'reembolso_valor' => '349,90',
            ],
        ];

        $ok = $this->svc->enviar(
            $tipo, $email, 'Usuário Teste',
            $varsTest[$tipo] ?? []
        );

        // return $this->json(['ok' => false, 'erro' => $ok]);

        try {
            $ok = $this->svc->enviar(
                $tipo, $email, 'Usuário Teste',
                $varsTest[$tipo] ?? []
            );
            if (class_exists('LogService')) {
                LogService::audit('email_transacional_teste', ['tipo' => $tipo, 'email' => $email]);
            }
            return $this->json(['ok' => $ok, 'msg' => $ok ? 'Email enviado com sucesso' : 'Falha no envio — verifique o log']);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }
}
