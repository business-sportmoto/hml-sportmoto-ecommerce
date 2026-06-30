<?php
// ════════════════════════════════════════════════════════
// admin/views/banners/_form-extras.php
// Seção extra do form de banner:
//   - Seletor de ícone do badge
//   - Campo de texto do badge (titulo_overlay)
//   - Configuração do countdown (data_fim)
//
// Incluir dentro do form principal de banners:
//   View::partial('banners/_form-extras', ['banner' => $banner])
// ════════════════════════════════════════════════════════
$b = $banner ?? [];
$isEdit = !empty($b);
?>

<!-- ═══════════════════════════════════════════════════
     BADGE PILL
═══════════════════════════════════════════════════ -->
<div class="admin-card">
  <div class="admin-card-header">
    <h3>Badge / Etiqueta</h3>
    <small style="color:var(--text-3);font-size:11px;">Opcional — pill acima do título</small>
  </div>
  <div class="admin-card-body">

    <!-- Seletor de ícone -->
    <div class="form-group">
      <label>Ícone do badge</label>
      <div class="bn-icon-grid" id="bn-icon-grid">
        <?php
        $icones = [
          'flame'     => ['label'=>'Promoção',  'svg'=>'<path d="M12 2c0 0-5 5-5 10a5 5 0 0010 0C17 7 12 2 12 2z"/><path d="M12 12c0 0-2 2-2 4a2 2 0 004 0c0-2-2-4-2-4z"/>'],
          'lightning' => ['label'=>'Relâmpago', 'svg'=>'<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'],
          'star'      => ['label'=>'Destaque',  'svg'=>'<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>'],
          'percent'   => ['label'=>'Desconto',  'svg'=>'<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>'],
          'tag'       => ['label'=>'Coleção',   'svg'=>'<path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>'],
          'mountain'  => ['label'=>'Adventure', 'svg'=>'<polygon points="3 17 8 7 13 12 16 8 21 17"/><polyline points="3 17 21 17"/>'],
          'gift'      => ['label'=>'Presente',  'svg'=>'<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5" rx="1"/><path d="M12 22V7m0 0H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7zm0 0h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/>'],
          'truck'     => ['label'=>'Entrega',   'svg'=>'<rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>'],
          'moto'      => ['label'=>'Moto',      'svg'=>'<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6h-2l-3 8H5.5M15 6l3 5h1.5"/>'],
          'clock'     => ['label'=>'Tempo',     'svg'=>'<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'],
          'none'      => ['label'=>'Sem ícone', 'svg'=>'<circle cx="12" cy="12" r="10" stroke-dasharray="4 4"/>'],
        ];
        $iconeSelecionado = $isEdit ? ($b['nome_publico'] ?? 'none') : 'none';
        foreach ($icones as $key => $ic):
        ?>
        <label class="bn-icon-opt <?= $iconeSelecionado === $key ? 'is-selected' : '' ?>">
          <input type="radio" name="nome_publico" value="<?= $key ?>"
                 <?= $iconeSelecionado === $key ? 'checked' : '' ?>>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <?= $ic['svg'] ?>
          </svg>
          <span><?= $ic['label'] ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Texto do badge -->
    <div class="form-group">
      <label for="bn-titulo-overlay">Texto do badge</label>
      <input type="text" id="bn-titulo-overlay" name="titulo_overlay"
             class="form-control" maxlength="60"
             value="<?= $isEdit ? View::e($b['titulo_overlay'] ?? '') : '' ?>"
             placeholder="Ex: PROMOÇÃO RELÂMPAGO">
      <small class="form-help">
        Aparece em maiúsculas na pill acima do título.
        Deixe vazio para não exibir o badge.
      </small>
    </div>

    <!-- Preview ao vivo do badge -->
    <div id="bn-badge-preview-wrap" style="margin-top:10px;">
      <label style="font-size:11px;color:var(--text-3);font-weight:700;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px;">
        Preview
      </label>
      <div id="bn-badge-preview"
           style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;
                  background:#1e293b;border:1px solid rgba(255,255,255,.2);
                  border-radius:99px;font-size:11px;font-weight:800;
                  letter-spacing:.7px;text-transform:uppercase;color:#fff;
                  min-width:80px;">
        <svg id="bn-badge-icon-preview" width="13" height="13"
             viewBox="0 0 24 24" fill="none" stroke="white"
             stroke-width="1.8" stroke-linecap="round"></svg>
        <span id="bn-badge-text-preview">BADGE</span>
      </div>
    </div>

  </div>
</div>


<!-- ═══════════════════════════════════════════════════
     COUNTDOWN
═══════════════════════════════════════════════════ -->
<div class="admin-card">
  <div class="admin-card-header">
    <h3>Contador regressivo</h3>
    <small style="color:var(--text-3);font-size:11px;">Opcional — aparece se data_fim estiver no futuro</small>
  </div>
  <div class="admin-card-body">

    <label class="form-toggle" id="bn-countdown-toggle">
      <input type="checkbox" id="bn-tem-countdown"
             <?= ($isEdit && !empty($b['data_fim'])) ? 'checked' : '' ?>>
      <span>Exibir contador regressivo</span>
      <small>Mostra DIAS · HORAS · MIN · SEG sobre o banner</small>
    </label>

    <div id="bn-countdown-fields"
         style="margin-top:14px;<?= ($isEdit && !empty($b['data_fim'])) ? '' : 'display:none' ?>">

      <div class="form-row">
        <div class="form-group">
          <label for="bn-data-inicio">Início da exibição</label>
          <input type="datetime-local" id="bn-data-inicio" name="data_inicio"
                 class="form-control"
                 value="<?= $isEdit && !empty($b['data_inicio']) ? date('Y-m-d\TH:i', strtotime($b['data_inicio'])) : '' ?>">
          <small class="form-help">Banner só aparece a partir desta data/hora.</small>
        </div>
        <div class="form-group">
          <label for="bn-data-fim">Termina em *</label>
          <input type="datetime-local" id="bn-data-fim" name="data_fim"
                 class="form-control"
                 value="<?= $isEdit && !empty($b['data_fim']) ? date('Y-m-d\TH:i', strtotime($b['data_fim'])) : '' ?>">
          <small class="form-help">
            O contador regride até esta data.
            Banner some automaticamente quando expirar.
          </small>
        </div>
      </div>

      <!-- Preview do contador com tempo calculado -->
      <div id="bn-countdown-preview"
           style="background:#1e293b;padding:14px 18px;border-radius:10px;
                  display:flex;flex-direction:column;gap:8px;margin-top:4px;">
        <div style="font-size:11px;color:rgba(255,255,255,.5);font-weight:700;letter-spacing:.6px;
                    text-transform:uppercase;display:flex;align-items:center;gap:6px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="rgba(255,255,255,.5)" stroke-width="2" stroke-linecap="round">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
          </svg>
          TERMINA EM
        </div>
        <div style="display:flex;gap:8px;">
          <?php foreach (['DIAS','HORAS','MIN','SEG'] as $u): ?>
          <div style="flex:1;background:rgba(255,255,255,.12);border-radius:8px;padding:10px 6px;
                      text-align:center;">
            <div id="bn-prev-<?= strtolower($u) ?>"
                 style="font-size:22px;font-weight:900;color:#fff;font-variant-numeric:tabular-nums;">--</div>
            <div style="font-size:9px;font-weight:800;color:rgba(255,255,255,.5);
                        letter-spacing:.6px;text-transform:uppercase;margin-top:2px;"><?= $u ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════
     BOTÕES CTA — Pré-visualização de estilos
═══════════════════════════════════════════════════ -->
<div class="admin-card">
  <div class="admin-card-header"><h3>Botões de ação (CTA)</h3></div>
  <div class="admin-card-body">

    <div class="form-row">
      <!-- CTA 1 -->
      <div>
        <p style="font-size:12px;font-weight:800;color:var(--text-2);margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px;">
          Botão primário
        </p>
        <div class="form-group">
          <label>Texto</label>
          <input type="text" name="cta1_texto" class="form-control" maxlength="80"
                 value="<?= $isEdit ? View::e($b['cta1_texto'] ?? '') : '' ?>"
                 placeholder="Ex: Ver ofertas">
        </div>
        <div class="form-group">
          <label>Link</label>
          <input type="text" name="cta1_link" class="form-control" maxlength="500"
                 value="<?= $isEdit ? View::e($b['cta1_link'] ?? '') : '' ?>"
                 placeholder="https://...">
        </div>
        <div class="form-group">
          <label>Estilo</label>
          <div class="bn-cta-style-grid">
            <?php
            $estilos = [
              'primary'   => 'Escuro (padrão)',
              'outline'   => 'Contorno',
              'ghost'     => 'Mínimo',
              'secondary' => 'Colorido',
            ];
            $cta1Estilo = $isEdit ? ($b['cta1_estilo'] ?? 'primary') : 'primary';
            foreach ($estilos as $val => $lbl):
            ?>
            <label class="bn-cta-style-opt <?= $cta1Estilo === $val ? 'is-selected' : '' ?>">
              <input type="radio" name="cta1_estilo" value="<?= $val ?>"
                     <?= $cta1Estilo === $val ? 'checked' : '' ?>>
              <?= $lbl ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group">
          <label>Destino</label>
          <select name="cta1_target" class="form-control">
            <option value="_self"  <?= ($b['cta1_target']??'_self') === '_self'  ? 'selected':'' ?>>Mesma aba</option>
            <option value="_blank" <?= ($b['cta1_target']??'_self') === '_blank' ? 'selected':'' ?>>Nova aba</option>
          </select>
        </div>
      </div>

      <!-- CTA 2 -->
      <div>
        <p style="font-size:12px;font-weight:800;color:var(--text-2);margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px;">
          Botão secundário <span style="font-weight:400;color:var(--text-3)">(opcional)</span>
        </p>
        <div class="form-group">
          <label>Texto</label>
          <input type="text" name="cta2_texto" class="form-control" maxlength="80"
                 value="<?= $isEdit ? View::e($b['cta2_texto'] ?? '') : '' ?>"
                 placeholder="Ex: Ver coleção">
        </div>
        <div class="form-group">
          <label>Link</label>
          <input type="text" name="cta2_link" class="form-control" maxlength="500"
                 value="<?= $isEdit ? View::e($b['cta2_link'] ?? '') : '' ?>"
                 placeholder="https://...">
        </div>
        <div class="form-group">
          <label>Estilo</label>
          <div class="bn-cta-style-grid">
            <?php
            $cta2Estilo = $isEdit ? ($b['cta2_estilo'] ?? 'outline') : 'outline';
            foreach ($estilos as $val => $lbl):
            ?>
            <label class="bn-cta-style-opt <?= $cta2Estilo === $val ? 'is-selected' : '' ?>">
              <input type="radio" name="cta2_estilo" value="<?= $val ?>"
                     <?= $cta2Estilo === $val ? 'checked' : '' ?>>
              <?= $lbl ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group">
          <label>Destino</label>
          <select name="cta2_target" class="form-control">
            <option value="_self"  <?= ($b['cta2_target']??'_self') === '_self'  ? 'selected':'' ?>>Mesma aba</option>
            <option value="_blank" <?= ($b['cta2_target']??'_self') === '_blank' ? 'selected':'' ?>>Nova aba</option>
          </select>
        </div>
      </div>
    </div>

  </div>
</div>

<style>
/* ── Seletor de ícone ──────────────────────────────── */
.bn-icon-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(70px, 1fr));
  gap: 6px;
}
.bn-icon-opt {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  padding: 10px 6px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  cursor: pointer;
  font-size: 10.5px;
  font-weight: 700;
  color: var(--text-3);
  text-align: center;
  background: var(--bg);
  transition: all .15s;
}
.bn-icon-opt input[type="radio"] { display: none; }
.bn-icon-opt svg { width: 20px; height: 20px; color: var(--text-2); transition: color .15s; }
.bn-icon-opt:hover { border-color: var(--blue); color: var(--blue); }
.bn-icon-opt:hover svg { color: var(--blue); }
.bn-icon-opt.is-selected {
  border-color: var(--blue);
  background: #eff6ff;
  color: var(--blue);
}
.bn-icon-opt.is-selected svg { color: var(--blue); }

/* ── Seletor de estilo do CTA ──────────────────────── */
.bn-cta-style-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 5px;
}
.bn-cta-style-opt {
  padding: 7px 10px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 700;
  color: var(--text-2);
  text-align: center;
  background: var(--bg);
  transition: all .15s;
}
.bn-cta-style-opt input[type="radio"] { display: none; }
.bn-cta-style-opt:hover { border-color: var(--blue); color: var(--blue); }
.bn-cta-style-opt.is-selected { border-color: var(--blue); background: #eff6ff; color: var(--blue); }
</style>
