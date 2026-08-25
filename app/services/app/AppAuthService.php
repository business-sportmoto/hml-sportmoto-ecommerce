<?php
// app/services/app/AppAuthService.php
// Autenticação do app.
//
// NÃO passa por AuthController de propósito: aquele fluxo é feito para o
// navegador — CSRF via $_POST, reCAPTCHA v3, redirects, flash messages e
// View::share. Aqui o contrato é JSON puro sobre Bearer token.
//
// O que é REUSADO, e não reescrito:
//   AuthController::findUserByLogin()  — login por e-mail OU CPF (é public)
//   RateLimitService                   — as duas camadas (IP + conta)
//   AuthLogService / LogService        — a mesma trilha de auditoria da web
//   Session::loginCliente()            — para a ponte de sessão continuar válida
//   CartMergeService                   — junção do carrinho de visitante
//
// Sem reCAPTCHA (não existe em RN nativo), o throttle ganha uma terceira
// dimensão — o device_uuid — e, ao estourar o limiar, o app é mandado para uma
// verificação web em WebView, onde o reCAPTCHA existe. Nenhum caminho de
// bypass: o limite continua sendo aplicado, só muda onde o desafio acontece.

class AppAuthService
{
    private PDO $pdo;
    private AppTokenService $tokens;
    private AppDeviceService $dispositivos;

    /** Tentativas por dispositivo antes de exigir verificação no navegador. */
    private const LIMITE_DEVICE = 8;
    private const JANELA_DEVICE = 900; // 15 min

    /**
     * Hash descartável para igualar o tempo de resposta quando o usuário não
     * existe. Sem isso, a diferença de latência entre "conta inexistente" e
     * "senha errada" permite enumerar quais e-mails têm cadastro.
     *
     * O AuthController tem o seu, mas como `private const` — duplicamos o valor
     * em vez de afrouxar a visibilidade lá. É um hash público de um texto que
     * ninguém usa; não há segredo a proteger.
     */
    private const HASH_DESCARTAVEL = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->tokens = new AppTokenService($this->pdo);
        $this->dispositivos = new AppDeviceService($this->pdo);
    }

    /**
     * Login por senha.
     *
     * @param  array  $dispositivo Linha de app_dispositivos.
     * @return array{estado:string, ...} Sempre com `estado`; o app decide a tela por ele.
     */
    public function login(array $dispositivo, string $login, string $senha, ?string $ip): array
    {
        $login = SecurityHelper::sanitizeString($login);

        if ($login === '' || $senha === '') {
            return ['estado' => 'dados_invalidos', 'mensagem' => 'Informe login e senha.'];
        }

        // ── Camada extra: throttle por dispositivo ───────────────────────
        if ($this->tentativasDoDispositivo((int)$dispositivo['id']) >= self::LIMITE_DEVICE) {
            return [
                'estado' => 'verificacao_web_requerida',
                'mensagem' => 'Por segurança, conclua a verificação no navegador para continuar.',
                'url' => rtrim(BASE_URL, '/') . '/login',
            ];
        }

        // ── Camadas da web: IP + conta ───────────────────────────────────
        $ip ??= '0.0.0.0';
        $rateLimit = new RateLimitService();

        // Sem token de reCAPTCHA: o serviço devolve 'captcha' no limiar, e para
        // o app isso significa "vá terminar no navegador".
        $rl = $rateLimit->check($ip, $login, null);

        if ($rl['status'] === 'blocked') {
            LogService::warning('Login do app bloqueado por rate limit', [
                'dispositivo_id' => (int)$dispositivo['id'],
            ], 'auth');
            return ['estado' => 'bloqueado', 'mensagem' => $rl['msg'] ?? 'Muitas tentativas. Tente mais tarde.'];
        }

        if ($rl['status'] === 'captcha') {
            return [
                'estado' => 'verificacao_web_requerida',
                'mensagem' => 'Por segurança, conclua a verificação no navegador para continuar.',
                'url' => rtrim(BASE_URL, '/') . '/login',
            ];
        }

        $auth = new AuthController();
        $user = $auth->findUserByLogin($this->pdo, $login);

        if (!$user) {
            // Timing-safe — ver a nota em HASH_DESCARTAVEL.
            password_verify($senha, self::HASH_DESCARTAVEL);
            $this->registrarFalha($dispositivo, $rateLimit, $ip, $login);
            return ['estado' => 'credenciais_invalidas', 'mensagem' => 'E-mail ou senha incorretos.'];
        }

        if (empty($user['ativo'])) {
            LogService::warning('Tentativa de login do app em conta desativada', [
                'usuario_id' => (int)$user['id'],
            ], 'auth');
            return ['estado' => 'conta_desativada', 'mensagem' => 'Conta desativada. Fale com o nosso atendimento.'];
        }

        // Conta migrada da Tray nunca teve senha aqui; conta criada via Google
        // também não. O app manda definir a senha pelo fluxo web.
        if (isset($user['senha_definida']) && (int)$user['senha_definida'] === 0) {
            return [
                'estado' => 'definir_senha',
                'mensagem' => !empty($user['tray_id'])
                    ? 'Identificamos sua conta da nossa loja anterior. Defina uma nova senha para continuar.'
                    : 'Esta conta foi criada com Google. Entre com Google ou defina uma senha.',
                'url' => rtrim(BASE_URL, '/') . '/recuperar-senha?email=' . urlencode((string)$user['email']),
            ];
        }

        if (!password_verify($senha, (string)$user['senha_hash'])) {
            $this->registrarFalha($dispositivo, $rateLimit, $ip, $login, (int)$user['id']);
            return ['estado' => 'credenciais_invalidas', 'mensagem' => 'E-mail ou senha incorretos.'];
        }

        if (empty($user['email_verificado'])) {
            return [
                'estado' => 'email_pendente',
                'mensagem' => 'Confirme seu e-mail antes de entrar. Veja sua caixa de entrada.',
                'email' => $this->mascararEmail((string)$user['email']),
            ];
        }

        $rateLimit->register($ip, $login, true, 'senha');
        $rateLimit->clearAccount($login);
        $this->limparTentativas((int)$dispositivo['id']);

        LogService::audit('Login pelo app', [
            'usuario_id' => (int)$user['id'],
            'dispositivo_id' => (int)$dispositivo['id'],
        ]);

        // Senha correta não é o fim: se há segundo fator, o login para aqui.
        $desafio = $this->talvezExigir2FA($dispositivo, $user);
        if ($desafio) {
            return $desafio;
        }

        return $this->concluirLogin($dispositivo, $user);
    }

    /* =================================================================
       SEGUNDO FATOR
       ================================================================= */

    /**
     * Cria o desafio de 2FA quando o usuário tem segundo fator ativo.
     *
     * O desafio vive em `app_tokens` (tipo `2fa_challenge`), NÃO na sessão —
     * ao contrário do fluxo web, que usa Session::set('2fa_usuario_id').
     * Motivo: o app pode reinstalar, trocar de rede ou demorar a digitar o
     * código, e um desafio preso à sessão morreria no caminho. Em token, ele
     * é auditável, tem TTL próprio e sobrevive a tudo.
     *
     * @return array|null null quando não há 2FA a fazer.
     */
    private function talvezExigir2FA(array $dispositivo, array $user): ?array
    {
        $usuarioId = (int)$user['id'];

        $totp  = new TotpService();
        $temTotp  = $totp->isAtivo($usuarioId);
        $temEmail = (new TwoFactorService())->isAtivo($usuarioId);

        if (!$temTotp && !$temEmail) {
            return null;
        }

        // O `contexto` guarda quem é o usuário; o token em si é o que o app
        // devolve. Assim o cliente nunca precisa reenviar login e senha.
        $desafio = $this->tokens->emitir(
            '2fa_challenge',
            (int)$dispositivo['id'],
            AppTokenService::TTL_2FA,
            null,
            $usuarioId,
            (int)$user['cliente_id'],
            ['tentativas' => 0]
        );

        $canais = [];
        if ($temTotp) {
            $canais[] = ['tipo' => 'totp', 'rotulo' => 'Aplicativo autenticador'];
        }
        if ($temEmail) {
            $canais[] = [
                'tipo'    => 'email',
                'rotulo'  => 'Código por e-mail',
                'destino' => $this->mascararEmail((string)$user['email']),
            ];
        }

        return [
            'estado'     => '2fa_requerido',
            'mensagem'   => 'Confirme sua identidade para continuar.',
            'desafio'    => $desafio['token'],
            'expira_em'  => date(DATE_ATOM, time() + AppTokenService::TTL_2FA),
            'canais'     => $canais,
        ];
    }

    /**
     * Envia o código do segundo fator pelo canal escolhido.
     * TOTP não passa por aqui: o código já está no aplicativo do usuário.
     */
    public function enviarCodigo2FA(array $dispositivo, string $desafio): array
    {
        $linha = $this->tokens->validar($desafio, '2fa_challenge');

        if (!$linha || (int)$linha['dispositivo_id'] !== (int)$dispositivo['id']) {
            return ['ok' => false, 'codigo' => 'desafio_invalido', 'mensagem' => 'Desafio expirado. Entre novamente.'];
        }

        $usuarioId = (int)$linha['usuario_id'];

        // Evita virar máquina de spam de e-mail se alguém repetir a chamada.
        if (SecurityHelper::rateLimitExceeded('app_2fa_envio_' . $usuarioId, 3, 600)) {
            return ['ok' => false, 'codigo' => 'aguarde', 'mensagem' => 'Aguarde antes de pedir outro código.'];
        }

        try {
            $st = $this->pdo->prepare("SELECT nome, email FROM usuarios WHERE id = :id LIMIT 1");
            $st->execute([':id' => $usuarioId]);
            $user = $st->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return ['ok' => false, 'codigo' => 'desafio_invalido', 'mensagem' => 'Desafio inválido.'];
            }

            $codigo   = SecurityHelper::generateNumericCode(6);
            $expiraEm = date('Y-m-d H:i:s', time() + 600);

            $this->pdo->prepare(
                "UPDATE tokens_verificacao SET usado = 1
                 WHERE usuario_id = :u AND tipo = '2fa' AND usado = 0"
            )->execute([':u' => $usuarioId]);

            $this->pdo->prepare(
                "INSERT INTO tokens_verificacao (usuario_id, token, tipo, expira_em)
                 VALUES (:u, :t, '2fa', :e)"
            )->execute([':u' => $usuarioId, ':t' => $codigo, ':e' => $expiraEm]);

            MailHelper::sendLoginCode((string)$user['email'], (string)$user['nome'], $codigo);
        } catch (\Throwable $e) {
            // NUNCA logar o código — é o segredo de acesso.
            AppLog::exception($e, ['acao' => '2fa_envio', 'usuario_id' => $usuarioId]);
            return ['ok' => false, 'codigo' => 'falha_envio', 'mensagem' => 'Não foi possível enviar o código.'];
        }

        return [
            'ok'       => true,
            'destino'  => $this->mascararEmail((string)$user['email']),
            'reenvio_em' => 60,
        ];
    }

    /**
     * Verifica o código e finaliza o login.
     *
     * Aceita TOTP, código de backup e código por e-mail — o app manda só o
     * número e o servidor descobre qual é, em vez de exigir que a interface
     * pergunte "que tipo de código é esse?".
     */
    public function verificar2FA(array $dispositivo, string $desafio, string $codigo): array
    {
        $linha = $this->tokens->validar($desafio, '2fa_challenge');

        if (!$linha || (int)$linha['dispositivo_id'] !== (int)$dispositivo['id']) {
            return ['estado' => 'desafio_invalido', 'mensagem' => 'Desafio expirado. Entre novamente.'];
        }

        $usuarioId = (int)$linha['usuario_id'];
        $codigo    = preg_replace('/\D/', '', $codigo) ?: trim($codigo);
        $contexto  = $linha['contexto_arr'] ?? [];
        $tentativas = (int)($contexto['tentativas'] ?? 0);

        // 5 tentativas por desafio. Sem teto, um código de 6 dígitos cai por
        // força bruta em minutos.
        if ($tentativas >= 5) {
            $this->tokens->consumir((int)$linha['id'], 'tentativas_excedidas');
            return ['estado' => 'desafio_invalido', 'mensagem' => 'Muitas tentativas. Entre novamente.'];
        }

        $totp  = new TotpService();
        $valido = false;

        if ($totp->isAtivo($usuarioId)) {
            $segredo = $totp->getSegredo($usuarioId);
            if ($segredo && $totp->validarCodigo($segredo, $codigo)) {
                $valido = true;
            } elseif ($totp->validarCodigoBackup($usuarioId, $codigo)) {
                $valido = true;
                AppLog::warning('Login do app usou código de backup', ['usuario_id' => $usuarioId]);
            }
        }

        if (!$valido && (new TwoFactorService())->validarCodigo($usuarioId, $codigo)) {
            $valido = true;
        }

        if (!$valido) {
            $this->registrarTentativa2FA((int)$linha['id'], $tentativas + 1);

            if (class_exists('AuthLogService')) {
                AuthLogService::registrar($usuarioId, '2fa_fail', 'failed', 'app', [
                    'dispositivo_id' => (int)$dispositivo['id'],
                ]);
            }

            return [
                'estado' => 'codigo_invalido',
                'mensagem' => 'Código incorreto.',
                'tentativas_restantes' => max(0, 5 - ($tentativas + 1)),
            ];
        }

        // Desafio é de uso único: consumir impede replay do mesmo token.
        $this->tokens->consumir((int)$linha['id'], 'verificado');

        $user = $this->usuarioCompleto($usuarioId);
        if (!$user) {
            return ['estado' => 'desafio_invalido', 'mensagem' => 'Conta indisponível.'];
        }

        LogService::audit('2FA verificado no app', ['usuario_id' => $usuarioId]);

        return $this->concluirLogin($dispositivo, $user);
    }

    private function registrarTentativa2FA(int $tokenId, int $tentativas): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE app_tokens SET contexto = JSON_SET(COALESCE(contexto, '{}'), '$.tentativas', :n)
                 WHERE id = :id"
            )->execute([':n' => $tentativas, ':id' => $tokenId]);
        } catch (\Throwable $e) { /* o teto de tempo do token ainda protege */ }
    }

    /** Linha de `usuarios` + `cliente_id`, no formato que concluirLogin espera. */
    private function usuarioCompleto(int $usuarioId): ?array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT u.*, c.id AS cliente_id, c.tray_id
                 FROM usuarios u
                 INNER JOIN clientes c ON c.usuario_id = u.id
                 WHERE u.id = :id AND u.ativo = 1 AND u.deleted_at IS NULL
                 LIMIT 1"
            );
            $st->execute([':id' => $usuarioId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* =================================================================
       GOOGLE
       ================================================================= */

    /**
     * Login com Google.
     *
     * O `id_token` vem do SDK nativo configurado com o `webClientId` — por
     * isso o `aud` do token é o GOOGLE_CLIENT_ID web, e GoogleAuthService
     * valida sem nenhuma alteração.
     *
     * Conta nova NÃO é criada aqui em silêncio: o cadastro da loja pede CPF, e
     * criar cliente sem ele quebraria o checkout mais adiante. O app recebe
     * `completar_cadastro` e coleta o que falta.
     */
    public function loginGoogle(array $dispositivo, string $idToken): array
    {
        if (GOOGLE_CLIENT_ID === '') {
            return ['estado' => 'indisponivel', 'mensagem' => 'Login com Google não está configurado.'];
        }

        $servico = new GoogleAuthService();

        try {
            $payload = $servico->validarToken($idToken);
        } catch (\Throwable $e) {
            AppLog::warning('Token Google recusado', ['motivo' => $e->getMessage()]);
            return ['estado' => 'token_invalido', 'mensagem' => 'Não foi possível validar sua conta Google.'];
        }

        try {
            $cenario = $servico->avaliarCenario($payload);
        } catch (\Throwable $e) {
            return ['estado' => 'conta_desativada', 'mensagem' => $e->getMessage()];
        }

        if (($cenario['cenario'] ?? '') === 'login_direto') {
            $user = $this->usuarioCompleto((int)$cenario['usuario_id']);
            if (!$user) {
                return ['estado' => 'conta_desativada', 'mensagem' => 'Conta indisponível.'];
            }

            LogService::audit('Login Google pelo app', ['usuario_id' => (int)$user['id']]);

            // 2FA vale também para login social — quem ativou segundo fator
            // espera que ele proteja todas as portas.
            $desafio = $this->talvezExigir2FA($dispositivo, $user);
            if ($desafio) {
                return $desafio;
            }

            return $this->concluirLogin($dispositivo, $user);
        }

        return [
            'estado'   => 'completar_cadastro',
            'mensagem' => 'Falta pouco: precisamos de mais alguns dados para criar sua conta.',
            'perfil'   => [
                'nome'   => $payload['name'] ?? null,
                'email'  => $payload['email'] ?? null,
                'foto'   => $payload['picture'] ?? null,
            ],
            // O app devolve isto junto com CPF e telefone em /auth/google/cadastro.
            'id_token' => $idToken,
        ];
    }

    /**
     * Conclui o cadastro social, com os dados que o Google não fornece.
     */
    public function cadastrarComGoogle(array $dispositivo, string $idToken, array $extra): array
    {
        $servico = new GoogleAuthService();

        try {
            $payload = $servico->validarToken($idToken);
        } catch (\Throwable $e) {
            return ['estado' => 'token_invalido', 'mensagem' => 'Sessão do Google expirou. Tente novamente.'];
        }

        // Revalida o cenário: entre a primeira chamada e esta, a conta pode ter
        // sido criada em outro dispositivo.
        $cenario = $servico->avaliarCenario($payload);
        if (($cenario['cenario'] ?? '') === 'login_direto') {
            $user = $this->usuarioCompleto((int)$cenario['usuario_id']);
            return $user
                ? $this->concluirLogin($dispositivo, $user)
                : ['estado' => 'conta_desativada', 'mensagem' => 'Conta indisponível.'];
        }

        $cpf = preg_replace('/\D/', '', (string)($extra['cpf'] ?? ''));
        if (strlen($cpf) !== 11) {
            return ['estado' => 'dados_invalidos', 'mensagem' => 'Informe um CPF válido.'];
        }

        try {
            $usuarioId = $servico->criarConta($payload, [
                'cpf'      => $cpf,
                'telefone' => (string)($extra['telefone'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'cadastro_google']);
            return ['estado' => 'falha_cadastro', 'mensagem' => 'Não foi possível criar sua conta agora.'];
        }

        $user = $this->usuarioCompleto($usuarioId);
        if (!$user) {
            return ['estado' => 'falha_cadastro', 'mensagem' => 'Conta criada, mas não foi possível entrar. Tente o login.'];
        }

        LogService::audit('Conta criada via Google pelo app', ['usuario_id' => $usuarioId]);

        return $this->concluirLogin($dispositivo, $user);
    }

    /**
     * Fecha o login: vincula o dispositivo, mescla o carrinho e emite tokens.
     *
     * A ORDEM aqui não é arbitrária:
     *   1. captura o carrinho anônimo ANTES de qualquer coisa mexer na sessão;
     *   2. Session::loginCliente() regenera o session id — o dispositivo
     *      precisa ser resincronizado ou perde o carrinho (core/Session.php:123);
     *   3. só então mesclamos, já com o cliente conhecido.
     */
    public function concluirLogin(array $dispositivo, array $user): array
    {
        $dispositivoId = (int)$dispositivo['id'];
        $clienteId = (int)$user['cliente_id'];
        $usuarioId = (int)$user['id'];

        // 1. Carrinho de visitante, antes de a sessão mudar.
        $carrinhoAnonimo = Session::get('carrinho_id');
        $carrinhoAnonimo = $carrinhoAnonimo ? (int)$carrinhoAnonimo : null;

        // 2. Sessão. loginCliente() regenera o id.
        Session::loginCliente($user, ['id' => $clienteId]);
        AppSessionBridge::sincronizarSid($dispositivoId);

        $this->dispositivos->vincularCliente($dispositivoId, $usuarioId, $clienteId);

        // 3. Moto ativa. Sem isto, quem tem garagem entra e o catálogo age como
        // se não houvesse moto escolhida — o selo de compatibilidade some de
        // todos os cards até a pessoa reativar a moto na mão.
        try {
            (new VeiculoService())->carregarDoCliente($clienteId);
        } catch (\Throwable $e) {
            AppLog::warning('Falha ao carregar moto ativa no login', ['cliente_id' => $clienteId]);
        }

        // 4. Carrinho.
        $merge = (new CartMergeService($this->pdo))->mesclar($clienteId, $carrinhoAnonimo);
        if ($merge['carrinho_id']) {
            Session::setCarrinhoId((int)$merge['carrinho_id']);
        } else {
            Session::remove('carrinho_id');
        }

        // Tokens novos: a família anterior era anônima e não deve continuar
        // valendo agora que o dispositivo tem dono.
        $this->tokens->revogarDispositivo($dispositivoId, 'login');
        $par = $this->tokens->emitirPar($dispositivoId, $usuarioId, $clienteId);

        if (class_exists('AuthLogService')) {
            AuthLogService::registrar($usuarioId, 'login', 'success', 'app', [
                'dispositivo_id' => $dispositivoId,
            ]);
        }

        return [
            'estado' => 'autenticado',
            'access_token' => $par['access_token'],
            'refresh_token' => $par['refresh_token'],
            'expira_em' => $par['expira_em'],
            'cliente' => [
                'id' => $clienteId,
                'nome' => $user['nome'] ?? null,
                'email' => $user['email'] ?? null,
                'primeiro_nome' => $this->primeiroNome($user['nome'] ?? ''),
            ],
            'carrinho' => [
                'id' => $merge['carrinho_id'],
                'mesclado' => $merge['mesclado'],
                'itens_somados' => $merge['itens_somados'],
            ],
        ];
    }

    /** Encerra a sessão do dispositivo e o devolve ao estado anônimo. */
    public function logout(array $dispositivo): void
    {
        $dispositivoId = (int)$dispositivo['id'];

        if (class_exists('AuthLogService') && !empty($dispositivo['usuario_id'])) {
            AuthLogService::registrar((int)$dispositivo['usuario_id'], 'logout', 'success', 'app', [
                'dispositivo_id' => $dispositivoId,
            ]);
        }

        $this->dispositivos->desvincularCliente($dispositivoId);
        $this->tokens->revogarDispositivo($dispositivoId, 'logout');
        AppSessionBridge::reciclar($dispositivoId);
    }

    /* =================================================================
       Throttle por dispositivo
       ================================================================= */

    /**
     * Falhas de login deste dispositivo na janela.
     *
     * Conta em `auth_logs`, não no status HTTP: o endpoint responde 200 mesmo
     * com senha errada (o desfecho vai em `estado`), então contar por código de
     * resposta não funcionaria — e amarrar a proteção contra força bruta a um
     * detalhe de apresentação seria frágil de qualquer forma.
     *
     * `auth_logs` já é a fonte de verdade da trilha de acesso e é escrita tanto
     * pela web quanto pelo app.
     */
    private function tentativasDoDispositivo(int $dispositivoId): int
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM auth_logs
                 WHERE provider = 'app'
                   AND event_type = 'login_fail'
                   AND JSON_EXTRACT(metadados, '$.dispositivo_id') = :d
                   AND criado_em >= (NOW() - INTERVAL " . self::JANELA_DEVICE . " SECOND)"
            );
            $st->execute([':d' => $dispositivoId]);
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) {
            return 0; // fail-open: erro de contagem não tranca o usuário legítimo
        }
    }

    /**
     * Zera o contador após um login bem-sucedido.
     *
     * Sem isto, quem erra a senha 7 vezes e acerta na oitava continuaria a uma
     * tentativa do bloqueio pelos próximos 15 minutos — punindo justamente
     * quem provou ser o dono da conta.
     */
    private function limparTentativas(int $dispositivoId): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE auth_logs
                 SET event_type = 'login_fail_resolvido'
                 WHERE provider = 'app'
                   AND event_type = 'login_fail'
                   AND JSON_EXTRACT(metadados, '$.dispositivo_id') = :d
                   AND criado_em >= (NOW() - INTERVAL " . self::JANELA_DEVICE . " SECOND)"
            )->execute([':d' => $dispositivoId]);
        } catch (\Throwable $e) {
            // Não bloqueia o login que já foi aprovado.
        }
    }

    private function registrarFalha(
        array $dispositivo,
        RateLimitService $rateLimit,
        string $ip,
        string $login,
        ?int $usuarioId = null
    ): void {
        $rateLimit->register($ip, $login, false, 'senha');

        LogService::warning('Falha de login pelo app', [
            'dispositivo_id' => (int)$dispositivo['id'],
            'usuario_id' => $usuarioId,
        ], 'auth');

        if (class_exists('AuthLogService')) {
            AuthLogService::registrar($usuarioId, 'login_fail', 'failed', 'app', [
                'dispositivo_id' => (int)$dispositivo['id'],
            ]);
        }
    }

    /* =================================================================
       Utilidades
       ================================================================= */

    private function primeiroNome(string $nome): string
    {
        return trim(explode(' ', trim($nome))[0] ?? '');
    }

    private function mascararEmail(string $email): string
    {
        [$usuario, $dominio] = array_pad(explode('@', $email, 2), 2, '');
        if ($dominio === '') {
            return $email;
        }
        $visivel = mb_substr($usuario, 0, 1);
        return $visivel . str_repeat('*', max(2, mb_strlen($usuario) - 1)) . '@' . $dominio;
    }
}
