<?php
// Partial: barra de busca por moto para categorias
// Variáveis esperadas: $montadoras, $motoFiltro, $categoria
?>
<div class="cat-moto-search-bar">
  <div class="container">
    <div class="cat-moto-search-inner">

      <div class="cat-moto-search-label">
        <div class="cat-moto-search-icon">
          <?= IconLibrary::render('two-wheeler', 'icon icon--md') ?>
        </div>
        <div>
          <span class="cat-moto-search-title">Buscar para sua moto</span>
          <span class="cat-moto-search-sub">
            Filtre por compatibilidade
          </span>
        </div>
      </div>

      <form class="cat-moto-search-form"
            method="GET"
            action="<?= BASE_URL ?>/categoria/<?= View::e($categoria['slug']) ?>">

        <?php
        // Mantém outros filtros GET ativos
        foreach ($_GET as $k => $v) {
            if (in_array($k, ['montadora_id','modelo_id','ano'])) continue;
            echo '<input type="hidden" name="' . View::e($k) . '"
                         value="' . View::e($v) . '">';
        }
        ?>

        <!-- Montadora -->
        <div class="cat-moto-select-wrap">
          <!-- Montadora: adicionar data-slug -->
          <select name="montadora_id" id="cat-busca-montadora" class="cat-moto-select" required>
            <option value="">Montadora</option>
            <?php foreach ($montadoras as $m): ?>
            <option value="<?= $m['id'] ?>"
                    data-slug="<?= View::e($m['slug']) ?>"
                    <?= (int)($motoFiltro['montadora_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
              <?= View::e($m['nome']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>

        <!-- Modelo -->
        <div class="cat-moto-select-wrap">
          <select name="modelo_id"
                  id="cat-busca-modelo"
                  class="cat-moto-select"
                  <?= empty($motoFiltro['montadora_id']) ? 'disabled' : '' ?>>
            <option value="">Modelo</option>
            <?php
            // Se tem montadora selecionada, carrega modelos
            if (!empty($motoFiltro['montadora_id'])):
                $db   = Database::getInstance()->getConnection();
                $stmt = $db->prepare(
                    "SELECT id, nome FROM moto_modelos
                     WHERE montadora_id = ? AND ativo = 1
                     ORDER BY nome ASC"
                );
                $stmt->execute([$motoFiltro['montadora_id']]);
                foreach ($stmt->fetchAll() as $mod):
            ?>
            <option value="<?= $mod['id'] ?>"
                    <?= (int)($motoFiltro['modelo_id'] ?? 0) === (int)$mod['id']
                        ? 'selected' : '' ?>>
              <?= View::e($mod['nome']) ?>
            </option>
            <?php endforeach; endif; ?>
          </select>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>

        <!-- Ano -->
        <div class="cat-moto-select-wrap">
          <select name="ano"
                  id="cat-busca-ano"
                  class="cat-moto-select"
                  <?= empty($motoFiltro['modelo_id']) ? 'disabled' : '' ?>>
            <option value="">Ano</option>
            <?php
            if (!empty($motoFiltro['modelo_id'])):
                $stmt = $db->prepare(
                    "SELECT DISTINCT ano FROM moto_anos
                     WHERE modelo_id = ? ORDER BY ano DESC"
                );
                $stmt->execute([$motoFiltro['modelo_id']]);
                foreach ($stmt->fetchAll() as $a):
            ?>
            <option value="<?= $a['ano'] ?>"
                    <?= (int)($motoFiltro['ano'] ?? 0) === (int)$a['ano']
                        ? 'selected' : '' ?>>
              <?= $a['ano'] ?>
            </option>
            <?php endforeach; endif; ?>
          </select>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>

        <button type="submit" class="cat-moto-search-btn">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          Filtrar
        </button>

        <?php if (!empty($motoFiltro['montadora_id'])): ?>
        <a href="<?= BASE_URL ?>/categoria/<?= View::e($categoria['slug']) ?>"
           class="cat-moto-limpar-btn">
          Limpar
        </a>
        <?php endif; ?>

      </form>

      <!-- Badge do filtro ativo -->
      <?php if (!empty($motoFiltro['montadora_id'])): ?>
      <?php
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT mm.nome AS montadora,
                    mo.nome AS modelo
             FROM moto_montadoras mm
             LEFT JOIN moto_modelos mo ON mo.id = ?
             WHERE mm.id = ? LIMIT 1"
        );
        $stmt->execute([$motoFiltro['modelo_id'], $motoFiltro['montadora_id']]);
        $motoInfo = $stmt->fetch();
      ?>
      <div class="cat-moto-filtro-ativo">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
        Filtrando:
        <strong>
          <?= View::e($motoInfo['montadora'] ?? '') ?>
          <?php if (!empty($motoInfo['modelo'])): ?>
          › <?= View::e($motoInfo['modelo']) ?>
          <?php endif; ?>
          <?php if (!empty($motoFiltro['ano'])): ?>
          › <?= $motoFiltro['ano'] ?>
          <?php endif; ?>
        </strong>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>