<?php
/**
 * cli/fluxo-migrar-legado.php
 *
 * Converte fluxos do sistema ANTIGO (automacao_fluxos + automacao_passos)
 * em RASCUNHOS do motor v2 — para revisão e publicação manual no admin.
 *
 * Não desliga nada: os dois sistemas coexistem. Publique o fluxo novo e
 * desative o antigo quando validar. Detecções complexas do legado
 * (carrinho_abandonado, aniversario...) viram trigger_manual com nota —
 * a detecção antiga continua no automacao-worker até a paridade da Fase 3.
 *
 * Uso:
 *   php cli/fluxo-migrar-legado.php            → lista o que faria (dry-run)
 *   php cli/fluxo-migrar-legado.php --executar → cria os rascunhos
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Só roda em CLI.\n"); exit(1); }
$executar = in_array('--executar', $argv ?? [], true);

$ROOT = dirname(__DIR__);
chdir($ROOT);
require_once $ROOT . '/config/defines.php';
require_once $ROOT . '/config/config.php';
require_once $ROOT . '/config/database.php';
spl_autoload_register(function (string $class) use ($ROOT): void {
    foreach (['/core/','/app/models/','/app/helpers/','/app/services/'] as $p) {
        $f = $ROOT . $p . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});
require_once $ROOT . '/app/services/FluxoNoRegistry.php';

// Tipos do legado que têm trigger_evento equivalente no stream
$MAPA_TRIGGER = [
    'produto_visitado'   => ['evento' => 'produto_visto',   'entidade_tipo' => 'produto'],
    'categoria_visitada' => ['evento' => 'categoria_vista', 'entidade_tipo' => 'categoria'],
];

$db  = Database::getInstance()->getConnection();
$adm = new FluxoAdminService();

$fluxos = $db->query(
    "SELECT * FROM automacao_fluxos WHERE ativo = 1 ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

echo "Fluxos legados ativos: " . count($fluxos) . "\n\n";

foreach ($fluxos as $f) {
    $passos = $db->prepare(
        "SELECT * FROM automacao_passos WHERE fluxo_id = :f ORDER BY ordem ASC"
    );
    $passos->execute([':f' => $f['id']]);
    $passos = $passos->fetchAll(PDO::FETCH_ASSOC);

    if (!$passos) {
        echo "[PULA] {$f['tipo']} — sem passos\n";
        continue;
    }

    // ── Monta o grafo linear: trigger → (esperar → email)* ──
    $nos = [];
    $conexoes = [];

    if (isset($MAPA_TRIGGER[$f['tipo']])) {
        $nos[] = ['chave' => 't1', 'tipo' => 'trigger_evento',
                  'config' => $MAPA_TRIGGER[$f['tipo']] + ['apenas_logados' => true]];
        $nota = '';
    } else {
        $nos[] = ['chave' => 't1', 'tipo' => 'trigger_manual', 'config' => []];
        $nota = ' (trigger_manual — detecção segue no worker antigo)';
    }

    $anterior = 't1'; $portaAnterior = 'saida';
    foreach ($passos as $i => $p) {
        $n = $i + 1;
        $delayH = (int)$p['delay_horas'];
        if ($delayH > 0) {
            $nos[] = ['chave' => "e$n", 'tipo' => 'esperar', 'config' => ['horas' => $delayH]];
            $conexoes[] = ['de' => $anterior, 'porta' => $portaAnterior, 'para' => "e$n"];
            $anterior = "e$n"; $portaAnterior = 'saida';
        }
        $nos[] = ['chave' => "a$n", 'tipo' => 'acao_email',
                  'config' => ['template_id' => (int)$p['template_id']]];
        $conexoes[] = ['de' => $anterior, 'porta' => $portaAnterior, 'para' => "a$n"];
        $anterior = "a$n"; $portaAnterior = 'saida';
    }
    $nos[] = ['chave' => 'fim', 'tipo' => 'encerrar', 'config' => []];
    $conexoes[] = ['de' => $anterior, 'porta' => $portaAnterior, 'para' => 'fim'];

    $nome = 'v2: ' . $f['nome'];
    echo "[{$f['tipo']}] → '$nome' — " . count($nos) . " nós$nota\n";

    // Idempotencia: sem isto, cada execucao com --executar cria mais uma
    // copia de TODOS os fluxos. Ja aconteceu — rodou duas vezes e gerou 24
    // rascunhos para 12 fluxos legados (ids 6-17 e 18-29).
    $jaExiste = $db->prepare("SELECT id FROM fluxo_v2 WHERE nome = :n LIMIT 1");
    $jaExiste->execute([':n' => $nome]);
    if ($idExistente = $jaExiste->fetchColumn()) {
        echo "        -> ja migrado (rascunho #$idExistente) — pulando\n";
        continue;
    }

    if ($executar) {
        $id = $adm->criar($nome, 'Migrado do legado #' . $f['id'] . ' em ' . date('d/m/Y'));
        $r  = $adm->salvarRascunho($id, ['nos' => $nos, 'conexoes' => $conexoes], [
            'config' => ['reentrada' => 'nunca',
                         'sair_se_eventos' => ['pedido_criado']],
        ]);
        echo "        → rascunho #$id " . ($r['ok'] ? 'criado' : 'FALHOU: ' . implode('; ', $r['erros'])) . "\n";
    }
}

echo $executar ? "\nConcluído. Revise e publique no admin.\n"
               : "\nDry-run. Rode com --executar para criar os rascunhos.\n";
exit(0);
