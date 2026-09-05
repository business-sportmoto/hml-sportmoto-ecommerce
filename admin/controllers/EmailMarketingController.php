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

    private function requirePermission(): void
    {
        // A cascata mora no AuthHelper agora — ver o porquê lá.
        AuthHelper::requirePermissaoOuNivel('email_marketing', 'super', 'gerente');
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
