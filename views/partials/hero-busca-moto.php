<?php
// views/partials/hero-busca-moto.php
$db = Database::getInstance()->getConnection();
$montadoras = $db->query(
    "SELECT mm.id, mm.nome, mm.slug
     FROM moto_montadoras mm
     WHERE mm.ativo = 1
       AND EXISTS (
           SELECT 1 FROM produto_compatibilidade pc
           JOIN produtos p ON p.id = pc.produto_id
           WHERE pc.montadora_id = mm.id AND p.ativo = 1
       )
     ORDER BY mm.nome ASC"
)->fetchAll();

if (empty($montadoras)) return;
?>

<div class="hbm-wrap ">
  <div class="hbm-inner ">

    <div class="hbm-brand">
      <div class="hbm-brand-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
             stroke="white" stroke-width="2" stroke-linecap="round">
          <circle cx="5.5" cy="17.5" r="3.5"/>
          <circle cx="18.5" cy="17.5" r="3.5"/>
          <path d="M15 6h-2l-3 8H5.5"/>
          <path d="M15 6l3 5h1.5"/>
          <path d="M9 6h4"/>
        </svg>
      </div>
      <div>
        <span class="hbm-eyebrow">ENCONTRE CERTO NA PRIMEIRA</span>
        <h2 class="hbm-title">Busque peças pela sua moto</h2>
      </div>
    </div>

    <form id="form-hero-busca-moto" class="hbm-form">
      <div class="hbm-selects">

        <div class="hbm-select-wrap">
          <select name="montadora_id" id="hbm-montadora" class="hbm-select">
            <option value="">Montadora</option>
            <?php foreach ($montadoras as $m): ?>
            <option value="<?= $m['id'] ?>"
                    data-slug="<?= View::e($m['slug']) ?>">
              <?= View::e($m['nome']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <svg class="hbm-select-arrow" width="14" height="14" viewBox="0 0 24 24"
               fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>

        <div class="hbm-select-wrap">
          <select name="modelo_id" id="hbm-modelo" class="hbm-select" disabled>
            <option value="">Modelo</option>
          </select>
          <svg class="hbm-select-arrow" width="14" height="14" viewBox="0 0 24 24"
               fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>

        <div class="hbm-select-wrap">
          <select name="ano" id="hbm-ano" class="hbm-select" disabled>
            <option value="">Ano</option>
          </select>
          <svg class="hbm-select-arrow" width="14" height="14" viewBox="0 0 24 24"
               fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>

        <button type="submit" class="hbm-btn" id="hbm-btn" disabled>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <circle cx="11" cy="11" r="8"/>
            <path d="m21 21-4.35-4.35"/>
          </svg>
          Buscar
        </button>
      </div>
    </form>

  </div>
</div>

<script>
(function () {
  const $mont = document.getElementById('hbm-montadora');
  const $mod  = document.getElementById('hbm-modelo');
  const $ano  = document.getElementById('hbm-ano');
  const $btn  = document.getElementById('hbm-btn');
  const BASE  = '<?= BASE_URL ?>';

  if (!$mont) return;

  $mont.addEventListener('change', function () {
    const id   = this.value;
    const slug = this.options[this.selectedIndex]?.dataset?.slug || '';
    this.dataset.slug = slug;

    $mod.innerHTML = '<option value="">Carregando...</option>';
    $mod.disabled  = true;
    $ano.innerHTML = '<option value="">Ano</option>';
    $ano.disabled  = true;
    $btn.disabled  = true;

    if (!id) { $mod.innerHTML = '<option value="">Modelo</option>'; return; }

    fetch(`${BASE}/ajax/moto/modelos?montadora_id=${id}`)
      .then(r => r.json())
      .then(list => {
        let opts = '<option value="">Todos os modelos</option>';
        list.forEach(m => {
          opts += `<option value="${m.id}" data-slug="${m.slug}">${m.nome}</option>`;
        });
        $mod.innerHTML = opts;
        $mod.disabled  = false;
        $btn.disabled  = false;
      });
  });

  $mod.addEventListener('change', function () {
    const id = this.value;
    $ano.innerHTML = '<option value="">Carregando...</option>';
    $ano.disabled  = true;
    if (!id) { $ano.innerHTML = '<option value="">Ano</option>'; return; }
    fetch(`${BASE}/ajax/moto/anos?modelo_id=${id}`)
      .then(r => r.json())
      .then(list => {
        let opts = '<option value="">Todos os anos</option>';
        list.forEach(a => { opts += `<option value="${a.ano}">${a.ano}</option>`; });
        $ano.innerHTML = opts;
        $ano.disabled  = false;
      });
  });

  document.getElementById('form-hero-busca-moto').addEventListener('submit', function (e) {
    e.preventDefault();
    const montSlug = $mont.dataset.slug;
    if (!montSlug) return;
    const modSlug = $mod.options[$mod.selectedIndex]?.dataset?.slug;
    const ano     = $ano.value;
    let url = `${BASE}/montadora/${montSlug}`;
    if (modSlug) { url += `/${modSlug}`; if (ano) url += `-${ano}`; }
    window.location.href = url;
  });
})();
</script>