<?php
/**
 * Partial: características do produto.
 * Variáveis esperadas:
 *   $caracteristicasCategoria — array de características da categoria
 *   $mapaCaracteristicas      — [id => valor] dos valores salvos
 *   $p                        — produto atual
 */

$tiposIcons = [
    'texto'   => 'T',
    'numero'  => '#',
    'select'  => '≡',
    'boolean' => '◉',
    'textarea'=> '¶',
    'url'     => '🔗',
];
?>

<?php 

if (empty($caracteristicasCategoria)): ?>
<div class="char-vazio">
  <svg width="32" height="32" viewBox="0 0 24 24" fill="none"
       stroke="currentColor" stroke-width="1.2" stroke-linecap="round">
    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
    <polyline points="14 2 14 8 20 8"/>
    <line x1="16" y1="13" x2="8" y2="13"/>
    <line x1="16" y1="17" x2="8" y2="17"/>
    <polyline points="10 9 9 9 8 9"/>
  </svg>
  <p>Nenhuma característica configurada para esta categoria.</p>
  <a href="<?= BASE_URL ?>/admin/categorias" class="btn btn-ghost btn-sm">
    Configurar na categoria
  </a>
</div>

<?php else: ?>
<div class="char-grid" id="prod-chars-grid">
  <?php foreach ($caracteristicasCategoria as $c):
    $valor     = $mapaCaracteristicas[$c['id']] ?? '';
    $obrigatorio = $c['cat_obrigatorio'] ?? $c['obrigatorio'];
    $opcoes    = $c['opcoes'] ? json_decode($c['opcoes'], true) : [];
  ?>
  <div class="char-field <?= $obrigatorio ? 'char-field--required' : '' ?>">
    <label class="char-label">
      <?= View::e($c['nome']) ?>
      <?php if ($c['unidade']): ?>
        <span class="char-unidade">(<?= View::e($c['unidade']) ?>)</span>
      <?php endif; ?>
      <?php if ($obrigatorio): ?>
        <span class="pe-required">*</span>
      <?php endif; ?>
    </label>

    <?php if ($c['tipo'] === 'texto'): ?>
    <input type="text"
           name="caracteristicas[<?= $c['id'] ?>]"
           class="form-control"
           value="<?= View::e($valor) ?>"
           placeholder="<?= View::e($c['placeholder'] ?? '') ?>"
           <?= $obrigatorio ? 'required' : '' ?>>

    <?php elseif ($c['tipo'] === 'numero'): ?>
    <div class="char-numero-wrap">
      <input type="number"
             name="caracteristicas[<?= $c['id'] ?>]"
             class="form-control"
             value="<?= View::e($valor) ?>"
             step="any"
             placeholder="<?= View::e($c['placeholder'] ?? '0') ?>"
             <?= $obrigatorio ? 'required' : '' ?>>
      <?php if ($c['unidade']): ?>
      <span class="char-unidade-suffix"><?= View::e($c['unidade']) ?></span>
      <?php endif; ?>
    </div>

    <?php elseif ($c['tipo'] === 'select'): ?>
    <select name="caracteristicas[<?= $c['id'] ?>]"
            class="form-control"
            <?= $obrigatorio ? 'required' : '' ?>>
      <option value="">— Selecione —</option>
      <?php foreach ($opcoes as $opt): ?>
      <option value="<?= View::e($opt) ?>"
              <?= $valor === $opt ? 'selected' : '' ?>>
        <?= View::e($opt) ?>
      </option>
      <?php endforeach; ?>
    </select>

    <?php elseif ($c['tipo'] === 'boolean'): ?>
    <div class="char-boolean-group">
      <label class="char-bool-opt">
        <input type="radio"
               name="caracteristicas[<?= $c['id'] ?>]"
               value="Sim"
               <?= $valor === 'Sim' ? 'checked' : '' ?>>
        <span>Sim</span>
      </label>
      <label class="char-bool-opt">
        <input type="radio"
               name="caracteristicas[<?= $c['id'] ?>]"
               value="Não"
               <?= $valor === 'Não' ? 'checked' : '' ?>>
        <span>Não</span>
      </label>
      <?php if (!$obrigatorio): ?>
      <label class="char-bool-opt">
        <input type="radio"
               name="caracteristicas[<?= $c['id'] ?>]"
               value=""
               <?= $valor === '' ? 'checked' : '' ?>>
        <span>Não informado</span>
      </label>
      <?php endif; ?>
    </div>

    <?php elseif ($c['tipo'] === 'textarea'): ?>
    <textarea name="caracteristicas[<?= $c['id'] ?>]"
              class="form-control" rows="3"
              placeholder="<?= View::e($c['placeholder'] ?? '') ?>"
              <?= $obrigatorio ? 'required' : '' ?>><?= View::e($valor) ?></textarea>

    <?php elseif ($c['tipo'] === 'url'): ?>
    <input type="url"
           name="caracteristicas[<?= $c['id'] ?>]"
           class="form-control"
           value="<?= View::e($valor) ?>"
           placeholder="https://"
           <?= $obrigatorio ? 'required' : '' ?>>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>