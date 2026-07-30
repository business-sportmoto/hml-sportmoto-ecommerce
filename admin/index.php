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
        ROOT_PATH . '/app/services/payment/',
        ROOT_PATH . '/admin/controllers/',
    ];
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) { require_once $file; return; }
    }
});
// var_dump(ROOT_PATH);
// $svc = new EmailMarketingService();

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
    $ok = (new AdminTokenService())->revalidarRequest(Session::get('admin_user_id'));
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


