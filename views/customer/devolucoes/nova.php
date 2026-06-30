<?php
// views/customer/devolucoes/nova.php
$diasRestantes = max(0, 7 - (int)((time() - strtotime($pedido['atualizado_em'])) / 86400));
$urgente       = $diasRestantes <= 2;
?>

<div class="dnv-root" data-pedido-id="<?= (int)$pedido['id'] ?>">

  <!-- ── Header ───────────────────────────────────────── -->
  <div class="dnv-header">
    <a href="<?= BASE_URL ?>/minha-conta/pedido/<?= (int)$pedido['id'] ?>"
       class="dnv-back">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <polyline points="15 18 9 12 15 6"/>
      </svg>
      Pedido #<?= View::e($pedido['codigo']) ?>
    </a>
    <div class="dnv-header-row">
      <h1 class="dnv-title">Solicitar devolução ou troca</h1>
      <span class="dnv-deadline-pill <?= $urgente ? 'dnv-deadline-pill--urgent' : '' ?>">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <circle cx="12" cy="12" r="10"/>
          <polyline points="12 6 12 12 16 14"/>
        </svg>
        <?php if ($urgente): ?>
          Expira em <?= $diasRestantes ?> dia<?= $diasRestantes !== 1 ? 's' : '' ?>
        <?php else: ?>
          <?= $diasRestantes ?> dias restantes
        <?php endif; ?>
      </span>
    </div>
  </div>

  <form method="POST"
        action="<?= BASE_URL ?>/minha-conta/devolucao/nova"
        enctype="multipart/form-data"
        id="dnv-form"
        novalidate>
    <?= SecurityHelper::csrfField() ?>
    <input type="hidden" name="pedido_id" value="<?= (int)$pedido['id'] ?>">

    <div class="dnv-layout">
      <div class="dnv-main">

        <!-- ── 01 Tipo ──────────────────────────────── -->
        <div class="dnv-step" data-step="1">
          <div class="dnv-step-label">
            <span class="dnv-step-num">01</span>
            O que você precisa?
          </div>
          <div class="dnv-tipo-grid">
            <label class="dnv-tipo-card dnv-tipo-card--active" data-value="devolucao">
              <input type="radio" name="tipo" value="devolucao" checked>
              <div class="dnv-tipo-ico">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="1" x2="12" y2="23"/>
                  <path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                </svg>
              </div>
              <div>
                <strong>Devolução</strong>
                <span>Quero reembolso do valor pago</span>
              </div>
              <div class="dnv-tipo-check">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="3" stroke-linecap="round">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
              </div>
            </label>

            <label class="dnv-tipo-card" data-value="troca">
              <input type="radio" name="tipo" value="troca">
              <div class="dnv-tipo-ico">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="17 1 21 5 17 9"/>
                  <path d="M3 11V9a4 4 0 014-4h14"/>
                  <polyline points="7 23 3 19 7 15"/>
                  <path d="M21 13v2a4 4 0 01-4 4H3"/>
                </svg>
              </div>
              <div>
                <strong>Troca</strong>
                <span>Quero outro tamanho ou modelo</span>
              </div>
              <div class="dnv-tipo-check">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="3" stroke-linecap="round">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
              </div>
            </label>
          </div>
        </div>

        <!-- ── 02 Motivo ────────────────────────────── -->
        <div class="dnv-step" data-step="2">
          <div class="dnv-step-label">
            <span class="dnv-step-num">02</span>
            Qual o motivo?
          </div>
          <div class="dnv-select-wrap">
            <select name="motivo_id" id="dnv-motivo" class="dnv-select" required>
              <option value="">Selecione o motivo…</option>
              <?php foreach ($motivos as $m): ?>
              <option value="<?= (int)$m['id'] ?>"
                      data-exige-foto="<?= (int)$m['exige_foto'] ?>">
                <?= View::e($m['label']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <svg class="dnv-select-arrow" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </div>
          <div class="dnv-field-error" id="err-motivo"></div>
        </div>

        <!-- ── 03 Itens ─────────────────────────────── -->
        <div class="dnv-step" data-step="3">
          <div class="dnv-step-label">
            <span class="dnv-step-num">03</span>
            Quais itens?
            <span class="dnv-step-hint">Selecione ao menos um</span>
          </div>
          <div class="dnv-items">
            <?php foreach ($itens as $item):
              $img = !empty($item['imagem'])
                ? BASE_URL.'/uploads/produtos/'.$item['imagem']
                : BASE_URL.'/assets/img/placeholder.png';
              
                $img = ImageHelper::getCartItemImage($item['produto_id']);
            ?>
            <label class="dnv-item" for="item_<?= (int)$item['id'] ?>">
              <input type="checkbox"
                     name="itens[<?= (int)$item['id'] ?>]"
                     value="<?= (int)$item['quantidade'] ?>"
                     id="item_<?= (int)$item['id'] ?>"
                     class="dnv-item-input">
              <div class="dnv-item-thumb">
                <img src="<?= View::e($img) ?>" alt="" loading="lazy">
              </div>
              <div class="dnv-item-body">
                <span class="dnv-item-name"><?= View::e($item['nome_produto']) ?></span>
                <span class="dnv-item-meta">Qtd: <?= (int)$item['quantidade'] ?></span>
              </div>
              <div class="dnv-item-marker" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="3" stroke-linecap="round">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
          <div class="dnv-field-error" id="err-itens"></div>
        </div>

        <!-- ── 04 Descrição ─────────────────────────── -->
        <div class="dnv-step" data-step="4">
          <div class="dnv-step-label">
            <span class="dnv-step-num">04</span>
            Descreva o problema
            <span class="dnv-step-hint">opcional</span>
          </div>
          <div class="dnv-textarea-wrap">
            <textarea name="descricao"
                      id="dnv-desc"
                      class="dnv-textarea"
                      maxlength="800"
                      placeholder="Explique o que aconteceu com o produto. Quanto mais detalhe, mais rápido conseguimos resolver."></textarea>
            <span class="dnv-char-count"><span id="dnv-desc-count">0</span>/800</span>
          </div>
        </div>

        <!-- ── 05 Mídia ──────────────────────────────── -->
        <div class="dnv-step" data-step="5">
          <div class="dnv-step-label">
            <span class="dnv-step-num">05</span>
            Fotos ou vídeo
            <span class="dnv-step-hint" id="dnv-midia-badge">opcional</span>
          </div>

          <!-- Drop zone -->
          <div class="dnv-drop" id="dnv-drop" role="button" tabindex="0"
               aria-label="Clique ou arraste arquivos para enviar">
            <input type="file"
                   name="midias[]"
                   id="dnv-file-input"
                   multiple
                   accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime">

            <div class="dnv-drop-idle" id="dnv-drop-idle">
              <div class="dnv-drop-ico">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="16 16 12 12 8 16"/>
                  <line x1="12" y1="12" x2="12" y2="21"/>
                  <path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/>
                </svg>
              </div>
              <p class="dnv-drop-title">Arraste arquivos aqui</p>
              <p class="dnv-drop-sub">
                ou <button type="button" class="dnv-drop-btn" id="dnv-drop-btn">
                  selecione do dispositivo
                </button>
              </p>
              <p class="dnv-drop-meta">JPG · PNG · WEBP · MP4 — máx. 10 MB por arquivo</p>
            </div>
          </div>

          <!-- Previews grid (oculto até ter arquivo) -->
          <div class="dnv-previews" id="dnv-previews" hidden></div>

          <!-- Adicionar mais (oculto até ter arquivo) -->
          <button type="button" class="dnv-add-more" id="dnv-add-more" hidden>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <line x1="12" y1="5" x2="12" y2="19"/>
              <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Adicionar mais arquivos
          </button>
        </div>

      </div><!-- /.dnv-main -->

      <!-- ── Aside ─────────────────────────────────── -->
      <aside class="dnv-aside">
        <div class="dnv-aside-inner">

          <!-- Prazo -->
          <div class="dnv-aside-prazo <?= $urgente ? 'dnv-aside-prazo--urgent' : '' ?>">
            <div class="dnv-prazo-ring">
              <svg viewBox="0 0 44 44" fill="none">
                <circle cx="22" cy="22" r="19" stroke="rgba(255,255,255,.15)" stroke-width="3"/>
                <?php
                  $pct  = min(100, ($diasRestantes / 7) * 100);
                  $circ = 2 * M_PI * 19;
                  $dash = ($pct / 100) * $circ;
                ?>
                <circle cx="22" cy="22" r="19"
                        stroke="rgba(255,255,255,.8)"
                        stroke-width="3"
                        stroke-dasharray="<?= round($dash,1) ?> <?= round($circ,1) ?>"
                        stroke-linecap="round"
                        transform="rotate(-90 22 22)"/>
              </svg>
              <span class="dnv-prazo-num"><?= $diasRestantes ?></span>
            </div>
            <div class="dnv-prazo-info">
              <strong>dia<?= $diasRestantes !== 1 ? 's' : '' ?> restante<?= $diasRestantes !== 1 ? 's' : '' ?></strong>
              <span>para solicitar</span>
            </div>
          </div>

          <!-- Garantias -->
          <ul class="dnv-garantias">
            <?php
            $garantias = [
              ['Prazo CDC de 7 dias corridos',       'M4 12l2 2 4-4'],
              ['Frete de retorno pago pela loja',     'M4 12l2 2 4-4'],
              ['Análise em até 2 dias úteis',         'M4 12l2 2 4-4'],
              ['Reembolso em até 5 dias úteis',       'M4 12l2 2 4-4'],
            ];
            foreach ($garantias as $g):
            ?>
            <li>
              <span class="dnv-g-ico">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="3" stroke-linecap="round">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
              </span>
              <?= $g[0] ?>
            </li>
            <?php endforeach; ?>
          </ul>

          <!-- Separador -->
          <hr class="dnv-sep">

          <!-- Resumo dinâmico -->
          <div class="dnv-resumo" id="dnv-resumo">
            <p class="dnv-resumo-empty" id="dnv-resumo-empty">
              Selecione os itens para ver o resumo.
            </p>
            <div class="dnv-resumo-items" id="dnv-resumo-items" hidden></div>
          </div>

          <!-- Erros globais -->
          <!-- <div class="dnv-global-error" id="dnv-global-error" hidden>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <circle cx="12" cy="12" r="10"/>
              <line x1="12" y1="8" x2="12" y2="12"/>
              <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span id="dnv-global-error-msg"></span>
          </div> -->

          <!-- CTA -->
          <button type="submit" class="dnv-submit" id="dnv-submit">
            <span class="dnv-submit-label">Enviar solicitação</span>
            <span class="dnv-submit-loader" hidden>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                   class="dnv-spin">
                <path d="M21 12a9 9 0 11-6.219-8.56"/>
              </svg>
            </span>
          </button>

          <a href="<?= BASE_URL ?>/minha-conta/pedido/<?= (int)$pedido['id'] ?>"
             class="dnv-cancel">
            Cancelar
          </a>

        </div>
      </aside>
    </div><!-- /.dnv-layout -->
  </form>
</div>

<style>
/* ════════════════════════════════════════════════════
   views/customer/devolucoes/nova.php — CSS embutido
   ════════════════════════════════════════════════════ */

/* ── Tokens ─────────────────────────────────────── */
.dnv-root {
  --dnv-accent:     #2563eb;
  --dnv-accent-l:   #eff6ff;
  --dnv-dark:       #0f172a;
  --dnv-muted:      #64748b;
  --dnv-border:     #e2e8f0;
  --dnv-bg:         #f8fafc;
  --dnv-radius:     14px;
  --dnv-radius-sm:  9px;
  --dnv-spring:     cubic-bezier(.34, 1.56, .64, 1);
  --dnv-ease:       cubic-bezier(.22, 1, .36, 1);
  font-family: -apple-system,'SF Pro Display','Segoe UI',system-ui,sans-serif;
  padding-bottom: 40px;
}

/* ── Header ─────────────────────────────────────── */
.dnv-back {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: var(--dnv-muted);
  text-decoration: none;
  margin-bottom: 10px;
  transition: color .15s;
}
.dnv-back:hover { color: var(--dnv-dark); }
.dnv-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 24px;
}
.dnv-title {
  font-size: 22px;
  font-weight: 800;
  color: var(--dnv-dark);
  letter-spacing: -.3px;
  margin: 0;
}
.dnv-deadline-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 700;
  padding: 5px 12px;
  border-radius: 99px;
  background: #f0fdf4;
  color: #16a34a;
  border: 1.5px solid #bbf7d0;
}
.dnv-deadline-pill--urgent {
  background: #fef2f2;
  color: #dc2626;
  border-color: #fca5a5;
  animation: dnv-pulse 2s ease infinite;
}
@keyframes dnv-pulse {
  0%,100% { transform: scale(1); }
  50%      { transform: scale(1.03); }
}

/* ── Layout ─────────────────────────────────────── */
.dnv-layout {
  display: grid;
  grid-template-columns: 1fr 288px;
  gap: 20px;
  align-items: start;
}

/* ── Steps ──────────────────────────────────────── */
.dnv-step {
  background: #fff;
  border: 1px solid var(--dnv-border);
  border-radius: var(--dnv-radius);
  padding: 22px 24px;
  margin-bottom: 12px;
  opacity: 0;
  transform: translateY(12px);
  animation: dnv-in .4s var(--dnv-ease) forwards;
}
.dnv-step:nth-child(1) { animation-delay: .05s; }
.dnv-step:nth-child(2) { animation-delay: .10s; }
.dnv-step:nth-child(3) { animation-delay: .15s; }
.dnv-step:nth-child(4) { animation-delay: .20s; }
.dnv-step:nth-child(5) { animation-delay: .25s; }
@keyframes dnv-in {
  to { opacity:1; transform: translateY(0); }
}

.dnv-step-label {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13.5px;
  font-weight: 800;
  color: var(--dnv-dark);
  margin-bottom: 16px;
  text-transform: uppercase;
  letter-spacing: .5px;
}
.dnv-step-num {
  font-size: 11px;
  font-weight: 900;
  color: var(--dnv-accent);
  background: var(--dnv-accent-l);
  width: 28px;
  height: 20px;
  border-radius: 4px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  letter-spacing: 0;
  flex-shrink: 0;
}
.dnv-step-hint {
  font-size: 11px;
  font-weight: 600;
  color: #94a3b8;
  background: #f1f5f9;
  padding: 2px 8px;
  border-radius: 99px;
  letter-spacing: 0;
  text-transform: none;
}

/* ── Tipo ───────────────────────────────────────── */
.dnv-tipo-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.dnv-tipo-card {
  position: relative;
  display: flex;
  align-items: center;
  gap: 14px;
  border: 2px solid var(--dnv-border);
  border-radius: var(--dnv-radius-sm);
  padding: 16px;
  cursor: pointer;
  transition: border-color .2s var(--dnv-ease),
              background .2s var(--dnv-ease),
              transform .2s var(--dnv-spring);
  user-select: none;
}
.dnv-tipo-card:hover   { border-color: #93c5fd; transform: translateY(-1px); }
.dnv-tipo-card:active  { transform: scale(.98) translateY(0); }
.dnv-tipo-card input   { display: none; }
.dnv-tipo-card--active {
  border-color: var(--dnv-accent);
  background: var(--dnv-accent-l);
}
.dnv-tipo-ico {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  background: #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: background .2s;
}
.dnv-tipo-ico svg {
  width: 20px;
  height: 20px;
  stroke: var(--dnv-muted);
  transition: stroke .2s;
}
.dnv-tipo-card--active .dnv-tipo-ico {
  background: #dbeafe;
}
.dnv-tipo-card--active .dnv-tipo-ico svg { stroke: var(--dnv-accent); }
.dnv-tipo-card strong {
  display: block;
  font-size: 14px;
  font-weight: 700;
  color: var(--dnv-dark);
  margin-bottom: 2px;
}
.dnv-tipo-card span {
  font-size: 12.5px;
  color: var(--dnv-muted);
  line-height: 1.4;
}
.dnv-tipo-check {
  position: absolute;
  top: 10px;
  right: 10px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: 2px solid var(--dnv-border);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all .2s var(--dnv-spring);
}
.dnv-tipo-check svg {
  width: 10px;
  stroke: transparent;
  transition: stroke .15s;
}
.dnv-tipo-card--active .dnv-tipo-check {
  background: var(--dnv-accent);
  border-color: var(--dnv-accent);
}
.dnv-tipo-card--active .dnv-tipo-check svg { stroke: #fff; }

/* ── Select ─────────────────────────────────────── */
.dnv-select-wrap { position: relative; }
.dnv-select {
  width: 100%;
  height: 46px;
  padding: 0 40px 0 14px;
  border: 1.5px solid var(--dnv-border);
  border-radius: var(--dnv-radius-sm);
  font-size: 14px;
  font-family: inherit;
  color: var(--dnv-dark);
  background: #fff;
  appearance: none;
  cursor: pointer;
  transition: border-color .15s, box-shadow .15s;
}
.dnv-select:focus {
  outline: none;
  border-color: var(--dnv-accent);
  box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}
.dnv-select-arrow {
  position: absolute;
  right: 14px;
  top: 50%;
  transform: translateY(-50%);
  width: 15px;
  height: 15px;
  stroke: var(--dnv-muted);
  pointer-events: none;
}

/* ── Itens ──────────────────────────────────────── */
.dnv-items { display: flex; flex-direction: column; }
.dnv-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 8px;
  border-radius: var(--dnv-radius-sm);
  cursor: pointer;
  transition: background .12s;
  border-bottom: 1px solid #f8fafc;
}
.dnv-item:last-child { border-bottom: none; }
.dnv-item:hover { background: var(--dnv-bg); }
.dnv-item-input { display: none; }
.dnv-item-thumb {
  width: 54px;
  height: 54px;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid var(--dnv-border);
  flex-shrink: 0;
  background: var(--dnv-bg);
}
.dnv-item-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform .3s var(--dnv-ease);
}
.dnv-item:hover .dnv-item-thumb img { transform: scale(1.05); }
.dnv-item-body { flex: 1; min-width: 0; }
.dnv-item-name {
  display: block;
  font-size: 14px;
  font-weight: 600;
  color: var(--dnv-dark);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  margin-bottom: 3px;
}
.dnv-item-meta { font-size: 12.5px; color: #94a3b8; }
.dnv-item-marker {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 2px solid var(--dnv-border);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: all .2s var(--dnv-spring);
}
.dnv-item-marker svg {
  width: 11px;
  stroke: transparent;
  transition: stroke .15s;
}
/* Item selecionado — :has() com fallback via JS */
.dnv-item.is-checked { background: var(--dnv-accent-l); }
.dnv-item.is-checked .dnv-item-marker {
  background: var(--dnv-accent);
  border-color: var(--dnv-accent);
  transform: scale(1.1);
}
.dnv-item.is-checked .dnv-item-marker svg { stroke: #fff; }
.dnv-item.is-checked .dnv-item-name { color: #1d4ed8; }

/* ── Textarea ───────────────────────────────────── */
.dnv-textarea-wrap { position: relative; }
.dnv-textarea {
  width: 100%;
  min-height: 90px;
  padding: 12px 14px;
  border: 1.5px solid var(--dnv-border);
  border-radius: var(--dnv-radius-sm);
  font-size: 14px;
  font-family: inherit;
  color: var(--dnv-dark);
  background: #fff;
  resize: vertical;
  line-height: 1.6;
  transition: border-color .15s, box-shadow .15s;
  box-sizing: border-box;
}
.dnv-textarea:focus {
  outline: none;
  border-color: var(--dnv-accent);
  box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}
.dnv-char-count {
  position: absolute;
  bottom: 8px;
  right: 12px;
  font-size: 11px;
  color: #94a3b8;
  pointer-events: none;
}

/* ── Drop zone ──────────────────────────────────── */
.dnv-drop {
  position: relative;
  border: 2px dashed var(--dnv-border);
  border-radius: var(--dnv-radius-sm);
  padding: 32px 20px;
  text-align: center;
  cursor: pointer;
  transition: border-color .15s, background .15s;
  background: #fafbff;
}
.dnv-drop:hover, .dnv-drop.drag-over {
  border-color: var(--dnv-accent);
  background: var(--dnv-accent-l);
}
.dnv-drop.has-files { border-style: solid; border-color: #86efac; background: #f0fdf4; padding: 16px; }
.dnv-drop input[type="file"] {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
  z-index: -1; /* hidden but accessible */
}
.dnv-drop-ico {
  width: 48px;
  height: 48px;
  background: #f1f5f9;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 12px;
  transition: background .15s;
}
.dnv-drop-ico svg { width: 22px; stroke: #94a3b8; }
.dnv-drop:hover .dnv-drop-ico { background: #dbeafe; }
.dnv-drop:hover .dnv-drop-ico svg { stroke: var(--dnv-accent); }
.dnv-drop-title { font-size: 15px; font-weight: 700; color: var(--dnv-dark); margin: 0 0 4px; }
.dnv-drop-sub   { font-size: 13.5px; color: var(--dnv-muted); margin: 0 0 8px; }
.dnv-drop-btn {
  background: none; border: none;
  color: var(--dnv-accent);
  font-size: inherit; font-weight: 700;
  cursor: pointer; padding: 0;
  text-decoration: underline;
}
.dnv-drop-meta { font-size: 12px; color: #94a3b8; margin: 0; }

/* ── Previews ───────────────────────────────────── */
.dnv-previews {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
  gap: 8px;
  margin-top: 12px;
}
.dnv-preview {
  position: relative;
  aspect-ratio: 1;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid var(--dnv-border);
  background: var(--dnv-bg);
  animation: dnv-pop .3s var(--dnv-spring);
}
@keyframes dnv-pop {
  from { transform: scale(.85); opacity: 0; }
  to   { transform: scale(1);   opacity: 1; }
}
.dnv-preview img,
.dnv-preview video {
  width: 100%; height: 100%; object-fit: cover; display: block;
}
.dnv-preview-badge {
  position: absolute; top: 4px; left: 4px;
  background: rgba(0,0,0,.6);
  color: #fff; font-size: 9px; font-weight: 800;
  padding: 2px 6px; border-radius: 4px;
  text-transform: uppercase; letter-spacing: .5px;
}
.dnv-preview-rm {
  position: absolute; top: 4px; right: 4px;
  width: 22px; height: 22px; border-radius: 50%;
  background: rgba(0,0,0,.55); border: none;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; transition: background .15s;
}
.dnv-preview-rm svg { stroke: #fff; width: 10px; pointer-events: none; }
.dnv-preview-rm:hover { background: #ef4444; }

.dnv-add-more {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 100%;
  margin-top: 8px;
  padding: 9px;
  background: none;
  border: 1.5px dashed var(--dnv-border);
  border-radius: var(--dnv-radius-sm);
  color: var(--dnv-muted);
  font-size: 13px; font-weight: 600;
  cursor: pointer;
  transition: all .15s;
}
.dnv-add-more:hover {
  border-color: var(--dnv-accent);
  color: var(--dnv-accent);
  background: var(--dnv-accent-l);
}

/* ── Field errors ───────────────────────────────── */
.dnv-field-error {
  font-size: 12.5px;
  color: #dc2626;
  margin-top: 6px;
  min-height: 18px;
  font-weight: 500;
}

/* ── Aside ──────────────────────────────────────── */
.dnv-aside { position: sticky; top: 80px; }
.dnv-aside-inner {
  background: #fff;
  border: 1px solid var(--dnv-border);
  border-radius: var(--dnv-radius);
  padding: 20px;
  animation: dnv-in .4s var(--dnv-ease) .3s both;
}

/* Prazo card */
.dnv-aside-prazo {
  display: flex;
  align-items: center;
  gap: 14px;
  background: linear-gradient(135deg, #0f172a, #1e3a6e);
  border-radius: var(--dnv-radius-sm);
  padding: 16px;
  margin-bottom: 18px;
}
.dnv-aside-prazo--urgent {
  background: linear-gradient(135deg, #7f1d1d, #b91c1c);
}
.dnv-prazo-ring {
  position: relative;
  width: 44px;
  height: 44px;
  flex-shrink: 0;
}
.dnv-prazo-ring svg {
  width: 44px;
  height: 44px;
  display: block;
}
.dnv-prazo-num {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  font-weight: 900;
  color: #fff;
}
.dnv-prazo-info strong {
  display: block;
  font-size: 14px;
  font-weight: 700;
  color: #fff;
}
.dnv-prazo-info span {
  font-size: 12px;
  color: rgba(255,255,255,.55);
}

/* Garantias */
.dnv-garantias {
  list-style: none;
  padding: 0;
  margin: 0 0 16px;
  display: flex;
  flex-direction: column;
  gap: 9px;
}
.dnv-garantias li {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  font-size: 13px;
  color: #374151;
  line-height: 1.4;
}
.dnv-g-ico {
  width: 18px; height: 18px;
  border-radius: 50%;
  background: #f0fdf4;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; margin-top: 1px;
}
.dnv-g-ico svg { width: 9px; stroke: #16a34a; }

.dnv-sep {
  border: none;
  border-top: 1px solid var(--dnv-border);
  margin: 0 0 16px;
}

/* Resumo */
.dnv-resumo { margin-bottom: 14px; min-height: 32px; }
.dnv-resumo-empty {
  font-size: 13px;
  color: #94a3b8;
  text-align: center;
  margin: 0;
}
.dnv-resumo-items {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.dnv-resumo-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12.5px;
  color: var(--dnv-dark);
  animation: dnv-in .25s var(--dnv-ease);
}
.dnv-resumo-item img {
  width: 30px; height: 30px;
  object-fit: cover;
  border-radius: 5px;
  border: 1px solid var(--dnv-border);
  flex-shrink: 0;
}

/* Erro global */
.dnv-global-error {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  background: #fef2f2;
  border: 1px solid #fca5a5;
  border-radius: var(--dnv-radius-sm);
  padding: 10px 12px;
  font-size: 13px;
  color: #dc2626;
  margin-bottom: 12px;
}
.dnv-global-error svg { stroke: #dc2626; flex-shrink: 0; margin-top: 1px; }

/* Submit */
.dnv-submit {
  width: 100%;
  height: 46px;
  background: var(--dnv-accent);
  color: #fff;
  border: none;
  border-radius: var(--dnv-radius-sm);
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: background .15s, transform .2s var(--dnv-spring), box-shadow .15s;
  box-shadow: 0 2px 8px rgba(37,99,235,.25);
}
.dnv-submit:hover {
  background: #1d4ed8;
  box-shadow: 0 4px 16px rgba(37,99,235,.35);
  transform: translateY(-1px);
}
.dnv-submit:active { transform: scale(.98) translateY(0); }
.dnv-submit:disabled {
  background: #93c5fd;
  cursor: not-allowed;
  transform: none;
  box-shadow: none;
}
.dnv-spin { animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.dnv-cancel {
  display: block;
  text-align: center;
  font-size: 13px;
  color: #94a3b8;
  text-decoration: none;
  margin-top: 10px;
  padding: 6px;
  border-radius: 6px;
  transition: color .15s, background .15s;
}
.dnv-cancel:hover { color: var(--dnv-dark); background: var(--dnv-bg); }

/* ── Responsive ─────────────────────────────────── */
@media (max-width: 768px) {
  .dnv-layout         { grid-template-columns: 1fr; }
  .dnv-aside          { position: static; order: -1; }
  .dnv-aside-inner    { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .dnv-aside-prazo    { grid-column: 1/-1; }
  .dnv-garantias      { display: none; }
  .dnv-sep            { display: none; }
  .dnv-resumo         { display: none; }
  .dnv-submit, .dnv-cancel { margin-top: 0; }
  .dnv-tipo-grid      { grid-template-columns: 1fr; }
  .dnv-step           { padding: 16px; }
  .dnv-title          { font-size: 18px; }
}
</style>

<script>
</script>