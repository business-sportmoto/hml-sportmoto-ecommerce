<?php
/**
 * admin/views/chat/fluxo-editor.php
 *
 * Canvas visual sobre Drawflow — mesma biblioteca já usada em /admin/fluxos
 * e no fluxo de pagamentos. Gera o formato {nos, conexoes} que o backend espera.
 *
 * O canvas ocupa a área de conteúdo inteira; barra, paleta, zoom e painel
 * flutuam sobre ele. Quem liga esse modo é a presença de .ch-fx-tela na página
 * (ver chat.css) — nada aqui depende de alterar o layout do admin.
 *
 * @var array $fluxo (com ['grafo']) @var array $catalogo @var array $tags
 * @var array $templates @var array $fluxos @var array $agentes @var array $campos
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$badge = [
    'rascunho'  => ['Rascunho',  'var(--ch-mut)'],
    'publicado' => ['Publicado', 'var(--success)'],
    'pausado'   => ['Pausado',   'var(--warning)'],
    'arquivado' => ['Arquivado', 'var(--ch-mut)'],
];
[$stLbl, $stCor] = $badge[$fluxo['status']] ?? [$fluxo['status'], 'var(--ch-mut)'];

$config = json_decode($fluxo['config_json'] ?? '{}', true) ?: [];
?>

<div class="ch">
  <div class="ch-fx-tela" id="ch-fx-tela">

    <?php // ── Canvas: o fundo de tudo ─────────────────────────────────── ?>
    <div class="ch-fx-canvas" id="ch-fx-canvas"></div>

    <?php // ── Barra flutuante ─────────────────────────────────────────── ?>
    <div class="ch-fx-barra">
      <div class="ch-fx-grupo ch-fx-grupo--tit ch-fx-flut">
        <a href="<?= $base ?>/admin/chat/fluxos" class="ch-btn ch-btn--ico ch-btn--sm ch-btn--texto" title="Voltar para a lista">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        </a>
        <input type="text" class="ch-fx-nome-input" id="ch-fx-nome"
               value="<?= $h($fluxo['nome']) ?>" maxlength="120" title="Clique para renomear">
        <span class="ch-badge" style="color:<?= $stCor ?>;background:color-mix(in srgb, <?= $stCor ?> 14%, transparent);">
          <?= $h($stLbl) ?> · v<?= (int)$fluxo['versao_publicada'] ?>
        </span>
        <span class="ch-fx-estado" id="ch-fx-estado"></span>
      </div>

      <div class="ch-fx-grupo ch-fx-flut">
        <button type="button" class="ch-fx-zoom-btn" id="ch-fx-desfazer" title="Desfazer (Ctrl+Z)" disabled>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"/><path d="M3 13a9 9 0 1 0 3-7.7L3 8"/></svg>
        </button>
        <button type="button" class="ch-fx-zoom-btn" id="ch-fx-refazer" title="Refazer (Ctrl+Shift+Z)" disabled>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 7v6h-6"/><path d="M21 13a9 9 0 1 1-3-7.7L21 8"/></svg>
        </button>

        <span class="ch-fx-sep"></span>

        <button type="button" class="ch-btn ch-btn--sm ch-btn--texto" id="ch-fx-cfg">Regras</button>
        <button type="button" class="ch-btn ch-btn--sm" id="ch-fx-salvar">Salvar</button>
        <button type="button" class="ch-btn ch-btn--sm ch-btn--pri" id="ch-fx-publicar">Publicar</button>

        <?php if ($fluxo['status'] === 'publicado'): ?>
          <button type="button" class="ch-btn ch-btn--sm ch-fx-status" data-status="pausado">Pausar</button>
        <?php elseif ($fluxo['status'] === 'pausado'): ?>
          <button type="button" class="ch-btn ch-btn--sm ch-fx-status" data-status="publicado">Reativar</button>
        <?php endif; ?>
      </div>
    </div>

    <?php // ── Regras do fluxo (flutuante) ─────────────────────────────── ?>
    <div class="ch-fx-regras ch-fx-flut" id="ch-fx-cfg-box">
      <div class="ch-campo" style="margin-bottom:0;">
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

    <?php // ── Botão + e paleta ────────────────────────────────────────── ?>
    <button type="button" class="ch-fx-fab" id="ch-fx-fab" title="Adicionar bloco" aria-expanded="false">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>

    <div class="ch-fx-paleta ch-fx-flut" id="ch-fx-paleta">
      <div class="ch-fx-pal-cab">
        <strong>Adicionar bloco</strong>
        <button type="button" class="ch-fx-zoom-btn" id="ch-fx-pal-fechar" title="Fechar">&times;</button>
      </div>
      <div class="ch-fx-pal-corpo" id="ch-fx-pal-corpo"></div>
    </div>

    <?php // ── Zoom ────────────────────────────────────────────────────── ?>
    <div class="ch-fx-zoom ch-fx-flut">
      <button type="button" class="ch-fx-zoom-btn" id="ch-fx-zoom-out" title="Diminuir">−</button>
      <span class="ch-fx-zoom-pct" id="ch-fx-zoom-pct">100%</span>
      <button type="button" class="ch-fx-zoom-btn" id="ch-fx-zoom-in" title="Aumentar">+</button>
      <span class="ch-fx-sep"></span>
      <button type="button" class="ch-fx-zoom-btn" id="ch-fx-zoom-reset" title="Enquadrar tudo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8V5a2 2 0 0 1 2-2h3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3"/></svg>
      </button>
    </div>

    <?php // ── Painel do bloco: só existe com bloco selecionado ────────── ?>
    <div class="ch-fx-painel ch-fx-flut" id="ch-fx-painel">
      <div class="ch-fx-painel-head">
        <div style="min-width:0;">
          <div class="ch-fx-painel-tit" id="ch-fx-p-titulo"></div>
          <div class="ch-fx-painel-chave" id="ch-fx-p-chave"></div>
        </div>
        <button type="button" class="ch-fx-zoom-btn" id="ch-fx-p-fechar" title="Fechar">&times;</button>
      </div>
      <div class="ch-fx-painel-campos" id="ch-fx-p-campos"></div>
      <div class="ch-fx-painel-pe">
        <button type="button" class="ch-btn ch-btn--perigo-vazado ch-btn--sm" id="ch-fx-excluir-no" style="width:100%;">
          Excluir bloco
        </button>
      </div>
    </div>

    <div class="ch-fx-dica" id="ch-fx-dica">
      Arraste da bolinha da direita até a da esquerda para ligar · <strong>Delete</strong> apaga o bloco
    </div>

    <div class="ch-fx-msg" id="ch-fx-msg"></div>

  </div>
</div>

<script>
  // Antes do primeiro paint: evita o painel aparecer rolando e depois travar
  document.body.classList.add('ch-fx-modo');

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
