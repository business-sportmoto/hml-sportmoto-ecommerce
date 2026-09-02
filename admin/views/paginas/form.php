<?php
/**
 * admin/views/paginas/form.php — criar/editar uma página de conteúdo.
 *
 * Recebe: $pagina (linha de `paginas`) ou null para nova.
 */
$e   = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int) $s . 'px">'
    . (class_exists('IconLibrary') ? IconLibrary::ref($n, '') : '') . '</span>';

$p     = $pagina ?? [];
$novo  = empty($p['id']);
$ativo = $novo ? 0 : (int) ($p['ativo'] ?? 0);
?>

<div class="admin-page pg_page">

  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/paginas" class="pg_voltar"><?= $ico('arrow-back', 14) ?> Páginas</a>
      <h1 class="admin-page-title"><?= $novo ? 'Nova página' : $e($p['titulo']) ?></h1>
      <?php if (!$novo): ?>
        <p class="admin-page-sub">
          <?= $ativo ? 'Publicada em' : 'Rascunho —' ?>
          <a href="<?= BASE_URL ?>/<?= $e($p['slug']) ?>" target="_blank" rel="noopener">
            <?= BASE_URL ?>/<?= $e($p['slug']) ?>
          </a>
        </p>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:10px;align-items:center">
      <?php if (!$novo && $ativo): ?>
        <a href="<?= BASE_URL ?>/<?= $e($p['slug']) ?>" target="_blank" rel="noopener" class="btn btn-secondary">
          <?= $ico('open-in-new', 15) ?> Ver na loja
        </a>
      <?php endif; ?>
      <button type="button" class="btn btn-primary" id="pgSalvar">
        <?= $ico('save', 15) ?> Salvar
      </button>
    </div>
  </div>

  <form id="pgForm" autocomplete="off" onsubmit="return false;">
    <?= SecurityHelper::csrfField() ?>
    <input type="hidden" name="id" value="<?= (int) ($p['id'] ?? 0) ?>">

    <div class="pg_layout">

      <!-- ── coluna principal ─────────────────────────────────────── -->
      <div class="pg_col_principal">
        <div class="admin-card">
          <div class="admin-card-body">
            <div class="ap-form-group">
              <label class="ap-form-label" for="pg-titulo">Título</label>
              <input type="text" class="form-control pg_titulo_input" id="pg-titulo" name="titulo"
                     maxlength="200" value="<?= $e($p['titulo'] ?? '') ?>"
                     placeholder="Termos de uso" required>
            </div>

            <div class="ap-form-group">
              <label class="ap-form-label" for="pg-slug">Endereço</label>
              <div class="pg_slug_campo">
                <span class="pg_slug_base"><?= BASE_URL ?>/</span>
                <input type="text" class="form-control" id="pg-slug" name="slug"
                       maxlength="120" value="<?= $e($p['slug'] ?? '') ?>"
                       placeholder="termos-de-uso">
              </div>
              <span class="pg_hint" id="pg-slug-hint">
                Deixe vazio para gerar a partir do título.
                <?php if (!$novo): ?>
                  <strong>Mudar o endereço quebra os links já publicados.</strong>
                <?php endif; ?>
              </span>
            </div>

            <div class="ap-form-group">
              <label class="ap-form-label" for="pg-conteudo">Conteúdo</label>
              <textarea id="pg-conteudo" name="conteudo" class="form-control" rows="16"><?= $e($p['conteudo'] ?? '') ?></textarea>
              <?php
                $rteTarget = 'pg-conteudo';
                $rtePlaceholder = 'Escreva o conteúdo da página…';
                include __DIR__ . '/../partials/_rte.php';
              ?>
              <span class="pg_hint">
                O HTML é limpo no servidor ao salvar: script, iframe e atributos
                de evento são removidos, mesmo que colados.
              </span>
            </div>
          </div>
        </div>

        <div class="admin-card" style="margin-top:14px">
          <div class="admin-card-header"><h3>Busca e compartilhamento</h3></div>
          <div class="admin-card-body">
            <div class="ap-form-group">
              <label class="ap-form-label" for="pg-meta-title">Título no Google</label>
              <input type="text" class="form-control" id="pg-meta-title" name="meta_title"
                     maxlength="160" value="<?= $e($p['meta_title'] ?? '') ?>"
                     placeholder="Vazio = usa o título da página">
            </div>
            <div class="ap-form-group">
              <label class="ap-form-label" for="pg-meta-desc">Descrição no Google</label>
              <textarea class="form-control" id="pg-meta-desc" name="meta_description" rows="2"
                        maxlength="300" placeholder="Resumo de uma ou duas linhas"><?= $e($p['meta_description'] ?? '') ?></textarea>
              <span class="pg_hint"><span id="pg-meta-contagem">0</span>/300 — o Google costuma cortar perto de 160.</span>
            </div>
            <label class="toggle-field">
              <input type="checkbox" name="noindex" value="1" <?= !empty($p['noindex']) ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
              <span>Esconder dos buscadores <em class="pg_hint_inline">(a página continua acessível por link)</em></span>
            </label>
          </div>
        </div>
      </div>

      <!-- ── coluna lateral ───────────────────────────────────────── -->
      <div class="pg_col_lateral">
        <div class="admin-card">
          <div class="admin-card-header"><h3>Publicação</h3></div>
          <div class="admin-card-body">
            <label class="toggle-field">
              <input type="checkbox" id="pg-ativo" name="ativo" value="1" <?= $ativo ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
              <span>Publicada</span>
            </label>
            <p class="pg_nota" style="margin-top:12px">
              <?= $ico('info', 14) ?>
              Rascunho não abre na loja e não entra em menu, rodapé nem sitemap.
            </p>
            <?php if (!$novo && !empty($p['publicado_em'])): ?>
              <p class="pg_hint">No ar desde <?= $e(date('d/m/Y', strtotime((string) $p['publicado_em']))) ?>.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="admin-card" style="margin-top:14px">
          <div class="admin-card-header"><h3>Onde aparece</h3></div>
          <div class="admin-card-body">
            <label class="toggle-field">
              <input type="checkbox" name="no_rodape" value="1" <?= ($novo || !empty($p['no_rodape'])) ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
              <span>No rodapé</span>
            </label>
            <label class="toggle-field" style="margin-top:8px">
              <input type="checkbox" name="no_menu" value="1" <?= !empty($p['no_menu']) ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
              <span>No menu principal</span>
            </label>

            <div class="ap-form-group" style="margin-top:12px">
              <label class="ap-form-label" for="pg-menu-label">Nome curto</label>
              <input type="text" class="form-control" id="pg-menu-label" name="menu_label"
                     maxlength="80" value="<?= $e($p['menu_label'] ?? '') ?>"
                     placeholder="Vazio = usa o título">
              <span class="pg_hint">"Privacidade" cabe melhor que "Política de privacidade".</span>
            </div>

            <div class="ap-form-group">
              <label class="ap-form-label" for="pg-ordem">Ordem</label>
              <input type="number" class="form-control" id="pg-ordem" name="ordem_menu"
                     min="0" max="999" value="<?= ($p['ordem_menu'] ?? null) !== null ? (int) $p['ordem_menu'] : '' ?>"
                     placeholder="99">
              <span class="pg_hint">Menor vem primeiro. Vazio vai para o fim.</span>
            </div>
          </div>
        </div>

        <?php if (!$novo): ?>
        <div class="admin-card" style="margin-top:14px">
          <div class="admin-card-body">
            <button type="button" class="btn btn-secondary pg_excluir js-pg-excluir-form"
                    data-id="<?= (int) $p['id'] ?>">
              <?= $ico('trash', 14) ?> Excluir esta página
            </button>
            <p class="pg_hint" style="margin-top:8px">
              Some para sempre, e quem tiver o link recebe 404.
            </p>
          </div>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </form>
</div>

<script>
  window.PG = { base: '<?= ADMIN_URL ?>/paginas', novo: <?= $novo ? 'true' : 'false' ?> };
</script>
<script src="<?= PerformanceHelper::assetVersion('js/rte.js', true) ?>"></script>
<script src="<?= PerformanceHelper::assetVersion('js/paginas.js', true) ?>"></script>
