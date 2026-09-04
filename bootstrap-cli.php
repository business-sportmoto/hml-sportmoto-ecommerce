<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// bootstrap-cli.php — inicialização compartilhada para CLI/cron
//
// Extrai APENAS o núcleo do index.php que um cron precisa:
// configs + vendor + autoloader. NÃO inclui sessão, cookies,
// headers HTTP nem gzip — isso é web-only e quebra/vaza em CLI.
//
// Colocar em: ROOT do projeto (mesmo nível do index.php).
// Se o index.php um dia extrair o autoloader para cá e passar
// a incluí-lo, os dois param de divergir — hoje o index tem
// paths duplicados (services/email repetido) que isto já limpa.
// ════════════════════════════════════════════════════════

// Guard: só CLI. Impede que uma requisição web acesse o arquivo direto.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// __DIR__ = raiz do projeto (ajuste se colocar em subpasta)
require_once __DIR__ . '/config/defines.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

require_once __DIR__ . '/vendor/autoload.php';

// Mesmo autoloader do index.php — fonte única (sem os paths
// duplicados que estão no index hoje)
spl_autoload_register(function (string $class): void {
    // Hardening: nome de classe não pode conter separador de path
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $class)) {
        return;
    }
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
        // Faltavam aqui, embora estejam no admin/index.php: qualquer CLI
        // que toque o orquestrador de IA (worker dos agentes de BI,
        // diagnóstico) morria com "class not found".
        ROOT_PATH . '/app/services/ia/',
        ROOT_PATH . '/app/services/ia/providers/',
    ];
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});