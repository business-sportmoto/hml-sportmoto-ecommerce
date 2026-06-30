<?php
/**
 * app/services/email/EmailWebhookService.php
 *
 * Recebe eventos JÁ NORMALIZADOS pelos provider adapters
 * (parseWebhook) e aplica os efeitos no banco:
 *  - grava email_eventos (com dedupe);
 *  - atualiza email_campanha_destinatarios (status/datas);
 *  - atualiza email_contatos (status terminal);
 *  - adiciona email_supressoes quando aplicável;
 *  - incrementa contadores na campanha.
 */
class EmailWebhookService
{
    /** @var EmailEvent */
    private $eventos;
    /** @var EmailCampaignRecipient */
    private $destinatarios;
    /** @var EmailContact */
    private $contatos;
    /** @var EmailSuppression */
    private $supressoes;
    /** @var EmailCampaign */
    private $campanhas;

    public function __construct()
    {
        $this->eventos       = new EmailEvent();
        $this->destinatarios = new EmailCampaignRecipient();
        $this->contatos      = new EmailContact();
        $this->supressoes    = new EmailSuppression();
        $this->campanhas     = new EmailCampaign();
    }

    /**
     * Processa lista de eventos vinda do provider.
     * @return array stats
     */
    public function processarEventos(array $eventos)
    {
        $stats = [
            'processados' => 0, 'duplicados' => 0, 'ignorados' => 0,
            'entregues' => 0, 'aberturas' => 0, 'cliques' => 0,
            'bounces' => 0, 'complaints' => 0, 'descadastros' => 0,
        ];

        foreach ($eventos as $ev) {
            try {
                $this->processarUm($ev, $stats);
            } catch (Throwable $e) {
                if (class_exists('LogService')) {
                    LogService::error('email_webhook: ' . $e->getMessage(), ['ev' => $ev]);
                }
            }
        }
        return $stats;
    }

    private function processarUm(array $ev, array &$stats)
    {
        $tipo = $ev['tipo'] ?? '';
        if (!$tipo) { $stats['ignorados']++; return; }

        // Localiza destinatário: por provider_message_id, ou por destinatario_id na tag, ou por email
        $destinatario = null;
        if (!empty($ev['destinatario_id'])) {
            $destinatario = $this->destinatarios->find((int)$ev['destinatario_id']);
        }
        if (!$destinatario && !empty($ev['provider_message_id'])) {
            $destinatario = $this->destinatarios->findByProviderMessageId($ev['provider_message_id']);
        }

        $contato = null;
        if (!empty($ev['email'])) {
            $contato = $this->contatos->findByEmail($ev['email']);
        }

        $campanhaId    = $destinatario['campanha_id'] ?? ($ev['campanha_id'] ?? null);
        $destinatarioId = $destinatario['id'] ?? null;
        $contatoId      = $destinatario['contato_id'] ?? ($contato['id'] ?? null);

        // Idempotência via dedupe_key (INSERT IGNORE no model)
        $insertedId = $this->eventos->registrar([
            'campanha_id' => $campanhaId,
            'destinatario_id' => $destinatarioId,
            'contato_id' => $contatoId,
            'provider_message_id' => $ev['provider_message_id'] ?? null,
            'tipo' => $tipo,
            'subtipo' => $ev['subtipo'] ?? null,
            'ip' => $ev['ip'] ?? null,
            'user_agent' => $ev['user_agent'] ?? null,
            'dedupe_key' => $ev['dedupe_key'] ?? null,
            'payload_json' => $ev['payload'] ?? null,
        ]);
        if (!$insertedId) {
            $stats['duplicados']++;
            return;
        }

        switch ($tipo) {
            case 'enviado':
                // já marcamos como enviado no worker; só registramos o evento
                $stats['processados']++;
                break;

            case 'entregue':
                if ($destinatario && !in_array($destinatario['status'], ['entregue','aberto','clicado','bounce','complaint','descadastrado'], true)) {
                    $this->destinatarios->atualizarStatusEvento(
                        $destinatario['id'], 'entregue',
                        ['entregue_em' => date('Y-m-d H:i:s')]
                    );
                }
                if ($campanhaId) $this->campanhas->incrementar($campanhaId, 'total_entregues');
                $stats['entregues']++;
                break;

            case 'aberto':
                if ($destinatario) {
                    $jaAberto = !empty($destinatario['aberto_em']);
                    if (!in_array($destinatario['status'], ['aberto','clicado','bounce','complaint','descadastrado'], true)) {
                        $this->destinatarios->atualizarStatusEvento(
                            $destinatario['id'], 'aberto',
                            ['aberto_em' => date('Y-m-d H:i:s')]
                        );
                    }
                    if (!$jaAberto && $campanhaId) {
                        $this->campanhas->incrementar($campanhaId, 'total_aberturas');
                    }
                }
                $stats['aberturas']++;
                break;

            case 'clicado':
                if ($destinatario) {
                    $jaClicou = !empty($destinatario['clicado_em']);
                    if (!in_array($destinatario['status'], ['clicado','bounce','complaint','descadastrado'], true)) {
                        $this->destinatarios->atualizarStatusEvento(
                            $destinatario['id'], 'clicado',
                            ['clicado_em' => date('Y-m-d H:i:s')]
                        );
                    }
                    if (!$jaClicou && $campanhaId) {
                        $this->campanhas->incrementar($campanhaId, 'total_cliques');
                    }
                }
                $stats['cliques']++;
                break;

            case 'bounce':
                $sub = strtolower((string)($ev['subtipo'] ?? ''));
                $isHard = strpos($sub, 'hard') !== false || strpos($sub, 'permanent') !== false;
                if ($destinatario) {
                    $this->destinatarios->atualizarStatusEvento($destinatario['id'], 'bounce');
                }
                if ($contato) {
                    $this->contatos->setStatus($contato['id'], 'bounce');
                }
                if ($ev['email']) {
                    $this->supressoes->adicionar(
                        $ev['email'],
                        $isHard ? 'hard_bounce' : 'soft_bounce_repetido',
                        'webhook'
                    );
                }
                if ($campanhaId) $this->campanhas->incrementar($campanhaId, 'total_bounces');
                $stats['bounces']++;
                if (class_exists('LogService')) {
                    LogService::audit('email_bounce', ['email' => $ev['email'], 'sub' => $ev['subtipo']]);
                }
                break;

            case 'complaint':
                if ($destinatario) {
                    $this->destinatarios->atualizarStatusEvento($destinatario['id'], 'complaint');
                }
                if ($contato) {
                    $this->contatos->setStatus($contato['id'], 'complaint');
                }
                if ($ev['email']) {
                    $this->supressoes->adicionar($ev['email'], 'complaint', 'webhook');
                }
                if ($campanhaId) $this->campanhas->incrementar($campanhaId, 'total_complaints');
                $stats['complaints']++;
                if (class_exists('LogService')) {
                    LogService::audit('email_complaint', ['email' => $ev['email']]);
                }
                break;

            case 'descadastro':
                if ($destinatario) {
                    $this->destinatarios->atualizarStatusEvento($destinatario['id'], 'descadastrado');
                }
                if ($contato) {
                    $this->contatos->setStatus($contato['id'], 'descadastrado');
                    // espelha em newsletter
                    $st = (Database::getInstance()->getConnection())
                        ->prepare("UPDATE newsletter SET ativo = 0 WHERE email = :e");
                    $st->execute([':e' => $contato['email']]);
                }
                if ($ev['email']) {
                    $this->supressoes->adicionar($ev['email'], 'descadastro', 'webhook');
                }
                if ($campanhaId) $this->campanhas->incrementar($campanhaId, 'total_descadastros');
                $stats['descadastros']++;
                break;

            case 'falhou':
                if ($destinatario) {
                    $this->destinatarios->marcarFalha($destinatario['id'], $ev['subtipo'] ?? 'falha');
                }
                if ($campanhaId) $this->campanhas->incrementar($campanhaId, 'total_falhas');
                $stats['ignorados']++;
                break;

            default:
                $stats['ignorados']++;
                break;
        }
    }
}
