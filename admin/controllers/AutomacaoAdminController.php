<?php
/**
 * admin/controllers/AutomacaoAdminController.php
 */
class AutomacaoAdminController extends Controller
{
    /** @var AutomacaoModel */
    private $model;

    public function __construct()
    {
        // parent::__construct();
        $this->requirePermission();
        $this->model = new AutomacaoModel();
    }

    private function requirePermission(): void
    {
        if (method_exists('AuthHelper', 'requirePermission')) {
            try { AuthHelper::requirePermission('email_marketing'); return; } catch (Throwable $e) {}
        }
        if (method_exists('AuthHelper', 'requireAdminLevel')) {
            AuthHelper::requireAdminLevel(); return;
        }
        AuthHelper::requireAdmin();
    }

    /** Dashboard de automações. */
    public function index(): void
    {
        $kpis   = $this->model->kpisPorFluxo();
        $fluxos = $this->model->todosFluxos();

        $this->render('email-marketing/automacoes/index', [
            'kpis'   => $kpis,
            'fluxos' => $fluxos,
            'titulo' => 'Automações de Email',
        ], 'admin');
    }

    /** Editar configuração de um fluxo. */
    public function editar(int $id): void
    {
        $fluxo = $this->model->findFluxo($id);
        if (!$fluxo) {
            header('Location: ' . BASE_URL . '/admin/email-marketing/automacoes');
            exit;
        }
        $passos    = $this->model->passos($id);
        $templates = (new EmailTemplate())->all(true);
        $config    = json_decode($fluxo['config_json'] ?? '{}', true) ?: [];

        $this->render('email-marketing/automacoes/editar', [
            'fluxo'     => $fluxo,
            'passos'    => $passos,
            'templates' => $templates,
            'config'    => $config,
            'titulo'    => 'Editar automação — ' . $fluxo['nome'],
        ], 'admin');
    }

    /** Salva config do fluxo. */
    public function salvar()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);

        $config = [
            'delays_horas'       => array_map('intval', (array)($_POST['delays_horas'] ?? [])),
            'delays_dias'        => array_map('intval', (array)($_POST['delays_dias'] ?? [])),
            'min_visitas'        => (int)($_POST['min_visitas'] ?? 2),
            'cupom_pct'          => (float)($_POST['cupom_pct'] ?? 10),
            'cupom_dias_validade'=> (int)($_POST['cupom_dias_validade'] ?? 7),
            'dias_sem_compra'    => (int)($_POST['dias_sem_compra'] ?? 60),
            'delay_dias'         => (int)($_POST['delay_dias'] ?? 7),
        ];
        // Remove chaves sem valor
        $config = array_filter($config, fn($v) => $v !== 0 && $v !== '' && $v !== []);

        try {
            $this->model->atualizarFluxo($id, [
                'nome'        => trim((string)($_POST['nome'] ?? '')),
                'ativo'       => !empty($_POST['ativo']) ? 1 : 0,
                'config_json' => json_encode($config),
            ]);

            // Salva templates dos passos
            if (!empty($_POST['passo'])) {
                foreach ($_POST['passo'] as $passoId => $dados) {
                    $this->model->atualizarPasso((int)$passoId, [
                        'template_id' => !empty($dados['template_id']) ? (int)$dados['template_id'] : null,
                        'delay_horas' => !empty($dados['delay_horas']) ? (int)$dados['delay_horas'] : null,
                    ]);
                }
            }

            if (class_exists('LogService')) {
                LogService::audit('automacao_salvar', ['id' => $id]);
            }
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    /** Toggle ativo/inativo rápido. */
    public function toggle()
    {
        $this->verifyCsrf();
        $id    = (int)($_POST['id'] ?? 0);
        $ativo = !empty($_POST['ativo']) ? 1 : 0;
        try {
            $this->model->atualizarFluxo($id, ['ativo' => $ativo]);
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    /** Relatório de um fluxo. */
    public function relatorio(int $id): void
    {
        $fluxo = $this->model->findFluxo($id);
        if (!$fluxo) {
            header('Location: ' . BASE_URL . '/admin/email-marketing/automacoes');
            exit;
        }

        $db = Database::getInstance()->getConnection();

        // Últimos 30 dias
        $st = $db->prepare(
            "SELECT DATE(h.criado_em) AS data,
                    SUM(CASE WHEN h.resultado='enviado'   THEN 1 ELSE 0 END) AS enviados,
                    SUM(CASE WHEN h.resultado='suprimido' THEN 1 ELSE 0 END) AS suprimidos,
                    SUM(CASE WHEN h.resultado='erro'      THEN 1 ELSE 0 END) AS erros
             FROM automacao_historico h
             WHERE h.fluxo_id = :f
               AND h.criado_em > DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(h.criado_em)
             ORDER BY data ASC"
        );
        $st->execute([':f' => $id]);
        $grafico = $st->fetchAll(PDO::FETCH_ASSOC);

        // Últimos envios
        $st = $db->prepare(
            "SELECT h.*, u.nome AS cliente_nome, u.email AS cliente_email,
                    p.nome AS passo_nome
             FROM automacao_historico h
             JOIN clientes c ON c.id = h.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             JOIN automacao_passos p ON p.id = h.passo_id
             WHERE h.fluxo_id = :f
             ORDER BY h.criado_em DESC
             LIMIT 50"
        );
        $st->execute([':f' => $id]);
        $historico = $st->fetchAll(PDO::FETCH_ASSOC);

        $this->render('email-marketing/automacoes/relatorio', [
            'fluxo'     => $fluxo,
            'grafico'   => $grafico,
            'historico' => $historico,
            'titulo'    => 'Relatório — ' . $fluxo['nome'],
        ], 'admin');
    }
}
