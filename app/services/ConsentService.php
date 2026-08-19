<?php
declare(strict_types=1);

/**
 * app/services/ConsentService.php
 *
 * Gate de consentimento LGPD. Duas responsabilidades:
 *  1. REGISTRAR a escolha do usuário (chamado pelo banner/CMP)
 *  2. CONSULTAR se um evento pode ser despachado (chamado pelo
 *     dispatcher de tracking da Fase 1)
 *
 * FAIL-CLOSED: na ausência de consentimento explícito, NADA de
 * analytics/marketing é liberado. Isto é o que protege você antes
 * mesmo do banner existir — se o código de tracking rodar sem o
 * usuário ter aceito, o gate bloqueia por padrão.
 *
 * Três categorias:
 *  - necessarios: sempre on (base legal = legítimo interesse; são
 *    cookies essenciais de funcionamento, não exigem opt-in)
 *  - analytics: GA4 (opt-in)
 *  - marketing: Meta/Google Ads (opt-in)
 *
 * Evidência no banco (tabela consentimentos) — LGPD art. 8º exige
 * poder PROVAR o consentimento. Cookie guarda o estado p/ leitura
 * rápida; o banco guarda a prova (timestamp, IP, versão da política).
 */
final class ConsentService
{
    private const COOKIE          = 'sm_consent';
    private const COOKIE_DIAS     = 180;   // reperguntar a cada 6 meses
    public  const POLITICA_VERSAO = '1.0'; // subir quando a política mudar

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // ══════════════════════════════════════════════════
    // CONSULTA — usada pelo dispatcher de tracking (Fase 1)
    // ══════════════════════════════════════════════════

    /**
     * Pode despachar evento da categoria dada?
     * @param string $categoria 'analytics' | 'marketing'
     */
    public function permite(string $categoria): bool
    {
        $estado = $this->estadoAtual();

        // FAIL-CLOSED: sem cookie/estado = sem consentimento = NÃO
        if ($estado === null) {
            return false;
        }

        return match ($categoria) {
            'analytics' => (bool)($estado['analytics'] ?? false),
            'marketing' => (bool)($estado['marketing'] ?? false),
            // 'necessarios' sempre true; categoria desconhecida = NÃO
            'necessarios' => true,
            default => false,
        };
    }

    /**
     * Estado atual das categorias, lido do cookie.
     * @return array{analytics:bool,marketing:bool}|null null se não decidiu
     */
    public function estadoAtual(): ?array
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if ($raw === '') return null;

        $dados = json_decode($raw, true);
        if (!is_array($dados) || !isset($dados['a'], $dados['m'])) {
            return null; // cookie malformado = trata como não-decidido
        }

        return [
            'analytics' => (bool)$dados['a'],
            'marketing' => (bool)$dados['m'],
        ];
    }

    /** Já decidiu? (p/ o front saber se mostra o banner) */
    public function jaDecidiu(): bool
    {
        return $this->estadoAtual() !== null;
    }

    // ══════════════════════════════════════════════════
    // REGISTRO — usado pelo banner/CMP
    // ══════════════════════════════════════════════════

    /**
     * Registra a escolha: grava evidência no banco, seta o cookie.
     *
     * @param bool $analytics
     * @param bool $marketing
     * @param string $acao 'aceitou_tudo'|'recusou_tudo'|'personalizado'|'retirou'
     * @param int|null $clienteId se logado
     */
    public function registrar(
        bool $analytics,
        bool $marketing,
        string $acao,
        ?int $clienteId = null
    ): void {
        // Token do visitante (reusa o do cookie se já existe, senão gera)
        $token = $this->visitanteToken();

        // 1. Evidência no banco (append-only — cada decisão é uma linha)
        try {
            $this->db->prepare(
                "INSERT INTO consentimentos
                 (cliente_id, visitante_token, necessarios, analytics, marketing,
                  acao, politica_versao, ip, user_agent)
                 VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $clienteId,
                $token,
                (int)$analytics,
                (int)$marketing,
                $acao,
                self::POLITICA_VERSAO,
                SecurityHelper::clientIp(),
                mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (\Throwable $e) {
            // Registro de evidência NÃO pode quebrar a navegação, mas
            // é importante — loga o erro pra não perder silenciosamente
            error_log('[Consent] falha ao registrar evidência: ' . $e->getMessage());
        }

        // 2. Cookie com o estado (leitura rápida pelo gate)
        $this->setCookie($token, $analytics, $marketing);
    }

    /**
     * Retirada de consentimento (LGPD art. 8º §5 — retirar deve ser
     * tão fácil quanto dar). Zera analytics e marketing.
     */
    public function retirar(?int $clienteId = null): void
    {
        $this->registrar(false, false, 'retirou', $clienteId);
    }

    // ══════════════════════════════════════════════════
    // INTERNOS
    // ══════════════════════════════════════════════════

    /** Token estável do visitante (do cookie, ou novo UUID). */
    private function visitanteToken(): string
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if ($raw !== '') {
            $dados = json_decode($raw, true);
            if (is_array($dados) && !empty($dados['t'])) {
                return (string)$dados['t'];
            }
        }
        return $this->uuidv4();
    }

    private function setCookie(string $token, bool $analytics, bool $marketing): void
    {
        $payload = json_encode([
            't' => $token,
            'a' => $analytics ? 1 : 0,
            'm' => $marketing ? 1 : 0,
            'v' => self::POLITICA_VERSAO,
        ]);

        setcookie(self::COOKIE, $payload, [
            'expires'  => time() + self::COOKIE_DIAS * 86400,
            'path'     => '/',
            'secure'   => SecurityHelper::isHttps(),
            'httponly' => false,  // o JS do banner precisa LER (não é segredo)
            'samesite' => 'Lax',
        ]);

        // Reflete no $_COOKIE da requisição atual (pro gate já valer agora)
        $_COOKIE[self::COOKIE] = $payload;
    }

    private function uuidv4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}