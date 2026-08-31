<?php
// views/admin/usuarios/index.php
// Variáveis: $usuarios, $nivelFiltro, $meuUsuarioId, $salvo
?>
<div class="ap-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 style="font-size:22px;font-weight:800;margin:0;">Usuários do painel</h1>
    <p style="color:var(--c-text-muted);margin:4px 0 0;font-size:13.5px;">
      Clique em um cargo para ver tudo que ele pode fazer
    </p>
  </div>
  <a href="<?= ADMIN_URL ?>/usuarios/novo" class="btn btn-primary">+ Novo usuário</a>
</div>

<?php if (!empty($salvo)): ?>
<div style="background:var(--success-lt);border:1px solid var(--success-bd);border-radius:10px;
     padding:12px 16px;margin:14px 0;font-size:13.5px;color:var(--success);">
  ✓ Usuário salvo com sucesso.
</div>
<?php endif; ?>

<!-- Barra de cargos: badge clicável = modal de capacidades; segundo clique filtra -->
<div style="display:flex;gap:8px;margin:16px 0;flex-wrap:wrap;align-items:center;">
  <a href="<?= ADMIN_URL ?>/usuarios" class="btn"
     style="<?= $nivelFiltro === null ? 'background:var(--c-dark);color:#fff;' : '' ?>">Todos</a>
  <?php foreach (Cargos::LISTA as $slug => $c): ?>
  <button type="button" class="badge badge-cargo" data-nivel="<?= $slug ?>"
          style="background:<?= $c['bg'] ?>;color:<?= $c['cor'] ?>;border:1px solid <?= $c['cor'] ?>33;
                 font-size:12px;font-weight:800;padding:6px 14px;border-radius:99px;cursor:pointer;
                 <?= $nivelFiltro === $slug ? 'outline:2px solid ' . $c['cor'] . ';' : '' ?>">
    <?= View::e($c['label']) ?> ⓘ
  </button>
  <?php endforeach; ?>
</div>

<?php if (empty($usuarios)): ?>
<div class="admin-card" style="padding:48px;text-align:center;color:var(--c-text-muted);">
  <div style="font-size:40px;margin-bottom:8px;">👥</div>
  <strong style="color:var(--c-dark);">Nenhum usuário neste filtro</strong>
</div>
<?php else: ?>

<div class="admin-card">
  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead>
      <tr style="text-align:left;color:var(--c-text-muted);font-size:11.5px;
                 text-transform:uppercase;letter-spacing:.4px;">
        <th style="padding:12px 18px;">Usuário</th>
        <th style="padding:12px 10px;">Cargo</th>
        <th style="padding:12px 10px;text-align:center;">Status</th>
        <th style="padding:12px 10px;">Último login</th>
        <th style="padding:12px 18px;text-align:right;">Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($usuarios as $u):
        $c      = Cargos::get($u['nivel']) ?? ['label' => $u['nivel'], 'cor' => 'var(--text-2)', 'bg' => 'var(--bg)'];
        $ehSelf = (int)$u['usuario_id'] === (int)$meuUsuarioId;
        $inativo = !(int)$u['ativo'];
      ?>
      <tr style="border-top:1px solid var(--c-border);<?= $inativo ? 'opacity:.55;' : '' ?>">
        <td style="padding:12px 18px;">
          <div style="font-weight:700;">
            <?= View::e($u['nome']) ?>
            <?php if ($ehSelf): ?>
              <span style="font-size:10.5px;color:var(--c-text-muted);">(você)</span>
            <?php endif; ?>
          </div>
          <div style="font-size:12px;color:var(--c-text-muted);"><?= View::e($u['email']) ?></div>
        </td>
        <td style="padding:12px 10px;">
          <button type="button" class="badge badge-cargo" data-nivel="<?= View::e($u['nivel']) ?>"
                  style="background:<?= $c['bg'] ?>;color:<?= $c['cor'] ?>;font-size:11px;
                         font-weight:800;padding:4px 12px;border-radius:99px;border:none;cursor:pointer;">
            <?= View::e($c['label']) ?>
          </button>
        </td>
        <td style="padding:12px 10px;text-align:center;">
          <span class="badge" style="background:<?= $inativo ? 'var(--danger-lt)' : 'var(--success-lt)' ?>;
                color:<?= $inativo ? 'var(--danger)' : 'var(--success)' ?>;font-size:11px;font-weight:700;">
            <?= $inativo ? 'Inativo' : 'Ativo' ?></span>
        </td>
        <td style="padding:12px 10px;font-size:12px;color:var(--c-text-muted);">
          <?= $u['ultimo_login'] ? date('d/m/Y H:i', strtotime($u['ultimo_login'])) : 'nunca' ?>
        </td>
        <td style="padding:12px 18px;text-align:right;white-space:nowrap;">
          <a href="<?= ADMIN_URL ?>/usuarios/<?= (int)$u['admin_id'] ?>" class="btn"
             style="font-size:12px;">Editar</a>
          <?php if (!$ehSelf): ?>
          <button class="btn usr-toggle" data-id="<?= (int)$u['admin_id'] ?>"
                  style="font-size:12px;color:<?= $inativo ? 'var(--success)' : 'var(--danger)' ?>;">
            <?= $inativo ? 'Ativar' : 'Desativar' ?></button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Modal de capacidades do cargo (dados do Cargos.php — fonte única) -->
<div id="modal-cargo" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);
     z-index:200;align-items:center;justify-content:center;padding:20px;">
  <div style="background:var(--surface);border-radius:14px;max-width:480px;width:100%;
              max-height:85vh;overflow-y:auto;padding:24px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
      <span id="mc-badge" class="badge" style="font-size:12px;font-weight:800;
            padding:5px 14px;border-radius:99px;"></span>
    </div>
    <p id="mc-desc" style="margin:8px 0 16px;font-size:13px;color:var(--c-text-muted);"></p>
    <div id="mc-caps"></div>
    <div style="display:flex;justify-content:flex-end;margin-top:16px;">
      <button class="btn" id="mc-fechar">Fechar</button>
    </div>
  </div>
</div>

<script>
jQuery(function ($) {
  var CARGOS = <?= json_encode(Cargos::paraJson(), JSON_UNESCAPED_UNICODE) ?>;
  var CSRF   = $('meta[name="csrf-token"]').attr('content');

  /* Modal de capacidades — reutilizável por qualquer badge .badge-cargo */
  $(document).on('click', '.badge-cargo', function () {
    var c = CARGOS[$(this).data('nivel')];
    if (!c) return;
    $('#mc-badge').text(c.label).css({ background: c.bg, color: c.cor });
    $('#mc-desc').text(c.descricao);

    var $caps = $('#mc-caps').empty();
    Object.keys(c.capacidades).forEach(function (modulo) {
      var $bloco = $('<div style="margin-bottom:14px;">');
      $('<div style="font-size:11.5px;font-weight:800;text-transform:uppercase;' +
        'letter-spacing:.4px;color:var(--text-2);margin-bottom:6px;">').text(modulo).appendTo($bloco);
      var $ul = $('<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.7;">');
      c.capacidades[modulo].forEach(function (item) {
        $('<li>').text(item).appendTo($ul);
      });
      $bloco.append($ul).appendTo($caps);
    });
    $('#modal-cargo').css('display', 'flex');
  });
  $('#mc-fechar').on('click', function () { $('#modal-cargo').hide(); });

  /* Toggle ativo */
  $('.usr-toggle').on('click', function () {
    var id = $(this).data('id');
    $.post('<?= ADMIN_URL ?>/usuarios/' + id + '/toggle', { _csrf: CSRF }, null, 'json')
      .done(function (r) { r.ok ? location.reload() : alert(r.msg); })
      .fail(function () { alert('Erro de rede.'); });
  });
});
</script>