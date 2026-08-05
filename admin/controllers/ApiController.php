<?php
/**
 * ApiController — base dos endpoints públicos da API de logística.
 *
 * NÃO usa autenticação de admin. Cada endpoint chama exigir($escopo) no início,
 * o que aplica em ordem: autenticação (Bearer) → escopo → rate limit. As
 * respostas saem num envelope JSON padrão e TODA chamada é registrada em
 * log_api_requisicoes (método, rota, status, idempotency-key, ip, duração).
 *
 * Respostas de erro/sucesso encerram a execução (exit) após logar.
 */
abstract class ApiController extends Controller
{
    protected ApiKeyService $api;
    protected ?array $chave = null;
    protected float $t0;
    protected string $versao = 'v1';

    public function __construct()
    {
        $this->api = new ApiKeyService();
        $this->t0 = microtime(true);
        header('Content-Type: application/json; charset=UTF-8');
    }

    /* ---------------- pipeline ---------------- */

    /** Autentica, checa escopo e rate limit. Encerra com erro se falhar. */
    protected function exigir(string $escopo): array
    {
        $key = $this->api->autenticar($this->authorizationHeader());
        if (!$key) $this->falha(401, 'nao_autorizado', 'Chave de API ausente ou inválida.');

        if (!ApiKeyService::escopoPermitido($key['escopos_arr'] ?? [], $escopo)) {
            $this->chave = $key;
            $this->falha(403, 'sem_escopo', 'A chave não tem permissão para "' . $escopo . '".');
        }

        $cont = $this->api->contarRequisicoesRecentes((int)$key['id'], 60);
        if (ApiKeyService::rateLimitExcedido($cont, (int)$key['rate_limit'])) {
            $this->chave = $key;
            $this->falha(429, 'rate_limit', 'Limite de requisições por minuto excedido.', ['rate_limit' => (int)$key['rate_limit'], 'janela_segundos' => 60]);
        }

        $this->chave = $key;
        return $key;
    }

    /* ---------------- entrada ---------------- */

    protected function corpo(): array
    {
        $raw = file_get_contents('php://input');
        $j = json_decode((string)$raw, true);
        return is_array($j) ? $j : [];
    }

    protected function idempotencyKey(): ?string
    {
        $v = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null);
        return $v ? substr(trim((string)$v), 0, 120) : null;
    }

    protected function webhook(): array
    {
        return $this->chave['webhook'] ?? ['url' => null, 'secret' => null];
    }

    /* ---------------- saída ---------------- */

    protected function ok($dados, int $status = 200, array $meta = []): void
    {
        $this->emitir($status, ApiKeyService::envelope(true, $dados, null, $meta));
    }

    protected function falha(int $status, string $codigo, string $mensagem, array $meta = []): void
    {
        $this->emitir($status, ApiKeyService::envelope(false, null, ['codigo' => $codigo, 'mensagem' => $mensagem], $meta));
    }

    private function emitir(int $status, array $payload): void
    {
        http_response_code($status);
        $this->api->registrarRequisicao(
            $this->chave['id'] ?? null,
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $this->rotaAtual(),
            $this->versao,
            $status,
            $this->idempotencyKey(),
            $this->ipReal(),
            (int)round((microtime(true) - $this->t0) * 1000)
        );
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /* ---------------- utilidades ---------------- */

    protected function authorizationHeader(): string
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return (string)$_SERVER['HTTP_AUTHORIZATION'];
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) return (string)$v;
            }
        }
        return '';
    }

    protected function rotaAtual(): string
    {
        $uri = explode('?', (string)($_SERVER['REQUEST_URI'] ?? ''))[0];
        return $uri !== '' ? $uri : '/';
    }

    protected function ipReal(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string)$_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return null;
    }
}
