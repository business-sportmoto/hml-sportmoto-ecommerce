<?php
// ════════════════════════════════════════════════════════
// admin/views/banners/zonas-form.php
// Criar / Editar zona de banner
// ════════════════════════════════════════════════════════
$isEdit = !empty($zona);
$titulo = $isEdit ? 'Editar zona' : 'Nova zona de banner';
$id     = $isEdit ? (int)$zona['id'] : 0;

// var_dump($zona);
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <a href="<?= BASE_URL ?>/admin/banner-zonas" class="admin-back-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="19" y1="12" x2="5" y2="12"/>
          <polyline points="12 19 5 12 12 5"/>
        </svg>
        Zonas de banner
      </a>
      <h1><?= $titulo ?></h1>
      <?php if ($isEdit): ?>
      <p>ID #<?= $id ?> · Chave: <code><?= View::e($zona['chave']) ?></code></p>
      <?php endif; ?>
    </div>
    <button type="button" id="btn-salvar-zona" class="btn btn-primary">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
      Salvar zona
    </button>
  </div>

  <form id="form-zona">
    <input type="hidden" name="_csrf_token" value="<?= SecurityHelper::generateCsrf() ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="bz-form-grid">

      <!-- ══ COLUNA PRINCIPAL ══ -->
      <div class="bz-form-main">

        <!-- Informações básicas -->
        <div class="admin-card">
          <div class="admin-card-header"><h3>Identificação</h3></div>
          <div class="admin-card-body">

            <div class="form-group">
              <label for="zona-nome">
                Nome da zona <span class="required">*</span>
              </label>
              <input type="text" id="zona-nome" name="nome" class="form-control"
                     maxlength="120" required autocomplete="off"
                     value="<?= $isEdit ? View::e($zona['nome']) : '' ?>"
                     placeholder="Ex: Home — Hero principal">
              <small class="form-help">
                Nome legível para o time. Aparece na lista de zonas.
              </small>
            </div>

            <div class="form-group">
              <label for="zona-chave">
                Chave única <span class="required">*</span>
              </label>
              <div style="position:relative;">
                <input type="text" id="zona-chave" name="chave" class="form-control"
                       maxlength="60" required
                       value="<?= $isEdit ? View::e($zona['chave']) : '' ?>"
                       placeholder="Ex: home_hero"
                       <?= $isEdit ? 'readonly style="background:var(--bg);color:var(--text-3);cursor:not-allowed"' : '' ?>>
                <?php if ($isEdit): ?>
                <span class="bz-chave-locked" title="Chave não pode ser alterada após criação">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0110 0v4"/>
                  </svg>
                </span>
                <?php endif; ?>
              </div>
              <small class="form-help">
                Identificador único em código.
                Só letras minúsculas, números e <kbd>_</kbd>.
                Usado em <code>View::banner('home_hero')</code>.
                <?php if (!$isEdit): ?>
                Gerado automaticamente ao digitar o nome.
                <?php else: ?>
                Não pode ser alterada após criação.
                <?php endif; ?>
              </small>
            </div>

            <div class="form-group">
              <label for="zona-desc">Descrição</label>
              <textarea id="zona-desc" name="descricao" class="form-control"
                        rows="2" maxlength="300"
                        placeholder="Para que serve esta zona? Onde aparece no site?"><?= $isEdit ? View::e($zona['descricao'] ?? '') : '' ?></textarea>
            </div>

          </div>
        </div>

        <!-- Configurações técnicas -->
        <div class="admin-card">
          <div class="admin-card-header"><h3>Configurações</h3></div>
          <div class="admin-card-body">

            <div class="form-row">

              <!-- Formato -->
              <div class="form-group">
                <label>Formato <span class="required">*</span></label>
                <div class="bz-formato-grid" id="bz-formato-grid">
                  <?php
                  $formatos = [
                    'hero'      => ['label'=>'Hero',      'sub'=>'Banner grande, topo de página',
                                    'icon'=>'<rect x="3" y="5" width="18" height="14" rx="2"/>'],
                    'slider' => ['label'=>'Carrossel',  'sub'=>'Múltiplos banners com slide',
                                    'icon'=>'<path d="M3 6h18M3 12h18M3 18h18"/>'],
                    'strip'     => ['label'=>'Faixa',      'sub'=>'Banner horizontal estreito',
                                    'icon'=>'<rect x="2" y="9" width="20" height="6" rx="1"/>'],
                    'sidebar'   => ['label'=>'Lateral',    'sub'=>'Coluna lateral vertical',
                                    'icon'=>'<rect x="15" y="3" width="6" height="18" rx="1"/><rect x="3" y="3" width="9" height="18" rx="1"/>'],
                    'popup'     => ['label'=>'Popup',      'sub'=>'Modal sobre o conteúdo',
                                    'icon'=>'<rect x="4" y="4" width="16" height="16" rx="2"/><line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/>'],
                    'grid'      => ['label'=>'Grade',      'sub'=>'Múltiplos banners em grade',
                                    'icon'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>'],
                  ];
                  $formatoAtual = $isEdit ? $zona['formato'] : 'hero';
                  foreach ($formatos as $val => $f):
                  ?>
                  <label class="bz-formato-option <?= $formatoAtual === $val ? 'is-selected' : '' ?>">
                    <input type="radio" name="formato" value="<?= $val ?>"
                           <?= $formatoAtual === $val ? 'checked' : '' ?>>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                      <?= $f['icon'] ?>
                    </svg>
                    <span class="bz-formato-option-label"><?= $f['label'] ?></span>
                    <span class="bz-formato-option-sub"><?= $f['sub'] ?></span>
                  </label>
                  <?php endforeach; ?>
                </div>
              </div>

            </div>

            <div class="form-row">
              <!-- Dimensões recomendadas -->
              <div class="form-group">
                <label for="zona-larg">Largura recomendada (px)</label>
                <input type="number" id="zona-larg" name="largura_rec"
                       class="form-control" min="0" max="9999"
                       value="<?= $isEdit ? (int)($zona['largura_rec'] ?? 0) : '' ?>"
                       placeholder="Ex: 1920">
              </div>
              <div class="form-group">
                <label for="zona-alt">Altura recomendada (px)</label>
                <input type="number" id="zona-alt" name="altura_rec"
                       class="form-control" min="0" max="9999"
                       value="<?= $isEdit ? (int)($zona['altura_rec'] ?? 0) : '' ?>"
                       placeholder="Ex: 720">
              </div>
              <div class="form-group">
                <label for="zona-max">Máx. banners simultâneos</label>
                <input type="number" id="zona-max" name="max_banners"
                       class="form-control" min="1" max="20"
                       value="<?= $isEdit ? (int)($zona['max_banners'] ?? 1) : 1 ?>">
                <small class="form-help">
                  Carrossel/grade: use mais de 1.
                  Hero/strip: deixe 1.
                </small>
              </div>
            </div>

          </div>
        </div>

      </div>

      <!-- ══ COLUNA LATERAL ══ -->
      <div class="bz-form-side">

        <!-- Status -->
        <div class="admin-card">
          <div class="admin-card-header"><h3>Status</h3></div>
          <div class="admin-card-body">
            <label class="form-toggle">
              <input type="checkbox" name="ativo" value="1"
                     <?= (!$isEdit || $zona['ativo']) ? 'checked' : '' ?>>
              <span>Zona ativa</span>
              <small>Banners desta zona serão exibidos</small>
            </label>
          </div>
        </div>

        <!-- Preview da chave -->
        <div class="admin-card" id="bz-preview-card">
          <div class="admin-card-header"><h3>Como usar no código</h3></div>
          <div class="admin-card-body">
            <p style="font-size:12px;color:var(--text-3);margin-bottom:8px;">
              Para exibir esta zona em qualquer view:
            </p>
            <pre class="bz-code-preview" id="bz-code-preview"><?php
$chavePreview = $isEdit ? $zona['chave'] : 'minha_zona';
echo htmlspecialchars("<?php View::banner('{$chavePreview}') ?>");
?></pre>
            <p style="font-size:12px;color:var(--text-3);margin-top:8px;">
              Ou com o helper:
            </p>
            <pre class="bz-code-preview"><?php
echo htmlspecialchars("<?= Banner::slot('{$chavePreview}') ?>");
?></pre>
          </div>
        </div>

        <!-- Dica sobre formatos -->
        <div class="admin-card">
          <div class="admin-card-header"><h3>Guia de formatos</h3></div>
          <div class="admin-card-body">
            <dl class="bz-formato-guide">
              <dt>Hero</dt>
              <dd>1 banner grande, cobre a largura total. Ideal para topo da home.</dd>
              <dt>Carrossel</dt>
              <dd>2–5 banners em slide automático. Recomendado para home_hero.</dd>
              <dt>Faixa</dt>
              <dd>1 banner estreito (ex: 1920×80px). Ideal para avisos e promoções.</dd>
              <dt>Lateral</dt>
              <dd>Banner vertical na coluna lateral. Ex: 320×480px.</dd>
              <dt>Popup</dt>
              <dd>Modal que aparece sobre o conteúdo. Use com cuidado.</dd>
              <dt>Grade</dt>
              <dd>2–4 banners lado a lado. Útil para múltiplas promoções.</dd>
            </dl>
          </div>
        </div>

      </div>
    </div>
  </form>

</div>

<script>
    let isEditZonaBanner = <?= $isEdit ? 'true' : 'false' ?>;
</script>


