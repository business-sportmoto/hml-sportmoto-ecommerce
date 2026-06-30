<?php
declare(strict_types=1);

/**
 * CustomerSecurityController — ações de segurança do cliente no painel.
 * Hoje cobre o setup/gerenciamento de TOTP (app autenticador). Segue o
 * mesmo padrão de CustomerController: clienteId() via Session, $perfil
 * obrigatório em todo render, layout 'customer'.
 */
class CustomerSecurityController extends Controller {

    private Customer         $customerModel;
    private TotpService      $totpService;
    private TwoFactorService $twoFactorService;

    public function __construct() {
        $this->customerModel    = new Customer();
        $this->totpService      = new TotpService();
        $this->twoFactorService = new TwoFactorService();
    }

    private function clienteId(): int {
        return (int)Session::getClienteId();
    }

    private function usuarioId(): int {
        $perfil = $this->customerModel->getFullProfile($this->clienteId());
        return (int)($perfil['usuario_id'] ?? 0);
    }

    /**
     * GET /minha-conta/seguranca
     * Tela principal: mostra status do TOTP (ativo/inativo) e ponto de
     * entrada para ativar ou desativar.
     */
    public function index(): void {
        $perfil    = $this->customerModel->getFullProfile($this->clienteId());
        $usuarioId = (int)($perfil['usuario_id'] ?? 0);

        $totpAtivo        = $this->totpService->isAtivo($usuarioId);
        $codigosRestantes = $totpAtivo
            ? $this->totpService->contarCodigosBackupRestantes($usuarioId)
            : 0;

        $this->render('customer/seguranca', [
            'perfil'            => $perfil,
            'totpAtivo'         => $totpAtivo,
            'codigosRestantes'  => $codigosRestantes,
        ], 'customer');
    }

    /**
     * POST /minha-conta/seguranca/totp/iniciar
     * Gera um segredo TEMPORÁRIO (ainda não salvo como ativo — só é
     * persistido em usuarios.totp_secret depois da confirmação) e
     * retorna a URI para o QR code. Guarda o segredo na sessão até a
     * confirmação, exatamente como o fluxo de 2FA pendente do login.
     */
    public function totpIniciar(): void {
        $this->verifyCsrf();

        $perfil = $this->customerModel->getFullProfile($this->clienteId());
        $usuarioId = (int)($perfil['usuario_id'] ?? 0);

        if ($this->totpService->isAtivo($usuarioId)) {
            $this->json(['ok' => false, 'msg' => 'TOTP já está ativo. Desative antes de reconfigurar.']);
        }

        $segredo = $this->totpService->gerarSegredo();
        $uri     = $this->totpService->gerarUri($segredo, $perfil['email'] ?? '');

        // Segredo fica pendente na sessão até o usuário confirmar com
        // um código válido — só então é persistido no banco. Evita
        // gravar segredos "órfãos" de setups abandonados no meio.
        Session::set('_totp_setup_segredo', $segredo);

        $this->json([
            'ok'     => true,
            'uri'    => $uri,
            'secret' => $segredo, // exibido como texto também, para digitação manual
        ]);
    }

    /**
     * POST /minha-conta/seguranca/totp/confirmar
     * Valida o primeiro código gerado pelo app para confirmar que o
     * setup funcionou antes de ativar de fato e travar a conta nisso.
     * Gera e retorna os códigos de backup nesta mesma resposta (única
     * vez em que aparecem em texto puro).
     */
    public function totpConfirmar(): void {
        $this->verifyCsrf();

        $segredo = Session::get('_totp_setup_segredo');
        if (!$segredo) {
            $this->json(['ok' => false, 'msg' => 'Setup expirado. Comece novamente.', 'restart' => true]);
        }

        $codigo = trim($_POST['code'] ?? '');
        if (!$this->totpService->validarCodigo($segredo, $codigo)) {
            $this->json(['ok' => false, 'msg' => 'Código inválido. Verifique o app e tente novamente.']);
        }

        $usuarioId = $this->usuarioId();
        $this->totpService->ativar($usuarioId, $segredo);
        $codigosBackup = $this->totpService->gerarCodigosBackup($usuarioId, 8);

        Session::remove('_totp_setup_segredo');

        $this->json([
            'ok'             => true,
            'msg'            => 'App autenticador ativado com sucesso.',
            'codigos_backup' => $codigosBackup,
        ]);
    }

    /**
     * POST /minha-conta/seguranca/totp/desativar
     * Exige senha atual como confirmação — desativar 2FA é uma ação
     * sensível (reduz a segurança da conta), mesmo padrão de outras
     * ações sensíveis do painel.
     */
    /**
     * POST /minha-conta/seguranca/totp/desativar-solicitar
     * Dispara o código 2FA (e-mail/WhatsApp) para confirmar a
     * desativação — usa o MESMO TwoFactorService que já protege outras
     * ações sensíveis do painel, em vez de senha.
     *
     * Por que não senha: clientes que se cadastraram só via Google
     * (senha_definida = 0) não têm senha_hash para comparar — exigir
     * senha aqui travaria a única forma desses clientes desativarem o
     * TOTP. O código por e-mail/WhatsApp funciona para todo cliente,
     * independente de como a conta foi criada.
     */
    public function totpDesativarSolicitar(): void {
        $this->verifyCsrf();

        $usuarioId = $this->usuarioId();
        if (!$this->totpService->isAtivo($usuarioId)) {
            $this->json(['ok' => false, 'msg' => 'TOTP não está ativo.']);
        }

        // solicitarVerificacao() já gera e salva o código, e retorna o
        // valor em texto puro para o chamador decidir como entregá-lo.
        // Só e-mail por agora — sempre disponível, independente de como
        // a conta foi criada (inclusive contas só-Google sem celular
        // cadastrado).
        $codigo = $this->twoFactorService->solicitarVerificacao($usuarioId, 'desativar_2fa');

        $perfil = $this->customerModel->getFullProfile($this->clienteId());
        MailHelper::send2FACode($perfil['email'] ?? '', $perfil['nome'] ?? '', $codigo);

        $this->json([
            'ok'  => true,
            'msg' => 'Código de verificação enviado para o seu e-mail.',
        ]);
    }

    /**
     * POST /minha-conta/seguranca/totp/desativar-confirmar
     * Valida o código 2FA e, se correto, desativa o TOTP. Limpa a
     * autorização imediatamente após o uso (limparAutorizacao) para
     * não deixar a janela de 5 minutos aberta para repetir a ação.
     */
    public function totpDesativarConfirmar(): void {
        $this->verifyCsrf();

        $usuarioId = $this->usuarioId();
        $codigo    = trim($_POST['code'] ?? '');

        if (!$this->twoFactorService->validarCodigo($usuarioId, $codigo)) {
            $this->json(['ok' => false, 'msg' => 'Código inválido ou expirado.']);
        }

        $this->twoFactorService->marcarAutorizado('desativar_2fa');

        $this->totpService->desativar($usuarioId);

        // Ação de uso único concluída — fecha a janela de autorização
        // residual em vez de deixá-la valer por mais 5 minutos.
        $this->twoFactorService->limparAutorizacao('desativar_2fa');

        $this->json(['ok' => true, 'msg' => 'App autenticador desativado.']);
    }

    /**
     * POST /minha-conta/seguranca/totp/regenerar-backup
     * Gera um novo lote de códigos de backup, invalidando os antigos.
     * Útil quando o cliente já usou vários códigos e quer reabastecer.
     */
    public function totpRegenerarBackup(): void {
        $this->verifyCsrf();

        $usuarioId = $this->usuarioId();
        if (!$this->totpService->isAtivo($usuarioId)) {
            $this->json(['ok' => false, 'msg' => 'TOTP não está ativo.']);
        }

        $codigosBackup = $this->totpService->gerarCodigosBackup($usuarioId, 8);

        $this->json([
            'ok'             => true,
            'codigos_backup' => $codigosBackup,
        ]);
    }
}