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
    /**
     * Teto de tentativas do CRON. Enforçado no seletor da fila, não
     * dentro do enviarPedido() — assim o cron para de bater num pedido
     * quebrado, mas o botão do admin ainda força o reenvio.
     * Mesma regra do BlingContatoService.
     */
    private const MAX_TENTATIVAS = 5;

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
    // FILA DE ENVIO — site → Bling
    //
    // O Bling é o dono do estoque: ele baixa o saldo de todos os
    // canais quando o pedido entra. Pedido que não chega ao Bling
    // é estoque que não baixa em canal nenhum. Por isso o envio
    // NÃO pode ser "melhor esforço" no checkout.
    //
    // O checkout só marca 'pendente' (um UPDATE, sem I/O externo):
    // Bling fora não derruba a compra. O cron drena e insiste.
    // ════════════════════════════════════════════════════

    /**
     * Marca um pedido para envio. Idempotente e barato.
     *
     * Não re-enfileira o que já sincronizou — o guard é o mesmo do
     * enviarPedido() (bling_pedidos_map), mas aqui evita até o UPDATE.
     */
    public function enfileirar(int $pedidoId): void
    {
        $this->db->prepare(
            "UPDATE pedidos
             SET bling_sync_status     = 'pendente',
                 bling_sync_tentativas = 0,
                 bling_sync_erro       = NULL
             WHERE id = ?
               AND bling_sync_status <> 'sincronizado'"
        )->execute([$pedidoId]);
    }

    /**
     * Drena a fila. Chamado pelo cron.
     *
     * @return array{total:int, ok:int, falhas:int, detalhes:array}
     */
    public function processarFila(int $limite = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM pedidos
             WHERE bling_sync_status = 'pendente'
               AND bling_sync_tentativas < ?
             ORDER BY bling_sync_tentativas ASC, id ASC
             LIMIT ?"
        );
        $stmt->execute([self::MAX_TENTATIVAS, $limite]);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));

        $ok = 0; $falhas = 0; $detalhes = [];

        foreach ($ids as $id) {
            try {
                $this->enviarPedido($id);
                $ok++;
            } catch (\Throwable $e) {
                $falhas++;
                $detalhes[] = ['pedido_id' => $id, 'msg' => $e->getMessage()];
            }
            usleep(300000); // 0,3s — respiro sob o teto de 3 rps
        }

        return ['total' => count($ids), 'ok' => $ok,
                'falhas' => $falhas, 'detalhes' => $detalhes];
    }

    /**
     * Pedidos que esgotaram as tentativas do cron. Nenhum deles baixou
     * estoque no Bling — logo, nenhum baixou em canal nenhum. É a lista
     * que o painel precisa mostrar em vermelho.
     */
    public function pedidosComFalha(int $limite = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, codigo, total, criado_em,
                    bling_sync_tentativas, bling_sync_erro
             FROM pedidos
             WHERE bling_sync_status = 'erro'
             ORDER BY criado_em DESC
             LIMIT ?"
        );
        $stmt->execute([$limite]);
        return $stmt->fetchAll();
    }

    /**
     * Itens que vão ao Bling sem `produto.id` — os que não baixam estoque.
     *
     * @return array<int,array{produto_id:int,codigo:?string,nome:string,quantidade:int}>
     */
    private function itensSemVinculo(array $itens): array
    {
        $out = [];
        foreach ($itens as $i) {
            if (!empty($i['bling_id'])) continue;
            $out[] = [
                'produto_id' => (int)($i['produto_id'] ?? 0),
                'codigo'     => trim((string)($i['codigo_item'] ?? '')) ?: null,
                'nome'       => (string)($i['nome_produto'] ?? 'sem nome'),
                'quantidade' => (int)($i['quantidade'] ?? 0),
            ];
        }
        return $out;
    }

    private function marcarSincronizado(int $pedidoId): void
    {
        $this->db->prepare(
            "UPDATE pedidos
             SET bling_sync_status     = 'sincronizado',
                 bling_sync_erro       = NULL,
                 bling_sincronizado_em = NOW()
             WHERE id = ?"
        )->execute([$pedidoId]);
    }

    /**
     * Conta a tentativa e guarda o motivo. Vira 'erro' ao bater o teto —
     * o cron para de tentar, mas o admin ainda pode forçar pelo botão.
     */
    private function marcarFalha(int $pedidoId, string $msg): void
    {
        $this->db->prepare(
            "UPDATE pedidos
             SET bling_sync_tentativas = bling_sync_tentativas + 1,
                 bling_sync_erro       = ?,
                 bling_sync_status     = IF(bling_sync_tentativas + 1 >= ?, 'erro', 'pendente')
             WHERE id = ?"
        )->execute([mb_substr($msg, 0, 500), self::MAX_TENTATIVAS, $pedidoId]);
    }

    // ════════════════════════════════════════════════════
    // PUSH: site → Bling
    // ════════════════════════════════════════════════════

    /**
     * Envia um pedido ao Bling.
     * Idempotente: se o pedido já foi enviado, retorna o bling_id salvo sem nova chamada.
     * Funciona para pedidos criados no site, no admin ou importados da Tray.
     */
    /**
     * Envia o pedido ao Bling e REGISTRA O RESULTADO no próprio pedido.
     *
     * O registro de estado é o que transforma o envio de "melhor esforço"
     * em fila confiável: sucesso marca 'sincronizado' (sai da fila),
     * falha conta a tentativa e guarda o motivo (o cron volta depois).
     *
     * Continua lançando a exceção para cima — o admin que aperta o botão
     * precisa ver o erro do Bling, não um "ok" silencioso.
     */
    public function enviarPedido(int $pedidoId): array
    {
        try {
            $r = $this->executarEnvio($pedidoId);
            $this->marcarSincronizado($pedidoId);

            // Só alerta em envio REAL. 'already_sent' não chamou a API e
            // já alertou na primeira vez — repetir viraria ruído no sino.
            if (($r['action'] ?? '') !== 'already_sent' && !empty($r['itens_sem_vinculo'])) {
                $this->alertarItensSemVinculo($pedidoId, $r['itens_sem_vinculo']);
            }

            return $r;
        } catch (\Throwable $e) {
            $this->marcarFalha($pedidoId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Item sem bling_id foi para o Bling como linha de texto livre.
     * O pedido existe lá, o site marcou 'sincronizado', a fila esvaziou —
     * e o estoque NÃO baixou em canal nenhum. O produto segue à venda no
     * site e nos marketplaces com saldo que já não existe.
     *
     * É a falha mais perigosa do modelo (Bling dono do estoque), porque
     * todo o resto reporta sucesso. Por isso vai como critical + sino:
     * ninguém descobre isso lendo log de rotina.
     */
    private function alertarItensSemVinculo(int $pedidoId, array $itens): void
    {
        $stmt = $this->db->prepare("SELECT codigo FROM pedidos WHERE id = ? LIMIT 1");
        $stmt->execute([$pedidoId]);
        $codigo = (string)($stmt->fetchColumn() ?: $pedidoId);

        $nomes = array_map(
            fn($i) => trim(($i['codigo'] ?? '—') . ' · ' . ($i['nome'] ?? 'sem nome')),
            $itens
        );

        LogService::critical(
            'Pedido enviado ao Bling com itens SEM vínculo — estoque não será baixado',
            [
                'pedido_id'  => $pedidoId,
                'codigo'     => $codigo,
                'itens'      => $itens,
                'consequencia' => 'Produtos seguem à venda no site e nos marketplaces '
                                . 'com saldo que já não existe. Vincular no Bling e '
                                . 'ajustar o estoque manualmente.',
            ],
            'bling'
        );

        // Notificação in-app não pode derrubar o envio já concluído.
        try {
            $qtd = count($nomes);
            NotificacaoService::criarBroadcast([
                'categoria' => 'estoque',
                'tipo'      => 'bling_item_sem_vinculo',
                'titulo'    => "Estoque não baixou no pedido #{$codigo}",
                'mensagem'  => $qtd . ' item(ns) foram ao Bling sem vínculo de produto: '
                             . mb_substr(implode(' | ', $nomes), 0, 400)
                             . '. Vincule no Bling e ajuste o estoque manualmente.',
                'url'       => '/admin/pedidos/' . $pedidoId,
            ], 'todos_admins');
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'bling', ['pedido_id' => $pedidoId]);
        }
    }

    private function executarEnvio(int $pedidoId): array
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

        // Levantado ANTES do envio, mas alertado só depois do sucesso
        // (em enviarPedido): se o envio falhar, o pedido volta pra fila
        // e não há nada a alertar ainda.
        $semVinculo = $this->itensSemVinculo($pedido['itens']);

        if ($blingExistente) {
            // Já existe — só salva o mapeamento
            $this->db->prepare(
                "INSERT IGNORE INTO bling_pedidos_map (pedido_id, bling_id) VALUES (?, ?)"
            )->execute([$pedidoId, $blingExistente]);

            $response = $this->api->put('/pedidos/vendas/'.$blingExistente, $payload);

            return ['ok' => true, 'bling_id' => $blingExistente, 'action' => 'recovered',
                    'res' => $response, 'itens_sem_vinculo' => $semVinculo];
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

        return ['ok' => true, 'bling_id' => $blingPedidoId, 'action' => 'created',
                'itens_sem_vinculo' => $semVinculo];
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

            // O `codigo` vai SEMPRE que existir, com ou sem vínculo.
            // Com vínculo é redundância inofensiva; sem vínculo é a
            // única chance de o Bling casar a linha com um produto dele
            // — e, se não casar, ao menos a linha fica rastreável pelo
            // mesmo código que o site usa.
            $codigo = trim((string)($item['codigo_item'] ?? ''));
            if ($codigo !== '') {
                $itemPayload['codigo'] = $codigo;
            }

            if (!empty($item['bling_id'])) {
                $itemPayload['produto'] = ['id' => (int)$item['bling_id']];
            } else {
                // Sem produto.id o Bling aceita a linha como texto livre e
                // NÃO baixa estoque. O pedido entra "com sucesso" e mente.
                // Quem alerta é o alertarItensSemVinculo() — aqui só monta.
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

        // Itens com o vínculo do Bling resolvido.
        //
        // pedido_itens.sku é VARCHAR mas guarda o ID de produto_skus
        // (ver AdminPedido::addItem) — daí o join por ps.id.
        //
        // O fallback para pr.bling_id só vale quando o produto NÃO tem
        // variação. Em produto com variação, o bling_id do pai é o da
        // FAMÍLIA: usá-lo baixaria estoque do item errado no Bling.
        // Melhor ficar sem vínculo e ser sinalizado do que acertar o
        // produto errado silenciosamente.
        //
        // codigo_item alimenta o campo `codigo` do item no payload —
        // é o que dá ao Bling uma chance de casar por código quando o
        // ID não existe, e o que torna a linha rastreável de qualquer jeito.
        $stmtItens = $this->db->prepare(
            "SELECT pi.*,
                    COALESCE(ps.bling_id, IF(pr.tem_variacao = 0, pr.bling_id, NULL)) AS bling_id,
                    COALESCE(ps.sku, pr.sku_legado) AS codigo_item
             FROM pedido_itens pi
             LEFT JOIN produto_skus ps ON ps.id  = pi.sku
             LEFT JOIN produtos     pr ON pr.id  = pi.produto_id
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