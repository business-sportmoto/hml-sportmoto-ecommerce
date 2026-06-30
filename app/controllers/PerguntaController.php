<?php
declare(strict_types=1);

// app/controllers/PerguntaController.php

class PerguntaController extends Controller {

    private const LIMITE_DIA_CLIENTE = 20;
    private const LIMITE_DIA_IP      = 50;

    private Pergunta $perguntas;

    public function __construct() {
        $this->perguntas = new Pergunta();
    }

    private function sessao(): string {
        if (empty($_SESSION['perg_sessao'])) {
            $_SESSION['perg_sessao'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['perg_sessao'];
    }

    // ── GET /perguntas?produto_id=X ───────────────────────
    // public function listar(): void {
    //     $produtoId = SecurityHelper::sanitizeInt($_GET['produto_id'] ?? 0);
    //     if (!$produtoId) $this->json(['ok' => false]);

    //     $emailLogado = null;
    //     if (Session::isClienteLogado()) {
    //         $db   = Database::getInstance()->getConnection();
    //         $stmt = $db->prepare("SELECT email FROM usuarios WHERE id = ? LIMIT 1");
    //         $stmt->execute([(int)Session::get('cliente_id')]);
    //         $emailLogado = $stmt->fetchColumn() ?: null;
    //     }

    //     $perguntas = $this->perguntas->listarPorProduto($produtoId, $emailLogado);

    //     $sessao    = $this->sessao();
    //     $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;

    //     foreach ($perguntas as &$p) {
    //         $p['votou_util'] = $this->perguntas->jaVotouUtil((int)$p['id'], $clienteId, $sessao);
    //         $p['data_fmt']   = date('d M Y', strtotime($p['criado_em']));
    //     }

    //     $this->json([
    //         'ok'          => true,
    //         'perguntas'   => $perguntas,
    //         'logado'      => Session::isClienteLogado(),
    //         'limite_diario' => $this->getLimiteRestante($clienteId),
    //     ]);
    // }

    public function listar(): void {
        $produtoId = SecurityHelper::sanitizeInt($_GET['produto_id'] ?? 0);
        $page      = max(1, (int)($_GET['page']     ?? 1));
        $perPage   = max(1, min(20, (int)($_GET['per_page'] ?? 4)));
    
        if (!$produtoId) $this->json(['ok' => false]);
    
        // E-mail do cliente logado para marcar "suas perguntas"
        $emailLogado = null;
        if (Session::isClienteLogado()) {
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "SELECT u.email FROM usuarios u
                JOIN clientes c ON c.usuario_id = u.id
                WHERE c.id = ? LIMIT 1"
            );
            $stmt->execute([(int)Session::get('cliente_id')]);
            $emailLogado = $stmt->fetchColumn() ?: null;
        }
    
        $total     = $this->perguntas->contarPorProduto($produtoId);
        $perguntas = $this->perguntas->listarPorProduto(
            $produtoId,
            $emailLogado,
            $page,
            $perPage
        );
    
        $sessao    = $this->sessao();
        $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
    
        foreach ($perguntas as &$p) {
            $p['votou_util'] = $this->perguntas->jaVotouUtil((int)$p['id'], $clienteId, $sessao);
            $p['data_fmt']   = date('d M Y', strtotime($p['criado_em']));
        }
        unset($p);
    
        $this->json([
            'ok'        => true,
            'perguntas' => $perguntas,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'has_more'  => ($page * $perPage) < $total,
            'logado'    => Session::isClienteLogado(),
            'limite_diario' => $this->getLimiteRestante($clienteId),
        ]);
    }

    // ── POST /perguntas/enviar ────────────────────────────
    public function enviar(): void {
        $this->verifyCsrf();

        $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        $pergunta  = trim((string)($_POST['pergunta'] ?? ''));
        $nome      = SecurityHelper::sanitizeString($_POST['nome']    ?? '');
        $email     = mb_strtolower(trim((string)($_POST['email']     ?? '')));
        $telefone  = SecurityHelper::sanitizeString($_POST['telefone'] ?? '');

        if (!$produtoId) $this->json(['ok' => false, 'msg' => 'Produto inválido.']);
        if (mb_strlen($pergunta) < 10 || mb_strlen($pergunta) > 500) {
            $this->json(['ok' => false, 'msg' => 'Pergunta deve ter entre 10 e 500 caracteres.']);
        }

        // Dados do autor (logado x anônimo)
        $clienteId = null;
        if (Session::isClienteLogado()) {
            $clienteId = (int)Session::get('cliente_id');
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT 
                    u.nome,
                    u.email,
                    c.telefone,
                    u.id AS usuario_id
                FROM clientes c
                INNER JOIN usuarios u 
                    ON c.usuario_id = u.id
                WHERE c.id = ?
            ");
            $stmt->execute([$clienteId]);
            $c = $stmt->fetch();
            $nome     = $c['nome']     ?? $nome;
            $email    = mb_strtolower($c['email'] ?? $email);
            $telefone = $c['telefone'] ?? $telefone;
        } else {
            // Validações para anônimo
            if (empty($nome) || mb_strlen($nome) < 2) {
                $this->json(['ok' => false, 'msg' => 'Informe seu nome.']);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->json(['ok' => false, 'msg' => 'E-mail inválido.']);
            }
        }

        // ── Rate limit ──────────────────────────────────
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if ($clienteId) {
            $usadoCliente = $this->perguntas->contarPerguntasDia(
                (string)$clienteId, 'cliente'
            );
            if ($usadoCliente >= self::LIMITE_DIA_CLIENTE) {
                $this->json([
                    'ok' => false,
                    'msg' => 'Você atingiu o limite de ' . self::LIMITE_DIA_CLIENTE
                          . ' perguntas hoje. Tente novamente amanhã.',
                ]);
            }
        } else {
            $usadoIp = $this->perguntas->contarPerguntasDia($ip, 'ip');
            if ($usadoIp >= self::LIMITE_DIA_IP) {
                $this->json([
                    'ok' => false,
                    'msg' => 'Limite diário de perguntas atingido para este endereço. Tente mais tarde.',
                ]);
            }
        }

        // ── Cria a pergunta ─────────────────────────────
        $id = $this->perguntas->criar([
            'produto_id'    => $produtoId,
            'cliente_id'    => $clienteId,
            'autor_nome'    => $nome,
            'autor_email'   => $email,
            'autor_telefone'=> $telefone ?: null,
            'pergunta'      => $pergunta,
            'ip'            => $ip,
            'user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);

        $this->perguntas->registrarRateLimit(
            $clienteId ? (string)$clienteId : $ip,
            $clienteId ? 'cliente' : 'ip'
        );

        //  $contexto = GeminiQAService::montarContexto($produtoId);
        //     $svc      = new GeminiQAService();
        //     $r        = $svc->responder($contexto, $pergunta);

        //         $this->json([
        //         'ok'    => false,
        //         'msg'   => $r,
        //         'fonte' => 'admin',
        //     ]);

        // ── Tenta IA ─────────────────────────────────────
        try {
            $contexto = GeminiQAService::montarContexto($produtoId);
            $svc      = new GeminiQAService();
            $r        = $svc->responder($contexto, $pergunta);

            if ($r['ok'] && $r['fonte'] === 'ia' && !empty($r['resposta'])) {
                $this->perguntas->salvarRespostaIA($id, $r['resposta']);
                $this->json([
                    'ok'        => true,
                    'msg'       => 'Resposta gerada!',
                    'resposta'  => $r['resposta'],
                    'fonte'     => 'ia',
                ]);
            } else {
                // IA não soube — vai pra fila admin
                $this->perguntas->marcarParaAdmin($id);
                $this->notificarAdmin($id);

                $msg = Session::isClienteLogado()
                     ? 'Sua pergunta foi enviada a um especialista. Você receberá a resposta por e-mail em até 24h.'
                     : 'Sua pergunta foi recebida. A resposta será enviada para ' . $email . ' em até 24h.';

                $this->json([
                    'ok'    => true,
                    'msg'   => $msg,
                    'fonte' => 'admin',
                ]);
            }
        } catch (\Throwable $e) {
            error_log('Pergunta IA error: ' . $e->getMessage());
            $this->perguntas->marcarParaAdmin($id);
            $this->notificarAdmin($id);
            $this->json([
                'ok'    => true,
                'msg'   => 'Pergunta recebida! Resposta em até 24h por e-mail.',
                'fonte' => 'admin',
            ]);
        }
    }

    // ── POST /perguntas/util ──────────────────────────────
    public function util(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $sessao    = $this->sessao();
        $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
        $ip        = $_SERVER['REMOTE_ADDR'] ?? '';

        $resultado = $this->perguntas->toggleUtil($id, $clienteId, $sessao, $ip);
        $this->json(['ok' => true] + $resultado);
    }

    private function getLimiteRestante(?int $clienteId): int {
        if (!$clienteId) return self::LIMITE_DIA_IP;
        $usado = $this->perguntas->contarPerguntasDia((string)$clienteId, 'cliente');
        return max(0, self::LIMITE_DIA_CLIENTE - $usado);
    }

    private function notificarAdmin(int $perguntaId): void {
        // Email simples; em produção mover para fila
        try {
            $db = Database::getInstance()->getConnection();
            $emailAdmin = $db->query(
                "SELECT valor FROM configuracoes WHERE chave = 'email_admin' LIMIT 1"
            )->fetchColumn();

            if (!$emailAdmin) return;

            $url = BASE_URL . '/admin/perguntas?id=' . $perguntaId;
            MailHelper::enviar(
                $emailAdmin,
                'Nova pergunta aguardando resposta',
                "Uma pergunta de cliente precisa de resposta humana.\n\n" .
                "Acesse: {$url}"
            );
        } catch (\Throwable $e) {
            error_log('Notif admin error: ' . $e->getMessage());
        }
    }
}