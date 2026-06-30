<?php
/**
 * cli/whatsapp-diagnostico.php
 *
 * Script de diagnóstico para validar a integração DataCrazy ANTES de usar
 * em produção. Roda no terminal:
 *
 *   php cli/whatsapp-diagnostico.php
 *   php cli/whatsapp-diagnostico.php 5547999998888   (testa um número real)
 *
 * Verifica:
 *   1. Configuração presente
 *   2. Conexão com a API
 *   3. Instância ativa
 *   4. (opcional) Busca de conversa por número
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Só roda em CLI.\n"); exit(1); }

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/defines.php';
require_once $ROOT . '/config/config.php';
require_once $ROOT . '/config/database.php';
if (is_file($ROOT . '/vendor/autoload.php')) require_once $ROOT . '/vendor/autoload.php';

spl_autoload_register(function ($c) use ($ROOT) {
    foreach ([
        '/core/', '/app/controllers/', '/app/models/', '/app/helpers/',
        '/app/services/', '/app/services/email/', '/app/services/email/providers/',
    ] as $p) {
        $f = $ROOT . $p . $c . '.php';
        if (is_file($f)) { require_once $f; return; }
    }
});

echo "═══════════════════════════════════════════\n";
echo " Diagnóstico WhatsApp / DataCrazy\n";
echo "═══════════════════════════════════════════\n\n";

// 1. Config
echo "[1] Verificando configuração...\n";
try {
    $dc = new DataCrazyService();
    echo "    ✔ Credenciais carregadas\n\n";
} catch (Throwable $e) {
    echo "    ✗ ERRO: " . $e->getMessage() . "\n";
    echo "    Configure DATACRAZY_API_KEY e DATACRAZY_INSTANCE_ID no .env ou config.php\n";
    exit(1);
}

// 2 + 3. Conexão e instância
echo "[2] Testando conexão com a API...\n";
$r = $dc->testarConexao();
if ($r['ok']) {
    echo "    ✔ " . $r['mensagem'] . "\n";
    if (!empty($r['instancia']['name'])) {
        echo "    Instância: " . $r['instancia']['name'] . "\n";
    }
    echo "\n";
} else {
    echo "    ✗ " . $r['mensagem'] . "\n\n";
    exit(1);
}

// 4. Teste de número (opcional)
$numeroTeste = $argv[1] ?? null;
if ($numeroTeste) {
    echo "[3] Testando número: $numeroTeste\n";
    $norm = $dc->normalizarTelefone($numeroTeste);
    echo "    Normalizado: " . ($norm ?: 'INVÁLIDO') . "\n";
    if ($norm) {
        $conv = $dc->buscarConversaPorTelefone($norm);
        if ($conv) {
            echo "    ✔ Conversa encontrada (ID: {$conv['id']})\n";
            echo "    Contato: " . ($conv['contact']['name'] ?? '—') . "\n";
        } else {
            echo "    ⚠ Nenhuma conversa aberta com esse número.\n";
            echo "    (Uma mensagem criaria um lead e aguardaria contato)\n";
        }
    }
    echo "\n";
}

echo "═══════════════════════════════════════════\n";
echo " Diagnóstico concluído. Tudo pronto! ✔\n";
echo "═══════════════════════════════════════════\n";
