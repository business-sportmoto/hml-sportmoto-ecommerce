<?php
// admin/views/produtos/form.php
$p      = $produto ?? null;
$isEdit = !empty($p);
$imgUrl = fn($f) => View::e($f);

$statusOpts = ['rascunho' => 'Rascunho', 'ativo' => 'Ativo', 'inativo' => 'Inativo'];

?>
<!-- navegação de seções fixa à esquerda -->
<div class="pe-layout">

  <!-- ── Editor principal ──────────────────────────────── -->
  <div class="pe-main">

    <!-- Topbar do editor -->
    <div class="pe-topbar" id="peTopbar">
      <div class="pe-topbar-left">
        <div class="pe-breadcrumb">
          <a href="<?= BASE_URL ?>/admin/produtos">Produtos</a>
          <span>›</span>
          <span id="pe-title-preview">
            <?= $isEdit ? View::e($p['nome']) : 'Novo produto' ?>
          </span>
        </div>
      </div>
      <div class="pe-topbar-actions">
        <?php if ($isEdit): ?>
        <a href="<?= BASE_URL ?>/produto/<?= View::e($p['slug']) ?>"
           target="_blank" class="btn btn-ghost btn-sm">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
            <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
          </svg>
          Ver produto
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline btn-sm" id="btn-salvar-rascunho">
          Salvar rascunho
        </button>
        <button type="button" class="btn btn-primary btn-sm" id="btn-salvar-produto">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
            <polyline points="17 21 17 13 7 13 7 21"/>
            <polyline points="7 3 7 8 15 8"/>
          </svg>
          <?= $isEdit ? 'Salvar alterações' : 'Publicar produto' ?>
        </button>
      </div>
    </div>

    <!-- Form -->
    <form id="form-produto" novalidate>
      <?= SecurityHelper::csrfField() ?>
      <input type="hidden" name="id" id="produto-id"
             value="<?= (int)($p['id'] ?? 0) ?>">
      <input type="hidden" name="ativo"    id="campo-ativo"
             value="<?= ($p['ativo'] ?? 1) ? 1 : 0 ?>">

      <div class="pe-sections">

        <!-- ════════════════════════════════════════════════
             SEÇÃO: INFORMAÇÕES GERAIS
             ════════════════════════════════════════════════ -->
        <section class="pe-section" id="pe-geral">
          <div class="pe-section-head">
            <h2>Informações gerais</h2>
            <p>Nome, descrição e categorização do produto</p>
          </div>

          <div class="pe-card">
            <div class="form-group">
              <label class="pe-label">
                Nome do produto <span class="pe-required">*</span>
              </label>
              <input type="text" name="nome" id="pe-nome"
                     class="form-control pe-input-lg"
                     value="<?= View::e($p['nome'] ?? '') ?>"
                     placeholder="Ex: Capacete AGV K3 SV Monocolor"
                     required autofocus>
              <div class="pe-field-meta">
                <span class="pe-char-count" id="nome-count">0 caracteres</span>
              </div>
            </div>

            <?php
              // URL travada para produto importado da Tray: o slug veio da
              // coluna "Endereço do Produto (URL Tray)" e é a mesma URL já
              // indexada pelo Google. Só a reimportação do CSV pode alterar.
              $slugTravado = $isEdit && trim((string)($p['tray_id'] ?? '')) !== '';
            ?>
            <div class="form-group">
              <label class="pe-label">
                Slug (URL)
                <?php if ($slugTravado): ?>
                <span class="pe-slug-lock-badge" title="URL definida pela importação da Tray">
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="3" stroke-linecap="round">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0110 0v4"/>
                  </svg>
                  Travada
                </span>
                <?php endif; ?>
              </label>
              <div class="pe-input-prefix-wrap">
                <span class="pe-input-prefix">/produto/</span>
                <input type="text" name="slug" id="pe-slug"
                       class="form-control"
                       value="<?= View::e($p['slug'] ?? '') ?>"
                       placeholder="gerado-automaticamente"
                       <?= $slugTravado ? 'readonly data-slug-travado="1"' : '' ?>>
                <?php if (!$slugTravado): ?>
                <button type="button" class="pe-slug-regen" id="btn-regen-slug"
                        title="Regenerar a partir do nome">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>
                  </svg>
                </button>
                <?php endif; ?>
              </div>
              <?php if ($slugTravado): ?>
              <div class="pe-field-hint">
                Produto importado da Tray (código <?= View::e($p['tray_id']) ?>).
                A URL vem do arquivo de importação e não muda ao editar — é a
                mesma que o Google já indexou. Para alterá-la, ajuste na Tray e
                reimporte o CSV.
              </div>
              <?php endif; ?>
            </div>

            <div class="form-group" id="pe-descricao-curta">
              <label class="pe-label">Descrição curta</label>
              <p class="pe-field-hint">
                Resumo exibido no card e topo da página do produto. Até 300 caracteres.
              </p>
              <textarea name="descricao_curta" id="pe-desc-curta"
                        class="form-control" rows="3"
                        maxlength="300"
                        placeholder="Destaque os pontos fortes do produto em 2-3 linhas..."><?= View::e($p['descricao_curta'] ?? '') ?></textarea>
              <span class="pe-char-count" id="desc-curta-count">0 / 300</span>
            </div>

            <div class="form-group">
              <label class="pe-label">Descrição completa</label>
              <p class="pe-field-hint">
                Aceita HTML básico: &lt;b&gt;, &lt;ul&gt;, &lt;li&gt;, &lt;br&gt;, &lt;p&gt;
              </p>
              <textarea name="descricao" id="pe-descricao"
                        class="form-control pe-textarea-rich" rows="10"
                        placeholder="Descreva detalhes técnicos, diferenciais, especificações..."><?= View::e($p['descricao'] ?? '') ?></textarea>
              <div class="pe-rte" data-target="pe-descricao">
                <div class="pe-rte-toolbar" role="toolbar" aria-label="Formatação">
                  <button type="button" class="pe-rte-btn" data-cmd="bold" title="Negrito"><strong>B</strong></button>
                  <button type="button" class="pe-rte-btn" data-cmd="italic" title="Itálico"><em>I</em></button>
                  <button type="button" class="pe-rte-btn" data-cmd="underline" title="Sublinhado"><u>U</u></button>
                  <span class="pe-rte-sep"></span>
                  <button type="button" class="pe-rte-btn" data-cmd="formatBlock" data-val="h2" title="Título">H2</button>
                  <button type="button" class="pe-rte-btn" data-cmd="formatBlock" data-val="h3" title="Subtítulo">H3</button>
                  <button type="button" class="pe-rte-btn" data-cmd="formatBlock" data-val="p" title="Parágrafo">¶</button>
                  <span class="pe-rte-sep"></span>
                  <button type="button" class="pe-rte-btn" data-cmd="insertUnorderedList" title="Lista">•</button>
                  <button type="button" class="pe-rte-btn" data-cmd="insertOrderedList" title="Lista numerada">1.</button>
                  <span class="pe-rte-sep"></span>
                  <button type="button" class="pe-rte-btn" data-cmd="createLink" title="Link">🔗</button>
                  <button type="button" class="pe-rte-btn" data-cmd="removeFormat" title="Limpar formatação">⌫</button>
                </div>
              
                <div class="pe-rte-area" contenteditable="true" data-placeholder="Descreva o produto…"></div>
              </div>
            </div>
          </div>

          <!-- Categorização -->
          <div class="pe-card" style="margin-top:16px;">
            <div class="pe-card-title">Controles</div>
            <div class="pe-grid-2">
              <!-- <div class="form-group">
                <label class="pe-label">Categoria</label>
                <select name="categoria_id" class="form-control" id="pe-categoria">
                  <option value="">— Selecione —</option>
                  <?php foreach ($categorias as $cat): ?>
                  <option value="<?= $cat['id'] ?>"
                          <?= ((int)($p['categoria_id'] ?? 0) === (int)$cat['id']) ? 'selected' : '' ?>>
                    <?= !empty($cat['parent_id']) ? '└ ' : '' ?>
                    <?= View::e($cat['nome']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div> -->
              
              
              
              <div class="form-group">
                <label class="pe-label">Marca</label>
                <select name="marca_id" class="form-control" id="pe-marca">
                  <option value="">— Selecione —</option>
                  <?php foreach ($marcas as $m): ?>
                  <option value="<?= $m['id'] ?>"
                          <?= ((int)($p['marca_id'] ?? 0) === (int)$m['id']) ? 'selected' : '' ?>>
                    <?= View::e($m['nome']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="pe-grid-2" style="margin-top:0;">
              <div class="form-group">
                <label class="pe-label">SKU / Código</label>
                <input type="text" name="sku_legado" class="form-control"
                       value="<?= View::e($p['sku_legado'] ?? '') ?>"
                       placeholder="Ex: AGV-K3-SV-001"
                       style="font-family:var(--font-mono);font-size:13px;">
              </div>
              <div class="form-group">
                <label class="pe-label">Família de produtos</label>
                <select name="familia_id" class="form-control">
                  <option value="">— Produto independente —</option>
                  <?php foreach ($familias as $fam): ?>
                  <option value="<?= $fam['id'] ?>"
                          <?= ((int)($p['familia_id'] ?? 0) === (int)$fam['id']) ? 'selected' : '' ?>>
                    <?= View::e($fam['nome']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label class="pe-label">
                  Bling ID
                  <span style="font-weight:400;color:var(--c-text-muted);font-size:11px;">
                    (preenchido pelo sync; edite só se necessário)
                  </span>
                </label>
                <input type="text" name="bling_id" class="form-control"
                       value="<?= View::e($p['bling_id'] ?? '') ?>"
                       placeholder="Resolvido automaticamente"
                       style="font-family:var(--font-mono);font-size:13px;">
              </div>
            </div>
          </div>
        </section>

        <!-- Substitua a seção de categorias existente por: -->
        <?php include __DIR__ . '/_section-categorias.php'; ?>

        <!-- Adicione entre .pe-section#pe-geral e .pe-section#pe-midia -->
        <section class="pe-section" id="pe-familia">
          <div class="pe-section-head">
            <h2>Família de produtos</h2>
            <p>Agrupa este produto com variantes de cor ou estampa (URLs diferentes)</p>
          </div>

          <div class="pe-card" id="pe-familia-card">

            <?php if (!empty($p['familia_id'])): ?>
            <!-- ── Família existente ──────────────────────────────── -->
            <?php
              $stmtFam = Database::getInstance()->getConnection()->prepare(
                  "SELECT f.id, f.nome,
                          COUNT(pr.id) AS total_membros
                  FROM familia_produtos f
                  LEFT JOIN produtos pr ON pr.familia_id = f.id AND pr.deleted_at IS NULL
                  WHERE f.id = ?
                  GROUP BY f.id"
              );
              $stmtFam->execute([$p['familia_id']]);
              $familiaAtual = $stmtFam->fetch();
            ?>
            <div class="pe-familia-info" id="pe-familia-info">
              <div class="pe-familia-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                  <rect x="2" y="3" width="6" height="18" rx="2"/>
                  <rect x="9" y="3" width="6" height="18" rx="2"/>
                  <rect x="16" y="3" width="6" height="18" rx="2"/>
                </svg>
              </div>
              <div class="pe-familia-details">
                <span class="pe-familia-nome"><?= View::e($familiaAtual['nome']) ?></span>
                <span class="pe-familia-meta">
                  <?= (int)$familiaAtual['total_membros'] ?> produto<?= $familiaAtual['total_membros'] != 1 ? 's' : '' ?> nesta família
                  <a href="<?= BASE_URL ?>/admin/familias/<?= $familiaAtual['id'] ?>"
                    target="_blank" class="pe-familia-ver-link">
                    Ver família →
                  </a>
                </span>
              </div>
              <div class="pe-familia-actions">
                <button type="button" class="btn btn-sm btn-ghost"
                        id="btn-trocar-familia"
                        data-familia-id="<?= $familiaAtual['id'] ?>"
                        data-familia-nome="<?= View::e($familiaAtual['nome']) ?>">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <polyline points="17 1 21 5 17 9"/>
                    <path d="M3 11V9a4 4 0 014-4h14"/>
                    <polyline points="7 23 3 19 7 15"/>
                    <path d="M21 13v2a4 4 0 01-4 4H3"/>
                  </svg>
                  Trocar
                </button>
                <button type="button" class="btn btn-sm btn-ghost"
                        id="btn-remover-familia">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="18" y1="6" x2="6"  y2="18"/>
                    <line x1="6"  y1="6" x2="18" y2="18"/>
                  </svg>
                  Remover
                </button>
              </div>
            </div>
            <input type="hidden" name="familia_id" id="campo-familia-id"
                  value="<?= (int)$p['familia_id'] ?>">

            <?php else: ?>
            <!-- ── Sem família ────────────────────────────────────── -->
            <div class="pe-familia-vazia" id="pe-familia-vazia">
              <div class="pe-familia-vazia-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                  <rect x="2" y="3" width="6" height="18" rx="2"/>
                  <rect x="9" y="3" width="6" height="18" rx="2"/>
                  <rect x="16" y="3" width="6" height="18" rx="2"/>
                </svg>
              </div>
              <p class="pe-familia-vazia-msg">
                Este produto não pertence a nenhuma família.
              </p>
              <p class="pe-familia-vazia-hint">
                Famílias conectam produtos com variações visuais (cores, estampas)
                que possuem URLs diferentes.
              </p>
              <div class="pe-familia-vazia-actions">
                <button type="button" class="btn btn-outline btn-sm"
                        id="btn-buscar-familia">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                  </svg>
                  Vincular a família existente
                </button>
                <button type="button" class="btn btn-primary btn-sm"
                        id="btn-criar-familia">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5"  y1="12" x2="19" y2="12"/>
                  </svg>
                  Criar nova família
                </button>
              </div>
            </div>
            <input type="hidden" name="familia_id" id="campo-familia-id" value="">
            <?php endif; ?>

          </div>
        </section>

        <!-- ════════════════════════════════════════════════
             SEÇÃO: MÍDIA
             ════════════════════════════════════════════════ -->
        <section class="pe-section" id="pe-midia">
          <div class="pe-section-head">
            <h2>Mídia</h2>
            <p>Galeria de imagens do produto</p>
          </div>

          <div class="pe-card">
            <?php if (!$isEdit): ?>
            <div class="pe-midia-alert">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
              </svg>
              Salve o produto primeiro para fazer upload de imagens.
            </div>
            <?php else: ?>

            <!-- Upload area -->
            <div class="pe-upload-area" id="pe-upload-area">
              <input type="file" id="pe-img-input" accept="image/*"
                     multiple class="pe-upload-hidden">
              <div class="pe-upload-content">
                <div class="pe-upload-icon">
                  <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                  </svg>
                </div>
                <p class="pe-upload-title">Arraste imagens ou clique para selecionar</p>
                <p class="pe-upload-hint">PNG, JPG, WEBP — máx. 5MB por imagem</p>
                <button type="button" class="btn btn-outline btn-sm"
                        onclick="document.getElementById('pe-img-input').click()">
                  Escolher arquivos
                </button>
              </div>
            </div>

            <!-- Galeria -->
            <p class="pe-gallery-dica">
              Arraste as imagens para ordenar. A <strong>primeira</strong> é a capa —
              a que aparece na listagem e no compartilhamento.
            </p>

            <div class="pe-gallery" id="pe-gallery">
              <?php foreach ($imagens as $i => $img): ?>
              <div class="pe-gallery-item <?= $img['principal'] ? 'is-principal' : '' ?>"
                   data-id="<?= $img['id'] ?>" draggable="true">
                <img src="<?= $imgUrl($img['arquivo']) ?>"
                     alt="" loading="lazy" draggable="false">
                <span class="pe-gallery-pos"><?= (int)$i + 1 ?></span>
                <div class="pe-gallery-overlay">
                  <?php if (!$img['principal']): ?>
                  <button type="button" class="pe-gallery-btn pe-set-principal"
                          data-id="<?= $img['id'] ?>"
                          title="Mover para o início (vira a capa)">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                      <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                    </svg>
                  </button>
                  <?php endif; ?>
                  <button type="button" class="pe-gallery-btn pe-gallery-btn--del pe-del-img"
                          data-id="<?= $img['id'] ?>"
                          title="Remover">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                      <line x1="18" y1="6" x2="6"  y2="18"/>
                      <line x1="6"  y1="6" x2="18" y2="18"/>
                    </svg>
                  </button>
                </div>
                <?php if ($img['principal']): ?>
                <span class="pe-gallery-badge">Capa</span>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>

            <?php endif; ?>
          </div>
        </section>

        <!-- ── Clips vinculados ──────────────────────────── -->
        <section class="pe-section" id="pe-clips">
          <div class="pe-section-head">
            <h2>Clips</h2>
            <p>Vídeos que mostram este produto</p>
          </div>

          <div class="pe-card">
            <?php if (!$isEdit): ?>
              <div class="pe-midia-alert">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <circle cx="12" cy="12" r="10"/>
                  <line x1="12" y1="8" x2="12" y2="12"/>
                  <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                Salve o produto primeiro para vincular clips.
              </div>
            <?php else: ?>

            <!-- Mesmo componente do formulário de clips, pelo lado oposto da
                 relação: lá um clip escolhe produtos, aqui um produto escolhe
                 clips. A tabela clip_produtos é a mesma. -->
            <div class="clip-produtos-tags" id="pe-clips-tags">
              <?php foreach (($clipsVinculados ?? []) as $cv): ?>
                <span class="clip-produto-tag" data-id="<?= (int)$cv['id'] ?>">
                  <?= View::e($cv['titulo']) ?><?= empty($cv['ativo']) ? ' (inativo)' : '' ?>
                  <button type="button" class="clip-produto-tag-remove" data-id="<?= (int)$cv['id'] ?>">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="3" stroke-linecap="round">
                      <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                  </button>
                </span>
                <input type="hidden" name="clip_ids[]" value="<?= (int)$cv['id'] ?>">
              <?php endforeach; ?>
            </div>

            <!-- Sempre presente, mesmo sem nenhum clip vinculado: sem ele o POST
                 nao traz clip_ids e o servidor nao consegue distinguir "removi
                 todos" de "esta tela nao mexe nisso". -->
            <input type="hidden" name="clip_ids[]" value="" id="pe-clips-sentinela">

            <div class="clip-produto-search-wrap">
              <input type="text" class="clip-produto-search-input"
                     id="pe-clip-search"
                     placeholder="Buscar clip para vincular…"
                     autocomplete="off">
              <div class="clip-produto-dropdown" id="pe-clip-dropdown"></div>
            </div>

            <small class="form-help" style="margin-top:8px;display:block;">
              O clip aparece na página do produto e o produto vira card de compra
              dentro do clip. Vale para os dois lados.
            </small>

            <?php if (empty($clips)): ?>
              <small class="form-help" style="margin-top:10px;display:block;color:var(--warning)">
                Nenhum clip cadastrado ainda.
                <a href="<?= BASE_URL ?>/admin/clips/form" target="_blank" rel="noopener">Criar o primeiro</a>.
              </small>
            <?php endif; ?>

            <?php endif; ?>
          </div>
        </section>

        <!-- Adicionar após a seção de frete (#pe-shipping): -->
        <section class="pe-section" id="pe-caracteristicas">
          <div class="pe-section-head">
            <h2>Características</h2>
            <p>
              Especificações técnicas definidas pela categoria
              <?php if (!empty($p['categoria_id'])): ?>
              — baseadas em
              <strong><?= View::e($p['categoria_nome'] ?? '') ?></strong>
              <?php endif; ?>
            </p>
          </div>

          <div class="pe-card" id="pe-chars-card">
            <?php
            include __DIR__ . '/_caracteristicas.php';
            ?>
          </div>
        </section>

        <?php include __DIR__ . '/_section-compatibilidade.php'; ?>

        <!-- ════════════════════════════════════════════════
             SEÇÃO: PREÇO & ESTOQUE
             ════════════════════════════════════════════════ -->
        <section class="pe-section" id="pe-preco">
          <div class="pe-section-head">
            <h2>Preço & estoque</h2>
            <p>Precificação, promoções e controle de estoque</p>
          </div>

          <div class="pe-card">
            <div class="pe-card-title">Precificação</div>
            <div class="pe-grid-3">
              <div class="form-group">
                <label class="pe-label">Preço regular <span class="pe-required">*</span></label>
                <div class="pe-price-input-wrap">
                  <span class="pe-price-prefix">R$</span>
                  <input type="number" name="preco" id="pe-preco"
                         class="form-control pe-price-input"
                         value="<?= number_format((float)($p['preco'] ?? 0), 2, '.', '') ?>"
                         placeholder="0,00" step="0.01" min="0" required>
                </div>
              </div>
              <div class="form-group">
                <label class="pe-label">Preço promocional</label>
                <div class="pe-price-input-wrap">
                  <span class="pe-price-prefix">R$</span>
                  <input type="number" name="preco_promo" id="pe-preco-promo"
                         class="form-control pe-price-input"
                         value="<?= !empty($p['preco_promo']) ? number_format((float)$p['preco_promo'], 2, '.', '') : '' ?>"
                         placeholder="0,00" step="0.01" min="0">
                </div>
              </div>
              <div class="form-group">
                <label class="pe-label">Desconto</label>
                <div class="pe-discount-badge" id="pe-discount-badge">
                  <span id="pe-discount-val">—</span>
                </div>
              </div>
            </div>

            <!-- Custo — base de toda análise de margem -->
            <div class="pe-grid-3" style="margin-top:14px;">
              <div class="form-group">
                <label class="pe-label">Custo de aquisição</label>
                <div class="pe-price-input-wrap">
                  <span class="pe-price-prefix">R$</span>
                  <input type="number" name="preco_custo" id="pe-preco-custo"
                         class="form-control pe-price-input"
                         value="<?= !empty($p['preco_custo']) ? number_format((float)$p['preco_custo'], 2, '.', '') : '' ?>"
                         placeholder="0,00" step="0.01" min="0">
                </div>
                <small style="color:var(--text-3);font-size:11px;">
                  Em branco = desconhecido. O produto fica de fora do cálculo de margem
                  — nunca entra como margem de 100%.
                </small>
              </div>
              <div class="form-group">
                <label class="pe-label">Margem estimada</label>
                <div class="pe-discount-badge" id="pe-margem-badge">
                  <span id="pe-margem-val">—</span>
                </div>
              </div>
              <div class="form-group">
                <label class="pe-label">Lucro por unidade</label>
                <div class="pe-discount-badge" id="pe-lucro-badge">
                  <span id="pe-lucro-val">—</span>
                </div>
              </div>
            </div>

            <script>
            // Margem ao vivo: quem digita o custo vê na hora o que ganha.
            // Usa o preço promocional quando existe — é o que o cliente paga.
            (function () {
              var custo = document.getElementById('pe-preco-custo'),
                  preco = document.getElementById('pe-preco'),
                  promo = document.getElementById('pe-preco-promo'),
                  mVal  = document.getElementById('pe-margem-val'),
                  lVal  = document.getElementById('pe-lucro-val');
              if (!custo || !preco) return;

              function brl(v) {
                return 'R$ ' + v.toFixed(2).replace('.', ',');
              }
              function calc() {
                var c = parseFloat(custo.value) || 0,
                    p = parseFloat(promo && promo.value) || parseFloat(preco.value) || 0;
                if (c <= 0 || p <= 0) { mVal.textContent = '—'; lVal.textContent = '—'; return; }
                var lucro  = p - c,
                    margem = (lucro / p) * 100;
                lVal.textContent = brl(lucro);
                mVal.textContent = margem.toFixed(1).replace('.', ',') + '%';
                var cor = margem < 0 ? '#dc2626' : (margem < 15 ? '#d97706' : '#16a34a');
                mVal.style.color = cor;
                lVal.style.color = cor;
              }
              [custo, preco, promo].forEach(function (el) {
                if (el) el.addEventListener('input', calc);
              });
              calc();
            })();
            </script>

            <!-- Período da promoção -->
            <div class="pe-promo-periodo" id="pe-promo-periodo"
                 style="<?= empty($p['preco_promo']) ? 'display:none' : '' ?>">
              <div class="pe-grid-2">
                <div class="form-group">
                  <label class="pe-label">Início da promoção</label>
                  <input type="datetime-local" name="promo_inicio"
                         class="form-control"
                         value="<?= !empty($p['promo_inicio'])
                             ? date('Y-m-d\TH:i', strtotime($p['promo_inicio'])) : '' ?>">
                </div>
                <div class="form-group">
                  <label class="pe-label">Fim da promoção</label>
                  <input type="datetime-local" name="promo_fim"
                         class="form-control"
                         value="<?= !empty($p['promo_fim'])
                             ? date('Y-m-d\TH:i', strtotime($p['promo_fim'])) : '' ?>">
                </div>
              </div>
            </div>

            <div class="form-group" style="margin-top:8px;">
              <label class="pe-label">Máximo de parcelas</label>
              <select name="parcelas_max" class="form-control" style="max-width:200px;">
                <?php for ($i=1; $i<=12; $i++): ?>
                <option value="<?= $i ?>"
                        <?= ((int)($p['parcelas_max'] ?? 12) === $i) ? 'selected' : '' ?>>
                  <?= $i ?>x sem juros
                </option>
                <?php endfor; ?>
              </select>
            </div>
          </div>

          <!-- Estoque -->
          <!-- <div class="pe-card" style="margin-top:16px;">
            <div class="pe-card-title">Estoque</div>
            <div class="pe-grid-3">
              <div class="form-group">
                <label class="pe-label">Quantidade em estoque</label>
                <div class="pe-estoque-wrap">
                  <button type="button" class="pe-estoque-btn" data-op="minus">−</button>
                  <input type="number" name="estoque_total"
                         class="form-control pe-estoque-input"
                         value="<?= (int)($p['estoque_total'] ?? 0) ?>"
                         min="0" id="pe-estoque">
                  <button type="button" class="pe-estoque-btn" data-op="plus">+</button>
                </div>
              </div>
              <div class="form-group">
                <label class="pe-label">Estoque mínimo</label>
                <p class="pe-field-hint">Alerta quando chegar neste valor</p>
                <input type="number" name="estoque_minimo"
                       class="form-control"
                       value="<?= (int)($p['estoque_minimo'] ?? 0) ?>"
                       min="0" id="pe-estoque-min">
              </div>
              <div class="form-group">
                <label class="pe-label">Status do estoque</label>
                <div class="pe-estoque-status" id="pe-estoque-status">
                  <span class="pe-estoque-indicator"></span>
                  <span id="pe-estoque-label">—</span>
                </div>
              </div>
            </div>
          </div> -->

          <!-- Substitua o .pe-card de estoque por: -->
            
          <!-- Dentro de #pe-preco, substitua o card de estoque por: -->

          <?php
            $estoqueService = new EstoqueService();
            $db = Database::getInstance()->getConnection();

            if ($isEdit):
              if (!empty($p['tem_variacao'])) {
                // Soma saldo de todos os SKUs ativos
                $stmt = $db->prepare(
                  "SELECT
                      ps.id, ps.sku,
                      COALESCE(es.saldo,     0) AS saldo,
                      COALESCE(es.reservado, 0) AS reservado,
                      GREATEST(COALESCE(es.saldo,0) - COALESCE(es.reservado,0), 0) AS disponivel
                  FROM produto_skus ps
                  LEFT JOIN estoque_saldo es
                          ON es.sku_id = ps.id AND es.produto_id = ps.produto_id
                  WHERE ps.produto_id = ? AND ps.ativo = 1
                  ORDER BY ps.id ASC"
                );
                $stmt->execute([$p['id']]);
                $estoqueSkus   = $stmt->fetchAll();
                $saldoTotal    = array_sum(array_column($estoqueSkus, 'saldo'));
                $reservadoTotal= array_sum(array_column($estoqueSkus, 'reservado'));
                $disponivelTotal = max(0, $saldoTotal - $reservadoTotal);
              } else {
                $saldoTotal     = $estoqueService->getSaldo((int)$p['id']);
                $disponivelTotal= $estoqueService->getDisponivel((int)$p['id']);
                $reservadoTotal = $saldoTotal - $disponivelTotal;
                $estoqueSkus    = [];
              }
            endif;
            ?>

            <div class="pe-card" style="margin-top:16px;" id="pe-estoque-card">
              <div class="pe-card-title-row">
                <span class="pe-card-title">Estoque</span>
                <?php if (!empty($p['tem_variacao'])): ?>
                <span class="pe-estoque-variacao-aviso">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                    <polyline points="2 17 12 22 22 17"/>
                    <polyline points="2 12 12 17 22 12"/>
                  </svg>
                  Calculado pelos SKUs
                </span>
                <?php endif; ?>
              </div>

              <?php if ($isEdit): ?>
              <!-- ── Totalizadores ─────────────────────────────────── -->
              <div class="pe-estoque-saldo-row">
                <div class="pe-estoque-saldo-item">
                  <span class="pe-estoque-saldo-label">
                    <?= !empty($p['tem_variacao']) ? 'Total em estoque' : 'Saldo atual' ?>
                  </span>
                  <span class="pe-estoque-saldo-valor" id="pe-saldo-atual">
                    <?= number_format($saldoTotal) ?> un
                  </span>
                </div>
                <div class="pe-estoque-saldo-item">
                  <span class="pe-estoque-saldo-label">Disponível</span>
                  <span class="pe-estoque-saldo-valor pe-estoque-saldo-valor--disp"
                        id="pe-saldo-disponivel">
                    <?= number_format($disponivelTotal) ?> un
                  </span>
                </div>
                <div class="pe-estoque-saldo-item">
                  <span class="pe-estoque-saldo-label">Reservado</span>
                  <span class="pe-estoque-saldo-valor pe-estoque-saldo-valor--res"
                        id="pe-saldo-reservado">
                    <?= number_format($reservadoTotal) ?> un
                  </span>
                </div>
              </div>

              <?php if (!empty($p['tem_variacao']) && !empty($estoqueSkus)): ?>
              <!-- ── Breakdown por SKU ─────────────────────────────── -->
              <div class="pe-estoque-skus-breakdown">
                <div class="pe-estoque-breakdown-header">
                  <span>SKU</span>
                  <span>Saldo</span>
                  <span>Reservado</span>
                  <span>Disponível</span>
                  <span></span>
                </div>
                <?php foreach ($estoqueSkus as $es): ?>
                <div class="pe-estoque-breakdown-row"
                    id="breakdown-sku-<?= $es['id'] ?>">
                  <span class="pe-estoque-sku-codigo">
                    <?= View::e($es['sku']) ?>
                  </span>
                  <span class="pe-estoque-sku-saldo">
                    <?= number_format($es['saldo']) ?>
                  </span>
                  <span class="pe-estoque-sku-reservado"
                        style="color:var(--warning)">
                    <?= number_format($es['reservado']) ?>
                  </span>
                  <span class="pe-estoque-sku-disponivel"
                        style="color:var(--success)">
                    <?= number_format($es['disponivel']) ?>
                  </span>
                  <div class="pe-estoque-sku-actions">
                    <button type="button"
                            class="btn btn-xs btn-ghost btn-ajustar-sku-estoque"
                            data-sku-id="<?= $es['id'] ?>"
                            data-sku-codigo="<?= View::e($es['sku']) ?>"
                            data-produto-id="<?= (int)$p['id'] ?>"
                            data-saldo="<?= (int)$es['saldo'] ?>"
                            title="Ajustar estoque">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                          stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5"  y1="12" x2="19" y2="12"/>
                      </svg>
                      Ajustar
                    </button>
                    <button type="button"
                            class="btn btn-xs btn-ghost btn-historico-sku-estoque"
                            data-sku-id="<?= $es['id'] ?>"
                            data-sku-codigo="<?= View::e($es['sku']) ?>"
                            data-produto-id="<?= (int)$p['id'] ?>"
                            title="Ver histórico">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                          stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                      </svg>
                      Histórico
                    </button>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <!-- ── Campo estoque_minimo (sempre visível) ──────────── -->
              <div id="pe-estoque-manual"
                  style="<?= !empty($p['tem_variacao']) ? 'margin-top:14px;padding-top:14px;border-top:1px solid var(--border)' : '' ?>">
                <?php if (empty($p['tem_variacao'])): ?>
                <!-- Campo manual só para produto sem variação -->
                <div class="pe-grid-2" style="margin-bottom:0;">
                  <div class="form-group">
                    <label class="pe-label">Estoque mínimo (alerta)</label>
                    <input type="number" name="estoque_minimo"
                          class="form-control" min="0"
                          value="<?= (int)($p['estoque_minimo'] ?? 0) ?>"
                          id="pe-estoque-min">
                  </div>
                  <div class="form-group">
                    <label class="pe-label">Status</label>
                    <div class="pe-estoque-status" id="pe-estoque-status">
                      <span class="pe-estoque-indicator"></span>
                      <span id="pe-estoque-label">—</span>
                    </div>
                  </div>
                </div>
                <?php else: ?>
                <div class="form-group" style="margin-bottom:0;">
                  <label class="pe-label">Estoque mínimo por SKU (alerta)</label>
                  <input type="number" name="estoque_minimo"
                        class="form-control" min="0" style="max-width:140px;"
                        value="<?= (int)($p['estoque_minimo'] ?? 0) ?>"
                        id="pe-estoque-min">
                  <p class="pe-field-hint">
                    Alerta quando qualquer SKU atingir este valor.
                  </p>
                </div>
                <?php endif; ?>
              </div>

              <!-- ── Ações gerais ───────────────────────────────────── -->
              <div class="pe-estoque-actions">
                <?php if (empty($p['tem_variacao'])): ?>
                <button type="button" class="btn btn-sm btn-outline"
                        id="btn-ajustar-estoque"
                        data-produto-id="<?= (int)$p['id'] ?>"
                        data-saldo="<?= $saldoTotal ?>">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5"  y1="12" x2="19" y2="12"/>
                  </svg>
                  Ajustar estoque
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-ghost"
                        id="btn-ver-historico-estoque"
                        data-produto-id="<?= (int)$p['id'] ?>">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                  </svg>
                  <?= !empty($p['tem_variacao']) ? 'Histórico geral' : 'Ver histórico' ?>
                </button>
<?php // O saldo é espelho do Bling: o botão puxa de lá, não recalcula localmente. ?>
                <button type="button" class="btn btn-sm btn-ghost"
                        id="btn-ressincronizar-estoque"
                        data-produto-id="<?= (int)$p['id'] ?>"
                        title="Puxa o saldo atual do Bling e grava no site">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>
                  </svg>
                  Puxar do Bling
                </button>
              </div>

              <?php endif; // isEdit ?>
            </div>

          
        </section>
        <!-- Modal: ajuste de estoque -->
          <div class="pe-modal-backdrop" id="modal-ajuste-estoque" style="display:none;">
            <div class="pe-modal">
              <div class="pe-modal-header">
                <h3>Ajustar estoque</h3>
                <button type="button" class="pe-modal-close"
                        id="btn-close-modal-ajuste">×</button>
              </div>
              <div class="pe-modal-body">
                <div class="estoque-operacao-tabs">
                  <button type="button"
                          class="estoque-op-tab active" data-op="entrada">
                    + Entrada
                  </button>
                  <button type="button"
                          class="estoque-op-tab" data-op="saida">
                    − Saída
                  </button>
                  <button type="button"
                          class="estoque-op-tab" data-op="corrigir">
                    = Corrigir
                  </button>
                </div>
                <input type="hidden" id="ajuste-operacao" value="entrada">

                <div class="form-group" style="margin-top:16px;">
                  <label class="pe-label" id="ajuste-qtd-label">
                    Quantidade a adicionar
                  </label>
                  <input type="number" id="ajuste-quantidade"
                        class="form-control" min="1" value="1">
                </div>

                <div class="form-group">
                  <label class="pe-label">Observação</label>
                  <input type="text" id="ajuste-observacao"
                        class="form-control"
                        placeholder="Ex: Recebimento NF 1234, Ajuste inventário...">
                </div>

                <!-- Preview do resultado -->
                <div class="estoque-ajuste-preview" id="estoque-ajuste-preview">
                  <span>Saldo atual: <strong id="preview-saldo-atual">—</strong></span>
                  <span class="estoque-ajuste-arrow">→</span>
                  <span>Novo saldo: <strong id="preview-saldo-novo">—</strong></span>
                </div>
              </div>
              <div class="pe-modal-footer">
                <button type="button" class="btn btn-ghost"
                        id="btn-cancelar-ajuste">Cancelar</button>
                <button type="button" class="btn btn-primary"
                        id="btn-confirmar-ajuste">Confirmar ajuste</button>
              </div>
            </div>
          </div>

          <!-- Modal: histórico de estoque -->
          <div class="pe-modal-backdrop" id="modal-historico-estoque" style="display:none;">
            <div class="pe-modal pe-modal--lg">
              <div class="pe-modal-header">
                <h3>Histórico de estoque</h3>
                <button type="button" class="pe-modal-close"
                        id="btn-close-modal-historico">×</button>
              </div>
              <div class="pe-modal-body" id="modal-historico-body">
                <div class="pe-loading">Carregando...</div>
              </div>
            </div>
          </div>
        <!-- ════════════════════════════════════════════════
             SEÇÃO: VARIAÇÕES
             ════════════════════════════════════════════════ -->
        <section class="pe-section" id="pe-variacao">
          <div class="pe-section-head">
            <h2>Variações</h2>
            <p>Configure SKUs, tamanhos, cores e outros atributos</p>
          </div>

          <div class="pe-card">
            <label class="pe-toggle-label">
              <div class="pe-toggle-switch">
                <input type="checkbox" name="tem_variacao" id="pe-tem-variacao"
                       value="1" <?= !empty($p['tem_variacao']) ? 'checked' : '' ?>>
                <span class="pe-toggle-track">
                  <span class="pe-toggle-thumb-inner"></span>
                </span>
              </div>
              <div>
                <span class="pe-toggle-title">Este produto tem variações</span>
                <span class="pe-toggle-desc">
                  Ative para gerenciar diferentes versões (tamanho, cor, voltagem...)
                </span>
              </div>
            </label>
          </div>

          <!-- Painel de variações (visível quando ativo) -->
          <div id="pe-variacao-panel"
               style="<?= empty($p['tem_variacao']) ? 'display:none' : '' ?>">

            <!-- Agrupadores (cor, estampa) -->
            <div class="pe-card" style="margin-top:16px;">
              <div class="pe-card-title-row">
                <span class="pe-card-title">Atributos agrupadores</span>
                <span class="pe-card-hint">
                  Definem a família do produto (ex: cor, estampa)
                </span>
              </div>

              <!-- Substitua o bloco #pe-agrupadores-list por: -->

              <!-- Substitua o #pe-agrupadores-list por: -->

              <div id="pe-agrupadores-list">
                <?php foreach ($agrupadores ?? [] as $ag): ?>
                <div class="pe-attr-row" data-tipo-id="<?= $ag['atributo_tipo_id'] ?>">

                  <div class="pe-attr-tipo">
                    <span><?= View::e($ag['tipo_nome']) ?></span>
                    <span class="pe-attr-display-type"><?= View::e($ag['tipo_display']) ?></span>
                  </div>

                  <div class="pe-attr-valor-wrap">
                    <!-- Hidden com o valor real -->
                    <input type="hidden"
                          class="pe-ag-hidden"
                          data-tipo-id="<?= $ag['atributo_tipo_id'] ?>"
                          value="<?= View::e($ag['valor']) ?>">

                    <!-- Hidden com o hex (color_swatch) -->
                    <input type="hidden"
                          class="pe-ag-hex"
                          data-tipo-id="<?= $ag['atributo_tipo_id'] ?>"
                          value="<?= View::e($ag['valor_hex'] ?? '') ?>">

                    <!-- Badge do valor selecionado -->
                    <div class="pe-ag-badge-wrap" id="ag-badge-<?= $ag['atributo_tipo_id'] ?>">
                      <?php if (!empty($ag['valor'])): ?>
                      <span class="pe-sku-badge">
                        <?php if ($ag['tipo_display'] === 'color_swatch' && !empty($ag['valor_hex'])): ?>
                        <span class="pe-sku-badge-swatch"
                              style="background:<?= View::e($ag['valor_hex']) ?>"></span>
                        <?php endif; ?>
                        <span class="pe-sku-badge-valor"><?= View::e($ag['valor']) ?></span>
                      </span>
                      <?php endif; ?>
                    </div>

                    <!-- Botão abrir modal -->
                    <button type="button"
                            class="pe-ag-edit-btn"
                            data-tipo-id="<?= $ag['atributo_tipo_id'] ?>"
                            data-tipo-nome="<?= View::e($ag['tipo_nome']) ?>"
                            data-tipo-display="<?= View::e($ag['tipo_display']) ?>"
                            title="Selecionar valor">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                          stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                      </svg>
                      Editar
                    </button>
                  </div>

                  <button type="button" class="pe-attr-del">×</button>
                </div>
                <?php endforeach; ?>
              </div>

              <button type="button" class="pe-add-btn" id="btn-add-agrupador">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <line x1="12" y1="5" x2="12" y2="19"/>
                  <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Adicionar atributo agrupador
              </button>
            </div>

            <!-- SKUs -->
            <div class="pe-card" style="margin-top:16px;">
              <div class="pe-card-title-row">
                <span class="pe-card-title">SKUs</span>
                <div>
                  <button type="button" class="btn btn-sm btn-outline" id="btn-add-sku">
                    + Adicionar SKU
                  </button>
                  <button type="button" class="btn btn-sm btn-primary" id="btn-sync-skus-bling"
                          data-produto-id="<?= (int)($p['id'] ?? 0) ?>">
                    ⟳ Sincronizar com Bling
                  </button>
                </div>
              </div>

              <!-- <div class="pe-skus-table-wrap">
                <table class="pe-skus-table">
                  <thead>
                    <tr>
                      <th>Código SKU</th>
                      <th>Atributos</th>
                      <th>Preço</th>
                      <th>Estoque</th>
                      <th>Ativo</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="pe-skus-tbody">
                    <?php foreach ($skus ?? [] as $sku): ?>
                    <tr data-sku-id="<?= $sku['id'] ?>">
                      <td>
                        <input type="text" class="form-control form-control--sm"
                               name="skus[<?= $sku['id'] ?>][sku]"
                               value="<?= View::e($sku['sku']) ?>"
                               style="font-family:var(--font-mono);font-size:12px;"
                               placeholder="SKU-001">
                      </td>
                      <td>
                        <span class="pe-sku-attrs">
                          <?= View::e($sku['atributos_str'] ?? '—') ?>
                        </span>
                      </td>
                      <td>
                        <div class="pe-price-input-wrap" style="max-width:120px;">
                          <span class="pe-price-prefix">R$</span>
                          <input type="number" class="form-control pe-price-input"
                                 name="skus[<?= $sku['id'] ?>][preco]"
                                 value="<?= number_format((float)$sku['preco'], 2, '.', '') ?>"
                                 step="0.01" min="0">
                        </div>
                      </td>
                      <td>
                        <input type="number" class="form-control form-control--sm"
                               name="skus[<?= $sku['id'] ?>][estoque]"
                               value="<?= (int)$sku['estoque'] ?>"
                               min="0" style="max-width:80px;">
                      </td>
                      <td>
                        <label class="pe-toggle-mini">
                          <input type="checkbox"
                                 name="skus[<?= $sku['id'] ?>][ativo]"
                                 value="1" <?= $sku['ativo'] ? 'checked' : '' ?>>
                          <span class="pe-toggle-mini-track"></span>
                        </label>
                      </td>
                      <td>
                        <button type="button" class="pe-sku-del btn btn-xs btn-ghost btn-danger">
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                            <line x1="18" y1="6" x2="6"  y2="18"/>
                            <line x1="6"  y1="6" x2="18" y2="18"/>
                          </svg>
                        </button>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div> -->

              <!-- Substitua todo o bloco .pe-skus-wrap por: -->
              <div class="pe-skus-table-wrap">
                    <table class="pe-skus-table">
                        <thead>
                        <tr>
                            <th style="width:100px;">Código SKU</th>
                            <th>Atributos</th>
                            <th style="width:130px;">Preço</th>
                            <th style="width:130px;">Promo</th>
                            <th style="width:100px;">Estoque</th>
                            <th style="width:50px;">Ativo</th>
                            <th style="width:36px;">Del</th>
                        </tr>
                        </thead>
                        <tbody id="pe-skus-tbody">
                        <?php foreach ($skus ?? [] as $sku):
                            $attrsMap = $sku['atributos_map'] ?? [];
                        ?>
                        <tr class="pe-sku-row" data-sku-id="<?= $sku['id'] ?>">

                            <td>
                              <?php
                                $skuBlingId = $sku['bling_id'] ?? null;
                                $skuSync    = !empty($skuBlingId);
                              ?>
                              <div style="display:flex;align-items:center;gap:6px;">
                                <span class="pe-sku-bling <?= $skuSync ? 'is-sync' : 'is-nosync' ?>"
                                      title="<?= $skuSync
                                          ? 'Vinculado ao Bling — ID: ' . View::e($skuBlingId)
                                          : 'Não vinculado ao Bling' ?>">
                                  <?php if ($skuSync): ?>
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                  <?php else: ?>
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                                  <?php endif; ?>
                                </span>
                                <input type="text"
                                       name="skus[<?= $sku['id'] ?>][sku]"
                                       class="form-control form-control--sm"
                                       value="<?= View::e($sku['sku']) ?>"
                                       placeholder="SKU-001"
                                       style="font-family:var(--font-mono);font-size:10px;">
                              </div>
                            </td>

                            <td>
                            <!-- Atributos selecionados como badges -->
                              <div class="pe-sku-attrs-badges" id="sku-badges-<?= $sku['id'] ?>">
                                  <?php foreach ($atributos_tipos as $tipo):
                                  if ($tipo['papel'] !== 'variacao') continue;
                                  $valor = $attrsMap[(int)$tipo['id']] ?? '';
                                  if (empty($valor)) continue;

                                  // Busca hex se for color_swatch
                                  $hex = null;
                                  if ($tipo['tipo_display'] === 'color_swatch') {
                                      $stmtHex = Database::getInstance()->getConnection()->prepare(
                                      "SELECT valor_hex FROM atributo_valores
                                      WHERE atributo_tipo_id = ? AND valor = ? LIMIT 1"
                                      );
                                      $stmtHex->execute([$tipo['id'], $valor]);
                                      $hex = $stmtHex->fetchColumn() ?: null;
                                  }
                                  ?>
                                  <span class="pe-sku-badge"
                                      data-tipo-id="<?= $tipo['id'] ?>"
                                      data-tipo-nome="<?= View::e($tipo['nome']) ?>">
                                  <?php if ($hex): ?>
                                  <span class="pe-sku-badge-swatch"
                                          style="background:<?= View::e($hex) ?>"></span>
                                  <?php endif; ?>
                                  <span class="pe-sku-badge-label"><?= View::e($tipo['nome']) ?>:</span>
                                  <span class="pe-sku-badge-valor"><?= View::e($valor) ?></span>
                                  <!-- Hidden para o submit -->
                                  <input type="hidden"
                                          name="skus[<?= $sku['id'] ?>][atributos][<?= $tipo['id'] ?>]"
                                          value="<?= View::e($valor) ?>">
                                  </span>
                                  <?php endforeach; ?>

                                  <!-- Botão abrir modal -->
                                  <button type="button"
                                          class="pe-sku-attrs-btn"
                                          data-sku-key="<?= $sku['id'] ?>"
                                          title="Selecionar atributos">
                                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                                      stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                      <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                      <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                  </svg>
                                  Editar
                                  </button>
                              </div>
                            </td>

                            <td>
                            <div class="pe-price-input-wrap">
                                <span class="pe-price-prefix" style="font-size:11px;">R$</span>
                                <input type="number"
                                    name="skus[<?= $sku['id'] ?>][preco]"
                                    class="form-control pe-price-input"
                                    value="<?= number_format((float)$sku['preco'], 2, '.', '') ?>"
                                    step="0.01" min="0" style="font-size:13px;">
                            </div>
                            </td>

                            <td>
                            <div class="pe-price-input-wrap">
                                <span class="pe-price-prefix" style="font-size:11px;">R$</span>
                                <input type="number"
                                    name="skus[<?= $sku['id'] ?>][preco_promo]"
                                    class="form-control pe-price-input"
                                    value="<?= !empty($sku['preco_promo']) ? number_format((float)$sku['preco_promo'], 2, '.', '') : '' ?>"
                                    step="0.01" min="0" placeholder="—"
                                    style="font-size:13px;">
                            </div>
                            </td>

                            <!-- Substitua o <td> de estoque na tabela de SKUs por: -->
                            <td>
                              <div class="pe-sku-estoque-wrap" id="sku-est-wrap-<?= $sku['id'] ?>">
                                <input type="number"
                                      name="skus[<?= $sku['id'] ?>][estoque]"
                                      class="form-control form-control--sm pe-sku-estoque"
                                      value="<?= (int)$sku['estoque'] ?>"
                                      data-original="<?= (int)$sku['estoque'] ?>"
                                      data-sku-id="<?= $sku['id'] ?>"
                                      data-produto-id="<?= (int)$p['id'] ?>"
                                      min="0"
                                      style="max-width:72px;">
                                <!-- Botões aparecem ao editar -->
                                <div class="pe-sku-estoque-btns" id="sku-est-btns-<?= $sku['id'] ?>"
                                    style="display:none;">
                                  <button type="button"
                                          class="pe-sku-est-confirm"
                                          data-sku-id="<?= $sku['id'] ?>"
                                          title="Confirmar alteração">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="3" stroke-linecap="round">
                                      <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                  </button>
                                  <button type="button"
                                          class="pe-sku-est-cancel"
                                          data-sku-id="<?= $sku['id'] ?>"
                                          title="Cancelar">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="3" stroke-linecap="round">
                                      <line x1="18" y1="6" x2="6"  y2="18"/>
                                      <line x1="6"  y1="6" x2="18" y2="18"/>
                                    </svg>
                                  </button>
                                </div>
                              </div>
                            </td>

                            <td style="text-align:center;">
                            <label class="pe-toggle-mini">
                                <input type="checkbox"
                                    name="skus[<?= $sku['id'] ?>][ativo]"
                                    value="1"
                                    <?= ($sku['ativo'] ?? 1) ? 'checked' : '' ?>>
                                <span class="pe-toggle-mini-track"></span>
                            </label>
                            </td>

                            <td>
                            <button type="button" class="pe-sku-del" title="Remover SKU">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                <line x1="18" y1="6" x2="6"  y2="18"/>
                                <line x1="6"  y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                            </td>

                        </tr>
                        
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="pe-skus-empty" id="pe-skus-empty"
                        style="<?= !empty($skus) ? 'display:none' : '' ?>">
                        Nenhum SKU. Clique em "+ Adicionar SKU" para começar.
                    </div>
                </div>

                <!-- Modal de seleção de atributos do SKU -->
                <div class="pe-modal-backdrop" id="modal-sku-attrs" style="display:none;">
                    <div class="pe-modal pe-modal--attrs">
                        <div class="pe-modal-header">
                        <h3>Atributos do SKU</h3>
                        <button type="button" class="pe-modal-close" id="btn-close-modal-attrs">×</button>
                        </div>
                        <div class="pe-modal-body" id="modal-attrs-body">
                        <!-- Preenchido via JS -->
                        </div>
                        <div class="pe-modal-footer">
                        <button type="button" class="btn btn-ghost" id="btn-cancelar-attrs">
                            Cancelar
                        </button>
                        <button type="button" class="btn btn-primary" id="btn-confirmar-attrs">
                            Confirmar seleção
                        </button>
                        </div>
                    </div>
                </div>
                <!-- Substitua o modal #modal-agrupador por: -->
                <!-- O modal já existente, apenas garanta que tem essa estrutura: -->
                <!-- O modal já existente, apenas garanta que tem essa estrutura: -->
<div class="pe-modal-backdrop" id="modal-agrupador" style="display:none;">
  <div class="pe-modal">
    <div class="pe-modal-header">
      <h3 id="modal-ag-titulo">Atributo agrupador</h3>
      <button type="button" class="pe-modal-close"
              id="btn-close-modal-agrupador">×</button>
    </div>
    <div class="pe-modal-body">

      <!-- Tipo (só aparece ao adicionar novo, escondido ao editar) -->
      <div class="form-group" id="modal-ag-tipo-group">
        <label class="pe-label">Tipo de atributo</label>
        <select class="form-control" id="modal-tipo-atributo">
          <option value="">— Selecione —</option>
          <?php foreach ($atributos_tipos as $at):
            if ($at['papel'] !== 'agrupador') continue;
          ?>
          <option value="<?= $at['id'] ?>"
                  data-display="<?= View::e($at['tipo_display']) ?>"
                  data-nome="<?= View::e($at['nome']) ?>">
            <?= View::e($at['nome']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Valores pré-definidos (renderizado via JS) -->
      <div id="modal-ag-valor-area" style="display:none;">
        <label class="pe-label" style="margin-bottom:8px;">
          Selecione o valor
        </label>

        <!-- Botões/swatches -->
        <div id="modal-ag-opcoes" class="modal-ag-opcoes-grid"></div>

        <!-- Input livre -->
        <div style="margin-top:10px;">
          <label class="pe-label" style="font-size:11px;color:var(--text-3);">
            Ou digite um valor personalizado
          </label>
          <input type="text"
                 class="form-control" id="modal-ag-texto"
                 placeholder="Ex: Vermelho, Azul...">
        </div>
        
        <!-- Dentro do #modal-ag-valor-area, antes do input de texto: -->
        <div id="modal-ag-sem-valores" style="display:none;" class="modal-ag-sem-valores-aviso">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          Nenhum valor pré-definido para este tipo.
          <a href="<?= BASE_URL ?>/admin/atributos" target="_blank">
            Cadastrar valores →
          </a>
        </div>

        <!-- Color picker (só color_swatch) -->
        <div id="modal-ag-cor-group" style="display:none;margin-top:12px;">
          <label class="pe-label">Cor (hex)</label>
          <div style="display:flex;gap:10px;align-items:center;">
            <input type="color" id="modal-ag-cor-picker"
                   class="pe-color-swatch-input-lg" value="#ff0000">
            <input type="text" id="modal-ag-cor-hex"
                   class="form-control" value="#FF0000" maxlength="7"
                   style="font-family:var(--font-mono);max-width:120px;">
          </div>
        </div>
      </div>

    </div>
    <div class="pe-modal-footer">
      <button type="button" class="btn btn-ghost"
              id="btn-cancelar-agrupador">Cancelar</button>
      <button type="button" class="btn btn-primary"
              id="btn-confirmar-agrupador">Confirmar</button>
    </div>
  </div>
</div>
                

            </div>
          </div>
        </section>

        <!-- ════════════════════════════════════════════════
             SEÇÃO: FRETE & DIMENSÕES
             ════════════════════════════════════════════════ -->
        <section class="pe-section" id="pe-shipping">
          <div class="pe-section-head">
            <h2>Frete & dimensões</h2>
            <p>Dados para cálculo de frete</p>
          </div>

          <div class="pe-card">
            <div class="pe-card-title">Peso e dimensões</div>
            <div class="pe-dimensoes-grid">
              <div class="form-group">
                <label class="pe-label">Peso (kg)</label>
                <div class="pe-unit-input">
                  <input type="number" name="peso_kg" class="form-control"
                         value="<?= $p['peso_kg'] ?? '' ?>"
                         placeholder="0.000" step="0.001" min="0">
                  <span class="pe-unit">kg</span>
                </div>
              </div>
              <div class="form-group">
                <label class="pe-label">Comprimento</label>
                <div class="pe-unit-input">
                  <input type="number" name="comprimento_cm" class="form-control"
                         value="<?= $p['comprimento_cm'] ?? '' ?>"
                         placeholder="0" step="0.5" min="0">
                  <span class="pe-unit">cm</span>
                </div>
              </div>
              <div class="form-group">
                <label class="pe-label">Largura</label>
                <div class="pe-unit-input">
                  <input type="number" name="largura_cm" class="form-control"
                         value="<?= $p['largura_cm'] ?? '' ?>"
                         placeholder="0" step="0.5" min="0">
                  <span class="pe-unit">cm</span>
                </div>
              </div>
              <div class="form-group">
                <label class="pe-label">Altura</label>
                <div class="pe-unit-input">
                  <input type="number" name="altura_cm" class="form-control"
                         value="<?= $p['altura_cm'] ?? '' ?>"
                         placeholder="0" step="0.5" min="0">
                  <span class="pe-unit">cm</span>
                </div>
              </div>
            </div>

            
            <div class="pe-frete-preview">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                <circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>
              </svg>
              <span id="pe-frete-info">
                Preencha o peso e dimensões para ver o cubo volumétrico.
              </span>
            </div>
          </div>
        </section>

        

        <!-- ════════════════════════════════════════════════
             SEÇÃO: SEO
             ════════════════════════════════════════════════ -->
        <section class="pe-section" id="pe-seo">
          <div class="pe-section-head">
            <h2>SEO</h2>
            <p>Como este produto aparecerá nos mecanismos de busca</p>
          </div>

          <!-- Preview Google -->
          <div class="pe-card pe-seo-preview-card">
            <div class="pe-card-title">Preview no Google</div>
            <div class="pe-google-preview">
              <div class="pe-google-url" id="seo-preview-url">
                <?= BASE_URL ?>/produto/<?= View::e($p['slug'] ?? 'slug-do-produto') ?>
              </div>
              <div class="pe-google-title" id="seo-preview-title">
                <?= View::e($p['meta_title'] ?? $p['nome'] ?? 'Título do produto') ?>
              </div>
              <div class="pe-google-desc" id="seo-preview-desc">
                <?= View::e($p['meta_description'] ?? $p['descricao_curta'] ?? 'Descrição que aparecerá nos resultados de busca...') ?>
              </div>
            </div>
          </div>

          <div class="pe-card" style="margin-top:16px;">
            <div class="form-group">
              <label class="pe-label">Meta title</label>
              <input type="text" name="meta_title" id="pe-meta-title"
                     class="form-control"
                     value="<?= View::e($p['meta_title'] ?? '') ?>"
                     placeholder="Deixe em branco para usar o nome do produto"
                     maxlength="160">
              <div class="pe-seo-bar-wrap">
                <div class="pe-seo-bar" id="seo-bar-title"></div>
                <span class="pe-seo-count" id="seo-count-title">0 / 90</span>
              </div>
            </div>

            <div class="form-group">
              <label class="pe-label">Meta description</label>
              <textarea name="meta_description" id="pe-meta-desc"
                        class="form-control" rows="3"
                        placeholder="Deixe em branco para usar a descrição curta"
                        maxlength="320"><?= View::e($p['meta_description'] ?? '') ?></textarea>
              <div class="pe-seo-bar-wrap">
                <div class="pe-seo-bar" id="seo-bar-desc"></div>
                <span class="pe-seo-count" id="seo-count-desc">0 / 256</span>
              </div>
            </div>

            <div class="form-group">
              <label class="pe-label">Keywords</label>
              <input type="text" name="meta_keywords" class="form-control"
                     value="<?= View::e($p['meta_keywords'] ?? '') ?>"
                     placeholder="capacete, agv, k3, moto, proteção">
              <p class="pe-field-hint">Separe por vírgulas. Impacto menor hoje em dia.</p>
            </div>

            <!-- Dados estruturados -->
            <div class="form-group">
              <label class="pe-label">Google Category</label>
              <input type="text" name="google_category" class="form-control"
                     value="<?= View::e($p['google_category'] ?? '') ?>"
                     placeholder="Ex: Veículos & Peças > Capacetes">
            </div>
          </div>
        </section>
        

        <!-- ════════════════════════════════════════════════
             SEÇÃO: CONFIGURAÇÕES
             ════════════════════════════════════════════════ -->
        <section class="pe-section" id="pe-config">
          <div class="pe-section-head">
            <h2>Configurações</h2>
            <p>Visibilidade, destaques e flags do produto</p>
          </div>

          <div class="pe-card">
            <div class="pe-card-title">Visibilidade</div>

            <label class="pe-toggle-label">
              <div class="pe-toggle-switch">
                <input type="checkbox" id="pe-ativo-config" value="1"
                       <?= ($p['ativo'] ?? 1) ? 'checked' : '' ?>>
                <span class="pe-toggle-track">
                  <span class="pe-toggle-thumb-inner"></span>
                </span>
              </div>
              <div>
                <span class="pe-toggle-title">Produto ativo</span>
                <span class="pe-toggle-desc">
                  Produto visível e disponível para compra no site
                </span>
              </div>
            </label>

            <div class="pe-divider"></div>

            <label class="pe-toggle-label">
              <div class="pe-toggle-switch">
                <input type="checkbox" name="destaque" value="1"
                       <?= !empty($p['destaque']) ? 'checked' : '' ?>>
                <span class="pe-toggle-track">
                  <span class="pe-toggle-thumb-inner"></span>
                </span>
              </div>
              <div>
                <span class="pe-toggle-title">Produto em destaque</span>
                <span class="pe-toggle-desc">
                  Aparece nas seções de destaque da home e categoria
                </span>
              </div>
            </label>

            <div class="pe-divider"></div>

            <label class="pe-toggle-label">
              <div class="pe-toggle-switch">
                <input type="checkbox" name="lancamento" value="1"
                       <?= !empty($p['lancamento']) ? 'checked' : '' ?>>
                <span class="pe-toggle-track">
                  <span class="pe-toggle-thumb-inner"></span>
                </span>
              </div>
              <div>
                <span class="pe-toggle-title">Lançamento</span>
                <span class="pe-toggle-desc">
                  Exibe badge "Lançamento" no card do produto
                </span>
              </div>
            </label>
          </div>

          <!-- Datas de auditoria (readonly) -->
          <?php if ($isEdit): ?>
          <div class="pe-card pe-audit-card" style="margin-top:16px;">
            <div class="pe-card-title">Auditoria</div>
            <div class="pe-audit-grid">
              <div>
                <span class="pe-audit-label">Criado em</span>
                <span class="pe-audit-val">
                  <?= date('d/m/Y H:i', strtotime($p['criado_em'])) ?>
                </span>
              </div>
              <div>
                <span class="pe-audit-label">Atualizado em</span>
                <span class="pe-audit-val">
                  <?= date('d/m/Y H:i', strtotime($p['atualizado_em'])) ?>
                </span>
              </div>
              <div>
                <span class="pe-audit-label">Visualizações</span>
                <span class="pe-audit-val">
                  <?= number_format((int)($p['visualizacoes'] ?? 0)) ?>
                </span>
              </div>
              <div>
                <span class="pe-audit-label">Vendidos</span>
                <span class="pe-audit-val">
                  <?= number_format((int)($p['vendidos'] ?? 0)) ?>
                </span>
              </div>
            </div>
          </div>

          <!-- Zona de perigo -->
          <div class="pe-card pe-danger-zone" style="margin-top:16px;">
            <div class="pe-card-title pe-card-title--danger">Zona de risco</div>
            <p class="pe-danger-text">
              Ações irreversíveis. Tenha certeza antes de prosseguir.
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <button type="button" class="btn btn-outline btn-sm"
                      id="btn-duplicar">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <rect x="9" y="9" width="13" height="13" rx="2"/>
                  <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                </svg>
                Duplicar produto
              </button>
              <button type="button" class="btn btn-outline btn-sm btn-danger"
                      id="btn-excluir-produto"
                      data-id="<?= (int)$p['id'] ?>"
                      data-nome="<?= View::e($p['nome']) ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <polyline points="3 6 5 6 21 6"/>
                  <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
                  <path d="M10 11v6M14 11v6"/>
                </svg>
                Excluir produto
              </button>
            </div>
          </div>
          <?php endif; ?>
        </section>

      </div><!-- /.pe-sections -->

        
    </form>
  </div><!-- /.pe-main -->

  <!-- ── Sidebar de navegação ─────────────────────────── -->
  <nav class="pe-nav" id="peNav">
    <div class="pe-nav-logo">
      <a href="<?= BASE_URL ?>/admin/produtos" class="pe-nav-back">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
        Produtos 
      </a>
    </div>

    <ul class="pe-nav-list">
      <?php
      $sections = [
        'geral'      => ['icon' => 'info',     'label' => 'Informações gerais'],
        'categorias' => ['icon' => 'categorias',   'label' => 'Categorias'],
        'midia'      => ['icon' => 'midia',    'label' => 'Mídia'],
        'clips'      => ['icon' => 'clips',    'label' => 'Clips'],
        'caracteristicas' => ['icon' => 'caracteristicas',     'label' => 'Características'],
        'compatibilidade' => ['icon' => 'motos',    'label' => 'Compatibilidade'],
        'preco'      => ['icon' => 'price',   'label' => 'Preço & estoque'],
        'variacao'   => ['icon' => 'stacks',   'label' => 'Variações'],
        'shipping'   => ['icon' => 'truck',    'label' => 'Frete & dimensões'],
        'seo'        => ['icon' => 'search',   'label' => 'SEO'],
        'config'     => ['icon' => 'settings', 'label' => 'Configurações'],
      ];
      $icons = [
        'info'          => IconLibrary::render('info', 'icon icon--md'),
        'categorias'    => IconLibrary::render('category', 'icon icon--md'),
        'midia'      => IconLibrary::render('gallery', 'icon icon--md'),
        'clips'      => IconLibrary::render('videocam', 'icon icon--md'),
        'caracteristicas' => IconLibrary::render('format-list-bulleted', 'icon icon--md'),
        'motos'   => IconLibrary::render('motorcycle', 'icon icon--md'),
        'price'    => IconLibrary::render('payments', 'icon icon--md'),
        'stacks'   => IconLibrary::render('stacks', 'icon icon--md'),
        'truck'    => IconLibrary::render('truck', 'icon icon--md'),
        'search'   => IconLibrary::render('search', 'icon icon--md'),
        'settings' =>  IconLibrary::render('settings', 'icon icon--md'),
      ];
      foreach ($sections as $key => $s): ?>
      <li>
        <a href="#pe-<?= $key ?>" class="pe-nav-item" data-section="<?= $key ?>">
          <span class="pe-nav-icon">
            <!-- <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round"> -->
              <?= $icons[$s['icon']] ?>
            <!-- </svg> -->
          </span>
          <?= $s['label'] ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
    <button type="button" class="btn btn-primary btn-sm btn-salvar-produto" id="btn-salvar-produto">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
        <polyline points="17 21 17 13 7 13 7 21"/>
        <polyline points="7 3 7 8 15 8"/>
      </svg>
      <?= $isEdit ? 'Salvar alterações' : 'Publicar produto' ?>
    </button>
    <!-- Status quick -->
    <div class="pe-nav-status">
      <span class="pe-nav-status-label">Status</span>
      <div class="pe-status-toggle">
        <label class="pe-toggle-pill">
          <input type="checkbox" id="quick-ativo" name="ativo" value="1"
                 <?= ($p['ativo'] ?? 1) ? 'checked' : '' ?>>
          <span class="pe-toggle-thumb"></span>
        </label>
        <span id="quick-ativo-label"><?= ($p['ativo'] ?? 1) ? 'Ativo' : 'Inativo' ?></span>
      </div>
    </div>
  </nav>
</div><!-- /.pe-layout -->

<!-- Modal de atributo agrupador -->
<div class="pe-modal-backdrop" id="modal-agrupador" style="display:none;">
  <div class="pe-modal">
    <div class="pe-modal-header">
      <h3>Adicionar atributo agrupador</h3>
      <button type="button" class="pe-modal-close" id="btn-close-modal-agrupador">×</button>
    </div>
    <div class="pe-modal-body">
      <div class="form-group">
        <label class="pe-label">Tipo de atributo</label>
        <select class="form-control" id="modal-tipo-atributo">
          <option value="">— Selecione —</option>
          <?php foreach ($atributos_tipos as $at):
            if ($at['papel'] !== 'agrupador') continue;
          ?>
          <option value="<?= $at['id'] ?>"
                  data-display="<?= View::e($at['tipo_display']) ?>"
                  data-nome="<?= View::e($at['nome']) ?>">
            <?= View::e($at['nome']) ?> (<?= View::e($at['tipo_display']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" id="modal-valor-group">
        <label class="pe-label">Valor</label>
        <input type="text" class="form-control" id="modal-valor-input"
               placeholder="Ex: Vermelho">
      </div>
      <div class="form-group" id="modal-cor-group" style="display:none;">
        <label class="pe-label">Cor (hex)</label>
        <div style="display:flex;gap:10px;align-items:center;">
          <input type="color" id="modal-cor-picker" value="#ff0000"
                 class="pe-color-swatch-input-lg">
          <input type="text"  id="modal-cor-hex" class="form-control"
                 value="#FF0000" maxlength="7" style="font-family:var(--font-mono);">
        </div>
      </div>
    </div>
    <div class="pe-modal-footer">
      <button type="button" class="btn btn-ghost" id="btn-cancelar-agrupador">
        Cancelar
      </button>
      <button type="button" class="btn btn-primary" id="btn-confirmar-agrupador">
        Adicionar
      </button>
    </div>
  </div>
</div>

<!-- Modal: buscar família existente -->
<div class="pe-modal-backdrop" id="modal-buscar-familia" style="display:none;">
  <div class="pe-modal">
    <div class="pe-modal-header">
      <h3>Vincular a família existente</h3>
      <button type="button" class="pe-modal-close"
              id="btn-close-modal-buscar-familia">×</button>
    </div>
    <div class="pe-modal-body">

      <div class="pe-familia-search-wrap">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <circle cx="11" cy="11" r="8"/>
          <line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" id="familia-search-input"
               class="pe-familia-search-input"
               placeholder="Buscar família pelo nome...">
      </div>

      <div id="familia-search-results" class="pe-familia-results">
        <p class="pe-familia-results-hint">
          Digite para buscar famílias cadastradas.
        </p>
      </div>

    </div>
    <div class="pe-modal-footer">
      <button type="button" class="btn btn-ghost"
              id="btn-cancelar-buscar-familia">Cancelar</button>
    </div>
  </div>
</div>

<!-- Modal: criar nova família -->
<div class="pe-modal-backdrop" id="modal-criar-familia" style="display:none;">
  <div class="pe-modal">
    <div class="pe-modal-header">
      <h3>Criar nova família</h3>
      <button type="button" class="pe-modal-close"
              id="btn-close-modal-criar-familia">×</button>
    </div>
    <div class="pe-modal-body">
      <form id="form-criar-familia">
        <?= SecurityHelper::csrfField() ?>
        <div class="form-group">
          <label class="pe-label">
            Nome da família <span class="pe-required">*</span>
          </label>
          <input type="text" name="nome" id="nova-familia-nome"
                 class="form-control"
                 placeholder="Ex: Capacete AGV K3 SV"
                 required>
          <p class="pe-field-hint">
            Use o nome base do produto sem a variação.
            Ex: "Tênis Air Max" em vez de "Tênis Air Max Vermelho".
          </p>
        </div>
      </form>
    </div>
    <div class="pe-modal-footer">
      <button type="button" class="btn btn-ghost"
              id="btn-cancelar-criar-familia">Cancelar</button>
      <button type="button" class="btn btn-primary"
              id="btn-salvar-nova-familia">Criar e vincular</button>
    </div>
  </div>
</div>

<?php
// Separa os dois papéis
$tiposVariacao  = array_values(array_filter($atributos_tipos, fn($t) => $t['papel'] === 'variacao'));
$tiposAgrupador = array_values(array_filter($atributos_tipos, fn($t) => $t['papel'] === 'agrupador'));

$db = Database::getInstance()->getConnection();

// Valores para tipos de VARIACAO (usados nos SKUs)
$valoresVariacao = [];
foreach ($tiposVariacao as $tipo) {
    $stmt = $db->prepare(
        "SELECT id, valor, valor_hex FROM atributo_valores
         WHERE atributo_tipo_id = ? ORDER BY ordem ASC, valor ASC"
    );
    $stmt->execute([$tipo['id']]);
    $valoresVariacao[$tipo['id']] = $stmt->fetchAll();
}

// Valores para tipos de AGRUPADOR (usados nos agrupadores)
$valoresAgrupador = [];
foreach ($tiposAgrupador as $tipo) {
    $stmt = $db->prepare(
        "SELECT id, valor, valor_hex FROM atributo_valores
         WHERE atributo_tipo_id = ? ORDER BY ordem ASC, valor ASC"
    );
    $stmt->execute([$tipo['id']]);
    $valoresAgrupador[$tipo['id']] = $stmt->fetchAll();
}
?>
<script>
window.ATRIBUTOS_VARIACAO  = <?= json_encode($tiposVariacao,   JSON_UNESCAPED_UNICODE) ?>;
window.ATRIBUTOS_AGRUPADOR = <?= json_encode($tiposAgrupador,  JSON_UNESCAPED_UNICODE) ?>;
window.ATRIBUTOS_VALORES   = <?= json_encode($valoresVariacao, JSON_UNESCAPED_UNICODE) ?>;
window.ATRIBUTOS_VALORES_AG= <?= json_encode($valoresAgrupador,JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
// Inicializa gerador de SEO na seção #pe-seo
document.addEventListener('DOMContentLoaded', function () {
    if (typeof adminSeoIA !== 'undefined') {
        adminSeoIA({
            tipo: 'produto',

            getContexto: () => ({
                nome      : document.getElementById('pe-nome')?.value || '',
                descricao : document.getElementById('pe-descricao')?.value || '',
                categoria : document.querySelector('#pe-categoria option:checked')?.text || '',
                marca     : document.querySelector('#pe-marca option:checked')?.text || '',
                preco     : document.getElementById('pe-preco')?.value || '',
            }),

            campos: {
                meta_title      : '#pe-meta-title',
                meta_description: '#pe-meta-desc',
                keywords        : '[name="meta_keywords"]',
                google_category : '[name="google_category"]',
            },

            container: '#pe-seo',
        });
    }
});

/*

// admin/views/categorias/form.php
adminSeoIA({
    tipo: 'categoria',
    getContexto: () => ({
        nome     : $('#cat-nome').val(),
        descricao: $('#cat-descricao').val(),
        parent   : $('#cat-parent option:checked').text(),
    }),
    campos: {
        meta_title      : '#cat-meta-title',
        meta_description: '#cat-meta-desc',
        keywords        : '[name="meta_keywords"]',
        google_category : '[name="google_category"]',
    },
    container: '#section-seo',
});

// admin/views/marcas/form.php
adminSeoIA({
    tipo: 'marca',
    getContexto: () => ({
        nome     : $('#marca-nome').val(),
        descricao: $('#marca-descricao').val(),
    }),
    campos: {
        meta_title      : '#marca-meta-title',
        meta_description: '#marca-meta-desc',
        keywords        : '[name="meta_keywords"]',
    },
    container: '#section-seo',
});

// admin/views/paginas/form.php
adminSeoIA({
    tipo: 'pagina',
    getContexto: () => ({
        titulo  : $('#pag-titulo').val(),
        conteudo: $('#pag-conteudo').val().substring(0, 500),
    }),
    campos: {
        meta_title      : '#pag-meta-title',
        meta_description: '#pag-meta-desc',
        keywords        : '[name="meta_keywords"]',
    },
    container: '#section-seo',
});

*/

(function () {
  var wrap = document.querySelector('.pe-rte');
  if (!wrap) return;
 
  var ta   = document.getElementById(wrap.dataset.target); // #pe-descricao
  var area = wrap.querySelector('.pe-rte-area');
  if (!ta || !area) return;
 
  // Inicializa o editor com o conteúdo já salvo no textarea
  area.innerHTML = ta.value || '';
 
  // Sincroniza editor → textarea (é o textarea que o form envia)
  function sync() { ta.value = area.innerHTML; }
  area.addEventListener('input', sync);
  area.addEventListener('blur', sync);
  // Garante sync no submit, mesmo sem blur
  if (ta.form) ta.form.addEventListener('submit', sync);
 
  // Toolbar
  wrap.querySelectorAll('.pe-rte-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var cmd = btn.dataset.cmd;
      area.focus();
 
      if (cmd === 'createLink') {
        var url = prompt('URL do link (https://…):', 'https://');
        if (url) {
          // Só permite http/https/mailto — bloqueia javascript:
          if (/^(https?:|mailto:)/i.test(url)) {
            document.execCommand('createLink', false, url);
          } else {
            alert('Link inválido. Use http://, https:// ou mailto:');
          }
        }
      } else if (cmd === 'formatBlock') {
        document.execCommand('formatBlock', false, btn.dataset.val);
      } else {
        document.execCommand(cmd, false, null);
      }
      sync();
      atualizarEstado();
    });
  });
 
  // Cola como TEXTO estruturado limpo (evita HTML sujo do clipboard).
  // Lembrete: isto é cosmético — a defesa real é o HtmlHelper no servidor.
  area.addEventListener('paste', function (e) {
    e.preventDefault();
    var texto = (e.clipboardData || window.clipboardData).getData('text/plain');
    document.execCommand('insertText', false, texto);
    sync();
  });
 
  // Realça os botões ativos conforme a seleção
  function atualizarEstado() {
    [['bold','bold'],['italic','italic'],['underline','underline']].forEach(function (p) {
      var b = wrap.querySelector('.pe-rte-btn[data-cmd="' + p[0] + '"]');
      if (b) {
        try { b.classList.toggle('is-active', document.queryCommandState(p[1])); }
        catch (err) {}
      }
    });
  }
  area.addEventListener('keyup', atualizarEstado);
  area.addEventListener('mouseup', atualizarEstado);

  $('#btn-sync-skus-bling').on('click', function () {
    var $btn = $(this), id = $btn.data('produto-id');
    if (!id) { adminToast('Salve o produto antes de sincronizar.', 'warning'); return; }
    CK.btnLoading($btn);
    $.post(BASE_URL + '/admin/produtos/' + id + '/sync-bling', { _token: CSRF_TOKEN })
      .done(function (r) {
        CK.btnLoading($btn, false);
        adminToast(r.msg, r.ok ? 'success' : 'error');
        if (r.ok) setTimeout(function () { location.reload(); }, 1500);
      })
      .fail(function () { CK.btnLoading($btn, false); adminToast('Erro de rede.', 'error'); });
  });
})();
</script>

<script>
  /* Clips disponiveis para vincular. Vao inteiros para o navegador porque sao
     poucos (o seletor busca em memoria, igual ao formulario de clips faz com
     os produtos) e porque assim nao ha um endpoint de busca a proteger. */
  window.PE_CLIPS = <?= json_encode(array_map(
      fn($c) => ['id' => (int)$c['id'], 'titulo' => (string)$c['titulo'], 'ativo' => (int)$c['ativo']],
      $clips ?? []
  ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
