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

        $servicoDevice = new AppDeviceService();
        $servicoDevice->desvincularCliente($dispositivoId);

        (new AppTokenService())->revogarDispositivo($dispositivoId, 'logout');

        AppSessionBridge::reciclar($dispositivoId);

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
