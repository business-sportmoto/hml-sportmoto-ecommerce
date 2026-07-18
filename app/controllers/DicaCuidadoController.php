<?php
/**
 * app/controllers/DicaCuidadoController.php
 *
 * GET /dica/{id}
 *
 * O clique na dica de cuidado é a PONTE entre o subsistema de vida útil e o
 * motor de fluxos: registra o evento `dica_cuidado_clicada` no stream e manda
 * o cliente para a página do produto.
 *
 * Quem clicou demonstrou interesse — é esse sinal (e só ele) que autoriza um
 * fluxo a fazer a abordagem comercial. Quem não clicou não é incomodado.
 *
 * ROTA (app/config/routes.php):
 *   Router::get('/dica/{id}', 'DicaCuidadoController@abrir');
 */
class DicaCuidadoController extends Controller
{
    public function abrir(): void
    {
        $id = (int)($this->params['id'] ?? $_GET['id'] ?? 0);
        $base = defined('BASE_URL') ? BASE_URL : '';

        if ($id <= 0) { $this->redirect($base . '/'); return; }

        // A dica só existe para quem tem conta — a notificação é in-app
        if (!Session::isClienteLogado()) {
            $this->redirect($base . '/login');
            return;
        }
        $clienteId = (int)Session::get('cliente_id');

        $svc = new VidaUtilService();
        $agenda = $svc->registrarClique($id, $clienteId, $_COOKIE['sm_vt'] ?? null);

        if (!$agenda) { $this->redirect($base . '/'); return; }

        // Produto ainda existe → página dele; senão, home
        if (!empty($agenda['produto_slug'])) {
            $this->redirect($base . '/produto/' . $agenda['produto_slug']);
            return;
        }
        if (!empty($agenda['produto_id'])) {
            $this->redirect($base . '/produto/' . (int)$agenda['produto_id']);
            return;
        }
        $this->redirect($base . '/');
    }
}
