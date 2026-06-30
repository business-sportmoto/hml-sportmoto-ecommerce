<?php
declare(strict_types=1);

class VeiculoController extends Controller {

    private VeiculoService $svc;

    public function __construct() {
        $this->svc = new VeiculoService();
    }

    // POST /meu-veiculo/salvar
    public function salvar(): void {
        $this->verifyCsrf();

        $montadoraId = SecurityHelper::sanitizeInt($_POST['montadora_id'] ?? 0);
        $modeloId    = SecurityHelper::sanitizeInt($_POST['modelo_id']    ?? 0) ?: null;
        $ano         = SecurityHelper::sanitizeInt($_POST['ano']           ?? 0) ?: null;
        $apelido     = SecurityHelper::sanitizeString($_POST['apelido']    ?? '');

        if (!$montadoraId) {
            $this->json(['ok' => false, 'msg' => 'Selecione uma montadora.']);
        }

        $ok = $this->svc->salvar($montadoraId, $modeloId, $ano, $apelido);

        if ($ok) {
            $veiculo = $this->svc->getAtivo();
            $this->json([
                'ok'      => true,
                'msg'     => 'Veículo salvo!',
                'veiculo' => $veiculo,
            ]);
        } else {
            $this->json(['ok' => false, 'msg' => 'Montadora inválida.']);
        }
    }

    // POST /meu-veiculo/remover
    public function remover(): void {
        $this->verifyCsrf();
        $this->svc->remover();
        $this->json(['ok' => true]);
    }

    // GET /meu-veiculo/status — para o JS atualizar a UI
    public function status(): void {
        $this->json([
            'ok'      => true,
            'veiculo' => $this->svc->getAtivo(),
        ]);
    }

    // Ajax: modelos de uma montadora (para o seletor)
    public function ajaxModelos(): void {
        $montadoraId = SecurityHelper::sanitizeInt($_GET['montadora_id'] ?? 0);
        if (!$montadoraId) $this->json([]);

        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT id, nome, slug FROM moto_modelos
             WHERE montadora_id = ? AND ativo = 1 ORDER BY nome ASC"
        );
        $stmt->execute([$montadoraId]);
        $this->json($stmt->fetchAll());
    }

    // Ajax: anos de um modelo
    public function ajaxAnos(): void {
        $modeloId = SecurityHelper::sanitizeInt($_GET['modelo_id'] ?? 0);
        if (!$modeloId) $this->json([]);

        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT DISTINCT ano FROM moto_anos WHERE modelo_id = ? ORDER BY ano DESC"
        );
        $stmt->execute([$modeloId]);
        $this->json($stmt->fetchAll());
    }
}