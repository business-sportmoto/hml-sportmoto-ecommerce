<?php
// ════════════════════════════════════════════════════════
// admin/views/clips/form.php
// ════════════════════════════════════════════════════════
$isEdit  = !empty($clip);
$titulo  = $isEdit ? 'Editar clip' : 'Novo clip';
$id      = $isEdit ? (int)$clip['id'] : 0;

$produtosVinculados = $isEdit ? (new Clip())->getProdutosDoClip($id) : [];
$svc = new ClipService();
?>

<div class="admin-page">

  <!-- Header -->
  <div class="admin-page-header">
    <div>
      <a href="<?= BASE_URL ?>/admin/clips" class="admin-back-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="19" y1="12" x2="5" y2="12"/>
          <polyline points="12 19 5 12 12 5"/>
        </svg>
        Voltar para Clips
      </a>
      <h1><?= $titulo ?></h1>
      <?php if ($isEdit): ?>
      <p>
        ID #<?= $id ?>
        · <?= number_format($clip['total_views']) ?> views
        · <?= number_format($clip['total_likes']) ?> likes
        · <?= number_format($clip['total_comentarios']) ?> comentários
      </p>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;">
      <?php if ($isEdit): ?>
      <button type="button" class="btn btn-ghost" id="btn-excluir-clip"
              data-id="<?= $id ?>" data-titulo="<?= View::e($clip['titulo']) ?>">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="3 6 5 6 21 6"/>
          <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
        </svg>
        Excluir
      </button>
      <?php endif; ?>
      <button type="button" class="btn btn-primary" id="btn-salvar-clip">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
        Salvar clip
      </button>
    </div>
  </div>

  <!-- Form -->
  <form id="form-clip" enctype="multipart/form-data">
    <input type="hidden" name="_csrf_token" value="<?= SecurityHelper::generateCsrf() ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="arquivo_video" id="clip-video-uid"
       value="<?= $isEdit ? View::e($clip['arquivo_video'] ?? '') : '' ?>">

    <div class="clip-form-grid">

      <!-- ═══ COLUNA ESQUERDA: Mídia ═══ -->
      <div class="clip-form-side">

        <div class="admin-card">
          <div class="admin-card-header">
            <h3>Vídeo</h3>
            <?php if ($isEdit && $clip['arquivo_video']): ?>
            <span class="admin-badge admin-badge--success">Carregado</span>
            <?php endif; ?>
          </div>
          <div class="admin-card-body">

            <?php if ($isEdit && !empty($clip['arquivo_video'])): ?>
            <?php
              
              $uid = $clip['arquivo_video'];
              $isUid = preg_match('/^[a-f0-9]{32}$/i', $uid);
            ?>
            <div class="clip-video-preview">
              <?php if ($isUid): ?>
                <!-- Vídeo no Stream: player iframe (no ADMIN o iframe é suficiente) -->
                <iframe src="https://iframe.cloudflarestream.com/<?= View::e($uid) ?>"
                        style="border:none;width:100%;aspect-ratio:9/16;max-height:420px;"
                        allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;"
                        allowfullscreen></iframe>
              <?php else: ?>
                <!-- Legado local (se houver) -->
                <video src="<?= View::upload('clips/' . $uid) ?>" controls preload="metadata"></video>
              <?php endif; ?>
            </div>
          <?php endif; ?>

            <div class="clip-upload-area" id="clip-upload-video">
              <input type="file" name="video" accept="video/mp4,video/quicktime,video/x-msvideo"
                    class="clip-upload-input" id="clip-input-video">
              <div class="clip-upload-empty">
                ... (mantém o SVG e textos) ...
              </div>
              <!-- NOVO: barra de progresso do upload ao Stream -->
              <div class="clip-upload-progress" id="clip-video-progress" style="display:none;margin-top:10px;">
                <div style="height:6px;background:rgba(255,255,255,.1);border-radius:99px;overflow:hidden;">
                  <span id="clip-video-bar" style="display:block;height:100%;width:0;background:var(--danger);transition:width .2s;"></span>
                </div>
                <small id="clip-video-status" style="display:block;margin-top:6px;color:var(--text-3);"></small>
              </div>
            </div>

            <p class="form-help" style="margin-top:10px;">
              <?php if (!$isEdit): ?>
              O vídeo será comprimido para 720p e convertido para MP4 H.264.
              Um poster será gerado automaticamente.
              <?php else: ?>
              Envie um novo vídeo para substituir o atual (opcional).
              <?php endif; ?>
            </p>
          </div>
        </div>
        
        <?php
  
          $posterAtual = $isEdit ? $svc->posterFor($clip) : null;
          $temPosterCustom = $isEdit && !empty($clip['arquivo_poster'])
                            && str_starts_with((string)$clip['arquivo_poster'], 'http');
        ?>
        <!-- Poster customizado -->
        <div class="admin-card">
          <div class="admin-card-header">
            <h3>Poster customizado</h3>
            <small style="color:var(--text-3);font-size:11px;">Opcional</small>
          </div>
          <div class="admin-card-body">
            <?php if ($posterAtual): ?>
            <div class="clip-poster-preview">
              <img src="<?= View::e($posterAtual) ?>" alt="Poster atual">
              <small style="display:block;margin-top:6px;color:var(--text-3);font-size:11px;">
                <?= $temPosterCustom
                    ? 'Poster personalizado'
                    : 'Gerado automaticamente do vídeo — envie um para personalizar' ?>
              </small>
            </div>
            <?php endif; ?>

            <div class="clip-upload-area clip-upload-area--poster" id="clip-upload-poster">
              <input type="file" name="poster" accept="image/jpeg,image/png,image/webp"
                     class="clip-upload-input" id="clip-input-poster">
              <div class="clip-upload-empty">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                  <rect x="3" y="3" width="18" height="18" rx="2"/>
                  <circle cx="8.5" cy="8.5" r="1.5"/>
                  <polyline points="21 15 16 10 5 21"/>
                </svg>
                <p><strong>Enviar poster</strong></p>
                <small>9:16 recomendado · JPG, PNG, WEBP</small>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- ═══ COLUNA DIREITA: Configurações ═══ -->
      <div class="clip-form-main">

        <!-- Informações básicas -->
        <div class="admin-card">
          <div class="admin-card-header">
            <h3>Informações</h3>
          </div>
          <div class="admin-card-body">

            <div class="form-group">
              <label for="clip-titulo">Título <span class="required">*</span></label>
              <input type="text" name="titulo" id="clip-titulo" class="form-control"
                     maxlength="150" required
                     value="<?= $isEdit ? View::e($clip['titulo']) : '' ?>"
                     placeholder="Ex: Como instalar o escapamento esportivo">
              <small class="form-help">Aparece como overlay no clip e em listagens.</small>
            </div>

            <div class="form-group">
              <label for="clip-descricao">Descrição</label>
              <textarea name="descricao" id="clip-descricao" class="form-control"
                        rows="3" maxlength="500"
                        placeholder="Breve descrição que aparece abaixo do título"><?= $isEdit ? View::e($clip['descricao'] ?? '') : '' ?></textarea>
            </div>

            <div class="form-group">
              <label for="clip-hashtags">Hashtags</label>
              <input type="text" name="hashtags" id="clip-hashtags" class="form-control"
                     maxlength="300"
                     value="<?= $isEdit ? View::e($clip['hashtags'] ?? '') : '' ?>"
                     placeholder="#moto #escapamento #honda">
              <small class="form-help">Separe com espaços. Usado para descoberta.</small>
            </div>

          </div>
        </div>

        <!-- Produto vinculado -->
        <!-- <div class="admin-card">
          <div class="admin-card-header">
            <h3>Produto vinculado</h3>
            <small style="color:var(--text-3);font-size:11px;">Opcional</small>
          </div>
          <div class="admin-card-body">

            <div class="form-group">
              <label for="clip-produto">Selecionar produto</label>
              <select name="produto_id" id="clip-produto" class="form-control">
                <option value="">— Sem produto vinculado —</option>
                <?php foreach ($produtos as $p): ?>
                <option value="<?= $p['id'] ?>"
                        <?= ($isEdit && (int)$clip['produto_id'] === (int)$p['id']) ? 'selected' : '' ?>>
                  <?= View::e($p['nome']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <small class="form-help">
                Se vinculado, o card de produto aparece no clip e direciona pra página de venda.
              </small>
            </div>

            <div id="clip-cta-generico" style="<?= ($isEdit && !$clip['produto_id']) ? '' : 'display:none' ?>">
              <hr style="margin:14px 0;border:none;border-top:1px solid var(--border);">
              <p class="form-help" style="margin-bottom:12px;">
                <strong>CTA genérico</strong> — usado quando não há produto vinculado.
              </p>

              <div class="form-row">
                <div class="form-group">
                  <label for="clip-cta-texto">Texto do botão</label>
                  <input type="text" name="cta_texto" id="clip-cta-texto"
                         class="form-control" maxlength="80"
                         value="<?= $isEdit ? View::e($clip['cta_texto'] ?? '') : '' ?>"
                         placeholder="Ex: Saiba mais">
                </div>
                <div class="form-group">
                  <label for="clip-cta-link">Link</label>
                  <input type="url" name="cta_link" id="clip-cta-link"
                         class="form-control" maxlength="500"
                         value="<?= $isEdit ? View::e($clip['cta_link'] ?? '') : '' ?>"
                         placeholder="https://...">
                </div>
              </div>
            </div>

          </div>
        </div> -->

        <!-- Produtos vinculados (múltiplos) -->
        <div class="admin-card vinculados">
          <div class="admin-card-header">
            <h3>Produtos vinculados</h3>
            <small style="color:var(--text-3);font-size:11px;">Opcional · vários</small>
          </div>
          <div class="admin-card-body">
        
            <!-- Tags dos produtos selecionados -->
            <div class="clip-produtos-tags" id="clip-produtos-tags">
              <?php if ($isEdit && !empty($produtosVinculados)): ?>
                <?php foreach ($produtosVinculados as $pv): ?>
                <span class="clip-produto-tag" data-id="<?= $pv['produto_id'] ?>">
                  <?= View::e($pv['nome']) ?>
                  <button type="button" class="clip-produto-tag-remove" data-id="<?= $pv['produto_id'] ?>">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round">
                      <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                  </button>
                </span>
                <!-- Input hidden para o form -->
                <input type="hidden" name="produto_ids[]" value="<?= $pv['produto_id'] ?>">
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
        
            <!-- Busca de produto para adicionar -->
            <div class="clip-produto-search-wrap">
              <input type="text" class="clip-produto-search-input"
                    id="clip-produto-search"
                    placeholder="Buscar produto para vincular…"
                    autocomplete="off">
              <div class="clip-produto-dropdown" id="clip-produto-dropdown">
                <!-- opções preenchidas via JS -->
              </div>
            </div>
            <small class="form-help" style="margin-top:8px;display:block;">
              Selecione um ou mais produtos. Eles aparecerão no clip como cards de compra.
            </small>
        
            <!-- CTA genérico (sem produto) -->
            <div id="clip-cta-generico" style="margin-top:14px;">
              <hr style="margin:0 0 14px;border:none;border-top:1px solid var(--border);">
              <p class="form-help" style="margin-bottom:10px;">
                <strong>CTA genérico</strong> — exibido quando não houver produto vinculado.
              </p>
              <div class="form-row">
                <div class="form-group">
                  <label>Texto do botão</label>
                  <input type="text" name="cta_texto" class="form-control" maxlength="80"
                        value="<?= $isEdit ? View::e($clip['cta_texto']??'') : '' ?>"
                        placeholder="Ex: Saiba mais">
                </div>
                <div class="form-group">
                  <label>Link</label>
                  <input type="url" name="cta_link" class="form-control" maxlength="500"
                        value="<?= $isEdit ? View::e($clip['cta_link']??'') : '' ?>"
                        placeholder="https://...">
                </div>
              </div>
            </div>
        
          </div>
        </div>

        <!-- Configurações -->
        <div class="admin-card">
          <div class="admin-card-header">
            <h3>Configurações</h3>
          </div>
          <div class="admin-card-body">

            <div class="form-row">
              <div class="form-group">
                <label for="clip-ordem">Ordem</label>
                <input type="number" name="ordem" id="clip-ordem"
                       class="form-control" min="0"
                       value="<?= $isEdit ? (int)$clip['ordem'] : 0 ?>">
                <small class="form-help">Menor número aparece primeiro.</small>
              </div>
              <div class="form-group" style="display:flex;flex-direction:column;justify-content:flex-end;gap:10px;">
                <label class="form-toggle">
                  <input type="checkbox" name="ativo" value="1"
                         <?= (!$isEdit || $clip['ativo']) ? 'checked' : '' ?>>
                  <span>Clip ativo</span>
                  <small>Visível no site</small>
                </label>

                <label class="form-toggle">
                  <input type="checkbox" name="destaque" value="1"
                         <?= ($isEdit && $clip['destaque']) ? 'checked' : '' ?>>
                  <span>Destaque na home</span>
                  <small>Aparece em "Clips em alta"</small>
                </label>
              </div>
            </div>

          </div>
        </div>

        <?php if ($isEdit): ?>
        <!-- Estatísticas -->
        <div class="admin-card">
          <div class="admin-card-header">
            <h3>Engajamento</h3>
            <span style="font-size:11px;color:var(--text-3);">
              Status:
              <span class="admin-badge admin-badge--<?= $clip['status']==='ativo'?'success':($clip['status']==='processando'?'warning':'muted') ?>">
                <?= View::e($clip['status']) ?>
              </span>
            </span>
          </div>
          <div class="admin-card-body">
            <div class="clip-stats-grid">
              <div class="clip-stat">
                <strong><?= number_format($clip['total_views']) ?></strong>
                <span>Visualizações</span>
              </div>
              <div class="clip-stat">
                <strong><?= number_format($clip['total_likes']) ?></strong>
                <span>Curtidas</span>
              </div>
              <div class="clip-stat">
                <strong><?= number_format($clip['total_comentarios']) ?></strong>
                <span>Comentários</span>
              </div>
              <div class="clip-stat">
                <strong><?= number_format($clip['total_compartilhamentos']) ?></strong>
                <span>Compartilhamentos</span>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </form>
</div>

<script>
const PRODUTOS = <?= json_encode($produtos, JSON_UNESCAPED_UNICODE) ?>;
  </script>