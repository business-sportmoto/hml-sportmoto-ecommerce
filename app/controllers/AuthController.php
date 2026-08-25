<?php
// app/controllers/AuthController.php
// Gerencia todo o fluxo de autenticação:
// registro → verificação e-mail → login → 2FA → recuperação de senha.

class AuthController extends Controller {

    /**
     * Hash bcrypt válido usado para timing-safe login.
     * NUNCA corresponde a nenhuma senha real — serve apenas para
     * gastar o mesmo tempo de CPU quando o usuário não existe.
     * Se PASSWORD_ALGO mudar (ex: argon2), regenerar com o novo algoritmo.
     */
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    private const COOKIE_ORIGEM = 'ec_verify_origin';

    /**
     * Tempo máximo entre validar a senha e concluir o 2FA.
     * Passou disso, a pendência morre e o login recomeça: a senha já foi
     * digitada, então a janela aberta é uma credencial parcial esperando.
     */
    private const PENDENCIA_2FA_TTL = 900; // 15 minutos

    private User             $userModel;
    private TokenService     $tokenService;
    private TwoFactorService $twoFactorService;
    private TotpService      $totpService;

    public function __construct() {
        $this->userModel        = new User();
        $this->tokenService     = new TokenService();
        $this->twoFactorService = new TwoFactorService();
        $this->totpService      = new TotpService();
    }

    /**
     * Hash do identificador de login para uso em LOG.
     * O e-mail/CPF em claro é PII: não vai para o log. O hash permite
     * correlacionar tentativas do mesmo alvo sem expor o dado.
     * Consistente com login_attempts.email_hash.
     */
    private function logId(string $login): string
    {
        return hash('sha256', mb_strtolower(trim($login)));
    }

    /**
     * Gate de 2FA. Chamado após senha/código validados mas ANTES de
     * finalizar o login. Se o usuário tem 2FA ativo: guarda estado
     * pendente, envia código e sinaliza ao frontend para ir à tela 2FA.
     * Retorna true se interrompeu o fluxo (2FA pendente).
     */
    private function maybeRequire2FA(array $user, bool $lembrar): bool {
        $userId = (int) $user['id'];

        // São DOIS interruptores independentes na tabela `usuarios`:
        //   dois_fatores_ativo → 2FA por e-mail/WhatsApp (toggle da conta)
        //   totp_ativo         → app autenticador (setup próprio, §Segurança)
        // Olhar só o primeiro fazia quem configurou o Google Authenticator
        // entrar direto com a senha: o app ficava ativo na tela de
        // segurança, mas o login nunca pedia o código.
        $exige2FA = $this->twoFactorService->isAtivo($userId)
                 || $this->totpService->isAtivo($userId);

        if (!$exige2FA) {
            return false;
        }

        // Marca a pendência — NAO envia codigo ainda.
        // O envio acontece em send2FAChannel() apos o usuario escolher
        // o canal (e-mail / WhatsApp / SMS) na tela /autenticacao-2fa.
        Session::set('_2fa_pending_user',    $userId);
        Session::set('_2fa_pending_cliente', (int)$user['cliente_id']);
        Session::set('_2fa_lembrar',         $lembrar);

        // Janela de vida da pendência. Sem isto, um `_2fa_pending_user`
        // esquecido na sessão deixa a tela de código válida por tempo
        // indeterminado depois da senha ter sido digitada.
        Session::set('_2fa_pending_em', time());

        $this->json([
            'ok'         => false,
            'requer_2fa' => true,
            'redirect'   => BASE_URL . '/autenticacao-2fa',
            'msg'        => 'Verificacao em duas etapas necessaria.',
        ]);
        return true;
    }

    /**
     * Usuário com 2FA pendente NESTA sessão, ou 0.
     *
     * Fonte única para as três telas do fluxo (form, envio e validação):
     * cada uma lia `_2fa_pending_user` por conta própria e nenhuma
     * checava validade, então a pendência sobrevivia indefinidamente.
     * Expirou → limpa tudo e devolve 0; quem chama decide o que exibir.
     */
    private function pending2FAUserId(): int {
        $userId = (int) Session::get('_2fa_pending_user');
        if ($userId <= 0) return 0;

        $iniciadoEm = (int) Session::get('_2fa_pending_em', 0);
        if ($iniciadoEm > 0 && (time() - $iniciadoEm) > self::PENDENCIA_2FA_TTL) {
            LogService::info('Pendência de 2FA expirada', [
                'usuario_id' => $userId,
            ], 'auth');
            $this->clear2FAPending();
            return 0;
        }

        return $userId;
    }

    /** Limpa todo o estado intermediário de 2FA da sessão. */
    private function clear2FAPending(): void {
        Session::remove('_2fa_pending_user');
        Session::remove('_2fa_pending_cliente');
        Session::remove('_2fa_lembrar');
        Session::remove('_2fa_canal_usado');
        Session::remove('_2fa_pending_em');
    }

    /**
     * Canais de 2FA disponiveis. E-mail sempre existe; WhatsApp/SMS
     * dependem de celular cadastrado. SMS fica como gancho desabilitado.
     */
    private function getCanais2FA(array $perfil, int $userId): array {
        $email   = $perfil['email']   ?? '';
        $celular = preg_replace('/\D/', '', $perfil['celular'] ?? '');
        $temCel  = strlen($celular) >= 10;
        $temTotp = $this->totpService->isAtivo($userId);

        // Canais de ENVIO (e-mail/WhatsApp) pertencem ao toggle
        // `dois_fatores_ativo`. Quem ativou SÓ o app autenticador não
        // pode ver o e-mail listado aqui: seria rebaixar um fator que o
        // cliente escolheu justamente por não depender da caixa postal.
        // Nesse caso a saída de emergência são os códigos de backup, que
        // o twoFactor() aceita no mesmo campo.
        $temEnvio = $this->twoFactorService->isAtivo($userId);

        return [
            // TOTP primeiro quando disponível — é o canal mais rápido
            // (código já está no app, sem esperar envio) e mais seguro
            // (não depende de e-mail/SMS poderem ser interceptados).
            'totp' => [
                'habilitado' => $temTotp,
                'label'      => 'App autenticador',
                'destino'    => 'Código do app ou um código de backup',
            ],
            'email' => [
                'habilitado' => $temEnvio && $email !== '',
                'label'      => 'E-mail',
                'destino'    => $this->maskEmail2($email),
            ],
            'whatsapp' => [
                'habilitado' => $temEnvio && $temCel,
                'label'      => 'WhatsApp',
                'destino'    => $temCel ? $this->maskPhone($celular) : 'Sem celular cadastrado',
            ],
            // SMS depende de TRÊS coisas: o 2FA por envio estar ligado na
            // conta, haver celular cadastrado e o gateway estar de fato
            // configurado. Sem a última checagem o cliente escolheria um
            // canal que só devolve erro — pior do que não oferecer.
            // 'sms' => [
            //     'habilitado' => $temEnvio && $temCel && SmsService::disponivel(),
            //     'label'      => 'SMS',
            //     'destino'    => $temCel ? $this->maskPhone($celular) : 'Sem celular cadastrado',
            // ],
            'sms' => [
                'habilitado' => $temEnvio && $temCel && SmsService::disponivel(),  // habilitar quando houver gateway SMS
                'label'      => 'SMS',
                'destino'    => $temCel ? $this->maskPhone($celular) : 'Sem celular cadastrado',
                // 'em_breve'   => true,
            ],
        ];
    }

    private function maskEmail2(string $email): string {
        if (!str_contains($email, '@')) return $email;
        [$u, $dom] = explode('@', $email, 2);
        return mb_substr($u, 0, 1) . str_repeat('*', max(1, mb_strlen($u) - 1)) . '@' . $dom;
    }

    private function maskPhone(string $cel): string {
        if (strlen($cel) < 4) return $cel;
        return '(' . substr($cel, 0, 2) . ') ****-**' . substr($cel, -2);
    }


    // ── Formulário de login ───────────────────────────────────

    public function loginForm(): void {
        if (Session::isClienteLogado()) {
            $this->redirect(BASE_URL . '/minha-conta');
        }
        SeoHelper::setTitle('Entrar');
        SeoHelper::setRobots('noindex, follow');
        $this->render('auth/login', [
            'etapa'  => 'identidade',
            'valor'  => '',
            'erro'   => Session::getFlash('error'),
        ], 'minimal');
    }

    // ── Etapa 1: verifica se o usuário existe ─────────────────

    public function checkIdentity(): void {
        $this->verifyCsrf();

        $valor = SecurityHelper::sanitizeString($_POST['login'] ?? '');
        $valor = trim($valor);

        if (empty($valor)) {
            $this->json(['ok' => false, 'msg' => 'Informe o e-mail ou CPF.']);
        }

        // ── Rate limit em 2 camadas (anti-enumeration) ────
        // Este endpoint revela se uma conta existe (UX em 2 etapas),
        // então o limite precisa ser apertado:
        //   curto prazo: 10 consultas / 5 min  (digitação humana real)
        //   longo prazo: 30 consultas / 1 hora (bloqueia scraping lento)
        $ipKey = md5($_SERVER['REMOTE_ADDR'] ?? '');
        if (SecurityHelper::rateLimitExceeded('check_identity_'   . $ipKey, 10, 300) ||
            SecurityHelper::rateLimitExceeded('check_identity_h_' . $ipKey, 30, 3600)) {
            // [LOG] Rate limit no endpoint que revela existência de conta:
            // assinatura clássica de enumeração de usuários.
            LogService::warning('Rate limit em checkIdentity (possível enumeração)', [
                'login_hash' => $this->logId($valor),
            ], 'auth');
            usleep(random_int(150000, 400000));
            $this->json(['ok' => false, 'msg' => 'Muitas tentativas. Aguarde alguns minutos.']);
        }

        $db   = Database::getInstance()->getConnection();
        $user = $this->findUserByLogin($db, $valor);

        // ── Delay artificial (150–400ms) ──────────────────
        // Aplica-se a TODAS as respostas: iguala estatisticamente o
        // tempo entre "existe" e "não existe" e torna a enumeração
        // em massa cara (cada consulta custa ao menos ~250ms).
        usleep(random_int(150000, 400000));

        if (!$user) {
            // Não encontrado → redireciona para cadastro
            $this->json([
                'ok'          => false,
                'nao_existe'  => true,
                'redirect'    => BASE_URL . '/cadastro?origem=' . urlencode($valor),
                'msg'         => 'Conta não encontrada. Vamos criar uma para você!',
            ]);
        }

        if (!$user['ativo']) {
            $this->json(['ok' => false, 'msg' => 'Esta conta está desativada. Entre em contato com o suporte.']);
        }

        /**
         * Conta sem senha local definida (senha_definida = 0) — não deve
         * ver a etapa de senha (não tem senha para digitar). Trata aqui,
         * na etapa 1, em vez de na etapa de senha: o cliente é desviado
         * antes mesmo de o campo de senha aparecer. Dois cenários,
         * diferenciados por clientes.tray_id:
         *
         *  - Importado da Tray (tray_id preenchido): migrado de outra
         *    plataforma, que não exportou senha. Redireciona direto para
         *    "definir senha", já com o e-mail na query string.
         *
         *  - Criado via Google (sem tray_id): orienta a usar o login
         *    Google (botão próprio na tela) ou "Esqueci minha senha".
         */
        if (isset($user['senha_definida']) && (int)$user['senha_definida'] === 0) {
            $veioDaTray = !empty($user['tray_id']);

            if ($veioDaTray) {
                Session::flash('info', 'Você precisa definir uma nova senha para ter acesso a sua conta.');
                
                $this->json([
                    'ok'            => false,
                    'definir_senha' => true,
                    'email'         => $user['email'],
                    'redirect'      => BASE_URL . '/recuperar-senha?email=' . urlencode($user['email']),
                    'msg'           => 'Identificamos sua conta da nossa loja anterior. '
                                     . 'Por segurança, defina uma nova senha para continuar.',
                ]);
            }

            $this->json([
                'ok'  => false,
                'sem_senha_google' => true,
                'msg' => 'Esta conta usa login com Google. Entre com o Google ou use "Esqueci minha senha".',
            ]);
        }

        // Usuário encontrado → retorna dados para exibir a etapa de senha
        $this->json([
            'ok'        => true,
            'nome'      => mb_substr($user['nome'], 0, strpos($user['nome'], ' ') ?: 20),
            'avatar'    => $this->getAvatar($db, $user['id']),
            'email_mask'=> $this->maskEmail($user['email']),
            'login'     => $valor,
        ]);
    }

    // ── Etapa 2a: login com senha ─────────────────────────────

    // public function login(): void {
    //     $this->verifyCsrf();

    //     $login  = SecurityHelper::sanitizeString($_POST['login'] ?? '');
    //     $senha  = $_POST['senha'] ?? '';
    //     $lembrar = !empty($_POST['lembrar']);

    //     if (empty($login) || empty($senha)) {
    //         $this->json(['ok' => false, 'msg' => 'Preencha todos os campos.']);
    //     }

    //     $rateKey = 'login_' . md5($login);
    //     if (SecurityHelper::rateLimitExceeded($rateKey, 5, 900)) {
    //         $this->json(['ok' => false, 'msg' => 'Muitas tentativas. Tente novamente em 15 minutos.']);
    //     }

    //     $db   = Database::getInstance()->getConnection();
    //     $user = $this->findUserByLogin($db, $login);

    //     if (!$user || !password_verify($senha, $user['senha_hash'])) {
    //         $this->json(['ok' => false, 'msg' => 'Senha incorreta.']);
    //     }

    //     if (!$user['ativo']) {
    //         $this->json(['ok' => false, 'msg' => 'Conta desativada.']);
    //     }

    //     // Verifica e-mail verificado
    //     if (!$user['email_verificado']) {
    //         $this->json([
    //             'ok'              => false,
    //             'email_pendente'  => true,
    //             'msg'             => 'Confirme seu e-mail antes de entrar. Verifique sua caixa de entrada.',
    //             'login'           => $login,
    //         ]);
    //     }

    //     SecurityHelper::clearRateLimit($rateKey);
    //     $this->finalizeLogin($user, $lembrar, $db);
    // }

    // // app/controllers/AuthController.php — método login() existente

    // public function login(): void {
    //     $this->verifyCsrf();
    //     $email = mb_strtolower(trim($_POST['email'] ?? ''));
    //     $senha = $_POST['senha'] ?? '';

    //     $stmt = $this->db->prepare(
    //         "SELECT id, nome, email, senha, senha_definida, ativo
    //         FROM clientes WHERE email = ? LIMIT 1"
    //     );
    //     $stmt->execute([$email]);
    //     $cliente = $stmt->fetch();

    //     if (!$cliente || !$cliente['ativo']) {
    //         AuthLogService::registrar(null, 'login_fail', 'failed', 'local', ['email' => $email]);
    //         $this->json(['ok' => false, 'msg' => 'E-mail ou senha incorretos.']);
    //     }

    //     // Se conta foi criada por social, não tem senha real — orienta o usuário
    //     if (!$cliente['senha_definida']) {
    //         AuthLogService::registrar((int)$cliente['id'], 'login_fail', 'failed', 'local', [
    //             'motivo' => 'sem_senha_definida',
    //         ]);
    //         $this->json([
    //             'ok'  => false,
    //             'msg' => 'Esta conta foi criada via Google. Faça login com Google ou use ' .
    //                     '"Esqueci minha senha" para definir uma senha.',
    //         ]);
    //     }

    //     if (!password_verify($senha, $cliente['senha'])) {
    //         AuthLogService::registrar((int)$cliente['id'], 'login_fail', 'failed', 'local');
    //         $this->json(['ok' => false, 'msg' => 'E-mail ou senha incorretos.']);
    //     }

    //     session_regenerate_id(true);
    //     Session::set('cliente_id',    (int)$cliente['id']);
    //     Session::set('cliente_nome',  $cliente['nome']);
    //     Session::set('cliente_email', $cliente['email']);
    //     Session::set('login_provider', 'local');

    //     AuthLogService::registrar((int)$cliente['id'], 'login_ok', 'success', 'local');

    //     if (class_exists('VeiculoService')) {
    //         (new VeiculoService())->carregarDoCliente((int)$cliente['id']);
    //     }

    //     $this->json(['ok' => true, 'redirect' => BASE_URL . '/minha-conta']);
    // }

    public function login(): void
    {
        $this->verifyCsrf();

        $login   = SecurityHelper::sanitizeString($_POST['login'] ?? $_POST['email'] ?? '');
        $senha   = $_POST['senha'] ?? '';
        $lembrar = !empty($_POST['lembrar']);

        if (empty($login) || empty($senha)) {
            $this->json(['ok' => false, 'msg' => 'Preencha todos os campos.']);
        }

        // ── Rate limit em 2 camadas (IP + conta) + CAPTCHA ────
        $ip             = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $recaptchaToken = $_POST['recaptcha_token'] ?? null;
        $rateLimit      = new RateLimitService();
        $rl             = $rateLimit->check($ip, $login, $recaptchaToken);

        if ($rl['status'] === 'blocked') {
            LogService::warning('Login bloqueado por rate limit', [
                'login_hash' => $this->logId($login),
                'motivo'     => $rl['msg'] ?? null,
            ], 'auth');
            $this->json(['ok' => false, 'msg' => $rl['msg']]);
        }
        if ($rl['status'] === 'captcha') {
            LogService::info('Login exigiu CAPTCHA', [
                'login_hash' => $this->logId($login),
            ], 'auth');
            // Token ausente ou score insuficiente — front precisa gerar
            // (ou regerar) o token do reCAPTCHA v3 e reenviar o login.
            $this->json([
                'ok'               => false,
                'captcha_required' => true,
                'msg'              => $rl['msg'],
            ]);
        }

        $db = Database::getInstance()->getConnection();

        $user = $this->findUserByLogin($db, $login);

        if (!$user) {
            // ── Timing-safe ───────────────────────────────
            // Executa um verify contra hash dummy para igualar a
            // latência com o caminho "usuário existe" — sem isso,
            // a diferença de tempo revela quais e-mails têm conta.
            password_verify($senha, self::DUMMY_HASH);

            $rateLimit->register($ip, $login, false, 'senha');

            if (class_exists('AuthLogService')) {
                AuthLogService::registrar(null, 'login_fail', 'failed', 'local', ['login' => $login]);
            }

            $this->json(['ok' => false, 'msg' => 'E-mail ou senha incorretos.']);
        }

        if (!$user['ativo']) {
            // [LOG] Alguém com credencial de conta DESATIVADA tentando entrar.
            // Merece warning: pode ser ex-funcionário ou conta banida.
            LogService::warning('Tentativa de login em conta desativada', [
                'usuario_id' => (int) $user['id'],
            ], 'auth');
            $this->json(['ok' => false, 'msg' => 'Conta desativada. Se isso for um engano, envie um e-mail para ecommerce@sportmoto.com.br.']);
        }

        /**
         * Conta sem senha local definida (senha_definida = 0). Dois
         * cenários possíveis, diferenciados por clientes.tray_id:
         *
         *  - Importada da Tray (tray_id preenchido): cliente migrado de
         *    outra plataforma. A Tray não exporta senha, então ele nunca
         *    teve senha aqui — precisa definir uma. Redireciona direto
         *    para o fluxo de "definir senha" (decisão de UX: sem fricção
         *    de clicar em link).
         *
         *  - Criada via Google (sem tray_id): mantém o comportamento
         *    atual — orienta a logar com Google ou usar "Esqueci senha".
         */
        if (isset($user['senha_definida']) && (int)$user['senha_definida'] === 0) {
            if (class_exists('AuthLogService')) {
                AuthLogService::registrar((int)$user['id'], 'login_fail', 'failed', 'local', [
                    'motivo' => 'sem_senha_definida',
                ]);
            }

            $veioDaTray = !empty($user['tray_id']);

            if ($veioDaTray) {
                $this->json([
                    'ok'              => false,
                    'definir_senha'   => true, // front redireciona automaticamente
                    'email'           => $user['email'],
                    'redirect'        => BASE_URL . '/recuperar-senha?email=' . urlencode($user['email']),
                    'msg'             => 'Identificamos sua conta da nossa loja anterior. '
                                       . 'Por segurança, defina uma nova senha para continuar.',
                ]);
            }

            $this->json([
                'ok'  => false,
                'msg' => 'Esta conta foi criada via Google. Faça login com Google ou use "Esqueci minha senha" para definir uma senha.',
            ]);
        }

        if (!password_verify($senha, $user['senha_hash'])) {
            $rateLimit->register($ip, $login, false, 'senha');

            // [LOG] Senha incorreta em conta EXISTENTE. Sinal forte de ataque
            // quando repetido: o dashboard agrupa por fingerprint e o contador
            // de ocorrências mostra o volume.
            LogService::warning('Falha de login: senha incorreta', [
                'usuario_id' => (int) $user['id'],
            ], 'auth');

                $this->json(['ok' => false, 'msg' => 'E-mail ou senha incorretos.']);
            }

        if (!$user['email_verificado']) { 
            $this->json([
                'ok'             => false,
                'email_pendente' => true,
                'msg'            => 'Confirme seu e-mail antes de entrar. Verifique sua caixa de entrada.',
                'login'          => $login,
            ]);
        }

        $rateLimit->register($ip, $login, true, 'senha');
        $rateLimit->clearAccount($login);

        // [LOG] audit: trilha de acesso (LGPD). Canal 'audit'.
        LogService::audit('Login bem-sucedido (senha)', [
            'usuario_id' => (int) $user['id'],
            'lembrar'    => $lembrar,
        ]);

        // ── Gate de 2FA ───────────────────────────────────
        // Senha OK, mas se o usuário tem 2FA ativo, NÃO loga ainda:
        // envia código e interrompe aqui. O login completa em twoFactor().
        if ($this->maybeRequire2FA($user, $lembrar)) {
            return;
        }

        $this->finalizeLogin($user, $lembrar);
    }

    // ── Etapa 2b: enviar código por e-mail ───────────────────

    public function sendLoginCode(): void {
        $this->verifyCsrf();

        $login = SecurityHelper::sanitizeString($_POST['login'] ?? '');
        if (empty($login)) {
            $this->json(['ok' => false, 'msg' => 'Informe o e-mail ou CPF.']);
        }

        // Rate limit de ENVIO de código (evita flood de e-mails)
        $ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateLimit = new RateLimitService();
        if (SecurityHelper::rateLimitExceeded('login_code_send_' . md5($login), 3, 600)) {
            $this->json(['ok' => false, 'msg' => 'Aguarde antes de solicitar outro código.']);
        }

        $db   = Database::getInstance()->getConnection();
        $user = $this->findUserByLogin($db, $login);

        if (!$user || !$user['ativo']) {
            // Resposta genérica por segurança
            $this->json(['ok' => true, 'msg' => 'Se a conta existir, o código será enviado.']);
        }

        // Gera código numérico de 6 dígitos
        $code     = SecurityHelper::generateNumericCode(6);
        $expiraEm = date('Y-m-d H:i:s', time() + 600); // 10 minutos

        // Invalida códigos de login anteriores (só os DESTE tipo — um
        // pedido de login não pode derrubar a verificação de e-mail
        // pendente do cadastro, e vice-versa).
        $db->prepare(
            "UPDATE tokens_verificacao SET usado = 1
             WHERE usuario_id = ? AND tipo = 'login_code' AND usado = 0"
        )->execute([$user['id']]);

        // Vincula o token ao navegador que o solicitou
        $origemHash = $this->emitirNonceOrigem();

        // tipo 'login_code', NÃO 'email_verify'. Os dois eram gravados no
        // mesmo balde, e verifyEmail() resolve /verificar-email/{token}
        // por token puro, sem escopo de usuário: um código de login de 6
        // dígitos podia ser resgatado como link de verificação de outra
        // pessoa. O ENUM da coluna já previa este valor.
        $db->prepare(
            "INSERT INTO tokens_verificacao
                (usuario_id, token, tipo, expira_em, origem_hash)
             VALUES (?, ?, 'login_code', ?, ?)"
        )->execute([$user['id'], $code, $expiraEm, $origemHash]);

        // Envia e-mail
        // Envia e-mail
        try {
            MailHelper::sendLoginCode($user['email'], $user['nome'], $code);

            // [LOG] NUNCA inclua $code no contexto — é o segredo de acesso.
            LogService::info('Código de login enviado por e-mail', [
                'usuario_id' => (int) $user['id'],
            ], 'auth');

        } catch (\Throwable $e) {
            // [LOG] error: o cliente legítimo NÃO consegue entrar.
            LogService::exception($e, 'error', 'auth', [
                'usuario_id' => (int) $user['id'],
                'acao'       => 'envio_codigo_login',
            ]);
            $this->json(['ok' => false, 'msg' => 'Não foi possível enviar o código. Tente novamente.']);
        }
        
        $this->json([
            'ok'         => true,
            'email_mask' => $this->maskEmail($user['email']),
            'msg'        => 'Código enviado para ' . $this->maskEmail($user['email']),
        ]);
    }

    // ── Etapa 2b: validar código de login ─────────────────────

    public function validateLoginCode(): void {
        $this->verifyCsrf();

        $login   = SecurityHelper::sanitizeString($_POST['login'] ?? '');
        $codigo  = trim($_POST['codigo'] ?? '');
        $lembrar = !empty($_POST['lembrar']);

        if (empty($login) || strlen($codigo) !== 6) {
            $this->json(['ok' => false, 'msg' => 'Código inválido.']);
        }

        $ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateLimit = new RateLimitService();

        // Rate limit por código: 5 tentativas / 10 min → força novo código
        $rlCode = $rateLimit->checkCode($ip, $login);
        if ($rlCode['status'] === 'blocked') {
            $this->json(['ok' => false, 'msg' => $rlCode['msg']]);
        }

        $db   = Database::getInstance()->getConnection();
        $user = $this->findUserByLogin($db, $login);

        if (!$user) {
            // Timing-safe + registra para o rate limit
            $rateLimit->register($ip, $login, false, 'codigo_email');
            $this->json(['ok' => false, 'msg' => 'Código incorreto ou expirado.']);
        }

        // Valida o código. Este endpoint atende DOIS formulários:
        //   #form-codigo       → login por código  → tipo 'login_code'
        //   #form-verify-email → ativação da conta → tipo 'email_verify'
        // Ambos provam posse da caixa postal, então ambos servem aqui.
        // Sempre escopado por usuario_id — nunca só pelo valor do token.
        $stmt = $db->prepare(
            "SELECT id FROM tokens_verificacao
             WHERE usuario_id = ?
               AND token      = ?
               AND tipo       IN ('login_code', 'email_verify')
               AND usado      = 0
               AND expira_em  > NOW()
             LIMIT 1"
        );
        $stmt->execute([$user['id'], $codigo]);
        $tokenRow = $stmt->fetch();

        if (!$tokenRow) {
            $rateLimit->register($ip, $login, false, 'codigo_email');

            // [LOG] Código de login errado/expirado. Repetido = brute-force
            // de OTP de 6 dígitos (espaço de busca pequeno — merece atenção).
            LogService::warning('Código de login inválido ou expirado', [
                'usuario_id' => (int) $user['id'],
            ], 'auth');

            $this->json(['ok' => false, 'msg' => 'Código incorreto ou expirado.']);
        }

        // Consome o token
        $db->prepare(
            "UPDATE tokens_verificacao SET usado = 1 WHERE id = ?"
        )->execute([$tokenRow['id']]);

        $rateLimit->register($ip, $login, true, 'codigo_email');
        $rateLimit->clearAccount($login);

        // Consumir um token 'email_verify' É prova de posse do e-mail.
        // Sem isto, quem ativa pelo CÓDIGO fica com email_verificado = 0
        // para sempre — só o clique no link (verifyEmail) marcava.
        if (empty($user['email_verificado'])) {
            // ── A GRAVAÇÃO QUE FALTAVA ────────────────────
            // O comentário acima já descrevia a intenção, mas o UPDATE
            // nunca existiu. Resultado: finalizeLogin() gravava
            // email_verificado=false na sessão, e o guard de bootstrap
            // (AuthHelper::enforceEmailVerificado) deslogava o cliente no
            // primeiro clique — o login "dava certo" e voltava para /login.
            $this->userModel->markEmailVerified((int) $user['id']);

            // Reflete no array que segue para o finalizeLogin: é dele que
            // sai a chave de sessão lida pelo guard.
            $user['email_verificado'] = 1;

            // [LOG] audit: ativação de conta pelo código — evento de
            // ciclo de vida da conta, não só de acesso.
            LogService::audit('E-mail verificado via código de login', [
                'usuario_id' => (int) $user['id'],
            ]);

            // Cliente novo ativou → cria contato no Bling AGORA (best-effort).
            // Bling fora NÃO trava o login: cai na fila e o cron cria depois.
            try {
                $svc = new BlingContatoService();
                $r   = $svc->sincronizarPorUsuario((int)$user['id']);
                if (!$r['ok']) {
                    $svc->enfileirarPorUsuario((int)$user['id']);   // fallback → fila
                }
            } catch (\Throwable $e) {
                error_log('[ativacao] sync Bling falhou, enfileirado: ' . $e->getMessage());
                (new BlingContatoService())->enfileirarPorUsuario((int)$user['id']);
            }
        }

        // Gate de 2FA também no login por código de e-mail
        if ($this->maybeRequire2FA($user, $lembrar)) {
            return;
        }

        $this->finalizeLogin($user, $lembrar); 
    }

    // ── 2FA ───────────────────────────────────────────────────

    public function twoFactorForm(): void {
        $userId = $this->pending2FAUserId();
        if ($userId <= 0) {
            Session::flash('error', 'Sua verificação expirou. Entre novamente.');
            $this->redirect(BASE_URL . '/login');
            return;
        }

        $perfil = $this->userModel->findWithProfile($userId);
        $canais = $this->getCanais2FA($perfil ?? [], $userId);

        // Nenhum canal utilizável = tela sem saída. Acontece se o cliente
        // tinha 2FA por e-mail e o e-mail foi apagado do perfil, ou se o
        // TOTP foi desativado no meio do fluxo. Melhor recomeçar o login
        // do que exibir uma lista de botões todos desabilitados.
        if (!array_filter($canais, static fn(array $c): bool => !empty($c['habilitado']))) {
            LogService::error('2FA sem canal disponível — login interrompido', [
                'usuario_id' => $userId,
            ], 'auth');

            $this->clear2FAPending();
            Session::flash('error',
                'Não há canal de verificação disponível para sua conta. Fale com o suporte.');
            $this->redirect(BASE_URL . '/login');
            return;
        }

        // ── Retoma a etapa do código ──────────────────────
        // O canal já escolhido vive na sessão. Sem consultá-lo, QUALQUER
        // recarga desta URL voltava para a lista de canais — inclusive o
        // redirect de "código inválido" do ramo não-Ajax logo abaixo, que
        // jogava o cliente de volta ao início da verificação em vez de
        // mostrar o erro. Só retoma se o canal ainda estiver habilitado.
        $canalEscolhido = (string) Session::get('_2fa_canal_usado', '');
        if ($canalEscolhido === '' || empty($canais[$canalEscolhido]['habilitado'])) {
            $canalEscolhido = '';
        }

        SeoHelper::setTitle('Verificação em dois fatores');
        SeoHelper::setRobots('noindex, nofollow');
        $this->render('auth/two-factor', [
            'canais'         => $canais,
            'canalEscolhido' => $canalEscolhido,
        ], 'minimal');
    }

    /**
     * POST /autenticacao-2fa/enviar
     * Envia o código pelo canal escolhido (email | whatsapp | sms).
     * Gera o código UMA vez via TwoFactorService; o canal só muda a entrega.
     */
    public function send2FAChannel(): void {
        $this->verifyCsrf();

        $userId = $this->pending2FAUserId();
        if ($userId <= 0) {
            $this->json(['ok' => false, 'msg' => 'Sessão expirada. Faça login novamente.', 'restart' => true]);
        }

        $canal  = SecurityHelper::sanitizeString($_POST['canal'] ?? '');
        $perfil = $this->userModel->findWithProfile($userId);
        $canais = $this->getCanais2FA($perfil ?? [], $userId);

        if (!isset($canais[$canal]) || !$canais[$canal]['habilitado']) {
            $this->json(['ok' => false, 'msg' => 'Canal de verificação indisponível.']);
        }

        // TOTP é diferente dos outros canais: não há nada para "enviar"
        // — o código já existe no app do usuário, gerado localmente a
        // cada 30s. Só confirma a escolha e libera a etapa de digitar.
        if ($canal === 'totp') {
            Session::set('_2fa_canal_usado', 'totp');
            $this->json([
                'ok'      => true,
                'canal'   => 'totp',
                'destino' => $canais['totp']['destino'],
                'msg'     => 'Digite o código do seu app autenticador.',
            ]);
        }

        // Rate limit de ENVIO (evita flood): 3 envios / 5 min por usuário
        if (SecurityHelper::rateLimitExceeded('2fa_send_' . $userId, 3, 300)) {
            $this->json(['ok' => false, 'msg' => 'Aguarde antes de solicitar outro código.']);
        }

        // Gera o código (mesma lógica do painel)
        $code = $this->twoFactorService->solicitarVerificacao($userId, 'login');

        try {
            switch ($canal) {
                case 'whatsapp':
                    // $perfil tem nome, celular, email — formato que o service espera
                    WhatsappService::sendCodigoVerificacao($perfil, $code, 10);
                    break;

                case 'sms':
                    // Diferente do e-mail e do WhatsApp, o SmsService NÃO
                    // lança em falha — devolve bool. Sem checar o retorno,
                    // a tela diria "código enviado" para uma mensagem que
                    // nunca saiu, e o cliente ficaria esperando.
                    $enviado = SmsService::sendCodigo(
                        (string) ($perfil['celular'] ?? ''),
                        $code,
                        10,
                        ['cliente_id' => (int) ($perfil['cliente_id'] ?? 0) ?: null]
                    );

                    if (!$enviado) {
                        $this->json([
                            'ok'  => false,
                            'msg' => 'Não foi possível enviar o SMS. Tente outro canal.',
                            
                        ]);
                    }
                    break;

                case 'email':
                default:
                    MailHelper::send2FACode($perfil['email'], $perfil['nome'], $code);
                    break;
            }
        } catch (\Throwable $e) {
            // ANTES: error_log('[AuthController] envio 2FA (' . $canal . '): ' . ...)
            // [LOG] error: o segundo fator não chegou — o cliente legítimo
            // fica travado fora da própria conta.
            LogService::exception($e, 'error', 'auth', [
                'usuario_id' => $userId,
                'canal'      => $canal,       // 'email' | 'whatsapp' | 'sms'
                'acao'       => 'envio_2fa',
            ]);

            $this->json(['ok' => false, 'msg' => 'Não foi possível enviar o código. Tente outro canal.']);
        }

        Session::set('_2fa_canal_usado', $canal);

        // [LOG] NUNCA o $code aqui.
        LogService::info('Código 2FA enviado', [
            'usuario_id' => $userId,
            'canal'      => $canal,
        ], 'auth');

        $this->json([
            'ok'      => true,
            'canal'   => $canal,
            'destino' => $canais[$canal]['destino'],
            'msg'     => 'Código enviado por ' . $canais[$canal]['label'] . '.',
        ]);
    }

    public function twoFactor(): void {
        $this->verifyCsrf();

        $userId = $this->pending2FAUserId();
        if ($userId <= 0) {
            $msg = 'Sua verificação expirou. Entre novamente.';
            if (AuthHelper::isAjax()) {
                $this->json(['ok' => false, 'msg' => $msg, 'restart' => true]);
            }
            Session::flash('error', $msg);
            $this->redirect(BASE_URL . '/login');
            return;
        }

        $code = trim($_POST['code'] ?? '');

        // Rate limit do código 2FA: 5 tentativas / 10 min
        $ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateLimit = new RateLimitService();
        $user      = $this->userModel->findWithProfile($userId);

        // A conta pode ter sido desativada/excluída ENTRE a senha e o
        // código. A senha já foi aceita, então só o recheck aqui impede
        // que a janela do 2FA vire uma porta para conta bloqueada.
        if (!$user || empty($user['ativo']) || !empty($user['deleted_at'])) {
            LogService::warning('2FA interrompido: conta inativa ou removida', [
                'usuario_id' => $userId,
            ], 'auth');

            $this->clear2FAPending();
            $msg = 'Não foi possível concluir o acesso. Fale com o suporte.';
            if (AuthHelper::isAjax()) {
                $this->json(['ok' => false, 'msg' => $msg, 'restart' => true]);
            }
            Session::flash('error', $msg);
            $this->redirect(BASE_URL . '/login');
            return;
        }

        $emailRL = $user['email'] ?? ('uid_' . $userId);

        $rlCode = $rateLimit->checkCode($ip, $emailRL);
        if ($rlCode['status'] === 'blocked') {
            // [LOG] CRÍTICO: alguém passou da senha e está martelando o 2FA.
            // Ou o atacante TEM a senha correta (credencial vazada) e só falta
            // o segundo fator — este é o sinal mais alarmante do fluxo de auth.
            LogService::critical('2FA bloqueado por rate limit (senha já validada)', [
                'usuario_id' => (int) $userId,
            ], 'auth');

            // Invalida a sessão 2FA pendente — força recomeçar o login
            $this->clear2FAPending();
            if (AuthHelper::isAjax()) {
                $this->json(['ok' => false, 'msg' => $rlCode['msg'], 'restart' => true]);
            }
            Session::flash('error', $rlCode['msg']);
            $this->redirect(BASE_URL . '/login');
            return;
        }

        // Roteia a validação conforme o canal escolhido na etapa anterior.
        // TOTP/backup não passam por TwoFactorService (que gera/envia
        // código temporário) — validam contra o segredo permanente do app.
        $canalUsado = Session::get('_2fa_canal_usado', 'email');

        if ($canalUsado === 'totp') {
            $segredo      = $this->totpService->getSegredo($userId);
            $codigoValido = $segredo && $this->totpService->validarCodigo($segredo, $code);

            // Fallback: se não bateu como TOTP, tenta como código de
            // backup de uso único (cobre "perdi o celular, mas tenho a
            // lista de códigos salva").
            if (!$codigoValido) {
                $codigoValido = $this->totpService->validarCodigoBackup($userId, $code);
            }
        } else {
            // Mesmo sistema do painel (e-mail/WhatsApp/SMS).
            // validarCodigo() recebe (usuarioId, code) — o 3º argumento
            // 'login' que existia aqui era silenciosamente descartado e
            // dava a impressão de haver escopo por ação, que não há.
            $codigoValido = $this->twoFactorService->validarCodigo($userId, $code);
        }

        if (!$codigoValido) {
            $rateLimit->register($ip, $emailRL, false, '2fa');
            // [LOG] Código 2FA errado.
            LogService::warning('Código 2FA inválido', [
                'usuario_id' => (int) $userId,
                'canal'      => $canalUsado,
            ], 'auth');

            if (AuthHelper::isAjax()) {
                $this->json(['ok' => false, 'msg' => 'Código inválido ou expirado.']);
            }
            Session::flash('error', 'Código inválido ou expirado. Tente novamente.');
            $this->redirect(BASE_URL . '/autenticacao-2fa');
            return;
        }

        $rateLimit->register($ip, $emailRL, true, '2fa');
        $rateLimit->clearAccount($emailRL);

        // [LOG] audit: segundo fator validado.
        LogService::audit('2FA validado', [
            'usuario_id' => (int) $userId,
            'canal'      => $canalUsado,
        ]);

        $lembrar = (bool) Session::get('_2fa_lembrar', false);

        $this->clear2FAPending();

        // finalizeLogin decide sozinho entre JSON e redirect conforme a
        // requisição — os dois ramos idênticos daqui eram ruído.
        $this->finalizeLogin($user, $lembrar);
    }

    /**
     * Finaliza o processo de login: cria sessão e redireciona.
     */
    protected function finalizeLogin(array $user, bool $lembrar): void {
        $db = Database::getInstance()->getConnection();

        // ── Pré-condição: sem cliente_id não há login ─────
        // Todo caminho de autenticação desemboca aqui, então é aqui que
        // a sessão meia-criada tem de ser barrada: sem clientes.id o
        // Session::getClienteId() volta 0 e o cliente "loga" numa conta
        // que nenhuma tela consegue carregar.
        $clienteId = (int) ($user['cliente_id'] ?? 0);
        if ($clienteId <= 0) {
            LogService::error('Login abortado: usuário sem perfil de cliente', [
                'usuario_id' => (int) ($user['id'] ?? 0),
            ], 'auth');

            $msg = 'Sua conta está incompleta. Fale com o suporte.';
            if (AuthHelper::isAjax()) {
                $this->json(['ok' => false, 'msg' => $msg]);
            }
            Session::flash('error', $msg);
            $this->redirect(BASE_URL . '/login');
            return;
        }

        // ── Anti session-fixation ─────────────────────────
        // Novo ID de sessão a cada login: um session ID fixado antes da
        // autenticação não pode ser reaproveitado. A regeneração mora
        // DENTRO de Session::loginCliente() — repetir aqui emitia dois
        // Set-Cookie de sessão no mesmo response.
        $cliente = ['id' => $clienteId];
        Session::loginCliente($user, $cliente, $lembrar);
        SecurityHelper::clearRateLimit('login_' . md5($user['email']));

        // ── Estado de verificação na sessão ───────────────
        // Lido do BANCO, não do array recebido. Este é o valor que o
        // guard de bootstrap (enforceEmailVerificado) consulta a cada
        // request: se vier defasado, o cliente é deslogado no primeiro
        // clique e o login parece "não funcionar".
        $stmtVer = $db->prepare("SELECT email_verificado FROM usuarios WHERE id = ? LIMIT 1");
        $stmtVer->execute([(int) $user['id']]);
        Session::set('email_verificado', (bool) $stmtVer->fetchColumn());

        // Sessão recém-validada: evita que validateActiveSession() dispare
        // já no primeiro request pós-login (o contador nasce em zero).
        Session::set('_session_verified_at', time());

        // Trilha de último acesso (coluna existia e nunca era escrita).
        $db->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?")
           ->execute([(int) $user['id']]);

        if ($lembrar) {
            // Cria sessão persistente com cookie (30 dias)
            $this->tokenService->createRememberToken($user['id']);


            // [LOG] audit: sessão de 30 dias criada. Se a conta for
            // comprometida depois, esta linha explica por que o acesso
            // persistiu.
            LogService::audit('Sessão persistente criada (lembrar-me)', [
                'usuario_id' => (int) $user['id'],
            ]);
        } else {
            // Registra sessão de auditoria sem cookie (24h)
            $this->registerAuditSession($user['id'], $db);

            // ── Modal "continuar conectado aqui?" ─────────────
            // Só quando o usuário NÃO marcou lembrar-me E o dispositivo
            // já é reconhecido (2+ logins de sucesso anteriores com o
            // mesmo User-Agent + faixa de IP) — evita perguntar de novo
            // para quem decidiu não confiar nesta máquina pela primeira
            // vez. Não decide nada agora: só sinaliza para a próxima
            // página exibir a modal (login responde via JSON/redirect,
            // não renderiza HTML aqui).
            try {
                $emailHash = hash('sha256', mb_strtolower(trim($user['email'])));
                $reconhecido = (new DeviceRecognitionService())
                    ->isDispositivoReconhecido((int)$user['id'], $emailHash);

                if ($reconhecido) {
                    Session::set('_mostrar_modal_lembrar', true);
                }
            } catch (\Throwable $e) {
                // Nunca bloqueia o login por falha nesta heurística.
                // ANTES: error_log('[AuthController] DeviceRecognitionService: ' ...)
                // [LOG] warning (não error): é heurística de UX, não bloqueia o login.
                LogService::exception($e, 'warning', 'auth', [
                    'usuario_id' => (int) $user['id'],
                    'acao'       => 'device_recognition',
                ]);
            }
        }

        // ── Alerta de novo dispositivo ────────────────────
        // Nunca bloqueia o login: best-effort em try/catch.
        try {
            $this->alertNewDevice($db, $user);
        } catch (\Throwable $e) {
            // ANTES: error_log('[AuthController] alertNewDevice: ' ...)
            // [LOG] error: o alerta de novo dispositivo é um CONTROLE DE
            // SEGURANÇA. Se ele falha em silêncio, um acesso indevido deixa
            // de ser comunicado ao titular. Não é cosmético.
            LogService::exception($e, 'error', 'auth', [
                'usuario_id' => (int) $user['id'],
                'acao'       => 'alerta_novo_dispositivo',
            ]);
        }

        $redirectUrl = AuthHelper::getRedirectAfterLogin();

        // ── Enriquecimentos best-effort ───────────────────
        // Garagem e vínculo de tracking são conveniências. Se qualquer
        // uma explodir, a exceção sobe ANTES da resposta: o cliente já
        // está logado no servidor, mas o front recebe HTML de erro onde
        // esperava JSON e trata como falha de login. Isola cada uma.
        try {
            (new VeiculoService())->carregarDoCliente($clienteId);
        } catch (\Throwable $e) {
            LogService::exception($e, 'warning', 'auth', [
                'usuario_id' => (int) $user['id'],
                'acao'       => 'carregar_garagem',
            ]);
        }

        try {
            TrackingService::vincularCliente((int) $user['id']);
        } catch (\Throwable $e) {
            LogService::exception($e, 'warning', 'auth', [
                'usuario_id' => (int) $user['id'],
                'acao'       => 'vincular_tracking',
            ]);
        }

        $primeiroNome = mb_substr($user['nome'], 0, strpos($user['nome'] . ' ', ' '));

        // ── Resposta conforme o tipo de requisição ────────
        // verifyEmail() chega aqui por NAVEGAÇÃO (clique no link do
        // e-mail). Responder JSON ali despejava `{"ok":true,...}` na tela
        // em vez de levar o cliente para a conta.
        if (!AuthHelper::isAjax()) {
            Session::flash('success', 'Bem-vindo(a), ' . $primeiroNome . '!');
            $this->redirect($redirectUrl);
            return;
        }

        $this->json([
            'ok'       => true,
            'redirect' => $redirectUrl,
            'msg'      => 'Bem-vindo(a), ' . $primeiroNome . '!',
        ]);
    }
    /**
     * E-mail de alerta quando o login vem de IP E dispositivo nunca
     * vistos antes para esta conta. Usa a base do login_attempts.
     *
     * Regras anti-ruído:
     *  - Primeiro login na era da auditoria (zero sucessos anteriores)
     *    → NÃO alerta: vira a baseline. Evita disparar e-mail para os
     *    6 mil usuários existentes no primeiro login pós-deploy.
     *  - IP OU user-agent já vistos em sucesso anterior → conhecido.
     */
    private function alertNewDevice(PDO $db, array $user): void {
        $ip = @inet_pton($_SERVER['REMOTE_ADDR'] ?? '') ?: str_repeat("\0", 16);
        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $emailHash = hash('sha256', mb_strtolower(trim($user['email'])));

        // Histórico total de sucessos (exclui o registro deste login,
        // inserido segundos atrás pelo RateLimitService::register)
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE email_hash = ? AND sucesso = 1
               AND criado_em < (NOW() - INTERVAL 10 SECOND)"
        );
        $stmt->execute([$emailHash]);
        $historico = (int)$stmt->fetchColumn();

        if ($historico === 0) return; // baseline — primeiro login auditado

        // Este IP ou este dispositivo já apareceram em sucesso anterior?
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE email_hash = ? AND sucesso = 1
               AND criado_em < (NOW() - INTERVAL 10 SECOND)
               AND (ip = ? OR user_agent = ?)"
        );
        $stmt->execute([$emailHash, $ip, $ua]);
        $conhecido = (int)$stmt->fetchColumn() > 0;

        if ($conhecido) return;

        $dispositivo = SessionManager::parseUserAgent($ua);

        if (class_exists('MailHelper') && method_exists('MailHelper', 'sendNewDeviceAlert')) {
            MailHelper::sendNewDeviceAlert(
                $user['email'],
                $user['nome'],
                $dispositivo,
                $_SERVER['REMOTE_ADDR'] ?? ''
            );
        }

        error_log(sprintf(
            '[SECURITY] Novo dispositivo no login — usuário %d (%s): %s / %s',
            $user['id'], $user['email'], $dispositivo, $_SERVER['REMOTE_ADDR'] ?? ''
        ));
    }

    /**
     * Registra sessão de auditoria sem cookie (login sem "lembrar").
     * Expira em 24h ou quando o usuário fizer logout.
     */
    private function registerAuditSession(int $userId, PDO $db): void {
        $ip     = $_SERVER['REMOTE_ADDR']     ?? null;
        $ua     = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $device = SessionManager::parseUserAgent($ua ?? '');

        $token = hash('sha256', session_id() . $userId . time());

        $db->prepare(
            "INSERT INTO sessoes_persistentes
            (usuario_id, token, ip, user_agent, nome_dispositivo,
            ultima_atividade, expira_em)
            VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))"
        )->execute([$userId, $token, $ip, $ua, $device]);

        // Salva o token e o ID da linha na sessão PHP. O ID permite que
        // a revogação remota (painel) force logout a cada request.
        Session::set('_audit_session_token',   $token);
        Session::set('_sessao_persistente_id', (int)$db->lastInsertId());
    }

    /**
     * GET /sessao/verificar-modal-lembrar
     * Chamado pelo JS do layout principal (fora do checkout) ao
     * carregar qualquer página pós-login. Consome a flag — só retorna
     * true UMA vez por login, nunca mais até o próximo login sem
     * "lembrar-me".
     */
    public function verificarModalLembrar(): void {
        $mostrar = (bool)Session::get('_mostrar_modal_lembrar', false);
        Session::remove('_mostrar_modal_lembrar');

        $this->json(['ok' => true, 'mostrar' => $mostrar]);
    }

    /**
     * POST /sessao/confirmar-lembrar
     * Resposta "Sim, continuar conectado aqui" da modal pós-login.
     * Exige senha — criar uma sessão persistente de longa duração numa
     * interação separada do login merece a mesma confirmação que o
     * checkbox teria exigido no momento certo (reduz a janela de
     * alguém com acesso temporário à sessão já aberta criar uma sessão
     * persistente sem ser o titular da conta).
     */
    public function confirmarLembrar(): void {
        if (!Session::isClienteLogado()) {
            $this->json(['ok' => false, 'msg' => 'Sessão expirada.']);
        }

        $senha  = $_POST['senha'] ?? '';
        $userId = (int)Session::get('usuario_id');

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT senha_hash FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($senha, $hash)) {
            // [LOG] Alguém COM a sessão aberta erra a senha ao tentar criar
            // sessão persistente. Cheira a acesso oportunista (máquina
            // deixada logada). Warning merecido.
            LogService::warning('Senha incorreta ao confirmar sessão persistente', [
                'usuario_id' => $userId,
            ], 'auth');
            $this->json(['ok' => false, 'msg' => 'Senha incorreta.']);
        }

        // IMPORTANTE: capturar o ID da sessão de auditoria ANTES de
        // chamar createRememberToken() — esse método sobrescreve
        // _sessao_persistente_id na sessão com o ID da nova linha de 30
        // dias. Se lêssemos depois, apagaríamos a sessão errada (a
        // própria recém-criada, não a de 24h).
        $auditId = (int)Session::get('_sessao_persistente_id', 0);

        // Mesma criação de sessão persistente que o checkbox "lembrar-me"
        // já dispara no login — reaproveita o método existente para não
        // duplicar a lógica de cookie/token/família.
        $this->tokenService->createRememberToken($userId);

        LogService::audit('Sessão persistente confirmada via modal', [
            'usuario_id' => $userId,
        ]);

        // Remove a sessão de auditoria de 24h criada no login (sem
        // lembrar-me) — senão ela fica órfã no banco, duplicando a
        // sessão deste mesmo dispositivo na lista de sessões ativas.
        //
        // Ordem proposital: cria a de 30 dias PRIMEIRO, depois apaga a
        // de 24h. Se o delete falhar, o cliente fica com a sessão que
        // pediu (30 dias) e sobra só a de 24h, que expira sozinha — em
        // vez do risco inverso de ficar sem nenhuma sessão.
        //
        // Filtra por usuario_id também, por segurança (IDOR).
        if ($auditId > 0) {
            $db->prepare(
                "DELETE FROM sessoes_persistentes WHERE id = ? AND usuario_id = ?"
            )->execute([$auditId, $userId]);

            // O token de auditoria não vale mais — a sessão atual agora é
            // identificada pelo cookie ec_remember (criado acima).
            Session::remove('_audit_session_token');
        }

        $this->json(['ok' => true, 'msg' => 'Pronto! Você vai continuar conectado aqui.']);
    }

    private function getAvatar(PDO $db, int $userId): ?string {
        $stmt = $db->prepare(
            "SELECT avatar FROM clientes WHERE usuario_id = ? LIMIT 1"
        );
        $stmt->execute([$userId]);
        $avatar = $stmt->fetchColumn();
        return $avatar ? UPLOAD_URL . '/avatars/' . $avatar : null;
    }

    private function maskEmail(string $email): string {
        [$local, $domain] = explode('@', $email);
        $visible = mb_substr($local, 0, 2);
        $stars   = str_repeat('*', max(2, mb_strlen($local) - 2));
        return $visible . $stars . '@' . $domain;
    }

    // ── Logout ────────────────────────────────────────────────

    public function logout(): void {
        $userId = Session::get('usuario_id');

        if ($userId) {
            $db = Database::getInstance()->getConnection();

            if (!empty($_COOKIE['ec_remember'])) {
                // Remove APENAS a sessão deste dispositivo.
                //
                // O cookie é "{familia}:{token}" — hashear a string
                // inteira nunca batia com nenhuma coluna, então o logout
                // deixava a linha viva no banco: ela seguia listada em
                // "sessões ativas" e o cookie continuava resgatável até
                // expirar. A família é o identificador estável do
                // dispositivo, e é por ela que se apaga.
                $partes  = explode(':', (string) $_COOKIE['ec_remember'], 2);
                $familia = $partes[0] ?? '';

                if ($familia !== '') {
                    $db->prepare(
                        "DELETE FROM sessoes_persistentes
                        WHERE usuario_id = ? AND token_familia = ?"
                    )->execute([$userId, $familia]);
                }

                // Apaga o cookie
                TokenService::clearRememberCookie();

            } else {
                // Remove APENAS a sessão de auditoria atual
                $auditToken = Session::get('_audit_session_token');
                if ($auditToken) {
                    $db->prepare(
                        "DELETE FROM sessoes_persistentes
                        WHERE usuario_id = ? AND token = ?"
                    )->execute([$userId, $auditToken]);
                }
            }

            LogService::audit('Logout', [
                'usuario_id' => (int) $userId,
                'persistente'=> !empty($_COOKIE['ec_remember']),
            ]);
        }

        $svc = new VeiculoService();
        $svc->limparSessao();

        Session::logoutCliente();
        Session::flash('info', 'Você saiu da sua contas.');
        $this->redirect(BASE_URL . '/login');
    }

    // ── Formulário de cadastro ────────────────────────────────

    public function registerForm(): void {
        if (Session::isClienteLogado()) {
            $this->redirect(BASE_URL . '/minha-conta');
        }

        $_GET['origem'] = urldecode($_GET['origem'] ?? '');
        // Pré-preenche se veio do login
        $origem = SecurityHelper::sanitizeString($_GET['origem'] ?? '');
        $email  = filter_var($origem, FILTER_VALIDATE_EMAIL) ? $origem : '';
        $cpf    = !$email ? $origem : '';

        SeoHelper::setTitle('Criar conta');
        $this->render('auth/register', [
            'email_pre' => $email,
            'cpf_pre'   => $cpf,
        ], 'minimal');
    }

    // ── Processar cadastro ────────────────────────────────────

    public function register(): void {
        $this->verifyCsrf();

        $nome    = SecurityHelper::sanitizeString($_POST['nome']    ?? '');
        $email   = SecurityHelper::sanitizeEmail( $_POST['email']   ?? '');
        $cpf     = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
        $senha   = $_POST['senha']    ?? '';
        $confirmar = $_POST['confirmar_senha'] ?? '';
        $newsletter = isset($_POST['newsletter']);

        $errors = [];
        if (mb_strlen($nome) < 3)               $errors[] = 'Nome muito curto.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail inválido.';
        if (!SecurityHelper::validatePassword($senha))  $errors[] = 'Senha fraca. Use 8+ caracteres, maiúsculas, minúsculas e números.';
        if ($senha !== $confirmar)               $errors[] = 'As senhas não conferem.';
        if (!empty($cpf) && !SecurityHelper::validateCpf($cpf)) $errors[] = 'CPF inválido.';

        if ($errors) {
            $this->json(['ok' => false, 'errors' => $errors]);
        }

        $db = Database::getInstance()->getConnection();

        // Verifica e-mail duplicado
        $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            $this->json(['ok' => false, 'msg' => 'Este e-mail já está cadastrado.',
                         'redirect' => BASE_URL . '/login?login=' . urlencode($email)]);
        }

        // Verifica CPF duplicado
        if (!empty($cpf)) {
            $stmt = $db->prepare("SELECT id FROM clientes WHERE cpf = ? LIMIT 1");
            $stmt->execute([$cpf]);
            if ($stmt->fetchColumn()) {
                $this->json(['ok' => false, 'msg' => 'Este CPF já está cadastrado.']);
            }
        }

        try {
            $db->beginTransaction();

            $tipo = 'cliente';

            // Cria usuário (email_verificado = 0)
            $senhaHash = password_hash($senha, PASSWORD_ARGON2ID);
            $db->prepare(
                "INSERT INTO usuarios (nome, email, senha_hash, tipo, email_verificado, ativo, criado_em)
                 VALUES (?, ?, ?, ?, 0, 1, NOW())"
            )->execute([$nome, $email, $senhaHash, $tipo]);
            $userId = (int)$db->lastInsertId();

            // Cria cliente
            $db->prepare(
                "INSERT INTO clientes (usuario_id, cpf, newsletter, criado_em)
                 VALUES (?, ?, ?, NOW())"
            )->execute([
                $userId,
                $cpf ?: null,
                $newsletter ? 1 : 0,
            ]);

            // Gera código de verificação (6 dígitos numéricos)
            $code     = SecurityHelper::generateNumericCode(6);
            $expiraEm = date('Y-m-d H:i:s', time() + 86400); // 24h
            // Vincula o token ao navegador que o solicitou
            $origemHash = $this->emitirNonceOrigem();
    
            $db->prepare(
                "INSERT INTO tokens_verificacao
                    (usuario_id, token, tipo, expira_em, origem_hash)
                VALUES (?, ?, 'email_verify', ?, ?)"
            )->execute([$userId, $code, $expiraEm, $origemHash]);

            $db->commit();

            // Envia e-mail de verificação
            MailHelper::sendVerificationEmail($email, $nome, $code);

            $stmt_c = $db->prepare("SELECT id FROM clientes WHERE usuario_id = ? LIMIT 1");
            $stmt_c->execute([$userId]);
            $c_id = $stmt_c->fetchColumn();
            if ($c_id) {
                // No método register(), dentro da transaction, após criar o cliente:                
                $db->prepare(
                    "INSERT INTO wishlist (cliente_id, nome, padrao, criado_em)
                    VALUES (?, 'Meus favoritos', 1, NOW())"
                )->execute([$c_id]);
            }
            
            // [LOG] audit: nascimento da conta. Base para investigar fraude de
            // cadastro em massa (o dashboard agrupa e conta ocorrências).
            LogService::audit('Conta criada', [
                'usuario_id' => (int) $userId,
                'com_cpf'    => !empty($cpf),
            ]);

            $this->json([
                'ok'         => true,
                'verificacao'=> true,
                'email_mask' => $this->maskEmail($email),
                'msg'        => 'Conta criada! Verifique seu e-mail para ativar.',
            ]);

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            LogService::error('Erro no cadastro: ' . $e->getMessage());
            $this->json(['ok' => false, 'msg' => 'Erro ao criar conta. Tente novamente.', 'error'=>$e->getMessage(), 'trace'=>$e->getTraceAsString(), 'line'=>$e->getLine()]);
        }
    }

    private function validateRegister(array $data): array {
        $errors = [];

        if (mb_strlen($data['nome']) < 3) {
            $errors[] = 'Nome deve ter pelo menos 3 caracteres.';
        }
        if (!SecurityHelper::validateEmail($data['email'])) {
            $errors[] = 'E-mail inválido.';
        }
        if ($this->userModel->emailExists($data['email'])) {
            $errors[] = 'Este e-mail já está cadastrado.';
        }
        if (!SecurityHelper::validatePassword($data['senha'])) {
            $errors[] = 'Senha fraca. Use ao menos 8 caracteres, letras maiúsculas, minúsculas e números.';
        }
        if ($data['senha'] !== $data['confirmar']) {
            $errors[] = 'As senhas não conferem.';
        }
        if (!empty($data['cpf']) && !SecurityHelper::validateCpf($data['cpf'])) {
            $errors[] = 'CPF inválido.';
        }
        if (!empty($data['cpf']) && $this->userModel->cpfExists($data['cpf'])) {
            $errors[] = 'Este CPF já está cadastrado.';
        }

        return $errors;
    }

    // ── Verificação de e-mail ─────────────────────────────────

    public function verifyEmail(string $token): void {
        $db = Database::getInstance()->getConnection();
 
        // ── Freio de enumeração ───────────────────────────
        // O token desta rota é um código de 6 dígitos e a busca abaixo
        // NÃO tem escopo de usuário: sem freio, varrer 000000–999999
        // consome/valida verificações de terceiros. 10 tentativas / 10min.
        $ipKey = md5($_SERVER['REMOTE_ADDR'] ?? '');
        if (SecurityHelper::rateLimitExceeded('verify_email_' . $ipKey, 10, 600)) {
            LogService::warning('Rate limit em verifyEmail (possível varredura de tokens)', [
                'token_hash' => hash('sha256', $token),
            ], 'auth');

            SeoHelper::setTitle('Link inválido');
            $this->render('auth/verify-invalid', ['jaUsado' => false], 'minimal');
            return;
        }

        // origem_hash entra no SELECT — é o que decide o auto-login
        $stmt = $db->prepare(
            "SELECT t.id, t.usuario_id, t.expira_em, t.origem_hash
             FROM tokens_verificacao t
             WHERE t.token = ?
               AND t.tipo  = 'email_verify'
               AND t.usado = 0
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
 
        if (!$row) {
            // Token inexistente OU já consumido. Distinguir importa:
            // scanners de link (Outlook Safe Links, antivírus) abrem
            // o link antes do usuário — dizer "inválido" a quem já
            // está verificado gera pânico e ticket de suporte.
            $usado = $db->prepare(
                "SELECT 1 FROM tokens_verificacao
                 WHERE token = ? AND tipo = 'email_verify' AND usado = 1
                 LIMIT 1"
            );
            $usado->execute([$token]);
 
            $jaUsado = (bool)$usado->fetchColumn();
            SeoHelper::setTitle($jaUsado ? 'E-mail já verificado' : 'Link inválido');
            $this->render('auth/verify-invalid', ['jaUsado' => $jaUsado], 'minimal');
            return;
        }
 
        if (strtotime($row['expira_em']) < time()) {
            SeoHelper::setTitle('Link expirado');
            $this->render('auth/verify-expired', [], 'minimal');
            return;
        }
 
        // ── Ativa o e-mail SEMPRE ─────────────────────────
        // Independente do navegador: um link legitimamente aberto
        // em outro dispositivo não pode impedir a ativação da conta.
        $db->prepare("UPDATE usuarios SET email_verificado = 1 WHERE id = ?")
           ->execute([$row['usuario_id']]);
        
           (new BlingContatoService())->enfileirarPorUsuario((int)$row['usuario_id']);
 
        $db->prepare("UPDATE tokens_verificacao SET usado = 1 WHERE id = ?")
           ->execute([$row['id']]);
 
        // ── Auto-login: SÓ no navegador de origem ─────────
        // Prova de posse do navegador = cookie cujo hash bate com
        // o gravado na criação do token. Comparação em tempo
        // constante (hash_equals) — o hash não é segredo, mas o
        // hábito evita vazar por timing em código copiado.
        //
        // FAIL-CLOSED: token legado (origem_hash NULL) ou cookie
        // ausente/divergente → verifica o e-mail e pede login.
        $mesmoNavegador = false;
        $cookie = (string)($_COOKIE[self::COOKIE_ORIGEM] ?? '');
 
        if (!empty($row['origem_hash']) && $cookie !== '') {
            $mesmoNavegador = hash_equals(
                (string)$row['origem_hash'],
                hash('sha256', $cookie)
            );
        }
 
        $this->limparNonceOrigem(); // single-use, independente do resultado
 
        if (!$mesmoNavegador) {
            Session::flash('success',
                'E-mail verificado com sucesso! Faça login para acessar sua conta.');
            $this->redirect(BASE_URL . '/login');
            return;
        }
 
        // Mesmo navegador → login automático (finalizeLogin já
        // redireciona de verdade em navegação, pelo patch anterior)
        $stmtUser = $db->prepare(
            "SELECT u.*, c.id AS cliente_id
             FROM usuarios u
             JOIN clientes c ON c.usuario_id = u.id
             WHERE u.id = ? LIMIT 1"
        );
        $stmtUser->execute([$row['usuario_id']]);
        $user = $stmtUser->fetch();
 
        if ($user) {
            $this->finalizeLogin($user, false);
            return;
        }
 
        Session::flash('success', 'E-mail verificado! Faça login para continuar.');
        $this->redirect(BASE_URL . '/login');
    }

    public function resendVerification(): void {
        $this->verifyCsrf();

        $login = SecurityHelper::sanitizeString($_POST['login'] ?? '');
        if (empty($login)) {
            $this->json(['ok' => false, 'msg' => 'Informe o e-mail.']);
        }

        $rateKey = 'resend_verify_' . md5($login);
        if (SecurityHelper::rateLimitExceeded($rateKey, 3, 600)) {
            $this->json(['ok' => false, 'msg' => 'Aguarde antes de solicitar outro código.']);
        }

        $db   = Database::getInstance()->getConnection();
        $user = $this->findUserByLogin($db, $login);

        if (!$user || $user['email_verificado']) {
            $this->json(['ok' => true, 'msg' => 'Se houver pendência, o código será reenviado.']);
        }

        $code     = SecurityHelper::generateNumericCode(6);
        $expiraEm = date('Y-m-d H:i:s', time() + 86400);

        $db->prepare(
            "UPDATE tokens_verificacao SET usado = 1
             WHERE usuario_id = ? AND tipo = 'email_verify' AND usado = 0"
        )->execute([$user['id']]);

        // Vincula o token ao navegador que o solicitou
        $origemHash = $this->emitirNonceOrigem();
 
        $db->prepare(
            "INSERT INTO tokens_verificacao
                (usuario_id, token, tipo, expira_em, origem_hash)
             VALUES (?, ?, 'email_verify', ?, ?)"
        )->execute([$user['id'], $code, $expiraEm, $origemHash]);

        MailHelper::sendVerificationEmail($user['email'], $user['nome'], $code);

        $this->json(['ok' => true, 'msg' => 'Novo código enviado para ' . $this->maskEmail($user['email'])]);

        $rateKey = 'login_' . md5($login);
        SecurityHelper::clearRateLimit($rateKey);

        $rateKeyCod = 'login_code_val_' . md5($login);
        SecurityHelper::clearRateLimit($rateKeyCod);
    }

    // ── Esqueci a senha ───────────────────────────────────────

    public function forgotForm(): void {
        SeoHelper::setTitle('Recuperar senha');
        $this->render('auth/forgot-password', [], 'minimal');
    }

    public function forgot(): void {
        $this->verifyCsrf();

        $email = SecurityHelper::sanitizeEmail($_POST['email'] ?? '');

        // Rate limit: evita spam de e-mails de recuperação
        if (SecurityHelper::rateLimitExceeded('forgot_' . md5($email), 3, 900)) {
            // [LOG] Flood de e-mails de recuperação = tentativa de assédio ao
            // titular ou de descoberta de contas.
            LogService::warning('Rate limit em recuperação de senha', [
                'login_hash' => $this->logId($email),
            ], 'auth');

            if (AuthHelper::isAjax()) {
                $this->json(['ok' => true, 'msg' => 'Se o e-mail existir, você receberá as instruções.']);
            }
            Session::flash('info', 'Se o e-mail existir em nossa base, você receberá as instruções.');
            $this->redirect(BASE_URL . '/recuperar-senha');
            return;
        }

        if (!SecurityHelper::validateEmail($email)) {
            if (AuthHelper::isAjax()) {
                $this->json(['ok' => false, 'msg' => 'E-mail inválido.']);
            }
            Session::flash('error', 'Informe um e-mail válido.');
            $this->redirect(BASE_URL . '/recuperar-senha');
            return;
        }

        $user = $this->userModel->findByEmail($email);

        // Resposta genérica independente de existir ou não (evita user enumeration)
        if ($user && $user['tipo'] === 'cliente' && $user['ativo']) {
            try {
                $token = $this->tokenService->createPasswordResetToken($user['id']);
                MailHelper::sendPasswordReset($user['email'], $user['nome'], $token);

                // [LOG] NUNCA logue o $token — quem lê o log redefine a senha.
                LogService::audit('Recuperação de senha solicitada', [
                    'usuario_id' => (int) $user['id'],
                ]);

            } catch (\Throwable $e) {
                // [LOG] error: o cliente não recebe o link e fica sem acesso.
                LogService::exception($e, 'error', 'auth', [
                    'usuario_id' => (int) $user['id'],
                    'acao'       => 'envio_reset_senha',
                ]);
            }
        }

        $msg = 'Se o e-mail estiver cadastrado, você receberá as instruções em breve.';

        if (AuthHelper::isAjax()) {
            $this->json(['ok' => true, 'msg' => $msg]);
        }
        Session::flash('success', $msg);
        $this->redirect(BASE_URL . '/recuperar-senha');
    }

    // ── Redefinir senha ───────────────────────────────────────

    public function resetForm(string $token): void {
        if (!$this->tokenService->passwordResetTokenExists($token)) {
            Session::flash('error', 'Link inválido ou expirado. Solicite um novo.');
            $this->redirect(BASE_URL . '/recuperar-senha');
            return;
        }
        SeoHelper::setTitle('Nova senha');
        $this->render('auth/reset-password', ['token' => $token], 'minimal');
    }

    public function reset(): void {
        $this->verifyCsrf();

        $token  = $_POST['token'] ?? '';
        $senha  = $_POST['senha'] ?? '';
        $conf   = $_POST['confirmar_senha'] ?? '';

        $userId = $this->tokenService->consumePasswordResetToken($token);

        if (!$userId) {
            // [LOG] Token de reset inválido/expirado/já usado. Repetido =
            // alguém tentando adivinhar ou reusar link de recuperação.
            LogService::warning('Token de reset de senha inválido ou expirado', [], 'auth');
            
            if (AuthHelper::isAjax()) {
                $this->json(['ok' => false, 'msg' => 'Link inválido ou expirado.']);
            }
            Session::flash('error', 'Link inválido ou expirado. Solicite um novo.');
            $this->redirect(BASE_URL . '/recuperar-senha');
            return;
        }

        if (!SecurityHelper::validatePassword($senha)) {
            if (AuthHelper::isAjax()) {
                $this->json(['ok' => false, 'msg' => 'Senha fraca. Use ao menos 8 caracteres, maiúsculas, minúsculas e números.']);
            }
            Session::flash('error', 'Senha fraca. Use ao menos 8 caracteres, maiúsculas, minúsculas e números.');
            $this->redirect(BASE_URL . '/redefinir-senha/' . $token);
            return;
        }

        if ($senha !== $conf) {
            if (AuthHelper::isAjax()) {
                $this->json(['ok' => false, 'msg' => 'As senhas não conferem.']);
            }
            Session::flash('error', 'As senhas não conferem.');
            $this->redirect(BASE_URL . '/redefinir-senha/' . $token);
            return;
        }

        $this->userModel->updatePassword($userId, $senha);

        // Revoga TODAS as sessões persistentes (todos os dispositivos).
        // Se a conta foi comprometida, o atacante perde o acesso agora —
        // deleteRememberToken só removia a sessão do dispositivo atual,
        // que num reset de senha geralmente nem é o do dono.
        $this->tokenService->revokeAllSessions($userId);

        SecurityHelper::regenerateCsrf();

        // [LOG] audit — evento de alto valor forense.
        LogService::audit('Senha redefinida via link de recuperação', [
            'usuario_id'        => (int) $userId,
            'sessoes_revogadas' => true,
        ]);

        if (AuthHelper::isAjax()) {
            $this->json(['ok' => true, 'redirect' => BASE_URL . '/login']);
        }
        Session::flash('success', 'Senha redefinida com sucesso! Faça login com sua nova senha.');
        $this->redirect(BASE_URL . '/login');
    }

    // ── Helpers privados ──────────────────────────────────────

    private function user2faEnabled(int $userId): bool {
        // Por padrão desabilitado — implementar coluna `2fa_ativo` em usuarios se quiser por usuário
        return false;
    }

    /**
     * Mescla o carrinho do visitante ao cliente após login.
     */
    private function mergeGuestCart(int $clienteId): void {
        $carrinhoId = Session::getCarrinhoId();
        if (!$carrinhoId) return;

        $db = Database::getInstance()->getConnection();

        // Verifica se o carrinho anônimo existe e não pertence a ninguém
        $stmt = $db->prepare(
            "SELECT id FROM carrinhos WHERE id = ? AND cliente_id IS NULL LIMIT 1"
        );
        $stmt->execute([$carrinhoId]);
        if (!$stmt->fetch()) return;

        // Verifica se o cliente já tem carrinho ativo
        $stmt = $db->prepare(
            "SELECT id FROM carrinhos WHERE cliente_id = ? ORDER BY atualizado_em DESC LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        $clienteCarrinho = $stmt->fetchColumn();

        if ($clienteCarrinho) {
            // Move itens do carrinho anônimo para o carrinho do cliente
            // (em caso de conflito de produto, soma quantidades)
            $db->prepare(
                "INSERT INTO carrinho_itens (carrinho_id, produto_id, estoque_id, quantidade, preco_unitario, opcoes_selecionadas)
                 SELECT ?, produto_id, estoque_id, quantidade, preco_unitario, opcoes_selecionadas
                 FROM carrinho_itens WHERE carrinho_id = ?
                 ON DUPLICATE KEY UPDATE quantidade = carrinho_itens.quantidade + VALUES(quantidade)"
            )->execute([$clienteCarrinho, $carrinhoId]);

            $db->prepare("DELETE FROM carrinhos WHERE id = ?")->execute([$carrinhoId]);
            Session::setCarrinhoId($clienteCarrinho);
        } else {
            // Atribui o carrinho anônimo ao cliente
            $db->prepare(
                "UPDATE carrinhos SET cliente_id = ? WHERE id = ?"
            )->execute([$clienteId, $carrinhoId]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────

    public function findUserByLogin(PDO $db, string $valor): ?array {
        $isCpf = preg_match('/^\d{11}$/', preg_replace('/\D/', '', $valor));

        if ($isCpf) {
            $cpf  = preg_replace('/\D/', '', $valor);
            $stmt = $db->prepare(
                "SELECT u.*, c.id AS cliente_id, c.tray_id
                 FROM usuarios u
                 JOIN clientes c ON c.usuario_id = u.id
                 WHERE c.cpf = ? AND u.deleted_at IS NULL
                 LIMIT 1"
            );
            $stmt->execute([$cpf]);
        } else {
            $stmt = $db->prepare(
                "SELECT u.*, c.id AS cliente_id, c.tray_id
                 FROM usuarios u
                 JOIN clientes c ON c.usuario_id = u.id
                 WHERE u.email = ? AND u.deleted_at IS NULL
                 LIMIT 1"
            );
            $stmt->execute([$valor]);
        }

        return $stmt->fetch() ?: null;
    }

    private function emitirNonceOrigem(int $ttlSegundos = 86400): string {
        $nonce = bin2hex(random_bytes(32)); // 256 bits
 
        setcookie(self::COOKIE_ORIGEM, $nonce, [
            'expires'  => time() + $ttlSegundos, // = TTL do token
            'path'     => '/',
            'secure'   => SecurityHelper::isHttps(),      // proxy-aware (Cloudflare)
            'httponly' => true,                  // XSS não lê
            'samesite' => 'Lax',                 // ver nota acima
        ]);
 
        return hash('sha256', $nonce);
    }

    /** Consome o cookie de origem (single-use). */
    private function limparNonceOrigem(): void {
        setcookie(self::COOKIE_ORIGEM, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => SecurityHelper::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}