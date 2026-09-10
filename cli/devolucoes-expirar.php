<?php
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════════════
 * cli/devolucoes-expirar.php
 *
 * Fecha solicitação de devolução que o cliente abandonou depois de receber
 * o código de postagem.
 *
 * ── O QUE EXPIRA, E O QUE NÃO ────────────────────────────────────
 *
 * SÓ `aguardando_postagem`. É o único estado em que a bola está com o
 * CLIENTE e o relógio é dele: a loja já emitiu (e pagou) a etiqueta reversa,
 * e ele não postou.
 *
 * Os outros estados abertos ficam de fora de propósito:
 *
 *   aguardando_aprovacao  → esperando a LOJA analisar
 *   pre_aprovado          → idem
 *   aprovado              → esperando a LOJA emitir a etiqueta
 *   em_transito_reverso   → já postou; o pacote está a caminho
 *   item_recebido         → chegou; esperando a LOJA inspecionar
 *
 * Expirar qualquer um deles seria punir o cliente pela demora da loja. Se
 * essas filas crescerem, o problema é operacional, não de prazo.
 *
 * ── O PRAZO ──────────────────────────────────────────────────────
 *
 * Conta a partir do EVENTO `aguardando_postagem` no histórico da
 * solicitação — o momento em que o código chegou às mãos do cliente. Não
 * usa `atualizado_em`, que qualquer alteração da linha empurra para frente
 * (a mesma armadilha que tirou o prazo do CDC de `pedidos.atualizado_em`).
 *
 * A janela é `codigo_validade_dias`, gravado pela transportadora quando a
 * etiqueta é emitida. Onde ele não veio, cai no piso: passado o prazo da
 * autorização de postagem, o código não é mais aceito na agência — manter a
 * solicitação aberta depois disso só engana o cliente.
 *
 * ── O QUE ACONTECE AO EXPIRAR ────────────────────────────────────
 *
 *   1. solicitação  → `expirado`
 *   2. reversa       → cancelada no módulo de logística (libera a linha)
 *   3. pedido        → volta para `entregue` (nada foi devolvido)
 *   4. cliente       → avisado, e pode abrir outra se o prazo do CDC permitir
 *
 * ── USO ──────────────────────────────────────────────────────────
 *
 *   php cli/devolucoes-expirar.php --dry-run   → só mostra o que faria
 *   php cli/devolucoes-expirar.php --limite=50
 *   php cli/devolucoes-expirar.php --sem-confirmacao --silencioso
 *
 *     --dry-run          não grava nada
 *     --sem-confirmacao  não pergunta (para o cron)
 *     --silencioso       não manda e-mail — use ao limpar backlog antigo,
 *                        para não disparar avisos sobre coisa de meses atrás
 *     --limite=N         teto de solicitações por execução (padrão 200)
 * ════════════════════════════════════════════════════════════════
 */

require __DIR__ . '/../bootstrap-cli.php';

// Piso quando a transportadora não informou a validade da autorização.
const PRAZO_POSTAGEM_DIAS = 20;

$dryRun         = in_array('--dry-run', $argv, true);
$semConfirmacao = in_array('--sem-confirmacao', $argv, true);
$silencioso     = in_array('--silencioso', $argv, true);

$limite = 200;
foreach ($argv as $a) {
    if (preg_match('/^--limite=(\d+)$/', $a, $m)) $limite = max(1, (int) $m[1]);
}

$db  = Database::getInstance()->getConnection();
$svc = new DevolucaoService();

// ── Quem passou do prazo ─────────────────────────────────────────
//
// O prazo é calculado no SQL para a seleção não depender do fuso do PHP
// bater com o do banco.
//
// MIN(h.criado_em): o primeiro evento `aguardando_postagem`. Se a etiqueta
// foi regerada (gerarPostagem aceita 'aguardando_postagem' justamente para
// isso), o relógio NÃO reinicia — senão bastaria regerar para a solicitação
// nunca vencer.
$sql = "
    SELECT sd.id,
           sd.status,
           sd.tipo,
           sd.pedido_id,
           sd.cliente_id,
           sd.reversa_id,
           sd.codigo_postagem_reversa,
           COALESCE(sd.codigo_validade_dias, :piso) AS validade_dias,
           MIN(h.criado_em)                          AS liberado_em,
           DATE_ADD(MIN(h.criado_em),
                    INTERVAL COALESCE(sd.codigo_validade_dias, :piso2) DAY) AS vence_em,
           p.codigo AS pedido_codigo,
           u.nome   AS cliente_nome
      FROM solicitacoes_devolucao sd
      JOIN solicitacoes_devolucao_historico h
        ON h.solicitacao_id = sd.id AND h.status_novo = 'aguardando_postagem'
      JOIN pedidos  p ON p.id = sd.pedido_id
      JOIN clientes c ON c.id = sd.cliente_id
      JOIN usuarios u ON u.id = c.usuario_id
     WHERE sd.status = 'aguardando_postagem'
  GROUP BY sd.id, sd.status, sd.tipo, sd.pedido_id, sd.cliente_id, sd.reversa_id,
           sd.codigo_postagem_reversa, sd.codigo_validade_dias, p.codigo, u.nome
    HAVING vence_em < NOW()
  ORDER BY vence_em ASC
     LIMIT {$limite}";

$stmt = $db->prepare($sql);
$stmt->execute([':piso' => PRAZO_POSTAGEM_DIAS, ':piso2' => PRAZO_POSTAGEM_DIAS]);
$alvos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$modo = [];
if ($dryRun)     $modo[] = 'dry-run';
if ($silencioso) $modo[] = 'silencioso';

echo "\n══ Devoluções abandonadas na postagem ══\n";
echo 'piso: ' . PRAZO_POSTAGEM_DIAS . " dias · limite: {$limite}"
   . ($modo ? ' · ' . implode(', ', $modo) : '') . "\n\n";

if (!$alvos) {
    echo "Nenhuma solicitação venceu o prazo de postagem.\n\n";
    exit(0);
}

foreach ($alvos as $a) {
    printf(
        "  #%-4s %-9s pedido %-12s %-22s liberado %s · vence %s (%s dias)\n",
        $a['id'],
        $a['tipo'],
        $a['pedido_codigo'],
        mb_substr((string) $a['cliente_nome'], 0, 22),
        date('d/m/Y', strtotime((string) $a['liberado_em'])),
        date('d/m/Y', strtotime((string) $a['vence_em'])),
        $a['validade_dias']
    );
}
echo "\n" . count($alvos) . " solicitação(ões) a expirar.\n";

if ($dryRun) {
    echo "\n[dry-run] nada foi gravado.\n\n";
    exit(0);
}

if (!$semConfirmacao) {
    echo "\nConfirma? [s/N] ";
    $r = trim((string) fgets(STDIN));
    if (strtolower($r) !== 's') { echo "Cancelado.\n\n"; exit(0); }
}

// ── Execução ─────────────────────────────────────────────────────
$reversa = new ReversaService();
$pedidos = new AdminPedidoService();
$email   = new EmailService();

$okCount = 0;
$erros   = [];

foreach ($alvos as $a) {
    $solId = (int) $a['id'];

    try {
        // Reconfere o status: a solicitação pode ter andado entre a seleção e
        // agora (o cliente postou e informou o rastreio neste intervalo).
        $atual = $db->prepare("SELECT status FROM solicitacoes_devolucao WHERE id = ? LIMIT 1");
        $atual->execute([$solId]);
        if ((string) $atual->fetchColumn() !== 'aguardando_postagem') {
            $erros[] = "#{$solId}: saiu de aguardando_postagem antes da execução";
            continue;
        }

        $db->prepare(
            "UPDATE solicitacoes_devolucao
                SET status = 'expirado', atualizado_em = NOW()
              WHERE id = ? AND status = 'aguardando_postagem'"
        )->execute([$solId]);

        $db->prepare(
            "INSERT INTO solicitacoes_devolucao_historico (solicitacao_id, status_novo, observacao, criado_em)
             VALUES (?, 'expirado', ?, NOW())"
        )->execute([
            $solId,
            'Prazo de postagem encerrado em ' . date('d/m/Y', strtotime((string) $a['vence_em']))
            . '. O código de postagem não é mais válido.',
        ]);

        // Libera a reversa no módulo de logística. A etiqueta já foi paga —
        // cancelar não devolve o dinheiro, mas tira a linha da fila de quem
        // acompanha reversas em aberto.
        if (!empty($a['reversa_id'])) {
            try {
                $reversa->cancelar((int) $a['reversa_id'], null);
            } catch (\Throwable $e) {
                LogService::warning('devolucao-expirar: falha ao cancelar reversa', [
                    'solicitacao_id' => $solId,
                    'reversa_id'     => (int) $a['reversa_id'],
                    'erro'           => $e->getMessage(),
                ]);
            }
        }

        // Nada voltou para a loja, então o pedido segue sendo uma venda
        // entregue. `devolvido` tem classe_bi = devolucao e faria o painel
        // descontar receita que continua na casa.
        $pedidos->mudarStatus(
            (int) $a['pedido_id'],
            'entregue',
            "Solicitação de devolução #{$solId} expirada: o produto não foi postado no prazo.",
            0,
            false
        );

        if (!$silencioso) {
            try {
                $sol    = $svc->findById($solId);
                $pedido = $db->prepare("SELECT * FROM pedidos WHERE id = ? LIMIT 1");
                $pedido->execute([(int) $a['pedido_id']]);
                $email->devolucaoNegada(
                    $sol,
                    $pedido->fetch(PDO::FETCH_ASSOC) ?: [],
                    'O prazo para postar o produto terminou e o código de postagem perdeu a validade. '
                    . 'Se ainda estiver dentro dos 7 dias da entrega, você pode abrir uma nova solicitação.'
                );
            } catch (\Throwable $e) {
                LogService::warning('devolucao-expirar: falha ao enviar e-mail', [
                    'solicitacao_id' => $solId, 'erro' => $e->getMessage(),
                ]);
            }
        }

        $okCount++;
        printf("  ✓ #%-4s expirada\n", $solId);

    } catch (\Throwable $e) {
        $erros[] = "#{$solId}: " . $e->getMessage();
        printf("  ✗ #%-4s %s\n", $solId, $e->getMessage());
    }
}

echo "\n── Resultado ──\n";
echo "  expiradas: {$okCount}\n";
if ($erros) {
    echo '  falhas:    ' . count($erros) . "\n";
    foreach ($erros as $e) echo "    - {$e}\n";
}
echo "\n";

LogService::info('devolucoes-expirar', [
    'expiradas' => $okCount,
    'falhas'    => count($erros),
    'silencioso'=> $silencioso,
]);
