<?php
/**
 * Card do quadro (kanban) da Central de Recuperação.
 *
 * Recebe: $rec (uma linha do `listar()`), $ehGestor.
 *
 * Mora num arquivo só porque é usado em dois lugares — na primeira carga do
 * quadro e no "carregar mais" de cada coluna (`View::capture` no controller).
 * Card duplicado em JS sairia do lugar na primeira mudança.
 */

if (!function_exists('crTempoDesde')) {
    function crTempoDesde(?string $dt): string {
        if (!$dt) return '—';
        $s = time() - strtotime($dt);
        if ($s < 3600)  return max(1, (int) floor($s / 60)) . 'min';
        if ($s < 86400) return (int) floor($s / 3600) . 'h';
        if ($s < 2592000) return (int) floor($s / 86400) . 'd';
        return (int) floor($s / 2592000) . 'mes';
    }
}

if (!function_exists('crStatusRotulo')) {
    function crStatusRotulo(string $s): string {
        return [
            'novo'                => 'Novo',
            'abandonado'          => 'Abandonado',
            'em_recuperacao'      => 'Em recuperação',
            'msg_enviada'         => 'Msg enviada',
            'aguardando_resposta' => 'Aguardando',
            'respondeu'           => 'Respondeu',
            'negociacao'          => 'Negociação',
            'recuperado'          => 'Recuperado',
            'perdido'             => 'Perdido',
            'ignorado'            => 'Ignorado',
            'sem_contato'         => 'Sem contato',
        ][$s] ?? $s;
    }
}

$prio    = (string) ($rec['prioridade'] ?? 'baixa');
$corPrio = [
    'imediata' => 'var(--danger)',
    'alta'     => 'var(--warning)',
    'media'    => 'var(--blue, #3b82f6)',
    'baixa'    => 'var(--border)',
][$prio] ?? 'var(--border)';

$valor    = (float) ($rec['valor_snapshot'] ?? 0);
$agendado = $rec['proximo_contato_em'] ?? null;
$atrasado = $agendado && strtotime($agendado) < time();
?>
<article class="cr-card" draggable="true"
         data-id="<?= (int) $rec['id'] ?>"
         data-status="<?= View::e((string) $rec['status']) ?>"
         style="--cr-prio:<?= $corPrio ?>">

  <div class="cr-card-topo">
    <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/<?= (int) $rec['id'] ?>"
       class="cr-card-nome" title="Abrir o carrinho">
      <?= View::e($rec['cliente_nome'] ?: 'Visitante não identificado') ?>
    </a>
    <span class="cr-card-tempo" title="Abandonado há">
      <?= crTempoDesde($rec['abandonado_em'] ?? null) ?>
    </span>
  </div>

  <div class="cr-card-valor"><?= PriceHelper::format($valor) ?></div>

  <div class="cr-card-tags">
    <span class="cr-tag cr-tag--status"><?= View::e(crStatusRotulo((string) $rec['status'])) ?></span>
    <?php if ($prio === 'imediata' || $prio === 'alta'): ?>
    <span class="cr-tag cr-tag--prio"><?= $prio === 'imediata' ? 'Imediata' : 'Alta' ?></span>
    <?php endif; ?>
    <?php if ((int) ($rec['pedidos_anteriores'] ?? 0) > 0): ?>
    <span class="cr-tag" title="Já comprou antes"><?= (int) $rec['pedidos_anteriores'] ?>ª compra</span>
    <?php endif; ?>
  </div>

  <div class="cr-card-rodape">
    <span class="cr-card-dono<?= empty($rec['responsavel_id']) ? ' cr-card-dono--pool' : '' ?>">
      <?= empty($rec['responsavel_id'])
            ? 'no pool'
            : View::e($rec['responsavel_nome'] ?: 'responsável #' . (int) $rec['responsavel_id']) ?>
    </span>

    <span class="cr-card-canais">
      <?php if (!empty($rec['cliente_telefone'])): ?><span title="Tem telefone">☎</span><?php endif; ?>
      <?php if (!empty($rec['cliente_email'])): ?><span title="Tem e-mail">✉</span><?php endif; ?>
      <?php if (empty($rec['cliente_telefone']) && empty($rec['cliente_email'])): ?>
      <span title="Sem canal de contato" style="color:var(--danger)">sem contato</span>
      <?php endif; ?>
    </span>
  </div>

  <?php if ($agendado): ?>
  <div class="cr-card-agenda<?= $atrasado ? ' cr-card-agenda--atrasada' : '' ?>">
    <?= $atrasado ? 'Contato atrasado desde' : 'Contato em' ?>
    <?= date('d/m H:i', strtotime($agendado)) ?>
  </div>
  <?php endif; ?>
</article>
