<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/EmailService.php
//
// Envio de e-mails transacionais via mail() nativo.
// Configurar em config.php:
//   define('MAIL_FROM',      'noreply@seusite.com.br');
//   define('MAIL_FROM_NAME', 'Nome da Loja');
//   define('SITE_NOME',      'Nome da Loja');
// ════════════════════════════════════════════════════════

class EmailService {

    private string $from;
    private string $fromName;
    private string $siteNome;

    public function __construct() {
        $this->from     = defined('MAIL_FROM')      ? MAIL_FROM      : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $this->fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : (defined('SITE_NOME') ? SITE_NOME : 'Loja');
        $this->siteNome = defined('SITE_NOME')      ? SITE_NOME      : 'Loja';
    }

    // ════════════════════════════════════════════════════
    // TEMPLATES DE PEDIDO
    // ════════════════════════════════════════════════════

    /**
     * Notifica o cliente sobre mudança de status do pedido.
     */
    public function statusPedido(array $pedido, string $novoStatus, ?string $observacao = null): bool {
        $statusLabels = [
            'aguardando_pagamento' => 'Aguardando pagamento',
            'pagamento_aprovado'   => 'Pagamento aprovado',
            'em_separacao'         => 'Pedido em separação',
            'enviado'              => 'Pedido enviado',
            'entregue'             => 'Pedido entregue',
            'cancelado'            => 'Pedido cancelado',
            'troca_devolucao'      => 'Troca/Devolução em processo',
        ];

        $statusLabel = $statusLabels[$novoStatus] ?? $novoStatus;
        $assunto     = "Pedido #{$pedido['codigo']} — {$statusLabel}";
        $corpo       = $this->templateStatusPedido($pedido, $novoStatus, $statusLabel, $observacao);

        return $this->enviar($pedido['cliente_email'], $assunto, $corpo);
    }

    /**
     * Confirmação de pedido criado manualmente pelo admin.
     */
    public function pedidoCriado(array $pedido): bool {
        $assunto = "Novo pedido #{$pedido['codigo']} recebido — {$this->siteNome}";
        $corpo   = $this->templatePedidoCriado($pedido);
        return $this->enviar($pedido['cliente_email'], $assunto, $corpo);
    }

    /**
     * Notifica código de rastreio disponível.
     */
    public function rastreioAdicionado(array $pedido): bool {
        $assunto = "Seu pedido #{$pedido['codigo']} foi enviado!";
        $corpo   = $this->templateRastreio($pedido);
        return $this->enviar($pedido['cliente_email'], $assunto, $corpo);
    }

    // ════════════════════════════════════════════════════
    // TEMPLATES HTML
    // ════════════════════════════════════════════════════

    private function templateStatusPedido(
        array $pedido, string $status, string $statusLabel, ?string $obs
    ): string {
        $cor = match($status) {
            'pagamento_aprovado','entregue','em_separacao' => '#16a34a',
            'cancelado'                                     => '#dc2626',
            'enviado'                                       => '#2563eb',
            default                                         => '#d97706',
        };

        $iconePath = match($status) {
            'pagamento_aprovado' => '✓',
            'enviado'            => '🚚',
            'entregue'           => '🏠',
            'cancelado'          => '✗',
            default              => '⏳',
        };

        $rastreioBlock = '';
        if ($status === 'enviado' && !empty($pedido['codigo_rastreio'])) {
            $rastreioBlock = "
            <div style='background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 16px;margin:16px 0;'>
                <p style='margin:0 0 6px;font-size:13px;font-weight:700;color:#1e40af;'>Código de rastreio</p>
                <code style='font-size:15px;font-weight:900;color:#1e3a8a;letter-spacing:1px;'>{$pedido['codigo_rastreio']}</code>
                <br>
                <a href='https://rastreamento.correios.com.br/app/index.php?ot={$pedido['codigo_rastreio']}'
                   style='font-size:12px;color:#2563eb;' target='_blank'>
                   Rastrear nos Correios →
                </a>
            </div>";
        }

        $obsBlock = $obs ? "
            <div style='background:#f9fafb;border-left:3px solid {$cor};padding:12px 14px;margin:16px 0;border-radius:0 6px 6px 0;'>
                <p style='margin:0;font-size:13.5px;color:#374151;'>{$obs}</p>
            </div>" : '';

        return $this->baseTemplate("Atualização do pedido #{$pedido['codigo']}", "
            <div style='text-align:center;margin-bottom:24px;'>
                <div style='width:56px;height:56px;background:{$cor};border-radius:50%;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;font-size:24px;line-height:56px;'>
                    <span style='color:white;font-size:22px;'>{$iconePath}</span>
                </div>
                <h2 style='margin:0;font-size:20px;font-weight:900;color:#111827;'>{$statusLabel}</h2>
                <p style='margin:6px 0 0;font-size:14px;color:#6b7280;'>
                    Pedido <strong>#{$pedido['codigo']}</strong>
                </p>
            </div>
            {$rastreioBlock}
            {$obsBlock}
            <div style='text-align:center;margin-top:20px;'>
                <a href='" . BASE_URL . "/minha-conta/pedido/{$pedido['id']}'
                   style='display:inline-block;background:#0f172a;color:#fff;padding:12px 28px;border-radius:9px;font-weight:700;font-size:14px;text-decoration:none;'>
                    Ver detalhes do pedido
                </a>
            </div>
        ");
    }

    private function templatePedidoCriado(array $pedido): string {
        return $this->baseTemplate("Pedido #{$pedido['codigo']} criado", "
            <div style='text-align:center;margin-bottom:24px;'>
                <h2 style='margin:0;font-size:20px;font-weight:900;color:#111827;'>
                    Pedido recebido!
                </h2>
                <p style='margin:6px 0 0;font-size:14px;color:#6b7280;'>
                    Código: <strong>#{$pedido['codigo']}</strong>
                </p>
            </div>
            <p style='font-size:14px;color:#374151;line-height:1.6;'>
                Olá, <strong>{$pedido['cliente_nome']}</strong>!
                Seu pedido foi registrado com sucesso. Em breve você receberá atualizações sobre o status.
            </p>
            <div style='text-align:center;margin-top:20px;'>
                <a href='" . BASE_URL . "/minha-conta/pedido/{$pedido['id']}'
                   style='display:inline-block;background:#0f172a;color:#fff;padding:12px 28px;border-radius:9px;font-weight:700;font-size:14px;text-decoration:none;'>
                    Acompanhar pedido
                </a>
            </div>
        ");
    }

    private function templateRastreio(array $pedido): string {
        return $this->baseTemplate("Seu pedido foi enviado!", "
            <div style='text-align:center;margin-bottom:24px;'>
                <span style='font-size:40px;'>🚚</span>
                <h2 style='margin:8px 0 0;font-size:20px;font-weight:900;color:#111827;'>
                    Pedido #{$pedido['codigo']} enviado!
                </h2>
            </div>
            <div style='background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:16px;text-align:center;'>
                <p style='margin:0 0 6px;font-size:12px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:.5px;'>
                    Código de rastreio
                </p>
                <code style='font-size:18px;font-weight:900;color:#1e3a8a;letter-spacing:2px;'>
                    {$pedido['codigo_rastreio']}
                </code>
            </div>
            <div style='text-align:center;margin-top:20px;'>
                <a href='https://rastreamento.correios.com.br/app/index.php?ot={$pedido['codigo_rastreio']}'
                   style='display:inline-block;background:#2563eb;color:#fff;padding:12px 28px;border-radius:9px;font-weight:700;font-size:14px;text-decoration:none;margin-right:8px;'>
                    Rastrear nos Correios
                </a>
                <a href='" . BASE_URL . "/minha-conta/pedido/{$pedido['id']}'
                   style='display:inline-block;background:#f1f5f9;color:#374151;padding:12px 28px;border-radius:9px;font-weight:700;font-size:14px;text-decoration:none;'>
                    Ver pedido
                </a>
            </div>
        ");
    }

    /**
     * Template base HTML para todos os e-mails.
     */
    private function baseTemplate(string $titulo, string $conteudo): string {
        $ano  = date('Y');
        $site = $this->siteNome;
        $url  = defined('BASE_URL') ? BASE_URL : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$titulo}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:600px;width:100%;">
        <!-- Header -->
        <tr>
          <td style="background:#0f172a;padding:24px 32px;text-align:center;">
            <a href="{$url}" style="color:#fff;font-size:18px;font-weight:900;text-decoration:none;">
              {$site}
            </a>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:32px;">
            {$conteudo}
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:20px 32px;text-align:center;">
            <p style="margin:0;font-size:12px;color:#9ca3af;">
              © {$ano} {$site}. Todos os direitos reservados.<br>
              <a href="{$url}" style="color:#6b7280;">{$url}</a>
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    // ════════════════════════════════════════════════════
    // ENVIO
    // ════════════════════════════════════════════════════

    /**
     * Envia o e-mail via mail() nativo.
     */
    public function enviar(
        string  $para,
        string  $assunto,
        string  $corpo,
        ?string $template  = null,
        ?int    $clienteId = null
    ): bool {
        if (empty($para)) return false;

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($this->fromName) . "?= <{$this->from}>\r\n";
        $headers .= "Reply-To: {$this->from}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

        $assutoCodificado = "=?UTF-8?B?" . base64_encode($assunto) . "?=";
        $result = @mail($para, $assutoCodificado, $corpo, $headers);
        $erro   = $result ? null : (error_get_last()['message'] ?? 'Falha desconhecida');

        if (!$result) {
            error_log("[EmailService] Falha ao enviar para {$para} — assunto: {$assunto}");
        }

        // ── Log de envio ─────────────────────────────────
        try {
            Database::getInstance()->getConnection()->prepare(
                "INSERT INTO emails_log
                 (cliente_id, destinatario, template, assunto, status, erro)
                 VALUES (?,?,?,?,?,?)"
            )->execute([
                $clienteId,
                $para,
                $template,
                $assunto,
                $result ? 'enviado' : 'erro',
                $erro,
            ]);
        } catch (\Throwable $e) {
            error_log("[EmailService] Falha ao logar e-mail: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * E-mail personalizado enviado pelo admin para o cliente.
     */
    public function enviarPersonalizado(array $cliente, string $assunto, string $mensagem): bool {
        $nome      = $cliente['nome'] ?? '';
        $email     = $cliente['email'] ?? '';
        $clienteId = (int)($cliente['cliente_id'] ?? $cliente['id'] ?? 0) ?: null;
        $corpo     = $this->baseTemplate($assunto, "
            <p style='font-size:14px;color:#374151;line-height:1.6;'>
                Olá, <strong>{$nome}</strong>!
            </p>
            <div style='font-size:14px;color:#374151;line-height:1.8;white-space:pre-wrap;'>
                " . htmlspecialchars($mensagem) . "
            </div>
        ");
        return $this->enviar($email, $assunto, $corpo, 'personalizado', $clienteId);
    }

    // ════════════════════════════════════════════════════
    // TEMPLATES DE DEVOLUÇÃO
    // ════════════════════════════════════════════════════

    public function devolucaoCriada(array $sol, array $pedido): bool {
        $assunto = "Solicitação de {$sol['tipo']} #{$sol['id']} recebida — Pedido #{$pedido['codigo']}";
        $corpo   = $this->baseTemplate("Solicitação recebida", "
            <p style='font-size:14px;color:#374151;line-height:1.6;'>
                Recebemos sua solicitação de <strong>{$sol['tipo']}</strong> referente ao pedido
                <strong>#{$pedido['codigo']}</strong>. Nossa equipe irá analisar em breve.
            </p>
            <div style='text-align:center;margin-top:20px;'>
                <a href='" . BASE_URL . "/minha-conta/devolucao/{$sol['id']}'
                   style='display:inline-block;background:#0f172a;color:#fff;padding:12px 28px;border-radius:9px;font-weight:700;font-size:14px;text-decoration:none;'>
                    Acompanhar solicitação
                </a>
            </div>
        ");
        return $this->enviar($sol['cliente_email'], $assunto, $corpo);
    }

    public function devolucaoAprovada(array $sol, array $pedido): bool {
        $codigo  = $sol['codigo_postagem_reversa'] ?? '—';
        $assunto = "Devolução aprovada — Código de postagem disponível";
        $corpo   = $this->baseTemplate("Devolução aprovada! 📦", "
            <p style='font-size:14px;color:#374151;line-height:1.6;'>
                Sua solicitação foi <strong>aprovada</strong>! Use o código abaixo para postar o produto
                em qualquer agência dos Correios <strong>gratuitamente</strong>.
            </p>
            <div style='background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:12px;padding:20px;text-align:center;margin:16px 0;'>
                <p style='margin:0 0 8px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#15803d;'>Código de postagem reversa</p>
                <code style='font-size:24px;font-weight:900;color:#0f172a;letter-spacing:2px;'>{$codigo}</code>
                " . (!empty($sol['codigo_validade_dias']) ? "<p style='margin:8px 0 0;font-size:12px;color:#6b7280;'>Válido por {$sol['codigo_validade_dias']} dias</p>" : "") . "
            </div>
            <div style='text-align:center;'>
                <a href='" . BASE_URL . "/minha-conta/devolucao/{$sol['id']}'
                   style='display:inline-block;background:#0f172a;color:#fff;padding:12px 28px;border-radius:9px;font-weight:700;font-size:14px;text-decoration:none;'>
                    Ver detalhes
                </a>
            </div>
        ");
        return $this->enviar($sol['cliente_email'], $assunto, $corpo);
    }

    public function devolucaoNegada(array $sol, array $pedido, string $motivo): bool {
        $assunto = "Atualização sobre sua solicitação de devolução — Pedido #{$pedido['codigo']}";
        $corpo   = $this->baseTemplate("Sobre sua solicitação", "
            <p style='font-size:14px;color:#374151;line-height:1.6;'>
                Infelizmente não foi possível aprovar sua solicitação de {$sol['tipo']}.
            </p>
            <div style='background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:14px 16px;margin:16px 0;'>
                <p style='margin:0;font-size:13.5px;color:#991b1b;'>{$motivo}</p>
            </div>
            <p style='font-size:13.5px;color:#6b7280;'>
                Dúvidas? Entre em contato pelo nosso <a href='" . BASE_URL . "/contato'>formulário de contato</a>.
            </p>
        ");
        return $this->enviar($sol['cliente_email'], $assunto, $corpo);
    }

    public function itemRecebido(array $sol, array $pedido, string $prazoInspecao): bool {
        $assunto = "Item recebido — Inspeção em andamento";
        $prazoFmt = date('d/m/Y', strtotime($prazoInspecao));
        $corpo   = $this->baseTemplate("Item recebido! 🔍", "
            <p style='font-size:14px;color:#374151;line-height:1.6;'>
                Recebemos o produto referente à sua solicitação #{$sol['id']}.
                A inspeção será concluída até <strong>{$prazoFmt}</strong> e você receberá uma notificação.
            </p>
        ");
        return $this->enviar($sol['cliente_email'], $assunto, $corpo);
    }

    public function inspecaoResultado(array $sol, array $pedido, string $resultado): bool {
        $aprovado = $resultado === 'aprovado';
        $assunto  = $aprovado ? "Inspeção aprovada — Reembolso em processamento" : "Resultado da inspeção";
        $cor      = $aprovado ? '#16a34a' : '#dc2626';
        $msg      = $aprovado
            ? "Seu produto foi inspecionado e <strong>aprovado</strong>! O reembolso de <strong>" . PriceHelper::format((float)$sol['valor_aprovado']) . "</strong> será processado em breve."
            : "Infelizmente o produto não passou na inspeção." . (!empty($sol['inspecao_observacao']) ? " Motivo: " . View::e($sol['inspecao_observacao']) : '');
        $corpo   = $this->baseTemplate("Resultado da inspeção", "
            <div style='background:" . ($aprovado ? '#f0fdf4' : '#fef2f2') . ";border-radius:10px;padding:16px;margin-bottom:16px;'>
                <p style='margin:0;font-size:14px;color:{$cor};'>{$msg}</p>
            </div>
        ");
        return $this->enviar($sol['cliente_email'], $assunto, $corpo);
    }

    public function devolucaoConcluida(array $sol, array $pedido, string $tipoReembolso, float $valor): bool {
        $assunto = "Devolução concluída — Reembolso processado";
        $modos   = ['credito'=>'crédito na sua conta','pix'=>'Pix','gateway'=>'estorno no cartão','boleto_manual'=>'transferência bancária'];
        $modo    = $modos[$tipoReembolso] ?? $tipoReembolso;
        $corpo   = $this->baseTemplate("Reembolso processado ✓", "
            <div style='text-align:center;margin-bottom:20px;'>
                <div style='font-size:28px;'>✅</div>
                <h2 style='margin:8px 0;font-size:20px;font-weight:900;color:#0f172a;'>Devolução concluída!</h2>
            </div>
            <p style='font-size:14px;color:#374151;line-height:1.6;text-align:center;'>
                Seu reembolso de <strong>" . PriceHelper::format($valor) . "</strong>
                foi processado via <strong>{$modo}</strong>.
            </p>
            " . ($tipoReembolso === 'credito' ? "<p style='font-size:13px;color:#6b7280;text-align:center;'>O saldo estará disponível na sua conta para uso nas próximas compras.</p>" : "") . "
        ");
        return $this->enviar($sol['cliente_email'], $assunto, $corpo);
    }

}