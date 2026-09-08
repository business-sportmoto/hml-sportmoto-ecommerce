<?php
/**
 * Recalcula a intenção de compra dos clientes — Fase 3 da Central de IA.
 *
 * Uso:
 *   php cli/ia-intencao.php                  (mostra quem entraria, não grava)
 *   php cli/ia-intencao.php --aplicar        (calcula e grava)
 *   php cli/ia-intencao.php --limite=50      (teto de clientes na rodada)
 *   php cli/ia-intencao.php --cliente=2      (um só, para conferir)
 *
 * Cron sugerido (uma vez por noite — interesse não muda de hora em hora):
 *   17 3 * * * cd /caminho && php cli/ia-intencao.php --aplicar --limite=500 \
 *              >> storage/logs/ia-intencao.log 2>&1
 *
 * CUSTO
 *   A maior parte dos clientes resolve por SQL, sem chamar provedor. Só o caso
 *   ambíguo (categorias empatadas ou termo de busca que contradiz o histórico)
 *   vira uma geração. O resumo ao final diz quantos foram por cada caminho —
 *   se `por_ia` estiver alto, o limiar de dominância está apertado demais.
 *
 * LGPD
 *   Só entra cliente com contato ATIVO e fora da lista de supressão. Quem pediu
 *   para sair sai do perfilamento junto.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

require __DIR__ . '/../bootstrap-cli.php';

// O bootstrap-cli não registra app/services/ia/ — cada CLI do módulo carrega
// o seu, como o ia-worker faz.
spl_autoload_register(function (string $classe): void {
    foreach (['/../app/services/ia/', '/../app/services/ia/providers/'] as $dir) {
        $f = __DIR__ . $dir . $classe . '.php';
        if (is_file($f)) { require_once $f; return; }
    }
});

$opts    = getopt('', ['aplicar', 'limite::', 'cliente::']);
$aplicar = array_key_exists('aplicar', $opts);
$limite  = isset($opts['limite'])  ? max(1, (int) $opts['limite'])  : 500;
$umSo    = isset($opts['cliente']) ? (int) $opts['cliente'] : 0;

$svc = new IAIntencaoService();

echo "== Intenção de compra — Central de IA ==\n";
echo $aplicar ? "Modo: APLICAR\n\n" : "Modo: simulação (use --aplicar para gravar)\n\n";

/* ── Um cliente só ─────────────────────────────────────── */
if ($umSo > 0) {
    $sinais = $svc->sinaisDe($umSo);
    printf("cliente %d — %d evento(s) na janela\n", $umSo, $sinais['eventos']);

    foreach (['categorias', 'marcas', 'produtos', 'buscas'] as $bloco) {
        if (empty($sinais[$bloco])) { continue; }
        echo "  {$bloco}:\n";
        foreach (array_slice($sinais[$bloco], 0, 5) as $item) {
            printf("    %-42s %s\n",
                mb_substr((string) ($item['nome'] ?? $item['termo'] ?? '?'), 0, 42),
                isset($item['peso']) ? 'peso ' . $item['peso'] : $item['visitas'] . 'x');
        }
    }

    printf("\n  sinal claro: %s\n", $svc->sinalClaro($sinais) ? 'sim (resolve por SQL)' : 'não (chama a IA)');

    if (!$aplicar) {
        $r = $svc->rotuloPorSql($sinais);
        printf("  rótulo que a SQL daria: %s [%s]\n", $r['segmento'], $r['confianca']);
        exit(0);
    }

    $r = $svc->recalcular($umSo);
    echo empty($r['ok'])
        ? "  não gravou: " . ($r['msg'] ?? '?') . "\n"
        : "  gravado: {$r['segmento']} (por {$r['origem']})\n";
    exit(empty($r['ok']) ? 1 : 0);
}

/* ── Lote ──────────────────────────────────────────────── */
$ids = $svc->elegiveis($limite);
printf("%d cliente(s) elegível(is) — com navegação na janela e consentimento vigente\n", count($ids));

if ($ids === []) {
    echo "\nNada a fazer.\n";
    exit(0);
}

if (!$aplicar) {
    $claros = 0;
    foreach ($ids as $id) {
        if ($svc->sinalClaro($svc->sinaisDe($id))) { $claros++; }
    }
    printf("  %d resolveriam por SQL (sem custo) · %d chamariam a IA\n", $claros, count($ids) - $claros);
    echo "\nRode com --aplicar para gravar.\n";
    exit(0);
}

$ini = microtime(true);
$r   = $svc->lote($limite);

printf("\ncalculados: %d de %d  ·  por SQL: %d  ·  por IA: %d  ·  pulados: %d  ·  %.1fs\n",
    $r['calculados'], $r['elegiveis'], $r['por_sql'], $r['por_ia'], $r['pulados'], microtime(true) - $ini);

echo "\nsegmentos no banco:\n";
foreach ($svc->segmentos() as $s) {
    printf("  %-28s %3d cliente(s)  (%d com confiança alta)\n", $s['segmento'], $s['clientes'], $s['alta']);
}

exit(0);
