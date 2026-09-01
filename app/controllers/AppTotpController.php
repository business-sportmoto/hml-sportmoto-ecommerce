<?php
// app/controllers/AppTotpController.php
//
// App autenticador (TOTP) dentro do aplicativo.
//
// Espelha CustomerSecurityController@totp*, reusando TotpService inteiro — o
// algoritmo, o segredo, os códigos de backup e a desativação por código de
// e-mail são exatamente os mesmos. Aqui só muda a casca: JSON em vez de
// formulário com CSRF, e o pipeline de dispositivo em vez de sessão web.
//
// Uma diferença de fluxo, e ela é do meio, não da implementação: na web o
// cliente lê um QR Code na tela do computador com a câmera do celular. No app,
// a tela e a câmera são o MESMO aparelho — não há como escanear. O caminho
// nativo é entregar a URI `otpauth://` ao sistema, que a repassa ao
// autenticador instalado, e oferecer o segredo copiável para quem preferir
// digitar ou configurar em outro aparelho. Por isso a resposta de `iniciar`
// traz `uri` e `segredo`, e nenhum QR.
//
// O segredo fica PENDENTE na sessão até o primeiro código ser confirmado,
// como na web: setup abandonado no meio não deixa segredo órfão no banco. A
// ponte de sessão por dispositivo faz isso funcionar sem cookie.

class AppTotpController extends AppApiController
{
    /** Onde o segredo espera a confirmação. Mesma chave da web, de propósito. */
    private const CHAVE_PENDENTE = '_totp_setup_segredo';

    /** Quantos códigos de backup por lote — o mesmo número da web. */
    private const CODIGOS_BACKUP = 8;

    /**
     * POST /api/app/v1/conta/seguranca/totp/iniciar
     *
     * Gera o segredo e devolve o que o aparelho precisa para configurar.
     * Nada é gravado no banco ainda.
     */
    public function iniciar(): void
    {
        $this->bootCliente();

        [$usuarioId, $email] = $this->identidade();
        $totp = new TotpService();

        // Reconfigurar por cima do ativo apagaria silenciosamente os códigos de
        // backup que o cliente guardou. Desativar é um passo consciente.
        if ($totp->isAtivo($usuarioId)) {
            $this->falha(409, 'totp_ja_ativo',
                'O app autenticador já está ativo. Desative antes de configurar de novo.');
        }

        $segredo = $totp->gerarSegredo();

        Session::set(self::CHAVE_PENDENTE, $segredo);

        $this->ok([
            'uri'     => $totp->gerarUri($segredo, $email),
            'segredo' => $segredo,
            // Em grupos de 4: o segredo tem 32 caracteres e quem for digitar à
            // mão precisa saber onde parou. Formatar aqui evita que app e site
            // quebrem a string de jeitos diferentes.
            'segredo_formatado' => trim(chunk_split($segredo, 4, ' ')),
        ]);
    }

    /**
     * POST /api/app/v1/conta/seguranca/totp/confirmar   Corpo: { codigo }
     *
     * Valida o primeiro código antes de ativar: prova que o autenticador foi
     * configurado de verdade, em vez de trancar a conta num segredo que o
     * aparelho do cliente talvez não tenha guardado.
     *
     * É aqui que os códigos de backup aparecem em texto puro, uma única vez.
     */
    public function confirmar(): void
    {
        $this->bootCliente();

        $segredo = Session::get(self::CHAVE_PENDENTE);
        if (!is_string($segredo) || $segredo === '') {
            $this->falha(409, 'setup_expirado',
                'A configuração expirou. Comece de novo.');
        }

        $corpo  = $this->exigirCampos(['codigo']);
        $codigo = preg_replace('/\D/', '', (string)$corpo['codigo']) ?? '';

        $totp = new TotpService();

        if (!$totp->validarCodigo($segredo, $codigo)) {
            $this->falha(422, 'codigo_invalido',
                'Código incorreto. Confira o app autenticador e tente de novo.');
        }

        [$usuarioId] = $this->identidade();

        $totp->ativar($usuarioId, $segredo);
        $codigos = $totp->gerarCodigosBackup($usuarioId, self::CODIGOS_BACKUP);

        Session::remove(self::CHAVE_PENDENTE);

        $this->registrar($usuarioId, 'totp_ativado');

        $this->ok([
            'codigos_backup' => $codigos,
            'mensagem'       => 'App autenticador ativado.',
        ]);
    }

    /**
     * POST /api/app/v1/conta/seguranca/totp/desativar/solicitar
     *
     * Desativar reduz a segurança da conta, então exige confirmação. O código
     * vai por e-mail, e não por senha: quem entrou pelo Google nunca definiu
     * senha, e exigi-la trancaria essa pessoa fora da própria configuração —
     * o mesmo raciocínio de CustomerSecurityController.
     */
    public function desativarSolicitar(): void
    {
        $this->bootCliente();

        [$usuarioId, $email, $nome] = $this->identidade();

        if (!(new TotpService())->isAtivo($usuarioId)) {
            $this->falha(409, 'totp_inativo', 'O app autenticador não está ativo.');
        }

        try {
            $codigo = (new TwoFactorService())->solicitarVerificacao($usuarioId, 'desativar_2fa');
            MailHelper::send2FACode($email, $nome, $codigo);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'totp_desativar_solicitar', 'usuario' => $usuarioId]);
            $this->falha(500, 'falha_envio',
                'Não foi possível enviar o código agora. Tente novamente em instantes.');
        }

        $this->ok([
            'destino'  => $this->mascarar($email),
            'mensagem' => 'Enviamos um código para o seu e-mail.',
        ]);
    }

    /**
     * POST /api/app/v1/conta/seguranca/totp/desativar   Corpo: { codigo }
     */
    public function desativar(): void
    {
        $this->bootCliente();

        $corpo  = $this->exigirCampos(['codigo']);
        $codigo = trim((string)$corpo['codigo']);

        [$usuarioId] = $this->identidade();

        $doisFatores = new TwoFactorService();

        if (!$doisFatores->validarCodigo($usuarioId, $codigo)) {
            $this->falha(422, 'codigo_invalido', 'Código inválido ou expirado.');
        }

        $doisFatores->marcarAutorizado('desativar_2fa');
        (new TotpService())->desativar($usuarioId);

        // Ação de uso único concluída: fecha a janela de autorização em vez de
        // deixá-la valendo por mais cinco minutos.
        $doisFatores->limparAutorizacao('desativar_2fa');

        $this->registrar($usuarioId, 'totp_desativado');

        $this->ok(['mensagem' => 'App autenticador desativado.']);
    }

    /**
     * POST /api/app/v1/conta/seguranca/totp/backup
     *
     * Novo lote de códigos, invalidando o anterior. Para quem já gastou quase
     * todos — ou perdeu o papel onde anotou.
     */
    public function regenerarBackup(): void
    {
        $this->bootCliente();

        [$usuarioId] = $this->identidade();

        $totp = new TotpService();
        if (!$totp->isAtivo($usuarioId)) {
            $this->falha(409, 'totp_inativo', 'O app autenticador não está ativo.');
        }

        $codigos = $totp->gerarCodigosBackup($usuarioId, self::CODIGOS_BACKUP);

        $this->registrar($usuarioId, 'totp_backup_regenerado');

        $this->ok([
            'codigos_backup' => $codigos,
            'mensagem'       => 'Novos códigos gerados. Os anteriores não valem mais.',
        ]);
    }

    /* ================================================================= */

    /**
     * O usuário por trás do cliente autenticado.
     *
     * TOTP mora em `usuarios`, e o pipeline do app entrega `cliente_id` — são
     * tabelas diferentes, e trocar uma pela outra ativaria o segundo fator na
     * conta errada.
     *
     * @return array{0:int,1:string,2:string}  [usuarioId, email, nome]
     */
    private function identidade(): array
    {
        $perfil    = (new Customer())->getFullProfile((int)$this->clienteId);
        $usuarioId = (int)($perfil['usuario_id'] ?? 0);

        if ($usuarioId <= 0) {
            $this->falha(500, 'usuario_ausente', 'Não foi possível identificar sua conta.');
        }

        return [
            $usuarioId,
            (string)($perfil['email'] ?? ''),
            (string)($perfil['nome'] ?? ''),
        ];
    }

    /** "ro****@gmail.com" — confirma o destino sem expor o endereço inteiro. */
    private function mascarar(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 2) {
            return $email;
        }

        return substr($email, 0, 2) . str_repeat('*', max(1, $at - 2)) . substr($email, $at);
    }

    /**
     * Registra a mudança no log de autenticação. Ligar ou desligar o segundo
     * fator é exatamente o tipo de evento que o dono da conta precisa
     * conseguir auditar depois — e best effort porque falhar em registrar não
     * pode desfazer o que já foi feito.
     */
    private function registrar(int $usuarioId, string $acao): void
    {
        try {
            // Estático, e com o USUÁRIO — é o que AppAuthService já passa nesta
            // mesma tabela (o parâmetro se chama $clienteId, mas os chamadores
            // do app e do Google gravam usuario_id ali).
            AuthLogService::registrar($usuarioId, $acao, 'success', 'app');
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => $acao, 'usuario' => $usuarioId]);
        }
    }
}
