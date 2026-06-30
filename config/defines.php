<?php
// config/defines.php
// Define caminhos absolutos para evitar problemas de inclusão relativa

define('ROOT_PATH',    dirname(__DIR__));           // raiz do projeto
define('APP_PATH',     ROOT_PATH . '/app');
define('VIEW_PATH',    ROOT_PATH . '/views');
define('ADMIN_PATH',   ROOT_PATH . '/admin');
define('ASSET_PATH',   ROOT_PATH . '/assets');
define('UPLOAD_PATH',  ROOT_PATH . '/uploads');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('VENDOR_PATH',  ROOT_PATH . '/vendor');


// Token opcional — aumenta rate limit do fipe.parallelum
// Obtenha em: https://fipe.online/pricing
define('FIPE_TOKEN', $_ENV['FIPE_TOKEN'] ?? '');