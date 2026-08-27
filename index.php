<?php
// index.php — bootstrap final com segurança reforçada

// Antes de qualquer output
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
ini_set('expose_php', 0);


// Remove header X-Powered-By
header_remove('X-Powered-By');

require_once __DIR__ . '/config/defines.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

require 'vendor/autoload.php';

// Autoloader
spl_autoload_register(function (string $class): void {
    $paths = [
        ROOT_PATH . '/core/',
        ROOT_PATH . '/app/controllers/',
        ROOT_PATH . '/app/models/',
        ROOT_PATH . '/app/helpers/',
        ROOT_PATH . '/app/services/',
        ROOT_PATH . '/app/services/payment/',
        ROOT_PATH . '/app/services/payment/adquirentes/',
        ROOT_PATH . '/app/services/payment/antifraude/',
        ROOT_PATH . '/app/services/email/',
        ROOT_PATH . '/app/services/email/providers/',        
        ROOT_PATH . '/app/services/email/',
        ROOT_PATH . '/app/services/email/providers/',       
        ROOT_PATH . '/app/services/sms/',
        ROOT_PATH . '/app/services/sms/providers/',
        ROOT_PATH . '/app/services/logistica/',
        ROOT_PATH . '/app/services/logistica/transportadoras/',
        ROOT_PATH . '/app/services/conversion/',
        ROOT_PATH . '/app/services/app/',
        ROOT_PATH . '/app/presenters/',
    ];
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
    // Descomente para debug temporário:
    // throw new RuntimeException("Classe '{$class}' não encontrada em: " . implode(', ', $paths));
});

// ── Short-circuit da API do app mobile ────────────────────────
// Requests em /api/app/* não precisam de nada do bootstrap web (sessão com
// cookie, remember-me, validação de sessão remota, query de marcasMenu,
// View::share, CSRF) e não devem receber headers de CSP/X-Frame.
// Sai daqui direto para AppRouter::dispatch().
$__uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (str_starts_with($__uri, '/api/app/')) {
    require ROOT_PATH . '/config/api-app-bootstrap.php';
    exit;
}

// Ativa headers de segurança
SecurityHelper::setSecurityHeaders();

// Compressão gzip em produção
if (!APP_DEBUG) {
    PerformanceHelper::enableGzip();
}

// Inicia sessão segura
Session::start();
AuthHelper::enforceEmailVerificado();
// Verifica cookie de "lembrar login"
if (!Session::isClienteLogado() && !empty($_COOKIE['ec_remember'])) {
    require_once ROOT_PATH . '/app/services/TokenService.php';
    require_once ROOT_PATH . '/app/models/SessionManager.php';
    TokenService::checkRememberCookie($_COOKIE['ec_remember']);
}

$db = Database::getInstance()->getConnection();

// Atualiza última atividade da sessão a cada request
// No bloco de atualização de atividade no index.php
// Substituir o bloco de verificação de sessão no index.php

// ── Validação de sessão (logout remoto) ───────────────────
// A cada 60s confere se a linha em sessoes_persistentes ainda existe.
// Usa _sessao_persistente_id (gravado no login) — entende o formato
// novo do cookie (familia:token) e a rotação do Sprint 3.
if (Session::isClienteLogado()) {
    try {
        $ultimaVerificacao = Session::get('_session_verified_at', 0);
        if ((time() - $ultimaVerificacao) > 60) {
            TokenService::validateActiveSession();
            // Se ainda logado após a validação, marca o timestamp
            if (Session::isClienteLogado()) {
                Session::set('_session_verified_at', time());
            } else {
                // Sessão revogada remotamente
                Session::flash('warning',
                    'Sua sessão foi encerrada. Faça login novamente.'
                );
                header('Location: ' . BASE_URL . '/login');
                exit;
            }
        }

        // ── Atualiza última atividade a cada 5 minutos ────────
        $ultimoTouch = Session::get('_last_activity_touch', 0);
        if ((time() - $ultimoTouch) > 300) {
            $sessaoId = Session::get('_sessao_persistente_id');
            if ($sessaoId) {
                $db->prepare(
                    "UPDATE sessoes_persistentes
                     SET ultima_atividade = NOW()
                     WHERE id = ?"
                )->execute([(int)$sessaoId]);
            }
            Session::set('_last_activity_touch', time());
        }
    } catch (Exception) {
        // Silencia erros de DB para não quebrar a requisição
    }
}

// ClickCaptureService::capturar();

// WhatsappService::sendSimples('5551994214617', 'Robert', 'tipo', "Mensagem *negrito*");

// Adicionar no index.php após os blocos acima
// Limpa sessões expiradas ocasionalmente (1% das requests)
if (rand(1, 100) === 1) {
    try {
        Database::getInstance()->getConnection()
            ->exec("DELETE FROM sessoes_persistentes WHERE expira_em < NOW()");
        
        (new CartCompartilhado())->limparExpiradosSemConversao();
    } catch (Exception) {}
}

// Modo de manutenção
if (APP_ENV === 'production') {
    try {
        $emManutencao = ConfigHelper::get('manutencao', false);
        if ($emManutencao && !Session::isAdminLogado()) {
            http_response_code(503);
            header('Retry-After: 3600');
            include ROOT_PATH . '/views/errors/maintenance.php';
            exit;
        }
    } catch (Exception) {
        // Se o DB ainda não estiver disponível, ignora
    }
}
// $db = Database::getInstance()->getConnection();
// $marcasMenu = CacheHelper::delete('menu_marcas');
$marcasMenu = CacheHelper::get('menu_marcas');
if (!$marcasMenu) {
    $stmt = $db->query(
        "SELECT m.id, m.nome, m.slug, m.logo, m.bg_cor,
                COUNT(p.id) AS total_produtos
         FROM marcas m
         LEFT JOIN produtos p ON p.marca_id = m.id
           AND p.ativo = 1 AND p.deleted_at IS NULL
         WHERE m.ativo = 1
         GROUP BY m.id
        --  HAVING total_produtos > 0
         ORDER BY total_produtos DESC, m.nome ASC
         LIMIT 12"
    );
    $marcasMenu = $stmt->fetchAll();
    CacheHelper::set('menu_marcas', $marcasMenu, 3600);
}
View::share('marcasMenu', $marcasMenu);

// Injeta dados globais para as views
try {
    View::shareMany([
        'config' => [
            'nome'     => ConfigHelper::get('site_nome', 'Sportmoto'),
            'logo'     => ConfigHelper::get('site_logo'),
            'telefone' => ConfigHelper::get('site_telefone'),
            'email'    => ConfigHelper::get('site_email'),
        ],
        'cliente_logado' => Session::isClienteLogado(),
        'cliente_nome'   => Session::get('cliente_nome'),
        'carrinho_count' => Session::getCarrinhoCount(),
        'csrf_token'     => SecurityHelper::generateCsrf(),
    ]);
} catch (Exception) {
    View::shareMany([
        'config'         => ['nome' => 'Loja', 'logo' => null, 'telefone' => null, 'email' => null],
        'cliente_logado' => false,
        'cliente_nome'   => null,
        'carrinho_count' => 0,
        'csrf_token'     => '',
    ]);
}


// Despacha
Router::dispatch();