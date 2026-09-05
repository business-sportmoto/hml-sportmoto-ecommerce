<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/AdminPedidoService.php
//
// Toda a lógica de negócio do módulo admin de pedidos.
// Orquestra: model + CouponService + EmailService + fila do Bling.
//
// NÃO movimenta estoque: o Bling é o dono do saldo e baixa em todos
// os canais quando o pedido chega lá. Ver mudarStatus() e §Bling.
// ════════════════════════════════════════════════════════

class AdminPedidoService {

    private AdminPedido   $model;
    private PedidoStatus  $statusModel;
    private EmailService  $email;
    private PDO           $db;

    private WhatsappService $wppService;
    private Order           $orderModel;
    private User           $userModel;



    public function __construct() {
        $this->model       = new AdminPedido();
        $this->statusModel = new PedidoStatus();
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
     * - Cancelamento de cupom se cancelado
     * - Log em pedido_historico
     * - Notificação por e-mail (se $notificar = true)
     */

    public function mudarStatus(
        int    $pedidoId,
        string $novoStatus,
        ?string $observacao,
        int    $adminId,
        bool   $notificar = false,
        ?int   $motivoCancelamentoId = null,
        ?string $motivoCancelamentoObs = null
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

        // ── Motivo de cancelamento ────────────────────────────
        // Devolução sempre teve motivo catalogado; cancelamento não —
        // era texto livre em pedido_historico.observacao, que não
        // agrega em relatório. Aqui ele vira dado.
        //
        // A condição olha classe_bi, NUNCA o slug: o admin pode criar
        // status de cancelamento próprios, e hardcodar 'cancelado'
        // deixaria esses de fora silenciosamente.
        if ($novoStatus !== $statusAtual) {
            $viraCancelamento = ($statusDef['classe_bi']      ?? null) === 'cancelamento';
            $saiuDeCancelado  = ($statusAtualDef['classe_bi'] ?? null) === 'cancelamento';

            if ($viraCancelamento) {
                $this->model->setMotivoCancelamento(
                    $pedidoId, $motivoCancelamentoId, $motivoCancelamentoObs
                );
            } elseif ($saiuDeCancelado) {
                // Reativação: limpa o motivo. Sem isto o pedido volta a
                // vender carregando um "cancelado por falta de estoque"
                // que o relatório continuaria contando.
                $this->model->setMotivoCancelamento($pedidoId, null, null);
            }
        }

        // ── Efeitos colaterais via flags do banco ─────────────
        if ($novoStatus !== $statusAtual) {

            // ── ESTOQUE: nada aqui, de propósito ──────────────
            // O Bling é o dono do saldo. Ele baixa quando o pedido entra e
            // estorna quando o pedido é cancelado LÁ, propagando para todos
            // os canais; o site recebe o número pronto pelo webhook/cron.
            //
            // Os flags estorna_estoque/reserva_estoque continuam na tabela
            // (a migration zera os valores) mas não movem mais estoque —
            // se voltarem a mover, o site e o Bling contam a mesma baixa
            // duas vezes e a vitrine encolhe até o próximo espelhamento.
            //
            // Cancelar aqui NÃO devolve estoque: cancele no Bling.

            // Flag: cancela_cupom → ao ENTRAR num status que cancela cupom
            if (!empty($statusDef['cancela_cupom'])) {
                $this->cancelarCupom($pedidoId);
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

        // Monta avisos (retroação de status)
        $avisos = [];
        if ($retrocedeu) {
            $avisos[] = "Status reduzido de ".($st['label'] ?? $statusAtual)." para ".($statusDef['label'] ?? $novoStatus)."'.";
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

        // FASE 2: Estoque — NÃO mexemos aqui.
        // O Bling é o dono do saldo. Se o pedido já foi enviado, quem
        // reflete a mudança de quantidade é a edição do pedido NO BLING;
        // o site recebe o saldo pronto pelo webhook/cron. Movimentar aqui
        // faria a mesma unidade ser contada duas vezes.
        //
        // Consequência aceita: a edição não valida mais disponibilidade.
        // O admin vê o saldo espelhado na tela e decide. Validar contra o
        // espelho daria uma falsa garantia — ele pode estar defasado em
        // até uma hora, e a autoridade é do Bling de qualquer forma.

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
        $this->model->removeItem($itemId);

        // Estoque: NÃO devolve aqui. O Bling é o dono do saldo — remover
        // o item no pedido do Bling é o que devolve a unidade a todos os
        // canais. Devolver aqui também contaria a mesma unidade duas vezes.

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

        // Estoque: NÃO consome aqui. Quem baixa é o Bling, ao receber o
        // pedido. Consumir no site duplicaria a baixa da mesma unidade.

        // Insere item no pedido e recalcula
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

            // Evento no stream: pedido lançado à mão conta como compra, e
            // precisa tirar o cliente das jornadas de recuperação igual ao
            // checkout. registrarPara() e não registrar(): aqui a sessão é a
            // do ADMIN, e o registrar() gravaria o id dele no lugar do cliente.
            if (class_exists('TrackingService') && method_exists('TrackingService', 'registrarPara')) {
                try {
                    TrackingService::registrarPara(
                        (int)$dados['cliente_id'], 'pedido_criado', 'pedido', $pedidoId,
                        ['total' => (float)($dados['total'] ?? 0), 'origem' => 'manual'],
                        'admin'
                    );
                } catch (\Throwable $e) {
                    error_log('[AdminPedidoService] evento pedido_criado: ' . $e->getMessage());
                }
            }

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('[AdminPedidoService] criarPedidoManual (fase 1): ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Erro ao criar pedido: ' . $e->getMessage()];
        }

        // ── FASE 2: Enfileira para o Bling ───────────────────
        // Substitui a antiga baixa de estoque local. O Bling é o dono do
        // saldo: é o pedido chegando LÁ que baixa em todos os canais,
        // inclusive no site. Baixar aqui também contaria duas vezes.
        //
        // Pedido manual entra na mesma fila do pedido do site — mesma
        // garantia de entrega, mesmo alerta se algum item estiver sem
        // vínculo (aí sim o estoque não baixa e alguém precisa saber).
        try {
            (new BlingOrderService())->enfileirar($pedidoId);
        } catch (\Throwable $e) {
            // Pedido já existe; a fila pode ser reprocessada pelo painel.
            LogService::exception($e, 'error', 'int', ['pedido_id' => $pedidoId]);
        }

        // ── FASE 3: Notificação e retorno ──────────────────────
        $pedido = $this->model->findById($pedidoId);
        if (!empty($dados['notificar_cliente']) && $pedido) {
            $this->email->pedidoCriado($pedido);
        }

        // Estoque não é mais ajustado aqui — quem baixa é o Bling ao
        // receber o pedido da fila. Sem aviso de estoque, portanto.
        $aviso = null;

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

    // ── estornarEstoque() e reservarEstoque() foram REMOVIDOS ──
    //
    // O Bling passou a ser o dono do saldo: cancelar ou reativar um
    // pedido no site não move mais estoque. Quem devolve a unidade a
    // todos os canais é o cancelamento feito NO BLING; o site recebe o
    // saldo pronto pelo webhook/cron.
    //
    // Além de duplicarem a contagem, os dois gravavam valores que o
    // ENUM de estoque_log não aceita ('entrada_cancelamento',
    // 'saida_reativacao_pedido', origem 'pedido') — com
    // STRICT_TRANS_TABLES ligado, todo INSERT deles falhava. E o
    // estornarEstoque ainda passava a chave 'sku' em vez de 'sku_id',
    // então movia estoque no nível do produto mesmo em item com variação.
    //
    // Ajuste manual de saldo continua disponível em Estoquecontroller.

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

    /* ════════════════════════════════════════════════════════
       ETIQUETA DE IDA (modulo de logistica)
       ════════════════════════════════════════════════════════ */

    /**
     * Emite a etiqueta de envio do pedido pelo modulo de logistica.
     *
     * Antes daqui nao saia etiqueta nenhuma: o admin gerava o envio por fora e
     * colava o codigo em salvarRastreio(). Agora a etiqueta e criada em
     * log_etiquetas, comprada na transportadora e o rastreio volta preenchido —
     * o mesmo caminho que a tela de Logistica ja usava.
     *
     * Emite de verdade (cobrado). So e chamado pelo botao do admin.
     *
     * @param array $opcoes transportadora_id, servico_codigo, servico_nome, formato
     */
    public function gerarEtiqueta(int $pedidoId, array $opcoes, ?int $usuarioId = null): array {
        $pedido = $this->model->findById($pedidoId);
        if (!$pedido) return ['ok' => false, 'msg' => 'Pedido não encontrado.'];

        if (!empty($pedido['codigo_rastreio'])) {
            return ['ok' => false, 'msg' => 'Este pedido já tem código de rastreio: ' . $pedido['codigo_rastreio']];
        }

        $transportadoraId = (int)($opcoes['transportadora_id'] ?? 0);
        $servico          = trim((string)($opcoes['servico_codigo'] ?? ''));
        if ($transportadoraId <= 0) return ['ok' => false, 'msg' => 'Selecione a transportadora.'];
        if ($servico === '')        return ['ok' => false, 'msg' => 'Selecione o serviço.'];

        $destinatario = $this->destinatarioDoPedido($pedido);
        $falta = $this->camposFaltantes($destinatario);
        if ($falta) {
            return ['ok' => false, 'msg' => 'Endereço de entrega incompleto: ' . implode(', ', $falta)];
        }

        $volumes = $this->volumesDoPedido($pedidoId);
        if (!$volumes) return ['ok' => false, 'msg' => 'Não foi possível calcular peso/dimensões dos itens.'];

        $etiquetas = new EtiquetaService();

        // criar() e idempotente pela chave (pedido + transportadora + servico +
        // volumes), entao dois cliques seguidos nao geram duas etiquetas.
        $criada = $etiquetas->criar([
            'pedido_id'         => $pedidoId,
            'transportadora_id' => $transportadoraId,
            'servico_codigo'    => $servico,
            'servico_nome'      => $opcoes['servico_nome'] ?? ($pedido['frete_servico'] ?? 'Envio'),
            'canal'             => 'pedido',
            'destinatario'      => $destinatario,
            'volumes'           => $volumes,
            'produtos'          => $this->produtosDoPedido($pedidoId),
            'valor_declarado'   => (float)($pedido['subtotal'] ?? $pedido['total'] ?? 0),
            'formato'           => $opcoes['formato'] ?? 'pdf',
        ], $usuarioId);

        if (empty($criada['ok'])) {
            return ['ok' => false, 'msg' => $criada['erro'] ?? 'Falha ao registrar a etiqueta.'];
        }

        $etiquetaId = (int)$criada['id'];

        // Ja emitida numa tentativa anterior: devolve o que existe.
        if (($criada['status'] ?? '') === 'emitida') {
            $e = $etiquetas->obter($etiquetaId) ?: [];
            $this->refletirRastreioNoPedido($pedidoId, (string)($e['codigo_rastreio'] ?? ''));
            return ['ok' => true, 'etiqueta_id' => $etiquetaId, 'codigo_rastreio' => $e['codigo_rastreio'] ?? null,
                    'url_pdf' => $e['url_pdf'] ?? null, 'reutilizada' => true];
        }

        $compra = $etiquetas->comprar($etiquetaId, $usuarioId);
        if (empty($compra['ok'])) {
            return ['ok' => false, 'msg' => $compra['erro'] ?? 'Falha ao emitir a etiqueta.',
                    'etapa' => $compra['etapa'] ?? null, 'etiqueta_id' => $etiquetaId];
        }

        $codigo = (string)($compra['codigo_rastreio'] ?? '');
        $this->refletirRastreioNoPedido($pedidoId, $codigo);

        return [
            'ok'              => true,
            'etiqueta_id'     => $etiquetaId,
            'codigo_rastreio' => $codigo ?: null,
            'url_pdf'         => $compra['url_pdf'] ?? null,
        ];
    }

    /**
     * Grava o rastreio no pedido reaproveitando salvarRastreio(), para nao
     * duplicar historico, notificacao ao cliente e regras de status.
     */
    private function refletirRastreioNoPedido(int $pedidoId, string $codigo): void {
        if ($codigo === '') return;
        try {
            $this->salvarRastreio($pedidoId, strtoupper($codigo), false);
        } catch (\Throwable $e) {
            // A etiqueta ja existe; nao perder isso por causa do reflexo.
            if (class_exists('LogService')) {
                LogService::warning('Etiqueta emitida mas falhou ao gravar rastreio no pedido', [
                    'pedido_id' => $pedidoId, 'codigo' => $codigo, 'erro' => $e->getMessage(),
                ]);
            }
        }
    }

    /** Destinatario a partir do endereco de entrega ja carregado em findById(). */
    private function destinatarioDoPedido(array $p): array {
        $so = static fn($v) => preg_replace('/\D/', '', (string)$v);
        return [
            'nome'        => $p['ent_destinatario'] ?: ($p['cliente_nome'] ?? ''),
            'email'       => $p['cliente_email'] ?? '',
            'documento'   => $so($p['cliente_cpf'] ?? ''),
            'cpf'         => $so($p['cliente_cpf'] ?? ''),
            'telefone'    => $so($p['cliente_telefone'] ?? ''),
            'cep'         => $so($p['ent_cep'] ?? ''),
            'logradouro'  => $p['ent_logradouro'] ?? '',
            'numero'      => $p['ent_numero'] ?? '',
            'complemento' => $p['ent_complemento'] ?? '',
            'bairro'      => $p['ent_bairro'] ?? '',
            'cidade'      => $p['ent_cidade'] ?? '',
            'uf'          => $p['ent_estado'] ?? '',
            'estado'      => $p['ent_estado'] ?? '',
        ];
    }

    /** O que falta para a transportadora aceitar o destinatario. */
    private function camposFaltantes(array $d): array {
        $rotulos = [
            'nome' => 'nome', 'cep' => 'CEP', 'logradouro' => 'logradouro',
            'numero' => 'número', 'bairro' => 'bairro', 'cidade' => 'cidade', 'uf' => 'UF',
        ];
        $falta = [];
        foreach ($rotulos as $k => $rotulo) {
            if (empty($d[$k])) $falta[] = $rotulo;
        }
        if (!empty($d['cep']) && strlen((string)$d['cep']) !== 8) $falta[] = 'CEP inválido';
        return $falta;
    }

    /** Um volume por unidade, com peso/dimensoes do cadastro do produto. */
    private function volumesDoPedido(int $pedidoId): array {
        $st = $this->db->prepare(
            "SELECT pi.quantidade,
                    pr.peso_kg, pr.altura_cm, pr.largura_cm, pr.comprimento_cm
               FROM pedido_itens pi
               JOIN produtos pr ON pr.id = pi.produto_id
              WHERE pi.pedido_id = ?"
        );
        $st->execute([$pedidoId]);

        $volumes = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $qtd = max(1, (int)$i['quantidade']);
            // Cadastro incompleto nao pode travar o envio: cai num volume
            // pequeno padrao, que o operador ajusta na tela de Logistica.
            $peso = (int) round(((float)$i['peso_kg'] ?: 0.3) * 1000);
            $vol  = [
                'peso_g'         => max(50, $peso),
                'altura_cm'      => (float)$i['altura_cm']      ?: 10.0,
                'largura_cm'     => (float)$i['largura_cm']     ?: 15.0,
                'comprimento_cm' => (float)$i['comprimento_cm'] ?: 20.0,
            ];
            for ($n = 0; $n < $qtd; $n++) $volumes[] = $vol;
        }
        return $volumes;
    }

    /** Descricao do conteudo (declaracao de conteudo dos Correios). */
    private function produtosDoPedido(int $pedidoId): array {
        $st = $this->db->prepare(
            "SELECT COALESCE(pi.nome_produto, pr.nome) AS nome, pi.quantidade, pi.preco_unitario
               FROM pedido_itens pi
               JOIN produtos pr ON pr.id = pi.produto_id
              WHERE pi.pedido_id = ?"
        );
        $st->execute([$pedidoId]);
        return array_map(static fn($i) => [
            'descricao'  => (string)$i['nome'],
            'quantidade' => (int)$i['quantidade'],
            'valor'      => (float)$i['preco_unitario'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

}