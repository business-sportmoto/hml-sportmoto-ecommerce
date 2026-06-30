<?php
// app/services/TwoFactorService.php
// Gerencia o 2FA por e-mail para ações sensíveis na conta do cliente.
// Ações protegidas: alterar senha, alterar e-mail, revogar sessões,
// ativar/desativar 2FA, excluir conta.

class TwoFactorService {

    private PDO $db;

    // Ações que exigem verificação 2FA
    public const ACOES_SENSIVEIS = [
        'alterar_senha',
        'revogar_sessoes',
        'ativar_2fa',
        'desativar_2fa',
        'excluir_conta',
        'login'
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Verifica se o usuário tem 2FA ativo.
     */
    public function isAtivo(int $userId): bool {
        $stmt = $this->db->prepare(
            "SELECT dois_fatores_ativo FROM usuarios WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$userId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Gera e envia código de verificação para uma ação sensível.
     * O código fica válido por 10 minutos.
     */
    public function solicitarVerificacao(int $userId, string $acao): string {
        if (!in_array($acao, self::ACOES_SENSIVEIS, true)) {
            throw new RuntimeException("Ação inválida: {$acao}");
        }

        // Invalida códigos anteriores da mesma ação
        $this->db->prepare(
            "UPDATE tokens_verificacao SET usado = 1
             WHERE usuario_id = ? AND tipo = '2fa' AND usado = 0"
        )->execute([$userId]);

        // Gera código numérico de 6 dígitos
        $code     = SecurityHelper::generateNumericCode(6);
        $expiraEm = date('Y-m-d H:i:s', time() + 600); // 10 minutos

        $this->db->prepare(
            "INSERT INTO tokens_verificacao (usuario_id, token, tipo, expira_em)
             VALUES (?, ?, '2fa', ?)"
        )->execute([$userId, $code, $expiraEm]);

        // Salva a ação pendente na sessão
        Session::set('2fa_acao_pendente', $acao);
        Session::set('2fa_usuario_id',    $userId);
        Session::set('2fa_gerado_em',     time());

        return $code;
    }

    /**
     * Valida o código informado pelo usuário.
     */
    public function validarCodigo(int $userId, string $code): bool {
        $stmt = $this->db->prepare(
            "SELECT id FROM tokens_verificacao
             WHERE usuario_id = ?
               AND token = ?
               AND tipo = '2fa'
               AND usado = 0
               AND expira_em > NOW()
             LIMIT 1"
        );
        $stmt->execute([$userId, $code]);
        $row = $stmt->fetch();

        if (!$row) return false;

        // Consome o token
        $this->db->prepare(
            "UPDATE tokens_verificacao SET usado = 1 WHERE id = ?"
        )->execute([$row['id']]);

        return true;
    }

    /**
     * Verifica se a ação atual está autorizada (código válido nesta sessão).
     * Autorização é válida por 5 minutos após verificação.
     */
    public function acaoAutorizada(string $acao): bool {
        $autorizado = Session::get('2fa_autorizado_' . $acao);
        $autorizadoEm = Session::get('2fa_autorizado_em_' . $acao);

        if (!$autorizado || !$autorizadoEm) return false;

        // Autorização expira em 5 minutos
        if ((time() - $autorizadoEm) > 300) {
            Session::remove('2fa_autorizado_' . $acao);
            Session::remove('2fa_autorizado_em_' . $acao);
            return false;
        }

        return true;
    }

    /**
     * Marca uma ação como autorizada na sessão.
     */
    public function marcarAutorizado(string $acao): void {
        Session::set('2fa_autorizado_' . $acao, true);
        Session::set('2fa_autorizado_em_' . $acao, time());
        Session::remove('2fa_acao_pendente');
        Session::remove('2fa_usuario_id');
        Session::remove('2fa_gerado_em');
    }

    /**
     * Revoga a autorização já concedida de UMA ação específica — o
     * inverso de marcarAutorizado(). Força que a próxima tentativa
     * dessa mesma ação peça o código 2FA de novo, mesmo que ainda
     * estivesse dentro da janela de 5 minutos de acaoAutorizada().
     *
     * Uso típico: depois de executar uma ação sensível de uso único
     * (ex: revogar_sessoes), chamar isso evita que a autorização
     * residual permita repetir a ação sem novo código antes de expirar
     * naturalmente.
     */
    public function limparAutorizacao(string $acao): void {
        Session::remove('2fa_autorizado_' . $acao);
        Session::remove('2fa_autorizado_em_' . $acao);
    }

    /**
     * Ativa o 2FA para um usuário.
     */
    public function ativar(int $userId): void {
        $this->db->prepare(
            "UPDATE usuarios SET dois_fatores_ativo = 1 WHERE id = ?"
        )->execute([$userId]);
    }

    /**
     * Desativa o 2FA para um usuário.
     */
    public function desativar(int $userId): void {
        $this->db->prepare(
            "UPDATE usuarios SET dois_fatores_ativo = 0 WHERE id = ?"
        )->execute([$userId]);
    }
}