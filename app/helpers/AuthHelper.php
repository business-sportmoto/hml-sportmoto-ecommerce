<?php
// app/helpers/AuthHelper.php
// Centraliza verificações de autenticação para proteger rotas.

class AuthHelper {

    /**
     * Exige que o cliente esteja logado.
     * Se não estiver, salva a URL de destino e redireciona para o login.
     */
    public static function requireCustomer(): void {
        // Logout remoto: se a sessão persistente foi revogada no painel
        // "sessões ativas", esta chamada encerra a sessão PHP na hora.
        TokenService::validateActiveSession();

        if (!Session::isClienteLogado()) {
            // Salva para onde o cliente queria ir
            Session::flash('redirect_after_login', $_SERVER['REQUEST_URI']);
            Session::flash('info', 'Faça login para continuar.');
            header('Location: ' . BASE_URL . '/login');
            exit;
        }
    }

    /**
     * Exige que o admin esteja logado.
     * Redireciona para o login do admin.
     */
    public static function requireAdmin(): void {
        if (!Session::isAdminLogado()) {
            Session::flash('error', 'Acesso restrito. Faça login como administrador.');
            header('Location: ' . ADMIN_URL . '/login');
            exit;
        }
    }

    /**
     * Exige um nível específico de admin.
     * Ajax recebe JSON 403; navegação recebe a view de erro.
     */
    public static function requireAdminLevel(string ...$levels): void {
        self::requireAdmin();
        if (self::hasLevel(...$levels)) return;
 
        http_response_code(403);
        if (self::isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Sem permissão para esta ação.']);
        } else {
            View::render('errors/403', [], 'minimal');
        }
        exit;
    }

    /**
     * Verificação NÃO-fatal de nível (retorna bool, não redireciona).
     * É a base do requireAdminLevel e do ehGestor() da Central de
     * Recuperação. 'super' passa em qualquer verificação (bypass
     * intencional — mesmo comportamento do requireAdminLevel).
     */
    public static function hasLevel(string ...$levels): bool {
        if (!Session::isAdminLogado()) return false;
        $nivel = (string) Session::get('admin_nivel');
        return $nivel === 'super' || in_array($nivel, $levels, true);
    }

    /**
     * Exige uma permissão específica do admin.
     */
    public static function requirePermission(string $permissao): void {
        self::requireAdmin();
        if (!Session::adminTemPermissao($permissao)) {
            http_response_code(403);
            View::render('errors/403', [], 'minimal');
            exit;
        }
    }

    /**
     * Permissão granular COM fallback de nível.
     *
     * É a cascata que os módulos tentavam montar à mão e que nunca funcionou:
     * `requirePermission()` **não lança** — nega com 403 e `exit` —, então o
     * `try/catch` em volta dela era código morto e o fallback, inalcançável.
     * E o `requireAdminLevel()` daquelas cascatas era chamado sem argumento
     * nenhum, o que só deixa passar o super pelo bypass. As duas pernas
     * levavam ao mesmo lugar: módulo super-only.
     *
     * Aqui a ordem é explícita e cada camada só concede:
     *   1. permissão nominal (ou `all`) concede;
     *   2. não havendo, o nível decide;
     *   3. só então nega.
     *
     * A negação replica o comportamento do requireAdminLevel: Ajax recebe
     * JSON 403, navegação recebe a view de erro. Um `$.post` que recebe HTML
     * onde espera JSON quebra no parse e esconde o motivo real.
     */
    public static function requirePermissaoOuNivel(string $permissao, string ...$niveis): void {
        self::requireAdmin();

        if (Session::adminTemPermissao($permissao)) return;
        if ($niveis && self::hasLevel(...$niveis)) return;

        http_response_code(403);
        if (self::isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Sem permissão para esta ação.']);
        } else {
            View::render('errors/403', [], 'minimal');
        }
        exit;
    }

    /**
     * Verifica se a requisição atual é Ajax (XMLHttpRequest).
     */
    public static function isAjax(): bool {
        return (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /**
     * Verifica se a requisição é POST.
     */
    public static function isPost(): bool {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    /**
     * Retorna a URL para redirecionar após login.
     */
    public static function getRedirectAfterLogin(): string {
        $url = Session::get('after_login_url');
    
        // One-shot: consome as chaves independente de serem válidas
        Session::remove('after_login_url');
        Session::remove('after_login_origem');
    
        if (
            is_string($url)
            && $url !== ''
            && str_starts_with($url, '/')
            && !str_starts_with($url, '//')
            && !preg_match('#^/[^/]*:#', $url)   // bloqueia /javascript:..., /data:...
        ) {
            return BASE_URL . $url;
        }
    
        return BASE_URL . '/minha-conta';
    }


    /**
     * Resolve o usuarios.id do admin logado (admins.usuario_id).
     * Lazy com cache em sessão: 1 query por sessão, cobre TODOS os
     * caminhos de autenticação (login, remember-me, futuros) — se a
     * resolução morasse só no login, cada caminho novo que esquecesse
     * de popular a chave reintroduziria autor=0 na auditoria.
     * Retorna 0 se o admin não tem vínculo (chamador decide bloquear).
     */
    public static function usuarioId(): int {
        $cached = (int) (Session::get('usuario_id') ?: 0);
        if ($cached > 0) return $cached;

        $adminId = (int) (Session::get('admin_id') ?: 0);
        if ($adminId <= 0) return 0;

        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT usuario_id FROM admins WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$adminId]);
        $usuarioId = (int) ($stmt->fetchColumn() ?: 0);

        if ($usuarioId > 0) {
            Session::set('usuario_id', $usuarioId); // cache pela vida da sessão
        } else {
            error_log('[AuthHelper] admin #' . $adminId . ' sem vínculo em admins.usuario_id');
        }
        return $usuarioId;
    }

    /**
     * Nome + cargo do admin logado para exibição (topbar).
     * Lazy com cache em sessão — mesmo padrão do usuarioId():
     * cobre sessões abertas antes do deploy que popula no login.
     */
    public static function adminDisplay(): array {
        $nivel = (string) (Session::get('admin_nivel') ?: '');
        $nome  = (string) (Session::get('usuario_nome') ?: '');
 
        if ($nome === '') {
            $uid = self::usuarioId();
            if ($uid > 0) {
                $stmt = Database::getInstance()->getConnection()->prepare(
                    "SELECT nome FROM usuarios WHERE id = ? LIMIT 1"
                );
                $stmt->execute([$uid]);
                $nome = (string) ($stmt->fetchColumn() ?: '');
                if ($nome !== '') Session::set('usuario_nome', $nome);
            }
        }
 
        return [
            'nome'  => $nome !== '' ? $nome : 'Admin',
            'nivel' => $nivel,
            'label' => Cargos::label($nivel),
        ];
    }

    /**
     * Desloga sessão de cliente cuja conta não está ativada.
     * Chamar no bootstrap, logo após Session::start().
     *
     * Por que deslogar em vez de só redirecionar: elimina o estado
     * "autenticado porém não verificado". Sem sessão meia-boca, não
     * há rota protegida que precise lembrar de checar verificação —
     * o invariante vale para o sistema inteiro, não rota a rota.
     */
    public static function enforceEmailVerificado(): void {
        if (!Session::isClienteLogado())   return;
        if (self::clienteEmailVerificado()) return;
 
        Session::logoutCliente();
        session_regenerate_id(true);   // identidade antiga morre junto
 
        // Ajax recebe JSON: um $.post que ganha HTML de redirect
        // quebra no parse e esconde o motivo real (mesma lição do
        // requireAdminLevel).
        if (self::isAjax()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'       => false,
                'msg'      => 'Ative sua conta para continuar.',
                'redirect' => BASE_URL . '/login',
            ]);
            exit;
        }
 
        Session::flash('error',
            'Ative sua conta para continuar. Faça login para receber um novo código.');
        header('Location: ' . BASE_URL . '/login');
        exit;
    }

    /**
     * Estado de ativação do cliente logado.
     *
     * ⚠ NÃO confundir com a chave de sessão 'cliente_verificado',
     * que guarda `clientes.verificado` — coluna que NUNCA é escrita
     * em lugar nenhum do projeto (sempre 0). Usar aquela chave aqui
     * deslogaria 100% dos clientes. A verificação de e-mail mora em
     * `usuarios.email_verificado`.
     *
     * Lazy com cache em sessão (padrão do usuarioId()): sessões
     * abertas antes do deploy não têm a chave e são resolvidas na
     * primeira navegação, sem forçar relogin geral.
     */
    private static function clienteEmailVerificado(): bool {
        $flag = Session::get('email_verificado');
        if ($flag !== null) return (bool) $flag;
 
        $clienteId = (int) (Session::getClienteId() ?: 0);
        if ($clienteId <= 0) return true;   // fail-open — ver nota abaixo
 
        try {
            $stmt = Database::getInstance()->getConnection()->prepare(
                "SELECT u.email_verificado
                 FROM clientes c
                 JOIN usuarios u ON u.id = c.usuario_id
                 WHERE c.id = ? LIMIT 1"
            );
            $stmt->execute([$clienteId]);
            $v = $stmt->fetchColumn();
 
            if ($v === false) return true;  // registro sumiu — fail-open
 
            Session::set('email_verificado', (bool) $v);
            return (bool) $v;
        } catch (\Throwable $e) {
            // FAIL-OPEN DELIBERADO: banco instável não pode deslogar a
            // loja inteira. Este guard é a 2ª camada; a 1ª (bloqueio no
            // login, linha ~412) continua de pé e não depende disto.
            // Trade-off: um cliente não verificado navega até o banco
            // voltar. Aceitável frente a uma queda de sessão global.
            error_log('[AuthHelper::clienteEmailVerificado] ' . $e->getMessage());
            return true;
        }
    }
}