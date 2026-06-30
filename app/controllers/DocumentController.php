<?php
// app/controllers/DocumentController.php

class DocumentController extends Controller {

    private DocumentService $docService;

    public function __construct() {
        $this->docService = new DocumentService();
    }

    // ── Upload via desktop ────────────────────────────────────

    public function upload(): void {
        AuthHelper::requireCustomer();
        $this->verifyCsrf();

        $clienteId = (int) Session::getClienteId();
        $tipo      = in_array($_POST['tipo'] ?? '', ['rg','cnh']) ? $_POST['tipo'] : 'rg';

        if (empty($_FILES['documento']['name'])) {
            $this->json(['ok' => false, 'msg' => 'Nenhum arquivo enviado.']);
        }

        $resultado = $this->docService->processUpload($clienteId, $_FILES['documento'], $tipo);

        // Se verificado, atualiza dados da sessão
        if ($resultado['ok'] && $resultado['status'] === 'verificado') {
            Session::set('cliente_verificado', true);
        }

        $this->json($resultado);
    }

    // ── Gerar QR code para upload mobile ─────────────────────

    public function generateQr(): void {
        AuthHelper::requireCustomer();

        $clienteId = (int) Session::getClienteId();
        $resultado = $this->docService->gerarTokenMobile($clienteId);

        $this->json($resultado);
    }

    // ── Polling: verifica se mobile já enviou ─────────────────

    public function checkStatus(): void {
        AuthHelper::requireCustomer();

        $clienteId = (int) Session::getClienteId();
        $status    = $this->docService->getStatus($clienteId);
        $result    = $this->docService->checkMobileResult($clienteId);

        $this->json([
            'ok'         => true,
            'status'     => $status['status'],
            'verificado' => $status['verificado'],
            'msg'        => $result['motivo_rejeicao'] ?? null,
            'score'      => $result['score_analise']   ?? null,
        ]);
    }

    // ── Página mobile: tirar foto ─────────────────────────────

    public function mobileForm(string $token): void {
        $clienteId = $this->docService->validarTokenMobile($token);

        if (!$clienteId) {
            $this->render('verify/expired', [], 'minimal');
            return;
        }

        SeoHelper::setTitle('Enviar documento');
        SeoHelper::setRobots('noindex, nofollow');

        $this->render('verify/mobile', [
            'token' => $token,
        ], 'minimal');
    }

    // ── Upload via mobile (formulário sem sessão) ─────────────

    public function mobileUpload(): void {
        $this->verifyCsrf();

        $token     = $_POST['token'] ?? '';
        $clienteId = $this->docService->validarTokenMobile($token);

        if (!$clienteId) {
            $this->json(['ok' => false, 'msg' => 'Link expirado. Gere um novo QR code.']);
        }

        $tipo = in_array($_POST['tipo'] ?? '', ['rg','cnh']) ? $_POST['tipo'] : 'rg';

        if (empty($_FILES['documento']['name'])) {
            $this->json(['ok' => false, 'msg' => 'Nenhuma foto enviada.']);
        }

        $resultado = $this->docService->processUpload($clienteId, $_FILES['documento'], $tipo);
        $this->json($resultado);
    }
}