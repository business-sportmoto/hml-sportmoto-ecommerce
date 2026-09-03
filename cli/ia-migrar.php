<?php
/**
 * Aplica as migrations da Central de Marketing IA, na ordem, só as que faltam.
 *
 * Uso:
 *   php cli/ia-migrar.php                     (mostra o estado, não grava nada)
 *   php cli/ia-migrar.php --aplicar           (aplica as pendentes, em ordem)
 *   php cli/ia-migrar.php --banco=outro_db    (mira outro banco do mesmo host)
 *
 * Por que existe:
 *   As 6 migrations do módulo vinham só dentro do pacote de instalação
 *   (admin/views/ia/completo/.../sql/), fora do sql/ do projeto — em qualquer
 *   ambiente que não fosse o dev elas simplesmente não existiam. Agora moram
 *   em sql/ia/ e este script sabe dizer o que já foi aplicado.
 *
 *   Nenhuma delas é idempotente (os ALTERs quebram na segunda execução), por
 *   isso cada uma tem um DETECTOR: uma coluna, um valor de ENUM ou uma tabela
 *   que só existe depois dela. O script só executa o que o detector diz que
 *   falta. Rodar duas vezes é seguro.
 *
 *   As instruções são executadas UMA A UMA (um splitter que respeita aspas —
 *   o seed do fase0 tem ponto-e-vírgula dentro dos prompts). Com exec() de
 *   arquivo inteiro o PDO só reporta o erro da primeira instrução; as demais
 *   falhariam em silêncio.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

require __DIR__ . '/../config/defines.php';
require __DIR__ . '/../config/config.php';

$opts    = getopt('', ['aplicar', 'banco::']);
$aplicar = array_key_exists('aplicar', $opts);
$banco   = isset($opts['banco']) && $opts['banco'] !== '' ? (string) $opts['banco'] : DB_NAME;

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $banco . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Não foi possível conectar em '{$banco}' @ " . DB_HOST . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/* ------------------------------------------------------------------ */
/* Detectores                                                           */
/* ------------------------------------------------------------------ */

$temTabela = function (string $t) use ($pdo, $banco): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $s->execute([$banco, $t]);
    return (int) $s->fetchColumn() > 0;
};
$temColuna = function (string $t, string $c) use ($pdo, $banco): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
    $s->execute([$banco, $t, $c]);
    return (int) $s->fetchColumn() > 0;
};
$enumTem = function (string $t, string $c, string $valor) use ($pdo, $banco): bool {
    $s = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
    $s->execute([$banco, $t, $c]);
    return str_contains((string) $s->fetchColumn(), "'{$valor}'");
};

/* Ordem importa: cada uma pressupõe a anterior. */
$migrations = [
    ['2026-07-02_ia_fase0.sql',      'Fase 0 — 13 tabelas + seeds',            fn () => $temTabela('ia_provedores')],
    ['2026-07-15_ia_fase2a.sql',     'Fase 2A — imagem assíncrona',            fn () => $enumTem('ia_roteamento_log', 'resultado', 'aguardando')],
    ['2026-07-16_ia_fase2b.sql',     'Fase 2B — recorte com cache',            fn () => $temColuna('ia_recortes_produto', 'geracao_id')],
    ['2026-07-16_ia_gemini_seo.sql', 'Gemini + SEO — provedor e tipo seo_pacote', fn () => $temColuna('ia_tipos_conteudo', 'saida')],
    ['2026-07-20_ia_fase2c.sql',     'Fase 2C — compositor de banners',        fn () => $temColuna('ia_geracoes', 'etapa')],
    ['2026-07-20_ia_fase3a.sql',     'Fase 3A — motor de campanhas',           fn () => $temTabela('ia_campanha_tipos')],
    // Detector: o gemini-3-flash (que não existe na API) já saiu da cadeia.
    // Os UPDATEs são idempotentes, então um falso "pendente" só repete no-ops.
    ['2026-09-03_ia_catalogo_ajustes.sql', 'Ajustes de catálogo — pino do SEO, timeouts, referência', function () use ($pdo, $temTabela) {
        if (!$temTabela('ia_modelos')) { return false; }
        $ativo = $pdo->query("SELECT ativo FROM ia_modelos WHERE codigo_modelo = 'gemini-3-flash' LIMIT 1")->fetchColumn();
        return $ativo === false || (int) $ativo === 0;
    }],
    ['2026-09-03_ia_provedor_claude.sql', 'Provedor Anthropic Claude — 3 modelos de texto', function () use ($pdo, $temTabela) {
        if (!$temTabela('ia_provedores')) { return false; }
        return (int) $pdo->query("SELECT COUNT(*) FROM ia_provedores WHERE codigo = 'claude'")->fetchColumn() > 0;
    }],
];

/* ------------------------------------------------------------------ */
/* Splitter de instruções: respeita aspas e comentários                 */
/* ------------------------------------------------------------------ */

function instrucoesDe(string $sql): array
{
    $saida = [];
    $atual = '';
    $len   = strlen($sql);
    $i     = 0;

    while ($i < $len) {
        $ch = $sql[$i];

        // comentário de linha
        if ($ch === '-' && substr($sql, $i, 2) === '--') {
            while ($i < $len && $sql[$i] !== "\n") { $i++; }
            continue;
        }
        // comentário de bloco
        if ($ch === '/' && substr($sql, $i, 2) === '/*') {
            $fim = strpos($sql, '*/', $i + 2);
            $i   = ($fim === false) ? $len : $fim + 2;
            continue;
        }
        // string entre aspas simples ou duplas: copia até fechar, honrando \x e ''
        if ($ch === "'" || $ch === '"') {
            $q = $ch;
            $atual .= $ch;
            $i++;
            while ($i < $len) {
                $c = $sql[$i];
                $atual .= $c;
                if ($c === '\\' && $i + 1 < $len) { $atual .= $sql[$i + 1]; $i += 2; continue; }
                if ($c === $q) {
                    if ($i + 1 < $len && $sql[$i + 1] === $q) { $atual .= $q; $i += 2; continue; } // '' escapado
                    $i++;
                    break;
                }
                $i++;
            }
            continue;
        }
        if ($ch === ';') {
            $t = trim($atual);
            if ($t !== '') { $saida[] = $t; }
            $atual = '';
            $i++;
            continue;
        }
        $atual .= $ch;
        $i++;
    }
    $t = trim($atual);
    if ($t !== '') { $saida[] = $t; }
    return $saida;
}

/* ------------------------------------------------------------------ */
/* Execução                                                             */
/* ------------------------------------------------------------------ */

echo "== Migrations da Central de IA ==\n";
echo "Banco: {$banco} @ " . DB_HOST . "\n";
echo $aplicar ? "Modo: APLICAR\n\n" : "Modo: simulação (use --aplicar para gravar)\n\n";

$dir       = dirname(__DIR__) . '/sql/ia/';
$pendentes = [];

foreach ($migrations as [$arquivo, $descricao, $detector]) {
    $ok = $detector();
    printf("  %-8s %-32s %s\n", $ok ? 'aplicada' : 'PENDENTE', $arquivo, $descricao);
    if (!$ok) { $pendentes[] = [$arquivo, $descricao]; }
}

if (empty($pendentes)) {
    echo "\nTudo aplicado. Nada a fazer.\n";
    exit(0);
}

if (!$aplicar) {
    echo "\n" . count($pendentes) . " pendente(s). Rode com --aplicar para executar, nesta ordem.\n";
    exit(0);
}

echo "\n";
foreach ($pendentes as [$arquivo, $descricao]) {
    $caminho = $dir . $arquivo;
    if (!is_file($caminho)) {
        fwrite(STDERR, "ERRO: {$caminho} não encontrado.\n");
        exit(1);
    }

    $instrucoes = instrucoesDe((string) file_get_contents($caminho));
    echo "-> {$arquivo} (" . count($instrucoes) . " instruções)\n";

    foreach ($instrucoes as $n => $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            fwrite(STDERR, "\nERRO na instrução " . ($n + 1) . " de {$arquivo}:\n");
            fwrite(STDERR, '   ' . mb_substr(preg_replace('/\s+/', ' ', $sql), 0, 160) . "...\n");
            fwrite(STDERR, '   ' . $e->getMessage() . "\n");
            fwrite(STDERR, "\nParado aqui. As instruções anteriores DESTE arquivo já valeram (DDL não tem rollback);\n");
            fwrite(STDERR, "corrija a causa e rode de novo — o detector decide o que ainda falta.\n");
            exit(1);
        }
    }
    echo "   ok\n";
}

echo "\nConcluído. Rode de novo sem --aplicar para conferir: tudo deve aparecer como 'aplicada'.\n";
