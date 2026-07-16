<?php
/**
 * app/controllers/NotificacaoController.php
 *
 * Endpoints do modal e badge — serve CLIENTE e ADMIN com o mesmo código.
 * O destinatário é resolvido pela sessão, nunca por parâmetro
 * (evita um usuário ler notificações de outro).
 *
 * Rotas:
 *   GET  /notificacoes/contador       → { ok, total }           (badge, polling)
 *   GET  /notificacoes/listar         → { ok, itens, tem_mais } (modal)
 *   POST /notificacoes/marcar-lida    → { ok }
 *   POST /notificacoes/marcar-todas   → { ok, marcadas }
 */
class NotificacaoController extends Controller
{

    private PDO $db;

    public function __construct() {
        AuthHelper::requireAdmin(); // bloqueia se não for admin
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Resolve o destinatário logado (admin tem prioridade se ambos na sessão).
     * @return array{0:string,1:int}|null  [tipo, id] ou null se não logado
     */
    private function destinatario(): ?array
    {
        // AJUSTE conforme suas chaves de sessão reais
        $adminId = (int)(Session::get('admin_id') ?? 0);
        if ($adminId > 0){

        $stmt = $this->db->prepare(
            "SELECT 
                u.id                          
             FROM admins a
             LEFT JOIN usuarios u ON u.id = a.usuario_id
             LEFT JOIN clientes c ON c.usuario_id = a.usuario_id
             WHERE a.id = ?"
        );
        $stmt->execute([$adminId]);

        $obj = $stmt->fetch();

            return ['admin', $obj['id']];
        }

        $clienteId = (int)(Session::get('cliente_id') ?? 0);
        if ($clienteId > 0) return ['cliente', $clienteId];

        return null;
    }

    // GET /notificacoes/contador — polling do badge
    public function contador(): void
    {
        $dest = $this->destinatario();
        if (!$dest) { $this->json(['ok' => false, 'total' => 0]); return; }

        [$tipo, $id] = $dest;
        $this->json([
            'ok'    => true,
            'total' => NotificacaoService::contarNaoLidas($tipo, $id),
            // 'teste'=>$dest
        ]);
    }

    // GET /notificacoes/listar?categoria=&apenas_nao_lidas=&pagina=
    public function listar(): void
    {
        $dest = $this->destinatario();
        if (!$dest) { $this->json(['ok' => false, 'itens' => []]); return; }

        [$tipo, $id] = $dest;

        $limite = 20;
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));

        $itens = NotificacaoService::listar($tipo, $id, [
            'categoria'        => $_GET['categoria'] ?? null,
            'apenas_nao_lidas' => !empty($_GET['apenas_nao_lidas']),
            'limite'           => $limite + 1,   // +1 para saber se tem mais
            'offset'           => ($pagina - 1) * $limite,
        ]);

        $temMais = count($itens) > $limite;
        if ($temMais) array_pop($itens);

        // Formata data relativa para o modal
        foreach ($itens as &$it) {
            $it['tempo'] = $this->tempoRelativo($it['recebido_em']);
        }
        unset($it);

        $this->json([
            'ok'         => true,
            'itens'      => $itens,
            'tem_mais'   => $temMais,
            'categorias' => NotificacaoService::LABELS_CATEGORIA,
        ]);
    }

    // POST /notificacoes/marcar-lida  { nu_id }
    public function marcarLida(): void
    {
        $this->verifyCsrf();
        $dest = $this->destinatario();
        if (!$dest) { $this->json(['ok' => false]); return; }

        [$tipo, $id] = $dest;
        $nuId = (int)($_POST['nu_id'] ?? 0);

        $this->json(['ok' => NotificacaoService::marcarLida($nuId, $tipo, $id)]);
    }

    // POST /notificacoes/marcar-todas
    public function marcarTodas(): void
    {
        $this->verifyCsrf();
        $dest = $this->destinatario();
        if (!$dest) { $this->json(['ok' => false]); return; }

        [$tipo, $id] = $dest;
        $marcadas = NotificacaoService::marcarTodasLidas($tipo, $id);

        $this->json(['ok' => true, 'marcadas' => $marcadas]);
    }

    // =========================================================================

    private function tempoRelativo(string $datetime): string
    {
        $ts   = strtotime($datetime);
        $diff = time() - $ts;

        if ($diff < 60)      return 'agora';
        if ($diff < 3600)    return floor($diff / 60) . ' min';
        if ($diff < 86400)   return floor($diff / 3600) . ' h';
        if ($diff < 172800)  return 'ontem';
        if ($diff < 604800)  return floor($diff / 86400) . ' dias';
        return date('d/m/Y', $ts);
    }
}
