<?php
$b      = $banner ?? null;
$isEdit = !empty($b);
$tipoMidia = $b['tipo_midia'] ?? 'imagem';
?>

<div class="admin-page banner-editor">

  <div class="admin-page-header">
    <div>
      <a href="<?= BASE_URL ?>/admin/banners" class="admin-back-link">← Banners</a>
      <h1><?= $isEdit ? 'Editar banner' : 'Novo banner' ?></h1>
    </div>
    <div style="display:flex;gap:8px;">
      <a href="<?= BASE_URL ?>/admin/banners" class="btn btn-ghost">Cancelar</a>
      <button type="button" class="btn btn-primary" id="btn-salvar-banner">
        <?= $isEdit ? 'Salvar alterações' : 'Criar banner' ?>
      </button>
    </div>
  </div>
   

  <form id="form-banner">
    <input type="hidden" name="_csrf_token" value="<?= SecurityHelper::generateCsrf() ?>">
    <input type="hidden" name="id" value="<?= (int)($b['id'] ?? 0) ?>">

    <div class="banner-editor-grid">

      <!-- ════ COLUNA PRINCIPAL ════ -->
      <div class="banner-editor-main">

        <!-- ── Mídia ───────────────────────────────────── -->
        <section class="pe-section">
          <div class="pe-section-head">
            <h2>Mídia do banner</h2>
            <p>Imagem, vídeo ou ambos (vídeo com fallback de imagem)</p>
          </div>

          <div class="pe-card">

            <!-- Tipo de mídia -->
            <div class="form-group">
              <label class="pe-label">Tipo de mídia</label>
              <div class="banner-tipo-tabs">
                <label class="banner-tipo-tab <?= $tipoMidia === 'imagem' ? 'is-active' : '' ?>">
                  <input type="radio" name="tipo_midia" value="imagem"
                         <?= $tipoMidia === 'imagem' ? 'checked' : '' ?>>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                  </svg>
                  Apenas imagem
                </label>
                <label class="banner-tipo-tab <?= $tipoMidia === 'video' ? 'is-active' : '' ?>">
                  <input type="radio" name="tipo_midia" value="video"
                         <?= $tipoMidia === 'video' ? 'checked' : '' ?>>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <polygon points="23 7 16 12 23 17 23 7"/>
                    <rect x="1" y="5" width="15" height="14" rx="2"/>
                  </svg>
                  Apenas vídeo
                </label>
                <label class="banner-tipo-tab <?= $tipoMidia === 'video_com_imagem' ? 'is-active' : '' ?>">
                  <input type="radio" name="tipo_midia" value="video_com_imagem"
                         <?= $tipoMidia === 'video_com_imagem' ? 'checked' : '' ?>>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <polygon points="23 7 16 12 23 17 23 7"/>
                    <rect x="1" y="5" width="15" height="14" rx="2"/>
                    <circle cx="6" cy="12" r="1.5" fill="currentColor"/>
                  </svg>
                  Vídeo + imagem fallback
                </label>
              </div>
            </div>

            <!-- Tabs Desktop / Mobile -->
            <div class="banner-device-tabs">
              <button type="button" class="banner-device-tab is-active"
                      data-tab="desktop">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <rect x="2" y="3" width="20" height="14" rx="2"/>
                  <line x1="8" y1="21" x2="16" y2="21"/>
                  <line x1="12" y1="17" x2="12" y2="21"/>
                </svg>
                Desktop
              </button>
              <button type="button" class="banner-device-tab" data-tab="mobile">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <rect x="5" y="2" width="14" height="20" rx="2"/>
                  <line x1="12" y1="18" x2="12.01" y2="18"/>
                </svg>
                Mobile (opcional)
              </button>
            </div>

            <!-- Painel Desktop -->
            <div class="banner-device-panel" data-panel="desktop">

              <!-- Upload imagem desktop -->
              <div class="form-group banner-upload-group" data-tipo="imagem">
                <label class="pe-label">Imagem desktop</label>
                <div class="banner-upload-area" data-input="imagem">
                  <?php if (!empty($b['arquivo_imagem'])): ?>
                  <div class="banner-upload-preview">
                    <img src="<?= View::uploadR2($b['arquivo_imagem']) ?>"
                         alt="" id="preview-imagem">
                    <button type="button" class="banner-upload-remove" data-target="imagem">
                      Trocar imagem
                    </button>
                  </div>
                  <?php else: ?>
                  <div class="banner-upload-empty">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                      <rect x="3" y="3" width="18" height="18" rx="2"/>
                      <circle cx="8.5" cy="8.5" r="1.5"/>
                      <polyline points="21 15 16 10 5 21"/>
                    </svg>
                    <span>Clique ou arraste a imagem</span>
                    <small>JPG, PNG, WEBP — Máx. 5MB</small>
                  </div>
                  <?php endif; ?>
                  <input type="file" name="imagem" accept="image/*"
                         class="banner-upload-input" data-video-slot="video">
                </div>
              </div>

              <!-- Upload vídeo desktop -->
              <div class="form-group banner-upload-group" data-tipo="video">
                <label class="pe-label">Vídeo desktop</label>
                <div class="banner-upload-area" data-input="video">
                  <?php if (!empty($b['arquivo_video'])): ?>
                  <div class="banner-upload-preview">
                    <?php if (preg_match('/^[a-f0-9]{32}$/i', $b['arquivo_video'])): ?>
                      <iframe src="https://iframe.cloudflarestream.com/<?= $b['arquivo_video'] ?>"
                              style="border:none;width:100%;aspect-ratio:16/9;" allow="autoplay; fullscreen" class="video-in"></iframe>
                    <?php else: ?>
                      <video src="<?= View::uploadR2($b['arquivo_video']) ?>" controls muted></video>
                    <?php endif; ?>
                    <button type="button" class="banner-upload-remove">Trocar vídeo</button>
                  </div>
                  <?php else: ?>
                  <div class="banner-upload-empty">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                      <polygon points="23 7 16 12 23 17 23 7"/>
                      <rect x="1" y="5" width="15" height="14" rx="2"/>
                    </svg>
                    <span>Clique ou arraste o vídeo</span>
                    <small>MP4, WEBM, MOV — Máx. 50MB</small>
                  </div>
                  <?php endif; ?>
                  <input type="file" name="video" accept="video/*"
                         class="banner-upload-input" data-video-slot="video">
                </div>

                <!-- URL externa de vídeo -->
                <div style="margin-top:10px;">
                  <input type="url" name="video_url_externo"
                         class="form-control form-control--sm"
                         value="<?= View::e($b['video_url_externo'] ?? '') ?>"
                         placeholder="ou cole URL do YouTube/Vimeo">
                </div>

                <!-- Opções de vídeo -->
                <div class="banner-video-opts">
                  <label class="banner-check">
                    <input type="checkbox" name="video_autoplay" value="1"
                           <?= !isset($b) || $b['video_autoplay'] ? 'checked' : '' ?>>
                    Autoplay
                  </label>
                  <label class="banner-check">
                    <input type="checkbox" name="video_loop" value="1"
                           <?= !isset($b) || $b['video_loop'] ? 'checked' : '' ?>>
                    Loop
                  </label>
                  <label class="banner-check">
                    <input type="checkbox" name="video_mute" value="1"
                           <?= !isset($b) || $b['video_mute'] ? 'checked' : '' ?>>
                    Mudo
                  </label>
                </div>
              </div>

            </div>

            <!-- Painel Mobile -->
            <div class="banner-device-panel" data-panel="mobile" hidden>
              <p class="pe-field-hint" style="margin-bottom:14px;">
                Versões mobile substituem a desktop em telas até 768px.
                Deixe vazio para usar as mídias desktop.
              </p>

              <div class="form-group banner-upload-group" data-tipo="imagem">
                <label class="pe-label">Imagem mobile</label>
                <div class="banner-upload-area">
                  <?php if (!empty($b['arquivo_imagem_mobile'])): ?>
                  <div class="banner-upload-preview">
                    <img src="<?= View::uploadR2('banners/' . $b['arquivo_imagem_mobile']) ?>" alt="">
                    <button type="button" class="banner-upload-remove">Trocar</button>
                  </div>
                  <?php else: ?>
                  <div class="banner-upload-empty">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                      <rect x="3" y="3" width="18" height="18" rx="2"/>
                      <circle cx="8.5" cy="8.5" r="1.5"/>
                      <polyline points="21 15 16 10 5 21"/>
                    </svg>
                    <span>Imagem mobile (opcional)</span>
                  </div>
                  <?php endif; ?>
                  <input type="file" name="imagem_mobile" accept="image/*"
                         class="banner-upload-input">
                </div>
              </div>

              <div class="form-group banner-upload-group" data-tipo="video">
                <label class="pe-label">Vídeo mobile</label>
                <div class="banner-upload-area">
                  <?php if (!empty($b['arquivo_video_mobile'])): ?>
                  <div class="banner-upload-preview">
                    <?php if (preg_match('/^[a-f0-9]{32}$/i', $b['arquivo_video'])): ?>
                      <iframe src="https://iframe.cloudflarestream.com/<?= $b['arquivo_video'] ?>"
                              style="border:none;width:100%;aspect-ratio:16/9;" allow="autoplay; fullscreen"></iframe>
                    <?php else: ?>
                      <video src="<?= View::uploadR2('banners/' . $b['arquivo_video']) ?>" controls muted></video>
                    <?php endif; ?>
                    <button type="button" class="banner-upload-remove">Trocar vídeo</button>
                  </div>
                  <?php else: ?>
                  <div class="banner-upload-empty">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                      <polygon points="23 7 16 12 23 17 23 7"/>
                      <rect x="1" y="5" width="15" height="14" rx="2"/>
                    </svg>
                    <span>Vídeo mobile (opcional)</span>
                  </div>
                  <?php endif; ?>
                  <input type="file" name="video_mobile" accept="video/*"
                         class="banner-upload-input">
                </div>
              </div>
            </div>

          </div>
        </section>

        <?= View::partial('banners/_form-extras', ['banner' => $banner]); ?>

        <!-- ── Conteúdo (texto + CTAs) ─────────────────── -->
        <section class="pe-section">
          <div class="pe-section-head">
            <h2>Conteúdo</h2>
            <p>Texto sobreposto à mídia e botões de ação</p>
          </div>

          <div class="pe-card">

            

            <div class="form-group">
              <label class="pe-label">Subtítulo - Conjunto com o titulo de badge</label>
              <textarea name="subtitulo_overlay" class="form-control" rows="2"
                        placeholder="Texto descritivo opcional"><?= View::e($b['subtitulo_overlay'] ?? '') ?></textarea>
            </div>

            <!-- Posição do texto -->
            <div class="form-group">
              <label class="pe-label">Posição do texto</label>
              <div class="banner-pos-grid">
                <?php
                $posicoes = [
                    'top-left'      => '↖',  'top-center'    => '↑',  'top-right'    => '↗',
                    'left'          => '←',  'center'        => '●',  'right'        => '→',
                    'bottom-left'   => '↙',  'bottom-center' => '↓',  'bottom-right' => '↘',
                ];
                foreach ($posicoes as $val => $arrow):
                ?>
                <label class="banner-pos-cell <?= ($b['posicao_texto'] ?? 'center') === $val ? 'is-active' : '' ?>">
                  <input type="radio" name="posicao_texto" value="<?= $val ?>"
                         <?= ($b['posicao_texto'] ?? 'center') === $val ? 'checked' : '' ?>>
                  <span><?= $arrow ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="pe-grid-2">
              <div class="form-group">
                <label class="pe-label">Cor do texto</label>
                <div class="banner-color-input">
                  <input type="color" name="cor_texto"
                         value="<?= View::e($b['cor_texto'] ?? 'var(--surface)') ?>">
                  <input type="text" class="form-control form-control--sm"
                         value="<?= View::e($b['cor_texto'] ?? 'var(--surface)') ?>"
                         readonly>
                </div>
              </div>
              <div class="form-group">
                <label class="pe-label">Cor do overlay (filtro)</label>
                <div class="banner-color-input">
                  <input type="color" name="cor_overlay"
                         value="<?= View::e($b['cor_overlay'] ?? '#000000') ?>">
                  <input type="text" class="form-control form-control--sm"
                         value="<?= View::e($b['cor_overlay'] ?? '#000000') ?>"
                         readonly>
                </div>
                <input type="range" name="overlay_opacidade"
                       min="0" max="100"
                       value="<?= (int)($b['overlay_opacidade'] ?? 0) ?>"
                       class="banner-range">
                <span class="banner-range-val">
                  Opacidade: <span><?= (int)($b['overlay_opacidade'] ?? 0) ?>%</span>
                </span>
              </div>
            </div>

            <!-- CTAs -->
            <!-- <div class="banner-ctas">
              <h4 class="banner-cta-section-title">Botões de ação</h4>

              <div class="banner-cta-row">
                <div class="banner-cta-num">1</div>
                <div class="banner-cta-fields">
                  <input type="text" name="cta1_texto" class="form-control form-control--sm"
                         value="<?= View::e($b['cta1_texto'] ?? '') ?>"
                         placeholder="Texto do botão (ex: Ver ofertas)">
                  <input type="text" name="cta1_link" class="form-control form-control--sm"
                         value="<?= View::e($b['cta1_link'] ?? '') ?>"
                         placeholder="URL ou /caminho">
                  <select name="cta1_estilo" class="form-control form-control--sm">
                    <option value="primary"   <?= ($b['cta1_estilo'] ?? 'primary') === 'primary'   ? 'selected' : '' ?>>Primário</option>
                    <option value="secondary" <?= ($b['cta1_estilo'] ?? '') === 'secondary' ? 'selected' : '' ?>>Secundário</option>
                    <option value="outline"   <?= ($b['cta1_estilo'] ?? '') === 'outline'   ? 'selected' : '' ?>>Outline</option>
                    <option value="ghost"     <?= ($b['cta1_estilo'] ?? '') === 'ghost'     ? 'selected' : '' ?>>Ghost</option>
                  </select>
                  <label class="banner-check">
                    <input type="checkbox" name="cta1_target" value="_blank"
                           <?= ($b['cta1_target'] ?? '_self') === '_blank' ? 'checked' : '' ?>>
                    Nova aba
                  </label>
                </div>
              </div>

              <div class="banner-cta-row">
                <div class="banner-cta-num">2</div>
                <div class="banner-cta-fields">
                  <input type="text" name="cta2_texto" class="form-control form-control--sm"
                         value="<?= View::e($b['cta2_texto'] ?? '') ?>"
                         placeholder="Texto (opcional)">
                  <input type="text" name="cta2_link" class="form-control form-control--sm"
                         value="<?= View::e($b['cta2_link'] ?? '') ?>"
                         placeholder="URL ou /caminho">
                  <select name="cta2_estilo" class="form-control form-control--sm">
                    <option value="outline"   <?= ($b['cta2_estilo'] ?? 'outline') === 'outline'   ? 'selected' : '' ?>>Outline</option>
                    <option value="primary"   <?= ($b['cta2_estilo'] ?? '') === 'primary'   ? 'selected' : '' ?>>Primário</option>
                    <option value="secondary" <?= ($b['cta2_estilo'] ?? '') === 'secondary' ? 'selected' : '' ?>>Secundário</option>
                    <option value="ghost"     <?= ($b['cta2_estilo'] ?? '') === 'ghost'     ? 'selected' : '' ?>>Ghost</option>
                  </select>
                  <label class="banner-check">
                    <input type="checkbox" name="cta2_target" value="_blank"
                           <?= ($b['cta2_target'] ?? '_self') === '_blank' ? 'checked' : '' ?>>
                    Nova aba
                  </label>
                </div>
              </div>
            </div> -->

            
            <hr style="border:none;border-top:1px solid var(--border);margin:18px 0;">

            <!-- Link geral -->
            <div class="form-group">
              <label class="pe-label">Link do banner inteiro (opcional)</label>
              <input type="text" name="link_geral" class="form-control"
                     value="<?= View::e($b['link_geral'] ?? '') ?>"
                     placeholder="Quando clicar em qualquer parte do banner">
              <label class="banner-check" style="margin-top:8px;">
                <input type="checkbox" name="link_target" value="_blank"
                       <?= ($b['link_target'] ?? '_self') === '_blank' ? 'checked' : '' ?>>
                Abrir em nova aba
              </label>
            </div>

            <div class="form-group">
              <label class="pe-label">Texto alternativo (alt)</label>
              <input type="text" name="alt_text" class="form-control"
                     value="<?= View::e($b['alt_text'] ?? '') ?>"
                     placeholder="Para acessibilidade e SEO">
            </div>

          </div>
        </section>

        <!-- ── Preview ao vivo ─────────────────────────── -->
        <section class="pe-section">
          <div class="pe-section-head">
            <h2>Preview</h2>
            <p>Visualização em tempo real</p>
          </div>
          <div class="pe-card" style="background:var(--surface2);padding:0;overflow:hidden;">
            <div class="banner-preview-toolbar">
              <button type="button" class="banner-preview-btn is-active" data-device="desktop">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <rect x="2" y="3" width="20" height="14" rx="2"/>
                  <line x1="8" y1="21" x2="16" y2="21"/>
                  <line x1="12" y1="17" x2="12" y2="21"/>
                </svg>
                Desktop
              </button>
              <button type="button" class="banner-preview-btn" data-device="mobile">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <rect x="5" y="2" width="14" height="20" rx="2"/>
                </svg>
                Mobile
              </button>
            </div>
            <div class="banner-preview-canvas" id="banner-preview-canvas">
              <div class="banner-preview-frame" id="banner-preview-frame">
                <!-- Conteúdo gerado via JS -->
              </div>
            </div>
          </div>
        </section>

      </div>

      <!-- ════ COLUNA LATERAL ════ -->
      <div class="banner-editor-side">

        <!-- Configurações -->
        <div class="pe-card">
          <h3 class="pe-card-title">Configurações</h3>

          <div class="form-group">
            <label class="pe-label">
              Título interno <span class="pe-required">*</span>
            </label>
            <input type="text" name="titulo" class="form-control"
                   value="<?= View::e($b['titulo'] ?? '') ?>"
                   placeholder="Para identificar no admin" required>
          </div>

          <div class="form-group">
            <label class="pe-label">Zona</label>
            <select name="zona_id" class="form-control" required>
              <?php foreach ($zonas as $z): ?>
              <option value="<?= $z['id'] ?>"
                      <?= (int)$zonaId === (int)$z['id'] ? 'selected' : '' ?>>
                <?= View::e($z['nome']) ?>
                <?php if ($z['largura_ideal']): ?>
                  (<?= $z['largura_ideal'] ?>×<?= $z['altura_ideal'] ?>)
                <?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="pe-label">Ordem de exibição</label>
            <input type="number" name="ordem" class="form-control"
                   value="<?= (int)($b['ordem'] ?? 0) ?>" min="0">
          </div>

          <label class="pe-toggle-label">
            <div class="pe-toggle-switch">
              <input type="checkbox" name="ativo" value="1"
                     <?= !isset($b) || $b['ativo'] ? 'checked' : '' ?>>
              <span class="pe-toggle-track">
                <span class="pe-toggle-thumb-inner"></span>
              </span>
            </div>
            <div>
              <span class="pe-toggle-title">Banner ativo</span>
              <span class="pe-toggle-desc">Visível no site</span>
            </div>
          </label>
        </div>

        <!-- Agendamento -->
        <!-- <div class="pe-card" style="margin-top:14px;">
          <h3 class="pe-card-title">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 style="vertical-align:middle;margin-right:5px;">
              <rect x="3" y="4" width="18" height="18" rx="2"/>
              <line x1="16" y1="2" x2="16" y2="6"/>
              <line x1="8"  y1="2" x2="8"  y2="6"/>
              <line x1="3"  y1="10" x2="21" y2="10"/>
            </svg>
            Agendamento
          </h3>
          <p class="pe-field-hint" style="margin-bottom:12px;">
            Defina quando o banner deve aparecer (opcional).
          </p>
          <div class="form-group">
            <label class="pe-label">Início</label>
            <input type="datetime-local" name="data_inicio" class="form-control"
                   value="<?= !empty($b['data_inicio']) ? date('Y-m-d\TH:i', strtotime($b['data_inicio'])) : '' ?>">
          </div>
          <div class="form-group">
            <label class="pe-label">Fim</label>
            <input type="datetime-local" name="data_fim" class="form-control"
                   value="<?= !empty($b['data_fim']) ? date('Y-m-d\TH:i', strtotime($b['data_fim'])) : '' ?>">
          </div>
        </div> -->

        <!-- Estatísticas -->
        <?php if ($isEdit): ?>
        <div class="pe-card" style="margin-top:14px;">
          <h3 class="pe-card-title">Estatísticas</h3>
          <div class="banner-stats">
            <div class="banner-stat">
              <strong><?= number_format($b['impressoes']) ?></strong>
              <span>Impressões</span>
            </div>
            <div class="banner-stat">
              <strong><?= number_format($b['cliques']) ?></strong>
              <span>Cliques</span>
            </div>
            <?php if ($b['impressoes'] > 0): ?>
            <div class="banner-stat banner-stat--ctr">
              <strong><?= round(($b['cliques'] / $b['impressoes']) * 100, 1) ?>%</strong>
              <span>CTR</span>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

      </div>

    </div>
    
  </form>
</div>