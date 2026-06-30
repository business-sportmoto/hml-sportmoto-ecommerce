<?php
/**
 * app/services/email/EmailConsentService.php
 *
 * Sincroniza email_contatos a partir de:
 *  - usuarios + clientes
 *  - newsletter
 *
 * Mantém token_descadastro seguro, normaliza emails e registra histórico
 * de consentimento.
 */
class EmailConsentService
{
    /** @var PDO */
    private $db;
    /** @var EmailContact */
    private $contatos;
    /** @var EmailConsent */
    private $consentimentos;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->contatos = new EmailContact();
        $this->consentimentos = new EmailConsent();
    }

    /**
     * Sincroniza contatos da base.
     * Retorna [usuarios, newsletter, total]
     */
    public function sincronizarTudo()
    {
        $u = $this->sincronizarDeClientes();
        $n = $this->sincronizarDeNewsletter();
        return [
            'clientes_usuarios' => $u,
            'newsletter' => $n,
            'total' => $u + $n,
        ];
    }

    public function sincronizarDeClientes()
    {
        // Junta usuarios + clientes, traz somente quem tem newsletter ativa em
        // clientes OU está flagado com email_verificado. Ajuste se sua regra de
        // base legal mudar.
        $sql = "SELECT
                  u.id   AS usuario_id,
                  c.id   AS cliente_id,
                  u.email,
                  u.nome,
                  u.email_verificado,
                  c.celular,
                  c.nascimento,
                  c.genero,
                  COALESCE(c.newsletter, 0) AS newsletter_ativa
                FROM usuarios u
                LEFT JOIN clientes c ON c.usuario_id = u.id
                WHERE (u.deleted_at IS NULL OR u.deleted_at = '0000-00-00 00:00:00')
                  AND u.ativo = 1
                  AND u.email IS NOT NULL
                  AND u.email <> ''";
        $st = $this->db->query($sql);
        $count = 0;
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $email = EmailContact::normalizeEmail($r['email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

            $primeiro = $this->primeiroNome($r['nome']);
            $genero = $this->normGenero($r['genero'] ?? null);

            $id = $this->contatos->upsert([
                'email' => $email,
                'nome'  => $r['nome'] ?? null,
                'primeiro_nome' => $primeiro,
                'cliente_id' => $r['cliente_id'] ? (int)$r['cliente_id'] : null,
                'usuario_id' => (int)$r['usuario_id'],
                'origem' => 'cliente',
                'base_legal' => (int)$r['newsletter_ativa'] === 1 ? 'consentimento' : 'legitimo_interesse',
                'email_verificado' => (int)$r['email_verificado'] ? 1 : 0,
                'genero' => $genero,
                'nascimento' => $r['nascimento'] ?: null,
                'telefone' => $r['celular'] ?: null,
            ]);
            if ($id) $count++;
        }
        return $count;
    }

    public function sincronizarDeNewsletter()
    {
        $sql = "SELECT id, email, nome, ativo, token_cancelamento
                FROM newsletter
                WHERE email IS NOT NULL AND email <> ''";
        $st = $this->db->query($sql);
        $count = 0;
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $email = EmailContact::normalizeEmail($r['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

            $status = (int)$r['ativo'] === 1 ? 'ativo' : 'descadastrado';
            $token = $r['token_cancelamento'] ?: null;

            $id = $this->contatos->upsert([
                'email' => $email,
                'nome'  => $r['nome'] ?: null,
                'primeiro_nome' => $this->primeiroNome($r['nome'] ?: ''),
                'newsletter_id' => (int)$r['id'],
                'origem' => 'newsletter',
                'base_legal' => 'consentimento',
                'status' => $status,
                'token_descadastro' => $token,
            ]);
            if ($id) $count++;
        }
        return $count;
    }

    public function optIn($contatoId, array $extra = [])
    {
        $this->contatos->setStatus($contatoId, 'ativo');
        $this->consentimentos->registrar($contatoId, 'opt_in', $extra);
    }

    public function optOut($contatoId, array $extra = [])
    {
        $this->contatos->setStatus($contatoId, 'descadastrado');
        $this->consentimentos->registrar($contatoId, 'opt_out', $extra);
        // espelha em newsletter, se houver
        $r = $this->contatos->find($contatoId);
        if ($r && !empty($r['email'])) {
            $st = $this->db->prepare("UPDATE newsletter SET ativo = 0 WHERE email = :e");
            $st->execute([':e' => $r['email']]);
        }
    }

    public function bloquearAdmin($contatoId, array $extra = [])
    {
        $this->contatos->setStatus($contatoId, 'bloqueado');
        $this->consentimentos->registrar($contatoId, 'bloqueio_admin', $extra);
    }

    public function desbloquearAdmin($contatoId, array $extra = [])
    {
        $this->contatos->setStatus($contatoId, 'ativo');
        $this->consentimentos->registrar($contatoId, 'desbloqueio_admin', $extra);
    }

    private function primeiroNome($nome)
    {
        if (!$nome) return null;
        $parts = preg_split('/\s+/', trim($nome), 2);
        return $parts[0] ?? null;
    }

    private function normGenero($v)
    {
        $v = strtoupper(trim((string)$v));
        if ($v === 'M' || $v === 'MASCULINO') return 'M';
        if ($v === 'F' || $v === 'FEMININO')  return 'F';
        if ($v !== '' && $v !== '0') return 'Outro';
        return 'NaoInformado';
    }
}
