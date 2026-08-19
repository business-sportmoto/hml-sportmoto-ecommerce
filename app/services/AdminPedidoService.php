<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/AdminPedidoService.php
//
// Toda a lógica de negócio do módulo admin de pedidos.
// Orquestra: model + EstoqueService + CouponService + EmailService
// ════════════════════════════════════════════════════════

class AdminPedidoService {

    private AdminPedido   $model;
    private PedidoStatus  $statusModel;
    private EstoqueService $estoque;
    private EmailService  $email;
    private PDO           $db;

    private WhatsappService $wppService;
    private Order           $orderModel;
    private User           $userModel;



    public function __construct() {
        $this->model       = new AdminPedido();
        $this->statusModel = new PedidoStatus();
        $this->estoque     = new EstoqueService();
        $this->email       = new EmailService();

        $this->wppService = new WhatsappService();
        $this->orderModel = new Order();
        $this->userModel = new User();

        $this->db          = Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // MUDANÇA DE STATUS
    // ════════════════════════════════════════════════════

    private function prepareWppDisparth($status, $pedido){
        $u = $this->userModel->getUserParcial($pedido['cliente_id']);

        switch ($status) {
            case 'pagamento_aprovado':
                $this->wppService->sendPagamentoAprovado($u, $pedido);
                LogService::audit('teste de wpp'.$status, [$u, $pedido]);
                break;
            
            default:                
                break;
        }
    }

    /**
     * Muda o status_pedido com efeitos colaterais:
     * - Estorno de estoque se cancelado
     * - Cancelamento de cupom se cancelado
     * - Log em pedido_historico
     * - Notificação por e-mail (se $notificar = true)
     */

    public function mudarStatus(
        int    $pedidoId,
        string $novoStatus,
        ?string $observacao,
        int    $adminId,
        bool   $notificar = false
    ): array {
        $pedido = $this->model->findById($pedidoId);
        if (!$pedido) {
            return ['ok' => false, 'msg' => 'Pedido não encontrado.'];
        }

        $statusAtual = $pedido['status_pedido'];

        // Carrega definição do novo status do banco (flags de comportamento)
        $statusDef  = $this->statusModel->findBySlug($novoStatus);
        $statusAtualDef = $this->statusModel->findBySlug($statusAtual);

        // Aviso (não bloqueio) ao retroagir status pela ordenação
        $ordemNovo  = (int)($statusDef['ordenacao']      ?? 50);
        $ordemAtual = (int)($statusAtualDef['ordenacao'] ?? 50);
        $retrocedeu = $ordemNovo < $ordemAtual && $novoStatus !== 'cancelado';

        try {
            // Atualiza status + log no histórico
            $this->model->updateStatus($pedidoId, $novoStatus, $observacao, $adminId);
        } catch (\Throwable $e) {
            error_log('[AdminPedidoService] mudarStatus updateStatus: ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Erro ao atualizar status: ' . $e->getMessage().' - '. $e->getLine().' - '. $e->getFile()];
        }

        // ── Efeitos colaterais via flags do banco ─────────────
        $avisoReativacao = null;

        if ($novoStatus !== $statusAtual) {

            // Flag: estorna_estoque → ao ENTRAR num status de cancelamento
            if (!empty($statusDef['estorna_estoque'])) {
                $this->estornarEstoque($pedidoId, $pedido['codigo'], $adminId);
            }

            // Flag: cancela_cupom → ao ENTRAR num status que cancela cupom
            if (!empty($statusDef['cancela_cupom'])) {
                $this->cancelarCupom($pedidoId);
            }

            // REATIVAÇÃO: estava num status que estornou estoque
            // e está indo para um status que reserva estoque
            $estavaCancelado = !empty($statusAtualDef['estorna_estoque']);
            $deveReservar    = !empty($statusDef['reserva_estoque']);

            if ($estavaCancelado && $deveReservar) {
                $falhas = $this->reservarEstoque($pedidoId, $pedido['codigo'], $adminId);
                if (!empty($falhas)) {
                    $avisoReativacao = 'Estoque insuficiente para: '
                        . implode(', ', $falhas)
                        . '. Ajuste o estoque manualmente antes de processar este pedido.';
                }
            }
        }

        // Aprovação de pagamento automática ao mover para status de pgto. aprovado
        if ($novoStatus === 'pagamento_aprovado' &&
            $pedido['status_pagamento'] !== 'aprovado') {
            try {
                $this->model->updateStatusPagamento(
                    $pedidoId, 'aprovado', null, null, null,
                    date('Y-m-d H:i:s')
                );

                // ── CONVERSÃO: Purchase (Fase 1) ──────────────────
                // Dispara AQUI porque é o momento exato do pagamento
                // confirmado — uma vez só (o guard status_pagamento
                // !== 'aprovado' garante que não duplica). Valor vem
                // do pedido no servidor, nunca do client.
                $this->registrarConversaoPurchase($pedidoId, $pedido);
            } catch (\Throwable $e) {
                error_log('[AdminPedidoService] updateStatusPagamento: ' . $e->getMessage());
            }
        }

        // Notificação pelo flag do status (só se $notificar = true)
        // (o flag notifica_cliente é informativo para o painel;
        //  a decisão final é do admin via checkbox na UI)

        // Notificação por e-mail (fora da transação)
        if ($notificar) {
            $pedido['cliente_email'] = $pedido['cliente_email'] ?? '';
            $this->email->statusPedido($pedido, $novoStatus, $observacao);
        }

        // Monta avisos (retroação de status + estoque insuficiente na reativação)
        $avisos = [];
        if ($retrocedeu) {
            $avisos[] = "Status reduzido de ".($st['label'] ?? $statusAtual)." para ".($statusDef['label'] ?? $novoStatus)."'.";
        }
        if ($avisoReativacao) {
            $avisos[] = $avisoReativacao;
        }

        try {
            $this->prepareWppDisparth($novoStatus, $pedido);
            // LogService::audit('teste de wpp'.$novoStatus, $pedido);
        } catch (\Throwable $th) {
            LogService::error('teste aqui', [$th]);
        }

        return [
            'ok'          => true,
            'retrocedeu'  => $retrocedeu,
            'reativado'   => $estavaCancelado ?? false,
            'aviso'       => !empty($avisos) ? implode(' ', $avisos) : null,
            'novo_status' => $novoStatus,
        ];
    }

    // ════════════════════════════════════════════════════
    // RASTREIO
    // ════════════════════════════════════════════════════

    public function salvarRastreio(int $pedidoId, string $codigo, bool $notificar): array {
        $pedido = $this->model->findById($pedidoId);
        if (!$pedido) return ['ok' => false, 'msg' => 'Pedido não encontrado.'];

        $this->model->updateRastreio($pedidoId, $codigo);

        // Se ainda não estava como enviado, atualiza
        if (!in_array($pedido['status_pedido'], ['enviado','entregue'])) {
            $this->model->updateStatus($pedidoId, 'enviado', "Rastreio adicionado: {$codigo}", 0);
        }

        if ($notificar) {
            $pedido['codigo_rastreio'] = $codigo;
            $this->email->rastreioAdicionado($pedido);
        }

        return ['ok' => true, 'codigo' => $codigo];
    }

    // ════════════════════════════════════════════════════
    // PAGAMENTO MANUAL
    // ════════════════════════════════════════════════════

    public function salvarPagamento(int $pedidoId, array $dados, int $adminId): array {
        $pedido = $this->model->findById($pedidoId);
        if (!$pedido) return ['ok' => false, 'msg' => 'Pedido não encontrado.'];

        $statusPag = $dados['status_pagamento'] ?? $pedido['status_pagamento'];
        $pagoEm    = $dados['pago_em']
            ? date('Y-m-d H:i:s', strtotime($dados['pago_em']))
            : ($statusPag === 'aprovado' ? date('Y-m-d H:i:s') : null);

        $this->model->updateStatusPagamento(
            $pedidoId,
            $statusPag,
            $dados['forma_pagamento']   ?? null,
            $dados['cartao_bandeira']   ?? null,
            $dados['cartao_ultimos_4']  ?? null,
            $pagoEm
        );

        // Se aprovado e status ainda aguardando, avança automaticamente
        if ($statusPag === 'aprovado' &&
            $pedido['status_pedido'] === 'aguardando_pagamento') {
            $this->model->updateStatus(
                $pedidoId, 'pagamento_aprovado',
                'Pagamento aprovado manualmente', $adminId
            );
        }

        return ['ok' => true];
    }

    // ════════════════════════════════════════════════════
    // EDIÇÃO DE ITENS (pré-aprovação)
    // ════════════════════════════════════════════════════

    public function editarItem(
        int $pedidoId, int $itemId, int $novaQtd, float $novoPreco
    ): array {
        $pedido = $this->model->findById($pedidoId);
        if (!$pedido) return ['ok' => false, 'msg' => 'Pedido não encontrado.'];

        if ($pedido['status_pedido'] !== 'aguardando_pagamento') {
            return ['ok' => false, 'msg' => 'Itens só podem ser editados quando o pedido está aguardando pagamento.'];
        }

        if ($novaQtd < 1) {
            return ['ok' => false, 'msg' => 'Quantidade mínima é 1. Para remover, use o botão de exclusão.'];
        }

        // FASE 1: Atualiza item no BD (sem transação externa)
        try {
            $resultado = $this->model->updateItem($itemId, $novaQtd, $novoPreco);
        } catch (\Throwable $e) {
            error_log('[AdminPedidoService] editarItem updateItem: ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Erro ao atualizar item: ' . $e->getMessage()];
        }

        $item  = $resultado['item'];
        $delta = $resultado['delta_estoque']; // positivo = devolveu, negativo = consumiu

        // FASE 2: Ajusta estoque — EstoqueService com sua própria transação
        if ($delta < 0) {
            // Aumentou quantidade — consome estoque
            try {
                $result = $this->estoque->saida(
                    produtoId : (int)$item['produto_id'],
                    quantidade: abs($delta),
                    tipo      : 'saida_pedido',
                    origem    : 'admin',
                    opcoes    : [
                        'sku_id'        => $item['sku_id'] ? (int)$item['sku_id'] : null,
                        'usuario_id'    => (int)Session::get('admin_id'),
                        'observacao'    => "Aumento de qtd no pedido #{$pedido['codigo']}",
                        'referencia_id' => $pedidoId,
                    ]
                );
                if (isset($result['ok']) && !$result['ok']) {
                    // Reverte item para quantidade anterior
                    $this->model->updateItem($itemId, $resultado['qtd_antiga'], (float)$item['preco_unitario']);
                    return ['ok' => false, 'msg' => 'Estoque insuficiente para esta quantidade.'];
                }
            } catch (\Throwable $e) {
                $this->model->updateItem($itemId, $resultado['qtd_antiga'], (float)$item['preco_unitario']);
                return ['ok' => false, 'msg' => 'Erro ao reservar estoque: ' . $e->getMessage()];
            }
        } elseif ($delta > 0) {
            // Reduziu quantidade — devolve estoque
            try {
                $this->estoque->entrada(
                    produtoId : (int)$item['produto_id'],
                    quantidade: $delta,
                    tipo      : 'entrada_ajuste_pedido',
                    origem    : 'admin',
                    opcoes    : [
                        'sku_id'        => $item['sku_id'] ? (int)$item['sku_id'] : null,
                        'usuario_id'    => (int)Session::get('admin_id'),
                        'observacao'    => "Redução de qtd no pedido #{$pedido['codigo']}",
                        'referencia_id' => $pedidoId,
                    ]
                );
            } catch (\Throwable $e) {
                error_log('[AdminPedidoService] editarItem entrada: ' . $e->getMessage());
                // Item já atualizado — estoque pode ser ajustado manualmente
            }
        }

        // FASE 3: Recalcula totais e cupom
        $totais = $this->model->recalcularTotais($pedidoId);
        if (!empty($pedido['cupom_id'])) {
            $this->revalidarCupom($pedidoId);
            $totais = $this->model->recalcularTotais($pedidoId);
        }

        return ['ok' => true, 'totais' => $totais];
    }

    public function removerItem(int $pedidoId, int $itemId): array {
        $pedido = $this->model->findById($pedidoId);
        if (!$pedido) return ['ok' => false, 'msg' => 'Pedido não encontrado.'];

        if ($pedido['status_pedido'] !== 'aguardando_pagamento') {
            return ['ok' => false, 'msg' => 'Itens só podem ser removidos enquanto aguardam pagamento.'];
        }

        $itens = $this->model->getItens($pedidoId);
        if (count($itens) <= 1) {
            return ['ok' => false, 'msg' => 'O pedido deve ter pelo menos 1 item.'];
        }

        // Remove item do BD (operação direta, sem transação externa)
        $item = $this->model->removeItem($itemId);

        // EstoqueService com sua própria transação
        try {
            $this->estoque->entrada(
                produtoId : (int)$item['produto_id'],
                quantidade: (int)$item['quantidade'],
                tipo      : 'entrada_cancelamento',
                origem    : 'admin',
                opcoes    : [
                    'sku_id'        => $item['sku_id'] ? (int)$item['sku_id'] : null,
                    'usuario_id'    => (int)Session::get('admin_id'),
                    'observacao'    => "Item removido do pedido #{$pedido['codigo']}",
                    'referencia_id' => $pedidoId,
                ]
            );
        } catch (\Throwable $e) {
            error_log('[AdminPedidoService] removerItem estoque: ' . $e->getMessage());
            // Item removido — estoque pode ser ajustado manualmente se necessário
        }

        $totais = $this->model->recalcularTotais($pedidoId);
        if (!empty($pedido['cupom_id'])) {
            $this->revalidarCupom($pedidoId);
            $totais = $this->model->recalcularTotais($pedidoId);
        }

        return ['ok' => true, 'totais' => $totais];
    }

    public function adicionarItem(
        int $pedidoId, int $produtoId, ?int $skuId, int $qtd, float $preco
    ): array {
        $pedido = $this->model->findById($pedidoId);
        if (!$pedido) return ['ok' => false, 'msg' => 'Pedido não encontrado.'];

        if ($pedido['status_pedido'] !== 'aguardando_pagamento') {
            return ['ok' => false, 'msg' => 'Itens só podem ser adicionados enquanto aguardam pagamento.'];
        }

        // FASE 1: Verifica e reserva estoque — EstoqueService com sua transação
        try {
            $result = $this->estoque->saida(
                produtoId : $produtoId,
                quantidade: $qtd,
                tipo      : 'saida_pedido',
                origem    : 'admin',
                opcoes    : [
                    'sku_id'        => $skuId,
                    'usuario_id'    => (int)Session::get('admin_id'),
                    'referencia_id' => $pedidoId,
                ]
            );
            if (isset($result['ok']) && !$result['ok']) {
                return ['ok' => false, 'msg' => 'Estoque insuficiente.'];
            }
        } catch (\Throwable $e) {
            error_log('[AdminPedidoService] adicionarItem saida: ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Erro ao reservar estoque: ' . $e->getMessage()];
        }

        // FASE 2: Insere item no pedido e recalcula
        $itemId = $this->model->addItem($pedidoId, $produtoId, $skuId, $qtd, $preco);
        $totais  = $this->model->recalcularTotais($pedidoId);

        return ['ok' => true, 'item_id' => $itemId, 'totais' => $totais];
    }

    // ════════════════════════════════════════════════════
    // CRIAÇÃO MANUAL
    // ════════════════════════════════════════════════════

    public function criarPedidoManual(array $dados, array $itens, int $adminId): array {
        if (empty($dados['cliente_id'])) {
            return ['ok' => false, 'msg' => 'Selecione um cliente.'];
        }
        if (empty($itens)) {
            return ['ok' => false, 'msg' => 'Adicione pelo menos 1 item.'];
        }

        // ── FASE 1: Pedido + itens em transação ──────────────
        // EstoqueService gerencia sua própria transação,
        // então não pode estar dentro de outra. Separamos as fases.

        $pedidoId = null;

        try {
            // Calcula totais
            $subtotal = array_sum(array_map(fn($i) => (float)$i['preco'] * (int)$i['qtd'], $itens));
            $desconto = (float)($dados['desconto'] ?? 0);
            $frete    = (float)($dados['frete']    ?? 0);
            $dados['subtotal'] = $subtotal;
            $dados['total']    = max(0, round($subtotal - $desconto + $frete, 2));

            $this->db->beginTransaction();

            // Cria registro do pedido
            $pedidoId = $this->model->criarManual($dados);

            // Insere itens (snapshots, sem mexer em estoque aqui)
            foreach ($itens as $item) {
                $this->model->addItem(
                    $pedidoId,
                    (int)$item['produto_id'],
                    !empty($item['sku_id']) ? (int)$item['sku_id'] : null,
                    (int)$item['qtd'],
                    (float)$item['preco']
                );
            }

            // Log de criação no histórico
            $this->model->updateStatus(
                $pedidoId,
                $dados['status_pedido'] ?? 'aguardando_pagamento',
                'Pedido criado manualmente via admin.',
                $adminId
            );

            $this->db->commit();

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('[AdminPedidoService] criarPedidoManual (fase 1): ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Erro ao criar pedido: ' . $e->getMessage()];
        }

        // ── FASE 2: Ajuste de estoque fora da transação ───────
        // EstoqueService abre e fecha sua própria transação por item.
        // Se falhar, o pedido já existe — o admin pode ajustar estoque manualmente.
        $errosEstoque = [];
        foreach ($itens as $item) {
            try {
                $result = $this->estoque->saida(
                    produtoId : (int)$item['produto_id'],
                    quantidade: (int)$item['qtd'],
                    tipo      : 'saida_pedido',
                    origem    : 'admin',
                    opcoes    : [
                        'sku_id'        => !empty($item['sku_id']) ? (int)$item['sku_id'] : null,
                        'usuario_id'    => $adminId,
                        'referencia_id' => $pedidoId,
                    ]
                );
                if (isset($result['ok']) && !$result['ok']) {
                    $errosEstoque[] = $item['nome'] ?? "produto_id {$item['produto_id']}";
                }
            } catch (\Throwable $e) {
                error_log('[AdminPedidoService] estoque saida item: ' . $e->getMessage());
                $errosEstoque[] = $item['nome'] ?? "produto_id {$item['produto_id']}";
            }
        }

        // ── FASE 3: Notificação e retorno ──────────────────────
        $pedido = $this->model->findById($pedidoId);
        if (!empty($dados['notificar_cliente']) && $pedido) {
            $this->email->pedidoCriado($pedido);
        }

        $aviso = !empty($errosEstoque)
            ? 'Pedido criado, mas estoque não ajustado para: ' . implode(', ', $errosEstoque) . '. Verifique manualmente.'
            : null;

        return [
            'ok'       => true,
            'pedido_id'=> $pedidoId,
            'codigo'   => $pedido['codigo'] ?? '',
            'aviso'    => $aviso,
        ];
    }

    // ════════════════════════════════════════════════════
    // NF
    // ════════════════════════════════════════════════════

    public function salvarNfe(int $pedidoId, array $dados): array {
        // Valida chave de acesso
        if (!empty($dados['chaveAcesso'])) {
            $chave = preg_replace('/\D/', '', $dados['chaveAcesso']);
            if (strlen($chave) !== 44) {
                return ['ok' => false, 'msg' => 'Chave de acesso deve ter 44 dígitos.'];
            }
            $dados['chaveAcesso'] = $chave;
        }

        $nfeId = $this->model->salvarNfe($pedidoId, $dados);

        // Atualiza link do PDF no pedido principal (acesso rápido)
        if (!empty($dados['linkPDF'])) {
            $this->db->prepare(
                "UPDATE pedidos SET nota_fiscal_url = ? WHERE id = ?"
            )->execute([$dados['linkPDF'], $pedidoId]);
        }

        return ['ok' => true, 'nfe_id' => $nfeId];
    }

    // ════════════════════════════════════════════════════
    // HELPERS PRIVADOS
    // ════════════════════════════════════════════════════

    /**
     * Estorna estoque de todos os itens do pedido (ao cancelar).
     */
    private function estornarEstoque(int $pedidoId, string $codigo, int $adminId): void {
        $itens = $this->model->getItens($pedidoId);
        foreach ($itens as $item) {
            try {
                $this->estoque->entrada(
                    produtoId : (int)$item['produto_id'],
                    quantidade: (int)$item['quantidade'],
                    tipo      : 'entrada_cancelamento',
                    origem    : 'pedido',
                    opcoes    : [
                        'sku'        => $item['sku'] ? (int)$item['sku'] : null,
                        'usuario_id'    => $adminId,
                        'observacao'    => "Cancelamento do pedido #{$codigo}",
                        'referencia_id' => $pedidoId,
                    ]
                );
            } catch (\Throwable $e) {
                error_log("[AdminPedidoService] estornarEstoque item {$item['id']}: " . $e->getMessage());
            }
        }
    }

    /**
     * Re-reserva estoque ao REATIVAR um pedido cancelado.
     *
     * Chamado quando o pedido SAI de um status com estorna_estoque=1
     * e ENTRA num status com reserva_estoque=1.
     * Retorna array de itens com falha (estoque insuficiente).
     */
    private function reservarEstoque(int $pedidoId, string $codigo, int $adminId): array {
        $itens  = $this->model->getItens($pedidoId);
        $falhas = [];

        foreach ($itens as $item) {
            try {
                $result = $this->estoque->saida(
                    produtoId : (int)$item['produto_id'],
                    quantidade: (int)$item['quantidade'],
                    tipo      : 'saida_reativacao_pedido',
                    origem    : 'pedido',
                    opcoes    : [
                        'sku_id'        => $item['sku_id'] ? (int)$item['sku_id'] : null,
                        'usuario_id'    => $adminId,
                        'observacao'    => "Reativação do pedido #{$codigo}",
                        'referencia_id' => $pedidoId,
                    ]
                );

                if (isset($result['ok']) && !$result['ok']) {
                    $falhas[] = $item['nome_produto'] ?? "produto_id {$item['produto_id']}";
                }
            } catch (\Throwable $e) {
                error_log("[AdminPedidoService] reservarEstoque item {$item['id']}: " . $e->getMessage());
                $falhas[] = $item['nome_produto'] ?? "produto_id {$item['produto_id']}";
            }
        }

        return $falhas;
    }

    /**
     * Cancela o uso de cupom vinculado ao pedido.
     */
    private function cancelarCupom(int $pedidoId): void {
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM cupom_usos
                 WHERE pedido_id = ? AND status IN ('reservado','aplicado','confirmado')
                 LIMIT 1"
            );
            $stmt->execute([$pedidoId]);
            $uso = $stmt->fetch();
            if ($uso) {
                $coupon = new CouponService();
                $coupon->cancelar((int)$uso['id'], 'Cancelamento de pedido');
            }
        } catch (\Throwable $e) {
            error_log("[AdminPedidoService] cancelarCupom: " . $e->getMessage());
        }
    }

    /**
     * Revalida e redistribui desconto do cupom após edição de itens.
     */
    private function revalidarCupom(int $pedidoId): void {
        try {
            $stmt = $this->db->prepare(
                "SELECT cu.*, c.tipo, c.valor, c.valor_maximo
                 FROM cupom_usos cu
                 JOIN cupons c ON c.id = cu.cupom_id
                 WHERE cu.pedido_id = ?
                   AND cu.status IN ('reservado','aplicado','confirmado')
                 LIMIT 1"
            );
            $stmt->execute([$pedidoId]);
            $uso = $stmt->fetch();
            if (!$uso) return;

            $itens    = $this->model->getItens($pedidoId);
            $subtotal = array_sum(array_map(fn($i) => (float)$i['preco_unitario'] * (int)$i['quantidade'], $itens));

            // Recalcula desconto simples (percentual/fixo)
            $desconto = 0.0;
            if ($uso['tipo'] === 'percentual') {
                $desconto = $subtotal * ((float)$uso['valor'] / 100);
            } elseif ($uso['tipo'] === 'fixo') {
                $desconto = min((float)$uso['valor'], $subtotal);
            }
            if ($uso['valor_maximo'] && $desconto > (float)$uso['valor_maximo']) {
                $desconto = (float)$uso['valor_maximo'];
            }

            $this->db->prepare(
                "UPDATE pedidos SET desconto = ? WHERE id = ?"
            )->execute([round($desconto, 2), $pedidoId]);

            $this->db->prepare(
                "UPDATE cupom_usos SET valor_desconto = ? WHERE id = ?"
            )->execute([round($desconto, 2), $uso['id']]);

        } catch (\Throwable $e) {
            error_log("[AdminPedidoService] revalidarCupom: " . $e->getMessage());
        }
    }

    /**
     * Registra o evento de conversão Purchase no ledger (Fase 1).
     * Cobre painel + webhook (ambos passam por mudarStatus).
     * À prova de falha: tracking quebrado não afeta o pedido.
    */
    private function registrarConversaoPurchase(int $pedidoId, array $pedido): void
    {
        try {
            $itens = $this->model->getItens($pedidoId);
            $contentIds = [];
            $numItems = 0;
            foreach ($itens as $item) {
                $contentIds[] = (string)($item['produto_id'] ?? '');
                $numItems += (int)($item['qtd'] ?? $item['quantidade'] ?? 1);
            }

            (new ConversionService())->purchase([
                'id'          => $pedidoId,
                'total'       => (float)($pedido['total'] ?? 0),
                'content_ids' => $contentIds,
                'num_items'   => $numItems,
            ], (int)($pedido['cliente_id'] ?? 0) ?: null);

        } catch (Throwable $e) {
            error_log('[AdminPedidoService] Purchase tracking: ' . $e->getMessage());
        }
    }
}