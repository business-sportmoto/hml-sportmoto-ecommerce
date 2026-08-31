<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/AdminPedidoController.php
// ════════════════════════════════════════════════════════

class AdminPedidoController extends Controller {

    private AdminPedido        $model;
    private AdminPedidoService $service;

    public function __construct() {
        // parent::__construct();
        AuthHelper::requireAdmin();
        $this->model   = new AdminPedido();
        $this->service = new AdminPedidoService();
    }

    // ── GET /admin/pedidos ────────────────────────────────
    public function index(): void {
        $filtros = [
            'q'                => SecurityHelper::sanitizeString($_GET['q']                ?? ''),
            'status_pedido'    => SecurityHelper::sanitizeString($_GET['status_pedido']    ?? ''),
            'status_pagamento' => SecurityHelper::sanitizeString($_GET['status_pagamento'] ?? ''),
            'forma_pagamento'  => SecurityHelper::sanitizeString($_GET['forma_pagamento']  ?? ''),
            'data_de'          => SecurityHelper::sanitizeString($_GET['data_de']          ?? ''),
            'data_ate'         => SecurityHelper::sanitizeString($_GET['data_ate']         ?? ''),
        ];
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;

        $total   = $this->model->contar(array_filter($filtros));
        $pedidos = $this->model->listar(array_filter($filtros), $page, $perPage);
        $kpis    = $this->model->getKpis();
        $counts  = $this->model->getContagensPorStatus();

        $totalPages = (int)ceil($total / $perPage);

        $this->render('pedidos/index', compact(
            'pedidos','filtros','kpis','counts',
            'total','page','totalPages','perPage'
        ), 'admin');
    }

    // ── GET /admin/pedidos/{id} ───────────────────────────
    public function show(int $id): void {
        $pedido   = $this->model->findById($id);
        if (!$pedido) $this->notFound();

        $itens    = $this->model->getItens($id);
        $historico= $this->model->getHistorico($id);
        $nf       = $this->model->getNfe($id);

        // Status dinâmicos do banco
        $statusModel     = new PedidoStatus();
        $todosStatus     = $statusModel->getAtivos();
        $statusMap       = $statusModel->getMapBySlug();
        $statusDef       = $statusMap[$pedido['status_pedido']] ?? null;
        $podeEditarItens = !($statusDef['bloqueia_edicao_itens'] ?? true);

        // ── Bling sync status ─────────────────────────────
        $db = Database::getInstance()->getConnection();

        $stmtMap = $db->prepare(
            "SELECT bling_id, criado_em FROM bling_pedidos_map WHERE pedido_id = ? LIMIT 1"
        );
        $stmtMap->execute([$id]);
        $blingMap = $stmtMap->fetch() ?: null;

        $stmtLog = $db->prepare(
            "SELECT tipo, direcao, status, msg_erro, criado_em
             FROM bling_sync_log
             WHERE referencia_id IN (?, ?)
             ORDER BY criado_em DESC
             LIMIT 8"
        );
        $stmtLog->execute([$id, $pedido['codigo']]);
        $blingLogs = $stmtLog->fetchAll();

        // ── Cupom usado neste pedido ──────────────────────
        // Busca em cupom_usos (não cancelados) — inclui reservado para
        // que pedidos com pagamento pendente também mostrem o cupom,
        // já que a confirmação só ocorre após o pagamento ser aprovado.
        $stmtCupom = $db->prepare(
            "SELECT cu.id AS uso_id, cu.status AS uso_status,
                    cu.valor_desconto, cu.valor_frete_desc, cu.valor_original,
                    cu.criado_em AS uso_criado_em,
                    c.id AS cupom_id, c.codigo, c.nome, c.tipo,
                    c.descricao, c.valor AS cupom_valor
             FROM cupom_usos cu
             JOIN cupons c ON c.id = cu.cupom_id
             WHERE cu.pedido_id = ?
               AND cu.status NOT IN ('cancelado')
             ORDER BY cu.criado_em DESC
             LIMIT 1"
        );
        $stmtCupom->execute([$id]);
        $cupomUso = $stmtCupom->fetch() ?: null;

        // ── Promoções automáticas aplicadas neste pedido ──
        $stmtPromo = $db->prepare(
            "SELECT pa.id, pa.tipo_beneficio, pa.valor_desconto,
                    pa.produto_brinde_id, pa.qtd_brinde,
                    pa.detalhes, pa.criado_em,
                    p.id   AS promocao_id,
                    p.nome AS promocao_nome,
                    p.tipo AS promocao_tipo
             FROM promocao_aplicacoes pa
             JOIN promocoes p ON p.id = pa.promocao_id
             WHERE pa.pedido_id = ?
             ORDER BY pa.valor_desconto DESC"
        );
        $stmtPromo->execute([$id]);
        $promocoesAplicadas = array_map(function (array $row): array {
            if (!empty($row['detalhes']) && is_string($row['detalhes'])) {
                $row['detalhes'] = json_decode($row['detalhes'], true) ?? [];
            }
            return $row;
        }, $stmtPromo->fetchAll());

        $this->render('pedidos/show', compact(
            'pedido','itens','historico','nf',
            'todosStatus','statusMap','statusDef','podeEditarItens',
            'blingMap','blingLogs','cupomUso','promocoesAplicadas'
        ), 'admin');
    }

    // ── GET /admin/pedidos/novo ───────────────────────────
    public function novoForm(): void {
        $this->render('pedidos/novo', [], 'admin');
    }

    // ── POST /admin/pedidos/novo ──────────────────────────
    public function criarManual(): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente','atendimento');

        $dados = [
            'cliente_id'          => (int)($_POST['cliente_id']          ?? 0),
            'endereco_id'         => (int)($_POST['endereco_id']          ?? 0) ?: null,
            'forma_pagamento'     => SecurityHelper::sanitizeString($_POST['forma_pagamento'] ?? 'manual'),
            'parcelas'            => (int)($_POST['parcelas']             ?? 1),
            'frete'               => (float)str_replace(',','.',($_POST['frete']     ?? '0')),
            'desconto'            => (float)str_replace(',','.',($_POST['desconto']  ?? '0')),
            'frete_descricao'     => SecurityHelper::sanitizeString($_POST['frete_descricao']   ?? ''),
            'status_pedido'       => SecurityHelper::sanitizeString($_POST['status_pedido']     ?? 'aguardando_pagamento'),
            'status_pagamento'    => SecurityHelper::sanitizeString($_POST['status_pagamento']  ?? 'pendente'),
            'observacao_cliente'  => SecurityHelper::sanitizeString($_POST['observacao_cliente']?? ''),
            'observacao_interna'  => SecurityHelper::sanitizeString($_POST['observacao_interna']?? ''),
            'notificar_cliente'   => !empty($_POST['notificar_cliente']),
            'cartao_bandeira'     => SecurityHelper::sanitizeString($_POST['cartao_bandeira']   ?? ''),
            'cartao_ultimos_4'    => SecurityHelper::sanitizeString($_POST['cartao_ultimos_4']  ?? ''),
            'pago_em'             => SecurityHelper::sanitizeString($_POST['pago_em']           ?? ''),
        ];

        // Itens enviados como JSON
        $itensRaw = $_POST['itens'] ?? '[]';
        $itens    = json_decode($itensRaw, true) ?: [];

        if (!$dados['cliente_id']) {
            $this->json(['ok' => false, 'msg' => 'Selecione um cliente.']);
        }
        if (empty($itens)) {
            $this->json(['ok' => false, 'msg' => 'Adicione pelo menos 1 item.']);
        }

        $adminId  = (int)Session::get('admin_id');
        $resultado = $this->service->criarPedidoManual($dados, $itens, $adminId);

        if ($resultado['ok']) {
            $resultado['redirect'] = ADMIN_URL . '/pedidos/' . $resultado['pedido_id'];

            // → Bling: envia sempre após criação, independente do status
            try {
                (new BlingOrderService())->enviarPedido($resultado['pedido_id']);
            } catch (\Throwable $e) {
                error_log('[AdminPedidoController] Bling criarManual #' . $resultado['pedido_id'] . ': ' . $e->getMessage());
            }
        }
        $this->json($resultado);
    }

    // ── POST /admin/pedidos/{id}/status ───────────────────
    public function updateStatus(int $id): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente','atendimento');

        $novoStatus = SecurityHelper::sanitizeString($_POST['status_pedido'] ?? '');
        $observacao = SecurityHelper::sanitizeString($_POST['observacao']    ?? '');
        $notificar  = !empty($_POST['notificar']);
        $adminId    = (int)Session::get('admin_id');

        // Valida contra a tabela pedido_status (inclui custom + padrão)
        $statusModel = new PedidoStatus();
        $statusDef   = $statusModel->findBySlug($novoStatus);

        if (!$statusDef || !$statusDef['ativo']) {
            $this->json(['ok' => false, 'msg' => 'Status inválido ou inativo.']);
        } 
 
        $resultado = $this->service->mudarStatus($id, $novoStatus, $observacao, $adminId, $notificar);

        if ($resultado['ok']) {
            $historico = $this->model->getHistorico($id);
            $resultado['historico_count'] = count($historico);
            $resultado['status_label']    = $statusDef['label'];
            $resultado['status_cor']      = $statusDef['cor'];

            // → Bling: garante que o pedido existe no Bling (idempotente)
            // Se ainda não foi enviado, envia agora com o status atual
            // Se já existe no Bling, o webhook retorna atualizações de lá para cá
            try {
                (new BlingOrderService())->enviarPedido($id);
            } catch (\Throwable $e) {
                error_log('[AdminPedidoController] Bling updateStatus #' . $id . ': ' . $e->getMessage());
            }
        }

        $this->json($resultado);
    }

    // ── POST /admin/pedidos/{id}/rastreio ─────────────────
    public function updateRastreio(int $id): void {
        $this->verifyCsrf();

        $codigo    = strtoupper(trim(SecurityHelper::sanitizeString($_POST['codigo_rastreio'] ?? '')));
        $notificar = !empty($_POST['notificar']);

        if (empty($codigo)) {
            $this->json(['ok' => false, 'msg' => 'Informe o código de rastreio.']);
        }

        $this->json($this->service->salvarRastreio($id, $codigo, $notificar));
    }

    // ── POST /admin/pedidos/{id}/pagamento ────────────────
    public function updatePagamento(int $id): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente');

        $dados = [
            'status_pagamento'  => SecurityHelper::sanitizeString($_POST['status_pagamento']  ?? ''),
            'forma_pagamento'   => SecurityHelper::sanitizeString($_POST['forma_pagamento']   ?? ''),
            'cartao_bandeira'   => SecurityHelper::sanitizeString($_POST['cartao_bandeira']   ?? ''),
            'cartao_ultimos_4'  => SecurityHelper::sanitizeString($_POST['cartao_ultimos_4']  ?? ''),
            'pago_em'           => SecurityHelper::sanitizeString($_POST['pago_em']           ?? ''),
        ];

        $adminId = (int)Session::get('admin_id');
        $this->json($this->service->salvarPagamento($id, $dados, $adminId));
    }

    // ── POST /admin/pedidos/{id}/item/{iid} ───────────────
    public function updateItem(int $id, int $iid): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente');

        $qtd   = max(1, (int)($_POST['quantidade'] ?? 1));
        $preco = (float)str_replace(',', '.', ($_POST['preco_unitario'] ?? '0'));

        if ($preco <= 0) $this->json(['ok' => false, 'msg' => 'Preço inválido.']);

        $this->json($this->service->editarItem($id, $iid, $qtd, $preco));
    }

    // ── POST /admin/pedidos/{id}/item/{iid}/del ───────────
    public function removeItem(int $id, int $iid): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente');
        $this->json($this->service->removerItem($id, $iid));
    }

    // ── POST /admin/pedidos/{id}/item/add ─────────────────
    public function addItem(int $id): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente');

        $produtoId = (int)($_POST['produto_id'] ?? 0);
        $skuId     = (int)($_POST['sku_id']     ?? 0) ?: null;
        $qtd       = max(1, (int)($_POST['quantidade']    ?? 1));
        $preco     = (float)str_replace(',', '.', ($_POST['preco_unitario'] ?? '0'));

        if (!$produtoId || $preco <= 0) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }

        $this->json($this->service->adicionarItem($id, $produtoId, $skuId, $qtd, $preco));
    }

    // ── POST /admin/pedidos/{id}/observacao ───────────────
    public function addObservacao(int $id): void {
        $this->verifyCsrf();

        $texto = SecurityHelper::sanitizeString($_POST['observacao'] ?? '');
        if (empty($texto)) $this->json(['ok' => false, 'msg' => 'Informe o texto.']);

        $this->model->addObservacao($id, $texto);
        $this->json(['ok' => true]);
    }

    // ── POST /admin/pedidos/{id}/nfe ──────────────────────
    public function salvarNfe(int $id): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente');

        $siteCnpj = ConfigHelper::get('site_cnpj', '40244615000188');

        $dados = [
            'serie'            => SecurityHelper::sanitizeString($_POST['serie']            ?? ''),
            'numero'           => SecurityHelper::sanitizeString($_POST['numero']           ?? ''),
            // 'tipo'             => SecurityHelper::sanitizeString($_POST['tipo']             ?? 'NFe'),
            // 'contato'          => SecurityHelper::sanitizeString($_POST['contato']          ?? ''),
            // 'cnpj'             => preg_replace('/\D/', '', $_POST['cnpj']                  ?? $siteCnpj),
            // 'vendedor'         => SecurityHelper::sanitizeString($_POST['vendedor']         ?? ''),
            'chaveAcesso'      => preg_replace('/\D/', '', $_POST['chaveAcesso']           ?? ''),
            // 'linkPDF'          => SecurityHelper::sanitizeString($_POST['linkPDF']          ?? ''),
            'linkDanfe'        => SecurityHelper::sanitizeString($_POST['linkDanfe']        ?? ''),
            // 'xml'              => $_POST['xml'] ?? null,
            'valorNota'        => (float)str_replace(',','.',($_POST['valorNota']          ?? '0')),
            // 'valorFrete'       => (float)str_replace(',','.',($_POST['valorFrete']         ?? '0')),
            'numeroPedidoLoja' => SecurityHelper::sanitizeString($_POST['numeroPedidoLoja']?? ''),
            'dataEmissao'      => !empty($_POST['dataEmissao'])
                ? date('Y-m-d H:i:s', strtotime($_POST['dataEmissao'])) : null,
            // 'dataSaidaEntrada' => !empty($_POST['dataSaidaEntrada']) ? date('Y-m-d H:i:s', strtotime($_POST['dataSaidaEntrada'])) : null,
        ];

        $this->json($this->service->salvarNfe($id, $dados));
    }

    // ── POST /admin/pedidos/{id}/enviar-cobranca ─────────
    // STUB: plug do gateway aqui.
    // Implemente o envio real de acordo com a forma de pagamento.
    public function enviarCobranca(int $id): void {
        $this->verifyCsrf();

        $pedido = $this->model->findById($id);
        if (!$pedido) $this->json(['ok' => false, 'msg' => 'Pedido não encontrado.']);

        $metodo = SecurityHelper::sanitizeString($_POST['metodo'] ?? $pedido['forma_pagamento'] ?? '');
        $email  = $pedido['cliente_email'] ?? '';

        if (empty($email)) {
            $this->json(['ok' => false, 'msg' => 'Cliente sem e-mail cadastrado.']);
        }

        // ────────────────────────────────────────────────
        // TODO: implemente o envio real de acordo com o gateway
        //
        // Exemplo Pix:
        //   $gateway = new PixGateway();
        //   $link    = $gateway->gerarLink($pedido);
        //   (new EmailService())->cobrarPix($pedido, $link);
        //
        // Exemplo Boleto:
        //   $boleto = $pedido['boleto_url'];
        //   (new EmailService())->cobrarBoleto($pedido, $boleto);
        //
        // Exemplo Cartão (link de pagamento):
        //   $gateway = new CartaoGateway();
        //   $link    = $gateway->gerarLinkPagamento($pedido);
        //   (new EmailService())->cobrarCartao($pedido, $link);
        // ────────────────────────────────────────────────

        // Por enquanto retorna erro explicativo
        // Remova este bloco quando implementar o gateway
        $this->json([
            'ok'  => false,
            'msg' => "Gateway não configurado para '{$metodo}'. "
                   . "Implemente AdminPedidoController::enviarCobranca() com seu provedor de pagamento.",
        ]);
    }

    // ── GET /admin/pedidos/buscar-cliente ─────────────────
    public function buscarCliente(): void {
        $q = SecurityHelper::sanitizeString($_GET['q'] ?? '');
        if (strlen(trim($q)) < 2) {
            $this->json(['ok' => true, 'clientes' => []]);
        }
        $clientes = $this->model->buscarClientes($q);
        $this->json(['ok' => true, 'clientes' => $clientes]);
    }

    // ── GET /admin/pedidos/buscar-produto ─────────────────
    public function buscarProduto(): void {
        $q = SecurityHelper::sanitizeString($_GET['q'] ?? '');
        if (strlen(trim($q)) < 2) {
            $this->json(['ok' => true, 'produtos' => []]);
        }
        $produtos = $this->model->buscarProdutos($q);
        $this->json(['ok' => true, 'produtos' => $produtos]);
    }

    // ── GET /admin/pedidos/{id}/enderecos-cliente ─────────
    public function enderecosPorCliente(int $clienteId): void {
        $enderecos = $this->model->getEnderecosPorCliente($clienteId);
        $this->json(['ok' => true, 'enderecos' => $enderecos]);
    }

    // ── GET /admin/pedidos/opcoes-envio ───────────────────
    // Transportadoras ativas + servicos de IDA, para o seletor da etiqueta.
    // Exclui modalidade 'reverso': aqueles codigos sao de devolucao e emitiriam
    // uma etiqueta de volta no lugar da de envio.
    public function opcoesEnvio(): void {
        $db = Database::getInstance()->getConnection();
        $ts = $db->query(
            "SELECT id, nome FROM log_transportadoras
              WHERE status = 'ativo' ORDER BY prioridade ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$ts) { $this->json(['ok' => true, 'transportadoras' => []]); }

        $in = implode(',', array_map(static fn($t) => (int)$t['id'], $ts));
        $sv = $db->query(
            "SELECT transportadora_id, codigo, nome FROM log_transportadora_servicos
              WHERE transportadora_id IN ($in) AND habilitado = 1
                AND (modalidade IS NULL OR modalidade <> 'reverso')
              ORDER BY nome ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $porT = [];
        foreach ($sv as $s) {
            $porT[$s['transportadora_id']][] = ['codigo' => $s['codigo'], 'nome' => $s['nome']];
        }
        foreach ($ts as &$t) { $t['servicos'] = $porT[$t['id']] ?? []; }
        unset($t);

        $this->json(['ok' => true, 'transportadoras' => $ts]);
    }

    // ── POST /admin/pedidos/{id}/etiqueta ─────────────────
    // Emite a etiqueta de envio pelo modulo de logistica. Cobrado: so no clique.
    public function gerarEtiqueta(int $id): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super', 'gerente', 'vendedor');

        $this->json($this->service->gerarEtiqueta($id, [
            'transportadora_id' => (int)($_POST['transportadora_id'] ?? 0),
            'servico_codigo'    => SecurityHelper::sanitizeString($_POST['servico_codigo'] ?? ''),
            'servico_nome'      => SecurityHelper::sanitizeString($_POST['servico_nome'] ?? ''),
            'formato'           => SecurityHelper::sanitizeString($_POST['formato'] ?? 'pdf'),
        ], AuthHelper::usuarioId()));
    }

}