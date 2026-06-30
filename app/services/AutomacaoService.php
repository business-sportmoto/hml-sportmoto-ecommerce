<?php
/**
 * app/services/AutomacaoService.php
 *
 * Motor de detecção — varre as tabelas e enfileira clientes
 * nos fluxos correspondentes. Chamado pelo worker a cada 5min.
 */
class AutomacaoService
{
    /** @var PDO */
    private $db;
    /** @var AutomacaoModel */
    private $model;

    public function __construct()
    {
        $this->db    = Database::getInstance()->getConnection();
        $this->model = new AutomacaoModel();
    }

    /**
     * Ponto de entrada do worker — roda todas as detecções.
     * Retorna array com contagem de enfileirados por tipo.
     */
    public function detectarTodos(): array
    {
        $resultado = [];
        $fluxos    = $this->model->todosFluxos();

        foreach ($fluxos as $fluxo) {
            if (!$fluxo['ativo']) continue;
            $cfg  = json_decode($fluxo['config_json'] ?? '{}', true) ?: [];
            $tipo = $fluxo['tipo'];

            try {
                $n = 0;
                switch ($tipo) {
                    case 'carrinho_abandonado':
                        $n = $this->detectarCarrinhoAbandonado($fluxo, $cfg); break;
                    case 'produto_visitado':
                        $n = $this->detectarProdutoVisitado($fluxo, $cfg); break;
                    case 'categoria_visitada':
                        $n = $this->detectarCategoriaVisitada($fluxo, $cfg); break;
                    case 'wishlist':
                        $n = $this->detectarWishlist($fluxo, $cfg); break;
                    case 'aniversario':
                        $n = $this->detectarAniversario($fluxo, $cfg); break;
                    case 'pos_compra_complementar':
                    case 'pos_compra_avaliacao':
                        $n = $this->detectarPosCompra($fluxo, $cfg); break;
                    case 'lancamento_moto':
                        $n = $this->detectarLancamentoMoto($fluxo, $cfg); break;
                    case 'reengajamento':
                        $n = $this->detectarReengajamento($fluxo, $cfg); break;
                    case 'boas_vindas':
                        $n = $this->detectarBoasVindas($fluxo, $cfg); break;
                }
                $resultado[$tipo] = $n;
            } catch (Throwable $e) {
                if (class_exists('LogService')) {
                    LogService::error("automacao_detectar[$tipo]: " . $e->getMessage());
                }
                // $resultado[$tipo . '_erro'] = $e->getMessage();

                // Ignora duplicate key — item já está na fila aguardando envio
                if (strpos($e->getMessage(), '1062') !== false) continue;
                $resultado[$tipo . '_erro'] = $e->getMessage();
            }
        }
        return $resultado;
    }

    // =========================================================================
    // 1. CARRINHO ABANDONADO
    // =========================================================================
    private function detectarCarrinhoAbandonado(array $fluxo, array $cfg): int
    {
        $passos = $this->model->passos((int)$fluxo['id']);
        if (!$passos) return 0;
        $delays = $cfg['delays_horas'] ?? [1, 24, 72];

        // Carrinhos com itens, de clientes logados, não convertidos em pedido
        $st = $this->db->query(
            "SELECT c.id AS carrinho_id, c.cliente_id, c.atualizado_em
             FROM carrinhos c
             WHERE c.cliente_id IS NOT NULL
               AND EXISTS (
                   SELECT 1 FROM carrinho_itens ci WHERE ci.carrinho_id = c.id
               )
               AND NOT EXISTS (
                   SELECT 1 FROM pedidos p
                   WHERE p.cliente_id = c.cliente_id
                     AND p.criado_em >= c.criado_em
                     AND p.status_pagamento IN ('aprovado','pendente')
               )
               AND c.atualizado_em < DATE_SUB(NOW(), INTERVAL 55 MINUTE)
               AND c.atualizado_em > DATE_SUB(NOW(), INTERVAL 7 DAY)
             LIMIT 500"
        );
        $carrinhos = $st->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;

        foreach ($carrinhos as $i => $c) {
            $clienteId  = (int)$c['cliente_id'];
            $carrinhoId = (int)$c['carrinho_id'];
            $atualizadoEm = strtotime($c['atualizado_em']);

            foreach ($passos as $idx => $passo) {
                $delayHoras = $delays[$idx] ?? $passo['delay_horas'];
                $disparoEm  = date('Y-m-d H:i:s', $atualizadoEm + ($delayHoras * 3600));
                $dedup = 'cart_' . $carrinhoId . '_p' . $passo['id'];

                $id = $this->model->enfileirar([
                    'fluxo_id'   => $fluxo['id'],
                    'passo_id'   => $passo['id'],
                    'cliente_id' => $clienteId,
                    'contexto'   => ['carrinho_id' => $carrinhoId],
                    'disparo_em' => $disparoEm,
                    'chave_dedup' => $dedup,
                ]);
                if ($id) $n++;
            }
        }
        return $n;
    }

    // =========================================================================
    // 2. PRODUTO VISITADO
    // =========================================================================
    private function detectarProdutoVisitado(array $fluxo, array $cfg): int
    {
        $passos    = $this->model->passos((int)$fluxo['id']);
        if (!$passos) return 0;
        $minVisitas = $cfg['min_visitas'] ?? 2;
        $delays     = $cfg['delays_horas'] ?? [2, 48];

        // Produtos visitados N+ vezes nas últimas 7 dias, sem compra
        $st = $this->db->prepare(
            "SELECT h.cliente_id, h.referencia_id AS produto_id,
                    COUNT(h.id) AS visitas, MAX(h.criado_em) AS ultima_visita
             FROM historico_navegacao h
             WHERE h.tipo = 'produto'
               AND h.cliente_id IS NOT NULL
               AND h.criado_em > DATE_SUB(NOW(), INTERVAL 7 DAY)
               AND NOT EXISTS (
                   SELECT 1 FROM pedido_itens pi
                   JOIN pedidos ped ON ped.id = pi.pedido_id
                   WHERE pi.produto_id = h.referencia_id
                     AND ped.cliente_id = h.cliente_id
                     AND ped.status_pagamento = 'aprovado'
                     AND ped.criado_em > DATE_SUB(NOW(), INTERVAL 30 DAY)
               )
             GROUP BY h.cliente_id, h.referencia_id
             HAVING visitas >= :mv
             LIMIT 300"
        );
        $st->execute([':mv' => $minVisitas]);
        $visitas = $st->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;

        foreach ($visitas as $v) {
            $clienteId = (int)$v['cliente_id'];
            $produtoId = (int)$v['produto_id'];
            $baseTs    = strtotime($v['ultima_visita']);

            foreach ($passos as $idx => $passo) {
                $delayHoras = $delays[$idx] ?? $passo['delay_horas'];
                $disparoEm  = date('Y-m-d H:i:s', $baseTs + ($delayHoras * 3600));
                $dedup = 'pv_' . $clienteId . '_' . $produtoId . '_p' . $passo['id'] . '_' . date('Ymd', $baseTs);

                $id = $this->model->enfileirar([
                    'fluxo_id'    => $fluxo['id'],
                    'passo_id'    => $passo['id'],
                    'cliente_id'  => $clienteId,
                    'contexto'    => ['produto_id' => $produtoId],
                    'disparo_em'  => $disparoEm,
                    'chave_dedup' => $dedup,
                ]);
                if ($id) $n++;
            }
        }
        return $n;
    }

    // =========================================================================
    // 3. CATEGORIA VISITADA
    // =========================================================================
    private function detectarCategoriaVisitada(array $fluxo, array $cfg): int
    {
        $passos = $this->model->passos((int)$fluxo['id']);
        if (!$passos) return 0;
        $passo = $passos[0];
        $delay = ($cfg['delays_horas'][0] ?? 3) * 3600;

        $st = $this->db->query(
            "SELECT h.cliente_id, h.referencia_id AS categoria_id,
                    MAX(h.criado_em) AS ultima_visita
             FROM historico_navegacao h
             WHERE h.tipo = 'categoria'
               AND h.cliente_id IS NOT NULL
               AND h.criado_em > DATE_SUB(NOW(), INTERVAL 6 HOUR)
             GROUP BY h.cliente_id, h.referencia_id
             LIMIT 300"
        );
        $visitas = $st->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;

        foreach ($visitas as $v) {
            $clienteId   = (int)$v['cliente_id'];
            $categoriaId = (int)$v['categoria_id'];
            $baseTs      = strtotime($v['ultima_visita']);
            $disparoEm   = date('Y-m-d H:i:s', $baseTs + $delay);
            $dedup = 'cat_' . $clienteId . '_' . $categoriaId . '_' . date('Ymd', $baseTs);

            $id = $this->model->enfileirar([
                'fluxo_id'    => $fluxo['id'],
                'passo_id'    => $passo['id'],
                'cliente_id'  => $clienteId,
                'contexto'    => ['categoria_id' => $categoriaId],
                'disparo_em'  => $disparoEm,
                'chave_dedup' => $dedup,
            ]);
            if ($id) $n++;
        }
        return $n;
    }

    // =========================================================================
    // 4. WISHLIST
    // =========================================================================
    private function detectarWishlist(array $fluxo, array $cfg): int
    {
        $passos = $this->model->passos((int)$fluxo['id']);
        if (!$passos) return 0;
        $passo = $passos[0];
        $delay = ($cfg['delays_horas'][0] ?? 24) * 3600;

        // Itens adicionados à wishlist nas últimas 25h
        $st = $this->db->query(
            "SELECT wi.id AS item_id, w.cliente_id, wi.produto_id, wi.adicionado_em
             FROM wishlist_itens wi
             JOIN wishlist w ON w.id = wi.wishlist_id
             WHERE wi.adicionado_em > DATE_SUB(NOW(), INTERVAL 25 HOUR)
               AND NOT EXISTS (
                   SELECT 1 FROM pedido_itens pi
                   JOIN pedidos ped ON ped.id = pi.pedido_id
                   WHERE pi.produto_id = wi.produto_id
                     AND ped.cliente_id = w.cliente_id
                     AND ped.status_pagamento = 'aprovado'
                     AND ped.criado_em > wi.adicionado_em
               )
             LIMIT 300"
        );
        $itens = $st->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;

        foreach ($itens as $item) {
            $clienteId = (int)$item['cliente_id'];
            $produtoId = (int)$item['produto_id'];
            $baseTs    = strtotime($item['adicionado_em']);
            $disparoEm = date('Y-m-d H:i:s', $baseTs + $delay);
            $dedup     = 'wl_' . $item['item_id'] . '_p' . $passo['id'];

            $id = $this->model->enfileirar([
                'fluxo_id'    => $fluxo['id'],
                'passo_id'    => $passo['id'],
                'cliente_id'  => $clienteId,
                'contexto'    => ['produto_id' => $produtoId],
                'disparo_em'  => $disparoEm,
                'chave_dedup' => $dedup,
            ]);
            if ($id) $n++;
        }
        return $n;
    }

    // =========================================================================
    // 5. ANIVERSÁRIO
    // =========================================================================
    private function detectarAniversario(array $fluxo, array $cfg): int
    {
        $passos = $this->model->passos((int)$fluxo['id']);
        if (count($passos) < 2) return 0;
        $n = 0;

        $mesHoje = (int)date('m');
        $diaHoje = (int)date('d');

        // Passo 1 — 7 dias antes
        $dt7 = date('Y-m-d', strtotime('+7 days'));
        $mes7 = (int)date('m', strtotime($dt7));
        $dia7 = (int)date('d', strtotime($dt7));

        $st = $this->db->prepare(
            "SELECT c.id AS cliente_id
             FROM clientes c
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE c.nascimento IS NOT NULL
               AND MONTH(c.nascimento) = :m
               AND DAY(c.nascimento)   = :d
               AND u.ativo = '1'"
        );
        $st->execute([':m' => $mes7, ':d' => $dia7]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $clienteId) {
            $dedup = 'aniv7_' . $clienteId . '_' . date('Y');
            $id = $this->model->enfileirar([
                'fluxo_id'    => $fluxo['id'],
                'passo_id'    => $passos[0]['id'],
                'cliente_id'  => (int)$clienteId,
                'contexto'    => [],
                'disparo_em'  => date('Y-m-d 10:00:00'),
                'chave_dedup' => $dedup,
            ]);
            if ($id) $n++;
        }

        // Passo 2 — no dia
        $st->execute([':m' => $mesHoje, ':d' => $diaHoje]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $clienteId) {
            $dedup = 'anivdia_' . $clienteId . '_' . date('Y');
            $id = $this->model->enfileirar([
                'fluxo_id'    => $fluxo['id'],
                'passo_id'    => $passos[1]['id'],
                'cliente_id'  => (int)$clienteId,
                'contexto'    => [],
                'disparo_em'  => date('Y-m-d 09:00:00'),
                'chave_dedup' => $dedup,
            ]);
            if ($id) $n++;
        }

        return $n;
    }

    // =========================================================================
    // 6. PÓS-COMPRA (complementar + avaliação)
    // =========================================================================
    private function detectarPosCompra(array $fluxo, array $cfg): int
    {
        $passos = $this->model->passos((int)$fluxo['id']);
        if (!$passos) return 0;
        $passo     = $passos[0];
        $delayDias = $cfg['delay_dias'] ?? ($fluxo['tipo'] === 'pos_compra_complementar' ? 7 : 14);

        // Pedidos que tiveram status 'entregue' nos últimos 2 dias (pelo histórico)
        // Usa o histórico para não depender do status atual — o pedido pode ter
        // mudado para devolvido/em_disputa depois de entregue.
        $st = $this->db->prepare(
            "SELECT p.id AS pedido_id, p.cliente_id, ph.criado_em AS entregue_em
            FROM pedido_historico ph
            JOIN pedidos p ON p.id = ph.pedido_id
            WHERE ph.status_novo = 'entregue'
            AND ph.criado_em > DATE_SUB(NOW(), INTERVAL 2 DAY)
            AND p.status_pagamento = 'aprovado'
            GROUP BY p.id
            LIMIT 200"
        );
        $st->execute();
        $pedidos = $st->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;

        foreach ($pedidos as $ped) {
            $clienteId = (int)$ped['cliente_id'];
            $pedidoId  = (int)$ped['pedido_id'];
            $baseTs    = strtotime($ped['entregue_em']);
            $disparoEm = date('Y-m-d H:i:s', $baseTs + ($delayDias * 86400));
            $dedup     = $fluxo['tipo'] . '_' . $pedidoId;

            $id = $this->model->enfileirar([
                'fluxo_id'    => $fluxo['id'],
                'passo_id'    => $passo['id'],
                'cliente_id'  => $clienteId,
                'contexto'    => ['pedido_id' => $pedidoId],
                'disparo_em'  => $disparoEm,
                'chave_dedup' => $dedup,
            ]);
            if ($id) $n++;
        }
        return $n;
    }

    // =========================================================================
    // 7. LANÇAMENTO PARA MOTO
    // =========================================================================
    private function detectarLancamentoMoto(array $fluxo, array $cfg): int
    {
        $passos = $this->model->passos((int)$fluxo['id']);
        if (!$passos) return 0;
        $passo = $passos[0];
        $n = 0;

        // Produtos cadastrados/atualizados nas últimas 2h
        // com compatibilidade com motos de clientes ativos
        $st = $this->db->query(
            "SELECT DISTINCT cv.cliente_id, p.id AS produto_id
             FROM produtos p
             JOIN produto_compatibilidade pc ON pc.produto_id = p.id
             JOIN cliente_veiculos cv ON cv.montadora_id = pc.montadora_id
               AND (pc.modelo_id IS NULL OR cv.modelo_id = pc.modelo_id)
               AND (
                   (pc.ano_inicio IS NULL AND pc.ano_fim IS NULL)
                   OR (pc.ano_inicio IS NULL AND cv.ano IS NOT NULL AND pc.ano_fim >= cv.ano)
                   OR (pc.ano_fim IS NULL AND cv.ano IS NOT NULL AND pc.ano_inicio <= cv.ano)
                   OR (cv.ano IS NOT NULL AND pc.ano_inicio <= cv.ano AND pc.ano_fim >= cv.ano)
                   OR cv.ano IS NULL
               )
             WHERE p.ativo = 1
               AND p.deleted_at IS NULL
               AND p.criado_em > DATE_SUB(NOW(), INTERVAL 2 HOUR)
             LIMIT 500"
        );
        $itens = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($itens as $item) {
            $clienteId = (int)$item['cliente_id'];
            $produtoId = (int)$item['produto_id'];
            $dedup = 'lanc_' . $clienteId . '_' . $produtoId;

            $id = $this->model->enfileirar([
                'fluxo_id'    => $fluxo['id'],
                'passo_id'    => $passo['id'],
                'cliente_id'  => $clienteId,
                'contexto'    => ['produto_id' => $produtoId],
                'disparo_em'  => date('Y-m-d H:i:s'),
                'chave_dedup' => $dedup,
            ]);
            if ($id) $n++;
        }
        return $n;
    }

    // =========================================================================
    // 8. REENGAJAMENTO
    // =========================================================================
    private function detectarReengajamento(array $fluxo, array $cfg): int
    {
        // LogService::info('detectarReengajamento', $fluxo);
        $passos = $this->model->passos((int)$fluxo['id']);
        if (!$passos) return 0;
        $diasSemCompra = $cfg['dias_sem_compra'] ?? 60;
        $delaysDias    = $cfg['delays_dias'] ?? [60, 75, 90];
        $n = 0;

        // Clientes que compraram exatamente diasSemCompra dias atrás
        // e não compraram depois (janela de 24h)
        $st = $this->db->prepare(
            "SELECT DISTINCT p.cliente_id,
                    MAX(p.criado_em) AS ultima_compra
             FROM pedidos p
             WHERE p.status_pagamento = 'aprovado'
             GROUP BY p.cliente_id
             HAVING ultima_compra < DATE_SUB(NOW(), INTERVAL :dias DAY)
               AND ultima_compra > DATE_SUB(NOW(), INTERVAL :diasp1 DAY)
             LIMIT 300"
        );
        $st->execute([':dias' => $diasSemCompra, ':diasp1' => $diasSemCompra + 5]);
        $clientes = $st->fetchAll(PDO::FETCH_ASSOC);
        
        // LogService::info('detectarReengajamento -> passos = '. date('Y-m-d', strtotime('-'.$diasSemCompra.' days')), $clientes);

        foreach ($clientes as $cli) {
            $clienteId = (int)$cli['cliente_id'];
            $baseTs    = strtotime($cli['ultima_compra']);

            foreach ($passos as $idx => $passo) {
                $delayDias = $delaysDias[$idx] ?? (int)($passo['delay_horas'] / 24);
                $disparoEm = date('Y-m-d H:i:s', $baseTs + ($delayDias * 86400));
                $dedup = 'reeng_' . $clienteId . '_p' . $passo['id'] . '_' . date('Y', $baseTs);

                $id = $this->model->enfileirar([
                    'fluxo_id'    => $fluxo['id'],
                    'passo_id'    => $passo['id'],
                    'cliente_id'  => $clienteId,
                    'contexto'    => [],
                    'disparo_em'  => $disparoEm,
                    'chave_dedup' => $dedup,
                ]);
                if ($id) $n++;
            }
        }
        return $n;
    }

    // =========================================================================
    // 9. BOAS-VINDAS
    // =========================================================================
    private function detectarBoasVindas(array $fluxo, array $cfg): int
    {
        $passos = $this->model->passos((int)$fluxo['id']);
        
        if (!$passos) return 0;
        $delays = $cfg['delays_horas'] ?? [0, 72, 168];
        $n = 0;      

        // Clientes criados nas últimas 2h
        $st = $this->db->query(
            "SELECT c.id AS cliente_id, c.criado_em
             FROM clientes c
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE c.verificado_em > DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND c.verificado = '1' AND u.ativo = '1' AND c.tray_id IS NULL
             LIMIT 100"
        );
        $novos = $st->fetchAll(PDO::FETCH_ASSOC);

        

        foreach ($novos as $cli) {
            $clienteId = (int)$cli['cliente_id'];
            $baseTs    = strtotime($cli['criado_em']);

            foreach ($passos as $idx => $passo) {
                $delayHoras = $delays[$idx] ?? $passo['delay_horas'];
                $disparoEm  = date('Y-m-d H:i:s', $baseTs + ($delayHoras * 3600));
                $dedup = 'bv_' . $clienteId . '_p' . $passo['id'];

                $id = $this->model->enfileirar([
                    'fluxo_id'    => $fluxo['id'],
                    'passo_id'    => $passo['id'],
                    'cliente_id'  => $clienteId,
                    'contexto'    => [],
                    'disparo_em'  => $disparoEm,
                    'chave_dedup' => $dedup,
                ]);
                if ($id) $n++;
            }
        }
        return $n;
    }
}
