<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/CouponService.php
//
// TODA a lógica de negócio de cupons está aqui.
// Controllers só recebem requisições e delegam para este service.
// Nenhuma regra financeira é tomada no front-end.
// ════════════════════════════════════════════════════════

class CouponService {

    private const RATE_LIMIT_IP       = 10;  // tentativas/hora por IP
    private const RATE_LIMIT_CLIENTE  = 20;  // tentativas/hora por cliente
    private const RATE_LIMIT_JANELA   = 3600; // segundos

    private PDO    $db;
    private Coupon $couponModel;

    public function __construct() {
        $this->db          = Database::getInstance()->getConnection();
        $this->couponModel = new Coupon();
    }

    // ════════════════════════════════════════════════════
    // INTERFACE PÚBLICA
    // ════════════════════════════════════════════════════

    /**
     * Valida e calcula um cupom. Não persiste nada.
     * Usar em: carrinho, checkout, recalculo de frete, etc.
     *
     * @param string   $codigo
     * @param array    $itens         [ {id, produto_id, preco, qtd, categoria_id, marca_id, em_promocao} ]
     * @param float    $subtotal
     * @param float    $frete
     * @param int|null $clienteId
     * @param array    $opcoes        ['origem','forma_pagamento','carrinho_id']
     *
     * @return array {ok, cupom?, desconto, frete_desconto, msg, codigo_erro, itens, regra}
     */
    public function validar(
        string $codigo,
        array  $itens,
        float  $subtotal,
        float  $frete,
        ?int   $clienteId,
        array  $opcoes = []
    ): array {
        $origem   = $opcoes['origem']    ?? 'carrinho';
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua       = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

        // ── 1. Rate limiting (anti brute-force) ─────────
        // if ($this->rateLimitExcedido($ip, $clienteId)) {
        //     return $this->erro('RATE_LIMIT', 'Muitas tentativas. Aguarde alguns minutos.', $codigo);
        // }

        // ── 2. Cupom existe ──────────────────────────────
        $cupom = $this->couponModel->findByCodigo($codigo);
        if (!$cupom) {
            $this->audit($codigo, null, $clienteId, 'validar', 'recusado', 'Cupom não encontrado', $subtotal, 0, $frete, $ip, $ua, $origem);
            return $this->erro('NAO_ENCONTRADO', 'Cupom inválido ou não encontrado.', $codigo);
        }

        // ── 3. Ativo ─────────────────────────────────────
        if (!$cupom['ativo']) {
            $this->audit($codigo, $cupom['id'], $clienteId, 'validar', 'recusado', 'Cupom inativo', $subtotal, 0, $frete, $ip, $ua, $origem);
            return $this->erro('INATIVO', 'Este cupom não está mais disponível.', $codigo);
        }

        // ── 4. Período de validade ────────────────────────
        $now = time();
        if ($cupom['data_inicio'] && strtotime($cupom['data_inicio']) > $now) {
            return $this->erro('AINDA_NAO_VALIDO', 'Este cupom ainda não está válido.', $codigo);
        }
        if ($cupom['data_fim'] && strtotime($cupom['data_fim']) < $now) {
            $this->audit($codigo, $cupom['id'], $clienteId, 'validar', 'recusado', 'Cupom expirado', $subtotal, 0, $frete, $ip, $ua, $origem);
            return $this->erro('EXPIRADO', 'Este cupom expirou.', $codigo);
        }

        // ── 5. Limite total de usos ───────────────────────
        if ($cupom['limite_total'] !== null && $cupom['total_usos'] >= $cupom['limite_total']) {
            $this->audit($codigo, $cupom['id'], $clienteId, 'validar', 'recusado', 'Limite total atingido', $subtotal, 0, $frete, $ip, $ua, $origem);
            return $this->erro('LIMITE_ATINGIDO', 'Este cupom já foi utilizado o número máximo de vezes.', $codigo);
        }

        // ── 6. Limite por cliente ─────────────────────────
        if ($clienteId) {
            $usosPorCliente = $this->couponModel->usosPorCliente($cupom['id'], $clienteId);
            if ($usosPorCliente >= (int)$cupom['limite_por_cliente']) {
                $this->audit($codigo, $cupom['id'], $clienteId, 'validar', 'recusado', 'Limite por cliente atingido', $subtotal, 0, $frete, $ip, $ua, $origem);
                return $this->erro('LIMITE_CLIENTE', 'Você já utilizou este cupom o número máximo de vezes.', $codigo);
            }
        }

        // ── 7. Cupom exclusivo por cliente ───────────────
        if ($cupom['escopo_clientes']) {
            $clientesPermitidos = $this->decodeJson($cupom['escopo_clientes']);
            if ($clientesPermitidos && ($clienteId === null || !in_array($clienteId, $clientesPermitidos, true))) {
                $this->audit($codigo, $cupom['id'], $clienteId, 'validar', 'recusado', 'Cupom exclusivo para outro cliente', $subtotal, 0, $frete, $ip, $ua, $origem);
                return $this->erro('EXCLUSIVO', 'Este cupom é exclusivo para outro cliente.', $codigo);
            }
        }

        // ── 8. Primeira compra ───────────────────────────
        if ($cupom['apenas_primeira_compra'] && $clienteId) {
            $temPedido = $this->clienteTemPedidoConfirmado($clienteId);
            if ($temPedido) {
                $this->audit($codigo, $cupom['id'], $clienteId, 'validar', 'recusado', 'Não é primeira compra', $subtotal, 0, $frete, $ip, $ua, $origem);
                return $this->erro('PRIMEIRA_COMPRA', 'Este cupom é válido apenas para a primeira compra.', $codigo);
            }
        }

        // ── 9. Elegibilidade dos itens ────────────────────
        $elegiveis    = $this->filtrarItensElegiveis($cupom, $itens);
        $naoElegiveis = array_filter($itens, fn($i) => !in_array($i, $elegiveis));

        if (empty($elegiveis) && !in_array($cupom['tipo'], ['frete_gratis','automatico'], true)) {
            $this->audit($codigo, $cupom['id'], $clienteId, 'validar', 'recusado', 'Nenhum produto elegível', $subtotal, 0, $frete, $ip, $ua, $origem);
            return $this->erro('PRODUTOS_INELEGIVEIS', 'Este cupom não é válido para os produtos do seu carrinho.', $codigo);
        }

        // ── 10. Produtos em promoção ──────────────────────
        if (!$cupom['permite_produto_promo']) {
            $temPromo = count(array_filter($elegiveis, fn($i) => !empty($i['em_promocao']))) > 0;
            if ($temPromo) {
                return $this->erro('COM_PROMOCAO', 'Este cupom não pode ser combinado com produtos em promoção.', $codigo);
            }
        }

        // ── 11. Valor mínimo do pedido ────────────────────
        $subtotalElegivel = array_sum(array_map(
            fn($i) => (float)$i['preco'] * (int)$i['qtd'],
            $elegiveis
        ));

        $baseVerificacao = $subtotalElegivel ?: $subtotal;
        if ((float)$cupom['valor_minimo_pedido'] > 0 && $baseVerificacao < (float)$cupom['valor_minimo_pedido']) {
            $minFmt = PriceHelper::format((float)$cupom['valor_minimo_pedido']);
            $this->audit($codigo, $cupom['id'], $clienteId, 'validar', 'recusado', "Valor mínimo não atingido ({$minFmt})", $subtotal, 0, $frete, $ip, $ua, $origem);
            return $this->erro('VALOR_MINIMO', "Este cupom exige valor mínimo de {$minFmt}.", $codigo);
        }

        // ── 12. Cálculo do desconto ───────────────────────
        $calculo = $this->calcularDesconto($cupom, $elegiveis, $subtotalElegivel, $frete);

        if ($calculo['desconto'] <= 0 && $calculo['frete_desconto'] <= 0) {
            return $this->erro('SEM_DESCONTO', 'Este cupom não gera desconto para o seu carrinho.', $codigo);
        }

        // ── 13. Teto do desconto ──────────────────────────
        if ($cupom['valor_maximo'] && $calculo['desconto'] > (float)$cupom['valor_maximo']) {
            $calculo['desconto'] = (float)$cupom['valor_maximo'];
        }

        // ── 14. Nenhum item com valor negativo ────────────
        foreach ($elegiveis as $item) {
            $valorItem  = (float)$item['preco'] * (int)$item['qtd'];
            $pesoItem   = $subtotalElegivel > 0 ? $valorItem / $subtotalElegivel : 0;
            $descItem   = $calculo['desconto'] * $pesoItem;
            if ($valorItem - $descItem < 0) {
                $calculo['desconto'] = $subtotalElegivel; // limita ao subtotal
                break;
            }
        }

        // ── 15. Distribui desconto por item ──────────────
        $itensComDesconto = $this->distribuirDesconto($elegiveis, $calculo['desconto'], $subtotalElegivel);

        // ── Auditoria de sucesso ──────────────────────────
        $this->audit(
            $codigo, $cupom['id'], $clienteId,
            'validar', 'aprovado', null,
            $subtotal, $calculo['desconto'], $frete,
            $ip, $ua, $origem,
            array_column($elegiveis, 'produto_id'),
            array_column(iterator_to_array((function () use ($naoElegiveis) { yield from $naoElegiveis; })()), 'produto_id'),
            $cupom['tipo']
        );

        return [
            'ok'              => true,
            'cupom'           => $this->sanitizarCupomParaFrontend($cupom),
            'desconto'        => $calculo['desconto'],
            'frete_desconto'  => $calculo['frete_desconto'],
            'total_desconto'  => $calculo['desconto'] + $calculo['frete_desconto'],
            'itens'           => $itensComDesconto,
            'msg'             => 'Cupom aplicado com sucesso.',
            'regra'           => $cupom['tipo'],
        ];
    }

    /**
     * Reserva o uso do cupom (dentro de transação, antes do pagamento).
     * Previne race condition com FOR UPDATE.
     */
    public function reservar(
        int   $cupomId,
        int   $pedidoId,
        ?int  $clienteId,
        float $valorDesconto,
        float $valorFreteDesc,
        float $valorOriginal,
        array $itensSnapshot = []
    ): int {
        // SEM beginTransaction() aqui — reservar() é chamado dentro da
        // transação já aberta em CheckoutController::process() (que cobre
        // pedido + itens + estoque + cupom como uma unidade atômica).
        //
        // Abrir uma segunda transação causava "There is already an active
        // transaction" porque PDO/MySQL não suporta transações aninhadas.
        // O FOR UPDATE de totalUsosComLock() continua efetivo — funciona
        // normalmente dentro da transação externa, bloqueando outras
        // transações que tentem reservar o mesmo cupom simultaneamente.
        // Se algo falhar, o rollBack() do chamador reverte tudo.

        // Lock no registro — bloqueia outras transações simultâneas
        $totalUsos = $this->couponModel->totalUsosComLock($cupomId);
        $cupom     = $this->couponModel->findById($cupomId);

        if (!$cupom) throw new \RuntimeException('Cupom não encontrado.');

        // Re-verifica limite (pode ter mudado entre validar e reservar)
        if ($cupom['limite_total'] !== null && $totalUsos >= $cupom['limite_total']) {
            throw new CouponException('LIMITE_ATINGIDO', 'Cupom esgotado. Tente outro.');
        }

        // Insere reserva
        $this->db->prepare(
            "INSERT INTO cupom_usos
             (cupom_id, cliente_id, pedido_id, status,
              valor_original, valor_desconto, valor_frete_desc, valor_final,
              itens_snapshot, ip, user_agent)
             VALUES (?,?,?,'reservado',?,?,?,?,?,?,?)"
        )->execute([
            $cupomId, $clienteId, $pedidoId,
            $valorOriginal,
            $valorDesconto,
            $valorFreteDesc,
            $valorOriginal - $valorDesconto,
            json_encode($itensSnapshot, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Confirma o uso após pagamento aprovado.
     */
    public function confirmar(int $usoId): void {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT cu.*, c.tipo FROM cupom_usos cu
                 JOIN cupons c ON c.id = cu.cupom_id
                 WHERE cu.id = ? FOR UPDATE"
            );
            $stmt->execute([$usoId]);
            $uso = $stmt->fetch();

            if (!$uso || $uso['status'] !== 'reservado') {
                $this->db->rollBack();
                return;
            }

            $this->db->prepare(
                "UPDATE cupom_usos SET status = 'confirmado' WHERE id = ?"
            )->execute([$usoId]);

            $this->couponModel->incrementarUso(
                (int)$uso['cupom_id'],
                (float)$uso['valor_desconto'] + (float)$uso['valor_frete_desc']
            );

            $this->db->commit();
            $this->auditUso($uso, 'confirmar', 'aprovado');

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Cancela/libera uma reserva (pagamento recusado, pedido cancelado).
     */
    public function cancelar(int $usoId, string $motivo = ''): void {
        $stmt = $this->db->prepare(
            "UPDATE cupom_usos SET status = 'cancelado' WHERE id = ? AND status IN ('reservado','aplicado')"
        );
        $stmt->execute([$usoId]);
    }

    /**
     * Estorna um uso confirmado (devolução total).
     */
    public function estornar(int $usoId, string $motivo = ''): void {
        $stmt = $this->db->prepare(
            "SELECT * FROM cupom_usos WHERE id = ? AND status = 'confirmado' LIMIT 1"
        );
        $stmt->execute([$usoId]);
        $uso = $stmt->fetch();
        if (!$uso) return;

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "UPDATE cupom_usos SET status = 'estornado' WHERE id = ?"
            )->execute([$usoId]);

            // Reverte contadores
            $desconto = (float)$uso['valor_desconto'] + (float)$uso['valor_frete_desc'];
            $this->db->prepare(
                "UPDATE cupons
                 SET total_usos = GREATEST(0, total_usos - 1),
                     total_desconto_concedido = GREATEST(0, total_desconto_concedido - ?)
                 WHERE id = ?"
            )->execute([$desconto, $uso['cupom_id']]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ════════════════════════════════════════════════════
    // CÁLCULO DE DESCONTO
    // ════════════════════════════════════════════════════

    private function calcularDesconto(
        array $cupom,
        array $elegiveis,
        float $subtotalElegivel,
        float $frete
    ): array {
        $desconto      = 0.0;
        $freteDesconto = 0.0;

        switch ($cupom['tipo']) {

            case 'percentual':
                $desconto = $subtotalElegivel * ((float)$cupom['valor'] / 100);
                break;

            case 'fixo':
                $desconto = min((float)$cupom['valor'], $subtotalElegivel);
                break;

            case 'frete_gratis':
                $freteDesconto = $frete;
                break;

            case 'progressivo':
                $regras = $this->decodeJson($cupom['regras_progressivas']) ?? [];
                usort($regras, fn($a,$b) => $b['min'] <=> $a['min']); // maior → menor
                foreach ($regras as $regra) {
                    $minOk  = $subtotalElegivel >= (float)$regra['min'];
                    $maxOk  = empty($regra['max']) || $subtotalElegivel <= (float)$regra['max'];
                    if ($minOk && $maxOk) {
                        $valor = (float)$regra['valor'];
                        $desconto = str_ends_with((string)$regra['tipo'] ?? '', '%')
                            ? $subtotalElegivel * ($valor / 100)
                            : min($valor, $subtotalElegivel);
                        break;
                    }
                }
                break;

            case 'automatico':
            case 'campanha':
            case 'recuperacao_carrinho':
                // Usa valor/tipo definido (percentual ou fixo)
                if (str_contains((string)$cupom['valor'], '.') || is_numeric($cupom['valor'])) {
                    $desconto = $cupom['valor'] <= 100
                        ? $subtotalElegivel * ((float)$cupom['valor'] / 100)
                        : min((float)$cupom['valor'], $subtotalElegivel);
                }
                break;

            case 'primeira_compra':
                $desconto = $subtotalElegivel * ((float)$cupom['valor'] / 100);
                break;

            case 'exclusivo':
                $desconto = $subtotalElegivel * ((float)$cupom['valor'] / 100);
                break;
        }

        return [
            'desconto'       => round($desconto, 2),
            'frete_desconto' => round($freteDesconto, 2),
        ];
    }

    /**
     * Distribui o desconto proporcionalmente entre os itens elegíveis.
     * Essencial para devolução parcial, troca parcial, cancelamento.
     */
    private function distribuirDesconto(
        array $elegiveis,
        float $totalDesconto,
        float $subtotalElegivel
    ): array {
        if ($subtotalElegivel <= 0 || $totalDesconto <= 0) {
            return array_map(fn($i) => $i + ['desconto_cupom' => 0.0, 'valor_final' => (float)$i['preco']], $elegiveis);
        }

        $resultado  = [];
        $distribuido = 0.0;
        $count       = count($elegiveis);

        foreach ($elegiveis as $idx => $item) {
            $valorItem = (float)$item['preco'] * (int)$item['qtd'];
            $peso      = $valorItem / $subtotalElegivel;

            // Último item absorve o resto (evita erro de arredondamento)
            $desconto = ($idx === $count - 1)
                ? $totalDesconto - $distribuido
                : round($totalDesconto * $peso, 2);

            $distribuido += $desconto;

            $resultado[] = array_merge($item, [
                'desconto_cupom' => $desconto,
                'valor_final'    => round($valorItem - $desconto, 2),
            ]);
        }

        return $resultado;
    }

    // ════════════════════════════════════════════════════
    // FILTRO DE ELEGIBILIDADE
    // ════════════════════════════════════════════════════

    private function filtrarItensElegiveis(array $cupom, array $itens): array {
        $produtosPermitidos  = $this->decodeJson($cupom['escopo_produtos'])   ?? [];
        $categoriasPermitidas = $this->decodeJson($cupom['escopo_categorias']) ?? [];
        $marcasPermitidas     = $this->decodeJson($cupom['escopo_marcas'])     ?? [];

        // Sem escopo = todos elegíveis
        $semEscopo = empty($produtosPermitidos) && empty($categoriasPermitidas) && empty($marcasPermitidas);
        if ($semEscopo) return $itens;

        return array_values(array_filter($itens, function ($item) use (
            $produtosPermitidos, $categoriasPermitidas, $marcasPermitidas
        ) {
            $okProduto   = empty($produtosPermitidos)   || in_array((int)$item['produto_id'],  $produtosPermitidos,   true);
            $okCategoria = empty($categoriasPermitidas) || in_array((int)$item['categoria_id'],$categoriasPermitidas, true);
            $okMarca     = empty($marcasPermitidas)      || in_array((int)$item['marca_id'],    $marcasPermitidas,     true);
            return $okProduto || $okCategoria || $okMarca;
        }));
    }

    // ════════════════════════════════════════════════════
    // RATE LIMITING
    // ════════════════════════════════════════════════════

    private function rateLimitExcedido(string $ip, ?int $clienteId): bool {
        $chaves = ["ip:{$ip}"];
        if ($clienteId) $chaves[] = "cli:{$clienteId}";

        foreach ($chaves as $chave) {
            try {
                $stmt = $this->db->prepare(
                    "INSERT INTO cupom_rate_limit (chave, tentativas, janela_em)
                     VALUES (?, 1, NOW())
                     ON DUPLICATE KEY UPDATE
                       tentativas = IF(
                         TIMESTAMPDIFF(SECOND, janela_em, NOW()) > ?,
                         1,
                         tentativas + 1
                       ),
                       janela_em = IF(
                         TIMESTAMPDIFF(SECOND, janela_em, NOW()) > ?,
                         NOW(),
                         janela_em
                       ),
                       bloqueado = (tentativas + 1) >= ?"
                );
                $limit = str_starts_with($chave, 'ip:') ? self::RATE_LIMIT_IP : self::RATE_LIMIT_CLIENTE;
                $stmt->execute([$chave, self::RATE_LIMIT_JANELA, self::RATE_LIMIT_JANELA, $limit]);

                $row = $this->db->query("SELECT tentativas, bloqueado FROM cupom_rate_limit WHERE chave = " . $this->db->quote($chave))->fetch();
                if ($row && $row['bloqueado']) return true;

            } catch (\Throwable $e) {
                // Se tabela não existir, não bloqueia
            }
        }
        return false;
    }

    // ════════════════════════════════════════════════════
    // AUDITORIA
    // ════════════════════════════════════════════════════

    private function audit(
        string  $codigo,
        ?int    $cupomId,
        ?int    $clienteId,
        string  $acao,
        string  $resultado,
        ?string $motivo,
        float   $valorCarrinho,
        float   $valorDesconto,
        float   $frete,
        string  $ip,
        string  $ua,
        string  $origem,
        array   $elegiveis    = [],
        array   $naoElegiveis = [],
        ?string $regra        = null
    ): void {
        try {
            // Busca dados do cliente se disponível
            $clienteEmail = $clienteCpf = null;
            if ($clienteId) {
                $c = $this->db->prepare("SELECT email, cpf FROM clientes WHERE id = ? LIMIT 1");
                $c->execute([$clienteId]);
                $cl = $c->fetch();
                $clienteEmail = $cl['email']  ?? null;
                $clienteCpf   = $cl['cpf']    ?? null;
            }

            $this->db->prepare(
                "INSERT INTO cupom_auditoria
                 (cupom_id, cupom_codigo, cliente_id, cliente_email, cliente_cpf,
                  acao, resultado, motivo_recusa,
                  valor_carrinho, valor_desconto, valor_final,
                  produtos_elegiveis, produtos_nao_elegiveis,
                  regra_aplicada, origem, ip, user_agent)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $cupomId, strtoupper($codigo), $clienteId, $clienteEmail, $clienteCpf,
                $acao, $resultado, $motivo,
                $valorCarrinho, $valorDesconto, $valorCarrinho - $valorDesconto,
                $elegiveis    ? json_encode($elegiveis)    : null,
                $naoElegiveis ? json_encode($naoElegiveis) : null,
                $regra, $origem, $ip, $ua,
            ]);

            // Incrementa recusas no model
            if ($resultado === 'recusado' && $cupomId) {
                $this->couponModel->incrementarRecusa($cupomId);
            }
        } catch (\Throwable $e) {
            error_log('[CouponService] Erro de auditoria: ' . $e->getMessage());
        }
    }

    private function auditUso(array $uso, string $acao, string $resultado): void {
        $this->audit(
            '', (int)$uso['cupom_id'], (int)$uso['cliente_id'],
            $acao, $resultado, null,
            (float)$uso['valor_original'], (float)$uso['valor_desconto'], 0,
            $uso['ip'] ?? '', $uso['user_agent'] ?? '', 'pedido'
        );
    }

    // ════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════

    private function erro(string $codigo, string $msg, string $cupomCodigo = ''): array {
        return ['ok' => false, 'codigo_erro' => $codigo, 'msg' => $msg,
                'desconto' => 0, 'frete_desconto' => 0, 'itens' => []];
    }

    private function sanitizarCupomParaFrontend(array $cupom): array {
        // Nunca expõe escopos, regras internas ou contadores sensíveis ao front
        return [
            'id'          => $cupom['id'],
            'codigo'      => $cupom['codigo'],
            'tipo'        => $cupom['tipo'],
            'descricao'   => $cupom['descricao'],
            'data_fim'    => $cupom['data_fim'],
        ];
    }

    private function decodeJson(?string $json): ?array {
        if (!$json) return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function clienteTemPedidoConfirmado(int $clienteId): bool {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM pedidos WHERE cliente_id = ? AND status_pagamento = 'aprovado' LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        return (bool)$stmt->fetchColumn();
    }
}

// ── Exceção tipada para o service ─────────────────────────
class CouponException extends \RuntimeException {
    public function __construct(
        public readonly string $codigoErro,
        string $message,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}