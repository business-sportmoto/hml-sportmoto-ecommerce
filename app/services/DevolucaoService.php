<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/DevolucaoService.php
// ════════════════════════════════════════════════════════

class DevolucaoService {

    private PDO              $db;
    private CreditoService   $credito;
    private ScoreService     $score;
    private ReversaService   $reversa;
    private EmailService     $email;
    private AdminPedidoService $service;

    // Prazo de inspeção em dias úteis
    const PRAZO_INSPECAO_DIAS = 2;
    // Prazo CDC para solicitação (dias corridos)
    const PRAZO_CDC_DIAS = 7;

    public function __construct() {
        $this->db        = Database::getInstance()->getConnection();
        $this->credito   = new CreditoService();
        $this->score     = new ScoreService();
        $this->reversa   = new ReversaService();
        $this->email     = new EmailService();
        $this->service   = new AdminPedidoService();
    }

    // ════════════════════════════════════════════════════
    // ELEGIBILIDADE
    // ════════════════════════════════════════════════════

    /**
     * O pedido pode receber uma solicitação agora?
     *
     * Extraído do topo de `criar()` para que quem precisa saber ANTES —
     * o formulário do site, a tela do app, e o upload de mídias que não deve
     * gravar arquivo para uma solicitação que vai ser recusada — pergunte à
     * mesma regra, em vez de cada um reimplementar a sua.
     *
     * @return array{ok:bool, msg?:string, pedido?:array, dias?:int}
     */
    public function podeSolicitar(int $clienteId, int $pedidoId): array {
        $pedido = $this->getPedido($pedidoId, $clienteId);
        if (!$pedido) {
            return ['ok' => false, 'msg' => 'Pedido não encontrado.'];
        }
        if ($pedido['status_pedido'] !== 'entregue') {
            return ['ok' => false, 'msg' => 'Só é possível solicitar devolução de pedidos entregues.'];
        }

        $dias = $this->diasDesde($this->dataDeEntrega($pedidoId) ?? $pedido['atualizado_em']);
        if ($dias > self::PRAZO_CDC_DIAS) {
            return ['ok' => false, 'msg' => 'Prazo de ' . self::PRAZO_CDC_DIAS
                . ' dias corridos expirado. Solicitação não permitida.'];
        }

        $stmt = $this->db->prepare(
            "SELECT id FROM solicitacoes_devolucao
             WHERE pedido_id = ? AND status NOT IN ('cancelado','expirado','concluido','concluido_reprovado','negado')
             LIMIT 1"
        );
        $stmt->execute([$pedidoId]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'msg' => 'Já existe uma solicitação ativa para este pedido.'];
        }

        return ['ok' => true, 'pedido' => $pedido, 'dias' => $dias];
    }

    /**
     * Quando o pedido foi ENTREGUE de verdade: o primeiro evento `entregue`
     * em `pedido_historico`.
     *
     * A tabela `pedidos` não tem coluna de entrega, e até 10/09/2026 esta
     * regra usava `pedidos.atualizado_em` — que muda a cada alteração do
     * pedido. Um pedido editado no admin no 6º dia reiniciava o prazo legal
     * do cliente; qualquer rotina que tocasse a linha esticava ou encurtava a
     * janela sem ninguém perceber.
     *
     * O formulário do site e a tela do app já liam daqui. O núcleo é que
     * estava para trás — as duas telas diziam que dava tempo e o `criar()`
     * recusava, ou o contrário.
     *
     * PRIMEIRO evento, não o último: um pedido reentregue depois de uma
     * tentativa falha não pode encurtar o prazo do Art. 49.
     */
    public function dataDeEntrega(int $pedidoId): ?string {
        $stmt = $this->db->prepare(
            "SELECT criado_em FROM pedido_historico
              WHERE pedido_id = ? AND status_novo = 'entregue'
           ORDER BY criado_em ASC LIMIT 1"
        );
        $stmt->execute([$pedidoId]);
        return $stmt->fetchColumn() ?: null;
    }

    // ════════════════════════════════════════════════════
    // CRIAÇÃO
    // ════════════════════════════════════════════════════

    /**
     * Cliente cria uma solicitação de devolução/troca.
     */
    public function criar(
        int    $clienteId,
        int    $pedidoId,
        string $tipo,
        int    $motivoId,
        array  $itens,       // [{pedido_item_id, quantidade}]
        string $descricao    = '',
        array  $fotosCaminhos= []
    ): array {
        // ── Validações ───────────────────────────────────
        $pode = $this->podeSolicitar($clienteId, $pedidoId);
        if (!$pode['ok']) return ['ok' => false, 'msg' => $pode['msg']];
        $pedido = $pode['pedido'];

        if (empty($itens)) {
            return ['ok' => false, 'msg' => 'Selecione ao menos um item para devolver.'];
        }

        // ── Calcula valor ────────────────────────────────
        [$valorTotal, $itensDados] = $this->calcularValorItens($pedidoId, $itens, $pedido);
        if ($valorTotal <= 0) {
            return ['ok' => false, 'msg' => 'Nenhum item válido selecionado.'];
        }

        // ── Score: pre-aprovação automática ─────────────
        $autoAprovar = $this->score->podeAutoAprovar($clienteId);
        $status      = $autoAprovar ? 'pre_aprovado' : 'aguardando_aprovacao';

        $this->db->beginTransaction();
        try {
            // INSERT solicitação
            $this->db->prepare(
                "INSERT INTO solicitacoes_devolucao
                 (pedido_id, cliente_id, tipo, status, motivo_id,
                  descricao, fotos_json, valor_solicitado, criado_em)
                 VALUES (?,?,?,?,?,?,?,?,NOW())"
            )->execute([
                $pedidoId, $clienteId, $tipo, $status, $motivoId,
                $descricao ?: null,
                !empty($fotosCaminhos) ? json_encode($fotosCaminhos) : null,
                $valorTotal,
            ]);
            $solId = (int)$this->db->lastInsertId();

            // INSERT itens
            $stmtItem = $this->db->prepare(
                "INSERT INTO solicitacoes_devolucao_itens
                 (solicitacao_id, pedido_item_id, quantidade,
                  valor_unitario, desconto_proporcional, valor_final)
                 VALUES (?,?,?,?,?,?)"
            );
            foreach ($itensDados as $item) {
                $stmtItem->execute([
                    $solId,
                    $item['pedido_item_id'],
                    $item['quantidade'],
                    $item['valor_unitario'],
                    $item['desconto_proporcional'],
                    $item['valor_final'],
                ]);
            }

            // Log histórico
            $this->logStatus($solId, $status, 'Solicitação criada pelo cliente.');

            // Se pré-aprovado, gera código de logística já
            if ($autoAprovar) {
                $logResult = $this->abrirReversa($solId, $clienteId, $pedido);
                if (!$logResult['ok']) {
                    // Falha na logística: coloca em aguardando_aprovacao para admin resolver
                    $this->db->prepare(
                        "UPDATE solicitacoes_devolucao SET status = 'aguardando_aprovacao' WHERE id = ?"
                    )->execute([$solId]);
                    $this->logStatus($solId, 'aguardando_aprovacao', 'Erro na geração do código de postagem automático. Revisão necessária.');
                }
            }

            $this->db->commit();

            // Notifica admin + cliente
            $sol = $this->findById($solId);
            $this->email->devolucaoCriada($sol, $pedido);

            $this->service->mudarStatus($pedido['id'], 'troca_devolucao', 
                $autoAprovar ? 'Devolução aprovada, aguarde as instruções para postagem.' : 'Devolução solicitada, aguarde a análise da loja.', 
            0, false);

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[DevolucaoService] criar: ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Erro ao criar solicitação.'];
        }

        return ['ok' => true, 'solicitacao_id' => $solId, 'status' => $status, 'auto_aprovado' => $autoAprovar];
    }

    // ════════════════════════════════════════════════════
    // ADMIN — APROVAÇÃO / NEGAÇÃO
    // ════════════════════════════════════════════════════

    public function aprovar(int $solId, int $adminId, ?string $obs = null): array {
        $sol = $this->findById($solId);
        if (!$sol) return ['ok' => false, 'msg' => 'Solicitação não encontrada.'];

        if (!in_array($sol['status'], ['aguardando_aprovacao','solicitado','pre_aprovado'])) {
            return ['ok' => false, 'msg' => "Status '{$sol['status']}' não permite aprovação."];
        }

        $pedido = $this->getPedidoById((int)$sol['pedido_id']);

        // Gera código de postagem reversa
        // Abre a reversa no modulo de logistica, mas NAO emite etiqueta: a
        // postagem reversa e cobrada e so sai no botao do admin.
        $logResult = $this->abrirReversa($solId, (int)$sol['cliente_id'], $pedido);

        // Sem codigo ainda, o correto e 'aprovado'; vai para aguardando_postagem
        // quando a etiqueta for realmente emitida.
        $novoStatus = 'aprovado';

        $this->db->prepare(
            "UPDATE solicitacoes_devolucao
             SET status = ?, aprovado_por_admin_id = ?, atualizado_em = NOW()
             WHERE id = ?"
        )->execute([$novoStatus, $adminId, $solId]);
        $this->logStatus($solId, $novoStatus, $obs ?? 'Aprovado pelo admin.', $adminId);

        // Notifica cliente
        $sol = $this->findById($solId);
        $this->email->devolucaoAprovada($sol, $pedido);

        $obs_status = !empty($obs) ? "Observação do admin: {$obs}" : "";
        $this->service->mudarStatus($pedido['id'], 'troca_devolucao', 'Devolução aprovada pela loja, em breve você vai receber as instruções para devolver. ' . $obs_status, $adminId, false);

        return ['ok' => true, 'status' => $novoStatus, 'logistica' => $logResult];
    }

    /**
     * Gera (ou regera) o código de postagem reversa para uma solicitação aprovada.
     * Usado quando a geração automática falhou no momento da aprovação,
     * ou quando o admin precisa regenerar o código manualmente.
     */
    public function gerarPostagem(int $solId, int $adminId): array {
        $sol = $this->findById($solId);
        if (!$sol) return ['ok' => false, 'msg' => 'Solicitação não encontrada.'];

        // Aceita tanto 'aprovado' (sem código) quanto 'aguardando_postagem' (regenar)
        if (!in_array($sol['status'], ['aprovado', 'aguardando_postagem'])) {
            return ['ok' => false, 'msg' => "Status '{$sol['status']}' não permite gerar código de postagem."];
        }

        $pedido    = $this->getPedidoById((int)$sol['pedido_id']);
        $logResult = $this->emitirEtiquetaReversa($solId, $adminId);

        if (!$logResult['ok']) {
            return [
                'ok'  => false,
                'msg' => $logResult['msg'] ?? 'Falha ao gerar código de postagem reversa.',
            ];
        }

        // Avança status para aguardando_postagem se ainda estava em 'aprovado'
        if ($sol['status'] === 'aprovado') {
            $this->db->prepare(
                "UPDATE solicitacoes_devolucao
                 SET status = 'aguardando_postagem', atualizado_em = NOW()
                 WHERE id = ?"
            )->execute([$solId]);
            $this->logStatus(
                $solId,
                'aguardando_postagem',
                'Código de postagem reversa gerado pelo admin.',
                $adminId
            );
        }

        return [
            'ok'  => true,
            'cod' => $logResult['cod'],
            'msg' => 'Código gerado com sucesso.',
        ];
    }

    /**
     * Registra recebimento físico manual pelo admin.
     * Localiza solicitação por CPF + referência do pedido.
     */
    /**
     * Busca uma solicitação ativa por qualquer referência:
     * CPF, ID/código do pedido, código de postagem ou rastreio reverso.
     */
    public function buscarParaRecebimento(string $busca): ?array {
        $busca    = trim($busca);
        $cpf      = preg_replace('/\D/', '', $busca);
        $numerico = preg_replace('/\D/', '', $busca);
        $like     = '%' . $busca . '%';

        $stmt = $this->db->prepare(
            "SELECT sd.id, sd.status, sd.tipo,
                    sd.codigo_postagem_reversa, sd.codigo_rastreio_reverso,
                    p.codigo AS pedido_codigo, p.id AS pedido_id,
                    u.nome   AS cliente_nome,
                    c.cpf    AS cliente_cpf
             FROM solicitacoes_devolucao sd
             JOIN pedidos  p ON p.id  = sd.pedido_id
             JOIN clientes c ON c.id  = sd.cliente_id
             JOIN usuarios u ON u.id  = c.usuario_id
             WHERE sd.status NOT IN ('negado','cancelado','concluido')
               AND (
                   c.cpf                         = ?
                   OR CAST(p.id AS CHAR)          = ?
                   OR p.codigo                    LIKE ?
                   OR sd.codigo_postagem_reversa  LIKE ?
                   OR sd.codigo_rastreio_reverso  LIKE ?
               )
             ORDER BY sd.criado_em DESC
             LIMIT 1"
        );
        $stmt->execute([
            strlen($cpf) === 11 ? $cpf : '',
            $numerico,
            $like,
            $like,
            $like,
        ]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Registra recebimento físico manual.
     * Recebe o ID da solicitação (já identificada via buscarParaRecebimento).
     */
    public function receberManual(
        int    $solId,
        string $codigoPostagem,
        string $codigoRastreio,
        string $observacao,
        int    $adminId
    ): array {
        $sol = $this->findById($solId);
        if (!$sol) {
            return ['ok' => false, 'msg' => 'Solicitação não encontrada.'];
        }

        $permitidos = ['aprovado', 'aguardando_postagem', 'em_transito_reverso'];
        if (!in_array($sol['status'], $permitidos)) {
            return ['ok' => false, 'msg' => "Status '{$sol['status']}' não permite registrar recebimento."];
        }

        $sets   = ["status = 'item_recebido'", 'atualizado_em = NOW()'];
        $params = [];

        if ($codigoPostagem && empty($sol['codigo_postagem_reversa'])) {
            $sets[]   = 'codigo_postagem_reversa = ?';
            $params[] = strtoupper($codigoPostagem);
        }
        if ($codigoRastreio) {
            $sets[]   = 'codigo_rastreio_reverso = ?';
            $params[] = strtoupper($codigoRastreio);
        }
        $params[] = $solId;

        $this->db->prepare(
            "UPDATE solicitacoes_devolucao SET " . implode(', ', $sets) . " WHERE id = ?"
        )->execute($params);

        $obs = $observacao ?: 'Recebimento registrado manualmente pelo admin.';
        $this->logStatus($solId, 'item_recebido', $obs, $adminId);

        $pedido = $this->getPedidoById((int)$sol['pedido_id']);
        $this->email->itemRecebido($sol, $pedido);

        return ['ok' => true, 'sol_id' => $solId];
    }
    public function negar(int $solId, int $adminId, string $motivo): array {
        $sol = $this->findById($solId);
        if (!$sol) return ['ok' => false, 'msg' => 'Solicitação não encontrada.'];

        if (!in_array($sol['status'], ['aguardando_aprovacao','solicitado','pre_aprovado'])) {
            return ['ok' => false, 'msg' => "Status '{$sol['status']}' não permite negação."];
        }

        $this->db->prepare(
            "UPDATE solicitacoes_devolucao
             SET status = 'negado', negado_motivo = ?, aprovado_por_admin_id = ?, atualizado_em = NOW()
             WHERE id = ?"
        )->execute([$motivo, $adminId, $solId]);
        $this->logStatus($solId, 'negado', $motivo, $adminId);

        $pedido = $this->getPedidoById((int)$sol['pedido_id']);
        $this->email->devolucaoNegada($sol, $pedido, $motivo);

        // Volta para o status de entregue, pois é o status anterior mais próximo que faz sentido 
        // (pode ter sido alterado para "em devolução" ou algo assim, mas o importante é retirar do status de "entregue" apenas quando for realmente aprovado)
        $this->service->mudarStatus($pedido['id'], 'entregue', "Devolução negada: {$motivo}", $adminId, false);

        return ['ok' => true];
    }

    // ════════════════════════════════════════════════════
    // ADMIN — RECEBIMENTO + INSPEÇÃO
    // ════════════════════════════════════════════════════

    public function confirmarRecebimento(int $solId, int $adminId, ?string $obs = null): array {
        $sol = $this->findById($solId);
        if (!$sol) return ['ok' => false, 'msg' => 'Solicitação não encontrada.'];

        if ($sol['status'] !== 'em_transito_reverso') {
            return ['ok' => false, 'msg' => "Só é possível confirmar recebimento no status 'em_transito_reverso'."];
        }

        // Calcula prazo de inspeção (2 dias úteis)
        $prazoInspecao = $this->adicionarDiasUteis(date('Y-m-d H:i:s'), self::PRAZO_INSPECAO_DIAS);

        $this->db->prepare(
            "UPDATE solicitacoes_devolucao
             SET status = 'item_recebido',
                 item_recebido_em = NOW(),
                 inspecao_prazo_ate = ?,
                 atualizado_em = NOW()
             WHERE id = ?"
        )->execute([$prazoInspecao, $solId]);
        $this->logStatus($solId, 'item_recebido', $obs ?? 'Item recebido na loja.', $adminId);

        $sol    = $this->findById($solId);
        $pedido = $this->getPedidoById((int)$sol['pedido_id']);
        $this->email->itemRecebido($sol, $pedido, $prazoInspecao);

        $this->service->mudarStatus($pedido['id'], 'troca_devolucao', 'Item devolvido à loja.', $adminId, false);

        return ['ok' => true, 'prazo_inspecao' => $prazoInspecao];
    }

    public function inspecionar(
        int    $solId,
        int    $adminId,
        string $resultado,   // 'aprovado' | 'reprovado'
        ?string $obs = null,
        ?float  $valorAprovado = null
    ): array {
        $sol = $this->findById($solId);
        if (!$sol) return ['ok' => false, 'msg' => 'Solicitação não encontrada.'];

        if ($sol['status'] !== 'item_recebido') {
            return ['ok' => false, 'msg' => "Inspeção só permitida no status 'item_recebido'."];
        }
        if (!in_array($resultado, ['aprovado', 'reprovado'])) {
            return ['ok' => false, 'msg' => 'Resultado inválido.'];
        }

        // Reprovado JÁ é o fim do caso.
        //
        // Antes parava em `inspecionado_reprovado` — status do qual não saía
        // transição nenhuma: `reembolsar()` exige `inspecionado_aprovado`, e
        // não havia outro caminho. A solicitação ficava aberta para sempre e o
        // PEDIDO ficava presa em `troca_devolucao` junto.
        //
        // Não há segunda etapa a cumprir: o produto fica na loja e não há
        // reembolso, então gravar o veredito e o desfecho em dois eventos
        // separados só encheria a linha do tempo do cliente com duas linhas
        // com um segundo de diferença. O motivo da reprovação vai na
        // observação, que é o que ele precisa ler.
        //
        // `inspecao_resultado` continua registrando 'reprovado' — o veredito
        // não se perde, só deixa de ser um status parado.
        $novoStatus = $resultado === 'aprovado'
            ? 'inspecionado_aprovado'
            : 'concluido_reprovado';

        // O valor vem de um campo de texto do admin e ia direto para o
        // reembolso. Aprovar MAIS do que foi solicitado nao e uma decisao que
        // alguem toma de proposito nesta tela — e digito a mais.
        $valorFinal = null;
        if ($resultado === 'aprovado') {
            $teto       = (float)$sol['valor_solicitado'];
            $valorFinal = $valorAprovado ?? $teto;
            $valorFinal = max(0.0, min($valorFinal, $teto));
        }

        $this->db->prepare(
            "UPDATE solicitacoes_devolucao
             SET status             = ?,
                 inspecao_resultado = ?,
                 inspecao_observacao= ?,
                 valor_aprovado     = ?,
                 inspecionado_em    = NOW(),
                 inspecao_admin_id  = ?,
                 atualizado_em      = NOW()
             WHERE id = ?"
        )->execute([$novoStatus, $resultado, $obs, $valorFinal, $adminId, $solId]);
        $this->logStatus($solId, $novoStatus, $obs ?? "Inspeção: {$resultado}.", $adminId);

        // Penaliza score se reprovado
        if ($resultado === 'reprovado') {
            $this->score->recalcular((int)$sol['cliente_id']);
        }

        $sol    = $this->findById($solId);
        $pedido = $this->getPedidoById((int)$sol['pedido_id']);
        $this->email->inspecaoResultado($sol, $pedido, $resultado);

        // Aprovado segue em `troca_devolucao` — ainda falta reembolsar.
        // Reprovado volta para `entregue`, e não para `devolvido`: nada foi
        // devolvido ao cliente, nem produto nem dinheiro. `devolvido` tem
        // classe_bi = devolucao e faria o painel descontar de uma receita que
        // continua na loja. É o mesmo destino que `negar()` já usa.
        if ($resultado === 'aprovado') {
            $this->service->mudarStatus(
                $sol['pedido_id'], 'troca_devolucao',
                'Devolução aprovada na inspeção. Valor aprovado: R$ ' . PriceHelper::format($valorFinal),
                $adminId, false
            );
        } else {
            $this->service->mudarStatus(
                $sol['pedido_id'], 'entregue',
                'Devolução encerrada sem reembolso após inspeção. O produto permanece na loja.'
                . ($obs ? " Motivo: {$obs}" : ''),
                $adminId, false
            );
        }

        return ['ok' => true, 'status' => $novoStatus, 'valor_aprovado' => $valorFinal];
    }

    /**
     * Encerra uma solicitação parada em `inspecionado_reprovado`.
     *
     * A partir de 10/09/2026 `inspecionar()` já fecha o caso na hora, então
     * ninguém novo cai neste estado. Este método existe para as solicitações
     * que ficaram travadas antes disso: sem ele, elas seguem abertas para
     * sempre, com o pedido preso em `troca_devolucao`.
     */
    public function encerrarReprovado(int $solId, int $adminId, ?string $obs = null): array {
        $sol = $this->findById($solId);
        if (!$sol) return ['ok' => false, 'msg' => 'Solicitação não encontrada.'];

        if ($sol['status'] !== 'inspecionado_reprovado') {
            return ['ok' => false, 'msg' => "Status '{$sol['status']}' não precisa deste encerramento."];
        }

        $this->db->prepare(
            "UPDATE solicitacoes_devolucao
                SET status = 'concluido_reprovado', atualizado_em = NOW()
              WHERE id = ?"
        )->execute([$solId]);
        $this->logStatus($solId, 'concluido_reprovado',
            $obs ?: 'Encerrada sem reembolso: inspeção reprovada.', $adminId);

        $this->service->mudarStatus(
            (int)$sol['pedido_id'], 'entregue',
            'Devolução encerrada sem reembolso após inspeção. O produto permanece na loja.'
            . ($obs ? " Motivo: {$obs}" : ''),
            $adminId, false
        );

        return ['ok' => true, 'status' => 'concluido_reprovado'];
    }

    // ════════════════════════════════════════════════════
    // ADMIN — REEMBOLSO
    // ════════════════════════════════════════════════════

    public function reembolsar(
        int    $solId,
        int    $adminId,
        string $tipoReembolso,  // gateway|pix|boleto_manual|credito
        array  $dados = []      // dados específicos por tipo
    ): array {
        $sol = $this->findById($solId);
        if (!$sol) return ['ok' => false, 'msg' => 'Solicitação não encontrada.'];

        if ($sol['status'] !== 'inspecionado_aprovado') {
            return ['ok' => false, 'msg' => "Reembolso só permitido após inspeção aprovada."];
        }

        $valorAprovado = (float)$sol['valor_aprovado'];
        if ($valorAprovado <= 0) {
            return ['ok' => false, 'msg' => 'Valor aprovado inválido.'];
        }

        $pedido    = $this->getPedidoById((int)$sol['pedido_id']);
        $txSaldoId = null;

        switch ($tipoReembolso) {

            case 'credito':
                // Busca prazo de expiração do motivo
                $motivo       = $this->getMotivo((int)$sol['motivo_id']);
                $diasExpiracao = $motivo['prazo_credito_dias'] ?? 30;

                $txSaldoId = $this->credito->creditar(
                    (int)$sol['cliente_id'],
                    $valorAprovado,
                    'credito_devolucao',
                    "Devolução do pedido #{$pedido['codigo']}",
                    $diasExpiracao,
                    'solicitacao',
                    $solId,
                    $adminId
                );
                break;

            // ── Pix automatico e estorno no cartao: NAO EXISTEM ──────────
            //
            // Ate 10/09/2026 estes dois eram `break` com um TODO comentado
            // dentro. O fluxo seguia reto para o UPDATE la embaixo: marcava
            // `concluido`, gravava `reembolsado_em`, disparava o e-mail de
            // "reembolso concluido" e movia o pedido para `devolvido` — com o
            // dinheiro ainda na loja e ninguem sabendo.
            //
            // Recusar e melhor que fingir. Enquanto nao houver chamada real,
            // o caminho honesto e `boleto_manual`: o admin transfere pelo
            // banco e marca aqui. O SafraPayAdapter ainda nao tem metodo de
            // estorno; quando tiver, o `gateway` volta com a chamada de
            // verdade no lugar deste return.
            case 'pix':
            case 'gateway':
                return [
                    'ok'  => false,
                    'msg' => 'Reembolso automático ainda não está disponível. '
                           . 'Faça a transferência pelo banco e registre em "Transferência manual", '
                           . 'ou use "Crédito na conta".',
                ];

            case 'boleto_manual':
                // Admin marca como concluído manualmente após transferência bancária
                break;

            default:
                return ['ok' => false, 'msg' => 'Tipo de reembolso inválido.'];
        }

        $this->db->prepare(
            "UPDATE solicitacoes_devolucao
             SET status               = 'concluido',
                 tipo_reembolso       = ?,
                 dados_reembolso_json = ?,
                 reembolsado_em       = NOW(),
                 transacao_saldo_id   = ?,
                 atualizado_em        = NOW()
             WHERE id = ?"
        )->execute([
            $tipoReembolso,
            !empty($dados) ? json_encode($dados) : null,
            $txSaldoId,
            $solId,
        ]);
        $this->logStatus($solId, 'concluido', "Reembolso via {$tipoReembolso}: R$ {$valorAprovado}", $adminId);

        // Atualiza score do cliente (devolução concluída legitimamente)
        $this->score->recalcular((int)$sol['cliente_id']);

        $sol = $this->findById($solId);
        $this->email->devolucaoConcluida($sol, $pedido, $tipoReembolso, $valorAprovado);

        $this->service->mudarStatus($sol['pedido_id'], 'devolvido', "Devolução concluída. Valor reembolsado: R$ " . PriceHelper::format($valorAprovado), $adminId, false);

        return ['ok' => true, 'status' => 'concluido', 'tx_saldo_id' => $txSaldoId];
    }

    // ════════════════════════════════════════════════════
    // CLIENTE — CANCELAR / INFORMAR RASTREIO
    // ════════════════════════════════════════════════════

    public function cancelarPorCliente(int $solId, int $clienteId): array {
        $sol = $this->findById($solId);
        if (!$sol || (int)$sol['cliente_id'] !== $clienteId) {
            return ['ok' => false, 'msg' => 'Solicitação não encontrada.'];
        }
        if (!in_array($sol['status'], ['solicitado','aguardando_aprovacao','pre_aprovado','aprovado','aguardando_postagem'])) {
            return ['ok' => false, 'msg' => 'Esta solicitação não pode mais ser cancelada.'];
        }

        $this->db->prepare(
            "UPDATE solicitacoes_devolucao SET status = 'cancelado', atualizado_em = NOW() WHERE id = ?"
        )->execute([$solId]);
        $this->logStatus($solId, 'cancelado', 'Cancelado pelo cliente.');

        // O cancelamento é permitido em `aguardando_postagem` — ou seja,
        // DEPOIS de a loja ter emitido (e pago) a etiqueta reversa. Cancelar
        // aqui não devolve esse dinheiro, mas deixar a reversa aberta na
        // logística deixa a fila de quem acompanha reversas mentindo: uma
        // linha esperando um pacote que nunca vem.
        if (!empty($sol['reversa_id'])) {
            try {
                $this->reversa->cancelar((int)$sol['reversa_id'], null);
            } catch (\Throwable $e) {
                LogService::warning('devolucao: falha ao cancelar reversa do cancelamento do cliente', [
                    'solicitacao_id' => $solId,
                    'reversa_id'     => (int)$sol['reversa_id'],
                    'erro'           => $e->getMessage(),
                ]);
            }
        }
        $this->service->mudarStatus($sol['pedido_id'], 'entregue', "Solicitação de devolução #{$sol['id']} foi cancelada pelo cliente.", 0, false); 

        return ['ok' => true];
    }

    public function informarRastreio(int $solId, int $clienteId, string $codigo): array {
        $sol = $this->findById($solId);
        if (!$sol || (int)$sol['cliente_id'] !== $clienteId) {
            return ['ok' => false, 'msg' => 'Solicitação não encontrada.'];
        }
        if ($sol['status'] !== 'aguardando_postagem') {
            return ['ok' => false, 'msg' => "Status atual não permite informar rastreio."];
        }

        $this->db->prepare(
            "UPDATE solicitacoes_devolucao
             SET status                  = 'em_transito_reverso',
                 codigo_rastreio_reverso = ?,
                 item_postado_em         = NOW(),
                 atualizado_em           = NOW()
             WHERE id = ?"
        )->execute([strtoupper(trim($codigo)), $solId]);
        $this->logStatus($solId, 'em_transito_reverso', "Rastreio informado: {$codigo}");

        return ['ok' => true];
    }

    // ════════════════════════════════════════════════════
    // QUERIES
    // ════════════════════════════════════════════════════

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            // `p.codigo` estava faltando: a tela de detalhe do admin imprime
            // $sol['pedido_codigo'] e o `?? ''` engolia, deixando um link "#"
            // vazio ao lado do cliente. `listar()` já trazia o campo.
            //
            // LEFT JOIN, não JOIN: existe solicitação apontando para pedido
            // que não existe mais (sol#2 → pedido_id 1, apagado). Com INNER a
            // tela de detalhe passaria a dar 404 nessas, escondendo o problema
            // em vez de mostrá-lo.
            "SELECT s.*, m.label AS motivo_label, m.exige_foto,
                    m.responsavel_frete, m.prazo_credito_dias,
                    u.nome AS cliente_nome, u.email AS cliente_email,
                    p.codigo AS pedido_codigo
             FROM solicitacoes_devolucao s
             JOIN motivos_devolucao m  ON m.id = s.motivo_id
             JOIN clientes c           ON c.id = s.cliente_id
             JOIN usuarios u           ON u.id = c.usuario_id
             LEFT JOIN pedidos p       ON p.id = s.pedido_id
             WHERE s.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getItens(int $solId): array {
        $stmt = $this->db->prepare(
            "SELECT sdi.*,
                    COALESCE(pi.nome_produto, pr.nome) AS nome_produto,
                    COALESCE(pi.sku, ps.sku)           AS sku,
                    COALESCE(pi.imagem_snapshot,
                        (SELECT img.arquivo FROM produto_imagens img
                         WHERE img.produto_id = pr.id AND img.principal = 1 LIMIT 1)
                    ) AS imagem,
                    pr.id AS produto_id
             FROM solicitacoes_devolucao_itens sdi
             JOIN pedido_itens pi ON pi.id = sdi.pedido_item_id
             JOIN produtos pr     ON pr.id = pi.produto_id
             LEFT JOIN produto_skus ps ON ps.id = pi.sku
             WHERE sdi.solicitacao_id = ?"
        );
        $stmt->execute([$solId]);
        return $stmt->fetchAll();
    }

    public function getHistorico(int $solId): array {
        $stmt = $this->db->prepare(
            // h.admin_id guarda `admins.id` (quem grava e
            // Session::get('admin_id')), entao o caminho ate o nome passa por
            // `admins`. O JOIN antigo comparava admins.id contra usuarios.id —
            // espacos de numeracao independentes, a "regra de ouro dos IDs" do
            // CLAUDE.md 4.1. No banco atual admins.id=1 e o Robert
            // (usuarios.id=3), e o JOIN resolvia usuarios.id=1 =
            // "Administrador": nome errado, sem erro nenhum, e exibido tambem
            // para o cliente.
            //
            // Mesmo caminho de OrderAdminController.php:103-107.
            "SELECT h.*, u.nome AS admin_nome
             FROM solicitacoes_devolucao_historico h
             LEFT JOIN admins   a ON a.id = h.admin_id
             LEFT JOIN usuarios u ON u.id = a.usuario_id
             WHERE h.solicitacao_id = ? ORDER BY h.criado_em DESC"
        );
        $stmt->execute([$solId]);
        return $stmt->fetchAll();
    }

    public function listar(array $filtros = [], int $page = 1, int $perPage = 20): array {
        [$where, $params] = $this->buildWhere($filtros);
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(
            "SELECT s.id, s.tipo, s.status, s.valor_solicitado, s.valor_aprovado,
                    s.tipo_reembolso, s.criado_em, s.atualizado_em,
                    m.label AS motivo_label,
                    u.nome AS cliente_nome, u.email AS cliente_email,
                    p.codigo AS pedido_codigo,
                    p.id AS pedido_id,
                    s.id AS solicitacao_id
             FROM solicitacoes_devolucao s
             JOIN motivos_devolucao m ON m.id = s.motivo_id
             JOIN clientes c          ON c.id = s.cliente_id
             JOIN usuarios u          ON u.id = c.usuario_id
             JOIN pedidos p           ON p.id = s.pedido_id
             WHERE {$where}
             ORDER BY s.criado_em DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function contar(array $filtros = []): int {
        [$where, $params] = $this->buildWhere($filtros);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM solicitacoes_devolucao s
             JOIN clientes c ON c.id = s.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             JOIN pedidos   p ON p.id = s.pedido_id
             WHERE {$where}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Quantas solicitações em cada status, respeitando os MESMOS filtros da
     * listagem.
     *
     * A tela montava isso iterando `$lista` — que traz 20 linhas. Os números
     * dos chips mudavam conforme a paginação: na página 2, contagens
     * diferentes para os mesmos dados. Mesma família do bug de KPI já
     * corrigido na listagem de pedidos.
     *
     * O filtro de `status` sai da conta de propósito: o chip precisa mostrar
     * quanto existe em CADA status, e filtrar por um deles zeraria todos os
     * outros — que é justamente onde o operador quer clicar em seguida.
     *
     * @return array<string,int>
     */
    public function contarPorStatus(array $filtros = []): array {
        unset($filtros['status']);
        [$where, $params] = $this->buildWhere($filtros);

        $stmt = $this->db->prepare(
            "SELECT s.status, COUNT(*) AS n
               FROM solicitacoes_devolucao s
               JOIN clientes c ON c.id = s.cliente_id
               JOIN usuarios u ON u.id = c.usuario_id
               JOIN pedidos  p ON p.id = s.pedido_id
              WHERE {$where}
           GROUP BY s.status"
        );
        $stmt->execute($params);

        $mapa = [];
        foreach ($stmt->fetchAll() as $r) $mapa[(string)$r['status']] = (int)$r['n'];
        return $mapa;
    }

    public function getMotivos(bool $apenasAtivos = false): array {
        $sql = "SELECT * FROM motivos_devolucao";
        if ($apenasAtivos) $sql .= " WHERE ativo = 1";
        $sql .= " ORDER BY ordenacao ASC";
        return $this->db->query($sql)->fetchAll();
    }

    public function getMotivo(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM motivos_devolucao WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // ════════════════════════════════════════════════════
    // HELPERS PRIVADOS
    // ════════════════════════════════════════════════════

    /**
     * Abre a reversa no modulo de logistica — SEM emitir etiqueta.
     *
     * Chamado na aprovacao (automatica ou do admin). Registra a intencao de
     * devolucao em log_reversas e ja deixa autorizada, mas nao encosta na
     * transportadora: emitir postagem reversa nos Correios e cobrado, e quem
     * decide isso e o admin, no botao "gerar codigo de postagem".
     *
     * Antes daqui saia um codigo 'FAKE######' de LogisticaReversa, um stub
     * apontando para uma URL de exemplo que nunca foi configurada.
     */
    private function abrirReversa(int $solId, int $clienteId, array $pedido): array {
        $sol = $this->findById($solId);
        if (!$sol) return ['ok' => false, 'msg' => 'Solicitação não encontrada.'];

        // Reaproveita a reversa ja ligada a esta solicitacao.
        $reversaId = (int)($sol['reversa_id'] ?? 0);
        if ($reversaId > 0) {
            return ['ok' => true, 'reversa_id' => $reversaId, 'reutilizada' => true];
        }

        $cliente = $this->dadosClienteParaReversa($clienteId);
        $itens   = $this->getItens($solId);

        $res = $this->reversa->solicitar([
            'pedido_id'       => (int)($pedido['id'] ?? $sol['pedido_id']),
            'cliente_id'      => $clienteId,
            'motivo'          => (($sol['tipo'] ?? '') === 'troca') ? 'troca' : 'devolucao',
            'tipo'            => 'postagem',
            'processo'        => (($sol['tipo'] ?? '') === 'troca') ? 'troca' : 'reembolso',
            'itens'           => array_map(static fn($i) => [
                'nome'       => $i['nome_produto'] ?? '',
                'sku'        => $i['sku'] ?? '',
                'quantidade' => (int)($i['quantidade'] ?? 1),
            ], $itens),
            'endereco_coleta' => $cliente,
        ], $this->usuarioAtual());

        if (empty($res['ok'])) {
            return ['ok' => false, 'msg' => $res['erro'] ?? 'Falha ao abrir a reversa.'];
        }

        $reversaId = (int)$res['id'];

        // Autoriza para liberar o botao de gerar etiqueta.
        if (($res['status'] ?? '') === 'solicitada') {
            $this->reversa->autorizar($reversaId, $this->usuarioAtual());
        }

        $this->vincularReversa($solId, $reversaId);

        return ['ok' => true, 'reversa_id' => $reversaId];
    }

    /**
     * Emite a etiqueta reversa DE VERDADE. Custa dinheiro — so no clique.
     *
     * Escreve de volta em solicitacoes_devolucao o codigo que a transportadora
     * devolveu, para que a tela do cliente e o e-mail passem a mostrar um
     * codigo que existe.
     */
    private function emitirEtiquetaReversa(int $solId, ?int $adminId = null): array {
        $sol = $this->findById($solId);
        if (!$sol) return ['ok' => false, 'msg' => 'Solicitação não encontrada.'];

        $reversaId = (int)($sol['reversa_id'] ?? 0);
        if ($reversaId <= 0) {
            $pedido = $this->getPedidoById((int)$sol['pedido_id']);
            $abriu  = $this->abrirReversa($solId, (int)$sol['cliente_id'], $pedido ?: []);
            if (empty($abriu['ok'])) return $abriu;
            $reversaId = (int)$abriu['reversa_id'];
        }

        [$transportadoraId, $servico] = $this->servicoReversaPadrao();
        if ($transportadoraId <= 0 || $servico === '') {
            return ['ok' => false, 'msg' => 'Nenhuma transportadora ativa com serviço de reversa configurado.'];
        }

        $itens = $this->getItens($solId);
        // A reversa dos Correios nao usa dimensoes: os volumes so alimentam a
        // descricao do conteudo. Um volume por item declarado.
        $volumes = [];
        foreach ($itens as $i) {
            $volumes[] = ['peso_g' => 300, 'altura_cm' => 10, 'largura_cm' => 15, 'comprimento_cm' => 20];
        }
        if (!$volumes) {
            $volumes[] = ['peso_g' => 300, 'altura_cm' => 10, 'largura_cm' => 15, 'comprimento_cm' => 20];
        }

        $res = $this->reversa->gerarEtiqueta($reversaId, [
            'transportadora_id' => $transportadoraId,
            'servico_codigo'    => $servico,
            'servico_nome'      => 'Reversa',
            'volumes'           => $volumes,
            'remetente'         => $this->dadosClienteParaReversa((int)$sol['cliente_id']),
            'produtos'          => array_map(static fn($i) => [
                'descricao'  => $i['nome_produto'] ?? 'Item',
                'quantidade' => (int)($i['quantidade'] ?? 1),
            ], $itens),
            'observacao'        => 'Devolucao #' . $solId,
        ], $adminId ?? $this->usuarioAtual());

        if (empty($res['ok'])) {
            return ['ok' => false, 'msg' => $res['erro'] ?? 'Falha ao gerar a etiqueta reversa.'];
        }

        // O codigo fica na reversa; le de la para gravar na solicitacao.
        $rev      = $this->reversa->obter($reversaId) ?: [];
        $codigo   = (string)($rev['codigo_rastreio'] ?? '');
        $validade = null;
        if (!empty($rev['validade_em'])) {
            $dias = (int) floor((strtotime((string)$rev['validade_em']) - time()) / 86400);
            $validade = $dias > 0 ? $dias : null;
        }

        if ($codigo !== '') {
            $this->db->prepare(
                "UPDATE solicitacoes_devolucao
                    SET codigo_postagem_reversa = ?,
                        codigo_validade_dias    = COALESCE(?, codigo_validade_dias),
                        atualizado_em           = NOW()
                  WHERE id = ?"
            )->execute([$codigo, $validade, $solId]);
        }

        return [
            'ok'          => true,
            'cod'         => $codigo,
            'validate'    => $validade,
            'reversa_id'  => $reversaId,
            'etiqueta_id' => $res['etiqueta_id'] ?? null,
            'url_pdf'     => $res['url_pdf'] ?? null,
            'rastreio'    => $res['rastreio'] ?? null,
        ];
    }

    /** Grava o vinculo devolucao -> reversa (tolera a coluna ainda nao migrada). */
    private function vincularReversa(int $solId, int $reversaId): void {
        try {
            $this->db->prepare("UPDATE solicitacoes_devolucao SET reversa_id = ?, atualizado_em = NOW() WHERE id = ?")
                     ->execute([$reversaId, $solId]);
        } catch (\Throwable $e) {
            // Sem a coluna (migration nao aplicada) o fluxo continua, mas cada
            // aprovacao abriria uma reversa nova — por isso o aviso e explicito.
            if (class_exists('LogService')) {
                LogService::warning('devolucao: coluna reversa_id ausente — rode sql/devolucao_reversa_link_migration.sql', [
                    'solicitacao_id' => $solId, 'reversa_id' => $reversaId, 'erro' => $e->getMessage(),
                ]);
            }
        }
    }

    /** Transportadora + codigo de servico usados na reversa. */
    private function servicoReversaPadrao(): array {
        try {
            $t = $this->db->query(
                "SELECT id, config FROM log_transportadoras
                  WHERE status = 'ativo' AND adapter = 'CorreiosAdapter'
                  ORDER BY prioridade ASC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);

            if ($t) {
                $cfg = json_decode((string)$t['config'], true) ?: [];
                $cod = (string)($cfg['reversa_codigo_servico'] ?? '');
                if ($cod !== '') return [(int)$t['id'], $cod];

                // Sem codigo na config, cai no primeiro servico de modalidade reversa.
                $s = $this->db->prepare(
                    "SELECT codigo FROM log_transportadora_servicos
                      WHERE transportadora_id = ? AND habilitado = 1 AND modalidade = 'reverso'
                      ORDER BY prioridade ASC, nome ASC LIMIT 1"
                );
                $s->execute([(int)$t['id']]);
                $cod = (string)($s->fetchColumn() ?: '');
                if ($cod !== '') return [(int)$t['id'], $cod];
            }
        } catch (\Throwable $e) {
            if (class_exists('LogService')) {
                LogService::error('Falha ao resolver serviço de reversa', ['erro' => $e->getMessage()]);
            }
        }
        return [0, ''];
    }

    /** Endereco do cliente, que e o REMETENTE da volta. */
    private function dadosClienteParaReversa(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT u.nome, u.email, c.cpf, c.telefone,
                    e.cep, e.logradouro, e.numero, e.complemento, e.bairro, e.cidade, e.estado
               FROM clientes c
               JOIN usuarios u ON u.id = c.usuario_id
          LEFT JOIN enderecos e ON e.id = (
                    SELECT id FROM enderecos WHERE cliente_id = c.id AND principal = 1 LIMIT 1
               )
              WHERE c.id = ? LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        $d = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$d) return [];

        $so = static fn($v) => preg_replace('/\D/', '', (string)$v);

        return [
            'nome'        => $d['nome'] ?? '',
            'email'       => $d['email'] ?? '',
            'documento'   => $so($d['cpf'] ?? ''),
            'cpf'         => $so($d['cpf'] ?? ''),
            'telefone'    => $so($d['telefone'] ?? ''),
            'cep'         => $so($d['cep'] ?? ''),
            'logradouro'  => $d['logradouro'] ?? '',
            'numero'      => $d['numero'] ?? '',
            'complemento' => $d['complemento'] ?? '',
            'bairro'      => $d['bairro'] ?? '',
            'cidade'      => $d['cidade'] ?? '',
            'uf'          => $d['estado'] ?? '',
            'estado'      => $d['estado'] ?? '',
        ];
    }

    /** usuarios.id de quem opera (null quando roda pelo cliente/worker). */
    private function usuarioAtual(): ?int {
        if (class_exists('AuthHelper')) {
            $id = (int) AuthHelper::usuarioId();
            if ($id > 0) return $id;
        }
        return null;
    }

    private function calcularValorItens(int $pedidoId, array $itens, array $pedido): array {
        $totalPedido = (float)$pedido['total'];
        $stmt        = $this->db->prepare(
            "SELECT pi.*, COALESCE(pi.nome_produto, pr.nome) AS nome_produto
             FROM pedido_itens pi
             JOIN produtos pr ON pr.id = pi.produto_id
             WHERE pi.pedido_id = ?"
        );
        $stmt->execute([$pedidoId]);
        $allItens = $stmt->fetchAll();
        $itensMap = [];
        foreach ($allItens as $pi) {
            $itensMap[$pi['id']] = $pi;
        }

        $subtotalPedido = (float)$pedido['subtotal'];
        $itensDados     = [];
        $valorTotal     = 0.0;

        // Quanto de cada item JA voltou em solicitacoes anteriores. Sem isto,
        // a mesma unidade pode ser devolvida duas vezes: a checagem de
        // "solicitacao ativa" em criar() olha o PEDIDO, e uma solicitacao
        // concluida deixa de ser ativa — liberando um segundo pedido de
        // devolucao sobre o mesmo item.
        $jaDevolvido = $this->quantidadesJaDevolvidas($pedidoId);

        foreach ($itens as $solItem) {
            $piId = (int)$solItem['pedido_item_id'];
            $pi   = $itensMap[$piId] ?? null;
            if (!$pi) continue;

            // Aqui havia `min($qtd, 1)`, com o comentario "valida qtd > 0".
            // `min` com 1 nao e piso, e TETO: quem comprou 3 e pedia as 3
            // recebia o valor de UMA, sem erro em lugar nenhum — nem no
            // formulario, nem no e-mail de confirmacao, nem no painel de BI
            // (que le esta mesma coluna).
            //
            // O que faltava era piso E teto, e o teto e a quantidade comprada
            // menos o que ja voltou antes.
            $disponivel = (int)$pi['quantidade'] - (int)($jaDevolvido[$piId] ?? 0);
            if ($disponivel <= 0) continue;

            $qtd = max(1, (int)$solItem['quantidade']);
            $qtd = min($qtd, $disponivel);

            $valorUnit = (float)$pi['preco_unitario'];
            // Desconto proporcional de cupom (se houver)
            $desconto  = $subtotalPedido > 0
                ? round(((float)$pedido['desconto']) * ($valorUnit * $qtd / $subtotalPedido), 2)
                : 0;
            $valorFinal = round(($valorUnit * $qtd) - $desconto, 2);

            $itensDados[] = [
                'pedido_item_id'       => $piId,
                'quantidade'           => $qtd,
                'valor_unitario'       => $valorUnit,
                'desconto_proporcional'=> $desconto,
                'valor_final'          => $valorFinal,
            ];
            $valorTotal += $valorFinal;
        }

        return [round($valorTotal, 2), $itensDados];
    }

    /**
     * Quanto de cada pedido_item ja saiu em devolucoes que ainda valem.
     *
     * Solicitacao cancelada, negada ou expirada nao consome saldo — o item
     * nunca voltou. As demais consomem, inclusive as em andamento: reservar
     * a quantidade na hora do pedido evita que o cliente abra duas
     * solicitacoes somando mais do que comprou.
     *
     * @return array<int,int>  pedido_item_id => quantidade
     */
    private function quantidadesJaDevolvidas(int $pedidoId): array {
        $stmt = $this->db->prepare(
            "SELECT sdi.pedido_item_id, SUM(sdi.quantidade) AS qtd
               FROM solicitacoes_devolucao_itens sdi
               JOIN solicitacoes_devolucao sd ON sd.id = sdi.solicitacao_id
              WHERE sd.pedido_id = ?
                AND sd.status NOT IN ('cancelado','negado','expirado')
           GROUP BY sdi.pedido_item_id"
        );
        $stmt->execute([$pedidoId]);

        $mapa = [];
        foreach ($stmt->fetchAll() as $r) {
            $mapa[(int)$r['pedido_item_id']] = (int)$r['qtd'];
        }
        return $mapa;
    }

    private function logStatus(int $solId, string $status, ?string $obs = null, ?int $adminId = null): void {
        $this->db->prepare(
            "INSERT INTO solicitacoes_devolucao_historico (solicitacao_id, status_novo, observacao, admin_id)
             VALUES (?,?,?,?)"
        )->execute([$solId, $status, $obs, $adminId]);
    }

    private function getPedido(int $pedidoId, int $clienteId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM pedidos WHERE id = ? AND cliente_id = ? LIMIT 1"
        );
        $stmt->execute([$pedidoId, $clienteId]);
        return $stmt->fetch() ?: null;
    }

    private function getPedidoById(int $pedidoId): array {
        $stmt = $this->db->prepare("SELECT * FROM pedidos WHERE id = ? LIMIT 1");
        $stmt->execute([$pedidoId]);
        return $stmt->fetch() ?: [];
    }

    private function diasDesde(string $dataStr): int {
        $data = new \DateTime($dataStr);
        $hoje = new \DateTime();
        return (int)$hoje->diff($data)->days;
    }

    private function adicionarDiasUteis(string $dataBase, int $dias): string {
        $dt = new \DateTime($dataBase);
        $adicionados = 0;
        while ($adicionados < $dias) {
            $dt->modify('+1 day');
            $dow = (int)$dt->format('N'); // 1=Mon...7=Sun
            if ($dow < 6) $adicionados++;
        }
        return $dt->format('Y-m-d H:i:s');
    }

    private function buildWhere(array $filtros): array {
        $where  = ['1=1'];
        $params = [];
        if (!empty($filtros['cliente_id'])) {
            $where[]  = 's.cliente_id = ?';
            $params[] = (int)$filtros['cliente_id'];
        }
        if (!empty($filtros['status'])) {
            $where[]  = 's.status = ?';
            $params[] = $filtros['status'];
        }
        if (!empty($filtros['tipo'])) {
            $where[]  = 's.tipo = ?';
            $params[] = $filtros['tipo'];
        }
        if (!empty($filtros['q'])) {
            $like     = '%' . $filtros['q'] . '%';
            $where[]  = '(u.nome LIKE ? OR u.email LIKE ? OR p.codigo LIKE ?)';
            $params   = array_merge($params, [$like, $like, $like]);
        }
        return [implode(' AND ', $where), $params];
    }
}