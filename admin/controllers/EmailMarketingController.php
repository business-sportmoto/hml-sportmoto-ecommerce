<?php
/**
 * admin/controllers/EmailMarketingController.php
 */
class EmailMarketingController extends Controller
{
    private $dash;

    public function __construct()
    {
        // parent::__construct();
        $this->requirePermission();
        $this->dash = new EmailDashboardService();
    }

    private function requirePermission()
    {
        if (method_exists('AuthHelper', 'requirePermission')) {
            try { AuthHelper::requirePermission('email_marketing'); return; } catch (Throwable $e) {}
        }
        if (method_exists('AuthHelper', 'requireAdminLevel')) {
            AuthHelper::requireAdminLevel(); return;
        }
        AuthHelper::requireAdmin();
    }

    public function index()
    {
        $svc = new EmailMarketingService();
        $kpis = $svc->dashboardKpis();
        $ultimas = $svc->ultimasCampanhas(8);

        $dados = $this->dash->coletar();

        $this->render('email-marketing/index', [
            'kpis' => $kpis,
            'ultimas' => $ultimas,
            'dash'   => $dados,
            'titulo' => 'Email Marketing',
        ], 'admin');
    }
}
