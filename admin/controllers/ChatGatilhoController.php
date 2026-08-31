<?php
/**
 * admin/controllers/ChatGatilhoController.php
 *
 * Gatilhos: palavra-chave, boas-vindas, resposta padrão e referência.
 *
 * Rotas:
 *   GET  /admin/chat/gatilhos            → listagem + formulário
 *   POST /admin/chat/gatilhos/salvar     → cria ou edita
 *   POST /admin/chat/gatilhos/{id}/ativo → liga/desliga
 *   POST /admin/chat/gatilhos/{id}/excluir
 *   POST /admin/chat/gatilhos/simular    → testa uma frase contra a régua
 */
class ChatGatilhoController extends Controller
{
    private ChatGatilhoService $svc;

    public function __construct()
    {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->svc = new ChatGatilhoService();
    }

    public function index(): void
    {
        $this->render('chat/gatilhos', [
            'titulo'   => 'Chat — Gatilhos',
            'gatilhos' => $this->svc->listar(),
            'fluxos'   => (new ChatFluxoAdminService())->listarPublicados(),
            'tags'     => (new ChatContatoService())->listarTags(),
        ], 'admin');
    }

    public function salvar(): void
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0) ?: null;

        $r = $this->svc->salvar([
            'nome'          => $_POST['nome']          ?? '',
            'tipo'          => $_POST['tipo']          ?? 'palavra_chave',
            'padrao'        => $_POST['padrao']        ?? '',
            'modo_match'    => $_POST['modo_match']    ?? 'contem',
            'acao'          => $_POST['acao']          ?? 'fluxo',
            'fluxo_id'      => $_POST['fluxo_id']      ?? 0,
            'mensagem'      => $_POST['mensagem']      ?? '',
            'tag_id'        => $_POST['tag_id']        ?? 0,
            'prioridade'    => $_POST['prioridade']    ?? 50,
            'ativo'         => !empty($_POST['ativo']),
            'so_fora_fluxo' => !empty($_POST['so_fora_fluxo']),
        ], $id);

        $this->json($r);
    }

    public function alternarAtivo($id): void
    {
        $this->verifyCsrf();
        $this->svc->alternarAtivo(SecurityHelper::sanitizeInt($id));
        $this->json(['ok' => true]);
    }

    public function excluir($id): void
    {
        $this->verifyCsrf();
        $this->svc->excluir(SecurityHelper::sanitizeInt($id));
        $this->json(['ok' => true]);
    }

    /**
     * Simulador: qual gatilho responderia a esta frase?
     * Deixa testar a régua sem gastar mensagem nem cobaia.
     */
    public function simular(): void
    {
        $this->verifyCsrf();
        $texto = trim((string)($_POST['texto'] ?? ''));
        if ($texto === '') { $this->json(['ok' => false, 'erro' => 'Escreva uma frase para testar.']); return; }

        $this->json([
            'ok'        => true,
            'resultado' => $this->svc->simular($texto, !empty($_POST['primeira_mensagem'])),
        ]);
    }
}
