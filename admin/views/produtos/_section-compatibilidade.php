<?php
$compat = new MotoCompatibilidade();
$itens  = $isEdit ? $compat->getDoProduct((int)$p['id']) : [];

$db = Database::getInstance()->getConnection();
$montadoras = $db->query(
    "SELECT id, nome, slug FROM moto_montadoras WHERE ativo=1 ORDER BY nome ASC"
)->fetchAll();
?>

<section class="pe-section" id="pe-compatibilidade">
  <div class="pe-section-head">
    <h2>Compatibilidade por moto</h2>
    <p>
      Vincule este produto a montadoras, modelos e anos específicos
      para aparecer na busca por moto.
    </p>
  </div>

  <div class="pe-card">

    <?php if (empty($montadoras)): ?>
    <div class="compat-aviso">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      Nenhuma montadora cadastrada ainda.
      <a href="<?= BASE_URL ?>/admin/motos/sincronizar" class="btn btn-sm btn-outline">
        Sincronizar FIPE agora
      </a>
    </div>
    <?php else: ?>

    <!-- Lista de vínculos existentes -->
    <div id="compat-list" class="compat-list">
      <?php foreach ($itens as $item): ?>
      <?php include __DIR__ . '/_compat-item.php'; ?>
      <?php endforeach; ?>

      <?php if (empty($itens)): ?>
      <div class="compat-empty" id="compat-empty">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.2" stroke-linecap="round">
          <circle cx="12" cy="12" r="10"/>
          <path d="M12 8v4M12 16h.01"/>
        </svg>
        <span>Sem compatibilidade configurada</span>
        <p>Adicione para que este produto apareça na busca por moto.</p>
      </div>
      <?php endif; ?>
    </div>

    <!-- Botão adicionar -->
    <button type="button" class="compat-add-btn" id="btn-add-compat">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5"  y1="12" x2="19" y2="12"/>
      </svg>
      Adicionar compatibilidade
    </button>

    <?php endif; ?>

  </div>
</section>

<!-- Dados para JS -->
<script>
window.MOTO_MONTADORAS = <?= json_encode(
    array_map(fn($m) => ['id' => $m['id'], 'nome' => $m['nome'], 'slug' => $m['slug']], $montadoras),
    JSON_UNESCAPED_UNICODE
) ?>;
</script>