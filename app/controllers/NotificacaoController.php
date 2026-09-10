<?php
/**
 * app/controllers/NotificacaoController.php
 *
 * Endpoints do sino — badge, lista e marcação de leitura.
 *
 * ── DUAS CAIXAS, NUNCA MISTURADAS ───────────────────────────────
 *
 * O site e o painel têm caixas SEPARADAS. O que é informação da loja fica na
 * loja; o que é operação do painel fica no painel. Uma mesma pessoa pode ser
 * admin e cliente ao mesmo tempo (CLAUDE.md §4.1) — e nesse caso ela tem
 * DUAS caixas, não uma.
 *
 * Quem decide de qual caixa se trata é a ROTA, não a sessão:
 *
 *   /notificacoes/*          → sempre a caixa do CLIENTE   (config/routes.php)
 *   /admin/notificacoes/*    → sempre a caixa do ADMIN     (admin/config/routes.php)
 *
 * ── O QUE HAVIA ANTES ───────────────────────────────────────────
 *
 * Uma resolução só, com `admin_id` tendo prioridade:
 *
 *     $adminId = Session::get('admin_id');
 *     if ($adminId > 0) return ['admin', ...];     // vencia sempre
 *     $clienteId = Session::get('cliente_id');
 *     if ($clienteId > 0) return ['cliente', ...];
 *
 * Resultado: quem era admin e cliente via, no sino do SITE, as notificações
 * do PAINEL — "Pedido novo #1234", "Devolução solicitada" — no lugar das
 * dela própria.
 *
 * E o construtor chamava `AuthHelper::requireAdmin()`, com o comentário
 * "bloqueia se não for admin". Como estas são as rotas do site, o sino do
 * cliente comum **nunca funcionou**: toda chamada era barrada, e o badge
 * ficava eternamente vazio sem erro visível.
 *
 * ── O DESTINATÁRIO NUNCA VEM POR PARÂMETRO ──────────────────────
 *
 * Sai da sessão, sempre. Aceitar um id do cliente aqui deixaria qualquer um
 * ler a caixa de qualquer outro trocando um número na URL.
 */
class NotificacaoController extends Controller
{
    private PDO $db;

    /** Preenchido pela porta de entrada (comoCliente / comoAdmin). */
    private ?array $dest = null;

    public function __construct()
    {
        // Sem guarda global: este controller serve as DUAS caixas, e cada
        // porta exige a sessão que lhe corresponde. Guarda de admin aqui
        // trancava o cliente para fora das próprias notificações.
        $this->db = Database::getInstance()->getConnection();
    }

    // =====================================================================
    // PORTAS — SITE (caixa do cliente)
    // =====================================================================

    /** GET /notificacoes/contador */
    public function contador(): void      { if ($this->comoCliente()) $this->respContador(); }

    /** GET /notificacoes/listar */
    public function listar(): void        { if ($this->comoCliente()) $this->respListar(); }

    /** POST /notificacoes/marcar-lida */
    public function marcarLida(): void    { if ($this->comoCliente()) $this->respMarcarLida(); }

    /** POST /notificacoes/marcar-todas */
    public function marcarTodas(): void   { if ($this->comoCliente()) $this->respMarcarTodas(); }

    // =====================================================================
    // PORTAS — PAINEL (caixa do admin)
    // =====================================================================

    /** GET /admin/notificacoes/contador */
    public function contadorAdmin(): void    { if ($this->comoAdmin()) $this->respContador(); }

    /** GET /admin/notificacoes/listar */
    public function listarAdmin(): void      { if ($this->comoAdmin()) $this->respListar(); }

    /** POST /admin/notificacoes/marcar-lida */
    public function marcarLidaAdmin(): void  { if ($this->comoAdmin()) $this->respMarcarLida(); }

    /** POST /admin/notificacoes/marcar-todas */
    public function marcarTodasAdmin(): void { if ($this->comoAdmin()) $this->respMarcarTodas(); }

    // =====================================================================
    // RESOLUÇÃO DO DESTINATÁRIO
    // =====================================================================

    /**
     * Caixa do cliente. Falso quando não há cliente logado — e aí a resposta
     * já foi enviada.
     *
     * `isClienteLogado()`, não `Session::get('cliente_id') > 0`: a flag é o
     * que o resto do site usa para dizer "está logado", e um `cliente_id`
     * remanescente numa sessão deslogada abriria a caixa de alguém.
     */
    private function comoCliente(): bool
    {
        if (!Session::isClienteLogado()) {
            $this->json(['ok' => false, 'total' => 0, 'itens' => []]);
            return false;
        }
        $this->dest = ['cliente', (int) Session::get('cliente_id')];
        return true;
    }

    /**
     * Caixa do admin.
     *
     * O destinatário é `usuarios.id`, não `admins.id`: notificação é
     * endereçada à PESSOA, e as duas tabelas numeram independente
     * (CLAUDE.md §4.1). `AuthHelper::usuarioId()` já faz essa ponte com
     * cache de sessão.
     */
    private function comoAdmin(): bool
    {
        if (!Session::isAdminLogado()) {
            $this->json(['ok' => false, 'total' => 0, 'itens' => []]);
            return false;
        }

        $usuarioId = AuthHelper::usuarioId();
        if ($usuarioId <= 0) {
            // Admin sem vínculo em `usuarios` (CLAUDE.md §4.8.4): não há a
            // quem endereçar. Caixa vazia é melhor que a de outra pessoa.
            $this->json(['ok' => false, 'total' => 0, 'itens' => []]);
            return false;
        }

        $this->dest = ['admin', $usuarioId];
        return true;
    }

    // =====================================================================
    // RESPOSTAS — comuns às duas caixas
    // =====================================================================

    private function respContador(): void
    {
        [$tipo, $id] = $this->dest;
        $this->json([
            'ok'    => true,
            'total' => NotificacaoService::contarNaoLidas($tipo, $id),
        ]);
    }

    private function respListar(): void
    {
        [$tipo, $id] = $this->dest;

        $limite = 20;
        $pagina = max(1, (int) ($_GET['pagina'] ?? 1));

        $itens = NotificacaoService::listar($tipo, $id, [
            'categoria'        => $_GET['categoria'] ?? null,
            'apenas_nao_lidas' => !empty($_GET['apenas_nao_lidas']),
            'limite'           => $limite + 1,   // +1 para saber se tem mais
            'offset'           => ($pagina - 1) * $limite,
        ]);

        $temMais = count($itens) > $limite;
        if ($temMais) array_pop($itens);

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

    private function respMarcarLida(): void
    {
        $this->verifyCsrf();
        [$tipo, $id] = $this->dest;

        // `marcarLida` valida a posse antes do UPDATE — o nu_id vem do
        // cliente e não pode ser confiado sozinho.
        $nuId = (int) ($_POST['nu_id'] ?? 0);
        $this->json(['ok' => NotificacaoService::marcarLida($nuId, $tipo, $id)]);
    }

    private function respMarcarTodas(): void
    {
        $this->verifyCsrf();
        [$tipo, $id] = $this->dest;

        $this->json([
            'ok'       => true,
            'marcadas' => NotificacaoService::marcarTodasLidas($tipo, $id),
        ]);
    }

    // =====================================================================

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
