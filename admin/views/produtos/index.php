<?php
// admin/views/produtos/index.php

$db = Database::getInstance()->getConnection();

$marcas = $db->query(
    "SELECT id, nome FROM marcas WHERE ativo=1 ORDER BY nome ASC"
)->fetchAll();

$categorias = $db->query(
    "SELECT id, nome, parent_id FROM categorias WHERE ativo=1 ORDER BY parent_id ASC, nome ASC"
)->fetchAll();
$catMap = array_column($categorias, null, 'id');

$atributos = $db->query(
    "SELECT at.id, at.nome, at.papel,
            GROUP_CONCAT(av.valor ORDER BY av.ordem SEPARATOR '||') AS valores
     FROM atributo_tipos at
     LEFT JOIN atributo_valores av ON av.atributo_tipo_id = at.id
     GROUP BY at.id
     ORDER BY at.papel ASC, at.ordenacao ASC"
)->fetchAll();

$filtrosAtivos = array_filter([
    'q'            => $_GET['q']            ?? '',
    'marca_id'     => $_GET['marca_id']     ?? '',
    'categoria_id' => $_GET['categoria_id'] ?? '',
    'tem_variacao' => $_GET['tem_variacao'] ?? '',
    'estoque'      => $_GET['estoque']      ?? '',
    'status'       => $_GET['status']       ?? '',
]);
$totalFiltros = count($filtrosAtivos);
?>

<div class="admin-page prod-list-page">

  <!-- ── Topbar ─────────────────────────────────────────── -->
  <div class="prod-list-topbar">
    <div class="prod-list-topbar-left">
      <h1 class="prod-list-titulo">Produtos</h1>
      <span class="prod-list-count">
        <?= number_format($total) ?> produto<?= $total != 1 ? 's' : '' ?>
      </span>
    </div>
    <div class="prod-list-topbar-right">
      <a href="<?= BASE_URL ?>/admin/importar" class="btn btn-dark btn-sm">
        <?= IconLibrary::render('upload') ?> 
        Importar
      </a>
      <button type="button" class="btn btn-outline btn-sm" id="btn-editar-massa">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
          <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
        </svg>
        Editar em massa
        <span class="prod-massa-count" id="massa-count" style="display:none;">0</span>
      </button>
      <a href="<?= BASE_URL ?>/admin/produtos/criar" class="btn btn-primary btn-sm">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="12" y1="5" x2="12" y2="19"/>
          <line x1="5"  y1="12" x2="19" y2="12"/>
        </svg>
        Novo produto
      </a>
    </div>
  </div>

  <!-- ── Filtros ────────────────────────────────────────── -->
  <div class="prod-filters-wrap">
    <form method="GET" id="form-filtros">
      <div class="prod-filters-row">

        <div class="prod-search-wrap">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" name="q"
                 class="prod-search-input"
                 value="<?= View::e($_GET['q'] ?? '') ?>"
                 placeholder="Nome, SKU, marca...">
        </div>

        <button type="button"
                class="prod-filter-toggle <?= $totalFiltros > 0 ? 'has-filters' : '' ?>"
                id="btn-toggle-filters">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
          </svg>
          Filtros
          <?php if ($totalFiltros > 0): ?>
          <span class="prod-filter-badge"><?= $totalFiltros ?></span>
          <?php endif; ?>
        </button>

        <button type="submit" class="btn btn-primary btn-sm">Buscar</button>

        <?php if ($totalFiltros > 0): ?>
        <a href="<?= BASE_URL ?>/admin/produtos"
           class="btn btn-ghost btn-sm">Limpar</a>
        <?php endif; ?>
      </div>

      <!-- Filtros avançados -->
      <div class="prod-filters-advanced <?= $totalFiltros > 0 ? 'open' : '' ?>"
           id="prod-filters-advanced">
        <div class="prod-filters-grid">

          <div class="prod-filter-group">
            <label class="prod-filter-label">Marca</label>
            <select name="marca_id" class="form-control form-control--sm">
              <option value="">Todas</option>
              <?php foreach ($marcas as $m): ?>
              <option value="<?= $m['id'] ?>"
                      <?= ($_GET['marca_id'] ?? '') == $m['id'] ? 'selected' : '' ?>>
                <?= View::e($m['nome']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="prod-filter-group">
            <label class="prod-filter-label">Categoria</label>
            <select name="categoria_id" class="form-control form-control--sm">
              <option value="">Todas</option>
              <?php
              function renderFilterCats(array $cats, ?int $pid = null, int $d = 0): void {
                  foreach ($cats as $c) {
                      if ((int)($c['parent_id']??0) !== (int)$pid) continue;
                      $sel = (($_GET['categoria_id']??'')==$c['id']) ? 'selected' : '';
                      $pre = str_repeat('&nbsp;&nbsp;',$d) . ($d>0?'└ ':'');
                      echo "<option value=\"{$c['id']}\" {$sel}>{$pre}".htmlspecialchars($c['nome'])."</option>";
                      renderFilterCats($cats,(int)$c['id'],$d+1);
                  }
              }
              renderFilterCats($categorias, null);
              ?>
            </select>
          </div>

          <div class="prod-filter-group">
            <label class="prod-filter-label">Status</label>
            <select name="status" class="form-control form-control--sm">
              <option value="">Todos</option>
              <option value="ativo"    <?= ($_GET['status']??'')==='ativo'    ? 'selected':'' ?>>Ativo</option>
              <option value="inativo"  <?= ($_GET['status']??'')==='inativo'  ? 'selected':'' ?>>Inativo</option>
              <option value="destaque" <?= ($_GET['status']??'')==='destaque' ? 'selected':'' ?>>Destaque</option>
            </select>
          </div>

          <div class="prod-filter-group">
            <label class="prod-filter-label">Estoque</label>
            <select name="estoque" class="form-control form-control--sm">
              <option value="">Qualquer</option>
              <option value="ok"    <?= ($_GET['estoque']??'')==='ok'    ? 'selected':'' ?>>Em estoque</option>
              <option value="baixo" <?= ($_GET['estoque']??'')==='baixo' ? 'selected':'' ?>>Baixo</option>
              <option value="zero"  <?= ($_GET['estoque']??'')==='zero'  ? 'selected':'' ?>>Zerado</option>
            </select>
          </div>

          <div class="prod-filter-group">
            <label class="prod-filter-label">Tipo</label>
            <select name="tem_variacao" class="form-control form-control--sm">
              <option value="">Todos</option>
              <option value="0" <?= ($_GET['tem_variacao']??'')==='0' ? 'selected':'' ?>>Simples</option>
              <option value="1" <?= ($_GET['tem_variacao']??'')==='1' ? 'selected':'' ?>>Com variações</option>
            </select>
          </div>

          <?php foreach ($atributos as $at):
            if (!$at['valores']) continue;
            $vals = explode('||', $at['valores']);
            $key  = 'attr_' . $at['id'];
          ?>
          <div class="prod-filter-group">
            <label class="prod-filter-label"><?= View::e($at['nome']) ?></label>
            <select name="<?= $key ?>" class="form-control form-control--sm">
              <option value="">Qualquer</option>
              <?php foreach ($vals as $v): ?>
              <option value="<?= View::e($v) ?>"
                      <?= ($_GET[$key]??'') === $v ? 'selected':'' ?>>
                <?= View::e($v) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endforeach; ?>

        </div>

        <?php if ($totalFiltros > 0): ?>
        <div class="prod-active-filters">
          <?php foreach ($filtrosAtivos as $k => $v): ?>
          <span class="prod-active-tag">
            <?= View::e($k) ?>: <strong><?= View::e($v) ?></strong>
          </span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- ── Toolbar de edição em massa ────────────────────── -->
  <div class="prod-massa-toolbar" id="prod-massa-toolbar" style="display:none;">
    <div class="prod-massa-toolbar-inner">
      <label class="prod-massa-check-all-label">
        <input type="checkbox" id="check-all-header">
        <span id="massa-selected-label">0 selecionados</span>
      </label>
      <span class="prod-massa-toolbar-sep"></span>
      <span class="prod-massa-toolbar-hint">
        Clique nas linhas ou marque o checkbox para selecionar
      </span>
    </div>
  </div>

  <!-- ── Tabela ─────────────────────────────────────────── -->
  <?php if (empty($produtos)): ?>
  <div class="admin-empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1" stroke-linecap="round">
      <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
    </svg>
    <p>Nenhum produto encontrado.</p>
    <a href="<?= BASE_URL ?>/admin/produtos/criar" class="btn btn-primary">
      Criar primeiro produto
    </a>
  </div>
  <?php else: ?>

  <div class="admin-card prod-table-card">
    <div class="admin-table-wrap">
      <table class="admin-table prod-table" id="prod-table">
        <thead>
          <tr>
            <th class="prod-col-check" width="40" style="display:none;"></th>
            <th width="52"></th>
            <th>Produto</th>
            <th>Categoria / Marca</th>
            <th class="text-center" width="130">Preço</th>
            <th class="text-center" width="100">Estoque</th>
            <th class="text-center" width="64">Ativo</th>
            <th class="text-right"  width="100">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($produtos as $p):
            $temVar   = (bool)$p['tem_variacao'];
            $estoque  = (int)$p['estoque_total'];
            $min      = (int)($p['estoque_minimo'] ?? 0);
            $corEst   = $estoque === 0 ? 'danger' : ($estoque <= $min ? 'warning' : 'success');
          ?>

          <tr class="prod-row <?= $temVar ? 'has-variacao' : 'is-simples' ?>"
              data-id="<?= $p['id'] ?>"
              data-tem-variacao="<?= (int)$temVar ?>"
              data-preco="<?= number_format((float)$p['preco'], 2, '.', '') ?>"
              data-estoque="<?= $estoque ?>">

            <!-- Checkbox -->
            <td class="prod-col-check" style="display:none;">
              <input type="checkbox" class="prod-checkbox" value="<?= $p['id'] ?>">
            </td>

            <!-- Thumb -->
            <td>
              <?php if (!empty($p['imagem'])): ?>
              <img src="<?= View::e($p['imagem']) ?>"
                   alt="" loading="lazy" class="prod-thumb">
              <?php else: ?>
              <div class="prod-thumb-empty">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                  <rect x="3" y="3" width="18" height="18" rx="2"/>
                  <circle cx="8.5" cy="8.5" r="1.5"/>
                  <polyline points="21 15 16 10 5 21"/>
                </svg>
              </div>
              <?php endif; ?>
            </td>

            <!-- Nome + campos massa -->
            <td class="prod-td-nome">
              <a href="<?= BASE_URL ?>/admin/produtos/<?= $p['id'] ?>/editar"
                 class="prod-nome-link">
                <?= View::e($p['nome']) ?>
              </a>
              <div class="prod-badges">
                <?php if ($temVar): ?>
                <span class="prod-badge prod-badge--variacao">Variações</span>
                <?php endif; ?>
                <?php if ($p['destaque']): ?>
                <span class="prod-badge prod-badge--destaque">Destaque</span>
                <?php endif; ?>
              </div>

              <!-- Campos inline de edição em massa -->
              <div class="prod-massa-fields" id="massa-fields-<?= $p['id'] ?>">

                <!-- Preço -->
                <div class="prod-massa-row" data-field="preco">
                  <div class="prod-massa-input-group">
                    <span class="prod-massa-prefix">R$</span>
                    <input type="number"
                           class="prod-massa-input prod-preco-input"
                           data-id="<?= $p['id'] ?>"
                           data-tem-variacao="<?= (int)$temVar ?>"
                           data-original="<?= number_format((float)$p['preco'], 2, '.', '') ?>"
                           value="<?= number_format((float)$p['preco'], 2, '.', '') ?>"
                           step="0.01" min="0"
                           placeholder="Preço">
                    <button type="button"
                            class="prod-massa-btn prod-preco-save"
                            data-id="<?= $p['id'] ?>"
                            data-tem-variacao="<?= (int)$temVar ?>"
                            title="Confirmar (Enter)">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                           stroke="currentColor" stroke-width="3" stroke-linecap="round">
                        <polyline points="20 6 9 17 4 12"/>
                      </svg>
                    </button>
                  </div>
                  <span class="prod-massa-field-label">Preço (R$)</span>
                </div>

                <!-- Estoque — só para simples -->
                <?php if (!$temVar): ?>
                <div class="prod-massa-row" data-field="estoque">
                  <div class="prod-massa-input-group">
                    <input type="number"
                           class="prod-massa-input prod-estoque-input"
                           data-id="<?= $p['id'] ?>"
                           data-original="<?= $estoque ?>"
                           value="<?= $estoque ?>"
                           min="0"
                           placeholder="Estoque">
                    <button type="button"
                            class="prod-massa-btn prod-estoque-save"
                            data-id="<?= $p['id'] ?>"
                            title="Confirmar (Enter)">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                           stroke="currentColor" stroke-width="3" stroke-linecap="round">
                        <polyline points="20 6 9 17 4 12"/>
                      </svg>
                    </button>
                  </div>
                  <span class="prod-massa-field-label">Estoque (un)</span>
                </div>
                <?php endif; ?>

                <!-- Variações: botão para expandir -->
                <?php if ($temVar): ?>
                <button type="button"
                        class="prod-expand-skus-btn"
                        data-id="<?= $p['id'] ?>"
                        data-loaded="0">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                    <polyline points="2 17 12 22 22 17"/>
                    <polyline points="2 12 12 17 22 12"/>
                  </svg>
                  Editar estoque por variação
                  <svg class="expand-arrow" width="12" height="12" viewBox="0 0 24 24"
                       fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <polyline points="6 9 12 15 18 9"/>
                  </svg>
                </button>
                <?php endif; ?>
              </div>
            </td>

            <!-- Categoria / Marca -->
            <td>
              <span class="prod-cat-txt">
                <?= View::e($p['categoria_nome'] ?? '—') ?>
              </span>
              <?php if (!empty($p['marca_nome'])): ?>
              <span class="prod-marca-txt">
                <?= View::e($p['marca_nome']) ?>
              </span>
              <?php endif; ?>
            </td>

            <!-- Preço display -->
            <td class="text-center prod-td-preco">
              <?php if (!empty($p['preco_promo'])): ?>
              <span class="prod-preco-de">
                <?= PriceHelper::format((float)$p['preco']) ?>
              </span>
              <span class="prod-preco-por">
                <?= PriceHelper::format((float)$p['preco_promo']) ?>
              </span>
              <?php else: ?>
              <span class="prod-preco-val">
                <?= PriceHelper::format((float)$p['preco']) ?>
              </span>
              <?php endif; ?>
              <?php if ($temVar): ?>
              <span class="prod-preco-hint">a partir</span>
              <?php endif; ?>
            </td>

            <!-- Estoque display -->
            <td class="text-center">
              <span class="admin-badge admin-badge--<?= $corEst ?> prod-estoque-badge"
                    id="estoque-badge-<?= $p['id'] ?>">
                <?= number_format($estoque) ?>
              </span>
              <?php if ($temVar): ?>
              <span class="prod-skus-count">
                <?php
                $stSkus = $db->prepare("SELECT COUNT(*) FROM produto_skus WHERE produto_id=? AND ativo=1");
                $stSkus->execute([$p['id']]);
                $nSkus = (int)$stSkus->fetchColumn();
                echo $nSkus . ' SKU' . ($nSkus!=1?'s':'');
                ?>
              </span>
              <?php endif; ?>
            </td>

            <!-- Toggle ativo -->
            <td class="text-center">
              <button type="button"
                      class="admin-toggle <?= $p['ativo'] ? 'admin-toggle--on' : '' ?>"
                      data-id="<?= $p['id'] ?>" data-type="produto">
                <span class="admin-toggle-track">
                  <span class="admin-toggle-thumb"></span>
                </span>
              </button>
            </td>

            <!-- Ações -->
            <td class="text-right">
              <div class="admin-row-actions">
                <a href="<?= BASE_URL ?>/produto/<?= View::e($p['slug']) ?>"
                   target="_blank" class="btn btn-xs btn-ghost">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
                    <polyline points="15 3 21 3 21 9"/>
                    <line x1="10" y1="14" x2="21" y2="3"/>
                  </svg>
                </a>
                <a href="<?= BASE_URL ?>/admin/produtos/<?= $p['id'] ?>/editar"
                   class="btn btn-xs btn-ghost">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                  </svg>
                </a>
                <button type="button"
                        class="btn btn-xs btn-ghost btn-excluir-produto"
                        data-id="<?= $p['id'] ?>"
                        data-nome="<?= View::e($p['nome']) ?>">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
                  </svg>
                </button>
              </div>
            </td>
          </tr>

          <!-- Sub-row para SKUs (injetada via Ajax) -->
          <tr class="prod-skus-subrow" id="skus-row-<?= $p['id'] ?>"
              style="display:none;">
            <td colspan="8" style="padding:0;"></td>
          </tr>

          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginação -->
    <?php $totalPages = (int)ceil($total / $perPage);
    if ($totalPages > 1): ?>
    <div class="admin-pagination">
      <span class="admin-pagination-info">
        <?= (($page-1)*$perPage)+1 ?>–<?= min($page*$perPage,$total) ?>
        de <?= number_format($total) ?>
      </span>
      <div class="admin-pagination-btns">
        <?php if ($page>1): ?>
        <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>"
           class="btn btn-sm btn-ghost">←</a>
        <?php endif; ?>
        <?php for ($i=max(1,$page-2); $i<=min($totalPages,$page+2); $i++): ?>
        <a href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"
           class="btn btn-sm <?= $i===$page?'btn-primary':'btn-ghost' ?>">
          <?= $i ?>
        </a>
        <?php endfor; ?>
        <?php if ($page<$totalPages): ?>
        <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>"
           class="btn btn-sm btn-ghost">→</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>