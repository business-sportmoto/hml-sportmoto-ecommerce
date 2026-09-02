<?php
/**
 * admin/views/paginas/index.php — lista das páginas de conteúdo.
 *
 * Recebe: $paginas (banco), $emArquivo (montadas em /pages), $filtros.
 */
$e   = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int) $s . 'px">'
    . (class_exists('IconLibrary') ? IconLibrary::ref($n, '') : '') . '</span>';
$data = static fn($v) => $v ? date('d/m/Y', strtotime((string) $v)) : '—';
?>

<div class="admin-page pg_page">

  <div class="admin-page-header">
    <div>
      <h1 class="admin-page-title"><?= $ico('docs', 22) ?> Páginas</h1>
      <p class="admin-page-sub">Termos, privacidade, trocas — o conteúdo que muda sem deploy</p>
    </div>
    <a href="<?= ADMIN_URL ?>/paginas/nova" class="btn btn-primary">
      <?= $ico('add', 15) ?> Nova página
    </a>
  </div>

  <div class="admin-card">
    <div class="admin-card-body">
      <form method="get" class="pg_filtros">
        <input type="search" name="busca" class="form-control" placeholder="Buscar por título ou endereço…"
               value="<?= $e($filtros['busca']) ?>">
        <select name="status" class="form-control">
          <option value="">Todas</option>
          <option value="ativas"   <?= $filtros['status'] === 'ativas'   ? 'selected' : '' ?>>Publicadas</option>
          <option value="rascunho" <?= $filtros['status'] === 'rascunho' ? 'selected' : '' ?>>Rascunhos</option>
        </select>
        <button type="submit" class="btn btn-secondary"><?= $ico('search', 15) ?> Filtrar</button>
      </form>
    </div>
  </div>

  <div class="admin-card" style="margin-top:14px">
    <div class="admin-card-body" style="padding:0">
      <?php if (!$paginas): ?>
        <p class="pg_vazio">
          Nenhuma página ainda. Comece pelos <strong>Termos de uso</strong> e pela
          <strong>Política de privacidade</strong> — o rodapé já aponta para elas.
        </p>
      <?php else: ?>
      <table class="admin-table pg_tabela">
        <thead>
          <tr>
            <th>Página</th>
            <th style="width:110px">Status</th>
            <th style="width:150px">Aparece em</th>
            <th style="width:110px">Atualizada</th>
            <th style="width:170px"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($paginas as $p): ?>
          <tr data-id="<?= (int) $p['id'] ?>">
            <td>
              <a class="pg_titulo" href="<?= ADMIN_URL ?>/paginas/editar/<?= (int) $p['id'] ?>">
                <?= $e($p['titulo']) ?>
              </a>
              <div class="pg_slug">/<?= $e($p['slug']) ?></div>
              <?php if ((int) $p['tamanho'] < 120): ?>
                <span class="pg_curto" title="Conteúdo muito curto para publicar">
                  <?= $ico('alerta', 12) ?> conteúdo curto
                </span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= $p['ativo'] ? 'badge-success' : 'badge-warning' ?> js-pg-badge">
                <?= $p['ativo'] ? 'Publicada' : 'Rascunho' ?>
              </span>
            </td>
            <td class="pg_onde">
              <?php if ($p['no_menu']): ?><span>Menu</span><?php endif; ?>
              <?php if ($p['no_rodape']): ?><span>Rodapé</span><?php endif; ?>
              <?php if ($p['noindex']): ?><span class="pg_noindex">noindex</span><?php endif; ?>
              <?php if (!$p['no_menu'] && !$p['no_rodape']): ?>
                <span class="pg_so_link">só por link</span>
              <?php endif; ?>
            </td>
            <td class="pg_data"><?= $data($p['atualizado_em']) ?></td>
            <td class="pg_acoes">
              <a class="btn btn-secondary btn-sm" href="<?= ADMIN_URL ?>/paginas/editar/<?= (int) $p['id'] ?>">
                <?= $ico('edit', 14) ?> Editar
              </a>
              <?php if ($p['ativo']): ?>
                <a class="btn btn-secondary btn-sm" target="_blank" rel="noopener"
                   href="<?= BASE_URL ?>/<?= $e($p['slug']) ?>" title="Abrir na loja">
                  <?= $ico('open-in-new', 14) ?>
                </a>
              <?php endif; ?>
              <button type="button" class="btn btn-secondary btn-sm js-pg-alternar"
                      title="<?= $p['ativo'] ? 'Despublicar' : 'Publicar' ?>">
                <?= $ico($p['ativo'] ? 'sync-disabled' : 'check-circle', 14) ?>
              </button>
              <button type="button" class="btn-icon btn-icon--danger js-pg-excluir"
                      title="Excluir" aria-label="Excluir <?= $e($p['titulo']) ?>">&times;</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($emArquivo): ?>
  <div class="admin-card" style="margin-top:14px">
    <div class="admin-card-header"><h3>Páginas montadas em arquivo</h3></div>
    <div class="admin-card-body">
      <p class="pg_nota">
        <?= $ico('info', 14) ?>
        Estas têm HTML, CSS e JS próprios em <code>/pages/{slug}</code> e são
        versionadas no git. Aparecem aqui para você saber que existem — editar
        exige deploy, porque o conteúdo delas é código executado.
      </p>
      <div class="pg_arquivos">
        <?php foreach ($emArquivo as $a): ?>
          <a class="pg_arquivo" href="<?= BASE_URL ?>/<?= $e($a['slug']) ?>" target="_blank" rel="noopener">
            <strong><?= $e($a['titulo']) ?></strong>
            <span>/<?= $e($a['slug']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
  window.PG = { base: '<?= ADMIN_URL ?>/paginas' };
</script>
<script src="<?= PerformanceHelper::assetVersion('js/paginas.js', true) ?>"></script>
