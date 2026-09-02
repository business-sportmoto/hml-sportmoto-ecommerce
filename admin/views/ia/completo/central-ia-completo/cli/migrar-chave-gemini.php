<?php
/**
 * Migra a chave do Gemini do .env para ia_provedores (cifrada) — rodar UMA vez.
 *
 * Uso (produção/homolog):
 *   /usr/local/lsws/lsphp82/bin/php cli/migrar-chave-gemini.php
 * Laragon:
 *   php cli/migrar-chave-gemini.php
 *
 * Depois de rodar: teste na tela (/admin/ia/config → Gemini → ⚡ Testar) e
 * REMOVA GEMINI_API_KEY / GEMINI_MODEL / GEMINI_FALLBACK_MODEL do .env.
 * Alternativa sem CLI: cole a chave direto na tela de config, como fez
 * com OpenAI e Replicate — o efeito é o mesmo.
 */

/* AJUSTE: espelhe exatamente o cabeçalho de includes do email-worker.php */
require __DIR__ . '/../defines.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/../database.php';
require __DIR__ . '/../vendor/autoload.php';

echo "== Migração da chave Gemini ==\n";

if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
    exit("Nada a migrar: GEMINI_API_KEY não está definida no config/.env.\n" .
         "Se preferir, cole a chave direto na tela /admin/ia/config.\n");
}

$db = Database::getInstance()->getConnection();
$id = $db->query("SELECT id FROM ia_provedores WHERE codigo = 'gemini' LIMIT 1")->fetchColumn();

if ($id === false) {
    exit("Provedor 'gemini' não existe — rode antes sql/2026-07-16_ia_gemini_seo.sql.\n");
}

(new IAProvedor())->definirChave((int) $id, (string) GEMINI_API_KEY);
$db->exec('UPDATE ia_provedores SET ativo = 1 WHERE id = ' . (int) $id);

echo "Chave gravada cifrada em ia_provedores e provedor ATIVADO.\n";

foreach (['GEMINI_MODEL', 'GEMINI_FALLBACK_MODEL'] as $c) {
    if (defined($c)) {
        echo "Registro: {$c} = " . constant($c) . " (o modelo agora é gerenciado pela tela de config)\n";
    }
}

echo "Próximos passos: ⚡ Testar na tela de config, gerar um SEO de teste e\n" .
     "remover as constantes GEMINI_* do .env. O GeminiService.php pode ser apagado.\n";
