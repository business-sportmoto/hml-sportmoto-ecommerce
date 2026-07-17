<?php
// config/config.php
require_once __DIR__ . '/env.php';
// Carrega o .env da raiz do projeto
loadEnv(dirname(__DIR__) . '/.env');

define('SITE_NOME', 'Sportmoto');

define('SANDBOX', true); // Modo sandbox para testes (desativa exclusões permanentes, etc.)

define('REVIEWS_LIMIT_RATE', 30); // Máximo de avaliações por IP por hora (ajuste conforme necessário)

define('GEMINI_API_KEY', $_ENV['GEMINI_API_KEY'] ?? '');
define('GEMINI_MODEL',   'gemini-3-flash-preview');
// fallback barato/rápido, se quiser:
define('GEMINI_FALLBACK_MODEL', 'gemini-2.5-flash');

define('META_WABA_ID', getenv('META_WABA_ID'));
define('IA_CRYPTO_KEY', getenv('IA_CRYPTO_KEY')); 
define('IA_PRODUTO_IMG_BASE', '');

// ─── Modelos disponíveis ─────────────────────────────────────
// gemini-2.0-flash: Recomendado — rápido, gratuito no AI Studio
// gemini-2.5-flash: Mais capaz, pode ter custo
// gemini-2.5-pro: Mais avançado, pago

// ─── Ambiente ────────────────────────────────────────────────
define('APP_ENV', getenv('APP_ENV')); // 'production' em produção. e homologation em homologação
define('APP_DEBUG', APP_ENV === 'development');

// ─── Banco de dados ──────────────────────────────────────────
define('DB_HOST',    '127.0.0.1');
define('DB_PORT',    '3306');

if(APP_ENV == 'development'){ //Quando tiver no localhost
    define('BASE_URL',   getenv('DEV_BASE_URL'));
    define('DB_NAME',    getenv('DEV_DB_NAME'));
    define('DB_USER',    getenv('DEV_DB_USER'));
    define('DB_PASS',    '');
}elseif(APP_ENV == 'production'){ // //Site pronto
    define('BASE_URL',   getenv('PROD_BASE_URL'));
    define('DB_NAME',    getenv('PROD_DB_NAME'));
    define('DB_USER',    getenv('PROD_DB_USER'));
    define('DB_PASS',    '');
}elseif(APP_ENV == 'homologation'){ // Processo de teste 
    define('BASE_URL',   getenv('HML_BASE_URL'));
    define('DB_NAME',    getenv('HML_DB_NAME') );
    define('DB_USER',    getenv('HML_DB_USER') );
    define('DB_PASS',    getenv('HML_DB_PASS'));    
}

// ─── URL base ────────────────────────────────────────────────
// define('BASE_URL', 'http://localhost/ecommerce');

define('ADMIN_URL', BASE_URL . '/admin');
define('ASSET_URL', BASE_URL . '/assets');
define('UPLOAD_URL', BASE_URL . '/uploads');


define('DB_CHARSET', 'utf8mb4');

// ─── Sessão ──────────────────────────────────────────────────
define('SESSION_NAME',     'ec_session');
define('SESSION_LIFETIME', 86400 * 30); // 30 dias (lembrar login)
define('CSRF_TOKEN_NAME',  '_csrf_token');

// ─── Segurança ───────────────────────────────────────────────
define('PASSWORD_ALGO',   PASSWORD_ARGON2ID); // hash seguro
define('TOKEN_EXPIRY',    3600);              // 1h para tokens de e-mail/SMS
define('MAX_LOGIN_TRIES', 5);                 // bloqueio após N tentativas

// ─── Upload ──────────────────────────────────────────────────
define('UPLOAD_MAX_SIZE',  5 * 1024 * 1024);  // 5MB
define('ALLOWED_IMAGES',   ['jpg','jpeg','png','webp']);
define('IMG_PRODUCT_W',    800);
define('IMG_PRODUCT_H',    800);
define('IMG_THUMB_W',      400);
define('IMG_THUMB_H',      400);

// ─── Loja ────────────────────────────────────────────────────
define('ITEMS_PER_PAGE',  24);
// define('CURRENCY_SYMBOL', 'R$');
defined('CURRENCY_SYMBOL') || define('CURRENCY_SYMBOL', 'R$');
define('CURRENCY_CODE',   'BRL');

define('SLIDER_CARD_TIMER',   5000); // 5 segundos

// ─── E-mail ──────────────────────────────────────────────────
define('MAIL_HOST',     'smtp.seuprovedor.com');
define('MAIL_PORT',     587);
define('MAIL_USER',     'noreply@sualoja.com.br');
define('MAIL_PASS',     'senha_smtp');
define('MAIL_FROM',     'noreply@sualoja.com.br');
define('MAIL_FROM_NAME','Sportmoto');


// ─── Tratamento de erros ─────────────────────────────────────
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', STORAGE_PATH . '/logs/php_errors.log');
}

// Fuso horário padrão Brasil
date_default_timezone_set('America/Sao_Paulo');

// Adicionar em config/config.php

// ── Encriptação de documentos ────────────────────────────────
// IMPORTANTE: gere uma chave única para seu projeto:
// php -r "echo base64_encode(random_bytes(32));"
// NUNCA commite essa chave em repositórios. Use variável de ambiente em produção.
define('DOC_ENCRYPT_KEY', getenv('DOC_ENCRYPT_KEY') ?: 'TROQUE_ESTA_CHAVE_POR_UMA_GERADA');
define('DOC_ENCRYPT_ALGO', getenv('DOC_ENCRYPT_ALGO'));
define('DOC_MAX_SIZE',     10 * 1024 * 1024); // 10MB
define('DOC_ALLOWED_MIME', ['image/jpeg', 'image/png', 'image/webp']);
define('DOC_TOKEN_TTL',    1800); // 30 minutos para o link mobile


// configurações do site

define('CONF_BAR_VEICLE', true); // Exibir barra "Meu veículo" na página do produto
// define('CONF_VAR_VEICLE_ALL_PROD', false); // Exibir "Meu veículo" para todos os produtos, mesmo sem compatibilidade

define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');

define('EMAIL_MARKETING_KEY',getenv('MAILGUN_PUBLIC_API_KEY'));

// https://api.mailgun.net