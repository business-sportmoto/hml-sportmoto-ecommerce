<?php
/**
 * cli/chat-fluxo-check.php
 *
 * Por que este fluxo não roda? Confere a VERSÃO PUBLICADA — que é a que o motor
 * executa — e não o rascunho que o editor mostra na tela.
 *
 * Responde as perguntas que o canvas não responde:
 *   · alguma automação aponta para este fluxo, e ela está ativa?
 *   · qual bloco é a entrada? (o motor pega o primeiro trigger que achar)
 *   · saindo da entrada, dá para CHEGAR em cada bloco? Bloco desenhado mas não
 *     alcançável é o erro mais comum — no canvas ele parece ligado.
 *   · que portas ficaram sem ligação?
 *   · a regra de reentrada está barrando quem já passou pelo fluxo?
 *
 * Uso:
 *   php cli/chat-fluxo-check.php                 lista os fluxos
 *   php cli/chat-fluxo-check.php --fluxo=7
 *   php cli/chat-fluxo-check.php --fluxo=7 --contato=42   (checa a reentrada)
 *
 * Só lê. Não altera nada, não envia nada, não gasta token.
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
require_once $ROOT . '/app/services/ChatNoRegistry.php';

$db = Database::getInstance()->getConnection();

$arg = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_]+)(?:=(.*))?$/i', $a, $m)) $arg[$m[1]] = $m[2] ?? '1';
}
$fluxoId   = (int)($arg['fluxo'] ?? 0);
$contatoId = (int)($arg['contato'] ?? 0);

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

// ── Sem argumento: lista ─────────────────────────────────────────────────────
if ($fluxoId < 1) {
    $titulo('Fluxos');
    foreach ($db->query(
        "SELECT f.id, f.nome, f.status, f.versao_publicada,
                (SELECT COUNT(*) FROM chat_ig_regras r WHERE r.fluxo_id = f.id) AS automacoes
         FROM chat_fluxos f ORDER BY f.id"
    )->fetchAll(PDO::FETCH_ASSOC) as $f) {
        printf("  #%-4d %-34s %-10s v%-3s %s\n", $f['id'], mb_substr($f['nome'], 0, 34),
            $f['status'], $f['versao_publicada'],
            $f['automacoes'] > 0 ? $f['automacoes'] . ' automação(ões)' : '');
    }
    echo "\n  Detalhe: php cli/chat-fluxo-check.php --fluxo=ID\n\n";
    exit(0);
}

$st = $db->prepare("SELECT * FROM chat_fluxos WHERE id = :i");
$st->execute([':i' => $fluxoId]);
$fluxo = $st->fetch(PDO::FETCH_ASSOC);
if (!$fluxo) { echo "Fluxo #$fluxoId não existe.\n"; exit(1); }

$problemas = [];

// ═════════════════════════════════════════════════════════════════════════════
$titulo("Fluxo #{$fluxo['id']} — {$fluxo['nome']}");

$publicado = $fluxo['status'] === 'publicado' && (int)$fluxo['versao_publicada'] >= 1;
$item($publicado, 'publicado', "status={$fluxo['status']} · versão publicada v{$fluxo['versao_publicada']}");
if (!$publicado) $problemas[] = 'Rascunho não roda. Abra o editor e clique em Publicar.';

if ($fluxo['status'] === 'pausado') {
    $problemas[] = 'Fluxo PAUSADO — o motor não inicia sessão nova enquanto estiver assim.';
}

$versao = max(1, (int)$fluxo['versao_publicada']);

// ── Rascunho diferente do publicado ──────────────────────────────────────────
$conta = function (int $v) use ($db, $fluxoId): int {
    $s = $db->prepare("SELECT COUNT(*) FROM chat_fluxo_nos WHERE fluxo_id = :f AND versao = :v");
    $s->execute([':f' => $fluxoId, ':v' => $v]);
    return (int)$s->fetchColumn();
};
$nRasc = $conta(0);
$nPub  = $conta($versao);
if ($nRasc !== $nPub) {
    echo '  ' . $cor("· rascunho tem $nRasc bloco(s), publicado tem $nPub", 'aviso')
       . $cor(' — o que roda é o publicado', 'fraco') . "\n";
    $problemas[] = 'O rascunho mudou depois da última publicação. Publique de novo.';
}

// ═════════════════════════════════════════════════════════════════════════════
$titulo('Quem chama este fluxo');

$autos = $db->prepare(
    "SELECT id, nome, status, ativo, escopo, palavras, responder_publico, enviar_dm
     FROM chat_ig_regras WHERE fluxo_id = :f"
);
$autos->execute([':f' => $fluxoId]);
$listaAutos = $autos->fetchAll(PDO::FETCH_ASSOC);

if (!$listaAutos) {
    $item(false, 'nenhuma automação do Instagram aponta para este fluxo');
    $problemas[] = 'Sem automação vinculada, o fluxo só começa por campanha ou disparo manual. '
                 . 'No editor da automação, campo "Continuar num fluxo".';
} else {
    foreach ($listaAutos as $a) {
        $viva = $a['status'] === 'ativa' && (int)$a['ativo'] === 1;
        $item($viva, "automação #{$a['id']} “{$a['nome']}”",
            'status=' . $a['status'] . ' · escopo=' . $a['escopo']
            . ' · palavras=' . ($a['palavras'] !== null && $a['palavras'] !== '' ? $a['palavras'] : 'qualquer'));
        if (!$viva) {
            $problemas[] = "Automação #{$a['id']} está “{$a['status']}”. Só automação ATIVA é "
                         . 'considerada quando um comentário chega.';
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
$titulo('Grafo publicado (v' . $versao . ')');

$stN = $db->prepare("SELECT chave, tipo_no, config_json FROM chat_fluxo_nos
                     WHERE fluxo_id = :f AND versao = :v ORDER BY id");
$stN->execute([':f' => $fluxoId, ':v' => $versao]);
$nos = [];
foreach ($stN->fetchAll(PDO::FETCH_ASSOC) as $n) $nos[$n['chave']] = $n;

$stC = $db->prepare("SELECT no_origem, porta, no_destino FROM chat_fluxo_conexoes
                     WHERE fluxo_id = :f AND versao = :v ORDER BY id");
$stC->execute([':f' => $fluxoId, ':v' => $versao]);
$cons = $stC->fetchAll(PDO::FETCH_ASSOC);

if (!$nos) { echo "  (versão publicada sem blocos)\n"; $problemas[] = 'A versão publicada está vazia.'; }

// ── Entrada ──────────────────────────────────────────────────────────────────
$triggers = [];
foreach ($nos as $chave => $n) {
    if (ChatNoRegistry::ehTrigger($n['tipo_no'])) $triggers[] = $chave;
}

$entrada = $triggers[0] ?? null;
$item((bool)$entrada, 'bloco de entrada',
    $entrada ? "$entrada [{$nos[$entrada]['tipo_no']}]" : 'NENHUM bloco de gatilho na versão publicada');

if (!$entrada) {
    $problemas[] = 'Sem bloco de gatilho o motor não sabe por onde começar e desiste na hora. '
                 . 'Todo fluxo precisa de um — "Disparo manual" serve para automação do Instagram.';
} elseif (count($triggers) > 1) {
    $problemas[] = 'Há ' . count($triggers) . ' blocos de gatilho (' . implode(', ', $triggers) . '). '
                 . 'O motor pega o primeiro que o banco devolver — sem ordem garantida. Deixe só um.';
}

// ── Alcançabilidade ──────────────────────────────────────────────────────────
$saidas = [];
foreach ($cons as $c) $saidas[$c['no_origem']][] = $c;

$vistos = [];
if ($entrada) {
    $fila = [$entrada];
    while ($fila) {
        $atual = array_shift($fila);
        if (isset($vistos[$atual])) continue;
        $vistos[$atual] = true;
        foreach ($saidas[$atual] ?? [] as $c) {
            if (!isset($vistos[$c['no_destino']])) $fila[] = $c['no_destino'];
        }
    }
}

echo "\n";
foreach ($nos as $chave => $n) {
    $alcanca = isset($vistos[$chave]);
    $cfg = json_decode($n['config_json'] ?? '{}', true);
    $vazio = !is_array($cfg) || $cfg === [];

    $marca = $chave === $entrada ? ' (entrada)' : '';
    $extra = [];
    if ($vazio) $extra[] = 'sem configuração';
    if (!empty($cfg['produto_id'])) $extra[] = 'produto #' . (int)$cfg['produto_id'];

    $item($alcanca, "$chave [{$n['tipo_no']}]$marca",
        ($alcanca ? '' : 'NÃO ALCANÇÁVEL · ') . implode(' · ', $extra));

    if (!$alcanca) {
        $problemas[] = "O bloco “{$chave}” ({$n['tipo_no']}) não é alcançável a partir da entrada. "
                     . 'No canvas ele parece ligado, mas nenhum caminho sai do gatilho e chega nele.';
    }
}

// ── Ligações ─────────────────────────────────────────────────────────────────
// "Alcançável" não é "no caminho": uma condição divide o fluxo em dois, e quem
// comenta num Reel toma sempre o mesmo lado. Só vendo o destino de cada porta
// dá para saber se a conversa passa pelo bloco que fala no direct.
$titulo('Ligações, porta por porta');

$falaNoDirect = ['msg_texto', 'msg_midia', 'msg_botoes', 'msg_lista', 'msg_template',
                 'msg_botao_url', 'msg_ig_card', 'esperar_resposta', 'ia_responder',
                 'acao_cupom_produto'];

$temDirect = false;
foreach ($nos as $chave => $n) {
    if (!isset($vistos[$chave])) continue;
    if (in_array($n['tipo_no'], $falaNoDirect, true)) { $temDirect = true; break; }
}

foreach ($nos as $chave => $n) {
    if (!isset($vistos[$chave])) continue;

    $noObj  = ChatNoRegistry::obter($n['tipo_no']);
    $portas = $noObj ? $noObj->portas() : [];
    $fala   = in_array($n['tipo_no'], $falaNoDirect, true);

    echo '  ' . ($chave === $entrada ? $cor('▶', 'ok') : ' ')
       . ' ' . $cor($chave, 'forte') . " [{$n['tipo_no']}]"
       . ($fala ? $cor('  fala no direct', 'ok') : '') . "\n";

    if (!$portas) { echo "      (sem saídas)\n"; continue; }

    foreach ($portas as $p) {
        $destino = null;
        foreach ($saidas[$chave] ?? [] as $c) if ($c['porta'] === $p) { $destino = $c['no_destino']; break; }

        if ($destino === null) {
            echo '      ' . str_pad($p, 22) . $cor('→ nada (a conversa para aqui)', 'aviso') . "\n";
            continue;
        }
        $tipoDest = $nos[$destino]['tipo_no'] ?? '?';
        echo '      ' . str_pad($p, 22) . "→ $destino [$tipoDest]\n";
    }
}

if (!$temDirect) {
    $problemas[] = 'Nenhum bloco alcançável fala no direct. A automação responde o comentário '
                 . 'e a conversa morre aí. Para mandar mensagem no direct o caminho precisa '
                 . 'passar por um bloco de mensagem ("Texto", "Pergunta com botões"…) ou pela '
                 . '"Etapa de IA" — "Responder comentário" só escreve embaixo do post.';
}

// ── Portas soltas ────────────────────────────────────────────────────────────
$titulo('Saídas sem ligação');
$soltas = 0;
foreach ($nos as $chave => $n) {
    if (!isset($vistos[$chave])) continue;   // bloco morto já foi reportado
    $no     = ChatNoRegistry::obter($n['tipo_no']);
    $portas = $no ? $no->portas() : [];
    foreach ($portas as $p) {
        $ligada = false;
        foreach ($saidas[$chave] ?? [] as $c) if ($c['porta'] === $p) { $ligada = true; break; }
        if ($ligada) continue;
        $soltas++;
        echo '  ' . $cor('·', 'aviso') . " $chave [{$n['tipo_no']}] → porta “{$p}” não vai a lugar nenhum\n";
    }
}
if ($soltas === 0) echo '  ' . $cor('nenhuma — todas as saídas têm destino', 'fraco') . "\n";
else echo '  ' . $cor("A conversa PARA quando cai numa dessas.", 'fraco') . "\n";

// ═════════════════════════════════════════════════════════════════════════════
$titulo('Reentrada');
$cfgF  = json_decode($fluxo['config_json'] ?? '{}', true) ?: [];
$modo  = (string)($cfgF['reentrada'] ?? 'sempre');
$item($modo === 'sempre', "modo: $modo",
    $modo === 'sempre' ? 'pode repetir' : 'quem já passou não entra de novo');

if ($modo !== 'sempre') {
    $problemas[] = "Reentrada em “{$modo}”: testar duas vezes com o mesmo perfil só funciona na primeira.";
}

if ($contatoId > 0) {
    $s = $db->prepare("SELECT COUNT(*) FROM chat_sessoes WHERE fluxo_id = :f AND contato_id = :c");
    $s->execute([':f' => $fluxoId, ':c' => $contatoId]);
    $qtd = (int)$s->fetchColumn();
    echo "  contato #$contatoId já tem $qtd sessão(ões) neste fluxo\n";
    if ($qtd > 0 && $modo !== 'sempre') {
        $problemas[] = "O contato #$contatoId já passou por aqui e a reentrada está em “{$modo}” — "
                     . 'ele não entra mais.';
    }
}

// ═════════════════════════════════════════════════════════════════════════════
$titulo('Últimas execuções');
$s = $db->prepare(
    "SELECT id, versao, contato_id, no_atual, status, erro_detalhe, criado_em
     FROM chat_sessoes WHERE fluxo_id = :f ORDER BY id DESC LIMIT 8"
);
$s->execute([':f' => $fluxoId]);
$sess = $s->fetchAll(PDO::FETCH_ASSOC);

if (!$sess) {
    echo '  ' . $cor('nenhuma sessão — o fluxo nunca foi iniciado', 'aviso') . "\n";
    echo '  ' . $cor('Se a automação está ativa e mesmo assim não há sessão, o comentário não '
                   . 'chegou a casar com ela. Veja /admin/chat/instagram/comentarios.', 'fraco') . "\n";
} else {
    foreach ($sess as $x) {
        echo "  #{$x['id']} {$x['criado_em']} v{$x['versao']} contato={$x['contato_id']}"
           . " parou em " . $cor((string)$x['no_atual'], 'forte') . " · {$x['status']}\n";
        if (!empty($x['erro_detalhe'])) echo '      ' . $cor($x['erro_detalhe'], 'ruim') . "\n";
    }
}

// ═════════════════════════════════════════════════════════════════════════════
if ($problemas) {
    $titulo('O que está travando');
    foreach ($problemas as $i => $p) echo '  ' . $cor(($i + 1) . '. ' . $p, 'ruim') . "\n";
    echo "\n";
    exit(1);
}

$titulo('Nada travando');
echo "  O fluxo publicado está íntegro e alcançável de ponta a ponta.\n\n";
exit(0);
