<?php
// admin/views/pagamentos/fluxo-editor.php
// $fluxo, $grafo, $catalogo, $adquirentes — injetados pelo AdminPagamentoFluxoController

$publicado = $fluxo['status'] === 'publicado';
$badge = [
    'rascunho'  => ['Rascunho',  '#b45309', '#fffbeb'],
    'publicado' => ['Publicado', '#15803d', '#f0fdf4'],
    'arquivado' => ['Arquivado', '#64748b', '#f8fafc'],
];
[$stLbl, $stCor, $stBg] = $badge[$fluxo['status']] ?? ['—', '#64748b', '#f8fafc'];
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/jerosoler/Drawflow/dist/drawflow.min.css">

<style>
/* Estilos do editor de pagamento. Escopados em pg- para não colidir com o
   editor de marketing (fx-), que usa o mesmo Drawflow na mesma instalação. */
.pg-wrap { display:grid; grid-template-columns:190px 1fr 300px; gap:0;
           height:calc(100vh - 230px); min-height:460px; border:1px solid var(--c-border);
           border-radius:10px; overflow:hidden; background:#fff; }
#pg-paleta { border-right:1px solid var(--c-border); background:#fafafa; overflow-y:auto; padding:10px; }
.pg-paleta-grupo { font-size:10.5px; font-weight:700; text-transform:uppercase;
                   color:#94a3b8; margin:12px 0 6px; letter-spacing:.4px; }
.pg-paleta-item { background:#fff; border:1px solid var(--c-border); border-radius:6px;
                  padding:8px 10px; margin-bottom:5px; font-size:12.5px; cursor:grab; }
.pg-paleta-item:hover { border-color:#94a3b8; background:#f8fafc; }
#pg-canvas { position:relative; background:#f8fafc;
             background-image:radial-gradient(#e2e8f0 1px, transparent 1px);
             background-size:18px 18px; }
#pg-painel { border-left:1px solid var(--c-border); background:#fff; overflow-y:auto;
             padding:14px; display:none; }
#pg-painel.aberto { display:block; }
#pg-painel-titulo { font-weight:700; font-size:14px; margin-bottom:4px; }
#pg-painel-desc { font-size:11.5px; color:var(--c-text-muted); margin-bottom:14px; line-height:1.5; }
.pg-no { padding:9px 12px; min-width:150px; }
.pg-no-titulo { font-weight:700; font-size:12.5px; }
.pg-no-sub { font-size:11px; color:#64748b; margin-top:3px; }
.pg-porta-lbl { position:absolute; left:16px; top:-6px; font-size:9.5px; color:#64748b;
                white-space:nowrap; background:#fff; padding:0 3px; border-radius:3px; }
.drawflow .drawflow-node { border-radius:8px; border:1px solid var(--c-border);
                           box-shadow:0 1px 3px rgba(0,0,0,.07); padding:0; background:#fff; }
.drawflow .drawflow-node.selected { border-color:#2563eb; box-shadow:0 0 0 2px #2563eb22; }
.drawflow .output { background:#94a3b8; }
.pg-alerta { padding:9px 12px; border-radius:6px; font-size:12.5px; margin-bottom:6px; }
.pg-erro  { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
.pg-aviso { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
.pg-toolbar { display:flex; align-items:center; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
</style>

<div class="admin-page">

  <div class="pg-toolbar">
    <a href="<?= ADMIN_URL ?>/pagamentos/fluxos" class="btn btn-outline">← Fluxos</a>
    <h1 class="admin-page-title" style="margin:0;font-size:18px;"><?= View::e($fluxo['nome']) ?></h1>
    <span style="background:<?= $stBg ?>;color:<?= $stCor ?>;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;">
      <?= $stLbl ?> · v<?= (int) $fluxo['versao'] ?>
    </span>
    <span style="flex:1;"></span>

    <button type="button" id="pg-zoom-out"   class="btn btn-outline" title="Zoom −">−</button>
    <button type="button" id="pg-zoom-reset" class="btn btn-outline" title="100%">100%</button>
    <button type="button" id="pg-zoom-in"    class="btn btn-outline" title="Zoom +">+</button>

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
              style="width:100%;margin-top:14px;color:#b91c1c;border-color:#fecaca;">
        Excluir nó
      </button>
    </div>
  </div>

  <p style="font-size:11.5px;color:var(--c-text-muted);margin-top:10px;">
    Arraste da paleta para o canvas · clique num nó para configurar ·
    arraste de uma bolinha de saída até a entrada de outro nó para conectar.
    <strong>As saídas de recusa do emissor</strong> (sem saldo, antifraude, dados inválidos)
    devem terminar em <em>Recusar</em> — ligá-las noutra adquirente é retentativa proibida
    pelas bandeiras, e o motor recusa em execução.
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
