<?php
/**
 * Migra a chave do Gemini do .env para ia_provedores (cifrada) — rodar UMA vez.
 *
 * Uso:
 *   php cli/migrar-chave-gemini.php            (mostra o que faria)
 *   php cli/migrar-chave-gemini.php --aplicar  (grava e ativa o provedor)
 *
 * Alternativa sem CLI: colar a chave direto em /admin/ia/config → Gemini,
 * como foi feito com OpenAI e Replicate. O efeito é o mesmo.
 *
 * ATENÇÃO ao roteiro original do pacote, que manda remover as constantes
 * GEMINI_* do .env e apagar app/services/GeminiService.php depois de migrar:
 * NESTE projeto isso quebra duas funcionalidades. GeminiQAService (perguntas
 * de produto) e ReviewSummaryService (resumo de avaliações) ainda usam o
 * GeminiService legado, que lê GEMINI_API_KEY e GEMINI_MODEL do .env. A
 * migração aqui COPIA a chave para o cofre da Central de IA; não remove nada.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

/* Mesma cadeia do ia-worker: defines -> config -> database -> autoload.
   O bootstrap-cli.php do projeto NÃO registra app/services/ia/, então o
   autoloader vai aqui explícito. */
require __DIR__ . '/../config/defines.php';
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

spl_autoload_register(function (string $classe): void {
    $pastas = [
        ROOT_PATH . '/core/',
        ROOT_PATH . '/app/models/',
        ROOT_PATH . '/app/helpers/',
        ROOT_PATH . '/app/services/',
        ROOT_PATH . '/app/services/ia/',
        ROOT_PATH . '/app/services/ia/providers/',
    ];
    foreach ($pastas as $pasta) {
        $arquivo = $pasta . $classe . '.php';
        if (is_file($arquivo)) {
            require_once $arquivo;
            return;
        }
    }
});

$aplicar = in_array('--aplicar', $argv, true);

echo "== Migração da chave Gemini ==\n";
echo $aplicar ? "Modo: APLICAR\n\n" : "Modo: simulação (use --aplicar para gravar)\n\n";

if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
    exit("Nada a migrar: GEMINI_API_KEY não está definida.\n" .
         "Se preferir, cole a chave direto em /admin/ia/config.\n");
}

$db = Database::getInstance()->getConnection();
$id = $db->query("SELECT id FROM ia_provedores WHERE codigo = 'gemini' LIMIT 1")->fetchColumn();

if ($id === false) {
    exit("Provedor 'gemini' não existe neste banco — as migrations da IA ainda não foram aplicadas aqui." . PHP_EOL
       . "Rode:  php cli/ia-migrar.php --aplicar" . PHP_EOL
       . "(sem argumentos ele só mostra o que falta)" . PHP_EOL);
}

$last4 = IACriptoService::last4((string) GEMINI_API_KEY);
echo "Provedor gemini: id {$id}\n";
echo "Chave encontrada no .env: •••• {$last4}\n";

if (!$aplicar) {
    echo "\nNada foi gravado. Rode de novo com --aplicar para:\n";
    echo "  1. cifrar a chave em ia_provedores.api_key_enc\n";
    echo "  2. ativar o provedor gemini\n";
    exit(0);
}

if (!(new IAProvedor())->definirChave((int) $id, (string) GEMINI_API_KEY)) {
    exit("Falha ao gravar a chave cifrada.\n");
}

$stmt = $db->prepare('UPDATE ia_provedores SET ativo = 1 WHERE id = ?');
$stmt->execute([(int) $id]);

LogService::audit('ia_provedor_chave_alterada', [
    'provedor_id' => (int) $id,
    'codigo'      => 'gemini',
    'last4'       => $last4,
    'origem'      => 'cli/migrar-chave-gemini.php',
]);

echo "\nChave gravada cifrada em ia_provedores e provedor ATIVADO.\n";
echo "Próximos passos:\n";
echo "  1. /admin/ia/config → Gemini → ⚡ Testar\n";
echo "  2. abrir um produto no admin e clicar em Gerar SEO\n";
echo "  3. conferir a geração em /admin/ia/historico (tipo 'Pacote SEO')\n";
echo "\nNÃO remova GEMINI_* do .env: GeminiQAService e ReviewSummaryService\n";
echo "ainda dependem dessas constantes.\n";
