<?php
// admin/views/pagamentos/fluxo-editor.php
// $fluxo, $grafo, $catalogo, $adquirentes — injetados pelo AdminPagamentoFluxoController

$publicado = $fluxo['status'] === 'publicado';
$badge = [
    'rascunho'  => ['Rascunho',  'var(--warning)', 'var(--warning-lt)'],
    'publicado' => ['Publicado', 'var(--success)', 'var(--success-lt)'],
    'arquivado' => ['Arquivado', 'var(--text-2)', 'var(--bg)'],
];
[$stLbl, $stCor, $stBg] = $badge[$fluxo['status']] ?? ['—', 'var(--text-2)', 'var(--bg)'];
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/jerosoler/Drawflow/dist/drawflow.min.css">

<div class="admin-page pg-editor">

  <div class="pg-toolbar">
    <a href="<?= ADMIN_URL ?>/pagamentos/fluxos" class="btn btn-outline">← Fluxos</a>
    <h1 class="pg-titulo"><?= View::e($fluxo['nome']) ?></h1>
    <span class="pg-selo" style="background:<?= $stBg ?>;color:<?= $stCor ?>;">
      <?= $stLbl ?> · v<?= (int) $fluxo['versao'] ?>
    </span>
    <span style="flex:1;"></span>

    <div class="pg-zoom">
      <button type="button" id="pg-zoom-out"   title="Diminuir zoom">−</button>
      <button type="button" id="pg-zoom-reset" title="Voltar a 100%">100%</button>
      <button type="button" id="pg-zoom-in"    title="Aumentar zoom">+</button>
    </div>

    <?php if ($publicado): ?>
      <button type="button" class="btn btn-primary" id="pg-criar-rascunho"
              data-metodo="<?= View::e($fluxo['metodo_codigo']) ?>">Criar rascunho para editar</button>
    <?php else: ?>
      <button type="button" id="pg-salvar"   class="btn btn-outline">Salvar rascunho</button>
      <button type="button" id="pg-publicar" class="btn btn-primary">Publicar</button>
    <?php endif; ?>
  </div>

  <?php if ($publicado): ?>
  <div class="pg-alerta pg-aviso" style="margin-bottom:12px;">
    Este fluxo está <strong>no ar</strong> e é somente leitura. Editar direto mudaria o
    roteamento de quem está no checkout agora — crie um rascunho para trabalhar.
  </div>
  <?php endif; ?>

  <div id="pg-msg"></div>

  <div class="pg-wrap">
    <div id="pg-paleta"></div>
    <div id="pg-canvas"></div>
    <div id="pg-painel">
      <div id="pg-painel-titulo">—</div>
      <div id="pg-painel-desc"></div>
      <div id="pg-painel-campos"></div>
      <button type="button" id="pg-del-no" class="btn btn-outline"
              style="width:100%;margin-top:14px;color:var(--danger);border-color:var(--danger-bd);">
        Excluir nó
      </button>
    </div>
  </div>

  <div class="pg-legenda">
    <b><i style="background:var(--success)"></i> aprovado</b>
    <b><i style="background:var(--info)"></i> aguardando pagamento</b>
    <b><i style="background:var(--danger)"></i> recusa do emissor</b>
    <b><i style="background:var(--warning)"></i> falha técnica</b>
  </div>

  <p class="pg-dica">
    Arraste da paleta para o canvas · clique num nó para configurar ·
    arraste de uma bolinha de saída até a entrada de outro nó para conectar.
    <strong>As saídas em vermelho devem terminar em Recusar</strong> — ligá-las
    noutra adquirente é retentativa proibida pelas bandeiras, e o motor recusa
    em execução. As em âmbar são as que podem cair para outra adquirente.
  </p>
</div>

<script src="https://cdn.jsdelivr.net/gh/jerosoler/Drawflow/dist/drawflow.min.js"></script>
<script>
  window.PG_FLUXO       = <?= json_encode(['id' => (int) $fluxo['id'], 'metodo' => $fluxo['metodo_codigo']], JSON_UNESCAPED_UNICODE) ?>;
  window.PG_GRAFO       = <?= json_encode($grafo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.PG_CATALOGO    = <?= json_encode($catalogo, JSON_UNESCAPED_UNICODE) ?>;
  window.PG_ADQUIRENTES = <?= json_encode(array_map(static fn($a) => [
        'codigo' => $a['codigo'], 'nome' => $a['nome'], 'ativo' => (int) $a['ativo'],
      ], $adquirentes), JSON_UNESCAPED_UNICODE) ?>;
  window.PG_ADMIN_URL   = '<?= ADMIN_URL ?>';
  window.PG_CSRF        = '<?= SecurityHelper::generateCsrf() ?>';
  window.PG_READONLY    = <?= $publicado ? 'true' : 'false' ?>;
</script>
<script src="<?= ADMIN_ASSET_URL ?>/js/pagamento-canvas.js"></script>

<?php if ($publicado): ?>
<script>
document.getElementById('pg-criar-rascunho').addEventListener('click', function () {
  var b = this; b.disabled = true;
  var fd = new FormData();
  fd.append('_csrf_token', window.PG_CSRF);
  fd.append('metodo', b.getAttribute('data-metodo'));
  fetch(window.PG_ADMIN_URL + '/pagamentos/fluxos/rascunho', {
    method: 'POST', body: fd, credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  }).then(function (r) { return r.json(); }).then(function (res) {
    if (res.ok) location.href = window.PG_ADMIN_URL + '/pagamentos/fluxos/editor?id=' + res.id;
    else { b.disabled = false; if (window.Toast) Toast.error(res.msg); }
  }).catch(function () { b.disabled = false; });
});
</script>
<?php endif; ?>
