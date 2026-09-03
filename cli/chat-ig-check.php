<?php
/**
 * cli/chat-ig-check.php
 *
 * A etapa ANTES do fluxo: o comentário chegou, e ele casou com alguma automação?
 *
 * Fecha a última parte da corrente. Já existem:
 *   · cli/chat-ia-teste.php     → a IA responde?
 *   · cli/chat-fluxo-check.php  → o fluxo publicado é alcançável?
 * Falta a entrada, que é onde o silêncio é mais difícil de diagnosticar: se o
 * webhook não entrega, ou se a automação não casa, nada acontece e nada
 * aparece — nem sessão, nem log, nem erro.
 *
 * Uso:
 *   php cli/chat-ig-check.php
 *   php cli/chat-ig-check.php --frase="quanto custa?"     testa o casamento
 *   php cli/chat-ig-check.php --frase="preço" --midia=17841400000000000
 *
 * Só lê. Não envia nada, não gasta token.
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

$arg = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_]+)(?:=(.*))?$/i', $a, $m)) $arg[$m[1]] = $m[2] ?? '1';
}
$frase = trim((string)($arg['frase'] ?? ''));
$midia = trim((string)($arg['midia'] ?? ''));

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
$titulo('1. Conta e assinatura do webhook');

$contas = $db->query(
    "SELECT id, ig_user_id, username, ativo, webhook_assinado, ultimo_erro,
            sincronizado_em, token_expira_em
     FROM chat_ig_contas ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$contas) {
    $item(false, 'nenhuma conta do Instagram conectada');
    $problemas[] = 'Conecte a conta em /admin/chat/instagram.';
}
foreach ($contas as $c) {
    $viva = (int)$c['ativo'] === 1 && (int)$c['webhook_assinado'] === 1;
    $item($viva, "@{$c['username']}",
        'ativo=' . $c['ativo'] . ' · webhook=' . ((int)$c['webhook_assinado'] === 1 ? 'assinado' : 'NÃO ASSINADO')
        . ' · mídias sincronizadas em ' . ($c['sincronizado_em'] ?: 'nunca'));

    if ((int)$c['ativo'] !== 1)            $problemas[] = "Conta @{$c['username']} está desativada.";
    if ((int)$c['webhook_assinado'] !== 1) $problemas[] = "Webhook de @{$c['username']} não está assinado — "
                                                        . 'a Meta não entrega comentário nenhum.';
    if (!empty($c['ultimo_erro'])) echo '    ' . $cor('· último erro: ' . $c['ultimo_erro'], 'aviso') . "\n";
}

$geral = ChatConfig::bool('ig_comentarios_ativo', true);
$item($geral, 'chave geral de comentários (ig_comentarios_ativo)',
    $geral ? 'ligada' : 'DESLIGADA — descarta tudo antes de olhar automação');
if (!$geral) $problemas[] = 'ig_comentarios_ativo está desligada: todo comentário é descartado na entrada.';

// ═════════════════════════════════════════════════════════════════════════════
$titulo('2. Chegou algum comentário?');

$total = (int)$db->query("SELECT COUNT(*) FROM chat_ig_comentarios")->fetchColumn();
$hoje  = (int)$db->query("SELECT COUNT(*) FROM chat_ig_comentarios WHERE criado_em >= CURDATE()")->fetchColumn();
$item($total > 0, 'comentários registrados', "$total no total · $hoje hoje");

if ($total === 0) {
    $problemas[] = 'Nenhum comentário jamais chegou. O problema está ANTES do sistema: '
                 . 'webhook não assinado, URL de callback errada, ou o app da Meta sem a permissão '
                 . 'instagram_manage_comments.';
} elseif ($hoje === 0) {
    echo '  ' . $cor('· nada hoje — se você comentou agora, o webhook não entregou', 'aviso') . "\n";
    $problemas[] = 'Comentários já chegaram algum dia, mas nenhum hoje.';
}

$ultimos = $db->query(
    "SELECT k.id, k.comment_id, k.media_id, k.from_username, k.texto, k.regra_id,
            k.contato_id, k.dm_enviado, k.dm_erro, k.respondido_publico, k.criado_em,
            r.nome AS regra_nome
     FROM chat_ig_comentarios k
     LEFT JOIN chat_ig_regras r ON r.id = k.regra_id
     ORDER BY k.id DESC LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($ultimos as $k) {
    $casou = !empty($k['regra_id']);
    echo '  ' . $cor($casou ? '✓' : '✗', $casou ? 'ok' : 'ruim')
       . " {$k['criado_em']} @{$k['from_username']}: "
       . $cor('"' . mb_substr((string)$k['texto'], 0, 44) . '"', 'forte') . "\n";
    echo '      mídia ' . ($k['media_id'] ?: '—')
       . ' · ' . ($casou ? "automação #{$k['regra_id']} “{$k['regra_nome']}”" : $cor('NENHUMA automação casou', 'ruim'))
       . ' · dm=' . $k['dm_enviado'] . ' · público=' . $k['respondido_publico'] . "\n";
    if (!empty($k['dm_erro'])) echo '      ' . $cor('erro: ' . $k['dm_erro'], 'ruim') . "\n";
}

if ($ultimos && !array_filter($ultimos, fn($k) => !empty($k['regra_id']))) {
    $problemas[] = 'Chegaram comentários, mas NENHUM casou com automação. '
                 . 'Rode com --frase="..." para ver onde o casamento falha.';
}

// ═════════════════════════════════════════════════════════════════════════════
$titulo('3. Automações ativas e o alcance de cada uma');

$regras = $db->query(
    "SELECT id, nome, status, ativo, gatilho_tipo, escopo, midias_json, palavras,
            modo_match, prioridade, fluxo_id, enviar_dm, responder_publico,
            uma_vez_por_pessoa, conta_id
     FROM chat_ig_regras ORDER BY prioridade, id"
)->fetchAll(PDO::FETCH_ASSOC);

$ativas = array_values(array_filter($regras, fn($r) => $r['status'] === 'ativa' && (int)$r['ativo'] === 1));

$item(count($ativas) > 0, 'automações ativas', count($ativas) . ' de ' . count($regras));
if (!$ativas) $problemas[] = 'Nenhuma automação ativa. Só automação ATIVA é considerada.';

foreach ($ativas as $r) {
    $ms = json_decode($r['midias_json'] ?? '[]', true) ?: [];
    echo "\n  automação #{$r['id']} “{$r['nome']}”\n";
    echo '      gatilho=' . $r['gatilho_tipo'] . ' · escopo=' . $r['escopo']
       . ' · prioridade=' . $r['prioridade'] . ' · fluxo=' . ($r['fluxo_id'] ?: 'nenhum') . "\n";
    echo '      palavras=' . ($r['palavras'] !== null && $r['palavras'] !== ''
            ? $r['palavras'] . ' (' . $r['modo_match'] . ')' : 'qualquer') . "\n";

    if ($r['escopo'] === 'midia') {
        if (!$ms) {
            echo '      ' . $cor('escopo por mídia, mas NENHUMA mídia selecionada — não casa com nada', 'ruim') . "\n";
            $problemas[] = "Automação #{$r['id']} tem escopo “uma publicação específica” e nenhuma "
                         . 'publicação marcada. Ela nunca vai casar.';
        } else {
            echo '      publicações marcadas (' . count($ms) . "):\n";
            foreach ($ms as $m) {
                $s = $db->prepare("SELECT tipo, permalink, publicado_em FROM chat_ig_midias WHERE media_id = :m LIMIT 1");
                $s->execute([':m' => (string)$m]);
                $info = $s->fetch(PDO::FETCH_ASSOC);
                echo '        · ' . $m
                   . ($info ? " [{$info['tipo']}] {$info['publicado_em']}"
                            : $cor('  — não está no cache de mídias', 'aviso')) . "\n";
            }
        }
    }

    if ((int)$r['uma_vez_por_pessoa'] === 1) {
        $s = $db->prepare(
            "SELECT COUNT(DISTINCT from_ig_id) FROM chat_ig_comentarios
             WHERE regra_id = :r AND dm_enviado = 1"
        );
        $s->execute([':r' => $r['id']]);
        $qtd = (int)$s->fetchColumn();
        echo '      "só uma vez por pessoa" ligado' . ($qtd > 0 ? " · $qtd pessoa(s) já atendida(s)" : '') . "\n";
    }
}

// ═════════════════════════════════════════════════════════════════════════════
if ($frase !== '') {
    $titulo('4. Simulando o casamento');
    echo '  frase: ' . $cor('"' . $frase . '"', 'forte') . "\n";
    echo '  mídia: ' . ($midia !== '' ? $midia : $cor('nenhuma informada — o escopo por mídia vai reprovar', 'aviso')) . "\n\n";

    $gat = new ChatGatilhoService($db);
    $casouAlguma = false;

    foreach ($ativas as $r) {
        $motivos = [];

        if ($r['gatilho_tipo'] !== 'comentario' && $r['gatilho_tipo'] !== 'comentario_reel') {
            $motivos[] = 'gatilho é ' . $r['gatilho_tipo'] . ', não comentário';
        }

        if ($r['escopo'] === 'midia') {
            $ms = array_map('strval', json_decode($r['midias_json'] ?? '[]', true) ?: []);
            if ($midia === '')                  $motivos[] = 'escopo por mídia e nenhuma mídia informada';
            elseif (!in_array($midia, $ms, true)) $motivos[] = 'a mídia informada não está entre as marcadas';
        }

        $palavras = trim((string)($r['palavras'] ?? ''));
        if ($palavras !== '' && !$gat->casa($frase, $palavras, (string)$r['modo_match'])) {
            $motivos[] = 'a frase não casa com "' . $palavras . '" (' . $r['modo_match'] . ')';
        }

        if (!$motivos) {
            $casouAlguma = true;
            echo '  ' . $cor('✓', 'ok') . " automação #{$r['id']} “{$r['nome']}” CASA"
               . $cor(' — é a que responderia (prioridade ' . $r['prioridade'] . ')', 'fraco') . "\n";
            break;   // o motor para na primeira que casa, por prioridade
        }

        echo '  ' . $cor('✗', 'ruim') . " automação #{$r['id']} “{$r['nome']}”\n";
        foreach ($motivos as $m) echo '      ' . $cor('· ' . $m, 'fraco') . "\n";
    }

    if (!$casouAlguma) {
        $problemas[] = 'Nenhuma automação ativa casa com essa frase nessa mídia — '
                     . 'é por isso que o comentário não dispara nada.';
    }
}

// ═════════════════════════════════════════════════════════════════════════════
$titulo('Lembretes que não dá para ver no banco');
echo '  ' . $cor('·', 'fraco') . " Comentário feito pela PRÓPRIA conta é sempre descartado —\n"
   . "    trava fixa no código, não a caixinha da tela. Teste de outro perfil.\n";
echo '  ' . $cor('·', 'fraco') . " Comentário em publicação antiga pode não gerar webhook.\n";
echo '  ' . $cor('·', 'fraco') . " Se a publicação não aparece na lista da automação, sincronize as\n"
   . "    mídias em /admin/chat/instagram antes de marcar o escopo.\n";

if ($problemas) {
    $titulo('O que está travando');
    foreach ($problemas as $i => $p) echo '  ' . $cor(($i + 1) . '. ' . $p, 'ruim') . "\n";
    echo "\n";
    exit(1);
}

$titulo('Entrada saudável');
echo "  Conta assinada, automação ativa e comentários casando.\n\n";
exit(0);
