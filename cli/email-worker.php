<?php
/**
 * cli/email-worker.php
 *
 * Worker CLI de envio de email marketing — SportMoto.
 * Bootstrap alinhado ao index.php (mesmos defines/config/database/autoload),
 * mas SEM iniciar sessão, despachar rotas ou compartilhar views.
 *
 * Uso:
 *   php cli/email-worker.php
 *   php cli/email-worker.php --verbose
 *
 * Cron sugerido (1x por minuto, com flock externo de defesa em profundidade):
 *   * * * * * flock -n /tmp/sm-email-worker.lock php /caminho/cli/email-worker.php >> /caminho/storage/logs/email-worker.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script só roda em CLI.\n");
    exit(1);
}

$verbose = in_array('--verbose', $argv ?? [], true);

// ============================================================================
// BOOTSTRAP — espelha o início do index.php sem ativar runtime web
// ============================================================================

// Marcador para que outros arquivos possam detectar contexto CLI se precisarem
define('EMAIL_WORKER_CLI', true);

$ROOT = dirname(__DIR__);
chdir($ROOT);

// Em CLI não há output buffering, mas mantemos a paridade simbólica
ini_set('expose_php', 0);

// 1) Defines globais do projeto (ROOT_PATH, BASE_URL, APP_ENV, APP_DEBUG, SITE_NAME, etc.)
require_once $ROOT . '/config/defines.php';

// 2) Config geral (constantes do app, EMAIL_MARKETING_KEY, etc.)
require_once $ROOT . '/config/config.php';

// 3) Database (define a classe Database; conexão é singleton, criada sob demanda)
require_once $ROOT . '/config/database.php';

// 4) Autoload do Composer (PHPMailer etc.)
if (is_file($ROOT . '/vendor/autoload.php')) {
    require_once $ROOT . '/vendor/autoload.php';
}

// 5) Autoloader idêntico ao do index.php
spl_autoload_register(function (string $class): void {
    $paths = [
        ROOT_PATH . '/core/',
        ROOT_PATH . '/app/controllers/',
        ROOT_PATH . '/app/models/',
        ROOT_PATH . '/app/helpers/',
        ROOT_PATH . '/app/services/',
        ROOT_PATH . '/app/services/email/',
        ROOT_PATH . '/app/services/email/providers/',
    ];
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Sanity check — Database tem de estar disponível
if (!class_exists('Database')) {
    fwrite(STDERR, "[FATAL] Classe Database não disponível após bootstrap.\n");
    exit(1);
}

// Tenta abrir conexão imediatamente, falha rápido se DB estiver fora
try {
    Database::getInstance()->getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] Falha ao conectar no banco: " . $e->getMessage() . "\n");
    exit(1);
}

// Carrega config do módulo
$cfg = require $ROOT . '/config/email-marketing.php';

// ============================================================================
// LOCK GLOBAL — apenas uma instância do worker por vez
// ============================================================================

$lockFile = $cfg['worker_lock_file'];
@mkdir(dirname($lockFile), 0775, true);

$fp = @fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    if ($verbose) echo "Outro worker já está rodando. Encerrando.\n";
    if ($fp) fclose($fp);
    exit(0);
}
ftruncate($fp, 0);
fwrite($fp, (string)getmypid());
fflush($fp);

register_shutdown_function(function () use (&$fp, $lockFile) {
    if (is_resource($fp)) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
    @unlink($lockFile);
});

// ============================================================================
// EXECUÇÃO
// ============================================================================

$started     = time();
$maxRun      = (int)$cfg['worker_max_runtime'];
$maxCamps    = (int)$cfg['worker_max_campanhas_por_rodada'];
$backoff     = $cfg['backoff_minutos'];
$maxAttempts = (int)$cfg['max_tentativas'];
$lockExpira  = (int)$cfg['lock_expira_segundos'];

$log = function ($m) use ($verbose) {
    if ($verbose) echo '[' . date('H:i:s') . "] $m\n";
    if (class_exists('LogService')) {
        try { LogService::info('email_worker: ' . $m); } catch (Throwable $e) {}
    }
};

$log("worker iniciado (pid " . getmypid() . ")");

$rec = new EmailCampaignRecipient();
$liberados = $rec->liberarLocksVencidos($lockExpira);
if ($liberados > 0) $log("liberados {$liberados} destinatários com lock vencido");

$campanhasModel = new EmailCampaign();
$campanhaSvc    = new EmailCampaignService();
$providerSvc    = new EmailProviderService();
$templateModel  = new EmailTemplate();
$tplSvc         = new EmailTemplateService();
$trackingSvc    = new EmailTrackingService();
$supressoesSvc  = new EmailSuppressionService();
$contatosModel  = new EmailContact();

// A/B Testing — carrega somente se o módulo estiver instalado
$abSvc       = class_exists('EmailAbTestService')     ? new EmailAbTestService()     : null;
$abVariacoes = class_exists('EmailCampaignVariation') ? new EmailCampaignVariation() : null;

// Fase 1 — inicia amostras A/B para campanhas recém-enfileiradas
if ($abSvc) {
    $db = Database::getInstance()->getConnection();
    $stAb = $db->query(
        "SELECT id FROM email_campanhas
         WHERE ab_ativo = 1
           AND status = 'enviando'
           AND (ab_fase IS NULL OR ab_fase = 'rascunho')
         LIMIT 5"
    );
    foreach ($stAb->fetchAll(PDO::FETCH_COLUMN) as $abId) {
        try {
            $r = $abSvc->iniciarAmostra((int)$abId);
            $log("A/B campanha #{$abId}: amostra iniciada A={$r['amostra_a']} B={$r['amostra_b']}");
        } catch (Throwable $e) {
            $log("A/B campanha #{$abId}: erro ao iniciar amostra — " . $e->getMessage());
        }
    }
}

$campanhas = $campanhasModel->elegiveisParaWorker($maxCamps);
if (!$campanhas) {
    $log("nenhuma campanha elegível");
    exit(0);
}

foreach ($campanhas as $camp) {
    if ((time() - $started) >= $maxRun) {
        $log("tempo máximo atingido — encerrando");
        break;
    }

    try {
        $log("processando campanha #{$camp['id']} ({$camp['nome']})");
        $campanhasModel->marcarIniciada($camp['id']);

        // ── Template padrão (pode ser sobrescrito por variação A/B) ──────────
        $tplPadrao = $templateModel->find((int)$camp['template_id']);
        if (!$tplPadrao) {
            $log("  template não encontrado, marcando como erro");
            $campanhasModel->setStatus($camp['id'], 'erro');
            continue;
        }

        $provedor = $providerSvc->build((int)$camp['provedor_id']);
        $cfgProv  = $providerSvc->getConfig((int)$camp['provedor_id']);

        $fromEmailPadrao = $camp['remetente_email'] ?: $cfgProv['remetente_email'];
        $fromNamePadrao  = $camp['remetente_nome']  ?: $cfgProv['remetente_nome'];
        $replyTo         = $camp['reply_to']        ?: $cfgProv['reply_to'];

        // ── Pré-carrega variações A/B se necessário ───────────────────────
        $abVariacoesMap = [];
        if ($abVariacoes && !empty($camp['ab_ativo'])) {
            foreach ($abVariacoes->findByCampanha((int)$camp['id']) as $v) {
                $abVariacoesMap[$v['variacao']] = $v;
            }
        }

        $batch     = max(1, (int)$camp['batch_size']);
        $intervalo = max(0, (int)$camp['intervalo_segundos']);

        // Loop de lotes até esgotar tempo, OU não houver mais destinatários
        while ((time() - $started) < $maxRun) {

            // Pode ter sido pausada/cancelada por outro processo
            $atual = $campanhasModel->find($camp['id']);
            if (!$atual) break;
            if (in_array($atual['status'], ['pausada','cancelada','concluida','erro'], true)) {
                $log("  status mudou para {$atual['status']} — saindo");
                break;
            }

            $destinatarios = $rec->reservarLote($camp['id'], $batch);
            if (!$destinatarios) {
                $log("  fila vazia — verificando conclusão");
                $campanhaSvc->finalizarSeCompleta($camp['id']);
                break;
            }

            $log("  lote de " . count($destinatarios) . " destinatários reservado");

            foreach ($destinatarios as $d) {
                try {
                    // Última verificação de supressão/status
                    if ($supressoesSvc->isSuppressed($d['email'])) {
                        $rec->marcarIgnorado($d['id'], 'email suprimido');
                        continue;
                    }
                    $contato = $contatosModel->findByEmail($d['email']);
                    if ($contato && in_array($contato['status'], ['descadastrado','bounce','complaint','bloqueado'], true)) {
                        $rec->marcarIgnorado($d['id'], 'contato ' . $contato['status']);
                        continue;
                    }

                    // ── Override A/B por destinatário ─────────────────────────────
                    $tpl       = $tplPadrao;
                    $fromEmail = $fromEmailPadrao;
                    $fromName  = $fromNamePadrao;

                    $variacaoLetra = $d['variacao'] ?? null;
                    if ($variacaoLetra && isset($abVariacoesMap[$variacaoLetra])) {
                        $v = $abVariacoesMap[$variacaoLetra];
                        // Template da variação (se configurado)
                        if (!empty($v['template_id'])) {
                            $tplVar = $templateModel->find((int)$v['template_id']);
                            if ($tplVar) $tpl = $tplVar;
                        }
                        // Remetente da variação (se configurado)
                        if (!empty($v['remetente_email'])) $fromEmail = $v['remetente_email'];
                        if (!empty($v['remetente_nome']))  $fromName  = $v['remetente_nome'];
                    }

                    $vars = [
                        'nome'          => $d['nome'] ?: ($contato['nome'] ?? 'Cliente'),
                        'primeiro_nome' => ($contato['primeiro_nome'] ?? null) ?: ($d['nome'] ?: 'Cliente'),
                        'email'         => $d['email'],
                        'cupom'         => '',
                        'site_nome'     => defined('SITE_NAME') ? SITE_NAME : 'SportMoto',
                        'url_site'      => defined('BASE_URL')  ? BASE_URL  : '',
                        'url_descadastro' => (defined('BASE_URL') ? BASE_URL : '')
                                          . '/email/descadastrar/' . $d['token_unsub'],
                        'data_atual'    => date('d/m/Y'),
                    ];

                    // Assunto: variação A/B > override campanha > template
                    $assuntoBase = (!empty($variacaoLetra) && !empty($abVariacoesMap[$variacaoLetra]['assunto']))
                        ? $abVariacoesMap[$variacaoLetra]['assunto']
                        : ($camp['assunto_override'] ?: $tpl['assunto']);
                    $assunto = $tplSvc->renderInline($assuntoBase, $vars);
                    $html = $tplSvc->render($tpl['html'], $vars);
                    $text = $tpl['texto']
                        ? $tplSvc->render($tpl['texto'], $vars)
                        : $tplSvc->htmlToText($html);

                    // tracking: links + pixel + unsub
                    $html = $trackingSvc->reescreverLinks(
                        $html, $camp['id'], $d['id'], $d['token_open'],
                        $vars['url_descadastro']
                    );
                    $html = $trackingSvc->injetarPixel($html, $d['token_open']);
                    list($html, $text) = $tplSvc->injectUnsubscribe($html, $text, $vars['url_descadastro']);

                    // List-Unsubscribe (RFC 8058 one-click)
                    $headers = [
                        'List-Unsubscribe'      => '<' . $vars['url_descadastro'] . '>',
                        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                        'X-Campaign-Id'         => (string)$camp['id'],
                    ];

                    $payload = [
                        'from_email'      => $fromEmail,
                        'from_name'       => $fromName,
                        'reply_to'        => $replyTo,
                        'to_email'        => $d['email'],
                        'to_name'         => $vars['nome'],
                        'subject'         => $assunto,
                        'html'            => $html,
                        'text'            => $text,
                        'headers'         => $headers,
                        'campanha_id'     => (int)$camp['id'],
                        'destinatario_id' => (int)$d['id'],
                    ];

                    /** @var EmailSendResult $res */
                    $res = $provedor->send($payload);

                    if ($res->success) {
                        $rec->marcarEnviado($d['id'], $res->providerMessageId);
                        $campanhasModel->incrementar($camp['id'], 'total_enviados');
                        // Contadores A/B
                        if ($abVariacoes && $variacaoLetra) {
                            $abVariacoes->incrementar((int)$camp['id'], $variacaoLetra, 'enviados');
                        }

                        if (class_exists('CanalLogService')) {
                            CanalLogService::gravar('email_marketing', 'campanha_' . $camp['id'], [
                                'destinatario'    => $d['email'],
                                'assunto'         => $assunto,
                                'preview'         => mb_substr(strip_tags($html), 0, 200),
                                'template_id'     => (int)$camp['template_id'],
                                'status'          => $res->success ? 'enviado' : 'erro',
                                'provider_msg_id' => $res->providerMessageId ?? null,
                                'erro_detalhe'    => $res->success ? null : $res->error,
                                'via'             => 'worker',
                            ]);
                        }
                    } else {
                        if ($res->permanent) {
                            $rec->marcarFalha($d['id'], $res->error);
                            $campanhasModel->incrementar($camp['id'], 'total_falhas');
                            if ($abVariacoes && $variacaoLetra) {
                                $abVariacoes->incrementar((int)$camp['id'], $variacaoLetra, 'falhas');
                            }
                            if (stripos($res->error, 'invalid') !== false
                                || stripos($res->error, 'unknown user') !== false) {
                                $supressoesSvc->suprimir($d['email'], 'dominio_invalido', 'worker', $res->error);
                            }

                            if (class_exists('CanalLogService')) {
                                CanalLogService::gravar('email_marketing', 'campanha_' . $camp['id'], [
                                    'destinatario'    => $d['email'],
                                    'assunto'         => $assunto,
                                    'preview'         => mb_substr(strip_tags($html), 0, 200),
                                    'template_id'     => (int)$camp['template_id'],
                                    'status'          => $res->success ? 'enviado' : 'erro',
                                    'provider_msg_id' => $res->providerMessageId ?? null,
                                    'erro_detalhe'    => $res->success ? null : $res->error,
                                    'via'             => 'worker',
                                ]);
                            }
                        } else {
                            $tentativasAtual = (int)$d['tentativas'];
                            if ($tentativasAtual + 1 >= $maxAttempts) {
                                $rec->marcarFalha($d['id'], $res->error);
                                $campanhasModel->incrementar($camp['id'], 'total_falhas');
                            } else {
                                $idx = min($tentativasAtual, count($backoff) - 1);
                                $sec = ($backoff[$idx] ?? 60) * 60;
                                $rec->marcarFalha($d['id'], $res->error, $sec);
                            }

                            if (class_exists('CanalLogService')) {
                                CanalLogService::gravar('email_marketing', 'campanha_' . $camp['id'], [
                                    'destinatario'    => $d['email'],
                                    'assunto'         => $assunto,
                                    'preview'         => mb_substr(strip_tags($html), 0, 200),
                                    'template_id'     => (int)$camp['template_id'],
                                    'status'          => $res->success ? 'enviado' : 'erro',
                                    'provider_msg_id' => $res->providerMessageId ?? null,
                                    'erro_detalhe'    => $res->success ? null : $res->error,
                                    'via'             => 'worker',
                                ]);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $rec->marcarFalha($d['id'], $e->getMessage(), 60);
                    $log("  erro destinatário #{$d['id']}: " . $e->getMessage());

                    if (class_exists('CanalLogService')) {
                        CanalLogService::gravar('email_marketing', 'campanha_' . $camp['id'], [
                            'destinatario'    => $d['email'],
                            'assunto'         => $assunto,
                            'preview'         => mb_substr(strip_tags($html), 0, 200),
                            'template_id'     => (int)$camp['template_id'],
                            'status'          => $res->success ? 'enviado' : 'erro',
                            'provider_msg_id' => $res->providerMessageId ?? null,
                            'erro_detalhe'    => $res->success ? null : $res->error,
                            'via'             => 'worker',
                        ]);
                    }
                }
            }

            // Verifica ciclo A/B após cada lote
            if ($abSvc && !empty($camp['ab_ativo']) && ($camp['ab_fase'] ?? '') === 'amostra') {
                try {
                    $r = $abSvc->processarCiclo((int)$camp['id']);
                    if ($r['acao'] !== 'aguardando') {
                        $log("  A/B ciclo campanha #{$camp['id']}: " . json_encode($r, JSON_UNESCAPED_UNICODE));
                        // Recarrega variações caso vencedor tenha sido aplicado
                        $abVariacoesMap = [];
                        foreach ($abVariacoes->findByCampanha((int)$camp['id']) as $v) {
                            $abVariacoesMap[$v['variacao']] = $v;
                        }
                    }
                } catch (Throwable $e) {
                    $log("  A/B erro no ciclo: " . $e->getMessage());
                }
            }

            // Intervalo entre lotes (respeita limite do provedor)
            if ($intervalo > 0 && (time() - $started) < $maxRun) {
                sleep($intervalo);
            }
        }

        // Tenta finalizar (caso tenha esvaziado nesta rodada)
        $campanhaSvc->finalizarSeCompleta($camp['id']);

    } catch (Throwable $e) {
        $log("  ERRO campanha #{$camp['id']}: " . $e->getMessage());
        if (class_exists('LogService')) {
            try { LogService::error('email_worker campanha #' . $camp['id'] . ': ' . $e->getMessage()); } catch (Throwable $ex) {}
        }
    }
}

$log("worker encerrado (tempo: " . (time() - $started) . "s)");
exit(0);