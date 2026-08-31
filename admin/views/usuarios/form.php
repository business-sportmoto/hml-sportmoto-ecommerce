<?php
// views/admin/usuarios/form.php  (v2)
// Novo fluxo: busca usuário existente → promove. Sem senha.
// Variáveis: $usuario (null=novo/promover; array=editar), $erro
$editando = !empty($usuario['admin_id']);
$ehSelf   = !empty($usuario['eh_self']);
$nivelSel = $usuario['nivel'] ?? 'vendedor';
?>
<div class="ap-page-header" style="display:flex;align-items:center;gap:14px;">
  <a href="<?= ADMIN_URL ?>/usuarios" class="btn">← Voltar</a>
  <h1 style="font-size:20px;font-weight:800;margin:0;">
    <?= $editando ? 'Editar acesso' : 'Conceder acesso ao painel' ?>
  </h1>
</div>

<?php if (!empty($erro)): ?>
<div style="background:var(--danger-lt);border:1px solid var(--danger-bd);border-radius:10px;
     padding:12px 16px;margin:14px 0;font-size:13.5px;color:var(--danger);">⚠ <?= View::e($erro) ?></div>
<?php endif; ?>

<?php if (!$editando): // ── FLUXO: BUSCAR + PROMOVER ── ?>

<div class="admin-card" style="padding:18px;max-width:640px;">
  <div style="background:var(--blue-lt);border:1px solid var(--blue-bd);border-radius:10px;
       padding:12px 16px;margin-bottom:18px;font-size:13px;color:var(--blue);">
    ℹ️ O acesso é concedido a um usuário <strong>já cadastrado</strong> na loja.
    Ele usa a <strong>senha de login que já possui</strong> — nenhuma senha é criada aqui.
  </div>

  <div class="form-group">
    <label class="form-label">Buscar usuário por e-mail ou CPF</label>
    <div style="display:flex;gap:8px;">
      <input type="text" id="busca-termo" class="form-control"
             placeholder="email@exemplo.com ou 000.000.000-00" autocomplete="off">
      <button type="button" class="btn btn-primary" id="btn-buscar" style="white-space:nowrap;">
        🔍 Buscar</button>
    </div>
    <span class="form-hint" id="busca-hint">A busca localiza clientes e usuários existentes.</span>
  </div>

  <!-- Resultado + confirmação (revelado via Ajax) -->
  <form method="post" action="<?= ADMIN_URL ?>/usuarios/promover" id="form-promover"
        style="display:none;margin-top:18px;border-top:1px solid var(--c-border);padding-top:18px;">
    <?= SecurityHelper::csrfField() ?>
    <input type="hidden" name="usuario_id" id="pr-usuario-id">

    <div style="background:var(--success-lt);border:1px solid var(--success-bd);border-radius:10px;
         padding:14px 16px;margin-bottom:16px;">
      <div style="font-weight:800;font-size:15px;" id="pr-nome"></div>
      <div style="font-size:13px;color:var(--c-text-muted);" id="pr-email"></div>
      <div id="pr-aviso-vend" style="display:none;font-size:12px;color:var(--warning);margin-top:6px;"></div>
    </div>

    <div class="form-group">
      <label class="form-label">Cargo *</label>
      <div style="display:flex;flex-wrap:wrap;gap:10px;" id="cargo-opts">
        <?php foreach (Cargos::LISTA as $slug => $c): ?>
        <label class="cargo-opt" data-nivel="<?= $slug ?>"
               style="border:2px solid var(--c-border);border-radius:10px;padding:10px 14px;
                      cursor:pointer;display:flex;align-items:center;gap:8px;">
          <input type="radio" name="nivel" value="<?= $slug ?>"
                 style="display:none;" <?= $slug === 'vendedor' ? 'checked' : '' ?>>
          <span class="badge" style="background:<?= $c['bg'] ?>;color:<?= $c['cor'] ?>;
                font-size:11.5px;font-weight:800;padding:4px 12px;border-radius:99px;">
            <?= View::e($c['label']) ?></span>
          <button type="button" class="badge-cargo-info" data-nivel="<?= $slug ?>"
                  style="border:none;background:none;cursor:pointer;font-size:14px;
                         color:var(--c-text-muted);">ⓘ</button>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Código do vendedor: só aparece se cargo = vendedor -->
    <div class="form-group" id="grupo-codigo" style="display:none;">
      <label class="form-label">Código do vendedor</label>
      <div style="display:flex;gap:8px;">
        <input type="text" name="codigo_vendedor" id="inp-codigo" class="form-control"
               maxlength="12" style="text-transform:uppercase;font-family:ui-monospace,monospace;
               font-weight:700;letter-spacing:1px;" placeholder="Gerado automaticamente">
        <button type="button" class="btn" id="btn-regen" style="white-space:nowrap;">🎲 Gerar</button>
      </div>
      <span class="form-hint">Gerado das iniciais do nome. Editável — 3 a 12 letras/números.
        É o código usado no checkout e nos relatórios de comissão.</span>
    </div>

    <div id="aviso-super" style="display:none;background:var(--purple-lt);border:1px solid var(--purple-bd);
         border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12.5px;color:var(--purple);">
      ⚠️ <strong>Super Admin tem acesso TOTAL</strong>, incluindo gestão de usuários e integrações.
    </div>

    <button type="submit" class="btn btn-primary" style="min-width:180px;">
      Conceder acesso</button>
  </form>
</div>

<?php else: // ── FLUXO: EDITAR ACESSO EXISTENTE ── ?>

<?php if ($ehSelf): ?>
<div style="background:var(--warning-lt);border:1px solid var(--warning-bd);border-radius:10px;
     padding:12px 16px;margin:14px 0;font-size:13px;color:var(--warning);">
  🔒 Você está editando a própria conta: cargo e status não podem ser alterados por você mesmo.
</div>
<?php endif; ?>

<form method="post" action="<?= ADMIN_URL ?>/usuarios/<?= (int)$usuario['admin_id'] ?>">
  <?= SecurityHelper::csrfField() ?>
  <div class="admin-card" style="padding:18px;display:grid;gap:18px;max-width:640px;">

    <div class="form-group">
      <label class="form-label">Nome</label>
      <input type="text" name="nome" class="form-control" maxlength="120" required
             value="<?= View::e($usuario['nome']) ?>">
    </div>
    <div class="form-group">
      <label class="form-label">E-mail</label>
      <input type="email" class="form-control" value="<?= View::e($usuario['email']) ?>" disabled>
      <span class="form-hint">Identidade de login — imutável. Senha é a do próprio usuário.</span>
    </div>

    <div class="form-group">
      <label class="form-label">Cargo *</label>
      <div style="display:flex;flex-wrap:wrap;gap:10px;">
        <?php foreach (Cargos::LISTA as $slug => $c): ?>
        <label class="cargo-opt" data-nivel="<?= $slug ?>"
               style="border:2px solid <?= $nivelSel === $slug ? $c['cor'] : 'var(--c-border)' ?>;
                      border-radius:10px;padding:10px 14px;cursor:<?= $ehSelf ? 'not-allowed' : 'pointer' ?>;
                      display:flex;align-items:center;gap:8px;<?= $ehSelf && $nivelSel !== $slug ? 'opacity:.4;' : '' ?>">
          <input type="radio" name="nivel" value="<?= $slug ?>" style="display:none;"
                 <?= $nivelSel === $slug ? 'checked' : '' ?> <?= $ehSelf ? 'disabled' : '' ?>>
          <span class="badge" style="background:<?= $c['bg'] ?>;color:<?= $c['cor'] ?>;
                font-size:11.5px;font-weight:800;padding:4px 12px;border-radius:99px;">
            <?= View::e($c['label']) ?></span>
          <button type="button" class="badge-cargo-info" data-nivel="<?= $slug ?>"
                  style="border:none;background:none;cursor:pointer;font-size:14px;
                         color:var(--c-text-muted);">ⓘ</button>
        </label>
        <?php endforeach; ?>
      </div>
      <?php if ($ehSelf): ?><input type="hidden" name="nivel" value="<?= View::e($nivelSel) ?>"><?php endif; ?>
    </div>

    <div class="form-group" id="grupo-codigo"
         style="<?= $nivelSel !== 'vendedor' ? 'display:none;' : '' ?>">
      <label class="form-label">Código do vendedor</label>
      <div style="display:flex;gap:8px;">
        <input type="text" name="codigo_vendedor" id="inp-codigo" class="form-control"
               maxlength="12" value="<?= View::e($usuario['codigo_vendedor'] ?? '') ?>"
               style="text-transform:uppercase;font-family:ui-monospace,monospace;
               font-weight:700;letter-spacing:1px;"
               placeholder="Deixe em branco para gerar">
        <button type="button" class="btn" id="btn-regen" style="white-space:nowrap;">🎲 Gerar</button>
      </div>
      <?php if (!empty($usuario['codigo_vendedor']) && !(int)($usuario['vendedor_ativo'] ?? 0)): ?>
        <span class="form-hint" style="color:var(--warning);">Este código existe mas está inativo —
          selecionar "Vendedor" o reativa.</span>
      <?php endif; ?>
    </div>

    <?php if (!$ehSelf): ?>
    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13.5px;">
      <input type="checkbox" name="ativo" value="1" <?= (int)$usuario['ativo'] ? 'checked' : '' ?>>
      Usuário ativo (pode fazer login)
    </label>
    <?php endif; ?>
  </div>

  <div style="display:flex;gap:10px;margin-top:14px;align-items:center;">
    <button type="submit" class="btn btn-primary" style="min-width:160px;">Salvar</button>
    <a href="<?= ADMIN_URL ?>/usuarios" class="btn">Cancelar</a>
    <?php if (!empty($usuario['codigo_vendedor'])): ?>
    <a href="<?= ADMIN_URL ?>/usuarios/vendas/<?= (int)$usuario['admin_id'] ?>"
       class="btn" style="margin-left:auto;">📊 Ver vendas</a>
    <?php endif; ?>
  </div>
</form>
<?php endif; ?>

<!-- Modal de capacidades (reutilizado) -->
<div id="modal-cargo" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);
     z-index:200;align-items:center;justify-content:center;padding:20px;">
  <div style="background:var(--surface);border-radius:14px;max-width:480px;width:100%;
              max-height:85vh;overflow-y:auto;padding:24px;">
    <span id="mc-badge" class="badge" style="font-size:12px;font-weight:800;padding:5px 14px;border-radius:99px;"></span>
    <p id="mc-desc" style="margin:8px 0 16px;font-size:13px;color:var(--c-text-muted);"></p>
    <div id="mc-caps"></div>
    <div style="display:flex;justify-content:flex-end;margin-top:16px;">
      <button type="button" class="btn" id="mc-fechar">Fechar</button>
    </div>
  </div>
</div>

<script>
jQuery(function ($) {
  var CARGOS  = <?= json_encode(Cargos::paraJson(), JSON_UNESCAPED_UNICODE) ?>;
  var CSRF    = $('meta[name="csrf-token"]').attr('content');
  var EH_SELF = <?= $ehSelf ? 'true' : 'false' ?>;

  /* ── Modal de capacidades ── */
  function abrirModal(nivel) {
    var c = CARGOS[nivel]; if (!c) return;
    $('#mc-badge').text(c.label).css({ background: c.bg, color: c.cor });
    $('#mc-desc').text(c.descricao);
    var $caps = $('#mc-caps').empty();
    Object.keys(c.capacidades).forEach(function (m) {
      var $b = $('<div style="margin-bottom:14px;">');
      $('<div style="font-size:11.5px;font-weight:800;text-transform:uppercase;' +
        'letter-spacing:.4px;color:var(--text-2);margin-bottom:6px;">').text(m).appendTo($b);
      var $ul = $('<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.7;">');
      c.capacidades[m].forEach(function (i) { $('<li>').text(i).appendTo($ul); });
      $b.append($ul).appendTo($caps);
    });
    $('#modal-cargo').css('display', 'flex');
  }
  $(document).on('click', '.badge-cargo-info', function (e) {
    e.preventDefault(); e.stopPropagation(); abrirModal($(this).data('nivel'));
  });
  $('#mc-fechar').on('click', function () { $('#modal-cargo').hide(); });

  /* ── Seleção de cargo + reação (código vendedor / aviso super) ── */
  function aoTrocarCargo(nivel) {
    $('#grupo-codigo').toggle(nivel === 'vendedor');
    $('#aviso-super').toggle(nivel === 'super');
  }
  $('.cargo-opt').on('click', function () {
    if (EH_SELF) return;
    var nivel = $(this).data('nivel');
    $(this).find('input[type=radio]').prop('checked', true);
    $('.cargo-opt').css('border-color', 'var(--c-border)');
    $(this).css('border-color', CARGOS[nivel].cor);
    aoTrocarCargo(nivel);
  });
  aoTrocarCargo($('input[name=nivel]:checked').val());

<?php if (!$editando): ?>
  /* ── Busca Ajax de usuário ── */
  function buscar() {
    var termo = $('#busca-termo').val().trim();
    if (termo.length < 3) { $('#busca-hint').text('Digite ao menos 3 caracteres.'); return; }

    $('#btn-buscar').prop('disabled', true).text('Buscando…');
    $.getJSON('<?= ADMIN_URL ?>/usuarios/buscar', { termo: termo })
      .done(function (r) {
        if (!r.ok) {
          $('#form-promover').hide();
          $('#busca-hint').text(r.msg).css('color', 'var(--danger)');
          return;
        }
        var u = r.usuario;
        $('#pr-usuario-id').val(u.id);
        $('#pr-nome').text(u.nome);
        $('#pr-email').text(u.email);
        $('#inp-codigo').val(u.sugestao_cod);

        if (u.ja_vendedor) {
          $('#pr-aviso-vend').text('⚠ Já possui código de vendedor: ' + u.ja_vendedor +
            ' (será reativado se mantiver o cargo Vendedor)').show();
        } else {
          $('#pr-aviso-vend').hide();
        }
        $('#busca-hint').text('Usuário encontrado.').css('color', 'var(--success)');
        $('#form-promover').slideDown(150);
      })
      .fail(function () { $('#busca-hint').text('Erro de rede.').css('color', 'var(--danger)'); })
      .always(function () { $('#btn-buscar').prop('disabled', false).text('🔍 Buscar'); });
  }
  $('#btn-buscar').on('click', buscar);
  $('#busca-termo').on('keypress', function (e) { if (e.which === 13) { e.preventDefault(); buscar(); } });
<?php endif; ?>

  /* ── Gerar/regerar código (client-side, prévia; server valida) ── */
  $('#btn-regen').on('click', function () {
    var nome = <?= $editando
        ? json_encode($usuario['nome'] ?? '', JSON_UNESCAPED_UNICODE)
        : "($('#pr-nome').text() || '')" ?>;
    var ini = (nome.trim().split(/\s+/).map(function (p) { return p.charAt(0); })
                 .join('').replace(/[^A-Za-zÀ-ÿ]/g, '').toUpperCase().slice(0, 2)) || 'VD';
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', suf = '';
    var buf = new Uint32Array(3); window.crypto.getRandomValues(buf);
    for (var i = 0; i < 3; i++) suf += chars[buf[i] % chars.length];
    $('#inp-codigo').val(ini + suf);
  });
});
</script>