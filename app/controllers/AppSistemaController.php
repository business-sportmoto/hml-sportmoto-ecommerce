<?php
// app/controllers/AppSistemaController.php
// Endpoints de sistema: configuração remota e ciclo de vida do dispositivo.
// São os únicos que existem antes de o app ter qualquer token.

class AppSistemaController extends AppApiController
{
    /**
     * GET /api/app/v1/config
     *
     * Primeira chamada do app em todo cold start. Traz gate de versão, feature
     * flags e os tokens de cor — assim uma mudança de identidade visual ou o
     * desligamento de um recurso não exigem nova submissão às lojas.
     */
    public function config(): void
    {
        $this->bootAberto();

        $this->ok([
            'versao_minima'      => (string)ConfigHelper::get('app_versao_minima', '1.0.0'),
            'versao_recomendada' => (string)ConfigHelper::get('app_versao_recomendada', '1.0.0'),
            'url_loja'           => rtrim(BASE_URL, '/'),
            'url_termos'         => rtrim(BASE_URL, '/') . '/termos-de-uso',
            'url_privacidade'    => rtrim(BASE_URL, '/') . '/politica-de-privacidade',
            'moeda'              => ['codigo' => CURRENCY_CODE, 'simbolo' => CURRENCY_SYMBOL],
            'features' => [
                'clips'         => (bool)ConfigHelper::get('app_feature_clips', true),
                'garagem'       => (bool)ConfigHelper::get('app_feature_garagem', true),
                'pix'           => (bool)ConfigHelper::get('app_feature_pix', true),
                'boleto'        => (bool)ConfigHelper::get('app_feature_boleto', true),
                'cartao'        => (bool)ConfigHelper::get('app_feature_cartao', true),
                'login_google'  => GOOGLE_CLIENT_ID !== '',
                'login_apple'   => (bool)ConfigHelper::get('app_feature_apple', false),
            ],
            // Espelha assets/css/main.css:1-48. O script sync-tokens.mjs do app
            // gera os tokens em build time; isto permite ajuste sem novo build.
            'cores' => [
                'primary'     => '#398de6',
                'primary_d'   => '#125ec1',
                'primary_t'   => '#aad3ff',
                'primary_l'   => '#e8ebfd',
                'dark'        => '#1a1a2e',
                'text'        => '#2d2d2d',
                'text_muted'  => '#888888',
                'border'      => '#e8e8e8',
                'bg'          => '#f7f8fa',
                'surface'     => '#ffffff',
                'success'     => '#06d6a0',
                'green'       => '#00a650',
                'warning'     => '#f4a261',
                'info'        => '#378add',
                'favorited'   => '#dd3737',
            ],
            'manutencao' => [
                'ativa'    => (bool)ConfigHelper::get('manutencao', false),
                'mensagem' => (string)ConfigHelper::get('manutencao_mensagem', ''),
            ],
        ]);
    }

    /**
     * POST /api/app/v1/dispositivos/registrar
     *
     * Idempotente: o app chama em todo cold start sem token. Registra ou
     * atualiza a instalação e devolve um par access/refresh anônimo — a partir
     * daí o carrinho de visitante já funciona, sem cookie nenhum.
     *
     * Corpo: { device_uuid, plataforma, app_versao?, build_numero?,
     *          os_versao?, modelo?, locale? }
     */
    public function registrarDispositivo(): void
    {
        $this->bootAberto();
        $corpo = $this->exigirCampos(['device_uuid', 'plataforma']);

        $servico = new AppDeviceService();
        $device  = $servico->registrar([
            'device_uuid'  => $corpo['device_uuid'],
            'plataforma'   => $corpo['plataforma'],
            'app_versao'   => $corpo['app_versao']   ?? null,
            'build_numero' => $corpo['build_numero'] ?? null,
            'os_versao'    => $corpo['os_versao']    ?? null,
            'modelo'       => $corpo['modelo']       ?? null,
            'locale'       => $corpo['locale']       ?? null,
            'ip'           => $this->ipReal(),
        ]);

        if (!$device) {
            $this->falha(422, 'dados_invalidos',
                'device_uuid inválido ou plataforma não suportada (use android, ios ou web).');
        }

        if (!empty($device['bloqueado'])) {
            $this->falha(403, 'dispositivo_bloqueado', 'Este dispositivo está bloqueado.');
        }

        // Um registro novo encerra as credenciais anteriores desta instalação:
        // reinstalar o app não deve deixar tokens órfãos válidos por 90 dias.
        $tokens = new AppTokenService();
        $tokens->revogarDispositivo((int)$device['id'], 'novo_registro');

        $par = $tokens->emitirPar(
            (int)$device['id'],
            $device['usuario_id'] !== null ? (int)$device['usuario_id'] : null,
            $device['cliente_id'] !== null ? (int)$device['cliente_id'] : null
        );

        $this->ok([
            'access_token'  => $par['access_token'],
            'refresh_token' => $par['refresh_token'],
            'expira_em'     => $par['expira_em'],
            'expira_em_s'   => $par['expira_em_s'],
            'anonimo'       => empty($device['cliente_id']),
            'dispositivo'   => [
                'device_uuid' => $device['device_uuid'],
                'plataforma'  => $device['plataforma'],
            ],
        ], 201);
    }

    /**
     * PATCH /api/app/v1/dispositivos
     * Corpo: { push_token?, push_habilitado?, app_versao?, build_numero?,
     *          os_versao?, locale? }
     */
    public function atualizarDispositivo(): void
    {
        $this->bootPublico();
        $this->liberarSessao();

        $corpo = $this->corpo();
        if (!$corpo) {
            $this->falha(422, 'dados_invalidos', 'Nada para atualizar.');
        }

        $ok = (new AppDeviceService())->atualizar((int)$this->dispositivo['id'], $corpo);

        if (!$ok) {
            $this->falha(500, 'falha_atualizacao', 'Não foi possível atualizar o dispositivo.');
        }

        $this->ok(['atualizado' => true]);
    }

    /**
     * POST /api/app/v1/telemetria
     *
     * O app reporta o que quebrou NELE — crash de JS, tela de erro, falha ao
     * reproduzir um clip. Sem isto, a saúde do aplicativo é um ponto cego:
     * o servidor responde 200 e mesmo assim o usuário vê tela branca.
     *
     * Corpo: { nivel, mensagem, tipo?, tela?, contexto?, stack? }
     */
    public function telemetria(): void
    {
        $this->bootPublico();
        $this->liberarSessao();

        $corpo = $this->exigirCampos(['mensagem']);

        $nivel = strtolower((string)($corpo['nivel'] ?? 'error'));
        $tela  = isset($corpo['tela']) ? mb_substr((string)$corpo['tela'], 0, 80) : null;
        $tipo  = isset($corpo['tipo']) ? mb_substr((string)$corpo['tipo'], 0, 80) : null;

        // O contexto do cliente é dado não confiável: vem do aparelho. Limitamos
        // o tamanho e a profundidade antes de guardar — um payload gigante em
        // laço encheria a tabela de logs.
        $contexto = [];
        if (!empty($corpo['contexto']) && is_array($corpo['contexto'])) {
            $contexto = array_slice(
                array_map(
                    static fn($v) => is_scalar($v) ? mb_substr((string)$v, 0, 300) : '[objeto]',
                    $corpo['contexto']
                ),
                0,
                20,
                true
            );
        }

        if ($tela) { $contexto['tela'] = $tela; }
        if ($tipo) { $contexto['tipo_erro'] = $tipo; }
        if (!empty($corpo['stack'])) {
            $contexto['stack'] = mb_substr((string)$corpo['stack'], 0, 4000);
        }

        AppLog::doCliente($nivel, (string)$corpo['mensagem'], $contexto);

        $this->ok(['registrado' => true], 202);
    }

    /**
     * GET /api/app/v1/_saude  (somente com APP_DEBUG)
     *
     * Resumo de saúde do app: erros por nível, tráfego, latência e os
     * problemas mais recorrentes. Em produção este painel deve viver no admin,
     * atrás de autenticação — aqui é a versão de desenvolvimento.
     */
    public function saude(): void
    {
        if (!APP_DEBUG) {
            $this->falha(404, 'nao_encontrado', 'Rota não encontrada.');
        }

        $this->bootAberto();
        $horas = (int)$this->query('horas', 24);

        $this->ok(AppLog::saude($horas));
    }

    /**
     * GET /api/app/v1/_diagnostico  (somente com APP_DEBUG)
     *
     * Teste de aceitação da Fase 0: prova que a ponte de sessão está de pé.
     * Incrementa um contador na sessão do dispositivo — se duas chamadas
     * seguidas, sem cookie algum, devolverem 1 e depois 2, a ponte funciona e
     * o carrinho anônimo vai funcionar junto.
     */
    public function diagnostico(): void
    {
        if (!APP_DEBUG) {
            $this->falha(404, 'nao_encontrado', 'Rota não encontrada.');
        }

        $this->bootPublico();

        $contador = (int)Session::get('_diag_contador', 0) + 1;
        Session::set('_diag_contador', $contador);

        $this->ok([
            'dispositivo_id'  => (int)$this->dispositivo['id'],
            'session_id'      => session_id(),
            'sessao_persiste' => $contador > 1,
            'contador'        => $contador,
            'cliente_id'      => $this->clienteId,
            'logado'          => Session::isClienteLogado(),
            'carrinho_id'     => Session::get('carrinho_id'),
            'php'             => PHP_VERSION,
        ]);
    }
}
