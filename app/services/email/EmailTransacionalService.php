<?php
/**
 * app/services/email/EmailTransacionalService.php
 *
 * Serviço de envio de emails transacionais.
 * Busca o template pelo nome no banco (tipo='transacional'),
 * renderiza com as variáveis fornecidas e envia via provedor padrão.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * MAPEAMENTO: tipo → nome do template no banco
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * 'verificacao_email'  → 'Verificação de Email'
 * 'redefinicao_senha'  → 'Redefinição de Senha'
 * 'codigo_2fa'         → 'Código 2FA'
 * 'codigo_login'       → 'Código de Login'
 * 'boas_vindas'        → 'Boas-vindas'
 * 'pedido_confirmado'  → 'Pedido Confirmado'
 * 'pedido_enviado'     → 'Pedido Enviado'
 * 'pedido_cancelado'   → 'Pedido Cancelado'
 *
 * Adicione novos tipos apenas criando um template no banco com
 * tipo='transacional' e adicionando o mapeamento em $mapa abaixo.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class EmailTransacionalService
{
    /** @var PDO */
    private $db;
    /** @var EmailTemplateService */
    private $tplSvc;

    private $svc;

    /** Mapeamento tipo → nome do template no banco. */
    private static $mapa = [
        'verificacao_email'             => 'Verificação de Email',
        'redefinicao_senha'             => 'Redefinição de Senha',
        'codigo_2fa'                    => 'Código 2FA',
        'codigo_login'                  => 'Código de Login',
        'boas_vindas'                   => 'Boas-vindas',
        'pedido_confirmado'             => 'Pedido Confirmado',
        'pedido_enviado'                => 'Pedido Enviado',
        'pedido_cancelado'              => 'Pedido Cancelado',
        'pedido_cancelado_s_pagamento'  => 'Pedido Cancelado S/Pagamento',
        'pedido_cancelado_manual'       => 'Pedido Cancelado Manual',
        'pedido_cancelado_c/reembolso'  => 'Pedido Cancelado C/Reembolso',
        'volta_estoque'                 => 'Voltou ao estoque (avise-me)',

        'senha_alterada'                 => 'Senha alterada',
        'aviso_de_login'                 => 'Aviso de login',
        'pergunta_respondida'            => 'Pergunta respondida'
    ];

    public function __construct()
    {
        $this->db       = Database::getInstance()->getConnection();
        $this->tplSvc   = new EmailTemplateService();
        $this->svc      = new EmailProviderService();
    }

    // =========================================================================
    // MÉTODO PRINCIPAL
    // =========================================================================

    /**
     * Envia um email transacional.
     *
     * @param string $tipo       Chave do mapa (ex: 'pedido_confirmado')
     * @param string $para       Email do destinatário
     * @param string $nomeDestino Nome do destinatário
     * @param array  $vars       Variáveis para o template
     * @return bool
     */
    public function enviar(string $tipo, string $para, string $nomeDestino, array $vars = []): bool
    {   
        
        // Variáveis globais sempre disponíveis
        $vars = array_merge([
            'logo_url'   => BASE_URL.'/uploads'.ConfigHelper::get('site_logo'),
            'logo_loja'   => BASE_URL.'/uploads'.ConfigHelper::get('site_logo'),
            'site_nome'  => ConfigHelper::get('site_nome'),
            'site_url'   => ConfigHelper::get('site_url'),
            'nome'       => $nomeDestino,
            'ano'        => date('Y'),
            'data_atual' => date('d/m/Y'),
            
            'cor_padrao' => ConfigHelper::get('cor_padrao'),
            'empresa_endereco' => ConfigHelper::get('empresa_endereco'),
            'empresa_cnpj'     => ConfigHelper::get('empresa_cnpj'),
            'email'           => ConfigHelper::get('site_email'),
            'atendimento_url'  => BASE_URL . '/contato',
            'politica_privacidade_url' => BASE_URL . '/politica-de-privacidade',
            'descadastro_url'  => BASE_URL . '/email/descadastrar/' . urlencode($para),  
            'url_descadastro'=> BASE_URL . '/email/descadastrar/' . urlencode($para),      
        ], $vars);


        $template = $this->buscarTemplate($tipo);

        if (!$template) {
            // Sem template: registra e retorna false para o fallback do MailHelper
            $this->registrarLog($tipo, null, $para, '', 'sem_template', null, null, null, $vars);
            return false;
        }

        // Renderiza assunto e HTML
        $assunto = $this->tplSvc->renderInline($template['assunto'], $vars);
        $html    = $this->tplSvc->render($template['html'], $vars);
        $texto   = $template['texto']
            ? $this->tplSvc->render($template['texto'], $vars)
            : $this->tplSvc->htmlToText($html);

        // Busca provedor
        $provedor = $this->buscarProvedor();
        
        if (!$provedor) {
            $this->registrarLog($tipo, (int)$template['id'], $para, $assunto, 'erro', null, null,
                'Nenhum provedor ativo encontrado', $vars);
            return false;
        }

        try {
            $provider = $this->svc->build($provedor['id']);            
            // $provider = $this->svc->build($provedor);            

            $resultado = $provider->send([
                'from_email' => $provedor['remetente_email'],
                'from_name'  => $provedor['remetente_nome'],
                'to_email'   => $para,
                'to_name'    => $nomeDestino,
                'subject'    => $assunto,
                'html'       => $html,
                'text'       => $texto,
                'headers'    => [
                    'X-Email-Type' => $tipo,
                ],
            ]);

            

            $sucesso = method_exists($resultado, 'isOk') ? $resultado->isOk() : (bool)$resultado;
            $msgId   = method_exists($resultado, 'getMessageId') ? $resultado->getMessageId() : null;
            $erro    = (!$sucesso && method_exists($resultado, 'getMessage')) ? $resultado->getMessage() : null;

            $this->registrarLog(
                $tipo, (int)$template['id'], $para, $assunto,
                $sucesso ? 'enviado' : 'erro',
                (int)$provedor['id'], $msgId, $erro, $vars
            );

            if (class_exists('LogService') && !$sucesso) {
                LogService::warning("email_transacional[$tipo] falha para $para: $erro");
            }

            return $sucesso;

        } catch (Throwable $e) {
            $this->registrarLog($tipo, (int)$template['id'], $para, $assunto, 'erro',
                (int)($provedor['id'] ?? 0), null, $e->getMessage(), $vars);
            if (class_exists('LogService')) {
                LogService::error("email_transacional[$tipo]: " . $e->getMessage());
            }
            return false;
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function buscarTemplate(string $tipo): ?array
    {
        $nome = self::$mapa[$tipo] ?? null;
        if (!$nome) return null;

        $st = $this->db->prepare(
            "SELECT * FROM email_templates
             WHERE nome = :nome AND tipo = 'transacional' AND status = 'ativo'
             ORDER BY versao DESC LIMIT 1"
        );
        $st->execute([':nome' => $nome]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function buscarProvedor(): ?array
    {
        // Primeiro tenta o provedor padrão ativo
        $st = $this->db->query(
            "SELECT * FROM email_provedores WHERE ativo = 1 AND padrao = 1 LIMIT 1"
        );
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;

        // Qualquer provedor ativo
        $st = $this->db->query(
            "SELECT * FROM email_provedores WHERE ativo = 1 LIMIT 1"
        );
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function buildProvider(array $provedor): object
    {
        $creds = json_decode($provedor['credenciais'] ?? '{}', true) ?: [];

        // Descriptografa se necessário
        if (class_exists('EncryptionHelper') && is_array($creds)) {
            array_walk_recursive($creds, function (&$v) {
                if (is_string($v) && strpos($v, 'enc1:') === 0) {
                    $v = EncryptionHelper::decrypt(substr($v, 5));
                }
            });
        }

        $config = array_merge($provedor, ['credenciais_decoded' => $creds]);

        $map = [
            'mailgun'  => 'MailgunEmailProvider',
            'ses'      => 'SesEmailProvider',
            'sendgrid' => 'SendgridEmailProvider',
            'brevo'    => 'BrevoEmailProvider',
            'smtp'     => 'SmtpEmailProvider',
        ];

        $class = $map[strtolower($provedor['tipo'])] ?? null;
        if (!$class || !class_exists($class)) {
            throw new RuntimeException("Provider '{$provedor['tipo']}' não encontrado");
        }

        return new $class($config);
    }

    private function registrarLog(
        string  $tipo,
        ?int    $templateId,
        string  $destinatario,
        string  $assunto,
        string  $status,
        ?int    $provedorId,
        ?string $providerMsgId,
        ?string $erroDetalhe,
        array   $vars = []
    ): void {
        try {
            // Remove dados sensíveis do log
            $contexto = $vars;
            foreach (['senha', 'password', 'token', 'secret'] as $campo) {
                unset($contexto[$campo]);
            }

            $this->db->prepare(
                "INSERT INTO email_transacionais_log
                 (tipo, template_id, destinatario, assunto, status,
                  provedor_id, provider_msg_id, erro_detalhe, contexto_json)
                 VALUES (:tp, :tid, :dest, :ass, :st, :prov, :pmid, :err, :ctx)"
            )->execute([
                ':tp'   => $tipo,
                ':tid'  => $templateId,
                ':dest' => $destinatario,
                ':ass'  => $assunto,
                ':st'   => $status,
                ':prov' => $provedorId,
                ':pmid' => $providerMsgId,
                ':err'  => $erroDetalhe,
                ':ctx'  => !empty($contexto) ? json_encode($contexto, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (Throwable $e) {
            // Log não pode quebrar o fluxo principal
        }

        // No final de registrarLog(), após o INSERT em email_transacionais_log:
        if (class_exists('CanalLogService')) {
            CanalLogService::gravar('email', $tipo, [
                'destinatario'    => $destinatario,
                'cliente_id'      => null, // email transacional não tem sempre
                'assunto'         => $assunto,
                'preview'         => mb_substr($assunto, 0, 100),
                'template_id'     => $templateId,
                'status'          => $status === 'enviado' ? 'enviado' : 'erro',
                'provider_msg_id' => $providerMsgId,
                'erro_detalhe'    => $erroDetalhe,
                'via'             => 'transacional',
            ]);
        }
    }

    // =========================================================================
    // CONSULTAS ADMIN
    // =========================================================================

    public function logRecente(int $limit = 100, ?string $tipo = null): array
    {
        $where = $tipo ? "WHERE tipo = :t" : "";
        $params = $tipo ? [':t' => $tipo] : [];
        $st = $this->db->prepare(
            "SELECT l.*, t.nome AS template_nome
             FROM email_transacionais_log l
             LEFT JOIN email_templates t ON t.id = l.template_id
             $where
             ORDER BY l.id DESC LIMIT $limit"
        );
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function kpis(): array
    {
        $r = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                SUM(status='enviado') AS enviados,
                SUM(status='erro') AS erros,
                SUM(status='sem_template') AS sem_template,
                COUNT(DISTINCT tipo) AS tipos_distintos
             FROM email_transacionais_log
             WHERE criado_em > DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetch(PDO::FETCH_ASSOC);
        return $r ?: [];
    }

    public function kpisPorTipo(): array
    {
        return $this->db->query(
            "SELECT tipo,
                    COUNT(*) AS total,
                    SUM(status='enviado') AS enviados,
                    SUM(status='erro') AS erros,
                    MAX(criado_em) AS ultimo_envio
             FROM email_transacionais_log
             WHERE criado_em > DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY tipo ORDER BY total DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Retorna o mapa de tipos para usar na view admin. */
    public static function getMapa(): array
    {
        return self::$mapa;
    }
}
