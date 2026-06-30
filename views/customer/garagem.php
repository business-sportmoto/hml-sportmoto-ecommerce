<?php
$temMotos = !empty($motos);
?>

<div class="garagem-page garagem-actions">

  <!-- ── Header ─────────────────────────────────────────── -->
  <div class="garagem-header">
    <div>
      <h1 class="garagem-title">
        <?= IconLibrary::render('motorcycle', 'icon icon--md') ?>
        Minha Garagem
      </h1>
      <p class="garagem-sub">
        <?= $temMotos
            ? count($motos) . ' moto' . (count($motos) > 1 ? 's' : '') . ' cadastrada' . (count($motos) > 1 ? 's' : '')
            : 'Cadastre suas motos para ver peças compatíveis automaticamente.'
        ?>
      </p>
    </div>
    <button type="button" class="btn-add-moto" id="btn-add-moto">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5"  y1="12" x2="19" y2="12"/>
      </svg>
      Adicionar moto
    </button>
  </div>

  <!-- ── Estado vazio ───────────────────────────────────── -->
  <?php if (!$temMotos): ?>
  <div class="garagem-empty">
    <div class="garagem-empty-icon">
      <?php IconLibrary::render('motorcycle', 'icon icon--md') ?>
    </div>
    <h2>Sua garagem está vazia</h2>
    <p>
      Adicione sua primeira moto para que possamos<br>
      mostrar apenas peças compatíveis com ela.
    </p>
    <button type="button" class="btn-add-moto-large" id="btn-add-moto-empty">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5"  y1="12" x2="19" y2="12"/>
      </svg>
      Adicionar minha primeira moto
    </button>
  </div>
  <?php else: ?>

  <!-- ── Grid de motos ──────────────────────────────────── -->
  <div class="garagem-grid">
    <?php foreach ($motos as $m): ?>
    <article class="moto-card <?= $m['principal'] ? 'is-ativa' : '' ?>"
             data-id="<?= $m['id'] ?>">

      <a href="<?= BASE_URL ?>/minha-conta/garagem/moto/<?= $m['id'] ?>"
     class="moto-card-link" aria-label="Ver detalhes da moto">

      <?php if ($m['principal']): ?>
      <div class="moto-card-badge-ativa">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="3" stroke-linecap="round">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
        Moto ativa
      </div>
      <?php endif; ?>

      <!-- Visual: cor + thumb -->
      <div class="moto-card-visual"
           style="<?= !empty($m['cor']) ? 'background:linear-gradient(135deg,' . View::e($m['cor']) . ',' . ConfigHelper::darken($m['cor'], 25) . ')' : '' ?>">
        <?php if (!empty($m['modelo_thumb'])): ?>
        <img src="<?= View::upload('motos/' . $m['modelo_thumb']) ?>"
             alt="<?= View::e($m['modelo_nome']) ?>"
             class="moto-card-thumb">
        <?php elseif (!empty($m['montadora_thumb'])): ?>
        <img src="<?= View::upload('motos/' . $m['montadora_thumb']) ?>"
             alt="<?= View::e($m['montadora_nome']) ?>"
             class="moto-card-thumb moto-card-thumb--logo">
        <?php else: ?>
        <?= IconLibrary::render('motorcycle', 'icon icon--md moto-card-icon') ?>
        <?php endif; ?>
      </div>

      <!-- Info -->
      <div class="moto-card-body">
        <?php if (!empty($m['apelido'])): ?>
        <h3 class="moto-card-apelido"><?= View::e($m['apelido']) ?></h3>
        <p class="moto-card-modelo"><?= View::e($m['label']) ?></p>
        <?php else: ?>
        <h3 class="moto-card-apelido"><?= View::e($m['montadora_nome']) ?></h3>
        <p class="moto-card-modelo">
          <?php if ($m['modelo_nome']): ?>
            <?= View::e($m['modelo_nome']) ?>
            <?php if ($m['ano']): ?> · <?= $m['ano'] ?><?php endif; ?>
          <?php else: ?>
            Todos os modelos
          <?php endif; ?>
        </p>
        <?php endif; ?>

        <?php if (!empty($m['placa'])): ?>
        <div class="moto-card-placa">
          <span><?= View::e(strtoupper($m['placa'])) ?></span>
        </div>
        <?php endif; ?>
      </div>
      </a>
      <!-- Ações -->
      <div class="moto-card-actions">
        <!-- No moto-card-actions, antes do botão de editar: -->
        <button type="button" class="btn-fotos-moto" data-id="<?= $m['id'] ?>"
                data-label="<?= View::e($m['label']) ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <rect x="3" y="3" width="18" height="18" rx="2"/>
            <circle cx="8.5" cy="8.5" r="1.5"/>
            <polyline points="21 15 16 10 5 21"/>
          </svg>
          Fotos
        </button>
        <?php if (!$m['principal']): ?>
        <button type="button" class="btn-ativar-moto" data-id="<?= $m['id'] ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          Tornar ativa
        </button>
        <?php endif; ?>
        <button type="button" class="btn-editar-moto" data-id="<?= $m['id'] ?>"
                data-apelido="<?= View::e($m['apelido'] ?? '') ?>"
                data-cor="<?= View::e($m['cor'] ?? '') ?>"
                data-placa="<?= View::e($m['placa'] ?? '') ?>"
                data-observacoes="<?= View::e($m['observacoes'] ?? '') ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
          Editar
        </button>
        <button type="button" class="btn-remover-moto" data-id="<?= $m['id'] ?>"
                data-label="<?= View::e($m['label']) ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
          </svg>
        </button>
        
      </div>

    </article>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>

</div>


<!-- ══ MODAL: Adicionar moto ══════════════════════════════ -->
<div class="garagem-modal" id="modal-add-moto">
  <div class="garagem-modal-backdrop"></div>
  <div class="garagem-modal-content">

    <div class="garagem-modal-header">
      <h2>Adicionar moto à garagem</h2>
      <button type="button" class="garagem-modal-close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6"  y2="18"/>
          <line x1="6"  y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <form id="form-add-moto" class="garagem-form">
      <input type="hidden" name="_csrf_token" value="<?= SecurityHelper::generateCsrf() ?>">

      <!-- Etapa 1: Dados da moto -->
      <div class="garagem-form-section">
        <h3 class="garagem-form-section-title">Dados da moto</h3>

        <div class="garagem-form-row garagem-form-row--3">
          <div class="garagem-form-group">
            <label>Montadora *</label>
            <div class="garagem-select-wrap">
              <select name="montadora_id" id="add-montadora" required>
                <option value="">Selecione...</option>
                <?php foreach ($montadoras as $m): ?>
                <option value="<?= $m['id'] ?>"><?= View::e($m['nome']) ?></option>
                <?php endforeach; ?>
              </select>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </div>
          </div>

          <div class="garagem-form-group">
            <label>Modelo</label>
            <div class="garagem-select-wrap">
              <select name="modelo_id" id="add-modelo" disabled>
                <option value="">Todos os modelos</option>
              </select>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </div>
          </div>

          <div class="garagem-form-group">
            <label>Ano</label>
            <div class="garagem-select-wrap">
              <select name="ano" id="add-ano" disabled>
                <option value="">Todos os anos</option>
              </select>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </div>
          </div>
        </div>
      </div>

      <!-- Etapa 2: Personalização -->
      <div class="garagem-form-section">
        <h3 class="garagem-form-section-title">Personalização</h3>

        <div class="garagem-form-group">
          <label>Apelido</label>
          <input type="text" name="apelido" maxlength="80"
                 placeholder='Ex: "Minha CG", "A Vermelha"'>
          <small>Como você quer chamar essa moto?</small>
        </div>

        <div class="garagem-form-row garagem-form-row--2">
          <div class="garagem-form-group">
            <label>Cor</label>
            <div class="garagem-color-picker">
              <input type="color" name="cor" value="#dc2626">
              <div class="garagem-color-presets">
                <?php $presets = ['#dc2626','#1e293b','#2563eb','#16a34a','#f59e0b','#7c3aed','#ec4899','#ffffff']; ?>
                <?php foreach ($presets as $p): ?>
                <button type="button" class="garagem-color-preset"
                        style="background:<?= $p ?>"
                        data-cor="<?= $p ?>"></button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="garagem-form-group">
            <label>Placa (opcional)</label>
            <input type="text" name="placa" maxlength="10"
                   placeholder="ABC-1234" style="text-transform:uppercase">
          </div>
        </div>

        <label class="garagem-check">
          <input type="checkbox" name="tornar_ativo" value="1" checked>
          <span>Tornar moto ativa</span>
          <small>Ao navegar pelo site, vamos mostrar peças compatíveis com esta moto</small>
        </label>
      </div>

      <div class="garagem-modal-footer">
        <button type="button" class="btn-cancelar garagem-modal-close">Cancelar</button>
        <button type="submit" class="btn-salvar-moto">Adicionar à garagem</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL: Editar moto ═════════════════════════════════ -->
<div class="garagem-modal" id="modal-edit-moto">
  <div class="garagem-modal-backdrop"></div>
  <div class="garagem-modal-content">

    <div class="garagem-modal-header">
      <h2>Editar moto</h2>
      <button type="button" class="garagem-modal-close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6"  y2="18"/>
          <line x1="6"  y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <form id="form-edit-moto" class="garagem-form">
      <input type="hidden" name="_csrf_token" value="<?= SecurityHelper::generateCsrf() ?>">
      <input type="hidden" name="id" id="edit-id">

      <div class="garagem-form-section">
        <div class="garagem-form-group">
          <label>Apelido</label>
          <input type="text" name="apelido" id="edit-apelido" maxlength="80">
        </div>

        <div class="garagem-form-row garagem-form-row--2">
          <div class="garagem-form-group">
            <label>Cor</label>
            <div class="garagem-color-picker">
              <input type="color" name="cor" id="edit-cor" value="#dc2626">
              <div class="garagem-color-presets">
                <?php foreach ($presets as $p): ?>
                <button type="button" class="garagem-color-preset"
                        style="background:<?= $p ?>"
                        data-cor="<?= $p ?>" data-target="#edit-cor"></button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="garagem-form-group">
            <label>Placa</label>
            <input type="text" name="placa" id="edit-placa" maxlength="10"
                   style="text-transform:uppercase">
          </div>
        </div>

        <div class="garagem-form-group">
          <label>Observações</label>
          <textarea name="observacoes" id="edit-observacoes" rows="2"
                    placeholder="Anotações sobre a moto (opcional)"></textarea>
        </div>
      </div>

      <div class="garagem-modal-footer">
        <button type="button" class="btn-cancelar garagem-modal-close">Cancelar</button>
        <button type="submit" class="btn-salvar-moto">Salvar alterações</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ DRAWER: Galeria da moto ════════════════════════════ -->
<div class="garagem-modal" id="modal-fotos-moto">
  <div class="garagem-modal-backdrop"></div>
  <div class="garagem-modal-content garagem-modal-content--lg">

    <div class="garagem-modal-header">
      <div>
        <h2>Galeria da moto</h2>
        <span id="fotos-moto-label" class="garagem-modal-sub"></span>
      </div>
      <button type="button" class="garagem-modal-close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6"  y2="18"/>
          <line x1="6"  y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <input type="hidden" id="fotos-veiculo-id">

    <!-- Upload zone -->
    <div class="fotos-upload-zone" id="fotos-upload-zone">
      <input type="file" id="fotos-input" accept="image/jpeg,image/png,image/webp" multiple hidden>

      <div class="fotos-upload-empty" id="fotos-upload-empty">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
          <polyline points="17 8 12 3 7 8"/>
          <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        <p><strong>Clique ou arraste fotos aqui</strong></p>
        <small>JPG, PNG, WEBP · até 8MB · máx. 10 fotos</small>
      </div>

      <div class="fotos-upload-opts">
        <label class="fotos-vis-opt">
          <input type="radio" name="vis_default" value="privado" checked>
          <div>
            <span>🔒 Privadas</span>
            <small>Só você vê</small>
          </div>
        </label>
        <label class="fotos-vis-opt">
          <input type="radio" name="vis_default" value="publico">
          <div>
            <span>🌐 Públicas</span>
            <small>Após aprovação</small>
          </div>
        </label>
      </div>
    </div>

    <!-- Grid de fotos -->
    <div class="fotos-grid" id="fotos-grid"></div>

    <!-- Estado de carregamento -->
    <div class="fotos-loading" id="fotos-loading" hidden>
      <div class="spinner"></div>
      <span>Processando fotos...</span>
    </div>

  </div>
</div>

<?php
// Helper inline simples (poderia ir num helper)

?>