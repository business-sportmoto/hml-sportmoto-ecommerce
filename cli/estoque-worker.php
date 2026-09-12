#!/usr/bin/env php
<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// cli/estoque-worker.php — a fila da ponte de estoque
//
// Três fases por rodada, nesta ordem:
//   1. resgata movimento preso em `enviando` (processo morto no meio)
//   2. traduz eventos recebidos em movimentos
//   3. despacha movimentos pendentes
//
// ── FASE 1 DO MÓDULO: ele não envia nada ──────────────
// Os dois interruptores (`estoque_ponte_perna_a` e `_b` em `configuracoes`)
// nascem em 0. Com a perna desligada o worker traduz e enfileira, mas não
// toca em API nenhuma. É de propósito: a fase 1 entrega software que não faz
// nada em produção, para o corte da fase 2 ser só ligar a perna e trocar a
// URL do webhook no painel do Bling.
//
// Uso:
//   php cli/estoque-worker.php --verbose
//   php cli/estoque-worker.php --limite=100
//   php cli/estoque-worker.php --simular --verbose   injeta um evento de teste
//
// Cron (a cada minuto — o lock impede sobreposição):
//   * * * * * cd /CAMINHO && php cli/estoque-worker.php >> storage/logs/estoque-worker.log 2>&1
//
// Sai 0 quando não há o que fazer; 1 só em erro de execução.
// Ver docs/sportmoto-os/12-decisoes-tecnicas/estoque-modulo-especificacao.md
// ════════════════════════════════════════════════════════

require_once __DIR__ . '/../bootstrap-cli.php';

$argv    = $argv ?? [];
$verbose = in_array('--verbose', $argv, true);
$simular = in_array('--simular', $argv, true);

$limite = 50;
foreach ($argv as $a) {
    if (preg_match('/^--limite=(\d+)$/', $a, $m)) $limite = max(1, (int)$m[1]);
}

$log = static function (string $m) use ($verbose): void {
    if ($verbose) echo '[' . date('H:i:s') . "] {$m}\n";
};

// ── Lock: uma instância por vez ───────────────────────
$lockFile = ROOT_PATH . '/storage/locks/estoque-worker.lock';
@mkdir(dirname($lockFile), 0775, true);
$fp = @fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    $log('Outro estoque-worker já está rodando.');
    if ($fp) fclose($fp);
    exit(0);
}

$svc     = new EstoquePonteService();
$falhou  = false;
$contas  = ['resgatados' => 0, 'eventos' => 0, 'movimentos' => 0,
            'enviados' => 0, 'represados' => 0, 'ignorados' => 0, 'falhas' => 0];

try {
    // ── Simulação: exercita o caminho sem tocar em Syscar nem Bling ──
    if ($simular) {
        $sku = Database::getInstance()->getConnection()->query(
            "SELECT sku_legado FROM produtos
              WHERE sku_legado IS NOT NULL AND sku_legado <> ''
                AND deleted_at IS NULL
              LIMIT 1"
        )->fetchColumn() ?: 'SKU-INEXISTENTE';

        $r = $svc->registrarEvento('syscar', 'movimento.simulado', 'sim-' . uniqid(), [
            'cod'        => $sku,
            'lancamento' => 'S',
            'movimento'  => 1,
            'n_saldo'    => 9,
            'preco_mov'  => 99.90,
            'tipo'       => 'SIMULACAO — worker',
        ], true);
        $log("simulação: evento #{$r['id']} criado para o SKU {$sku}");
    }

    // ── Fase 1: resgate ───────────────────────────────
    $contas['resgatados'] = $svc->resgatarPresos();
    if ($contas['resgatados'] > 0) {
        $log("resgatados {$contas['resgatados']} movimento(s) presos em enviando");
    }

    // ── Fase 2: eventos → movimentos ──────────────────
    foreach ($svc->eventosPendentes($limite) as $evt) {
        $contas['eventos']++;
        $id = (int)$evt['id'];

        try {
            $traducao = $svc->traduzirEvento($evt);

            if ($traducao['motivo_ignorado'] !== null) {
                $svc->marcarEventoIgnorado($id, $traducao['motivo_ignorado']);
                $contas['ignorados']++;
                $log("evento #{$id} ignorado: {$traducao['motivo_ignorado']}");
                continue;
            }

            foreach ($traducao['movimentos'] as $mov) {
                $res = $svc->criarMovimento($mov);
                $contas['movimentos']++;
                $log(sprintf(
                    'evento #%d → movimento #%d%s',
                    $id, $res['id'], $res['duplicado'] ? ' (duplicado, absorvido)' : ''
                ));
            }

            $svc->marcarEventoProcessado($id);

        } catch (\Throwable $e) {
            $svc->marcarEventoErro($id, $e->getMessage());
            $contas['falhas']++;
            $falhou = true;
            $log("evento #{$id} ERRO: " . $e->getMessage());
            LogService::exception($e, 'error', 'estoque', ['evento_id' => $id]);
        }
    }

    // ── Fase 3: despacho ──────────────────────────────
    foreach ($svc->movimentosPendentes($limite) as $mov) {
        $id    = (int)$mov['id'];
        $perna = $mov['direcao'] === 'para_bling' ? 'a' : 'b';

        // Perna desligada: nem reivindica o movimento. Reivindicar contaria
        // uma tentativa, e o movimento chegaria ao teto sem ninguém ter
        // tentado nada — o contrário do que a fase 1 quer.
        if (!$svc->pernaLigada($perna)) {
            $contas['represados']++;
            continue;
        }

        if (!$svc->marcarEnviando($id)) {
            $log("movimento #{$id} já foi tomado por outro processo");
            continue;
        }

        try {
            $r = $svc->despachar($mov);

            if ($r['ok']) {
                $svc->confirmar($id, $r['resposta']);
                $contas['enviados']++;
                $log("movimento #{$id} confirmado");
                continue;
            }

            $esgotou = $svc->falhar($id, $r['motivo'], $r['resposta']);
            $contas['falhas']++;
            $falhou = true;
            $log("movimento #{$id} falhou: {$r['motivo']}" . ($esgotou ? ' (esgotado)' : ''));

            if ($esgotou) {
                avisarFalhaCritica($mov, $r['motivo']);
            }

        } catch (\Throwable $e) {
            $esgotou = $svc->falhar($id, $e->getMessage());
            $contas['falhas']++;
            $falhou = true;
            LogService::exception($e, 'error', 'estoque', ['movimento_id' => $id]);
            if ($esgotou) {
                avisarFalhaCritica($mov, $e->getMessage());
            }
        }
    }

} catch (\Throwable $e) {
    fwrite(STDERR, 'estoque-worker ERRO: ' . $e->getMessage() . "\n");
    LogService::exception($e, 'critical', 'estoque');
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(1);
}

// ── Resumo ────────────────────────────────────────────
// Silencioso quando não houve nada: um cron de um minuto que sempre imprime
// enche o log com ruído que ninguém lê.
$houve = array_sum($contas) > 0;
if ($verbose || $houve) {
    printf(
        "%s | estoque-ponte | eventos=%d movimentos=%d enviados=%d represados=%d ignorados=%d falhas=%d resgatados=%d\n",
        date('Y-m-d H:i:s'),
        $contas['eventos'], $contas['movimentos'], $contas['enviados'],
        $contas['represados'], $contas['ignorados'], $contas['falhas'],
        $contas['resgatados']
    );
}

if ($contas['represados'] > 0 && $verbose) {
    echo "  nota: {$contas['represados']} movimento(s) aguardando — perna desligada "
       . "(estoque_ponte_perna_a / _b em `configuracoes`).\n";
}

flock($fp, LOCK_UN);
fclose($fp);
exit($falhou ? 1 : 0);


/**
 * Tentativas esgotadas é o momento de gritar: foi o pedido original do dono —
 * "o erro sendo computado em LogService critico". O sino avisa quem opera,
 * porque log que ninguém abre não avisa ninguém.
 */
function avisarFalhaCritica(array $mov, string $motivo): void
{
    $sku = (string)($mov['sku_codigo'] ?? '?');

    LogService::critical('Movimento de estoque esgotou as tentativas', [
        'movimento_id' => $mov['id']        ?? null,
        'direcao'      => $mov['direcao']   ?? null,
        'sku'          => $sku,
        'operacao'     => $mov['operacao']  ?? null,
        'quantidade'   => $mov['quantidade'] ?? null,
        'motivo'       => $motivo,
    ], 'estoque');

    try {
        NotificacaoService::criarBroadcast([
            'categoria' => 'estoque',
            'tipo'      => 'ponte_estoque_falhou',
            'titulo'    => "Baixa de estoque não foi aplicada — SKU {$sku}",
            'mensagem'  => mb_substr($motivo, 0, 180),
            'url'       => '/admin/estoque/ponte',
        ], 'todos_admins');
    } catch (\Throwable $e) {
        // O aviso não pode derrubar o worker: a falha já está registrada.
        LogService::exception($e, 'warning', 'estoque');
    }
}
