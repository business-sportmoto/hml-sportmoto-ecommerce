<?php
/**
 * admin/controllers/ChatContatoController.php
 *
 * Base de contatos (subscribers) e tags.
 *
 * Rotas:
 *   GET  /admin/chat/contatos              → listagem com filtros
 *   GET  /admin/chat/contatos/{id}         → ficha do contato
 *   POST /admin/chat/contatos/{id}/salvar  → edita dados básicos
 *   POST /admin/chat/contatos/{id}/tag     → aplica/remove tag
 *   POST /admin/chat/contatos/{id}/campo   → grava campo personalizado
 *   POST /admin/chat/contatos/{id}/optin   → opt-in / opt-out
 *   POST /admin/chat/contatos/{id}/vincular→ liga a um cliente da loja
 *   POST /admin/chat/contatos/criar        → cria contato manualmente
 *   GET  /admin/chat/contatos/exportar     → CSV do segmento filtrado
 *   GET  /admin/chat/tags                  → gestão de tags
 *   POST /admin/chat/tags/salvar|excluir
 */
class ChatContatoController extends Controller
{
    private ChatContatoService $svc;

    public function __construct()
    {
        AuthHelper::requireAdminLevel('super', 'gerente', 'vendedor');
        $this->svc = new ChatContatoService();
    }

    /** Escrita é restrita: vendedor consulta, gestor altera a base. */
    private function exigirGestao(): void
    {
        AuthHelper::requireAdminLevel('super', 'gerente');
    }

    // =========================================================================
    // LISTAGEM
    // =========================================================================

    public function index(): void
    {
        $f      = $this->filtrosDaRequisicao();
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $r      = $this->svc->listar($f, $pagina, 30);

        $this->render('chat/contatos', [
            'titulo'    => 'Chat — Contatos',
            'contatos'  => $r['itens'],
            'total'     => $r['total'],
            'pagina'    => $pagina,
            'porPagina' => 30,
            'filtros'   => $f,
            'tags'      => $this->svc->listarTags(),
            'podeGerir' => AuthHelper::hasLevel('super', 'gerente'),
        ], 'admin');
    }

    private function filtrosDaRequisicao(): array
    {
        return [
            'busca'       => trim((string)($_GET['q'] ?? '')),
            'optin'       => $_GET['optin'] ?? '',
            'janela'      => (string)($_GET['janela'] ?? ''),
            'com_cliente' => $_GET['com_cliente'] ?? '',
            'origem'      => trim((string)($_GET['origem'] ?? '')),
            'tags'        => array_filter(array_map('intval', (array)($_GET['tags'] ?? []))),
            'tags_modo'   => (string)($_GET['tags_modo'] ?? 'qualquer'),
            'desde'       => trim((string)($_GET['desde'] ?? '')),
            'ate'         => trim((string)($_GET['ate'] ?? '')),
        ];
    }

    public function show($id): void
    {
        $id = SecurityHelper::sanitizeInt($id);
        $contato = $this->svc->obter($id);
        if (!$contato) { http_response_code(404); echo 'Contato não encontrado.'; return; }

        $conversa = (new ChatConversaService())->obterPorContato($id);
        $db = Database::getInstance()->getConnection();

        // Histórico de fluxos deste contato
        $st = $db->prepare(
            "SELECT s.*, f.nome AS fluxo_nome FROM chat_sessoes s
             LEFT JOIN chat_fluxos f ON f.id = s.fluxo_id
             WHERE s.contato_id = :c ORDER BY s.id DESC LIMIT 20"
        );
        $st->execute([':c' => $id]);
        $sessoes = $st->fetchAll(PDO::FETCH_ASSOC);

        $this->render('chat/contato-show', [
            'titulo'    => 'Contato — ' . $contato['nome_exibicao'],
            'contato'   => $contato,
            'conversa'  => $conversa,
            'sessoes'   => $sessoes,
            'notas'     => $this->svc->notas($id),
            'tags'      => $this->svc->listarTags(),
            'cliente'   => $this->dadosDoCliente($contato['cliente_id'] ?? null),
            'podeGerir' => AuthHelper::hasLevel('super', 'gerente'),
        ], 'admin');
    }

    private function dadosDoCliente(?int $clienteId): ?array
    {
        if (!$clienteId) return null;
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT c.id, u.nome, u.email, c.cpf, c.celular, c.criado_em,
                        (SELECT COUNT(*) FROM pedidos p WHERE p.cliente_id = c.id) AS pedidos,
                        (SELECT COALESCE(SUM(p.total), 0) FROM pedidos p WHERE p.cliente_id = c.id) AS gasto
                 FROM clientes c
                 JOIN usuarios u ON u.id = c.usuario_id
                 WHERE c.id = :id LIMIT 1"
            );
            $st->execute([':id' => $clienteId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // =========================================================================
    // EDIÇÃO
    // =========================================================================

    public function salvar($id): void
    {
        $this->verifyCsrf();
        $this->exigirGestao();
        $id = SecurityHelper::sanitizeInt($id);

        $this->svc->atualizar($id, [
            'nome'   => trim((string)($_POST['nome'] ?? '')),
            'email'  => trim((string)($_POST['email'] ?? '')),
            'idioma' => trim((string)($_POST['idioma'] ?? 'pt_BR')),
        ]);
        $this->json(['ok' => true, 'contato' => $this->svc->obter($id)]);
    }

    public function criar(): void
    {
        $this->verifyCsrf();
        $this->exigirGestao();

        $tel  = ChatMetaClient::normalizarNumero((string)($_POST['telefone'] ?? ''));
        if ($tel === '' || strlen($tel) < 12) {
            $this->json(['ok' => false, 'erro' => 'Informe um telefone válido com DDD.']); return;
        }

        $contato = $this->svc->garantir($tel, [
            'nome'   => trim((string)($_POST['nome'] ?? '')) ?: null,
            'email'  => trim((string)($_POST['email'] ?? '')) ?: null,
            'origem' => 'manual',
        ]);

        // Contato criado à mão nunca tem janela aberta — deixar isso explícito
        // evita a surpresa de "criei e não consigo mandar mensagem".
        $this->json([
            'ok'      => true,
            'id'      => (int)$contato['id'],
            'aviso'   => 'Contato criado. Como ele ainda não escreveu para a loja, '
                       . 'só é possível enviar template aprovado até que ele responda.',
        ]);
    }

    public function tag($id): void
    {
        $this->verifyCsrf();
        $this->exigirGestao();
        $id    = SecurityHelper::sanitizeInt($id);
        $tagId = (int)($_POST['tag_id'] ?? 0);
        if ($tagId < 1) { $this->json(['ok' => false, 'erro' => 'Tag inválida.']); return; }

        if ((string)($_POST['acao'] ?? 'adicionar') === 'remover') {
            $this->svc->removerTag($id, $tagId);
        } else {
            $this->svc->aplicarTag($id, $tagId, AuthHelper::usuarioId());
        }
        $this->json(['ok' => true, 'tags' => $this->svc->tagsDo($id)]);
    }

    public function campo($id): void
    {
        $this->verifyCsrf();
        $this->exigirGestao();
        $id = SecurityHelper::sanitizeInt($id);

        $chave = trim((string)($_POST['chave'] ?? ''));
        if ($chave === '') { $this->json(['ok' => false, 'erro' => 'Informe o nome do campo.']); return; }

        $this->svc->setCampo($id, $chave, $_POST['valor'] ?? null);
        $c = $this->svc->obter($id);
        $this->json(['ok' => true, 'campos' => $c['campos'] ?? []]);
    }

    public function optin($id): void
    {
        $this->verifyCsrf();
        $this->exigirGestao();
        $id = SecurityHelper::sanitizeInt($id);

        if (!empty($_POST['optin'])) {
            $this->svc->optIn($id);
        } else {
            $this->svc->optOut($id, 'ação manual do admin');
        }
        $this->json(['ok' => true, 'contato' => $this->svc->obter($id)]);
    }

    public function bloquear($id): void
    {
        $this->verifyCsrf();
        $this->exigirGestao();
        $id = SecurityHelper::sanitizeInt($id);
        $this->svc->atualizar($id, ['bloqueado' => !empty($_POST['bloqueado'])]);
        $this->json(['ok' => true, 'contato' => $this->svc->obter($id)]);
    }

    public function vincular($id): void
    {
        $this->verifyCsrf();
        $this->exigirGestao();
        $id = SecurityHelper::sanitizeInt($id);

        $clienteId = (int)($_POST['cliente_id'] ?? 0);
        if ($clienteId > 0) $this->svc->vincularCliente($id, $clienteId);
        else                $this->svc->desvincularCliente($id);

        $this->json(['ok' => true, 'contato' => $this->svc->obter($id)]);
    }

    /** Autocomplete de clientes da loja, para o vínculo manual. */
    public function buscarClientes(): void
    {
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) { $this->json(['ok' => true, 'itens' => []]); return; }

        if (SecurityHelper::rateLimitByIp('chat_busca_cliente', 60, 60)) {
            $this->json(['ok' => false, 'erro' => 'Muitas buscas. Aguarde um instante.'], 429); return;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT c.id, u.nome, u.email, c.celular
                 FROM clientes c JOIN usuarios u ON u.id = c.usuario_id
                 WHERE u.nome LIKE :q1 OR u.email LIKE :q2 OR c.celular LIKE :q3 OR c.cpf LIKE :q4
                 ORDER BY u.nome LIMIT 15"
            );
            $t = '%' . $q . '%';
            $st->execute([':q1' => $t, ':q2' => $t, ':q3' => $t, ':q4' => $t]);
            $this->json(['ok' => true, 'itens' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'itens' => []]);
        }
    }

    public function excluirNota($id): void
    {
        $this->verifyCsrf();
        $this->exigirGestao();
        $this->svc->excluirNota(SecurityHelper::sanitizeInt($id));
        $this->json(['ok' => true]);
    }

    // =========================================================================
    // EXPORTAÇÃO
    // =========================================================================

    /** CSV do segmento filtrado. Streaming — não monta tudo em memória. */
    public function exportar(): void
    {
        $this->exigirGestao();

        $f   = $this->filtrosDaRequisicao();
        $ids = $this->svc->idsDoSegmento($f, 50000);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contatos-chat-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // BOM: o Excel abre acentos corretamente

        fputcsv($out, ['ID', 'Telefone', 'Nome', 'E-mail', 'Cliente ID', 'Opt-in',
                       'Bloqueado', 'Janela aberta', 'Tags', 'Origem',
                       'Recebidas', 'Enviadas', 'Cadastrado em'], ';');

        foreach (array_chunk($ids, 300) as $lote) {
            foreach ($lote as $cid) {
                $c = $this->svc->obter((int)$cid);
                if (!$c) continue;
                fputcsv($out, [
                    $c['id'],
                    $c['wa_id'],
                    $c['nome_exibicao'],
                    $c['email'],
                    $c['cliente_id'],
                    (int)$c['optin'] === 1 ? 'sim' : 'não',
                    (int)$c['bloqueado'] === 1 ? 'sim' : 'não',
                    $c['na_janela'] ? 'sim' : 'não',
                    implode(', ', array_column($c['tags'], 'nome')),
                    $c['origem'],
                    $c['total_entrada'],
                    $c['total_saida'],
                    $c['criado_em'],
                ], ';');
            }
            flush();
        }
        fclose($out);
        exit;
    }

    // =========================================================================
    // TAGS
    // =========================================================================

    public function tags(): void
    {
        $this->render('chat/tags', [
            'titulo'    => 'Chat — Tags',
            'tags'      => $this->svc->listarTags(),
            'podeGerir' => AuthHelper::hasLevel('super', 'gerente'),
        ], 'admin');
    }

    public function tagSalvar(): void
    {
        $this->verifyCsrf();
        $this->exigirGestao();

        $nome = trim((string)($_POST['nome'] ?? ''));
        if ($nome === '') { $this->json(['ok' => false, 'erro' => 'Informe o nome da tag.']); return; }

        $id = $this->svc->criarTag($nome, (string)($_POST['cor'] ?? '#2563eb'),
                                   trim((string)($_POST['descricao'] ?? '')) ?: null);

        $this->json($id ? ['ok' => true, 'id' => $id] : ['ok' => false, 'erro' => 'Falha ao criar a tag.']);
    }

    public function tagExcluir($id): void
    {
        $this->verifyCsrf();
        $this->exigirGestao();
        $this->svc->excluirTag(SecurityHelper::sanitizeInt($id));
        $this->json(['ok' => true]);
    }
}
