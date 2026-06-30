<?php
/**
 * admin/controllers/EmailListAdminController.php
 */
// class EmailListAdminController extends Controller
// {
//     /** @var EmailList */
//     private $model;

//     public function __construct()
//     {
//         // parent::__construct();
//         $this->requirePermission();
//         $this->model = new EmailList();
//     }

//     private function requirePermission()
//     {
//         if (method_exists('AuthHelper', 'requirePermission')) {
//             try { AuthHelper::requirePermission('email_marketing'); return; } catch (Throwable $e) {}
//         }
//         if (method_exists('AuthHelper', 'requireAdminLevel')) {
//             AuthHelper::requireAdminLevel(); return;
//         }
//         AuthHelper::requireAdmin();
//     }

//     public function index()
//     {
//         $itens = $this->model->all(false);
//         $this->render('email-marketing/listas/index', [
//             'itens' => $itens,
//             'titulo' => 'Listas de Email',
//         ], 'admin');
//     }

//     public function salvar()
//     {
//         $this->verifyCsrf();
//         $id = (int)($_POST['id'] ?? 0);
//         $nome = trim((string)($_POST['nome'] ?? ''));
//         if ($nome === '') {
//             return $this->json(['ok' => false, 'erro' => 'Nome é obrigatório']);
//         }
//         try {
//             $novoId = $this->model->save([
//                 'id' => $id,
//                 'nome' => $nome,
//                 'descricao' => trim((string)($_POST['descricao'] ?? '')) ?: null,
//                 'ativo' => !empty($_POST['ativo']) ? 1 : 0,
//             ]);
//             return $this->json(['ok' => true, 'id' => $novoId]);
//         } catch (Throwable $e) {
//             return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
//         }
//     }

//     public function excluir()
//     {
//         $this->verifyCsrf();
//         $id = (int)($_POST['id'] ?? 0);
//         if (!$id) return $this->json(['ok' => false, 'erro' => 'ID inválido']);
//         try {
//             $this->model->delete($id);
//             return $this->json(['ok' => true]);
//         } catch (Throwable $e) {
//             return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
//         }
//     }
// }


// <?php
/**
 * admin/controllers/EmailListAdminController.php
 *
 * Versão ATUALIZADA: contém os métodos originais + detalhes, busca,
 * adicionar (manual/lote/CSV) e remover contatos.
 *
 * Substitua o arquivo existente por este.
 */
class EmailListAdminController extends Controller
{
    /** @var EmailList */
    private $model;

    public function __construct()
    {
        // parent::__construct();
        $this->requirePermission();
        $this->model = new EmailList();
    }

    private function requirePermission()
    {
        if (method_exists('AuthHelper', 'requirePermission')) {
            try { AuthHelper::requirePermission('email_marketing'); return; } catch (Throwable $e) {}
        }
        if (method_exists('AuthHelper', 'requireAdminLevel')) {
            AuthHelper::requireAdminLevel(); return;
        }
        AuthHelper::requireAdmin();
    }

    public function index()
    {
        $itens = $this->model->all(false);
        $this->render('email-marketing/listas/index', [
            'itens' => $itens,
            'titulo' => 'Listas de Email',
        ], 'admin');
    }

    public function salvar()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        if ($nome === '') {
            return $this->json(['ok' => false, 'erro' => 'Nome é obrigatório']);
        }
        try {
            $novoId = $this->model->save([
                'id' => $id,
                'nome' => $nome,
                'descricao' => trim((string)($_POST['descricao'] ?? '')) ?: null,
                'ativo' => !empty($_POST['ativo']) ? 1 : 0,
            ]);
            return $this->json(['ok' => true, 'id' => $novoId]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function excluir()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) return $this->json(['ok' => false, 'erro' => 'ID inválido']);
        try {
            $this->model->delete($id);
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // ===== MÉTODOS NOVOS =====================================================
    // =========================================================================

    /**
     * GET /admin/email-marketing/listas/{id}
     * Tela de detalhes com contatos paginados + ações.
     */
    public function detalhes($id)
    {
        $id = (int)$id;
        $lista = $this->model->find($id);
        if (!$lista) {
            header('Location: ' . BASE_URL . '/admin/email-marketing/listas');
            exit;
        }

        $filtros = [
            'busca'          => trim((string)($_GET['busca'] ?? '')),
            'status_contato' => $_GET['status_contato'] ?? '',
        ];
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));

        $resultado = $this->model->contatosDaLista($id, $filtros, $pagina, 50);

        $this->render('email-marketing/listas/detalhes', [
            'lista'     => $lista,
            'resultado' => $resultado,
            'filtros'   => $filtros,
            'titulo'    => 'Lista: ' . $lista['nome'],
        ], 'admin');
    }

    /**
     * POST /admin/email-marketing/listas/buscar-contatos
     * AJAX para autocomplete do modal "Adicionar contato".
     */
    public function buscarContatos()
    {
        $this->verifyCsrf();
        $listaId = (int)($_POST['lista_id'] ?? 0);
        $busca   = trim((string)($_POST['busca'] ?? ''));
        if (!$listaId) return $this->json(['ok' => false, 'erro' => 'ID inválido']);

        try {
            $itens = $this->model->buscarContatosDisponiveis($listaId, $busca, 20);
            return $this->json(['ok' => true, 'itens' => $itens]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage(). ' - file:'.$e->getFile(). ' - line:'.$e->getLine()]);
        }
    }

    /**
     * POST /admin/email-marketing/listas/adicionar-contato
     * Adiciona um único contato (já existente) à lista.
     */
    public function adicionarContato()
    {
        $this->verifyCsrf();
        $listaId = (int)($_POST['lista_id'] ?? 0);
        $contatoId = (int)($_POST['contato_id'] ?? 0);
        if (!$listaId || !$contatoId) {
            return $this->json(['ok' => false, 'erro' => 'Parâmetros inválidos']);
        }
        try {
            $this->model->adicionarContato($listaId, $contatoId);
            if (class_exists('LogService')) {
                LogService::audit('email_lista_add_contato', ['lista' => $listaId, 'contato' => $contatoId]);
            }
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    /**
     * POST /admin/email-marketing/listas/adicionar-em-lote
     * Recebe lista de emails (textarea, 1 por linha) — cria contatos se
     * necessário e adiciona à lista.
     */
    public function adicionarEmLote()
    {
        $this->verifyCsrf();
        $listaId = (int)($_POST['lista_id'] ?? 0);
        $emailsRaw = trim((string)($_POST['emails'] ?? ''));
        if (!$listaId || $emailsRaw === '') {
            return $this->json(['ok' => false, 'erro' => 'Informe a lista e os emails']);
        }

        $linhas = preg_split('/[\r\n,;]+/', $emailsRaw);
        $emails = [];
        foreach ($linhas as $linha) {
            $email = strtolower(trim($linha));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = true;
            }
        }
        $emails = array_keys($emails);

        if (!$emails) {
            return $this->json(['ok' => false, 'erro' => 'Nenhum email válido encontrado']);
        }

        try {
            $contactModel = new EmailContact();
            $supressoes = new EmailSuppression();
            $contatoIds = [];
            $stats = ['contatos_criados' => 0, 'suprimidos' => 0];

            foreach ($emails as $email) {
                if ($supressoes->isSuppressed($email)) {
                    $stats['suprimidos']++;
                    continue;
                }
                $c = $contactModel->findByEmail($email);
                if ($c) {
                    $contatoIds[] = (int)$c['id'];
                } else {
                    $novoId = $contactModel->upsert([
                        'email'      => $email,
                        'origem'     => 'admin',
                        'base_legal' => 'consentimento',
                        'status'     => 'ativo',
                    ]);
                    if ($novoId) {
                        $contatoIds[] = $novoId;
                        $stats['contatos_criados']++;
                    }
                }
            }

            $r = $this->model->adicionarEmLote($listaId, $contatoIds);
            $r = array_merge($stats, $r, ['total_emails' => count($emails)]);

            if (class_exists('LogService')) {
                LogService::audit('email_lista_add_lote', array_merge(['lista' => $listaId], $r));
            }
            return $this->json(['ok' => true, 'resultado' => $r]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    /**
     * POST /admin/email-marketing/listas/remover-contato
     * Soft-remove: pivot fica com status='removido'.
     */
    public function removerContato()
    {
        $this->verifyCsrf();
        $listaId = (int)($_POST['lista_id'] ?? 0);
        $contatoId = (int)($_POST['contato_id'] ?? 0);
        if (!$listaId || !$contatoId) {
            return $this->json(['ok' => false, 'erro' => 'Parâmetros inválidos']);
        }
        try {
            $this->model->removerContato($listaId, $contatoId);
            if (class_exists('LogService')) {
                LogService::audit('email_lista_rm_contato', ['lista' => $listaId, 'contato' => $contatoId]);
            }
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    /**
     * POST /admin/email-marketing/listas/importar-csv
     * Upload multipart de CSV.
     */
    public function importarCsv()
    {
        $this->verifyCsrf();
        $listaId = (int)($_POST['lista_id'] ?? 0);
        if (!$listaId) {
            return $this->json(['ok' => false, 'erro' => 'ID da lista inválido']);
        }
        if (empty($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            return $this->json(['ok' => false, 'erro' => 'Arquivo CSV não enviado']);
        }
        $tmp = $_FILES['arquivo']['tmp_name'];
        $nomeOrig = $_FILES['arquivo']['name'];

        // Validação simples de extensão
        $ext = strtolower(pathinfo($nomeOrig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            return $this->json(['ok' => false, 'erro' => 'Envie um arquivo .csv']);
        }

        // Tamanho razoável
        if ($_FILES['arquivo']['size'] > 10 * 1024 * 1024) {
            return $this->json(['ok' => false, 'erro' => 'Arquivo muito grande (limite 10MB)']);
        }

        try {
            $stats = $this->model->importarCsv($listaId, $tmp);

            // Registra a importação no histórico (se a tabela existir)
            try {
                $importModel = new EmailImport();
                $importModel->criar([
                    'lista_id'    => $listaId,
                    'arquivo'     => $nomeOrig,
                    'total_linhas'    => $stats['total_linhas'],
                    'inseridos'   => $stats['adicionados_lista'],
                    'duplicados'  => $stats['duplicados'] + $stats['ja_estavam_lista'],
                    'invalidos'   => $stats['emails_invalidos'],
                    'criado_por'  => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
                    'status'      => 'concluido',
                ]);
            } catch (Throwable $e) {
                // se EmailImport não tiver método criar com esta assinatura, ignora
            }

            if (class_exists('LogService')) {
                LogService::audit('email_lista_import_csv',
                    array_merge(['lista' => $listaId, 'arquivo' => $nomeOrig], $stats));
            }
            return $this->json(['ok' => true, 'resultado' => $stats]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }
}
