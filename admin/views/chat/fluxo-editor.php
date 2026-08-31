<?php
/**
 * admin/views/chat/fluxo-editor.php
 *
 * Canvas visual sobre Drawflow — mesma biblioteca já usada em /admin/fluxos
 * e no fluxo de pagamentos. Gera o formato {nos, conexoes} que o backend espera.
 *
 * @var array $fluxo (com ['grafo']) @var array $catalogo @var array $tags
 * @var array $templates @var array $fluxos @var array $agentes @var array $campos
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$badge = [
    'rascunho'  => ['Rascunho',  'var(--text-3)'],
    'publicado' => ['Publicado', 'var(--success)'],
    'pausado'   => ['Pausado',   'var(--warning)'],
    'arquivado' => ['Arquivado', 'var(--text-3)'],
];
[$stLbl, $stCor] = $badge[$fluxo['status']] ?? [$fluxo['status'], 'var(--text-3)'];

$config = json_decode($fluxo['config_json'] ?? '{}', true) ?: [];
?>

<div class="ch">

  <div class="ch-fx-toolbar">
    <div class="ch-fx-titulo">
      <a href="<?= $base ?>/admin/chat/fluxos" class="ch-btn ch-btn--ico" title="Voltar">←</a>
      <input type="text" class="ch-fx-nome-input" id="ch-fx-nome"
             value="<?= $h($fluxo['nome']) ?>" maxlength="120" title="Clique para renomear">
      <span class="ch-badge" style="color:<?= $stCor ?>;background:color-mix(in srgb, <?= $stCor ?> 16%, transparent);">
        <?= $h($stLbl) ?> · v<?= (int)$fluxo['versao_publicada'] ?>
      </span>
    </div>

    <button type="button" class="ch-btn ch-btn--ico" id="ch-fx-zoom-out" title="Diminuir zoom">−</button>
    <button type="button" class="ch-btn ch-btn--ico" id="ch-fx-zoom-reset" title="Zoom 100%">⌂</button>
    <button type="button" class="ch-btn ch-btn--ico" id="ch-fx-zoom-in" title="Aumentar zoom">+</button>

    <button type="button" class="ch-btn" id="ch-fx-cfg">Regras</button>
    <button type="button" class="ch-btn" id="ch-fx-salvar">Salvar rascunho</button>
    <button type="button" class="ch-btn ch-btn--pri" id="ch-fx-publicar">Publicar</button>

    <?php if ($fluxo['status'] === 'publicado'): ?>
      <button type="button" class="ch-btn ch-fx-status" data-status="pausado">Pausar</button>
    <?php elseif ($fluxo['status'] === 'pausado'): ?>
      <button type="button" class="ch-btn ch-fx-status" data-status="publicado">Reativar</button>
    <?php endif; ?>
  </div>

  <?php // ── Regras do fluxo ───────────────────────────────────────────── ?>
  <div class="ch-card" id="ch-fx-cfg-box" style="display:none;margin-bottom:12px;">
    <div class="ch-card-body">
      <div class="ch-grid-2">
        <div class="ch-campo">
          <label class="ch-label">Quando o contato pode entrar de novo neste fluxo</label>
          <select class="ch-select" id="ch-fx-reentrada">
            <option value="sempre"       <?= ($config['reentrada'] ?? 'sempre') === 'sempre' ? 'selected' : '' ?>>Sempre (recomendado para menu e atendimento)</option>
            <option value="nunca"        <?= ($config['reentrada'] ?? '') === 'nunca' ? 'selected' : '' ?>>Só uma vez na vida</option>
            <option value="apos_dias:7"  <?= ($config['reentrada'] ?? '') === 'apos_dias:7'  ? 'selected' : '' ?>>No máximo a cada 7 dias</option>
            <option value="apos_dias:30" <?= ($config['reentrada'] ?? '') === 'apos_dias:30' ? 'selected' : '' ?>>No máximo a cada 30 dias</option>
          </select>
          <div class="ch-ajuda">
            Num fluxo de menu, <strong>“só uma vez”</strong> faz o bot ficar mudo na segunda vez
            que a pessoa escrever. Use isso apenas em campanhas de sequência.
          </div>
        </div>
      </div>
    </div>
  </div>

  <div id="ch-fx-msg"></div>

  <?php // ── Editor ────────────────────────────────────────────────────── ?>
  <div class="ch-fx-wrap">
    <div class="ch-fx-paleta" id="ch-fx-paleta"></div>
    <div class="ch-fx-canvas" id="ch-fx-canvas"></div>
    <div class="ch-fx-painel">
      <div class="ch-fx-painel-head">
        <div class="ch-fx-painel-tit" id="ch-fx-p-titulo">Nenhum bloco selecionado</div>
        <div class="ch-fx-painel-chave" id="ch-fx-p-chave"></div>
      </div>
      <div class="ch-fx-painel-campos" id="ch-fx-p-campos">
        <div class="ch-vazio" style="padding:24px 6px;">
          Arraste um bloco da esquerda para o canvas, depois clique nele para configurar.
        </div>
      </div>
      <div class="ch-fx-painel-pe" id="ch-fx-p-pe" style="display:none;">
        <button type="button" class="ch-btn ch-btn--perigo ch-btn--sm" id="ch-fx-excluir-no" style="width:100%;">
          Excluir bloco
        </button>
      </div>
    </div>
  </div>

  <p class="ch-sm ch-mut" style="margin-top:10px;">
    Arraste blocos da paleta · clique num bloco para configurar ·
    arraste da bolinha da direita até a da esquerda para ligar ·
    <strong>Delete</strong> apaga o bloco selecionado.
  </p>
</div>

<script>
  window.CHFX = {
    base:      '<?= $base ?>',
    csrf:      '<?= $h($csrf_token ?? '') ?>',
    fluxoId:   <?= (int)$fluxo['id'] ?>,
    grafo:     <?= json_encode($fluxo['grafo'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    catalogo:  <?= json_encode($catalogo, JSON_UNESCAPED_UNICODE) ?>,
    tags:      <?= json_encode($tags, JSON_UNESCAPED_UNICODE) ?>,
    templates: <?= json_encode($templates, JSON_UNESCAPED_UNICODE) ?>,
    fluxos:    <?= json_encode($fluxos, JSON_UNESCAPED_UNICODE) ?>,
    agentes:   <?= json_encode($agentes, JSON_UNESCAPED_UNICODE) ?>,
    campos:    <?= json_encode($campos, JSON_UNESCAPED_UNICODE) ?>,
    publicado: <?= (int)$fluxo['versao_publicada'] ?>
  };
</script>
