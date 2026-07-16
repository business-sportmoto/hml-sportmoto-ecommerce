<?php
/**
 * views/admin/fluxos/editor.php  (Fase 2 — SUBSTITUI o editor JSON da Fase 1)
 *
 * Canvas visual sobre Drawflow 0.0.60 (cdnjs, já permitido no CSP do site).
 * Gera exatamente o mesmo formato de grafo — o backend não mudou.
 *
 * @var array $fluxo           (com ['grafo'] = rascunho v0)
 * @var array $catalogo        FluxoNoRegistry::catalogo() → tipo → {portas, trigger}
 * @var array $emailTemplates  [{id, nome}] para o select do acao_email
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$badge = [
    'rascunho'  => ['Rascunho', '#71717a'],
    'publicado' => ['Publicado', '#16a34a'],
    'pausado'   => ['Pausado', '#f59e0b'],
];
[$stLbl, $stCor] = $badge[$fluxo['status']] ?? [$fluxo['status'], '#71717a'];
$configJson = $fluxo['config_json'] ?: "{\n  \"reentrada\": \"nunca\",\n  \"sair_se_eventos\": []\n}";
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/jerosoler/Drawflow/dist/drawflow.min.css">


<div class="em_wrapper" style="max-width:none;">

  <!-- Toolbar -->
  <div class="fx-toolbar">
    <div class="fx-titulo">
      <a href="<?= $base ?>/admin/fluxos" class="fx-btn fx-btn-icon" title="Voltar">
        <i class="bi bi-arrow-left">
          <?= IconLibrary::render('arrow-back') ?>
        </i>
      </a>
      <?= htmlspecialchars($fluxo['nome']) ?>
      <span class="fx-badge" style="color:<?= $stCor ?>;background:<?= $stCor ?>18;">
        <?= $stLbl ?> · v<?= (int)$fluxo['versao_publicada'] ?>
      </span>
    </div>

    <button type="button" id="fx-zoom-out"   class="fx-btn fx-btn-icon" title="Zoom -"><i class="bi bi-zoom-out"><?= IconLibrary::render('zoom-out') ?></i></button>
    <button type="button" id="fx-zoom-reset" class="fx-btn fx-btn-icon" title="Zoom 100%"><i class="bi bi-aspect-ratio"><?= IconLibrary::render('aspect-ratio') ?></i></button>
    <button type="button" id="fx-zoom-in"    class="fx-btn fx-btn-icon" title="Zoom +"><i class="bi bi-zoom-in"><?= IconLibrary::render('zoom-in') ?></i></button>

    <button type="button" id="fx-cfg-toggle" class="fx-btn">
      <i class="bi bi-shield-check"></i> Guard-rails
    </button>
    <button type="button" id="fx-salvar" class="fx-btn">
      <i class="bi bi-save"></i> Salvar rascunho
    </button>
    <button type="button" id="fx-publicar" class="fx-btn fx-btn-pri">
      <i class="bi bi-rocket-takeoff"><?= IconLibrary::render('rocket-launch') ?></i></i> Publicar
    </button>
    <?php if ($fluxo['status'] === 'publicado'): ?>
      <button type="button" class="fx-btn fx-status-btn" data-status="pausado">
        <i class="bi bi-pause-circle"></i> Pausar
      </button>
    <?php elseif ($fluxo['status'] === 'pausado'): ?>
      <button type="button" class="fx-btn fx-status-btn" data-status="publicado">
        <i class="bi bi-play-circle"></i> Reativar
      </button>
    <?php endif; ?>
  </div>

  <!-- Config do fluxo (guard-rails) -->
  <div id="fx-cfg-box">
    <label class="fx-label" style="margin-bottom:6px;">
      Config do fluxo — reentrada e exit conditions (JSON)
    </label>
    <textarea id="fx-cfg-json" spellcheck="false"><?= htmlspecialchars($configJson) ?></textarea>
    <p style="font-size:11px;color:var(--em-text-muted);margin:6px 0 0;">
      Ex.: <code>{"reentrada":"apos_dias:30","sair_se_eventos":["pedido_criado"]}</code>
    </p>
  </div>

  <div id="fx-msg"></div>

  <!-- Editor -->
  <div class="fx-wrap">
    <div id="fx-paleta"></div>
    <div id="fx-canvas"></div>
    <div id="fx-painel">
      <div class="fx-painel-head">
        <div id="fx-painel-titulo">—</div>
        <div id="fx-painel-chave"></div>
      </div>
      <div id="fx-painel-campos"></div>
      <div class="fx-painel-foot">
        <button type="button" id="fx-del-no" class="fx-btn-del">
          <i class="bi bi-trash"></i> Excluir nó
        </button>
      </div>
    </div>
  </div>

  <p style="font-size:11.5px;color:var(--em-text-muted);margin-top:10px;">
    Arraste nós da paleta para o canvas · clique num nó para configurar ·
    arraste da bolinha de saída até a de entrada para conectar ·
    <b>Delete</b> remove o nó selecionado.
  </p>
</div>

<script src="https://cdn.jsdelivr.net/gh/jerosoler/Drawflow/dist/drawflow.min.js"></script>
<script>
  window.FX_FLUXO_ID        = <?= (int)$fluxo['id'] ?>;
  window.FX_GRAFO_INICIAL   = <?= json_encode($fluxo['grafo'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.FX_CATALOGO        = <?= json_encode($catalogo, JSON_UNESCAPED_UNICODE) ?>;
  window.FX_EMAIL_TEMPLATES = <?= json_encode($emailTemplates ?? [], JSON_UNESCAPED_UNICODE) ?>;
  window.BASE_URL   = window.BASE_URL   || '<?= BASE_URL ?>';
  window.CSRF_TOKEN = '<?= \SecurityHelper::generateCsrf() ?>';
</script>


<script>
// Botões de status (pausar/reativar) — fora do fluxo-canvas.js por usarem reload
(function ($) {
  $('.fx-status-btn').on('click', function () {
    $.post(window.BASE_URL + '/admin/fluxos/' + window.FX_FLUXO_ID + '/status', {
      fluxo_id: window.FX_FLUXO_ID,
      status: $(this).data('status'),
      csrf_token: window.CSRF_TOKEN
    }, function (r) { if (r.ok) location.reload(); }, 'json');
  });
})(jQuery);
</script>
