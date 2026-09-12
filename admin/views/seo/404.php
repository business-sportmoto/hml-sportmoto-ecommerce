<?php
/**
 * View: endereços com erro 404 (admin, só super).
 *
 * A coluna que responde "de onde está vindo" é a Origem: `interno` é link
 * quebrado numa página NOSSA — corrige-se a fonte, não o sintoma.
 */
$paginas = (int) ceil(max(1, (int) $total) / max(1, (int) $porPagina));
$qs = static function (array $novo) use ($filtros, $pagina): string {
    $base = ['busca' => $filtros['busca'], 'status' => $filtros['status'],
             'origem' => $filtros['origem'], 'robos' => $filtros['robos'],
             'ruido' => $filtros['ruido'], 'pagina' => $pagina];
    return '?' . http_build_query(array_filter(array_merge($base, $novo), static fn($v) => $v !== '' && $v !== 0));
};
$rotulo = ['interno' => 'Link nosso', 'busca' => 'Busca', 'externo' => 'Site externo', 'direto' => 'Direto'];
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <h1>Endereços com erro 404</h1>
      <p>O que as pessoas e os buscadores pedem e o site não tem.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/seo/redirecionamentos" class="btn btn-outline">
      Redirecionamentos
    </a>
  </div>

  <div class="admin-stats-row" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
    <?php foreach ([
        ['A resolver',       (int) ($resumo['a_resolver'] ?? 0), 'Visitados por gente, ainda sem regra'],
        ['Endereços',        (int) ($resumo['enderecos']  ?? 0), 'Total já registrado'],
        ['Acessos',          (int) ($resumo['acessos']    ?? 0), 'Somando todas as visitas'],
        ['De link nosso',    (int) ($resumo['internos']   ?? 0), 'Página do site com link errado'],
        ['De busca',         (int) ($resumo['de_busca']   ?? 0), 'Vindos do Google e afins'],
    ] as [$lab, $val, $dica]): ?>
    <div class="admin-card" style="flex:1;min-width:150px;padding:14px;" title="<?= View::e($dica) ?>">
      <div style="font-size:22px;font-weight:800;"><?= number_format($val, 0, ',', '.') ?></div>
      <div style="color:var(--text-3);font-size:12px;"><?= View::e($lab) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="admin-card">
    <div class="admin-card-header">
      <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;width:100%;">
        <input type="text" name="busca" class="form-control" style="max-width:260px;"
               value="<?= View::e($filtros['busca']) ?>" placeholder="Buscar endereço...">

        <select name="status" class="form-control" style="max-width:160px;">
          <option value="">Todos os status</option>
          <?php foreach (['novo' => 'Novo', 'ignorado' => 'Ignorado', 'resolvido' => 'Resolvido'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $filtros['status'] === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>

        <select name="origem" class="form-control" style="max-width:170px;">
          <option value="">Qualquer origem</option>
          <?php foreach ($rotulo as $v => $l): ?>
          <option value="<?= $v ?>" <?= $filtros['origem'] === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>

        <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
          <input type="checkbox" name="robos" value="1" <?= $filtros['robos'] ? 'checked' : '' ?>> Incluir robôs
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
          <input type="checkbox" name="ruido" value="1" <?= $filtros['ruido'] ? 'checked' : '' ?>> Incluir varredura
        </label>

        <button type="submit" class="btn btn-sm btn-primary">Filtrar</button>
        <a href="<?= BASE_URL ?>/admin/seo/404" class="btn btn-sm btn-ghost">Limpar</a>
      </form>
    </div>

    <?php if (empty($urls)): ?>
    <div class="admin-empty-state" style="padding:40px;text-align:center;">
      <p>Nenhum endereço quebrado com estes filtros.</p>
      <p style="color:var(--text-3);font-size:13px;">
        Varredura de robô (/wp-login.php e afins) fica escondida por padrão — marque "Incluir varredura" para ver.
      </p>
    </div>

    <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Endereço</th>
            <th class="text-center" width="110">Acessos</th>
            <th width="130">Origem</th>
            <th>Veio de</th>
            <th width="150">Última vez</th>
            <th width="210">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($urls as $u): ?>
          <tr id="url-row-<?= (int) $u['id'] ?>" <?= $u['status'] === 'ignorado' ? 'style="opacity:.55"' : '' ?>>
            <td>
              <a href="<?= BASE_URL . View::e($u['caminho']) ?>" target="_blank" rel="noopener"
                 style="font-family:var(--font-mono);font-size:12px;"><?= View::e($u['caminho']) ?></a>
              <?php if (!empty($u['query_exemplo'])): ?>
              <div style="color:var(--text-3);font-size:11px;">?<?= View::e($u['query_exemplo']) ?></div>
              <?php endif; ?>
              <?php if ($u['status'] === 'resolvido' && !empty($u['redir_destino'])): ?>
              <div style="color:var(--success);font-size:11px;">
                → <?= View::e($u['redir_destino']) ?> (<?= (int) $u['redir_tipo'] ?>)
              </div>
              <?php endif; ?>
            </td>

            <td class="text-center">
              <strong><?= number_format((int) $u['ocorrencias'], 0, ',', '.') ?></strong>
              <div style="color:var(--text-3);font-size:11px;">
                <?= (int) $u['visitas_humano'] ?> gente · <?= (int) $u['visitas_robo'] ?> robô
              </div>
            </td>

            <td>
              <span class="admin-badge admin-badge--<?= $u['ultima_origem'] === 'interno' ? 'danger' : 'muted' ?>">
                <?= View::e($rotulo[$u['ultima_origem']] ?? $u['ultima_origem']) ?>
              </span>
            </td>

            <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                title="<?= View::e((string) $u['ultimo_referer']) ?>">
              <?= $u['ultimo_referer'] ? View::e($u['ultimo_referer']) : '<span style="color:var(--text-3)">—</span>' ?>
            </td>

            <td style="font-size:12px;">
              <?= date('d/m/Y H:i', strtotime((string) $u['ultima_vez'])) ?>
            </td>

            <td>
              <div class="admin-row-actions">
                <?php if ($u['status'] !== 'resolvido'): ?>
                <button type="button" class="btn btn-xs btn-primary btn-redirecionar"
                        data-id="<?= (int) $u['id'] ?>"
                        data-caminho="<?= View::e($u['caminho']) ?>">Redirecionar</button>
                <?php endif; ?>
                <button type="button" class="btn btn-xs btn-ghost btn-acessos"
                        data-id="<?= (int) $u['id'] ?>">Acessos</button>
                <button type="button" class="btn btn-xs btn-ghost btn-status"
                        data-id="<?= (int) $u['id'] ?>"
                        data-status="<?= $u['status'] === 'ignorado' ? 'novo' : 'ignorado' ?>">
                  <?= $u['status'] === 'ignorado' ? 'Reativar' : 'Ignorar' ?>
                </button>
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
      <a class="btn btn-sm btn-ghost" href="<?= $qs(['pagina' => $pagina - 1]) ?>">Anterior</a>
      <?php endif; ?>
      <span style="align-self:center;font-size:13px;color:var(--text-3)">
        Página <?= $pagina ?> de <?= $paginas ?> · <?= number_format((int) $total, 0, ',', '.') ?> endereços
      </span>
      <?php if ($pagina < $paginas): ?>
      <a class="btn btn-sm btn-ghost" href="<?= $qs(['pagina' => $pagina + 1]) ?>">Próxima</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
(function ($) {
  // BASE_URL e CSRF_TOKEN só existem DEPOIS deste bloco (o layout os
  // declara abaixo do conteúdo), então vêm do PHP — sem depender da
  // ordem de carga dos scripts.
  const BASE = '<?= BASE_URL ?>/admin/seo';
  const CSRF = '<?= SecurityHelper::generateCsrf() ?>';

  // ── Criar redirecionamento a partir da linha ──────────────
  $(document).on('click', '.btn-redirecionar', function () {
    const id      = $(this).data('id');
    const caminho = $(this).data('caminho');

    const drawer = adminDrawer({
      titulo   : 'Redirecionar endereço',
      subtitulo: caminho,
      tamanho  : 'sm',
      conteudo : `
        <div class="form-group">
          <label class="pe-label">Para onde deve levar</label>
          <input type="text" id="rd-destino" class="form-control"
                 placeholder="/categoria/capacetes">
          <p class="pe-field-hint">Caminho interno começando com "/" ou URL completa.</p>
        </div>
        <div class="form-group">
          <label class="pe-label">Tipo</label>
          <select id="rd-tipo" class="form-control">
            <option value="301">301 — mudou de endereço de vez</option>
            <option value="302">302 — temporário</option>
            <option value="410">410 — essa página acabou (sem destino)</option>
          </select>
        </div>
        <button type="button" class="btn btn-primary" style="width:100%" id="rd-salvar">Salvar</button>`,
    });

    $(drawer.corpo()).on('change', '#rd-tipo', function () {
      $('#rd-destino').prop('disabled', this.value === '410');
    });

    $(drawer.corpo()).on('click', '#rd-salvar', function () {
      const $btn = $(this).prop('disabled', true).text('Salvando...');

      $.post(BASE + '/404/redirecionar', {
        id          : id,
        destino     : $('#rd-destino').val(),
        tipo        : $('#rd-tipo').val(),
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
  });

  // ── Últimos acessos ───────────────────────────────────────
  $(document).on('click', '.btn-acessos', function () {
    const id = $(this).data('id');
    const drawer = adminDrawer({
      titulo  : 'Últimos acessos',
      tamanho : 'lg',
      conteudo: '<div class="pe-loading">Carregando...</div>',
    });

    $.get(BASE + '/404/' + id + '/acessos', function (res) {
      if (!res.ok) { drawer.setConteudo('<p>' + res.msg + '</p>'); return; }
      if (!res.acessos.length) {
        drawer.setConteudo('<p>Sem acesso detalhado guardado — o detalhe some depois de 90 dias, e varredura de robô não é registrada.</p>');
        return;
      }

      let html = '<div class="admin-table-wrap"><table class="admin-table"><thead><tr>'
               + '<th>Quando</th><th>Origem</th><th>Veio de</th><th>IP</th><th>Navegador</th>'
               + '</tr></thead><tbody>';

      res.acessos.forEach(a => {
        html += '<tr>'
             +  '<td style="font-size:12px">' + a.criado_em + '</td>'
             +  '<td>' + a.origem + '</td>'
             +  '<td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
             +      $('<div>').text(a.referer || '—').html() + '</td>'
             +  '<td style="font-family:var(--font-mono);font-size:12px">' + $('<div>').text(a.ip || '—').html() + '</td>'
             +  '<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'
             +      $('<div>').text(a.user_agent || '').html() + '">'
             +      $('<div>').text(a.user_agent || '—').html() + '</td>'
             +  '</tr>';
      });

      drawer.setConteudo(html + '</tbody></table></div>');
    }, 'json');
  });

  // ── Ignorar / reativar ────────────────────────────────────
  $(document).on('click', '.btn-status', function () {
    const $btn = $(this);

    $.post(BASE + '/404/status', {
      id          : $btn.data('id'),
      status      : $btn.data('status'),
      _csrf_token : CSRF,
    }, function (res) {
      if (!res.ok) { showToast(res.msg || 'Erro.', 'error'); return; }
      window.location.reload();
    }, 'json');
  });
})(jQuery);
</script>
