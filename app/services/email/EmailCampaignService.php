<?php
/**
 * app/services/email/EmailCampaignService.php
 *
 * Orquestra ações de alto nível de campanhas:
 * teste, duplicar, pausar/continuar/cancelar, e verificação de conclusão.
 */
class EmailCampaignService
{
    /** @var PDO */
    private $db;
    /** @var EmailCampaign */
    private $campanhas;
    /** @var EmailTemplate */
    private $templates;
    /** @var EmailProviderService */
    private $providers;
    /** @var EmailTemplateService */
    private $tplSvc;
    /** @var EmailTrackingService */
    private $tracking;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->campanhas = new EmailCampaign();
        $this->templates = new EmailTemplate();
        $this->providers = new EmailProviderService();
        $this->tplSvc = new EmailTemplateService();
        $this->tracking = new EmailTrackingService();
    }

    /**
     * Envia um email de teste para o endereço informado, sem registrar nenhum
     * destinatário na fila.
     */
    public function enviarTeste($campanhaId, $emailDestino, $nomeDestino = null)
    {
        $camp = $this->campanhas->find($campanhaId);
        if (!$camp) throw new RuntimeException('Campanha não encontrada');

        $tpl = $this->templates->find((int)$camp['template_id']);
        if (!$tpl) throw new RuntimeException('Template da campanha não encontrado');

        $provedor = $this->providers->build((int)$camp['provedor_id']);
        $cfgProv  = $this->providers->getConfig((int)$camp['provedor_id']);

        $emailDestino = strtolower(trim($emailDestino));
        if (!filter_var($emailDestino, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email de teste inválido');
        }

        $vars = [
            'nome' => $nomeDestino ?: 'Cliente',
            'primeiro_nome' => $nomeDestino ?: 'Cliente',
            'email' => $emailDestino,
            'site_nome' => defined('SITE_NAME') ? SITE_NAME : 'SportMoto',
            'url_site' => defined('BASE_URL') ? BASE_URL : '',
            'url_descadastro' => (defined('BASE_URL') ? BASE_URL : '') . '/email/descadastrar/TESTE',
            'data_atual' => date('d/m/Y'),
            'cupom' => '',
        ];

        $assunto = $this->tplSvc->renderInline(
            $camp['assunto_override'] ?: $tpl['assunto'],
            $vars
        );
        $html = $this->tplSvc->render($tpl['html'], $vars);
        $text = $tpl['texto'] ? $this->tplSvc->render($tpl['texto'], $vars) : $this->tplSvc->htmlToText($html);

        list($html, $text) = $this->tplSvc->injectUnsubscribe($html, $text, $vars['url_descadastro']);

        $fromEmail = $camp['remetente_email'] ?: $cfgProv['remetente_email'];
        $fromName  = $camp['remetente_nome']  ?: $cfgProv['remetente_nome'];
        $replyTo   = $camp['reply_to']        ?: $cfgProv['reply_to'];

        $payload = [
            'from_email' => $fromEmail,
            'from_name'  => $fromName,
            'reply_to'   => $replyTo,
            'to_email'   => $emailDestino,
            'to_name'    => $nomeDestino,
            'subject'    => '[TESTE] ' . $assunto,
            'html'       => $html,
            'text'       => $text,
            'headers'    => [
                'X-Email-Test' => '1',
            ],
        ];

        return $provedor->send($payload);
    }

    /** Cria uma cópia da campanha no status 'rascunho'. */
    public function duplicar($campanhaId)
    {
        $c = $this->campanhas->find($campanhaId);
        if (!$c) throw new RuntimeException('Campanha não encontrada');

        $novaId = $this->campanhas->save([
            'nome' => $c['nome'] . ' (cópia)',
            'provedor_id' => $c['provedor_id'],
            'template_id' => $c['template_id'],
            'lista_id'    => $c['lista_id'],
            'segmento_id' => $c['segmento_id'],
            'assunto_override'   => $c['assunto_override'],
            'preheader_override' => $c['preheader_override'],
            'remetente_email'    => $c['remetente_email'],
            'remetente_nome'     => $c['remetente_nome'],
            'reply_to'           => $c['reply_to'],
            'agendada_para'      => null,
            'batch_size'         => $c['batch_size'],
            'intervalo_segundos' => $c['intervalo_segundos'],
            'criado_por' => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
        ]);
        return $novaId;
    }

    public function pausar($campanhaId)
    {
        $c = $this->campanhas->find($campanhaId);
        if (!$c) throw new RuntimeException('Campanha não encontrada');
        if (!in_array($c['status'], ['agendada','enviando','enfileirando'], true)) {
            throw new RuntimeException('Só é possível pausar campanhas agendadas/em envio');
        }
        $this->campanhas->setStatus($campanhaId, 'pausada');
    }

    public function continuar($campanhaId)
    {
        $c = $this->campanhas->find($campanhaId);
        if (!$c) throw new RuntimeException('Campanha não encontrada');
        if ($c['status'] !== 'pausada') {
            throw new RuntimeException('Só é possível retomar campanhas pausadas');
        }
        $novo = (!empty($c['agendada_para']) && strtotime($c['agendada_para']) > time())
            ? 'agendada'
            : 'enviando';
        $this->campanhas->setStatus($campanhaId, $novo);
    }

    public function cancelar($campanhaId)
    {
        $c = $this->campanhas->find($campanhaId);
        if (!$c) throw new RuntimeException('Campanha não encontrada');
        if (in_array($c['status'], ['concluida','cancelada'], true)) {
            throw new RuntimeException('Campanha já está finalizada');
        }
        // marca destinatários ainda em fila como ignorados (cancel)
        $st = $this->db->prepare("UPDATE email_campanha_destinatarios
            SET status = 'ignorado', erro = 'campanha cancelada', finalizado_em = NOW()
            WHERE campanha_id = :c AND status IN ('fila','processando')");
        $st->execute([':c' => (int)$campanhaId]);

        $this->campanhas->setStatus($campanhaId, 'cancelada');
    }

    /** Marca como concluída quando não há mais destinatários a processar. */
    public function finalizarSeCompleta($campanhaId)
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM email_campanha_destinatarios
            WHERE campanha_id = :c AND status IN ('fila','processando')");
        $st->execute([':c' => (int)$campanhaId]);
        $pendentes = (int)$st->fetchColumn();
        if ($pendentes === 0) {
            $this->campanhas->marcarConcluida($campanhaId);
            return true;
        }
        return false;
    }
}
