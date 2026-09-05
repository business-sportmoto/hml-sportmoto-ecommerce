<?php
/**
 * cli/fluxo-testar.php
 *
 * Testa um fluxo AGORA, sem esperar o worker: inicia uma jornada, processa,
 * e imprime cada passo que o motor deu (direto do fluxo_passos_log).
 *
 * USO:
 *   php cli/fluxo-testar.php --fluxo=12 --cliente=5
 *   php cli/fluxo-testar.php --fluxo=12 --cliente=5 --contexto='{"produto_id":123}'
 *   php cli/fluxo-testar.php --fluxo=12 --cliente=5 --acordar     (zera esperas e segue)
 *   php cli/fluxo-testar.php --execucao=88 --acordar              (retoma jornada existente)
 *
 * O fluxo precisa estar PUBLICADO. Funciona com qualquer trigger (o disparo
 * aqui é manual, ignorando a condição do trigger — para testar o resto do grafo).
 *
 * --acordar: zera dormir_ate (nó esperar) e força timeout de esperar_evento,
 *            depois processa de novo — atravessa as esperas em segundos.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Só roda em CLI.\n"); exit(1); }

$ROOT = dirname(__DIR__);
chdir($ROOT);
require_once $ROOT . '/config/defines.php';
require_once $ROOT . '/config/config.php';
require_once $ROOT . '/config/database.php';
if (is_file($ROOT . '/vendor/autoload.php')) require_once $ROOT . '/vendor/autoload.php';

spl_autoload_register(function (string $class) use ($ROOT): void {
    foreach (['/core/','/app/controllers/','/app/models/','/app/helpers/',
              '/app/services/','/app/services/email/','/app/services/email/providers/'] as $p) {
        $f = $ROOT . $p . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

// ── Argumentos ───────────────────────────────────────────────────────────────
$opts = getopt('', ['fluxo::', 'cliente::', 'contexto::', 'execucao::', 'acordar']);
$fluxoId  = isset($opts['fluxo'])    ? (int)$opts['fluxo']    : 0;
$clienteId= isset($opts['cliente'])  ? (int)$opts['cliente']  : 0;
$execId   = isset($opts['execucao']) ? (int)$opts['execucao'] : 0;
$acordar  = array_key_exists('acordar', $opts);
$contexto = [];
if (!empty($opts['contexto'])) {
    $contexto = json_decode((string)$opts['contexto'], true);
    if (!is_array($contexto)) { fwrite(STDERR, "--contexto não é JSON válido.\n"); exit(1); }
}

if (!$execId && (!$fluxoId || !$clienteId)) {
    fwrite(STDERR, "Uso: --fluxo=ID --cliente=ID [--contexto='{...}'] [--acordar]\n");
    fwrite(STDERR, "  ou: --execucao=ID [--acordar]\n");
    exit(1);
}

$db    = Database::getInstance()->getConnection();
$motor = new FluxoMotor();

// ── Inicia (ou localiza) a execução ─────────────────────────────────────────
if (!$execId) {
    $f = $db->prepare("SELECT nome, status, versao_publicada FROM fluxo_v2 WHERE id=:id");
    $f->execute([':id' => $fluxoId]);
    $fluxo = $f->fetch(PDO::FETCH_ASSOC);
    if (!$fluxo) { fwrite(STDERR, "Fluxo #$fluxoId não existe.\n"); exit(1); }
    if ((int)$fluxo['versao_publicada'] < 1) {
        fwrite(STDERR, "Fluxo \"{$fluxo['nome']}\" nunca foi publicado — publique antes de testar.\n");
        exit(1);
    }
    if ($fluxo['status'] !== 'publicado') {
        echo "AVISO: fluxo está \"{$fluxo['status']}\" — o teste roda mesmo assim (disparo manual).\n";
    }

    $execId = $motor->iniciarExecucao($fluxoId, $clienteId, null, $contexto);
    if (!$execId) {
        fwrite(STDERR, "Não deu para iniciar (reentrada 'nunca' e o cliente já passou por este fluxo? Confira fluxo_execucoes).\n");
        exit(1);
    }
    echo "✔ Jornada #$execId iniciada — fluxo \"{$fluxo['nome']}\" v{$fluxo['versao_publicada']}, cliente #$clienteId\n";
}

// ── Processa (com opção de atravessar esperas) ───────────────────────────────
$rodada = 0;
do {
    $rodada++;
    $motor->resolverEsperasEvento(50);
    $motor->processarExecucoes(50, 60);

    $st = $db->prepare("SELECT status, dormir_ate, evento_aguardado, timeout_em, erro_detalhe
                        FROM fluxo_execucoes WHERE id=:id");
    $st->execute([':id' => $execId]);
    $exec = $st->fetch(PDO::FETCH_ASSOC);
    if (!$exec) { fwrite(STDERR, "Execução #$execId sumiu?!\n"); exit(1); }

    $viva = in_array($exec['status'], ['dormindo', 'aguardando_evento'], true);
    if ($viva && $acordar && $rodada < 10) {
        if ($exec['status'] === 'dormindo') {
            $db->prepare("UPDATE fluxo_execucoes SET dormir_ate = NOW() WHERE id=:id")
               ->execute([':id' => $execId]);
            echo "  ⏩ acordando (dormir_ate zerado)...\n";
        } else {
            // Força o timeout do esperar_evento: vence a espera pela porta 'timeout'
            $db->prepare("UPDATE fluxo_execucoes SET timeout_em = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id=:id")
               ->execute([':id' => $execId]);
            // O timeout também vive na spec do contexto
            $ctx = json_decode((string)$db->query("SELECT contexto_json FROM fluxo_execucoes WHERE id=$execId")->fetchColumn(), true) ?: [];
            foreach ($ctx as $k => $v) {
                if (strpos($k, '_ee_spec_') === 0 && is_array($v)) {
                    $ctx[$k]['timeout_em'] = date('Y-m-d H:i:s', time() - 60);
                }
            }
            $db->prepare("UPDATE fluxo_execucoes SET contexto_json=:c WHERE id=:id")
               ->execute([':c' => json_encode($ctx, JSON_UNESCAPED_UNICODE), ':id' => $execId]);
            echo "  ⏩ forçando timeout do esperar_evento (sai pela porta 'timeout')...\n";
        }
        continue;
    }
    break;
} while (true);

// ── Imprime a jornada ────────────────────────────────────────────────────────
echo "\n── Passos da jornada #$execId " . str_repeat('─', 40) . "\n";
try {
    $st = $db->prepare("SELECT no_chave, tipo_no, porta, detalhe, duracao_ms, criado_em
                        FROM fluxo_passos_log WHERE execucao_id=:e ORDER BY id ASC");
    $st->execute([':e' => $execId]);
    $passos = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$passos) {
        echo "(sem linhas no fluxo_passos_log — a migração de observabilidade rodou?)\n";
    }
    foreach ($passos as $p) {
        $icone = '·';
        if ($p['no_chave'] === '__inicio') $icone = '▶';
        elseif ($p['no_chave'] === '__fim') $icone = ($p['porta'] === 'concluido' ? '✔' : ($p['porta'] === 'erro' ? '✖' : '↩'));
        elseif ($p['porta'] === '__erro')   $icone = '✖';
        elseif ($p['porta'] === '__dormir' || $p['porta'] === '__aguardar') $icone = '⏸';

        $linha = sprintf("%s %-19s %-24s → %-12s", $icone,
            substr($p['criado_em'], 0, 19), $p['no_chave'] . ' (' . $p['tipo_no'] . ')', $p['porta']);
        if ($p['detalhe'])              $linha .= "  [{$p['detalhe']}]";
        if ((int)$p['duracao_ms'] > 200) $linha .= "  ({$p['duracao_ms']}ms)";
        echo $linha . "\n";
    }
} catch (Throwable $e) {
    echo "(fluxo_passos_log indisponível: {$e->getMessage()})\n";
}

echo str_repeat('─', 70) . "\n";
echo "Status final: {$exec['status']}";
if ($exec['status'] === 'dormindo')          echo " até {$exec['dormir_ate']}  (rode de novo com --acordar)";
if ($exec['status'] === 'aguardando_evento') echo " por \"{$exec['evento_aguardado']}\" até {$exec['timeout_em']}  (simule o evento, ou --acordar força o timeout)";
if ($exec['status'] === 'erro')              echo "\nErro: {$exec['erro_detalhe']}";
echo "\n";
exit($exec['status'] === 'erro' ? 1 : 0);
