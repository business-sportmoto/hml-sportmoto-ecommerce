<?php
/**
 * View: mapa de redirecionamentos (admin, só super).
 */
$paginas = (int) ceil(max(1, (int) $total) / max(1, (int) $porPagina));
$tipos   = [301 => 'Definitivo', 302 => 'Temporário', 410 => 'Acabou'];
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <h1>Redirecionamentos</h1>
      <p>Para onde vai cada endereço antigo. Consultado só quando a página não existe.</p>
    </div>
    <div style="display:flex;gap:8px;">
      <a href="<?= BASE_URL ?>/admin/seo/404" class="btn btn-outline">Erros 404</a>
      <button type="button" class="btn btn-primary" id="btn-novo-redir">Novo redirecionamento</button>
    </div>
  </div>

  <div class="admin-card">
    <div class="admin-card-header">
      <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;width:100%;">
        <input type="text" name="busca" class="form-control" style="max-width:280px;"
               value="<?= View::e($filtros['busca']) ?>" placeholder="Buscar origem ou destino...">

        <select name="tipo" class="form-control" style="max-width:170px;">
          <option value="">Todos os tipos</option>
          <?php foreach ($tipos as $v => $l): ?>
          <option value="<?= $v ?>" <?= (int) $filtros['tipo'] === $v ? 'selected' : '' ?>><?= $v ?> — <?= $l ?></option>
          <?php endforeach; ?>
        </select>

        <select name="ativo" class="form-control" style="max-width:150px;">
          <option value="">Ativos e pausados</option>
          <option value="1" <?= $filtros['ativo'] === 1 ? 'selected' : '' ?>>Só ativos</option>
          <option value="0" <?= $filtros['ativo'] === 0 ? 'selected' : '' ?>>Só pausados</option>
        </select>

        <button type="submit" class="btn btn-sm btn-primary">Filtrar</button>
        <a href="<?= BASE_URL ?>/admin/seo/redirecionamentos" class="btn btn-sm btn-ghost">Limpar</a>
      </form>
    </div>

    <?php if (empty($regras)): ?>
    <div class="admin-empty-state" style="padding:40px;text-align:center;">
      <p>Nenhum redirecionamento ainda.</p>
      <p style="color:var(--text-3);font-size:13px;">
        A tela de <a href="<?= BASE_URL ?>/admin/seo/404">erros 404</a> cria a regra direto da linha do endereço quebrado.
      </p>
    </div>

    <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>De</th>
            <th>Para</th>
            <th width="110">Tipo</th>
            <th class="text-center" width="90">Usos</th>
            <th width="140">Último uso</th>
            <th class="text-center" width="80">Ativo</th>
            <th width="130">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($regras as $r): ?>
          <tr id="redir-row-<?= (int) $r['id'] ?>" <?= (int) $r['ativo'] ? '' : 'style="opacity:.55"' ?>>
            <td style="font-family:var(--font-mono);font-size:12px;"><?= View::e($r['origem']) ?></td>

            <td style="font-family:var(--font-mono);font-size:12px;">
              <?= (int) $r['tipo'] === 410
                    ? '<span style="color:var(--text-3)">— (página removida)</span>'
                    : View::e($r['destino']) ?>
            </td>

            <td>
              <span class="admin-badge admin-badge--<?= (int) $r['tipo'] === 301 ? 'success' : ((int) $r['tipo'] === 410 ? 'danger' : 'muted') ?>">
                <?= (int) $r['tipo'] ?> · <?= View::e($tipos[(int) $r['tipo']] ?? '') ?>
              </span>
            </td>

            <td class="text-center"><?= number_format((int) $r['acessos'], 0, ',', '.') ?></td>

            <td style="font-size:12px;">
              <?= $r['ultimo_acesso']
                    ? date('d/m/Y H:i', strtotime((string) $r['ultimo_acesso']))
                    : '<span style="color:var(--text-3)">nunca</span>' ?>
            </td>

            <td class="text-center">
              <button type="button" class="admin-toggle <?= (int) $r['ativo'] ? 'admin-toggle--on' : '' ?> btn-redir-ativo"
                      data-id="<?= (int) $r['id'] ?>">
                <span class="admin-toggle-track"><span class="admin-toggle-thumb"></span></span>
              </button>
            </td>

            <td>
              <div class="admin-row-actions">
                <button type="button" class="btn btn-xs btn-ghost btn-editar-redir" data-id="<?= (int) $r['id'] ?>">Editar</button>
                <button type="button" class="btn btn-xs btn-ghost btn-excluir-redir" data-id="<?= (int) $r['id'] ?>">Excluir</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($paginas > 1): ?>
    <div class="admin-card-footer" style="display:flex;gap:8px;justify-content:center;padding:14px;">
      <?php if ($pagina > 1): ?>
      <a class="btn btn-sm btn-ghost" href="?pagina=<?= $pagina - 1 ?>">Anterior</a>
      <?php endif; ?>
      <span style="align-self:center;font-size:13px;color:var(--text-3)">
        Página <?= $pagina ?> de <?= $paginas ?> · <?= number_format((int) $total, 0, ',', '.') ?> regras
      </span>
      <?php if ($pagina < $paginas): ?>
      <a class="btn btn-sm btn-ghost" href="?pagina=<?= $pagina + 1 ?>">Próxima</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  // BASE_URL e CSRF_TOKEN so existem DEPOIS deste bloco (o layout os declara
  // abaixo do conteudo), entao vem do PHP — sem depender de ordem de carga.
  const BASE = '<?= BASE_URL ?>/admin/seo/redirecionamentos';
  const CSRF = '<?= SecurityHelper::generateCsrf() ?>';

  function formulario(r) {
    r = r || {};
    const tipo = parseInt(r.tipo || 301, 10);
    return `
      <input type="hidden" id="rd-id" value="${r.id || 0}">
      <div class="form-group">
        <label class="pe-label">Endereço antigo (origem)</label>
        <input type="text" id="rd-origem" class="form-control"
               value="${$('<div>').text(r.origem || '').html()}" placeholder="/capacetes/com-viseira-solar">
        <p class="pe-field-hint">Só o caminho. Maiúsculas, barra final e query são ignoradas na comparação.</p>
      </div>
      <div class="form-group">
        <label class="pe-label">Tipo</label>
        <select id="rd-tipo" class="form-control">
          <option value="301" ${tipo === 301 ? 'selected' : ''}>301 — mudou de endereço de vez</option>
          <option value="302" ${tipo === 302 ? 'selected' : ''}>302 — temporário</option>
          <option value="410" ${tipo === 410 ? 'selected' : ''}>410 — essa página acabou</option>
        </select>
      </div>
      <div class="form-group" id="rd-destino-wrap" ${tipo === 410 ? 'style="display:none"' : ''}>
        <label class="pe-label">Novo endereço (destino)</label>
        <input type="text" id="rd-destino" class="form-control"
               value="${$('<div>').text(r.destino || '').html()}" placeholder="/categoria/capacetes">
        <p class="pe-field-hint">Caminho interno com "/" ou URL completa (http/https).</p>
      </div>
      <div class="form-group">
        <label class="pe-label">Observação</label>
        <input type="text" id="rd-obs" class="form-control"
               value="${$('<div>').text(r.observacao || '').html()}" placeholder="Opcional">
      </div>
      <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
        <input type="checkbox" id="rd-ativo" ${(r.ativo === undefined || parseInt(r.ativo, 10) === 1) ? 'checked' : ''}> Ativo
      </label>
      <button type="button" class="btn btn-primary" style="width:100%" id="rd-salvar">Salvar</button>`;
  }

  function abrir(r) {
    const drawer = adminDrawer({
      titulo  : r && r.id ? 'Editar redirecionamento' : 'Novo redirecionamento',
      tamanho : 'sm',
      conteudo: formulario(r),
    });

    $(drawer.corpo()).on('change', '#rd-tipo', function () {
      $('#rd-destino-wrap').toggle(this.value !== '410');
    });

    $(drawer.corpo()).on('click', '#rd-salvar', function () {
      const $btn = $(this).prop('disabled', true).text('Salvando...');

      $.post(BASE + '/salvar', {
        id          : $('#rd-id').val(),
        origem      : $('#rd-origem').val(),
        destino     : $('#rd-destino').val(),
        tipo        : $('#rd-tipo').val(),
        observacao  : $('#rd-obs').val(),
        ativo       : $('#rd-ativo').is(':checked') ? 1 : 0,
        _csrf_token : CSRF,
      }, function (res) {
        $btn.prop('disabled', false).text('Salvar');
        if (!res.ok) { showToast(res.msg, 'error'); return; }
        if (res.aviso) showToast(res.aviso, 'warning');
        showToast(res.msg, 'success');
        drawer.fechar();
        setTimeout(() => window.location.reload(), 700);
      }, 'json');
    });
  }

  $('#btn-novo-redir').on('click', () => abrir(null));

  $(document).on('click', '.btn-editar-redir', function () {
    $.get(BASE + '/' + $(this).data('id'), function (res) {
      if (!res.ok) { showToast(res.msg, 'error'); return; }
      abrir(res.regra);
    }, 'json');
  });

  $(document).on('click', '.btn-redir-ativo', function () {
    const $btn = $(this);
    $.post(BASE + '/alternar', { id: $btn.data('id'), _csrf_token: CSRF }, function (res) {
      if (!res.ok) { showToast(res.msg, 'error'); return; }
      $btn.toggleClass('admin-toggle--on', res.ativo === 1);
      $btn.closest('tr').css('opacity', res.ativo === 1 ? '' : '.55');
    }, 'json');
  });

  $(document).on('click', '.btn-excluir-redir', async function () {
    const id = $(this).data('id');
    const ok = await adminConfirm({
      titulo   : 'Excluir redirecionamento?',
      mensagem : 'O endereço antigo volta a responder 404.',
      tipo     : 'warning',
      confirmar: 'Excluir',
    });
    if (!ok) return;

    $.post(BASE + '/excluir', { id: id, _csrf_token: CSRF }, function (res) {
      if (!res.ok) { showToast(res.msg, 'error'); return; }
      $('#redir-row-' + id).fadeOut(200, function () { $(this).remove(); });
      showToast(res.msg, 'success');
    }, 'json');
  });
})();
</script>
