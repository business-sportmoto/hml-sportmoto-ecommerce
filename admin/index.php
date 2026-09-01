<?php
// admin/index.php — roteador isolado do painel administrativo

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict'); // mais restrito no admin
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_secure', 1);


require_once dirname(__DIR__) . '/config/defines.php';
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

require dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    $paths = [
        ROOT_PATH . '/core/',
        ROOT_PATH . '/app/models/',
        ROOT_PATH . '/app/helpers/',
        ROOT_PATH . '/app/services/',
        ROOT_PATH . '/app/services/email/',        
        ROOT_PATH . '/app/services/email/providers/',
        ROOT_PATH . '/app/services/ia/',
        ROOT_PATH . '/app/services/ia/providers/',
        ROOT_PATH . '/app/services/logistica/',
        ROOT_PATH . '/app/services/logistica/transportadoras/',
        ROOT_PATH . '/app/services/payment/',
        // Estes dois faltavam aqui, embora estejam no index.php da loja e no
        // bootstrap-cli. Resultado: adapter e antifraude carregavam no
        // checkout e no CLI, mas o painel morria com "class not found" ao
        // consultar uma transacao.
        ROOT_PATH . '/app/services/payment/adquirentes/',
        ROOT_PATH . '/app/services/payment/antifraude/',
        ROOT_PATH . '/admin/controllers/',
    ];
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) { require_once $file; return; }
    }
});
// var_dump(ROOT_PATH);
// $svc = new EmailMarketingService();

// Antes de qualquer saída. Em servidor nginx o .htaccess é ignorado, então sem
// esta linha o painel depende inteiramente da configuração do servidor — e foi
// o que segurou o microfone da gravação de voz em homologação.
SecurityHelper::setAdminHeaders();

Session::start();
// Session::logoutAdmin();
// var_dump($_SESSION); exit;

View::setBasePath(ROOT_PATH . '/admin/views');
View::setAssetPath(BASE_URL . '/admin/assets');

if (!Session::isAdminLogado()) {
    $dados = (new AdminTokenService())->validarCookie();
    if ($dados) {
        Session::loginAdmin($dados['user'], $dados['admin']);
    }
}

if (Session::isAdminLogado()) {
    $uid = (int) Session::get('admin_user_id');
    if ($uid <= 0) {                        // sessão antiga / shape ruim
        $uid = AuthHelper::usuarioId();     // bridge admin_id → usuarios.id
        if ($uid > 0) Session::set('admin_user_id', $uid);
    }
    $ok = $uid > 0 && (new AdminTokenService())->revalidarRequest($uid);
    if (!$ok) {
        Session::logoutAdmin();
        (new AdminTokenService())->revogarAtual();
        // redirect pro login
    }
}

// Dados globais compartilhados em todas as views do admin
if (Session::isAdminLogado()) {
    View::share('admin_nome',  Session::get('admin_nome'));
    View::share('admin_nivel', Session::get('admin_nivel'));
}
View::share('csrf_token', SecurityHelper::generateCsrf());

require_once ROOT_PATH . '/admin/config/routes.php';
AdminRouter::dispatch();


