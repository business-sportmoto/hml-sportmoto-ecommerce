<?php
/**
 * views/admin/fluxos/index.php
 * @var array $fluxos  @var array $catalogo
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$badge = [
    'rascunho'  => ['Rascunho', '#71717a'],
    'publicado' => ['Publicado', '#16a34a'],
    'pausado'   => ['Pausado', '#f59e0b'],
];
?>
<div class="em_wrapper">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;">
    <div>
      <h2 style="font-size:19px;font-weight:600;margin:0 0 3px;">Automação v2 — Fluxos</h2>
      <p style="font-size:13px;color:var(--em-text-muted);margin:0;">Motor de grafo com condições, esperas e múltiplos canais.</p>
    </div>
    <button type="button" id="fx-novo" class="ntfa-btn-pri" style="width:auto;padding:9px 18px;">
      <i class="bi bi-plus-lg"></i> Novo fluxo
    </button>
  </div>

  <div class="cl-card">
    <?php if (empty($fluxos)): ?>
      <p style="padding:28px;text-align:center;color:var(--em-text-muted);font-size:13px;">
        Nenhum fluxo ainda. Crie o primeiro.
      </p>
    <?php else: foreach ($fluxos as $f):
      [$lbl, $cor] = $badge[$f['status']] ?? [$f['status'], '#71717a']; ?>
    <div style="display:flex;align-items:center;gap:14px;padding:14px 16px;border-bottom:0.5px solid var(--em-border);">
      <div style="flex:1;min-width:0;">
        <a href="<?= $base ?>/admin/fluxos/<?= (int)$f['id'] ?>"
           style="font-size:14px;font-weight:600;color:inherit;text-decoration:none;">
          <?= htmlspecialchars($f['nome']) ?>
        </a>
        <p style="font-size:12px;color:var(--em-text-muted);margin:3px 0 0;">
          v<?= (int)$f['versao_publicada'] ?>
          · <?= (int)$f['em_andamento'] ?> em andamento
          · <?= (int)$f['concluidas'] ?> concluídas
        </p>
      </div>
      <span style="font-size:11px;font-weight:700;color:<?= $cor ?>;background:<?= $cor ?>18;padding:4px 10px;border-radius:12px;"><?= $lbl ?></span>
      <a href="<?= $base ?>/admin/fluxos/<?= (int)$f['id'] ?>" class="ntfa-btn-sec" style="text-decoration:none;">Editar</a>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<script>
(function ($) {
  $('#fx-novo').on('click', function () {
    var nome = prompt('Nome do fluxo:');
    if (!nome) return;
    $.post('<?= $base ?>/admin/fluxos/criar', {
      nome: nome,
      csrf_token: $('meta[name=csrf-token]').attr('content') || window.CSRF_TOKEN || ''
    }, function (r) {
      if (r.ok) window.location.href = '<?= $base ?>/admin/fluxos/' + r.id;
      else alert(r.erro || 'Falha ao criar.');
    }, 'json');
  });
})(jQuery);
</script>
