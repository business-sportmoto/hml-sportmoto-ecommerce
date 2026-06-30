<?php
// ════════════════════════════════════════════════════════
// app/controllers/HistoryController.php
// POST /historico/registrar — beacon sem bloquear página
// ════════════════════════════════════════════════════════
class HistoryController extends Controller {

    public function registrar(): void {
        $tipo       = $_POST['tipo']       ?? '';
        $referencia = $_POST['referencia'] ?? '';

        if (empty($tipo) || empty($referencia)) {
            $this->json(['ok' => false]);
        }

        $clienteId  = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
        $sessionKey = PersonalizationService::getSessionKey();

        $svc = new PersonalizationService($clienteId, $sessionKey);
        $svc->registrar($tipo, (string)$referencia);

        $this->json(['ok' => true]);
    }
}

// Rota: Router::post('/historico/registrar', 'HistoryController@registrar');