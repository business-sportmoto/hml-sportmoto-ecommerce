<?php
/**
 * cli/whatsapp-diagnostico-meta.php
 *
 * Valida a integração com a Meta Cloud API e lista templates aprovados.
 *
 *   php cli/whatsapp-diagnostico-meta.php
 *   php cli/whatsapp-diagnostico-meta.php aprovados   (só status APPROVED)
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Só roda em CLI.\n"); exit(1); }

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/defines.php';
require_once $ROOT . '/config/config.php';
require_once $ROOT . '/config/database.php';
if (is_file($ROOT . '/vendor/autoload.php')) require_once $ROOT . '/vendor/autoload.php';
spl_autoload_register(function ($c) use ($ROOT) {
    foreach (['/core/','/app/models/','/app/helpers/','/app/services/'] as $p) {
        $f = $ROOT . $p . $c . '.php';
        if (is_file($f)) { require_once $f; return; }
    }
});

echo "═══════════════════════════════════════════\n";
echo " Diagnóstico Meta Cloud API\n";
echo "═══════════════════════════════════════════\n\n";

echo "[1] Verificando configuração...\n";
try {
    $meta = new MetaCloudService();
    echo "    ✔ Credenciais carregadas\n\n";
} catch (Throwable $e) {
    echo "    ✗ ERRO: " . $e->getMessage() . "\n";
    echo "    Configure META_PHONE_NUMBER_ID e META_CLOUD_API_TOKEN no .env\n";
    exit(1);
}

echo "[2] Testando conexão...\n";
$r = $meta->testarConexao();
if ($r['ok']) {
    echo "    ✔ Número: " . $r['numero'] . "\n";
    echo "    ✔ Nome: "   . $r['nome']   . "\n";
    echo "    ✔ Qualidade: " . $r['qualidade'] . "\n\n";
} else {
    echo "    ✗ " . ($r['mensagem'] ?? 'Erro desconhecido') . "\n";
    exit(1);
}

echo "[3] Listando templates...\n";
$filtro = isset($argv[1]) && $argv[1] === 'aprovados' ? 'APPROVED' : null;
try {
    $res = $meta->listarTemplates($filtro);
    $templates = $res['data'] ?? [];
    if (empty($templates)) {
        echo "    Nenhum template encontrado" . ($filtro ? " com status $filtro" : "") . ".\n";
    } else {
        printf("    %-35s %-12s %s\n", 'NOME', 'STATUS', 'IDIOMA');
        echo "    " . str_repeat('─', 60) . "\n";
        foreach ($templates as $t) {
            $langs = implode(', ', array_column($t['components'] ?? [], 'language') ?: [$t['language'] ?? '?']);
            printf("    %-35s %-12s %s\n",
                $t['name'] ?? '?',
                $t['status'] ?? '?',
                $t['language'] ?? $langs
            );
        }
    }
} catch (Throwable $e) {
    echo "    ✗ " . $e->getMessage() . "\n";
}

echo "\n═══════════════════════════════════════════\n";
echo " Diagnóstico concluído.\n";
echo "═══════════════════════════════════════════\n";
