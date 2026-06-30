<?php
declare(strict_types=1);

/**
 * app/services/BlingOrderService.php
 *
 * Sincroniza pedidos do site para o Bling e processa
 * atualizações de status/NF-e vindas via webhook.
 */
class BlingOrderService
{
    private BlingApiClient $api;
    private PDO            $db;

    // Mapeamento carregado do banco via getStatusMap()
    // Não há mais constante hardcoded — admin configura no painel

    public function __construct()
    {
        $this->api = new BlingApiClient();
        $this->db  = Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // MAPEAMENTO DE STATUS (banco → dinâmico)
    // ════════════════════════════════════════════════════

    /**
     * Retorna o status local equivalente a uma situação do Bling.
     * Lê do banco (bling_status_map). Fallback para aguardando_pagamento.
     */
    private function blingParaLocal(string $blingId): ?string
    {
        static $cache = null;
        if ($cache === null) {
            $rows  = $this->db->query("SELECT bling_id, status_local FROM bling_status_map")->fetchAll();
            $cache = array_column($rows, 'status_local', 'bling_id');
        }
        return $cache[$blingId] ?? null;
    }

    /**
     * Retorna a situação_id do Bling equivalente ao status local.
     * Fallback para 6 (Em aberto).
     */
    private function localParaBling(string $statusLocal): int
    {
        $stmt = $this->db->prepare(
            "SELECT bling_id FROM bling_status_map WHERE status_local = ? ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([$statusLocal]);
        return (int)($stmt->fetchColumn() ?: 6);
    }

    // ════════════════════════════════════════════════════
    // PUSH: site → Bling
    // ════════════════════════════════════════════════════

    /**
     * Envia um pedido ao Bling.
     * Idempotente: se o pedido já foi enviado, retorna o bling_id salvo sem nova chamada.
     * Funciona para pedidos criados no site, no admin ou importados da Tray.
     */
    public function enviarPedido(int $pedidoId): array
    {
        // Já enviado? Retorna sem nova chamada à API
        $map = $this->db->prepare(
            "SELECT bling_id FROM bling_pedidos_map WHERE pedido_id = ? LIMIT 1"
        );
        $map->execute([$pedidoId]);
        if ($blingId = $map->fetchColumn()) {
            return ['ok' => true, 'bling_id' => $blingId, 'action' => 'already_sent'];
        }

        $pedido = $this->getPedidoCompleto($pedidoId);
        if (!$pedido) {
            throw new \RuntimeException("Pedido {$pedidoId} não encontrado.");
        }

        // Verifica se já existe no Bling pelo número do pedido
        // (evita duplicata quando forçando reenvio após falha)
        $blingExistente = $this->buscarPedidoNoBlingPorNumero($pedido['codigo']);    
        // Garante que o cliente existe no Bling
        $blingContatoId = $this->upsertContato($pedido);
        // Monta payload do pedido

        $payload = $this->buildPedidoPayload($pedido, $blingContatoId);    
        if ($blingExistente) {
            // Já existe — só salva o mapeamento
            $this->db->prepare(
                "INSERT IGNORE INTO bling_pedidos_map (pedido_id, bling_id) VALUES (?, ?)"
            )->execute([$pedidoId, $blingExistente]);

            $response = $this->api->put('/pedidos/vendas/'.$blingExistente, $payload);

            return ['ok' => true, 'bling_id' => $blingExistente, 'action' => 'recovered', 'res'=>$response];
        }

        

        // Envia para o Bling
        $response = $this->api->post('/pedidos/vendas', $payload);

        $blingPedidoId = (string)($response['data']['id'] ?? $response['id'] ?? '');
        if (!$blingPedidoId) {
            throw new \RuntimeException('Bling não retornou ID do pedido.');
        }

        // Salva mapeamento
        $this->db->prepare(
            "INSERT INTO bling_pedidos_map (pedido_id, bling_id) VALUES (?, ?)"
        )->execute([$pedidoId, $blingPedidoId]);

        return ['ok' => true, 'bling_id' => $blingPedidoId, 'action' => 'created'];
    }

    // ════════════════════════════════════════════════════
    // WEBHOOK: Bling → site
    // ════════════════════════════════════════════════════

    /**
     * Processa evento de mudança de situação do pedido.
     */
    public function processarAtualizacaoStatus(array $dados): void
    {
        $blingId    = (string)($dados['id'] ?? '');
        $situacaoId = (string)($dados['situacao']['id'] ?? '');

        if (!$blingId || !$situacaoId) return;

        // Encontra o pedido local
        $stmt = $this->db->prepare(
            "SELECT pedido_id FROM bling_pedidos_map WHERE bling_id = ? LIMIT 1"
        );
        $stmt->execute([$blingId]);
        $pedidoId = (int)$stmt->fetchColumn();
        if (!$pedidoId) return;

        LogService::info('processarAtualizacaoStatus', [$blingId,  $situacaoId, $pedidoId]);

        // Mapeia status
        $novoStatus = $this->blingParaLocal($situacaoId);
        if (!$novoStatus) return;

        $pedido = $this->db->prepare("SELECT status_pedido FROM pedidos WHERE id = ? LIMIT 1");
        $pedido->execute([$pedidoId]);
        $statusAtual = $pedido->fetchColumn();

        // Não regride status
        if ($statusAtual === $novoStatus) return;

        // Atualiza pedido
        $rastreio = $dados['codigoRastreamento'] ?? null;
        $sets     = ['status_pedido = ?', 'atualizado_em = NOW()'];
        $params   = [$novoStatus];

        if ($rastreio) {
            $sets[]   = 'codigo_rastreio = ?';
            $params[] = $rastreio;
        }
        if ($novoStatus === 'enviado') {
            $sets[]   = 'enviado_em = NOW()';
        }

        $params[] = $pedidoId;
        $this->db->prepare(
            "UPDATE pedidos SET " . implode(', ', $sets) . " WHERE id = ?"
        )->execute($params);

        // Grava histórico
        $this->db->prepare(
            "INSERT INTO pedido_historico (pedido_id, status_novo, observacao)
             VALUES (?, ?, 'Atualizado via Bling')"
        )->execute([$pedidoId, $novoStatus]);

        // Dispara e-mail para o cliente
        try {
            // $emailService = new EmailService();
            // $emailService->statusAtualizado($pedidoId, $novoStatus);
        } catch (\Throwable) {}
    }

    /**
     * Processa NF-e autorizada pelo Bling.
     */
    public function processarNfe(array $dados): void
    {
        $blingId = (string)($dados['pedido']['id'] ?? $dados['id'] ?? '');
        $linkPdf = $dados['linkPdf'] ?? $dados['linkDanfe'] ?? '';
        $linkXml = $dados['linkXml'] ?? '';
        $chave   = $dados['chaveAcesso'] ?? '';

        if (!$blingId) return;

        // Encontra pedido pelo Bling ID do pedido associado à NF-e
        $stmt = $this->db->prepare(
            "SELECT pedido_id FROM bling_pedidos_map WHERE bling_id = ? LIMIT 1"
        );
        $stmt->execute([$blingId]);
        $pedidoId = (int)$stmt->fetchColumn();
        if (!$pedidoId) return;

        $this->db->prepare(
            "UPDATE pedidos SET
               nota_fiscal_url      = ?,
               nota_fiscal_xml_url  = ?,
               nota_fiscal_chave    = ?
             WHERE id = ?"
        )->execute([$linkPdf ?: null, $linkXml ?: null, $chave ?: null, $pedidoId]);

        // Avança status para em_separacao se ainda estava em pagamento_aprovado
        $status = $this->db->prepare("SELECT status_pedido FROM pedidos WHERE id = ?")->execute([$pedidoId]);
        if ($this->db->query("SELECT status_pedido FROM pedidos WHERE id = {$pedidoId}")->fetchColumn() === 'pagamento_aprovado') {
            $this->db->prepare("UPDATE pedidos SET status_pedido = 'em_separacao' WHERE id = ?")->execute([$pedidoId]);
            $this->db->prepare(
                "INSERT INTO pedido_historico (pedido_id, status_novo, observacao) VALUES (?, 'em_separacao', 'NF-e emitida pelo Bling')"
            )->execute([$pedidoId]);
        }
    }

    // ════════════════════════════════════════════════════
    // PRIVADOS — construção do payload
    // ════════════════════════════════════════════════════

    private function buscarPedidoNoBlingPorNumero(string $numero): ?string
    {
        try {
            $resultado = $this->api->get('/pedidos/vendas', ['numerosLojas' => [$numero]]);
            // $itens = $resultado['data'] ?? $resultado;
            $itens = $this->normalizarListaBling($resultado);

            foreach ((array)$itens as $p) {
                if ((string)($p['numeroLoja'] ?? '') === (string)$numero) {
                    // LogService::info('buscarPedidoNoBlingPorNumero', [$p['numerosLojas'], $p['id']]);
                    return (string)$p['id'];
                }
            }

            LogService::info('buscarPedidoNoBlingPorNumero', $itens);
        } catch (\Throwable) {}
        return null;
    }

    private function buildPedidoPayload(array $pedido, ?string $contatoId): array
    {
        $itens = array_map(function ($item) {
            $itemPayload = [
                'valor'      => (float)$item['preco_unitario'],
                'quantidade' => (int)$item['quantidade'],
                'desconto'   => 0,
            ];
            if (!empty($item['bling_id'])) {
                $itemPayload['produto'] = ['id' => (int)$item['bling_id']];
            } else {
                $itemPayload['descricao'] = $item['nome_produto'] ?? 'Produto sem código';
            }
            return $itemPayload;
        }, $pedido['itens']);

        $payload = [
            'numeroLoja'      => $pedido['codigo'],
            'data'        => date('Y-m-d', strtotime($pedido['criado_em'])),
            'dataSaida'   => date('Y-m-d'),
            'desconto'    => [
                'unidade'  => 'REAL',
                'valor' => (float)$pedido['desconto'],
            ],
            'observacoes' => $pedido['observacao_cliente'] ?? '',
            'observacoesInternas'=> $pedido['observacao_interna'] ?? '',
            'itens'       => $itens,
            'transporte'  => [
                'frete'  => (float)$pedido['frete'],
                "prazoEntrega"=> (is_null($pedido['frete_prazo_dias']) ? 0 : $pedido['frete_prazo_dias']),
                'quantidadeVolumes'=> 1,
                'volumes'=> [
                    'codigoRastreamento'=>$pedido['codigo_rastreio'] ?? '',
                    'servico'=> $pedido['frete_servico'] ?? '',
                ],
            ],
        ];

        // contato é obrigatório — upsertContato() já lança exceção se não conseguir
        $payload['contato'] = ['id' => (int)$contatoId];

        // ⚠️ NÃO inclui situação na criação:
        // 1. Bling rejeita com erro code 50 se situação = padrão (Em aberto)
        // 2. Atualizações de status chegam via webhook do Bling de volta ao site
        // 3. Se precisar forçar status, fazer PUT /pedidos/vendas/{id}/situacoes após criar

        return $payload;
    }

    private function upsertContato(array $pedido): string
    {
        $cpfLimpo = preg_replace('/\D/', '', $pedido['cpf'] ?? '');
        $email    = strtolower(trim($pedido['email'] ?? ''));

        // Bling exige um contato. Se não houver CPF nem e-mail, lança exceção legível.
        if (!$cpfLimpo && !$email) {
            throw new \RuntimeException(
                'Pedido sem CPF nem e-mail associado. ' .
                'Vincule um cliente ao pedido antes de enviar ao Bling.'
            );
        }

        
        $contatoId = null;

        // ── 1. Busca por CPF no Bling ─────────────────────
        if ($cpfLimpo) {
            try {
                $results = $this->api->get('/contatos', ['pesquisa' => $cpfLimpo]);
                // $contatos = $results['data'] ?? $results;

                $contatos = $this->normalizarListaBling($results);

                LogService::info('cpfLimpo', $contatos);

                foreach ((array)$contatos as $c) {
                    $cpfBling = preg_replace('/\D+/', '', $c['numeroDocumento'] ?? $c['cpfCnpj'] ?? $c['cpf'] ?? '');

                    if ($cpfBling === $cpfLimpo) {
                        $contatoId = (string)$c['id'];
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // Falha na busca é não-crítica — tenta criar
                error_log('[Bling] Busca contato CPF falhou: ' . $e->getMessage());
            }
        }

        // ── 2. Fallback: busca por e-mail ─────────────────
        if (!$contatoId && $email) {
            try {
                $results = $this->api->get('/contatos', ['pesquisa' => $email]);
                // $contatos = $results['data'] ?? $results;
                $contatos = $this->normalizarListaBling($results);
                foreach ((array)$contatos as $c) {
                    if (strtolower($c['email'] ?? '') === $email) {
                        $contatoId = (string)$c['id'];
                        break;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[Bling] Busca contato e-mail falhou: ' . $e->getMessage());
            }
        }

        LogService::info('upsertContato', [$contatoId, $cpfLimpo, $email]);

        // ── Payload do contato ────────────────────────────
        // nome é obrigatório no Bling — fallbacks em cascata
        $nome = trim($pedido['cliente_nome'] ?? '');
        if (!$nome && $email) {
            $nome = explode('@', $email)[0]; // ex: "joao.silva" de joao.silva@gmail.com
        }
        if (!$nome) {
            $nome = 'Cliente ' . ($cpfLimpo ?: $email);
        }

        // $telefone = preg_replace('/\D/', '', $pedido['celular'] ?? $pedido['telefone'] ?? '');
        $telefone = $this->obterTelefonePedido($pedido);

        // Payload do contato — campos corretos da Bling API v3:
        // 'tipo' (não 'tipoPessoa'), 'situacao' obrigatório = "A" (ativo)
        $dados = [
            'nome'     => $nome,
            'tipo'     => 'F',   // F = Pessoa Física, J = Jurídica
            'situacao' => 'A',   // A=ativo | I=inativo | E=excluído | S=sem movimento
        ];
        if ($cpfLimpo) $dados['cpfCnpj']  = $cpfLimpo;
        if ($email)    $dados['email']     = $pedido['email'];
        // if ($telefone) $dados['telefone']  = $telefone;

        if (!$telefone) {
            throw new \RuntimeException(
                'Telefone inválido ou ausente para criar contato no Bling. Dados: ' .
                json_encode([
                    'pedido_id' => $pedido['id'] ?? null,
                    'codigo'   => $pedido['codigo'] ?? null,
                    'celular'  => $pedido['celular'] ?? null,
                    'telefone' => $pedido['telefone'] ?? null,
                    'email'    => $pedido['email'] ?? null,
                    'cpf'      => $pedido['cpf'] ?? null,
                ], JSON_UNESCAPED_UNICODE)
            );
        }

        $dados['telefone'] = $telefone;

        // ── 3. Atualiza se encontrou ──────────────────────
        if ($contatoId) {
            try {
                $this->api->put("/contatos/{$contatoId}", $dados);
            } catch (\Throwable $e) {
                // Update falhou mas temos o ID — segue com o existente
                error_log('[Bling] Update contato falhou: ' . $e->getMessage());
            }
            return $contatoId;
        }

        // ── 4. Cria novo contato ──────────────────────────
        // Não captura a exceção — falha na criação impede o pedido (erro visível no card)
        $response  = $this->api->post('/contatos', $dados);
        $novoId = (string)($response['data']['id'] ?? $response['id'] ?? '');

        if (!$novoId) {
            throw new \RuntimeException(
                'Bling criou o contato mas não retornou ID. Resposta: ' . json_encode($response)
            );
        }

        

        return $novoId;
    }

    private function getPedidoCompleto(int $pedidoId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.*,
                    c.cpf     AS cpf,
                    c.celular AS celular,
                    c.telefone AS telefone,
                    u.nome    AS cliente_nome,
                    u.email   AS email
             FROM pedidos p
             LEFT JOIN clientes c ON c.id  = p.cliente_id
             LEFT JOIN usuarios u ON u.id  = c.usuario_id
             WHERE p.id = ? LIMIT 1"
        );
        $stmt->execute([$pedidoId]);
        $pedido = $stmt->fetch();
        if (!$pedido) return null;

        // Itens com bling_id do SKU
        $stmtItens = $this->db->prepare(
            "SELECT pi.*, ps.bling_id
             FROM pedido_itens pi
             LEFT JOIN produto_skus ps ON ps.id = pi.sku
             WHERE pi.pedido_id = ?"
        );
        $stmtItens->execute([$pedidoId]);
        $pedido['itens'] = $stmtItens->fetchAll();

        return $pedido;
    }

    private function normalizarTelefoneBling(?string $telefone): ?string
    {
        $telefone = preg_replace('/\D+/', '', (string)$telefone);

        if ($telefone === '') {
            return null;
        }

        // Remove DDI 55 quando vier como +55, 55..., etc.
        if (strlen($telefone) > 11 && str_starts_with($telefone, '55')) {
            $telefone = substr($telefone, 2);
        }

        // Remove zeros à esquerda acidentais
        $telefone = ltrim($telefone, '0');

        // Fixo com DDD = 10 dígitos
        // Celular com DDD = 11 dígitos
        if (!in_array(strlen($telefone), [10, 11], true)) {
            return null;
        }

        return $telefone;
    }

    private function obterTelefonePedido(array $pedido): ?string
    {
        $possiveis = [
            $pedido['celular'] ?? null,
            $pedido['telefone'] ?? null,
            $pedido['fone'] ?? null,
        ];

        foreach ($possiveis as $valor) {
            $valor = trim((string)$valor);

            if ($valor === '') {
                continue;
            }

            $telefone = $this->normalizarTelefoneBling($valor);

            if ($telefone) {
                return $telefone;
            }
        }

        return null;
    }

    private function normalizarListaBling($resultado): array
    {
        if (!is_array($resultado)) {
            return [];
        }

        // Caso padrão da API v3: ['data' => [...]]
        $lista = $resultado['data'] ?? $resultado;

        // Corrige caso venha assim: [[{...}]]
        while (
            is_array($lista)
            && count($lista) === 1
            && isset($lista[0])
            && is_array($lista[0])
            && array_is_list($lista[0])
        ) {
            $lista = $lista[0];
        }

        return is_array($lista) ? $lista : [];
    }
}