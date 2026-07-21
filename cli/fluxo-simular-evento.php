<?php
/**
 * cli/fluxo-simular-evento.php
 *
 * Injeta um evento no event stream como se o cliente tivesse feito no site —
 * para testar triggers, esperar_evento e condições sem precisar navegar.
 *
 * USO:
 *   php cli/fluxo-simular-evento.php --cliente=5 --tipo=produto_visto --entidade-tipo=produto --entidade-id=123
 *   php cli/fluxo-simular-evento.php --cliente=5 --tipo=busca --contexto='{"termo":"pneu","resultados":0}'
 *   php cli/fluxo-simular-evento.php --cliente=5 --tipo=email_aberto --detectar
 *
 * --detectar : roda na hora a fase de detecção (triggers) + resolução de
 *              esperas + processamento — vê o efeito sem esperar o worker.
 * --repetir=N: insere N eventos (para testar min_ocorrencias).
 *
 * O INSERT é direto na tabela `eventos` (mesmo formato do TrackingService),
 * com token sentinela de simulação — fácil de achar e limpar depois:
 *   DELETE FROM eventos WHERE visitante_token = 'simulacao0000000000000000000000';
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

const TOKEN_SIMULACAO = 'simulacao0000000000000000000000'; // 31 chars + pad
$token = str_pad(TOKEN_SIMULACAO, 32, '0');

$opts = getopt('', ['cliente::', 'tipo::', 'entidade-tipo::', 'entidade-id::', 'contexto::', 'repetir::', 'detectar']);
$clienteId = isset($opts['cliente']) ? (int)$opts['cliente'] : 0;
$tipo      = trim((string)($opts['tipo'] ?? ''));
$entTipo   = isset($opts['entidade-tipo']) ? trim((string)$opts['entidade-tipo']) : null;
$entId     = isset($opts['entidade-id']) ? (int)$opts['entidade-id'] : null;
$repetir   = max(1, min(50, (int)($opts['repetir'] ?? 1)));
$detectar  = array_key_exists('detectar', $opts);
$contexto  = null;
if (!empty($opts['contexto'])) {
    $c = json_decode((string)$opts['contexto'], true);
    if (!is_array($c)) { fwrite(STDERR, "--contexto não é JSON válido.\n"); exit(1); }
    $contexto = json_encode($c, JSON_UNESCAPED_UNICODE);
}

if ($clienteId <= 0 || $tipo === '') {
    fwrite(STDERR, "Uso: --cliente=ID --tipo=EVENTO [--entidade-tipo=produto --entidade-id=123]\n");
    fwrite(STDERR, "     [--contexto='{...}'] [--repetir=N] [--detectar]\n\n");
    fwrite(STDERR, "Eventos do site: produto_visto, categoria_vista, catalogo_moto_visto,\n");
    fwrite(STDERR, "  busca, banner_click, banner_visto, pagina_vista, sessao_iniciada\n");
    fwrite(STDERR, "Eventos de ponte: email_aberto, email_clicado, dica_cuidado_clicada\n");
    exit(1);
}

$db = Database::getInstance()->getConnection();

// Herdar produto_id no contexto quando a entidade é produto (o trigger repassa)
if ($contexto === null && $entTipo === 'produto' && $entId) {
    $contexto = json_encode(['produto_id' => $entId]);
}

$ins = $db->prepare(
    "INSERT INTO eventos (visitante_token, cliente_id, sessao_id, tipo, entidade_tipo, entidade_id, contexto_json, criado_em)
     VALUES (:tok, :cid, 'simulacao', :tipo, :et, :ei, :ctx, NOW())"
);
for ($i = 0; $i < $repetir; $i++) {
    $ins->execute([
        ':tok' => $token, ':cid' => $clienteId, ':tipo' => $tipo,
        ':et' => $entTipo, ':ei' => $entId, ':ctx' => $contexto,
    ]);
}
$ultimoId = (int)$db->lastInsertId();
echo "✔ {$repetir} evento(s) \"$tipo\" inserido(s) para o cliente #$clienteId (último id: $ultimoId)\n";

if (!$detectar) {
    echo "  → o fluxo-worker pega no próximo ciclo. Para ver agora: rode com --detectar.\n";
    exit(0);
}

// ── Efeito imediato: detecção + resolução + processamento ───────────────────
echo "\n── Rodando detecção e processamento agora ──\n";
try {
    $trig = new FluxoTriggerService();
    $t = $trig->detectar(500);
    echo "  triggers: eventos_lidos={$t['eventos_lidos']} execucoes_iniciadas={$t['execucoes_iniciadas']}\n";
} catch (Throwable $e) {
    echo "  triggers: ERRO {$e->getMessage()}\n";
}
try {
    $motor = new FluxoMotor();
    $r = $motor->resolverEsperasEvento(300);
    echo "  esperas resolvidas: $r\n";
    $p = $motor->processarExecucoes(100, 60);
    echo "  execuções: " . json_encode($p, JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo "  motor: ERRO {$e->getMessage()}\n";
}

echo "\nInspeção rápida:\n";
echo "  SELECT * FROM fluxo_execucoes WHERE cliente_id=$clienteId ORDER BY id DESC LIMIT 5;\n";
echo "  SELECT * FROM fluxo_passos_log WHERE cliente_id=$clienteId ORDER BY id DESC LIMIT 20;\n";
exit(0);
