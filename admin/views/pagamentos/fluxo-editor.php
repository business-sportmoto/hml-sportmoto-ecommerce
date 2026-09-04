<?php
// admin/views/pagamentos/fluxo-editor.php
// $fluxo, $grafo, $catalogo, $adquirentes — injetados pelo AdminPagamentoFluxoController
//
// O canvas ocupa a área de conteúdo inteira; barra, paleta, painel, legenda e
// avisos flutuam sobre ele — mesmo desenho do editor de fluxo do chat. Quem
// liga esse modo é a presença de .pg-tela na página (ver pages.css); nada aqui
// depende de alterar o layout do admin.

$publicado = $fluxo['status'] === 'publicado';
$badge = [
    'rascunho'  => ['Rascunho',  'var(--warning)', 'var(--warning-lt)'],
    'publicado' => ['Publicado', 'var(--success)', 'var(--success-lt)'],
    'arquivado' => ['Arquivado', 'var(--text-2)', 'var(--bg)'],
];
[$stLbl, $stCor, $stBg] = $badge[$fluxo['status']] ?? ['—', 'var(--text-2)', 'var(--bg)'];
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/jerosoler/Drawflow/dist/drawflow.min.css">

<?php // Sem .admin-page de proposito: ela traz padding e max-width, e aqui a
      // tela inteira e canvas. O editor do chat faz igual. ?>
<div class="pg-editor">
  <div class="pg-tela" id="pg-tela">

    <?php // ── Canvas: o fundo de tudo ─────────────────────────────────── ?>
    <div id="pg-canvas"></div>

    <?php // ── Barra flutuante ─────────────────────────────────────────── ?>
    <div class="pg-barra">
      <div class="pg-grupo pg-flut">
        <a href="<?= ADMIN_URL ?>/pagamentos/fluxos" class="pg-voltar" title="Voltar para a lista de fluxos">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        </a>
        <h1 class="pg-titulo" title="<?= View::e($fluxo['nome']) ?>"><?= View::e($fluxo['nome']) ?></h1>
        <span class="pg-selo" style="background:<?= $stBg ?>;color:<?= $stCor ?>;">
          <?= $stLbl ?> · v<?= (int) $fluxo['versao'] ?>
        </span>
        <?php if ($publicado): ?>
          <span class="pg-somente-leitura" title="Este fluxo está no ar. Veja o porquê em “?”.">
            somente leitura
          </span>
        <?php endif; ?>
      </div>

      <div class="pg-grupo pg-flut">
        <button type="button" class="pg-ajuda-btn" id="pg-ajuda-btn"
                aria-expanded="false" title="Como ligar os nós">?</button>

        <span class="pg-sep"></span>

        <div class="pg-zoom">
          <button type="button" id="pg-zoom-out"   title="Diminuir zoom">−</button>
          <button type="button" id="pg-zoom-reset" title="Voltar a 100%">100%</button>
          <button type="button" id="pg-zoom-in"    title="Aumentar zoom">+</button>
        </div>

        <span class="pg-sep"></span>

        <?php if ($publicado): ?>
          <button type="button" class="btn btn-primary btn-sm" id="pg-criar-rascunho"
                  data-metodo="<?= View::e($fluxo['metodo_codigo']) ?>">Criar rascunho para editar</button>
        <?php else: ?>
          <button type="button" id="pg-salvar"   class="btn btn-outline btn-sm">Salvar rascunho</button>
          <button type="button" id="pg-publicar" class="btn btn-primary btn-sm">Publicar</button>
        <?php endif; ?>
      </div>

    </div>

    <?php // ── Ajuda: as regras que decidem se o motor pode retentar ───── ?>
    <div class="pg-ajuda pg-flut" id="pg-ajuda">
      <?php if ($publicado): ?>
      <p>
        Este fluxo está <strong>no ar</strong> e é somente leitura. Editar direto mudaria o
        roteamento de quem está no checkout agora — crie um rascunho para trabalhar.
      </p>
      <?php endif; ?>
      <p>
        Arraste da paleta para o canvas · clique num nó para configurar ·
        arraste de uma bolinha de saída até a entrada de outro nó para conectar.
      </p>
      <p>
        <strong>As saídas em vermelho devem terminar em Recusar</strong> — ligá-las
        noutra adquirente é retentativa proibida pelas bandeiras, e o motor recusa
        em execução. As em âmbar são as que podem cair para outra adquirente.
      </p>
    </div>

    <?php // ── Paleta ──────────────────────────────────────────────────── ?>
    <aside class="pg-paleta-box pg-flut">
      <div class="pg-paleta-cab">
        Blocos
        <span>Arraste para o canvas</span>
      </div>
      <div id="pg-paleta"></div>
    </aside>

    <?php // ── Painel do nó: só existe com nó selecionado ──────────────── ?>
    <aside class="pg-flut" id="pg-painel">
      <div class="pg-painel-cab">
        <div id="pg-painel-titulo">—</div>
        <div id="pg-painel-desc"></div>
      </div>
      <div id="pg-painel-campos"></div>
      <?php // Em fluxo publicado o botao some: o no sumiria da tela e nada
            // disso poderia ser salvo (o servidor recusa gravar publicado). ?>
      <div class="pg-painel-pe"<?= $publicado ? ' hidden' : '' ?>>
        <button type="button" id="pg-del-no" class="btn btn-outline"
                style="width:100%;color:var(--danger);border-color:var(--danger-bd);">
          Excluir nó
        </button>
      </div>
    </aside>

    <?php // ── Avisos de salvar/publicar ───────────────────────────────── ?>
    <div id="pg-msg"></div>

    <?php // ── Legenda das portas ──────────────────────────────────────── ?>
    <div class="pg-legenda">
      <div class="pg-legenda-pilula">
        <b><i style="background:var(--success)"></i> aprovado</b>
        <b><i style="background:var(--info)"></i> aguardando pagamento</b>
        <b><i style="background:var(--danger)"></i> recusa do emissor</b>
        <b><i style="background:var(--warning)"></i> falha técnica</b>
      </div>
    </div>

  </div>
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

<script>
// Caixa de ajuda: fechada por padrão para não roubar espaço do canvas.
(function () {
  var btn = document.getElementById('pg-ajuda-btn');
  var box = document.getElementById('pg-ajuda');
  if (!btn || !box) return;

  function fechar() {
    box.classList.remove('aberta');
    btn.classList.remove('aberto');
    btn.setAttribute('aria-expanded', 'false');
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    var abrindo = !box.classList.contains('aberta');
    box.classList.toggle('aberta', abrindo);
    btn.classList.toggle('aberto', abrindo);
    btn.setAttribute('aria-expanded', abrindo ? 'true' : 'false');
  });

  // Clique fora e Esc fecham — a caixa cobre parte do canvas.
  document.addEventListener('click', function (e) {
    if (box.classList.contains('aberta') && !box.contains(e.target)) fechar();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') fechar();
  });
})();
</script>

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
