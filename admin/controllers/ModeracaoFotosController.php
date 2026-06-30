<?php
declare(strict_types=1);

class ModeracaoFotosController extends Controller {

    public function __construct() {
        AuthHelper::requireAdmin();
    }

    public function index(): void {
        $filtro = $_GET['filtro'] ?? 'pendentes';

        $whereStatus = match ($filtro) {
            'aprovadas'  => "f.status_moderacao = 'aprovada'",
            'rejeitadas' => "f.status_moderacao = 'rejeitada'",
            default      => "f.status_moderacao = 'pendente'",
        };

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->query(
            "SELECT f.*,
                    cv.apelido AS moto_apelido,
                    cv.ano     AS moto_ano,
                    mm.nome    AS montadora_nome,
                    mo.nome    AS modelo_nome,
                    u.nome     AS cliente_nome,
                    u.email    AS cliente_email,
                    c.insta_cliente
            FROM cliente_veiculo_fotos f
            JOIN cliente_veiculos cv  ON cv.id = f.veiculo_id
            JOIN clientes c           ON c.id  = f.cliente_id
            JOIN usuarios u           ON u.id  = c.usuario_id
            JOIN moto_montadoras mm   ON mm.id = cv.montadora_id
            LEFT JOIN moto_modelos mo ON mo.id = cv.modelo_id
            WHERE f.visibilidade = 'publico'
            AND {$whereStatus}
            ORDER BY f.criado_em "
            . ($filtro === 'pendentes' ? 'ASC' : 'DESC')
        );
        $pendentes = $stmt->fetchAll();

        // Conta sempre pendentes pra badge
        $totalPendentes = (int)$db->query(
            "SELECT COUNT(*) FROM cliente_veiculo_fotos
            WHERE visibilidade='publico' AND status_moderacao='pendente'"
        )->fetchColumn();

        $this->render('moderacao-fotos/index', [
            'pendentes'      => $pendentes,
            'totalPendentes' => $totalPendentes,
            'filtroAtual'    => $filtro,
        ], 'admin');
    }

    public function aprovar(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db = Database::getInstance()->getConnection();
        $db->prepare(
            "UPDATE cliente_veiculo_fotos
             SET status_moderacao = 'aprovada',
                 motivo_rejeicao  = NULL,
                 moderado_em      = NOW(),
                 moderado_por     = ?
             WHERE id = ?"
        )->execute([Session::get('admin_id'), $id]);

        $this->json(['ok' => true]);
    }

    public function rejeitar(): void {
        $this->verifyCsrf();
        $id     = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $motivo = SecurityHelper::sanitizeString($_POST['motivo'] ?? '');

        if (!$id || !$motivo) {
            $this->json(['ok' => false, 'msg' => 'Motivo obrigatório.']);
        }

        $db = Database::getInstance()->getConnection();
        $db->prepare(
            "UPDATE cliente_veiculo_fotos
             SET status_moderacao = 'rejeitada',
                 motivo_rejeicao  = ?,
                 moderado_em      = NOW(),
                 moderado_por     = ?
             WHERE id = ?"
        )->execute([$motivo, Session::get('admin_id'), $id]);

        $this->json(['ok' => true]);
    }
}