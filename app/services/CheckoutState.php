<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/CheckoutState.php
//
// Persiste escolhas do checkout (endereço, frete, cupom,
// observação) no carrinho atual + sessão.
//
// Estratégia híbrida:
//   - Sessão  → leitura rápida (sem hit no DB)
//   - DB      → backup em carrinhos.checkout_meta (JSON)
//     Recupera estado se sessão expirar mas cliente voltar logado.
//
// Pagamento NÃO entra aqui — fica em sessão volátil.
// ════════════════════════════════════════════════════════

class CheckoutState {

    private const SESSION_KEY = 'checkout_state';
    private PDO $db;

    /**
     * Schema do estado persistido:
     *   [
     *     'endereco_id'      => int,
     *     'frete'            => ['codigo','descricao','valor','prazo','carrier','poster','tag'],
     *     'cupom'            => ['codigo','desconto','tipo'],
     *     'observacao'       => string,
     *     'ultima_etapa'     => 'identify|address|payment|summary',
     *     'atualizado_em'    => int (timestamp),
     *   ]
     */
    private array $state = [];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->load();
    }

    // ── LEITURA ────────────────────────────────────────

    public function getEnderecoId(): ?int {
        return isset($this->state['endereco_id'])
            ? (int)$this->state['endereco_id']
            : null;
    }

    public function getFrete(): ?array {
        return $this->state['frete'] ?? null;
    }

    public function getCupom(): ?array {
        return $this->state['cupom'] ?? null;
    }

    public function getObservacao(): string {
        return (string)($this->state['observacao'] ?? '');
    }

    public function getUltimaEtapa(): string {
        return (string)($this->state['ultima_etapa'] ?? 'identify');
    }

    public function getAll(): array {
        return $this->state;
    }

    // ── ESCRITA ────────────────────────────────────────

    public function setEnderecoId(int $id): void {
        // Trocar de endereço invalida frete (CEP pode ter mudado)
        $currentId = $this->getEnderecoId();
        if ($currentId !== $id) {
            unset($this->state['frete']);
        }
        $this->state['endereco_id'] = $id;
        $this->save();
    }

    public function setFrete(array $frete): void {
        $this->state['frete'] = [
            'codigo'    => (string)($frete['codigo']    ?? ''),
            'descricao' => (string)($frete['descricao'] ?? ''),
            'valor'     => (float) ($frete['valor']     ?? 0),
            'prazo'     => (int)   ($frete['prazo']     ?? 0),
            'carrier'   => (string)($frete['carrier']   ?? ''),
            'poster'    => (string)($frete['poster']    ?? ''),
            'tag'       => (string)($frete['tag']       ?? ''),
        ];
        $this->save();
    }

    public function setCupom(string $codigo, float $desconto, string $tipo = 'fixo'): void {
        $this->state['cupom'] = [
            'codigo'   => mb_strtoupper(trim($codigo)),
            'desconto' => $desconto,
            'tipo'     => $tipo,  // fixo | percentual
        ];
        $this->save();
    }

    public function removerCupom(): void {
        unset($this->state['cupom']);
        $this->save();
    }

    public function setObservacao(string $obs): void {
        $this->state['observacao'] = mb_substr($obs, 0, 500);
        $this->save();
    }

    public function setUltimaEtapa(string $etapa): void {
        static $validas = ['identify','address','payment','summary'];
        if (in_array($etapa, $validas, true)) {
            $this->state['ultima_etapa'] = $etapa;
            $this->save();
        }
    }

    // ── HELPERS DE FLUXO ───────────────────────────────

     public function proximaEtapaUrl(): string {
        if (!Session::isClienteLogado())    return BASE_URL . '/checkout/identify';
        if (!$this->getEnderecoId())         return BASE_URL . '/checkout/address';
        if (!$this->getFrete())              return BASE_URL . '/checkout/address';
        return BASE_URL . '/checkout/payment';
    }
 
    public function podeAcessar(string $etapa): bool {
        return match ($etapa) {
            'identify' => true,
            'address'  => Session::isClienteLogado(),
            'payment'  => Session::isClienteLogado() && $this->getEnderecoId() !== null,
            'summary'  => Session::isClienteLogado()
                       && $this->getEnderecoId() !== null
                       && $this->getFrete()      !== null,
            default    => false,
        };
    }

    /**
     * Determina a próxima etapa pendente baseada no estado atual.
     * Retorna a URL pra redirecionar.
     */
    // public function proximaEtapaUrl(): string {
    //     if (!Session::isClienteLogado()) {
    //         return BASE_URL . '/checkout/identify';
    //     }
    //     if (!$this->getEnderecoId()) {
    //         return BASE_URL . '/checkout/address';
    //     }
    //     if (!$this->getFrete()) {
    //         return BASE_URL . '/checkout/payment';
    //     }
    //     return BASE_URL . '/checkout/summary';
    // }

    /**
     * Verifica se o cliente pode acessar uma etapa (não pode pular).
     */
    // public function podeAcessar(string $etapa): bool {
    //     return match ($etapa) {
    //         'identify' => true,
    //         'address'  => Session::isClienteLogado(),
    //         'payment'  => Session::isClienteLogado() && $this->getEnderecoId() !== null,
    //         'summary'  => Session::isClienteLogado() && $this->getEnderecoId() !== null && $this->getFrete() !== null,
    //         default    => false,
    //     };
    // }

    public function clear(): void {
        $this->state = [];
        Session::remove(self::SESSION_KEY);
        $carrinhoId = $this->getCarrinhoId();
        if ($carrinhoId) {
            $this->db->prepare("UPDATE carrinhos SET checkout_meta = NULL WHERE id = ?")
                     ->execute([$carrinhoId]);
        }
    }

    // ── PERSISTÊNCIA ───────────────────────────────────

    private function load(): void {
        // 1. Tenta da sessão (mais rápido)
        $session = Session::get(self::SESSION_KEY);
        if (is_array($session)) {
            $this->state = $session;
            return;
        }

        // 2. Fallback: carrega do DB se cliente logado
        $carrinhoId = $this->getCarrinhoId();
        if (!$carrinhoId) return;

        $stmt = $this->db->prepare(
            "SELECT checkout_meta FROM carrinhos WHERE id = ?"
        );
        $stmt->execute([$carrinhoId]);
        $json = $stmt->fetchColumn();

        if ($json) {
            $decoded = json_decode((string)$json, true);
            if (is_array($decoded)) {
                $this->state = $decoded;
                Session::set(self::SESSION_KEY, $this->state);
            }
        }
    }

    private function save(): void {
        $this->state['atualizado_em'] = time();
        Session::set(self::SESSION_KEY, $this->state);

        $carrinhoId = $this->getCarrinhoId();
        if (!$carrinhoId) return;

        try {
            $this->db->prepare(
                "UPDATE carrinhos SET checkout_meta = ? WHERE id = ?"
            )->execute([json_encode($this->state, JSON_UNESCAPED_UNICODE), $carrinhoId]);
        } catch (\PDOException $e) {
            // Coluna pode não existir ainda — não bloqueia, só loga
            error_log('[CheckoutState] save falhou: ' . $e->getMessage());
        }
    }

    public function getCarrinhoId(): ?int {
        if (method_exists('Session', 'getCarrinhoId')) {
            $id = Session::getCarrinhoId();
            return $id ? (int)$id : null;
        }
        return null;
    }

    public static function getSessionKey(): string {
        if (session_status() === PHP_SESSION_ACTIVE) return session_id();
        if (empty($_SESSION['_psess'])) {
            $_SESSION['_psess'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['_psess'];
    }
}