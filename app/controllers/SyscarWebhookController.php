<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/SyscarWebhookController.php
//
// A porta de entrada da PERNA A: o Syscar empurra um movimento de estoque e
// o painel o enfileira para escrever no Bling.
//
// URL: POST /webhooks/syscar/estoque
//
// ── O que este endpoint NÃO repete do admin.loja ─────────────────
//
//  · Lá, a rota equivalente só confere se o cabeçalho `Authorization`
//    EXISTE — o valor nunca é validado. Pelo código, qualquer requisição com
//    esse cabeçalho escreve estoque no Bling. Aqui o segredo é comparado com
//    `hash_equals` e, sem segredo configurado, o endpoint NEGA.
//  · Lá, o token viaja na query string, que fica no log de acesso de qualquer
//    proxy. Aqui vai em cabeçalho.
//  · Lá, a requisição do Syscar fica esperando a chamada ao Bling terminar
//    (timeout de 30 s). Aqui o endpoint só GRAVA e responde; quem fala com o
//    Bling é o worker.
//
// Ver docs/sportmoto-os/12-decisoes-tecnicas/estoque-modulo-especificacao.md
// ════════════════════════════════════════════════════════

class SyscarWebhookController extends Controller
{
    /** Teto de corpo aceito. Movimento de estoque é pequeno; o resto é abuso. */
    private const MAX_BYTES = 64 * 1024;

    // ── POST /webhooks/syscar/estoque ─────────────────────
    public function estoque(): void
    {
        $raw = file_get_contents('php://input') ?: '';

        // ── 1. Autenticação, antes de qualquer coisa ──────
        // Fail-closed: sem segredo configurado, ou token diferente, para aqui.
        // Não loga o corpo — requisição não autenticada é potencialmente
        // forjada, e gravá-la só enche a tabela com lixo de quem varre porta.
        if (!$this->autenticado()) {
            LogService::warning('Webhook do Syscar rejeitado', [
                'motivo' => 'token ausente ou inválido',
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
            ], 'estoque');
            $this->json(['ok' => false, 'erro' => 'nao autorizado'], 401);
            return;
        }

        if (strlen($raw) > self::MAX_BYTES) {
            $this->json(['ok' => false, 'erro' => 'corpo grande demais'], 413);
            return;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->json(['ok' => false, 'erro' => 'corpo nao e JSON'], 400);
            return;
        }

        // ── 2. Validação mínima ───────────────────────────
        // Só o suficiente para não enfileirar lixo. A tradução completa (SKU,
        // saldo zero virando balanço, resolução de produto) é do worker — o
        // endpoint tem de responder rápido.
        $codigo = trim((string)($payload['cod'] ?? $payload['sku'] ?? ''));
        $lanc   = strtoupper(trim((string)($payload['lancamento'] ?? $payload['operacao'] ?? '')));

        if ($codigo === '') {
            $this->json(['ok' => false, 'erro' => 'informe o codigo do SKU em `cod`'], 422);
            return;
        }
        if (!in_array($lanc, ['E', 'S', 'B'], true)) {
            $this->json(['ok' => false, 'erro' => 'lancamento deve ser E, S ou B'], 422);
            return;
        }

        // ── 3. Enfileira e responde ───────────────────────
        try {
            $r = (new EstoquePonteService())->registrarEvento(
                'syscar',
                'movimento',
                $this->chaveExterna($payload),
                $payload,
                true
            );
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'estoque', ['onde' => 'webhook syscar']);
            $this->json(['ok' => false, 'erro' => 'falha ao registrar'], 500);
            return;
        }

        // 202: aceito e enfileirado — ainda não aplicado. É a resposta honesta,
        // porque quem escreve no Bling é o worker, na próxima rodada.
        $this->json([
            'ok'        => true,
            'evento_id' => $r['id'],
            'duplicado' => $r['duplicado'],
            'msg'       => $r['duplicado']
                ? 'Movimento já havia sido recebido — nada foi duplicado.'
                : 'Movimento enfileirado.',
        ], 202);
    }

    /**
     * A identidade do movimento, que torna a reentrega inofensiva.
     *
     * O Syscar hoje **não manda um id de movimento** — o `sys_id` do payload é
     * o id do PRODUTO lá, não da movimentação. Então:
     *
     *   1º  `protocolo` ou `id_movimento`, se o Syscar passar a mandar;
     *   2º  um sha256 do conteúdo, incluindo `data_time` e o SALDO NOVO.
     *
     * O saldo novo é o que separa dois movimentos legítimos e iguais em
     * sequência (vender 1 unidade duas vezes deixa saldos diferentes) de uma
     * reentrega do mesmo movimento (que repete o saldo). É o mesmo raciocínio
     * aplicado ao webhook de estoque do Bling.
     */
    private function chaveExterna(array $p): ?string
    {
        foreach (['protocolo', 'id_movimento', 'movimento_id'] as $campo) {
            $v = trim((string)($p[$campo] ?? ''));
            if ($v !== '') return mb_substr($v, 0, 120);
        }

        $partes = [
            'cod'  => (string)($p['cod'] ?? $p['sku'] ?? ''),
            'dt'   => (string)($p['data_time'] ?? $p['data'] ?? ''),
            'lanc' => (string)($p['lancamento'] ?? $p['operacao'] ?? ''),
            'mov'  => (string)($p['movimento'] ?? $p['quantidade'] ?? ''),
            'novo' => (string)($p['n_saldo'] ?? ''),
        ];

        // Sem data nem saldo novo não há o que distinguir: melhor não fingir
        // identidade. Sem chave externa o evento não é deduplicado aqui — o
        // protocolo do movimento ainda barra adiante.
        if ($partes['dt'] === '' && $partes['novo'] === '') return null;

        return 'sha:' . hash('sha256', implode('|', $partes));
    }

    /**
     * Compara o token do cabeçalho com o segredo, em tempo constante.
     *
     * Aceita `X-Syscar-Token` ou `Authorization: Bearer <token>`. O segredo
     * vem do ambiente (`SYSCAR_INBOUND_TOKEN`) e, como alternativa operável
     * sem deploy, de `configuracoes.estoque_syscar_token_entrada`.
     *
     * ⚠ Enquanto não se souber o que o Syscar consegue enviar, isto é um
     * token comparado byte a byte. Se o fornecedor suportar HMAC do corpo,
     * troque por `hash_hmac('sha256', $raw, $segredo)` — o molde está em
     * `BlingWebhookController::assinaturaValida()`.
     */
    private function autenticado(): bool
    {
        $segredo = $this->segredo();
        if ($segredo === '') {
            // Sem segredo NÃO se libera por engano. É o oposto do que o
            // admin.loja faz.
            return false;
        }

        $recebido = trim((string)($_SERVER['HTTP_X_SYSCAR_TOKEN'] ?? ''));

        if ($recebido === '') {
            $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
            if (stripos($auth, 'bearer ') === 0) {
                $recebido = trim(substr($auth, 7));
            }
        }

        if ($recebido === '') return false;

        return hash_equals($segredo, $recebido);
    }

    private function segredo(): string
    {
        $v = getenv('SYSCAR_INBOUND_TOKEN');
        if ($v !== false && trim((string)$v) !== '') return trim((string)$v);
        if (!empty($_ENV['SYSCAR_INBOUND_TOKEN'])) return trim((string)$_ENV['SYSCAR_INBOUND_TOKEN']);

        try {
            $stmt = Database::getInstance()->getConnection()->prepare(
                "SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1"
            );
            $stmt->execute(['estoque_syscar_token_entrada']);
            $v = $stmt->fetchColumn();
            return $v !== false ? trim((string)$v) : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
