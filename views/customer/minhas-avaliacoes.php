<?php
// ════════════════════════════════════════════════════════
// views/customer/minhas-avaliacoes.php
// Lista produtos dos pedidos com opção de avaliação rápida
// ════════════════════════════════════════════════════════

// Controller carrega:
//   $itens   — array de produtos únicos dos pedidos
//   $votados — IDs de produtos já avaliados pelo cliente

$isLogado  = Session::isClienteLogado();
$clienteId = $isLogado ? (int)Session::get('cliente_id') : null;
?>

<div class="cav-page">

  <div class="cav-header">
    <div>
      <h1 class="cav-title">Minhas avaliações</h1>
      <p class="cav-sub">Produtos dos seus pedidos que você pode avaliar.</p>
    </div>
    <?php if (!empty($stats)): ?>
    <div class="cav-stats">
      <span class="cav-stat" id="cav-stat-avaliados">
        <strong id="cav-stat-avaliados-num"><?= $stats['avaliados'] ?></strong>
        <span id="cav-stat-avaliados-label"><?= $stats['avaliados'] !== 1 ? 'avaliados' : 'avaliado' ?></span>
      </span>
      <span class="cav-stat cav-stat--pending" id="cav-stat-pendentes"
            style="<?= $stats['pendentes'] > 0 ? '' : 'display:none;' ?>">
        <strong id="cav-stat-pendentes-num"><?= $stats['pendentes'] ?></strong>
        <span id="cav-stat-pendentes-label"><?= $stats['pendentes'] !== 1 ? 'pendentes' : 'pendente' ?></span>
      </span>
    </div>
    <?php endif; ?>
  </div>

  <?php if (empty($itens)): ?>
  <div class="cav-empty">
    <svg width="52" height="52" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1.2" stroke-linecap="round">
      <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
    </svg>
    <h3>Nenhum produto para avaliar</h3>
    <p>Quando você fizer um pedido aprovado, os produtos aparecerão aqui.</p>
    <a href="<?= BASE_URL ?>" class="cav-btn-shop">Explorar produtos</a>
  </div>

  <?php else: ?>

  <!-- Filtros rápidos -->
  <div class="cav-filters">
    <button type="button" class="cav-filter-btn is-active" data-filtro="todos">
      Todos (<span id="cav-count-todos"><?= count($itens) ?></span>)
    </button>
    <button type="button" class="cav-filter-btn" data-filtro="avaliar">
      Para avaliar (<span id="cav-count-avaliar"><?= count(array_filter($itens, fn($i) => !$i['ja_avaliou'])) ?></span>)
    </button>
    <button type="button" class="cav-filter-btn" data-filtro="avaliados">
      Já avaliados (<span id="cav-count-avaliados"><?= count(array_filter($itens, fn($i) => $i['ja_avaliou'])) ?></span>)
    </button>
  </div>

  <!-- Grade de produtos -->
  <div class="cav-grid" id="cav-grid">

    <?php foreach ($itens as $item):
      $jaAvaliou  = (bool)$item['ja_avaliou'];
      $imgUrl     = $item['img_capa']
                  ? UPLOAD_URL . '/products/' . $item['img_capa']
                  : UPLOAD_URL . '/placeholder.webp';
      $preco      = 'R$ ' . number_format((float)$item['preco_pago'], 2, ',', '.');
      $mediaFmt   = $item['nota_media']
                  ? number_format((float)$item['nota_media'], 1, ',', '.')
                  : null;
    ?>

    <div class="cav-card <?= $jaAvaliou ? 'is-avaliado' : '' ?>"
         data-filtro="<?= $jaAvaliou ? 'avaliados' : 'avaliar' ?>"
         data-produto-id="<?= (int)$item['produto_id'] ?>">

      <!-- Imagem clicável -->
      <div class="cav-card-img"
           <?= !$jaAvaliou ? 'role="button" tabindex="0" data-open-review="' . (int)$item['produto_id'] . '"' : '' ?>
           title="<?= $jaAvaliou ? '' : 'Clique para avaliar' ?>">
        <img src="<?= View::e($imgUrl) ?>"
             alt="<?= View::e($item['nome']) ?>"
             loading="lazy">

        <?php if (!$jaAvaliou): ?>
        <div class="cav-card-rate-hint">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="#f59e0b">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
          Avaliar
        </div>
        <?php else: ?>
        <div class="cav-card-done">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="white" stroke-width="3" stroke-linecap="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          Avaliado
        </div>
        <?php endif; ?>
      </div>

      <!-- Info -->
      <div class="cav-card-body">
        <p class="cav-card-nome"><?= View::e(mb_substr($item['nome'], 0, 60)) ?></p>

        <?php if ($mediaFmt): ?>
        <!-- Badge de avaliação existente -->
        <?php View::partial('partials/_rating-badge', [
            'media' => (float)$item['nota_media'],
            'total' => (int)$item['total_avaliacoes'],
            'size'  => 'xs',
        ]) ?>
        <?php endif; ?>

        <div class="cav-card-meta">
          <span class="cav-card-preco"><?= $preco ?></span>
          <span class="cav-card-pedido">Pedido #<?= $item['pedido_id'] ?></span>
        </div>
      </div>

      <!-- Ação -->
      <div class="cav-card-footer">
        <?php if (!$jaAvaliou): ?>
        <button type="button"
                class="cav-btn-avaliar"
                data-open-review="<?= (int)$item['produto_id'] ?>"
                data-nome="<?= View::e($item['nome']) ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
          Avaliar produto
        </button>
        <?php else: ?>
        <a href="<?= BASE_URL ?>/produto/<?= View::e($item['slug']) ?>#avaliacao"
           class="cav-btn-ver">
          Ver avaliação
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

  </div>
  <?php endif; ?>

</div>

<!-- ══ MODAL DE AVALIAÇÃO RÁPIDA ═══════════════════════ -->
<div id="cav-review-modal" class="sm-write-modal">
  <div class="sm-write-panel" style="max-width:600px">
    <div class="sm-write-panel-header">
      <div>
        <h3 class="sm-write-panel-title">Avaliar produto</h3>
        <p id="cav-modal-produto-nome"
           style="font-size:13px;color:#64748b;margin-top:3px;"></p>
      </div>
      <button type="button" class="sm-write-close" id="cav-modal-close">
        <svg viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"
             fill="none" stroke-linecap="round">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <form id="cav-review-form" novalidate>
      <input type="hidden" name="_csrf_token"
             value="<?= SecurityHelper::generateCsrf() ?>">
      <input type="hidden" name="produto_id" id="cav-form-produto-id">
      <input type="hidden" name="upload_token" id="cav-upload-token" value="">

      <!-- Star picker (mesma implementação da página de produto) -->
      <div class="sm-star-picker">
        <div class="sm-star-picker-label" id="cav-star-label">Sua nota *</div>
        <div class="sm-star-picker-stars" id="cav-star-picker"
             role="radiogroup" aria-labelledby="cav-star-label">
          <?php for ($i=1;$i<=5;$i++): ?>
          <span class="sm-star-picker-star" data-val="<?= $i ?>"
                role="radio" tabindex="0" aria-checked="false"
                aria-label="<?= $i ?> estrela<?= $i > 1 ? 's' : '' ?>">
            <svg viewBox="0 0 24 24">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
          </span>
          <?php endfor; ?>
        </div>
        <div class="sm-star-picker-hint" id="cav-star-hint"></div>
        <input type="hidden" name="nota" id="cav-nota-val" value="0">
      </div>

      <div class="sm-write-field">
        <label class="sm-write-field-label" for="cav-titulo">Título</label>
        <input class="sm-write-input" id="cav-titulo" name="titulo"
               type="text" maxlength="150"
               placeholder="Resuma em uma frase">
        <div class="sm-write-counter" id="cav-titulo-counter">0/150</div>
      </div>

      <div class="sm-write-field">
        <label class="sm-write-field-label" for="cav-comentario">
          Comentário *
        </label>
        <textarea class="sm-write-textarea" id="cav-comentario"
                  name="comentario" maxlength="2000"
                  placeholder="Conte sua experiência com este produto…"></textarea>
        <div class="sm-write-counter" id="cav-comentario-counter">0/2000</div>
      </div>

      <!-- Upload opcional -->
      <div class="sm-write-field">
        <label class="sm-write-field-label">Fotos (opcional)</label>
        <div class="sm-upload-zone" id="cav-upload-zone">
          <div class="sm-upload-zone-icon">
            <svg viewBox="0 0 24 24" stroke="currentColor" fill="none"
                 stroke-width="1.5" stroke-linecap="round">
              <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
              <polyline points="17 8 12 3 7 8"/>
              <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
          </div>
          <div class="sm-upload-zone-text">Clique ou arraste fotos</div>
          <div class="sm-upload-zone-btn">Selecionar</div>
          <div class="sm-upload-zone-hint">JPG, PNG, WEBP · até 5MB</div>
          <input type="file" class="sm-upload-zone-input"
                 id="cav-upload-input"
                 accept=".jpg,.jpeg,.png,.webp" multiple>
        </div>
        <div class="sm-upload-previews" id="cav-upload-previews"></div>
        <div class="sm-upload-progress-wrap" id="cav-upload-progress"
             style="display:none;"></div>
      </div>

      <!-- Nota de verificação -->
      <div class="cav-compra-badge">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
          <polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
        Avaliação verificada — você comprou este produto
      </div>

      <button type="submit" class="sm-write-submit" id="cav-submit">
        Publicar avaliação
      </button>
    </form>

    <!-- Resultado (sucesso/erro) -->
    <div id="cav-result" hidden style="text-align:center;padding:16px 0;">
      <div id="cav-result-icon" style="margin:0 auto 14px;width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#f0fdf4;">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none"
             stroke="#16a34a" stroke-width="3" stroke-linecap="round">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
      </div>
      <h4 id="cav-result-title"
          style="font-size:17px;font-weight:800;color:#0f172a;margin-bottom:6px;"></h4>
      <p id="cav-result-msg"
         style="font-size:13.5px;color:#64748b;line-height:1.5;margin-bottom:20px;"></p>
      <div style="display:flex;flex-direction:column;gap:10px;max-width:260px;margin:0 auto;">
        <button type="button" id="cav-btn-proximo" class="sm-write-submit" style="display:none;">
          Avaliar próximo produto
        </button>
        <button type="button" id="cav-result-close"
                class="sm-write-submit sm-write-submit--ghost">
          Fechar
        </button>
      </div>
    </div>
  </div>
</div>

<style>
/* ════════════════════════════════════════════════════════
   customer/minhas-avaliacoes.php
   ════════════════════════════════════════════════════════ */

.cav-page { max-width: 1100px; }

/* Header */
.cav-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 16px; flex-wrap: wrap;
  margin-bottom: 24px;
}
.cav-title {
  font-size: 24px; font-weight: 900;
  color: #0f172a; letter-spacing: -.4px; margin-bottom: 4px;
}
.cav-sub { font-size: 13.5px; color: #64748b; }

.cav-stats { display: flex; gap: 8px; align-items: center; }
.cav-stat {
  display: inline-flex; align-items: baseline; gap: 4px;
  padding: 5px 12px;
  background: #f8fafc; border: 1.5px solid #e2e8f0;
  border-radius: 99px; font-size: 12px; color: #64748b;
}
.cav-stat strong { font-size: 14px; font-weight: 800; color: #0f172a; }
.cav-stat--pending { border-color: #fde68a; background: #fefce8; color: #92400e; }
.cav-stat--pending strong { color: #d97706; }

/* Estado vazio */
.cav-empty {
  text-align: center; padding: 64px 24px;
}
.cav-empty svg { color: #e2e8f0; margin-bottom: 16px; }
.cav-empty h3 { font-size: 18px; font-weight: 800; color: #0f172a; margin-bottom: 6px; }
.cav-empty p  { font-size: 14px; color: #64748b; margin-bottom: 20px; }
.cav-btn-shop {
  display: inline-block; padding: 12px 24px;
  background: #0f172a; color: #fff;
  border-radius: 10px; text-decoration: none;
  font-size: 14px; font-weight: 800;
}

/* Filtros */
.cav-filters {
  display: flex; gap: 6px; flex-wrap: wrap;
  margin-bottom: 20px;
}
.cav-filter-btn {
  padding: 7px 14px;
  border: 1.5px solid #e2e8f0; border-radius: 99px;
  background: #fff; font-family: inherit;
  font-size: 12.5px; font-weight: 700;
  color: #64748b; cursor: pointer; transition: all .15s;
}
.cav-filter-btn:hover { border-color: #94a3b8; color: #0f172a; }
.cav-filter-btn.is-active {
  background: #0f172a; border-color: #0f172a; color: #fff;
}

/* Grade */
.cav-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 16px;
}
@media (max-width: 640px) {
  .cav-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
}

/* Card */
.cav-card {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 14px;
  overflow: hidden;
  display: flex; flex-direction: column;
  transition: all .2s;
}
.cav-card:hover {
  border-color: #cbd5e1;
  box-shadow: 0 4px 16px rgba(0,0,0,.06);
  transform: translateY(-2px);
}
.cav-card.is-avaliado { opacity: .75; }
.cav-card.is-avaliado:hover { opacity: 1; }
.cav-card[style*="display:none"] { display: none !important; }

/* Imagem */
.cav-card-img {
  position: relative;
  aspect-ratio: 1;
  background: #f8fafc;
  overflow: hidden;
  cursor: pointer;
}
.cav-card.is-avaliado .cav-card-img { cursor: default; }
.cav-card-img img {
  width: 100%; height: 100%;
  object-fit: cover; display: block;
  transition: transform .3s;
}
.cav-card:hover .cav-card-img img { transform: scale(1.04); }

/* Hint de avaliação */
.cav-card-rate-hint {
  position: absolute; inset: 0;
  background: rgba(0,0,0,.32);
  display: flex; align-items: center; justify-content: center;
  gap: 6px;
  color: #fff; font-size: 13px; font-weight: 800;
  opacity: 0; transition: opacity .2s;
}
.cav-card:hover .cav-card-rate-hint { opacity: 1; }

/* Badge "Avaliado" */
.cav-card-done {
  position: absolute; top: 8px; left: 8px;
  display: inline-flex; align-items: center; gap: 4px;
  padding: 4px 9px;
  background: #16a34a; color: #fff;
  border-radius: 99px;
  font-size: 10px; font-weight: 800;
}

/* Corpo */
.cav-card-body { padding: 10px 12px; flex: 1; }
.cav-card-nome {
  font-size: 12.5px; font-weight: 700; color: #0f172a;
  line-height: 1.35; margin-bottom: 6px;
  display: -webkit-box; -webkit-line-clamp: 2;
  -webkit-box-orient: vertical; overflow: hidden;
}
.cav-card-meta {
  display: flex; flex-direction: column; gap: 2px;
  margin-top: 6px;
}
.cav-card-preco {
  font-size: 13px; font-weight: 800; color: #0f172a;
}
.cav-card-pedido {
  font-size: 10.5px; color: #94a3b8;
}

/* Footer com botão */
.cav-card-footer {
  padding: 8px 10px;
  border-top: 1px solid #f1f5f9;
  background: #fafbfc;
}
.cav-btn-avaliar {
  display: flex; align-items: center; justify-content: center; gap: 6px;
  width: 100%; padding: 9px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #fff; border: none; border-radius: 8px;
  font-family: inherit; font-size: 12.5px; font-weight: 800;
  cursor: pointer; transition: all .15s;
}
.cav-btn-avaliar:hover { filter: brightness(1.08); transform: translateY(-1px); }
.cav-btn-ver {
  display: block; text-align: center;
  padding: 9px;
  background: #f1f5f9; color: #64748b;
  border-radius: 8px; text-decoration: none;
  font-size: 12px; font-weight: 700;
}
.cav-btn-ver:hover { background: #e2e8f0; }

/* Badge de compra verificada no modal */
.cav-compra-badge {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; margin-bottom: 16px;
  background: #f0fdf4; border: 1px solid #bbf7d0;
  border-radius: 8px;
  font-size: 12.5px; font-weight: 700; color: #166534;
}

/* Contador de caracteres */
.sm-write-counter {
  font-size: 11px; color: #94a3b8;
  text-align: right; margin-top: 4px;
}
.sm-write-counter.is-near-limit { color: #d97706; font-weight: 700; }

/* Variante ghost do botão de submit (usado no "Fechar" quando há "Avaliar próximo") */
.sm-write-submit--ghost {
  background: transparent;
  border: 1.5px solid var(--rv-border, #e2e8f0);
  color: var(--rv-text2, #64748b);
}
.sm-write-submit--ghost:hover {
  background: var(--rv-surface2, #f8fafc);
  color: var(--rv-text, #0f172a);
}
</style>

<script>

</script>