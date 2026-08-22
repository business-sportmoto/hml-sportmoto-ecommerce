<?php
// config/api-app-bootstrap.php
// Bootstrap enxuto da API do app mobile. Substitui todo o miolo do index.php
// para requests em /api/app/*.
//
// Incluído pelo short-circuit no topo do index.php, ANTES de
// SecurityHelper::setSecurityHeaders(). Com isso o request do app pula:
//   Session::start() da web + cookie ec_session
//   AuthHelper::enforceEmailVerificado()
//   TokenService::checkRememberCookie() e validateActiveSession()
//   UPDATE de sessoes_persistentes
//   a limpeza aleatória de 1% das requests
//   ConfigHelper::get('manutencao')
//   a query de marcasMenu (index.php:143)
//   View::shareMany() + SecurityHelper::generateCsrf()
//
// São 4-6 queries e um punhado de headers de CSP que não fazem sentido em JSON.

// ── Marca o modo API ────────────────────────────────────────────────────────
// Lido por Session::validateFingerprint() para não destruir a sessão do app
// quando o User-Agent muda (core/Session.php).
define('APP_API', true);

// ── Headers ─────────────────────────────────────────────────────────────────
// Remove o que o PHP possa ter herdado; o Apache ainda pode injetar os dele
// via mod_headers, mas nenhum deles atrapalha uma resposta JSON.
header_remove('Content-Security-Policy');
header_remove('Cross-Origin-Opener-Policy');
header_remove('X-Powered-By');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Vary: Origin, Authorization');

// ── CORS ────────────────────────────────────────────────────────────────────
// React Native nativo não faz preflight — isto existe para o target web do
// Expo (npx expo start --web) e para depuração no navegador.
$origensPermitidas = array_filter(array_map('trim', explode(',', (string)getenv('APP_CORS_ORIGINS'))));
if (!$origensPermitidas) {
    $origensPermitidas = [
        'http://localhost:8081',   // Metro / Expo web
        'http://localhost:19006',  // Expo web (legado)
        'http://127.0.0.1:8081',
    ];
}

$origem = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origem !== '' && in_array($origem, $origensPermitidas, true)) {
    header('Access-Control-Allow-Origin: ' . $origem);
    header('Access-Control-Allow-Credentials: false');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-Idempotency-Key, X-App-Versao, X-HTTP-Method-Override');
    header('Access-Control-Max-Age: 86400');
}

// Preflight morre aqui, sem tocar em banco.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Sessão ──────────────────────────────────────────────────────────────────
// use_strict_mode DESLIGADO: com ele ligado (index.php:7), o PHP ignoraria o
// session id vindo de app_dispositivos por "não existir ainda" e geraria outro,
// quebrando a ponte de sessão em silêncio. É seguro aqui porque o id vem de um
// lookup autenticado no banco — nunca do cliente.
ini_set('session.use_strict_mode', 0);
// O app não usa cookies: o vínculo é o Bearer token.
ini_set('session.use_cookies', 0);
ini_set('session.use_only_cookies', 0);
ini_set('session.cache_limiter', '');
ini_set('session.gc_maxlifetime', 2592000); // 30 dias (o handler manda, mas alinha)

session_set_save_handler(new AppSessionHandler(), true);

// ── Erros ───────────────────────────────────────────────────────────────────
// Sem isto, uma exceção não tratada devolveria HTML (ou página em branco) para
// um cliente que só sabe interpretar JSON.
set_exception_handler(function (\Throwable $e): void {
    if (class_exists('LogService')) {
        LogService::error('Exceção não tratada na API do app', [
            'erro'    => $e->getMessage(),
            'arquivo' => $e->getFile() . ':' . $e->getLine(),
            'rota'    => $_SERVER['REQUEST_URI'] ?? '',
        ]);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    $erro = ['codigo' => 'erro_interno', 'mensagem' => 'Erro interno no servidor.'];
    if (APP_DEBUG) {
        $erro['debug'] = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    }

    echo json_encode(['ok' => false, 'erro' => $erro], JSON_UNESCAPED_UNICODE);
    exit;
});

// ── Manutenção ──────────────────────────────────────────────────────────────
// Responde JSON em vez da view HTML, para o app poder exibir a própria tela.
if (APP_ENV === 'production') {
    try {
        if (ConfigHelper::get('manutencao', false)) {
            http_response_code(503);
            header('Retry-After: 3600');
            echo json_encode([
                'ok'  => false,
                'erro' => [
                    'codigo'   => 'manutencao',
                    'mensagem' => 'Estamos em manutenção. Volte em instantes.',
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (\Throwable $e) { /* banco indisponível não deve mascarar o erro real */ }
}

// ── Higiene esporádica ──────────────────────────────────────────────────────
// 1% das requests, mesmo padrão do index.php:118.
if (random_int(1, 100) === 1) {
    try {
        (new AppTokenService())->limparExpirados();
    } catch (\Throwable $e) { /* silencioso */ }
}

// ── Despacho ────────────────────────────────────────────────────────────────
AppRouter::dispatch();
