<?php
/**
 * admin/controllers/EmailAbTestAdminController.php
 *
 * Gerencia o ciclo de vida de teste A/B numa campanha.
 */
class EmailAbTestAdminController extends Controller
{
    /** @var EmailCampaign */
    private $campanhas;
    /** @var EmailCampaignVariation */
    private $variacoes;
    /** @var EmailAbTestService */
    private $svc;
    /** @var EmailTemplate */
    private $templates;

    public function __construct()
    {
        parent::__construct();
        $this->requirePermission();
        $this->campanhas = new EmailCampaign();
        $this->variacoes = new EmailCampaignVariation();
        $this->svc       = new EmailAbTestService();
        $this->templates = new EmailTemplate();
    }

    private function requirePermission(): void
    {
        // A cascata mora no AuthHelper agora — ver o porquê lá.
        AuthHelper::requirePermissaoOuNivel('email_marketing', 'super', 'gerente');
    }

    /** Ativa modo A/B em uma campanha existente. */
    public function ativar()
    {
        $this->verifyCsrf();
        $campanhaId = (int)($_POST['campanha_id'] ?? 0);
        $camp = $this->campanhas->find($campanhaId);
        if (!$camp) return $this->json(['ok' => false, 'erro' => 'Campanha não encontrada']);
        if (!in_array($camp['status'], ['rascunho','pausada'], true)) {
            return $this->json(['ok' => false, 'erro' => 'Só é possível ativar A/B em campanhas em rascunho ou pausadas']);
        }

        try {
            $this->campanhas->update($campanhaId, [
                'ab_ativo' => 1,
                'ab_fase'  => 'rascunho',
            ]);

            // Cria registros vazios pra variação A e B (se ainda não houver)
            foreach (['a','b'] as $letra) {
                $this->variacoes->save($campanhaId, $letra, [
                    'template_id' => null,
                    'assunto'     => $camp['assunto'] ?? null,
                ]);
            }

            if (class_exists('LogService')) LogService::audit('ab_ativar', ['campanha_id' => $campanhaId]);
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function desativar()
    {
        $this->verifyCsrf();
        $campanhaId = (int)($_POST['campanha_id'] ?? 0);
        $camp = $this->campanhas->find($campanhaId);
        if (!$camp) return $this->json(['ok' => false, 'erro' => 'Campanha não encontrada']);
        if (!in_array($camp['status'], ['rascunho','pausada'], true)) {
            return $this->json(['ok' => false, 'erro' => 'Só é possível desativar A/B em campanhas em rascunho ou pausadas']);
        }
        $this->campanhas->update($campanhaId, [
            'ab_ativo' => 0,
            'ab_fase'  => null,
        ]);
        return $this->json(['ok' => true]);
    }

    /** Tela de configuração das duas variações. */
    public function variacoes($id)
    {
        $id = (int)$id;
        $camp = $this->campanhas->find($id);
        if (!$camp) { header('Location: ' . BASE_URL . '/admin/email-marketing/campanhas'); exit; }

        $variacoes = $this->variacoes->findByCampanha($id);
        $vMap = [];
        foreach ($variacoes as $v) $vMap[$v['variacao']] = $v;
        if (!isset($vMap['a'])) $vMap['a'] = $this->emptyVariacao('a');
        if (!isset($vMap['b'])) $vMap['b'] = $this->emptyVariacao('b');

        $templates = $this->templates->all(true);

        $this->render('email-marketing/abtest/variacoes', [
            'campanha'  => $camp,
            'variacoes' => $vMap,
            'templates' => $templates,
            'titulo'    => 'Variações A/B — ' . $camp['nome'],
        ], 'admin');
    }

    private function emptyVariacao(string $letra): array
    {
        return [
            'variacao' => $letra, 'template_id' => null,
            'assunto' => null, 'preheader' => null,
            'remetente_email' => null, 'remetente_nome' => null,
            'total_destinatarios' => 0, 'total_enviados' => 0, 'total_entregues' => 0,
            'total_aberturas' => 0, 'total_cliques' => 0,
            'total_bounces' => 0, 'total_complaints' => 0,
            'total_descadastros' => 0, 'total_falhas' => 0,
        ];
    }

    /** Salva as duas variações. */
    public function salvarVariacoes()
    {
        $this->verifyCsrf();
        $campanhaId = (int)($_POST['campanha_id'] ?? 0);

        $cfgPostA = $_POST['a'] ?? [];
        $cfgPostB = $_POST['b'] ?? [];

        try {
            foreach (['a' => $cfgPostA, 'b' => $cfgPostB] as $letra => $dados) {
                $this->variacoes->save($campanhaId, $letra, [
                    'template_id'     => !empty($dados['template_id']) ? (int)$dados['template_id'] : null,
                    'assunto'         => trim((string)($dados['assunto'] ?? '')) ?: null,
                    'preheader'       => trim((string)($dados['preheader'] ?? '')) ?: null,
                    'remetente_email' => trim((string)($dados['remetente_email'] ?? '')) ?: null,
                    'remetente_nome'  => trim((string)($dados['remetente_nome'] ?? '')) ?: null,
                ]);
            }

            // Atualiza configuração A/B na campanha
            $cfg = [
                'ab_amostra_pct_a'    => max(5, min(50, (int)($_POST['ab_amostra_pct_a'] ?? 15))),
                'ab_amostra_pct_b'    => max(5, min(50, (int)($_POST['ab_amostra_pct_b'] ?? 15))),
                'ab_metrica'          => in_array($_POST['ab_metrica'] ?? 'clique', ['abertura','clique','manual'], true)
                                           ? $_POST['ab_metrica'] : 'clique',
                'ab_tempo_analise_min' => max(10, min(10080, (int)($_POST['ab_tempo_analise_min'] ?? 240))),
                'ab_min_eventos'      => max(1, min(1000, (int)($_POST['ab_min_eventos'] ?? 10))),
                'ab_em_empate'        => in_array($_POST['ab_em_empate'] ?? 'aguardar_manual', ['a','b','random','aguardar_manual'], true)
                                           ? $_POST['ab_em_empate'] : 'aguardar_manual',
                'ab_envio_automatico' => !empty($_POST['ab_envio_automatico']) ? 1 : 0,
            ];
            $this->campanhas->update($campanhaId, $cfg);

            if (class_exists('LogService')) {
                LogService::audit('ab_salvar_variacoes', ['campanha_id' => $campanhaId, 'cfg' => $cfg]);
            }
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    /** Valida configuração antes de enfileirar. */
    public function validar($id)
    {
        $id = (int)$id;
        $r = $this->svc->validarConfiguracao($id);
        return $this->json($r);
    }

    /** Tela de relatório/comparação A vs B. */
    public function relatorio($id)
    {
        $id = (int)$id;
        $camp = $this->campanhas->find($id);
        if (!$camp) { header('Location: ' . BASE_URL . '/admin/email-marketing/campanhas'); exit; }

        $variacoes = $this->variacoes->findByCampanha($id);
        $vMap = [];
        foreach ($variacoes as $v) $vMap[$v['variacao']] = $v;

        // Status atual do ciclo (se em amostra, mostra quanto falta)
        $statusCiclo = $this->svc->verificarDecisao($id);

        $taxas = null;
        try {
            if (count($variacoes) >= 2 && (int)$variacoes[0]['total_entregues'] > 0) {
                $taxas = $this->svc->calcularVencedor($id);
            }
        } catch (Throwable $e) { /* ignore */ }

        $this->render('email-marketing/abtest/relatorio', [
            'campanha' => $camp,
            'variacoes' => $vMap,
            'status_ciclo' => $statusCiclo,
            'taxas' => $taxas,
            'titulo' => 'Relatório A/B — ' . $camp['nome'],
        ], 'admin');
    }

    /** Escolhe vencedor manualmente. */
    public function escolherVencedor()
    {
        $this->verifyCsrf();
        $campanhaId = (int)($_POST['campanha_id'] ?? 0);
        $vencedor = $_POST['vencedor'] ?? '';
        try {
            $qtd = $this->svc->escolherManualmente(
                $campanhaId, $vencedor,
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
            );
            return $this->json(['ok' => true, 'qtd_rollout' => $qtd]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }
}
