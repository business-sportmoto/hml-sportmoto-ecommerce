<?php
declare(strict_types=1);

// admin/controllers/PerguntasController.php

class PerguntasController extends Controller {

    private EmailTransacionalService $mailTransService;    

    public function __construct() {
        AuthHelper::requireAdmin();
        $this->mailTransService = new EmailTransacionalService();
    }

    public function index(): void {
        $db     = Database::getInstance()->getConnection();
        $filtro = $_GET['status'] ?? 'aguardando_admin';

        $statusValidos = ['aguardando_admin', 'respondida', 'aguardando_ia', 'rejeitada'];
        if (!in_array($filtro, $statusValidos)) $filtro = 'aguardando_admin';

        $stmt = $db->prepare(
            "SELECT pp.*,
                    p.nome AS produto_nome, p.slug AS produto_slug
             FROM produto_perguntas pp
             JOIN produtos p ON p.id = pp.produto_id
             WHERE pp.status = ?
             ORDER BY pp.criado_em " . ($filtro === 'aguardando_admin' ? 'ASC' : 'DESC') . "
             LIMIT 100"
        );
        $stmt->execute([$filtro]);
        $perguntas = $stmt->fetchAll();

        $contadores = [];
        foreach ($statusValidos as $s) {
            $st = $db->prepare("SELECT COUNT(*) FROM produto_perguntas WHERE status = ?");
            $st->execute([$s]);
            $contadores[$s] = (int)$st->fetchColumn();
        }

        $this->render('perguntas/index', [
            'perguntas'  => $perguntas,
            'filtro'     => $filtro,
            'contadores' => $contadores,
        ], 'admin');
    }

    public function responder(): void {
        $this->verifyCsrf();
        $id       = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $resposta = trim((string)($_POST['resposta'] ?? ''));

        if (!$id || mb_strlen($resposta) < 10) {
            $this->json(['ok' => false, 'msg' => 'Resposta muito curta.']);
        }
        if (mb_strlen($resposta) > 2000) {
            $this->json(['ok' => false, 'msg' => 'Resposta muito longa (máx. 2000 chars).']);
        }

        $adminId = (int)Session::get('admin_id');
        $perg    = (new Pergunta())->salvarRespostaAdmin($id, $resposta, $adminId);

        if ($perg && !$perg['notificado']) {            
            $this->enviarWhatsappCliente($perg, $resposta);

            $this->mailTransService->enviar('pergunta_respondida', $perg['autor_email'], $perg['autor_nome'], [                
                'produto_nome'  => $perg['produto_nome'],
                'produto_url'   => BASE_URL.'/produto/'.$perg['produto_slug'].'#qa-section',
                'produto_img'   => ImageHelper::getPrincipal($perg['produto_id']),
            ]);

            Database::getInstance()->getConnection()->prepare(
                "UPDATE produto_perguntas SET notificado = 1 WHERE id = ?"
            )->execute([$id]);
        }

        $this->json(['ok' => true, 'msg' => 'Resposta enviada e cliente notificado.']);
    }

    public function rejeitar(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        Database::getInstance()->getConnection()->prepare(
            "UPDATE produto_perguntas
             SET status = 'rejeitada', visivel = 0 WHERE id = ?"
        )->execute([$id]);

        $this->json(['ok' => true]);
    }

    private function enviarWhatsappCliente(array $perg, string $resposta): void {
        if (empty($perg['autor_telefone'])) return;

        $mensagem = sprintf(
            "Olá %s! Sua pergunta sobre '%s' foi respondida:\n\n" .
            "Sua pergunta: %s\n\nResposta: %s\n\n" .
            "Veja mais em: %s/produto/%s#perguntas",
            $perg['autor_nome'],
            $perg['produto_nome'],
            $perg['pergunta'],
            $resposta,
            BASE_URL,
            $perg['produto_slug']
        );

        try {
            if($perg['autor_telefone'] && !is_null($perg['autor_telefone'])) {
                WhatsappService::sendTemplate('55'.$perg['autor_telefone'], 'alerta_pergunta', 'alerta_pergunta', [
                    // MetaCloudService::headerTexto('15421'),
                    MetaCloudService::body($perg['autor_nome']),
                    MetaCloudService::botaoUrl(0, 'produto/'.$perg['produto_slug'].'#qa-section'), // sufixo do botão URL
                ]);
            }
        } catch (\Throwable $e) {
            error_log('WhatsApp cliente error: ' . $e->getMessage());
        }
    }

    private function enviarEmailCliente(array $perg, string $resposta): void {
        $url = BASE_URL . '/produto/' . $perg['produto_slug'] . '#perguntas';

        $html = sprintf(
            '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">
                <h2 style="color:#0f172a;">Olá, %s!</h2>
                <p style="color:#475569;line-height:1.6;">
                    Sua pergunta sobre <strong>%s</strong> foi respondida:
                </p>
                <div style="background:#f8fafc;padding:16px;border-radius:8px;
                            border-left:3px solid #2563eb;margin:16px 0;">
                    <p style="color:#64748b;margin:0 0 8px;font-size:13px;">
                        <strong>Sua pergunta:</strong>
                    </p>
                    <p style="color:#0f172a;margin:0;">%s</p>
                </div>
                <div style="background:#eff6ff;padding:16px;border-radius:8px;margin:16px 0;">
                    <p style="color:#1e40af;margin:0 0 8px;font-size:13px;font-weight:700;">
                        Resposta:
                    </p>
                    <p style="color:#0f172a;margin:0;line-height:1.6;">%s</p>
                </div>
                <p style="margin:24px 0;">
                    <a href="%s" style="background:#0f172a;color:#fff;padding:12px 24px;
                                       border-radius:8px;text-decoration:none;display:inline-block;">
                        Ver no site
                    </a>
                </p>
            </div>',
            View::e($perg['autor_nome']),
            View::e($perg['produto_nome']),
            View::e($perg['pergunta']),
            nl2br(View::e($resposta)),
            View::e($url)
        );

        try {
            MailHelper::enviar(
                $perg['autor_email'],
                'Sua pergunta foi respondida — ' . $perg['produto_nome'],
                $html,
                true // HTML
            );
        } catch (\Throwable $e) {
            error_log('Email cliente error: ' . $e->getMessage());
        }
    }
}