<?php
$key       = isset($item['id']) && $item['id'] > 0 ? (string)$item['id'] : ($key ?? 'new_0');
$isNovo    = str_starts_with($key, 'new_');

// Monta resumo para o header
$partes = [$item['montadora_nome'] ?? 'Nova compatibilidade'];
if (!empty($item['modelo_nome'])) $partes[] = $item['modelo_nome'];
if (!empty($item['ano_inicio'])) {
    $partes[] = ($item['ano_inicio'] == $item['ano_fim'])
                ? $item['ano_inicio']
                : ($item['ano_inicio'] . '–' . ($item['ano_fim'] ?? 'atual'));
}
$resumo = implode(' › ', $partes);
?>

<div class="compat-item" data-key="<?= $key ?>">

  <!-- Header clicável -->
  <div class="compat-item-header" onclick="toggleCompatItem(this)">
    <div class="compat-item-moto">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <circle cx="5.5" cy="17.5" r="3.5"/>
        <circle cx="18.5" cy="17.5" r="3.5"/>
        <path d="M15 6h-2l-3 8H5.5"/>
        <path d="M15 6l3 5h1.5"/>
        <path d="M9 6h4"/>
      </svg>
      <span class="compat-item-resumo"><?= View::e($resumo) ?></span>
    </div>

    <!-- Chevron -->
    <svg class="compat-item-chevron" width="14" height="14"
         viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.5" stroke-linecap="round">
      <polyline points="6 9 12 15 18 9"/>
    </svg>

    <!-- Excluir (stopPropagation para não abrir o accordion) -->
    <button type="button"
            class="compat-item-del btn btn-xs btn-ghost"
            onclick="event.stopPropagation(); removerCompatItem(this)"
            title="Remover">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="18" y1="6" x2="6"  y2="18"/>
        <line x1="6"  y1="6" x2="18" y2="18"/>
      </svg>
    </button>
  </div>

  <!-- Body accordion -->
  <div class="compat-item-body <?= $isNovo ? 'open' : '' ?>">
    <div class="compat-item-fields">

      <div class="form-group">
        <label class="pe-label">Montadora</label>
        <select name="compatibilidades[<?= $key ?>][montadora_id]"
                class="form-control form-control--sm compat-montadora-sel"
                data-key="<?= $key ?>" required>
          <option value="">— Selecione —</option>
          <?php foreach ($montadoras as $mt): ?>
          <option value="<?= $mt['id'] ?>"
                  <?= (int)($item['montadora_id'] ?? 0) === (int)$mt['id'] ? 'selected' : '' ?>>
            <?= View::e($mt['nome']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="pe-label">Modelo</label>
        <select name="compatibilidades[<?= $key ?>][modelo_id]"
                class="form-control form-control--sm compat-modelo-sel"
                data-key="<?= $key ?>">
          <option value="">Todos os modelos</option>
          <?php
          if (!empty($item['montadora_id']) && !empty($item['modelo_id'])):
              $stmtMods = Database::getInstance()->getConnection()->prepare(
                  "SELECT id, nome FROM moto_modelos
                   WHERE montadora_id=? AND ativo=1 ORDER BY nome ASC"
              );
              $stmtMods->execute([$item['montadora_id']]);
              foreach ($stmtMods->fetchAll() as $mod):
          ?>
          <option value="<?= $mod['id'] ?>"
                  <?= (int)($item['modelo_id'] ?? 0) === (int)$mod['id'] ? 'selected' : '' ?>>
            <?= View::e($mod['nome']) ?>
          </option>
          <?php endforeach; endif; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="pe-label">Ano início</label>
        <input type="number"
               name="compatibilidades[<?= $key ?>][ano_inicio]"
               class="form-control form-control--sm"
               value="<?= View::e($item['ano_inicio'] ?? '') ?>"
               placeholder="2015" min="1960" max="2030">
      </div>

      <div class="form-group">
        <label class="pe-label">Ano fim</label>
        <input type="number"
               name="compatibilidades[<?= $key ?>][ano_fim]"
               class="form-control form-control--sm"
               value="<?= View::e($item['ano_fim'] ?? '') ?>"
               placeholder="2023" min="1960" max="2030">
      </div>

      <div class="form-group compat-obs-group">
        <label class="pe-label">Observação</label>
        <input type="text"
               name="compatibilidades[<?= $key ?>][observacao]"
               class="form-control form-control--sm"
               value="<?= View::e($item['observacao'] ?? '') ?>"
               placeholder="Ex: exceto modelos com ABS">
      </div>

    </div>
  </div>
</div>

<script>
// ── Accordion de compatibilidade ──────────────────────────

// Abre/fecha o body
function toggleCompatItem(header) {
  const body = header.closest('.compat-item')
                     .querySelector('.compat-item-body');
  const isOpen = body.classList.contains('open');

  // Fecha todos os outros (one-at-a-time opcional — remova se quiser vários abertos)
  document.querySelectorAll('.compat-item-body.open').forEach(b => {
    if (b !== body) b.classList.remove('open');
  });

  body.classList.toggle('open', !isOpen);
}

// Remove o item com animação
function removerCompatItem(btn) {
  const item = btn.closest('.compat-item');
  item.style.overflow  = 'hidden';
  item.style.maxHeight = item.offsetHeight + 'px';

  requestAnimationFrame(() => {
    item.style.transition = 'max-height .25s, opacity .2s, margin .25s';
    item.style.maxHeight  = '0';
    item.style.opacity    = '0';
    item.style.marginBottom = '0';
  });

  setTimeout(() => {
    item.remove();
    // Mostra estado vazio se não sobrou nenhum
    if (!document.querySelectorAll('.compat-item').length) {
      document.getElementById('compat-list').insertAdjacentHTML('beforeend', `
        <div class="compat-empty" id="compat-empty">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="1.2" stroke-linecap="round">
            <circle cx="12" cy="12" r="10"/>
            <path d="M12 8v4M12 16h.01"/>
          </svg>
          <span>Sem compatibilidade configurada</span>
        </div>`);
    }
  }, 260);
}



// Ao adicionar novo: abre já expandido
// No buildCompatItemHTML do IIFE existente, substitua a geração do body por:
// <div class="compat-item-body open"> (já adicionado no partial)
</script>