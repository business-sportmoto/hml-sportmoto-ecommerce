<?php
// app/controllers/AppAuthController.php
// Ciclo de vida do token no app.
//
// Fase 0 entrega só refresh e logout — sem eles o app não sobrevive aos 60
// minutos de vida do access token, então são infraestrutura, não recurso.
// Login por senha, código por e-mail, 2FA, Google e Apple entram na Fase 2 via
// AppAuthService (que reusa AuthController::findUserByLogin, RateLimitService,
// TwoFactorService e TotpService).

class AppAuthController extends AppApiController
{
    /**
     * POST /api/app/v1/auth/login
     * Corpo: { login, senha }   — `login` aceita e-mail OU CPF, como na web.
     *
     * A resposta SEMPRE traz `estado`, e é por ele que o app decide a tela:
     *   autenticado                → entra
     *   credenciais_invalidas      → mostra erro no formulário
     *   email_pendente             → tela "confirme seu e-mail"
     *   definir_senha              → abre o fluxo web (conta migrada da Tray)
     *   verificacao_web_requerida  → WebView com reCAPTCHA
     *   bloqueado / conta_desativada
     *
     * ── Sobre o código HTTP ──────────────────────────────────────────────
     * Todos esses desfechos respondem 200 com `ok: true`.
     *
     * Parece contraintuitivo para "senha errada", mas a alternativa é pior:
     * misturar `ok: true` com 401 é autocontraditório, e usar `ok: false`
     * jogaria os dados que o app precisa (a URL do fluxo de definir senha, o
     * e-mail mascarado) para dentro do envelope de erro, que só carrega código
     * e mensagem.
     *
     * A requisição FOI processada com sucesso; o que varia é o desfecho, e ele
     * está em `estado`. `ok: false` fica reservado para erro de protocolo —
     * corpo malformado (422) e rate limit (429).
     */
    public function login(): void
    {
        $this->bootPublico();

        $corpo = $this->exigirCampos(['login', 'senha']);

        $resultado = (new AppAuthService())->login(
            $this->dispositivo,
            (string)$corpo['login'],
            (string)$corpo['senha'],
            $this->ipReal()
        );

        if ($resultado['estado'] === 'bloqueado') {
            $this->falha(429, 'bloqueado', $resultado['mensagem']);
        }

        $this->ok($resultado);
    }

    /**
     * POST /api/app/v1/auth/2fa/enviar
     * Corpo: { desafio, canal? }
     *
     * Só faz sentido para o canal de e-mail: o código TOTP já está no
     * aplicativo autenticador do usuário.
     */
    public function enviarCodigo2fa(): void
    {
        $this->bootPublico();
        $corpo = $this->exigirCampos(['desafio']);

        $r = (new AppAuthService())->enviarCodigo2FA($this->dispositivo, (string)$corpo['desafio']);

        if (empty($r['ok'])) {
            $status = ($r['codigo'] ?? '') === 'aguarde' ? 429 : 401;
            $this->falha($status, (string)$r['codigo'], (string)$r['mensagem']);
        }

        $this->ok(['enviado' => true, 'destino' => $r['destino'], 'reenvio_em' => $r['reenvio_em']]);
    }

    /**
     * POST /api/app/v1/auth/2fa/verificar
     * Corpo: { desafio, codigo }
     *
     * Aceita TOTP, código de backup e código por e-mail — o servidor descobre
     * qual é, em vez de a interface ter que perguntar.
     */
    public function verificar2fa(): void
    {
        $this->bootPublico();
        $corpo = $this->exigirCampos(['desafio', 'codigo']);

        $r = (new AppAuthService())->verificar2FA(
            $this->dispositivo,
            (string)$corpo['desafio'],
            (string)$corpo['codigo']
        );

        $this->ok($r);
    }

    /**
     * POST /api/app/v1/auth/google
     * Corpo: { id_token }
     *
     * O `id_token` vem do SDK nativo configurado com o webClientId, então o
     * `aud` é o GOOGLE_CLIENT_ID web e o GoogleAuthService valida direto.
     */
    public function google(): void
    {
        $this->bootPublico();
        $corpo = $this->exigirCampos(['id_token']);

        $r = (new AppAuthService())->loginGoogle($this->dispositivo, (string)$corpo['id_token']);

        $this->ok($r);
    }

    /**
     * POST /api/app/v1/auth/google/cadastro
     * Corpo: { id_token, cpf, telefone? }
     *
     * Segundo passo quando o Google traz alguém que ainda não tem conta: o
     * cadastro da loja exige CPF, que o Google não fornece.
     */
    public function googleCadastro(): void
    {
        $this->bootPublico();
        $corpo = $this->exigirCampos(['id_token', 'cpf']);

        $r = (new AppAuthService())->cadastrarComGoogle(
            $this->dispositivo,
            (string)$corpo['id_token'],
            ['cpf' => $corpo['cpf'], 'telefone' => $corpo['telefone'] ?? '']
        );

        $this->ok($r, $r['estado'] === 'autenticado' ? 201 : 200);
    }

    /**
     * POST /api/app/v1/auth/refresh
     * Corpo: { refresh_token }
     *
     * Rotaciona: o refresh apresentado é consumido e um par novo é emitido na
     * mesma família. Apresentar duas vezes o mesmo refresh derruba a família
     * inteira (detecção de reuso em AppTokenService::rotacionar).
     */
    public function refresh(): void
    {
        $this->bootAberto();
        $corpo = $this->exigirCampos(['refresh_token']);

        $resultado = (new AppTokenService())->rotacionar((string)$corpo['refresh_token']);

        if (!$resultado['ok']) {
            if ($resultado['erro'] === 'reuso_detectado') {
                // 401 com código próprio: o app precisa distinguir "token velho,
                // tente de novo" de "sua sessão foi invalidada, faça login".
                $this->falha(401, 'sessao_invalidada',
                    'Sua sessão foi encerrada por segurança. Entre novamente.');
            }
            $this->falha(401, 'refresh_invalido', 'Refresh token inválido ou expirado.');
        }

        $tokens = $resultado['tokens'];
        $device = (new AppDeviceService())->porId((int)$resultado['dispositivo_id']);

        $this->ok([
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expira_em'     => $tokens['expira_em'],
            'expira_em_s'   => $tokens['expira_em_s'],
            'anonimo'       => empty($device['cliente_id']),
        ]);
    }

    /**
     * POST /api/app/v1/auth/logout
     *
     * Revoga todos os tokens do dispositivo e recicla a sessão: o device volta
     * a ser anônimo com um php_session_id novo, para que o carrinho do usuário
     * que saiu não fique visível para o próximo.
     */
    public function logout(): void
    {
        $this->bootPublico();

        $dispositivoId = (int)$this->dispositivo['id'];

        // Toda a limpeza (desvincular, revogar tokens, reciclar sessão) vive no
        // AppAuthService para que app e futuros callers sigam o mesmo caminho.
        (new AppAuthService())->logout($this->dispositivo);

        // Sem token válido o app não consegue nem listar o catálogo, então já
        // devolvemos um par anônimo — evita uma ida e volta a /registrar.
        $par = (new AppTokenService())->emitirPar($dispositivoId);

        $this->ok([
            'desconectado'  => true,
            'access_token'  => $par['access_token'],
            'refresh_token' => $par['refresh_token'],
            'expira_em'     => $par['expira_em'],
            'anonimo'       => true,
        ]);
    }
}
