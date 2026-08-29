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
/* Editor de fluxo de pagamento.
   Escopado em pg- para nao colidir com o editor de marketing (fx-), que usa o
   mesmo Drawflow na mesma instalacao.

   TEMA POR TOKEN: as cores moram nas variaveis abaixo e sao trocadas em bloco
   no escuro. Antes cada regra trazia a cor no corpo, e adicionar o tema
   escuro exigiria reescrever todas — agora e um bloco so. */
.pg-editor{
  --pg-fundo:var(--pgto-bg);
  --pg-superficie:var(--pgto-surface);
  --pg-recuo:var(--pgto-surface-2);
  --pg-borda:var(--pgto-border);
  --pg-borda2:var(--pgto-border-soft);
  --pg-texto:var(--pgto-text);
  --pg-texto2:var(--pgto-text-muted);
  --pg-grade:var(--pgto-border);
  --pg-sombra:var(--pgto-shadow-sm);
  --pg-sombra-no:var(--pgto-shadow);
}

/* Barra */
.pg-toolbar{display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.pg-titulo{margin:0;font-size:18px;font-weight:700;color:var(--pg-texto);letter-spacing:-.01em}
.pg-selo{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.02em}
.pg-zoom{display:inline-flex;border:1px solid var(--pg-borda);border-radius:8px;overflow:hidden}
.pg-zoom button{border:0;background:var(--pg-superficie);color:var(--pg-texto2);
  padding:7px 11px;font-size:12.5px;font-weight:600;cursor:pointer;line-height:1;
  border-right:1px solid var(--pg-borda);transition:background .14s,color .14s}
.pg-zoom button:last-child{border-right:0}
.pg-zoom button:hover{background:var(--pg-fundo);color:var(--pg-texto)}

/* Moldura */
.pg-wrap{display:grid;grid-template-columns:206px 1fr 310px;
  height:calc(100vh - 250px);min-height:480px;
  border:1px solid var(--pg-borda);border-radius:12px;overflow:hidden;
  background:var(--pg-superficie);box-shadow:var(--pg-sombra)}

/* Paleta */
#pg-paleta{border-right:1px solid var(--pg-borda);background:var(--pg-recuo);
  overflow-y:auto;padding:12px 11px}
.pg-paleta-grupo{font-size:10px;font-weight:700;text-transform:uppercase;
  color:var(--pg-texto2);margin:14px 0 7px;letter-spacing:.07em}
.pg-paleta-grupo:first-child{margin-top:2px}
.pg-paleta-item{position:relative;background:var(--pg-superficie);
  border:1px solid var(--pg-borda);border-radius:8px;
  padding:9px 11px 9px 20px;margin-bottom:6px;font-size:12.5px;
  color:var(--pg-texto);cursor:grab;transition:border-color .14s,transform .14s,box-shadow .14s}
/* Faixa da cor do grupo: identifica o tipo antes de ler o nome. */
.pg-paleta-item::before{content:"";position:absolute;left:8px;top:50%;
  transform:translateY(-50%);width:4px;height:16px;border-radius:2px;
  background:currentColor;opacity:.6}
.pg-paleta-item:hover{border-color:var(--pg-borda2);transform:translateX(2px);
  box-shadow:var(--pg-sombra)}
.pg-paleta-item:active{cursor:grabbing}

/* Canvas */
#pg-canvas{position:relative;background-color:var(--pg-fundo);
  background-image:radial-gradient(var(--pg-grade) 1px,transparent 1px);
  background-size:18px 18px}

.drawflow .drawflow-node{border-radius:10px;border:1px solid var(--pg-borda);
  box-shadow:var(--pg-sombra-no);padding:0;background:var(--pg-superficie);
  transition:box-shadow .16s,border-color .16s}
.drawflow .drawflow-node:hover{border-color:var(--pg-borda2)}
.drawflow .drawflow-node.selected{border-color:#2563eb;
  box-shadow:0 0 0 2px rgba(37,99,235,.28),var(--pg-sombra-no)}

.pg-no{padding:10px 13px;min-width:158px}
.pg-no-titulo{font-weight:700;font-size:12.5px;letter-spacing:-.01em}
.pg-no-sub{font-size:11px;color:var(--pg-texto2);margin-top:3px}

/* PORTAS — a informacao mais densa desta tela.
   Num fluxo de pagamento, o que cada saida significa decide se o motor pode
   retentar. Verde aprova, vermelho e recusa do emissor (nunca retenta), ambar
   e falha tecnica (pode cair para outra adquirente). Com tudo cinza, so
   lendo rotulo a rotulo se enxerga um fluxo mal ligado. */
.drawflow .output{background:#94a3b8;width:11px;height:11px;
  border:2px solid var(--pg-superficie);transition:transform .14s}
.drawflow .output:hover{transform:scale(1.25)}
.drawflow .output.pg-p-ok{background:#16a34a}
.drawflow .output.pg-p-espera{background:#0891b2}
.drawflow .output.pg-p-nega{background:#dc2626}
.drawflow .output.pg-p-tec{background:#d97706}
.drawflow .input{width:11px;height:11px;border:2px solid var(--pg-superficie);background:#64748b}

.pg-porta-lbl{position:absolute;left:16px;top:-6px;font-size:9.5px;
  color:var(--pg-texto2);white-space:nowrap;background:var(--pg-superficie);
  padding:0 4px;border-radius:3px;border:1px solid var(--pg-borda)}

.drawflow svg .main-path{stroke:var(--pg-borda2);stroke-width:2.2px}
.drawflow svg .main-path:hover{stroke:#2563eb}

/* Painel */
#pg-painel{border-left:1px solid var(--pg-borda);background:var(--pg-superficie);
  overflow-y:auto;padding:16px;display:none;color:var(--pg-texto)}
#pg-painel.aberto{display:block}
#pg-painel-titulo{font-weight:700;font-size:14.5px;margin-bottom:5px;color:var(--pg-texto)}
#pg-painel-desc{font-size:11.5px;color:var(--pg-texto2);margin-bottom:16px;line-height:1.55;
  padding-bottom:14px;border-bottom:1px solid var(--pg-borda)}
#pg-painel label{display:block;font-size:11.5px;font-weight:600;
  color:var(--pg-texto2);margin-bottom:5px}
#pg-painel .form-control{background:var(--pg-superficie);border-color:var(--pg-borda);
  color:var(--pg-texto)}

/* Alertas — os fundos *-soft do sistema sao translucidos e ja
   funcionam nos dois temas; so o texto precisa clarear no escuro. */
.pg-alerta{padding:10px 13px;border-radius:8px;font-size:12.5px;margin-bottom:7px;line-height:1.5}
.pg-erro{background:var(--pgto-red-soft);color:var(--pgto-red);border:1px solid #dc26264d}
.pg-aviso{background:var(--pgto-amber-soft);color:#b45309;border:1px solid #f59e0b4d}

/* Legenda */
.pg-legenda{display:flex;flex-wrap:wrap;gap:14px;align-items:center;
  margin-top:12px;font-size:11.5px;color:var(--pg-texto2)}
.pg-legenda b{display:inline-flex;align-items:center;gap:5px;font-weight:600}
.pg-legenda i{width:9px;height:9px;border-radius:50%;display:inline-block}
.pg-dica{font-size:11.5px;color:var(--pg-texto2);margin-top:10px;line-height:1.6}
.pg-dica strong{color:var(--pg-texto)}
@media (prefers-color-scheme: dark){
  html:not([data-theme="light"]) .pg-erro{color:#fca5a5}
  html:not([data-theme="light"]) .pg-aviso{color:#fcd34d}
}
html[data-theme="dark"] .pg-erro{color:#fca5a5}
html[data-theme="dark"] .pg-aviso{color:#fcd34d}
</style>

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
              style="width:100%;margin-top:14px;color:#b91c1c;border-color:#fecaca;">
        Excluir nó
      </button>
    </div>
  </div>

  <div class="pg-legenda">
    <b><i style="background:#16a34a"></i> aprovado</b>
    <b><i style="background:#0891b2"></i> aguardando pagamento</b>
    <b><i style="background:#dc2626"></i> recusa do emissor</b>
    <b><i style="background:#d97706"></i> falha técnica</b>
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
