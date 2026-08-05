<?php
/**
 * RastreioController (admin) — lista, detalhe e atualização manual de rastreios.
 * Permissão em cascata; CSRF nos POSTs.
 */
class RastreioController extends Controller
{
    private RastreioService $rastreios;

    public function __construct()
    {
        AuthHelper::requirePermission('logistica');
        $this->rastreios = new RastreioService();
    }

    public function index(): void
    {
        $this->render('logistica/rastreios', [
            'titulo'          => 'Rastreios',
            'transportadoras' => $this->transportadoras(),
            'filtros'         => $this->filtros(),
        ], 'admin');
    }

    public function dados(): void
    {
        $res = $this->rastreios->listar($this->filtros(), max(1, (int)($_GET['pagina'] ?? 1)));
        $res['ok'] = true;
        $this->json($res);
    }

    public function obter(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $r = $id > 0 ? $this->rastreios->obter($id) : null;
        if (!$r) { $this->json(['ok' => false, 'erro' => 'Rastreio não encontrado.']); return; }
        // external_id é interno — não expõe no detalhe da tela.
        unset($r['external_id']);
        $this->json(['ok' => true, 'rastreio' => $r, 'eventos' => $this->rastreios->timeline($id)]);
    }

    public function atualizar(): void
    {
        $this->verifyCsrf();
        $this->json($this->rastreios->atualizar((int)($_POST['id'] ?? 0)));
    }

    /* ---------------- helpers ---------------- */

    private function filtros(): array
    {
        $out = [];
        if (!empty($_GET['status']))            $out['status'] = (string)$_GET['status'];
        if (!empty($_GET['transportadora_id'])) $out['transportadora_id'] = (int)$_GET['transportadora_id'];
        if (!empty($_GET['atraso']))            $out['atraso'] = 1;
        if (!empty($_GET['ocorrencia']))        $out['ocorrencia'] = 1;
        if (!empty($_GET['busca']))             $out['busca'] = trim((string)$_GET['busca']);
        return $out;
    }

    private function transportadoras(): array
    {
        try {
            return Database::getInstance()->getConnection()
                ->query("SELECT id, nome FROM log_transportadoras WHERE status = 'ativo' ORDER BY prioridade ASC, id ASC")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
