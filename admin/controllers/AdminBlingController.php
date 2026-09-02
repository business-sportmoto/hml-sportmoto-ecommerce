<?php 
// ════════════════════════════════════════════════════════
// app/controllers/AdminBlingController.php
// Painel de configuração da integração Bling
// ════════════════════════════════════════════════════════
class AdminBlingController extends Controller
{
    private BlingAuthService $auth;
 
    public function __construct()
    {
        // parent::__construct();
        AuthHelper::requireAdmin();
        AuthHelper::requireAdminLevel('super');
        $this->auth = new BlingAuthService();
    }
 
    // ── GET /admin/configuracoes/bling ────────────────────
    public function index(): void
    {
        $db          = Database::getInstance()->getConnection();
        $conectado   = $this->auth->estaConectado();
        $tokenInfo   = $db->query("SELECT expires_at, atualizado_em FROM bling_tokens WHERE id = 1 LIMIT 1")->fetch();
        $logs        = $db->query(
            "SELECT * FROM bling_sync_log ORDER BY criado_em DESC LIMIT 50"
        )->fetchAll();
        $ultimaSync  = $db->query(
            "SELECT MAX(criado_em) FROM bling_sync_log WHERE tipo = 'estoque' AND status = 'ok'"
        )->fetchColumn();

        $depositos = $db->query(
            "SELECT bling_deposito_id, descricao, padrao, ativo
             FROM bling_depositos
             ORDER BY padrao DESC, descricao ASC"
        )->fetchAll();
 
        // Saúde da integração — os dois pontos cegos do modelo em que o
        // Bling é dono do estoque:
        //   cobertura     → produto vendável sem vínculo nunca recebe saldo
        //                   e nunca baixa (item vai ao Bling como texto livre)
        //   pedidosFalha  → pedido que esgotou a fila não baixou estoque
        //                   em canal nenhum
        $cobertura    = (new BlingVinculoService())->cobertura();
        $pedidosFalha = (new BlingOrderService())->pedidosComFalha(10);

        $filaPendente = (int)$db->query(
            "SELECT COUNT(*) FROM pedidos WHERE bling_sync_status = 'pendente'"
        )->fetchColumn();

        $this->render('configuracoes/bling', compact(
            'conectado', 'tokenInfo', 'logs', 'ultimaSync', 'depositos',
            'cobertura', 'pedidosFalha', 'filaPendente'
        ),'admin');
    }

    // ── POST /admin/configuracoes/bling/vincular-produtos ─────
    public function vincularProdutos(): void
    {
        $this->verifyCsrf();
        // Operação longa (lista catálogo inteiro): estende o tempo limite
        set_time_limit(300);
        try {
            $r = (new BlingVinculoService())->vincularTudo();
            $this->json([
                'ok'  => true,
                'msg' => "Vinculação concluída: {$r['vinculados_produtos']} produto(s) "
                       . "e {$r['vinculados_skus']} SKU(s) vinculados. "
                       . "Catálogo Bling: {$r['produtos_bling']} produtos, "
                       . "{$r['variacoes_bling']} variações em {$r['paginas']} páginas.",
                'detalhe' => $r,
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }


    // ── POST /admin/configuracoes/bling/credenciais ───────
    public function salvarCredenciais(): void
    {
        $this->verifyCsrf();
        $clientId     = SecurityHelper::sanitizeString($_POST['client_id']     ?? '');
        $clientSecret = SecurityHelper::sanitizeString($_POST['client_secret'] ?? '');
 
        if (!$clientId || !$clientSecret) {
            $this->json(['ok' => false, 'msg' => 'Preencha client_id e client_secret.']);
        }
 
        $this->auth->salvarCredenciais($clientId, $clientSecret);
        $this->json(['ok' => true, 'msg' => 'Credenciais salvas. Agora clique em "Conectar".']);
    }
 
    // ── GET /admin/configuracoes/bling/autorizar ──────────
    // Redireciona para o Bling para o usuário autorizar
    public function autorizar(): void
    {
        $url = $this->auth->getAuthUrl();
        header('Location: ' . $url);
        exit;
    }
 
    // ── GET /admin/configuracoes/bling/callback ───────────
    // Bling redireciona aqui após o usuário autorizar
    public function callback(): void
    {
        $code  = $_GET['code']  ?? '';
        $state = $_GET['state'] ?? '';
 
        if (!$code) {
            $this->redirect(ADMIN_URL . '/configuracoes/bling?erro=sem_code');
        }
 
        try {
            $this->auth->exchangeCode($code, $state);
            $this->redirect(ADMIN_URL . '/configuracoes/bling?sucesso=1');
        } catch (\Throwable $e) {
            $this->redirect(ADMIN_URL . '/configuracoes/bling?erro=' . urlencode($e->getMessage()));
        }
    }
 
    // ── POST /admin/configuracoes/bling/desconectar ───────
    public function desconectar(): void
    {
        $this->verifyCsrf();
        $this->auth->desconectar();
        $this->json(['ok' => true, 'msg' => 'Conta Bling desconectada.']);
    }
 
    // ── POST /admin/configuracoes/bling/sync-estoque ──────
    /**
     * Força o espelhamento de saldo AGORA — mesma operação do cron horário.
     *
     * Usa sincronizarEstoque() e NÃO sincronizarTudo(): o segundo resolve
     * vínculo com 1 chamada + 120ms de sleep por produto sem bling_id, sem
     * limite. Com catálogo real isso passa de 10 minutos e o botão morre no
     * timeout do PHP — era o comportamento anterior. Vincular é tarefa do
     * botão "Vincular agora" (listagem em lote) e do cron diário.
     */
    public function syncEstoque(): void
    {
        $this->verifyCsrf();

        // Mesmo teto dos outros botões longos desta tela.
        set_time_limit(300);

        try {
            $result = (new BlingEstoqueService())->sincronizarEstoque();

            // O service devolve 'erro' (ex.: sem depósito padrão) num retorno
            // que, no resto, parece sucesso. Antes isso virava um toast VERDE
            // dizendo "0 SKUs atualizados" — o admin saía achando que
            // sincronizou. Aqui vira falha explícita.
            if (!empty($result['erro'])) {
                $this->json(['ok' => false, 'msg' => $result['erro']] + $result);
            }

            $msg = "Sincronização concluída: {$result['atualizados']} de {$result['total']} "
                 . "vínculo(s) atualizados.";
            if (!empty($result['erros'])) {
                $msg .= " {$result['erros']} lote(s) falharam — veja o log de operações.";
            }

            $this->json(['ok' => true, 'msg' => $msg] + $result);
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'bling');
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * POST /admin/configuracoes/bling/deposito-padrao
     *
     * O saldo do site espelha UM depósito. Até aqui o `padrao` só vinha do
     * que o Bling devolve em /depositos, com fallback para o primeiro ativo —
     * se a conta tem mais de um depósito e o "padrão" dele não é o do
     * e-commerce, o site espelhava o saldo errado e não havia como corrigir
     * sem UPDATE no banco.
     */
    public function setDepositoPadrao(): void
    {
        $this->verifyCsrf();

        $blingDepositoId = SecurityHelper::sanitizeString($_POST['bling_deposito_id'] ?? '');
        if ($blingDepositoId === '') {
            $this->json(['ok' => false, 'msg' => 'Depósito inválido.']);
        }

        $db = Database::getInstance()->getConnection();

        // Whitelist: só aceita depósito que já veio do Bling. Impede que um
        // POST forjado aponte o espelho para um ID qualquer.
        $stmt = $db->prepare(
            "SELECT descricao FROM bling_depositos WHERE bling_deposito_id = ? LIMIT 1"
        );
        $stmt->execute([$blingDepositoId]);
        $descricao = $stmt->fetchColumn();
        if ($descricao === false) {
            $this->json(['ok' => false, 'msg' => 'Depósito não encontrado. Sincronize os depósitos primeiro.']);
        }

        try {
            $db->beginTransaction();
            $db->exec("UPDATE bling_depositos SET padrao = 0");
            $db->prepare("UPDATE bling_depositos SET padrao = 1, ativo = 1 WHERE bling_deposito_id = ?")
               ->execute([$blingDepositoId]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $this->json(['ok' => false, 'msg' => 'Erro ao salvar: ' . $e->getMessage()]);
        }

        LogService::audit('Depósito padrão do Bling alterado', [
            'bling_deposito_id' => $blingDepositoId,
            'descricao'         => $descricao,
            'usuario_id'        => AuthHelper::usuarioId(),
        ]);

        $this->json(['ok' => true,
                     'msg' => "Depósito padrão: {$descricao}. O próximo sync já usa este saldo."]);
    }
 
    // ── POST /admin/configuracoes/bling/forcar-sync ───────
    // Força o reenvio de um pedido específico, mesmo se já enviado.
    // Apaga o mapeamento anterior e tenta novamente, retornando
    // erros detalhados para o admin diagnosticar.
    public function forcarSincronizacao(): void
    {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super', 'gerente');
 
        $pedidoId = (int)($_POST['pedido_id'] ?? 0);
        if (!$pedidoId) $this->json(['ok' => false, 'msg' => 'pedido_id inválido.']);
 
        $db = Database::getInstance()->getConnection();
 
        // Remove mapeamento antigo para forçar novo envio
        $db->prepare("DELETE FROM bling_pedidos_map WHERE pedido_id = ?")->execute([$pedidoId]);
 
        try {
            $result = (new BlingOrderService())->enviarPedido($pedidoId);
 
            // Busca logs recentes para retornar ao admin
            $logs = $db->prepare(
                "SELECT tipo, status, msg_erro, criado_em
                 FROM bling_sync_log
                 WHERE referencia_id = ?
                 ORDER BY criado_em DESC LIMIT 5"
            );
            $logs->execute([$pedidoId]);
 
            $this->json([
                'ok'       => true,
                'bling_id' => $result['bling_id'],
                'msg'      => "Pedido enviado ao Bling com sucesso. ID: {$result['bling_id']}",
                'logs'     => $logs->fetchAll(),
                'status'  => $result
            ]);
        } catch (\Throwable $e) {
            // Busca o log do erro registrado pelo BlingApiClient
            $logErro = $db->prepare(
                "SELECT msg_erro, resposta, criado_em
                 FROM bling_sync_log
                 WHERE tipo = 'pedido' AND status = 'erro'
                 ORDER BY criado_em DESC LIMIT 1"
            );
            $logErro->execute();
            $ultimoErro = $logErro->fetch();
 
            $this->json([
                'ok'         => false,
                'msg'        => $e->getMessage(),
                'detalhe'    => $ultimoErro['resposta'] ?? null,
                'criado_em'  => $ultimoErro['criado_em'] ?? null,
            ]);
        }
    }
 
    // ── GET /admin/configuracoes/bling/status-map ─────────
    public function getStatusMap(): void
    {
        $db     = Database::getInstance()->getConnection();
        $mapa   = $db->query(
            "SELECT * FROM bling_status_map ORDER BY CAST(bling_id AS UNSIGNED)"
        )->fetchAll();
        $locais = $db->query(
            "SELECT slug, label FROM pedido_status WHERE ativo = 1 ORDER BY label"
        )->fetchAll();
        $this->json(['ok' => true, 'mapa' => $mapa, 'status_locais' => $locais]);
    }
 
    // ── POST /admin/configuracoes/bling/status-map ────────
    public function salvarStatusMap(): void
    {
        $this->verifyCsrf();
        $itens = json_decode($_POST['mapa'] ?? '[]', true) ?: [];
        if (empty($itens)) {
            $this->json(['ok' => false, 'msg' => 'Nenhum mapeamento enviado.']);
        }
 
        $db = Database::getInstance()->getConnection();

        // Whitelist dos status locais. O <select> da UI já é populado a
        // partir daqui, mas o POST é texto livre — e pedidos.status_pedido
        // é varchar SEM foreign key. Um slug inválido não daria erro nenhum:
        // o webhook do Bling gravaria a string no pedido e o painel passaria
        // a mostrar um status que não existe, sem nada nos logs.
        $validos = array_column(
            $db->query("SELECT slug FROM pedido_status WHERE ativo = 1")->fetchAll(),
            'slug'
        );

        $stmt = $db->prepare(
            "INSERT INTO bling_status_map (bling_id, bling_label, status_local)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
               bling_label  = VALUES(bling_label),
               status_local = VALUES(status_local)"
        );

        $invalidos = [];
        $salvos    = 0;

        $db->beginTransaction();
        try {
            foreach ($itens as $item) {
                $bId = trim($item['bling_id']    ?? '');
                $bLb = trim($item['bling_label'] ?? '');
                $lSt = trim($item['status_local']?? '');
                if (!$bId || !$lSt) continue;

                if (!in_array($lSt, $validos, true)) {
                    $invalidos[] = $lSt;
                    continue;
                }

                $stmt->execute([$bId, $bLb, $lSt]);
                $salvos++;
            }

            // Nada salvo e há inválidos: não é sucesso parcial, é erro.
            if ($salvos === 0 && $invalidos) {
                $db->rollBack();
                $this->json(['ok' => false, 'msg' =>
                    'Nenhum status local válido: ' . implode(', ', array_unique($invalidos))]);
            }

            $db->commit();

            $msg = "{$salvos} mapeamento(s) salvos.";
            if ($invalidos) {
                $msg .= ' Ignorados por status inexistente: '
                      . implode(', ', array_unique($invalidos)) . '.';
            }
            $this->json(['ok' => true, 'msg' => $msg, 'invalidos' => array_values(array_unique($invalidos))]);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
 
    // ── GET /admin/configuracoes/bling/situacoes ──────────
    // Busca situações disponíveis na conta Bling conectada
    public function getSituacoesBling(): void
    {
        try {
            $raw  = (new BlingApiClient())->get('/situacoes', ['tipo' => 3]);
            $list = array_map(fn($s) => [
                'id'        => (string)($s['id']       ?? ''),
                'descricao' => (string)($s['descricao'] ?? $s['nome'] ?? ''),
            ], $raw);
            $this->json(['ok' => true, 'situacoes' => array_values(array_filter($list, fn($s) => $s['id']))]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
 
    // ── POST /admin/configuracoes/bling/enviar-pedido ─────
    public function enviarPedido(): void
    {
        $this->verifyCsrf();
        $pedidoId = (int)($_POST['pedido_id'] ?? 0);
        if (!$pedidoId) $this->json(['ok' => false, 'msg' => 'pedido_id inválido.']);
 
        try {
            $result = (new BlingOrderService())->enviarPedido($pedidoId);
            $this->json(['ok' => true, 'msg' => "Pedido enviado ao Bling (ID: {$result['bling_id']})."] + $result);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── POST /admin/configuracoes/bling/sync-depositos ────
    public function syncDepositos(): void
    {
        $this->verifyCsrf();
        try {
            $resp = (new BlingApiClient())->get('/depositos');
            $db   = Database::getInstance()->getConnection();

            $stmt = $db->prepare(
                "INSERT INTO bling_depositos (bling_deposito_id, descricao, padrao, ativo)
                 VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                   descricao = VALUES(descricao),
                   padrao    = VALUES(padrao),
                   ativo     = 1"
            );

            $n = 0;
            foreach ((array)$resp as $d) {
                $bid = (string)($d['id'] ?? '');
                if ($bid === '') continue;
                $desc   = (string)($d['descricao'] ?? $d['nome'] ?? ('Depósito ' . $bid));
                $padrao = !empty($d['padrao']) ? 1 : 0;
                $stmt->execute([$bid, $desc, $padrao]);
                $n++;
            }

            // Garante um padrão: se nenhum veio marcado, elege o 1º ativo
            $temPadrao = (int)$db->query(
                "SELECT COUNT(*) FROM bling_depositos WHERE padrao = 1 AND ativo = 1"
            )->fetchColumn();
            if ($temPadrao === 0) {
                $db->exec("UPDATE bling_depositos SET padrao = 1 WHERE ativo = 1 ORDER BY id ASC LIMIT 1");
            }

            $this->json(['ok' => true, 'msg' => "{$n} depósito(s) sincronizado(s)."]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── POST /admin/configuracoes/bling/vincular-contatos ─────
    public function vincularContatos(): void
    {
        $this->verifyCsrf();
        set_time_limit(300);
        try {
            $r = (new BlingVinculoService())->vincularContatos();
            $this->json([
                'ok'  => true,
                'msg' => "Vinculação concluída: {$r['vinculados']} cliente(s) vinculados. "
                       . "Bling: {$r['contatos_bling']} contatos em {$r['paginas']} páginas.",
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
}