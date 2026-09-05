<?php
/**
 * admin/views/fluxos/editor.php  (Fase 2 — canvas visual)
 *
 * Canvas visual sobre Drawflow 0.0.60 (cdnjs, já permitido no CSP do site).
 * Gera exatamente o mesmo formato de grafo — o backend não mudou.
 *
 * TELA CHEIA: o canvas ocupa a área de conteúdo inteira e barra, paleta,
 * painel, config e avisos flutuam sobre ele — mesmo desenho do editor de fluxo
 * do chat (.ch-fx-tela) e do de pagamentos (.pg-tela). Quem liga o modo é a
 * presença de .fx-tela na página; nenhuma outra tela do admin muda.
 *
 * A view NÃO usa .em_wrapper de propósito: aquela classe traz
 * `padding:28px 32px 56px` e `min-height:100vh`, que numa tela que é toda
 * canvas viram faixa morta nas bordas e barra de rolagem no documento.
 *
 * Os ids abaixo são contrato com o fluxo-canvas.js — não renomear:
 *   #fx-canvas #fx-paleta #fx-painel #fx-painel-titulo #fx-painel-chave
 *   #fx-painel-campos #fx-del-no #fx-cfg-toggle #fx-cfg-box #fx-cfg-json
 *   #fx-msg #fx-salvar #fx-publicar #fx-zoom-in #fx-zoom-out #fx-zoom-reset
 *
 * @var array $fluxo           (com ['grafo'] = rascunho v0)
 * @var array $catalogo        FluxoNoRegistry::catalogo() → tipo → {portas, trigger}
 * @var array $emailTemplates  [{id, nome}] para o select do acao_email
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$badge = [
    'rascunho'  => ['Rascunho', 'var(--text-3)'],
    'publicado' => ['Publicado', 'var(--success)'],
    'pausado'   => ['Pausado', 'var(--warning)'],
];
[$stLbl, $stCor] = $badge[$fluxo['status']] ?? [$fluxo['status'], 'var(--text-3)'];
$configJson = $fluxo['config_json'] ?: "{\n  \"reentrada\": \"nunca\",\n  \"sair_se_eventos\": []\n}";
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/jerosoler/Drawflow/dist/drawflow.min.css">

<div class="fx-editor">
  <div class="fx-tela" id="fx-tela">

    <?php // ── Canvas: o fundo de tudo ───────────────────────────────────── ?>
    <div id="fx-canvas"></div>

    <?php // ── Barra flutuante ───────────────────────────────────────────── ?>
    <?php // pointer-events volta só nos grupos: o vão entre eles continua
          // arrastável, senão a faixa do topo virava uma parede sobre o canvas. ?>
    <div class="fx-barra">
      <div class="fx-grupo fx-flut">
        <a href="<?= $base ?>/admin/fluxos" class="fx-btn fx-btn-icon" title="Voltar para a lista">
          <i class="bi bi-arrow-left"><?= IconLibrary::render('arrow-back') ?></i>
        </a>
        <span class="fx-nome" title="<?= htmlspecialchars($fluxo['nome']) ?>">
          <?= htmlspecialchars($fluxo['nome']) ?>
        </span>
        <span class="fx-badge" style="color:<?= $stCor ?>;background:<?= $stCor ?>18;">
          <?= $stLbl ?> · v<?= (int)$fluxo['versao_publicada'] ?>
        </span>
      </div>

      <div class="fx-grupo fx-flut">
        <button type="button" id="fx-zoom-out"   class="fx-btn fx-btn-icon" title="Zoom −"><i class="bi bi-zoom-out"><?= IconLibrary::render('zoom-out') ?></i></button>
        <button type="button" id="fx-zoom-reset" class="fx-btn fx-btn-icon" title="Zoom 100%"><i class="bi bi-aspect-ratio"><?= IconLibrary::render('aspect-ratio') ?></i></button>
        <button type="button" id="fx-zoom-in"    class="fx-btn fx-btn-icon" title="Zoom +"><i class="bi bi-zoom-in"><?= IconLibrary::render('zoom-in') ?></i></button>

        <span class="fx-sep"></span>

        <button type="button" id="fx-cfg-toggle" class="fx-btn" title="Reentrada e exit conditions">
          <i class="bi bi-shield-check"></i> Guard-rails
        </button>
        <button type="button" id="fx-salvar" class="fx-btn">
          <i class="bi bi-save"></i> Salvar rascunho
        </button>
        <button type="button" id="fx-publicar" class="fx-btn fx-btn-pri">
          <i class="bi bi-rocket-takeoff"><?= IconLibrary::render('rocket-launch') ?></i> Publicar
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
    </div>

    <?php // ── Paleta: gaveta à esquerda, rola por dentro ────────────────── ?>
    <div class="fx-paleta-box fx-flut">
      <div class="fx-paleta-cab">
        Nós
        <span>Arraste para o canvas</span>
      </div>
      <div id="fx-paleta"></div>
    </div>

    <?php // ── Painel do nó: à direita, só existe com nó selecionado ─────── ?>
    <div id="fx-painel" class="fx-flut">
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

    <?php // ── Guard-rails: caixa flutuante (slideToggle pelo JS) ────────── ?>
    <div id="fx-cfg-box" class="fx-flut">
      <label class="fx-label">Config do fluxo — reentrada e exit conditions (JSON)</label>
      <textarea id="fx-cfg-json" spellcheck="false"><?= htmlspecialchars($configJson) ?></textarea>
      <p class="fx-cfg-dica">
        Ex.: <code>{"reentrada":"apos_dias:30","sair_se_eventos":["pedido_criado"]}</code>
      </p>
    </div>

    <?php // ── Aviso de salvar/publicar ──────────────────────────────────── ?>
    <div id="fx-msg"></div>

    <?php // ── Dica: pílula no rodapé ────────────────────────────────────── ?>
    <div class="fx-dica">
      Arraste nós da paleta · clique num nó para configurar ·
      arraste da saída até a entrada para conectar · <b>Delete</b> remove o nó
    </div>

  </div>
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
