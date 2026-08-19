<?php
declare(strict_types=1);

/**
 * app/controllers/ConsentController.php
 *
 * Recebe a escolha do banner e delega ao ConsentService (grava
 * evidência LGPD + seta o cookie). Endpoint único e simples.
 *
 * Rota: Router::post('/consent/salvar', 'ConsentController@salvar');
 */
final class ConsentController extends Controller
{
    public function salvar(): void
    {
        // CSRF — o banner envia window.CSRF_TOKEN
        $this->verifyCsrf();

        $analytics = ($_POST['analytics'] ?? '0') === '1';
        $marketing = ($_POST['marketing'] ?? '0') === '1';
        $acao      = $_POST['acao'] ?? 'personalizado';

        // Valida a ação contra a whitelist (não confia no cliente)
        $acoesValidas = ['aceitou_tudo', 'recusou_tudo', 'personalizado'];
        if (!in_array($acao, $acoesValidas, true)) {
            $acao = 'personalizado';
        }

        // cliente_id se logado (costura consentimento à conta)
        $clienteId = Session::getClienteId() ?: null;

        (new ConsentService())->registrar($analytics, $marketing, $acao, $clienteId);

        $this->json(['ok' => true]);
    }
}