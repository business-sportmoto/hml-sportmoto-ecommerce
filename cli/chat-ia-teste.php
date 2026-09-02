<?php
/**
 * cli/chat-ia-teste.php
 *
 * Testa o agente de IA do atendimento SEM Instagram, SEM fluxo e SEM automação.
 * Faz a chamada de verdade ao modelo e mostra tudo que aconteceu no caminho.
 *
 * Existe porque "a IA não respondeu" tem meia dúzia de causas em camadas
 * diferentes — modelo desligado, chave do provedor, teto do dia, produto
 * inativo, filtro barato, número inventado — e no fluxo todas terminam igual:
 * a porta `nao_sabe`, sem dizer qual foi.
 *
 * Uso:
 *   php cli/chat-ia-teste.php --produto=482 --pergunta="quanto custa?"
 *   php cli/chat-ia-teste.php --sku=152 --pergunta="serve na fazer 250?" --publico
 *   php cli/chat-ia-teste.php --produto=482 --pergunta="preço" --campos=sem_preco
 *   php cli/chat-ia-teste.php --check          (só o diagnóstico, não gasta token)
 *
 * A chamada com --pergunta CONSOME cota e custa dinheiro, como qualquer
 * resposta real. O --check não chama modelo nenhum.
 */

$ROOT = dirname(__DIR__);
chdir($ROOT);
require $ROOT . '/config/defines.php';
require $ROOT . '/config/config.php';
require $ROOT . '/config/database.php';

spl_autoload_register(function (string $c) use ($ROOT): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $c)) return;
    foreach (['/core/', '/app/helpers/', '/app/services/',
              '/app/services/ia/', '/app/services/ia/providers/', '/app/models/'] as $p) {
        $f = $ROOT . $p . $c . '.php';
        if (is_file($f)) { require_once $f; return; }
    }
});

$db = Database::getInstance()->getConnection();

// ── Argumentos ───────────────────────────────────────────────────────────────
$arg = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_]+)(?:=(.*))?$/i', $a, $m)) $arg[$m[1]] = $m[2] ?? '1';
}
$produtoId = (int)($arg['produto'] ?? 0);
$sku       = trim((string)($arg['sku'] ?? ''));
$pergunta  = trim((string)($arg['pergunta'] ?? ''));
$campos    = (string)($arg['campos'] ?? 'todos');
$publico   = isset($arg['publico']);
$soCheck   = isset($arg['check']) || $pergunta === '';

// ── Formatação ───────────────────────────────────────────────────────────────
$cor = static function (string $t, string $c): string {
    if (DIRECTORY_SEPARATOR === '\\' && !getenv('ANSICON') && !getenv('WT_SESSION')) return $t;
    $cores = ['ok' => '32', 'ruim' => '31', 'aviso' => '33', 'fraco' => '90', 'forte' => '1'];
    return "\033[" . ($cores[$c] ?? '0') . "m" . $t . "\033[0m";
};
$titulo = static function (string $t) use ($cor): void {
    echo "\n" . $cor($t, 'forte') . "\n" . str_repeat('─', mb_strlen($t)) . "\n";
};
$item = static function (bool $ok, string $t, string $extra = '') use ($cor): void {
    echo '  ' . $cor($ok ? '✓' : '✗', $ok ? 'ok' : 'ruim') . ' ' . $t
       . ($extra !== '' ? ' ' . $cor($extra, 'fraco') : '') . "\n";
};

$problemas = [];

// ═════════════════════════════════════════════════════════════════════════════
$titulo('1. Camada de IA');

$provs = $db->query(
    "SELECT id, codigo, ativo, api_key_last4, LENGTH(api_key_enc) AS klen FROM ia_provedores"
)->fetchAll(PDO::FETCH_ASSOC);

$temProv = false;
foreach ($provs as $p) {
    $ok = (int)$p['ativo'] === 1 && (int)$p['klen'] > 0;
    if ($ok) $temProv = true;
    $item($ok, "provedor {$p['codigo']}",
        ((int)$p['ativo'] === 1 ? 'ativo' : 'DESLIGADO')
        . ' · ' . ((int)$p['klen'] > 0 ? 'chave …' . $p['api_key_last4'] : 'SEM CHAVE'));
}
if (!$temProv) $problemas[] = 'Nenhum provedor de IA ativo e com chave.';

$modelos = $db->query(
    "SELECT m.id, m.codigo_modelo, m.nome, m.prioridade, m.ativo, p.codigo AS prov, p.ativo AS prov_ativo
     FROM ia_modelos m JOIN ia_provedores p ON p.id = m.provedor_id
     WHERE m.capacidade = 'texto' ORDER BY m.prioridade"
)->fetchAll(PDO::FETCH_ASSOC);

$ativos = array_filter($modelos, fn($m) => (int)$m['ativo'] === 1 && (int)$m['prov_ativo'] === 1);
foreach ($modelos as $m) {
    $item((int)$m['ativo'] === 1, "modelo de texto {$m['codigo_modelo']}",
        ((int)$m['ativo'] === 1 ? 'ativo' : 'DESLIGADO') . " · prioridade {$m['prioridade']} · {$m['prov']}");
}
if (!$modelos) $problemas[] = 'Não há nenhum modelo de capacidade "texto" cadastrado.';
elseif (!$ativos) {
    $problemas[] = 'Todos os modelos de texto estão desligados — o agente não tem com o que responder. '
                 . 'Ative pelo menos um em ia_modelos (capacidade = texto).';
}

$tipo = $db->query(
    "SELECT id, codigo, ativo FROM ia_tipos_conteudo WHERE codigo = 'resposta_atendimento'"
)->fetch(PDO::FETCH_ASSOC);
$item((bool)$tipo, 'tipo de conteúdo resposta_atendimento',
    $tipo ? "#{$tipo['id']}" . ((int)$tipo['ativo'] === 1 ? '' : ' (INATIVO)') : 'AUSENTE — rode a migration');
if (!$tipo) $problemas[] = 'Falta a linha resposta_atendimento em ia_tipos_conteudo (sql/chat-ia-cupom-migration.sql).';

$orfaos = (int)$db->query("SELECT COUNT(*) FROM admins WHERE usuario_id IS NULL")->fetchColumn();
$temAutor = (int)$db->query(
    "SELECT COUNT(*) FROM admins a JOIN usuarios u ON u.id = a.usuario_id
     WHERE a.nivel = 'super' AND u.ativo = 1"
)->fetchColumn();
$item($temAutor > 0, 'autor para a linha de custo',
    $temAutor > 0 ? "$temAutor super ativo(s)" : 'nenhum super ativo — o INSERT em ia_geracoes falha');
if ($temAutor < 1) $problemas[] = 'Sem super ativo vinculado a usuário, a geração não consegue ser gravada.';
if ($orfaos > 0) echo '    ' . $cor("· $orfaos admin(s) sem usuario_id", 'aviso') . "\n";

// ═════════════════════════════════════════════════════════════════════════════
$titulo('2. Teto do dia');

$teto  = ChatConfig::int('ia_limite_dia', 500);
$hoje  = (int)$db->query(
    "SELECT COUNT(*) FROM ia_geracoes
     WHERE formato IN ('ig_comentario','ig_direct') AND criado_em >= CURDATE()"
)->fetchColumn();

$dentro = $teto === 0 || $hoje < $teto;
$item($dentro, 'cota do módulo',
    $teto === 0 ? "sem teto · $hoje uso(s) hoje" : "$hoje de $teto usados hoje");
if (!$dentro) $problemas[] = "Teto diário atingido ($hoje/$teto). O agente cala até amanhã ou até aumentar o limite.";

// ═════════════════════════════════════════════════════════════════════════════
$titulo('3. Produto');

$agente = new ChatIaAgenteService($db);

if ($sku !== '' && $produtoId < 1) {
    $st = $db->prepare("SELECT id FROM produtos WHERE sku_legado = :s LIMIT 1");
    $st->execute([':s' => $sku]);
    $produtoId = (int)$st->fetchColumn();
    if ($produtoId < 1) $problemas[] = "Nenhum produto com sku_legado = $sku.";
}

$ctx = null;
if ($produtoId > 0) {
    $p = $db->prepare("SELECT id, nome, ativo, sku_legado FROM produtos WHERE id = :i");
    $p->execute([':i' => $produtoId]);
    $prod = $p->fetch(PDO::FETCH_ASSOC);

    if (!$prod) {
        $item(false, "produto #$produtoId", 'não existe');
        $problemas[] = "Produto #$produtoId não existe.";
    } else {
        $item((int)$prod['ativo'] === 1, $prod['nome'],
            "#{$prod['id']}" . ($prod['sku_legado'] ? " · SKU {$prod['sku_legado']}" : '')
            . ((int)$prod['ativo'] === 1 ? '' : ' · INATIVO'));

        // Mesma tradução que o nó do fluxo faz com o preset do painel
        $lista = match ($campos) {
            'sem_preco' => ['nome', 'descricao', 'ficha', 'compatibilidade'],
            'so_ficha'  => ['nome', 'ficha', 'compatibilidade'],
            default     => ChatIaAgenteService::CAMPOS,
        };
        $ctx = $agente->contextoProduto($produtoId, $lista);

        if ($ctx === null) {
            $problemas[] = 'Produto inativo — contextoProduto() devolve null e o bloco cala.';
        } else {
            echo "\n  " . $cor('O que a IA enxerga (e só isso):', 'fraco') . "\n";
            foreach ($ctx as $k => $v) {
                $txt = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v;
                $txt = trim(preg_replace('/\s+/', ' ', $txt));
                if ($txt === '') { echo '    ' . $cor("$k: (vazio)", 'aviso') . "\n"; continue; }
                echo "    $k: " . mb_substr($txt, 0, 150) . (mb_strlen($txt) > 150 ? '…' : '') . "\n";
            }
            foreach (['preco', 'descricao', 'ficha'] as $c) {
                if (in_array($c, $lista, true) && empty($ctx[$c])) {
                    echo '  ' . $cor("· sem $c no cadastro — pergunta sobre isso cai em NAO_SEI", 'aviso') . "\n";
                }
            }
        }
    }
} else {
    echo '  ' . $cor('(nenhum produto informado — use --produto=ID ou --sku=CODIGO)', 'fraco') . "\n";
}

// ═════════════════════════════════════════════════════════════════════════════
if ($problemas) {
    $titulo('Impedimentos');
    foreach ($problemas as $x) echo '  ' . $cor('✗ ' . $x, 'ruim') . "\n";
    echo "\n";
    if ($soCheck) exit(1);
    echo $cor('Corrija os itens acima — a resposta abaixo provavelmente vai falhar.', 'aviso') . "\n";
}

if ($soCheck) {
    $titulo('Sem pergunta, sem chamada');
    echo "  Passe --pergunta=\"...\" para o agente responder de verdade.\n\n";
    exit($problemas ? 1 : 0);
}
if ($ctx === null) {
    echo "\n" . $cor('Sem contexto de produto não há o que perguntar.', 'ruim') . "\n\n";
    exit(1);
}

// ═════════════════════════════════════════════════════════════════════════════
$titulo('4. Resposta');
echo '  pergunta: ' . $cor($pergunta, 'forte') . "\n";
echo '  público:  ' . ($publico ? 'sim — também responde o comentário' : 'não — só o direct') . "\n\n";

$antes  = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM ia_geracoes")->fetchColumn();
$t0     = microtime(true);
$r      = $agente->responder($pergunta, $ctx, ['publico' => $publico, 'produto_id' => $produtoId]);
$tempo  = round((microtime(true) - $t0) * 1000);

if ($r['ok']) {
    echo '  ' . $cor('DIRECT', 'ok') . "\n";
    foreach (explode("\n", $r['direct']) as $l) echo '    ' . $l . "\n";
    if ($publico) {
        echo "\n  " . $cor('COMENTÁRIO PÚBLICO', 'ok') . "\n";
        echo '    ' . ($r['publico'] ?? $cor('(não gerado)', 'aviso')) . "\n";
    }
} else {
    echo '  ' . $cor('SAIU PELA PORTA "não soube responder"', 'ruim') . "\n";
    echo '  motivo: ' . $cor($r['motivo'], 'aviso') . "\n\n";
    echo "  " . $cor(match (true) {
        str_contains($r['motivo'], 'sem pergunta')   => 'O filtro barato barrou: sem "?" nem palavra interrogativa e com menos de 12 letras.',
        str_contains($r['motivo'], 'teto')           => 'Cota do dia. Ajuste em /admin/chat/config.',
        str_contains($r['motivo'], 'não respondeu')  => 'O modelo não devolveu texto — veja a linha de erro abaixo.',
        str_contains($r['motivo'], 'fora do contexto') => 'O modelo inventou um número. A resposta foi descartada de propósito.',
        str_contains($r['motivo'], 'fora do que')    => 'A pergunta pede algo que não está no cadastro do produto.',
        default => 'Veja a linha de geração abaixo.',
    }, 'fraco') . "\n";
}

// ── O rastro em ia_geracoes ──────────────────────────────────────────────────
$g = $db->query(
    "SELECT id, status, modelo_codigo, provedor_codigo, tokens_in, tokens_out,
            custo_real_usd, tempo_ms, erro
     FROM ia_geracoes WHERE id > $antes ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$titulo('5. O que foi gravado');
if (!$g) {
    echo '  ' . $cor('nenhuma linha em ia_geracoes', 'aviso')
       . ' — o agente nem chegou a chamar o modelo.' . "\n";
} else {
    foreach ($g as $x) {
        echo "  #{$x['id']} " . ($x['status'] === 'concluida' ? $cor('concluida', 'ok') : $cor($x['status'], 'ruim'))
           . ' · ' . ($x['provedor_codigo'] ?? '?') . '/' . ($x['modelo_codigo'] ?? '?')
           . " · {$x['tokens_in']}→{$x['tokens_out']} tokens"
           . ' · US$ ' . number_format((float)$x['custo_real_usd'], 6)
           . " · {$x['tempo_ms']}ms\n";
        if (!empty($x['erro'])) echo '      ' . $cor(mb_substr($x['erro'], 0, 300), 'ruim') . "\n";
    }
}
echo "\n  total: {$tempo}ms\n\n";

exit($r['ok'] ? 0 : 1);
