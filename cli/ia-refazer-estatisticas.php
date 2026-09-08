<?php
/**
 * cli/ia-refazer-estatisticas.php
 *
 * Recalcula `ia_modelos.total_execucoes`, `total_falhas` e `tempo_medio_ms` a
 * partir de `ia_geracoes`.
 *
 * Existe porque `IAOrchestrator::atualizarEstatisticas()` falhava em silêncio
 * com `SQLSTATE[HY093]` (placeholder `:t` usado duas vezes sem emulação de
 * prepare). O erro era engolido pelo catch: a geração funcionava e os
 * contadores nunca saíam de zero.
 *
 * Sem este reparo, corrigir o código não conserta o passado — os modelos já
 * usados continuam marcados como "nunca executados", e a trava que impede
 * excluir modelo em uso segue solta até cada um ser usado de novo.
 *
 * `tempo_medio_ms` vira a média simples do que está registrado, não a média
 * móvel 80/20 do orquestrador: reproduzir a média móvel exigiria a ordem exata
 * das execuções e daria um número que parece preciso sem ser. Média simples é
 * honesta e converge com o uso.
 *
 * Uso:
 *   php cli/ia-refazer-estatisticas.php              simula (não grava)
 *   php cli/ia-refazer-estatisticas.php --aplicar    grava
 */

$ROOT = dirname(__DIR__);
chdir($ROOT);
require $ROOT . '/config/defines.php';
require $ROOT . '/config/config.php';
require $ROOT . '/config/database.php';

$aplicar = in_array('--aplicar', $argv, true);
$db = Database::getInstance()->getConnection();

// `falhou` sem modelo_id é falha ANTES de escolher modelo (nenhum ativo, teto
// de custo). Não é falha de modelo nenhum e não pode entrar na conta.
$linhas = $db->query(
    "SELECT modelo_id,
            COUNT(*)                                             AS execucoes,
            SUM(status = 'falhou')                               AS falhas,
            ROUND(AVG(NULLIF(tempo_ms, 0)))                      AS tempo_medio
       FROM ia_geracoes
      WHERE modelo_id IS NOT NULL
   GROUP BY modelo_id
   ORDER BY modelo_id"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$linhas) {
    echo "Nenhuma geração com modelo registrado — nada a refazer.\n";
    exit(0);
}

$atual = [];
foreach ($db->query(
    "SELECT id, codigo_modelo, total_execucoes, total_falhas, tempo_medio_ms FROM ia_modelos"
)->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $atual[(int)$m['id']] = $m;
}

printf("%-6s%-27s%-24s%s\n", 'ID', 'MODELO', 'AGORA', 'PASSARIA A SER');
echo str_repeat('─', 88), "\n";

$mudar = [];
foreach ($linhas as $l) {
    $id = (int)$l['modelo_id'];
    $m  = $atual[$id] ?? null;
    if (!$m) continue;   // modelo excluído; o histórico fica em ia_geracoes

    $novo = [
        'execucoes' => (int)$l['execucoes'],
        'falhas'    => (int)$l['falhas'],
        'tempo'     => $l['tempo_medio'] !== null ? (int)$l['tempo_medio'] : null,
    ];
    $velho = [
        'execucoes' => (int)$m['total_execucoes'],
        'falhas'    => (int)$m['total_falhas'],
        'tempo'     => $m['tempo_medio_ms'] !== null ? (int)$m['tempo_medio_ms'] : null,
    ];
    if ($novo === $velho) continue;

    $mudar[$id] = $novo;

    // `printf('%-22s')` alinha por bytes, e "·"/"—" são multibyte: a coluna
    // sairia torta justo numa saída feita para comparar lado a lado.
    $col = fn(array $v) => sprintf('%d exec · %d falha · %s',
        $v['execucoes'], $v['falhas'], $v['tempo'] === null ? '—' : $v['tempo'] . 'ms');
    $pad = fn(string $t, int $n) => $t . str_repeat(' ', max(1, $n - mb_strlen($t)));

    echo $pad((string)$id, 6)
       . $pad(mb_substr((string)$m['codigo_modelo'], 0, 26), 27)
       . $pad($col($velho), 24)
       . $col($novo) . "\n";
}

echo str_repeat('─', 88), "\n";

if (!$mudar) {
    echo "Todos os contadores já batem com o histórico.\n";
    exit(0);
}

echo count($mudar), " modelo(s) a corrigir.\n";

if (!$aplicar) {
    echo "\nSimulação — nada foi gravado. Rode com --aplicar para valer.\n";
    exit(0);
}

$db->beginTransaction();
try {
    $st = $db->prepare(
        'UPDATE ia_modelos
            SET total_execucoes = :e, total_falhas = :f, tempo_medio_ms = :t
          WHERE id = :id LIMIT 1'
    );
    foreach ($mudar as $id => $v) {
        $st->execute([':e' => $v['execucoes'], ':f' => $v['falhas'], ':t' => $v['tempo'], ':id' => $id]);
    }
    $db->commit();
    echo "\nAplicado em ", count($mudar), " modelo(s).\n";
} catch (Throwable $e) {
    $db->rollBack();
    echo "\nFalhou, nada foi gravado: ", $e->getMessage(), "\n";
    exit(1);
}
