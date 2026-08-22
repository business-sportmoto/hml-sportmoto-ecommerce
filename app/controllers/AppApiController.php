<?php
// app/controllers/AppApiController.php
// Base de todos os controllers da API do app mobile.
//
// Não herda de ApiController de propósito: aquele pipeline autentica CHAVES DE
// PARCEIRO (log_api_keys, escopos de logística), enquanto aqui o sujeito é um
// DISPOSITIVO que pode ou não ter um cliente vinculado.
//
// O que é deliberadamente igual ao ApiController: o envelope de resposta
// (via ApiKeyService::envelope) e o formato do log. Assim o app tem um único
// parser para as duas APIs.
//
//   sucesso: {"ok":true, "dados":…, "meta":{…}}
//   erro:    {"ok":false,"erro":{"codigo":…,"mensagem":…}}

abstract class AppApiController extends Controller
{
    protected ?array $dispositivo = null;
    protected ?int   $clienteId   = null;
    protected ?int   $usuarioId   = null;
    protected float  $t0;
    protected string $versao = 'v1';

    /** Requisições por minuto por dispositivo. 0 desliga. */
    protected int $rateLimit = 240;

    private ?PDO $pdo = null;

    public function __construct()
    {
        $this->t0 = microtime(true);
    }

    /* =================================================================
       PIPELINE DE BOOT
       ================================================================= */

    /**
     * Exige um dispositivo registrado; o cliente pode ser anônimo.
     * É o boot do carrinho, do catálogo com favoritos e de tudo que precisa de
     * estado por device sem exigir login.
     */
    protected function bootPublico(): void
    {
        $this->resolverDispositivo(true);
        $this->abrirSessao();
    }

    /**
     * Exige cliente autenticado. 401 caso contrário.
     */
    protected function bootCliente(): void
    {
        $this->resolverDispositivo(true);

        if (empty($this->dispositivo['cliente_id'])) {
            $this->falha(401, 'nao_autenticado', 'É necessário estar logado para acessar este recurso.');
        }

        $this->abrirSessao();

        $this->clienteId = (int)$this->dispositivo['cliente_id'];
        $this->usuarioId = $this->dispositivo['usuario_id'] !== null
            ? (int)$this->dispositivo['usuario_id']
            : null;
    }

    /**
     * Token opcional. Usado no catálogo público, onde a presença do cliente só
     * enriquece a resposta (favoritado, compatibilidade com a moto ativa).
     */
    protected function bootOpcional(): void
    {
        $this->resolverDispositivo(false);
        if ($this->dispositivo) {
            $this->abrirSessao();
        }
    }

    /**
     * Boot sem token nenhum — só para /config e /dispositivos/registrar, que
     * são justamente os endpoints que existem antes de haver token.
     */
    protected function bootAberto(): void
    {
        // nada a fazer; existe para deixar a intenção explícita no controller
    }

    private function resolverDispositivo(bool $obrigatorio): void
    {
        $token = AppTokenService::extrairToken($this->authorizationHeader());

        if ($token === '') {
            if ($obrigatorio) {
                $this->falha(401, 'token_ausente', 'Envie o header Authorization: Bearer <access_token>.');
            }
            return;
        }

        $linha = (new AppTokenService())->validar($token, 'access');

        if (!$linha) {
            if ($obrigatorio) {
                // Código específico para o app saber que deve tentar o refresh
                // em vez de mandar o usuário para a tela de login.
                $this->falha(401, 'token_expirado', 'Token inválido ou expirado.');
            }
            return;
        }

        $this->dispositivo = [
            'id'             => (int)$linha['dispositivo_id'],
            'device_uuid'    => $linha['device_uuid'],
            'plataforma'     => $linha['plataforma'],
            'app_versao'     => $linha['app_versao'],
            'php_session_id' => $linha['php_session_id'],
            'cliente_id'     => $linha['d_cliente_id'] !== null ? (int)$linha['d_cliente_id'] : null,
            'usuario_id'     => $linha['d_usuario_id'] !== null ? (int)$linha['d_usuario_id'] : null,
            'familia'        => $linha['familia'],
            'ultimo_acesso'  => $linha['d_ultimo_acesso'] ?? null,
        ];

        $this->clienteId = $this->dispositivo['cliente_id'];
        $this->usuarioId = $this->dispositivo['usuario_id'];

        $this->verificarRateLimit();

        // O ultimo_acesso já veio no JOIN da validação do token: decidir aqui
        // evita mandar ao banco um UPDATE que quase sempre não faria nada.
        $ultimo = $this->dispositivo['ultimo_acesso'];
        if ($ultimo === null || strtotime((string)$ultimo) < time() - 300) {
            (new AppDeviceService())->tocar($this->dispositivo['id'], $this->ipReal());
        }
    }

    private function abrirSessao(): void
    {
        if ($this->dispositivo) {
            AppSessionBridge::abrir($this->dispositivo);
        }
    }

    /**
     * Fecha a sessão para escrita. Chamar em TODO handler GET logo após o boot:
     * sem isso, os 4-6 requests paralelos que a home dispara viram fila.
     */
    protected function liberarSessao(): void
    {
        AppSessionBridge::liberar();
    }

    private function verificarRateLimit(): void
    {
        if ($this->rateLimit <= 0 || !$this->dispositivo) {
            return;
        }

        try {
            $st = $this->db()->prepare(
                "SELECT COUNT(*) FROM log_app_requisicoes
                 WHERE dispositivo_id = :d AND criado_em >= (NOW() - INTERVAL 60 SECOND)"
            );
            $st->execute([':d' => $this->dispositivo['id']]);
            $contagem = (int)$st->fetchColumn();
        } catch (\Throwable $e) {
            return; // fail-open: erro de contagem não derruba a API
        }

        if ($contagem >= $this->rateLimit) {
            $this->falha(429, 'rate_limit', 'Muitas requisições. Tente novamente em instantes.', [
                'rate_limit'      => $this->rateLimit,
                'janela_segundos' => 60,
            ]);
        }
    }

    /* =================================================================
       ENTRADA
       ================================================================= */

    /** Corpo JSON da requisição. Cai para $_POST se vier form-encoded. */
    protected function corpo(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $raw = file_get_contents('php://input');
        $j   = json_decode((string)$raw, true);

        if (is_array($j)) {
            return $cache = $j;
        }
        return $cache = (is_array($_POST) ? $_POST : []);
    }

    protected function campo(string $chave, $padrao = null)
    {
        $c = $this->corpo();
        return $c[$chave] ?? $padrao;
    }

    protected function query(string $chave, $padrao = null)
    {
        return $_GET[$chave] ?? $padrao;
    }

    /** Paginação normalizada. per_page limitado para não virar vetor de abuso. */
    protected function pagina(int $porPaginaPadrao = 24, int $maximo = 60): array
    {
        $pagina    = max(1, (int)($this->query('page', 1)));
        $porPagina = (int)($this->query('per_page', $porPaginaPadrao));
        $porPagina = max(1, min($maximo, $porPagina));

        return [
            'page'   => $pagina,
            'limit'  => $porPagina,
            'offset' => ($pagina - 1) * $porPagina,
        ];
    }

    protected function idempotencyKey(): ?string
    {
        $v = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null);
        return $v ? substr(trim((string)$v), 0, 120) : null;
    }

    /** Contexto para os presenters — nunca deixe um presenter ler Session. */
    protected function contexto(): PresenterContext
    {
        return PresenterContext::deDispositivo($this->dispositivo, $this->clienteId);
    }

    /* =================================================================
       SAÍDA
       ================================================================= */

    protected function ok($dados, int $status = 200, array $meta = []): void
    {
        $this->emitir($status, ApiKeyService::envelope(true, $dados, null, $meta));
    }

    /** Resposta de lista paginada com meta padronizado. */
    protected function okPaginado(string $chave, array $itens, int $total, array $pagina, array $extra = []): void
    {
        $dados = array_merge([$chave => $itens], $extra);
        $this->ok($dados, 200, [
            'pagina'    => $pagina['page'],
            'por_pagina'=> $pagina['limit'],
            'total'     => $total,
            'tem_mais'  => ($pagina['offset'] + count($itens)) < $total,
        ]);
    }

    protected function falha(int $status, string $codigo, string $mensagem, array $meta = []): void
    {
        $this->emitir($status, ApiKeyService::envelope(
            false, null, ['codigo' => $codigo, 'mensagem' => $mensagem], $meta
        ));
    }

    private function emitir(int $status, array $payload): void
    {
        // Libera a sessão antes de responder: mesmo em POST, o trabalho acabou.
        AppSessionBridge::liberar();

        http_response_code($status);
        $this->registrarRequisicao($status);

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function registrarRequisicao(int $status): void
    {
        try {
            $this->db()->prepare(
                "INSERT INTO log_app_requisicoes
                    (dispositivo_id, cliente_id, metodo, rota, versao, status_http,
                     idempotency_key, app_versao, plataforma, ip, duracao_ms)
                 VALUES (:d, :c, :m, :r, :v, :s, :i, :av, :p, :ip, :dur)"
            )->execute([
                ':d'   => $this->dispositivo['id'] ?? null,
                ':c'   => $this->clienteId,
                ':m'   => substr($_SERVER['REQUEST_METHOD'] ?? 'GET', 0, 8),
                ':r'   => substr($this->rotaAtual(), 0, 200),
                ':v'   => $this->versao,
                ':s'   => $status,
                ':i'   => $this->idempotencyKey(),
                ':av'  => $this->dispositivo['app_versao'] ?? null,
                ':p'   => $this->dispositivo['plataforma'] ?? null,
                ':ip'  => $this->ipReal(),
                ':dur' => (int)round((microtime(true) - $this->t0) * 1000),
            ]);
        } catch (\Throwable $e) { /* log nunca derruba a resposta */ }
    }

    /* =================================================================
       UTILIDADES
       ================================================================= */

    protected function db(): PDO
    {
        return $this->pdo ??= Database::getInstance()->getConnection();
    }

    /** Header Authorization com os fallbacks de Apache/CGI (igual ApiController). */
    protected function authorizationHeader(): string
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return (string)$_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    return (string)$v;
                }
            }
        }
        return '';
    }

    protected function ipReal(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string)$_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return null;
    }

    protected function rotaAtual(): string
    {
        return (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
    }

    /* =================================================================
       VALIDAÇÃO
       ================================================================= */

    /** Exige campos presentes e não vazios no corpo; 422 com a lista de faltantes. */
    protected function exigirCampos(array $obrigatorios): array
    {
        $corpo    = $this->corpo();
        $faltando = [];

        foreach ($obrigatorios as $campo) {
            if (!isset($corpo[$campo]) || $corpo[$campo] === '' || $corpo[$campo] === []) {
                $faltando[] = $campo;
            }
        }

        if ($faltando) {
            $this->falha(422, 'dados_invalidos', 'Campos obrigatórios ausentes.', ['campos' => $faltando]);
        }

        return $corpo;
    }
}
