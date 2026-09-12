<?php
/**
 * Quadro (kanban) da Central de Recuperação.
 *
 * Recebe: $colunas (config + primeira página de cards), $resumo (contagem e
 * valor por coluna), $porColuna, $filtros, $responsaveis, $ehGestor, $ehSuper.
 *
 * Escala: o quadro NUNCA carrega tudo. Cada coluna traz <?= (int) $porColuna ?>
 * cards e pede a próxima página sob demanda; os contadores do cabeçalho vêm de
 * uma consulta agrupada, não de contar cards na tela.
 */
$qsFiltros = array_filter([
    'q'              => $filtros['q'],
    'prioridade'     => $filtros['prioridade'],
    'responsavel_id' => $filtros['responsavel_id'],
    'contato'        => $filtros['contato'],
    'data_de'        => $filtros['data_de'],
    'data_ate'       => $filtros['data_ate'],
    'valor_min'      => $filtros['valor_min'],
    'valor_max'      => $filtros['valor_max'],
    'ordenar'        => $filtros['ordenar'],
], static fn($v) => $v !== '' && $v !== 0);

$totalGeral = array_sum(array_column($resumo, 'total'));
?>

<style>
/* Quadro da Central de Recuperação — escopo todo em .cr-* */
.cr-topo { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
.cr-toggle { display:inline-flex; border:1px solid var(--border); border-radius:8px; overflow:hidden; }
.cr-toggle a { padding:7px 14px; font-size:13px; font-weight:600; color:var(--text-2); text-decoration:none; }
.cr-toggle a.ativo { background:var(--primary, #2563eb); color:#fff; }

.cr-board { display:flex; gap:14px; overflow-x:auto; padding:4px 2px 16px; scroll-snap-type:x proximity; }
.cr-col { flex:0 0 300px; scroll-snap-align:start; background:var(--surface2, #f6f7f9);
          border:1px solid var(--border); border-radius:12px; display:flex; flex-direction:column;
          max-height:calc(100vh - 290px); }
.cr-col-topo { position:sticky; top:0; z-index:2; padding:12px 14px; border-bottom:1px solid var(--border);
               background:var(--surface, #fff); border-radius:12px 12px 0 0; }
.cr-col-titulo { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:800; }
.cr-col-ponto { width:8px; height:8px; border-radius:50%; }
.cr-col-num { margin-left:auto; font-size:12px; color:var(--text-3); font-weight:700; }
.cr-col-valor { font-size:12px; color:var(--text-3); margin-top:2px; }
.cr-col-corpo { padding:10px; display:flex; flex-direction:column; gap:8px; overflow-y:auto; flex:1; min-height:80px; }
.cr-col.cr-col--alvo { outline:2px dashed var(--primary, #2563eb); outline-offset:-4px; }
.cr-col-mais { margin:0 10px 10px; }
.cr-col-vazia { color:var(--text-3); font-size:12px; text-align:center; padding:18px 8px; }

.cr-card { background:var(--surface, #fff); border:1px solid var(--border); border-left:3px solid var(--cr-prio);
           border-radius:10px; padding:10px 12px; cursor:grab; transition:box-shadow .15s, transform .15s; }
.cr-card:hover { box-shadow:0 4px 14px rgba(0,0,0,.08); transform:translateY(-1px); }
.cr-card.cr-card--arrastando { opacity:.45; cursor:grabbing; }
.cr-card-topo { display:flex; align-items:baseline; gap:8px; }
.cr-card-nome { font-size:13px; font-weight:700; color:var(--text-1); text-decoration:none;
                overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cr-card-nome:hover { text-decoration:underline; }
.cr-card-tempo { margin-left:auto; font-size:11px; color:var(--text-3); white-space:nowrap; }
.cr-card-valor { font-size:16px; font-weight:800; margin:6px 0 8px; }
.cr-card-tags { display:flex; flex-wrap:wrap; gap:4px; margin-bottom:8px; }
.cr-tag { font-size:10px; padding:2px 7px; border-radius:20px; background:var(--surface2, #f1f2f4); color:var(--text-2); }
.cr-tag--status { background:var(--bg, #eef2f7); font-weight:600; }
.cr-tag--prio { background:var(--danger-lt, #fee2e2); color:var(--danger); font-weight:700; }
.cr-card-rodape { display:flex; align-items:center; justify-content:space-between; gap:8px;
                  font-size:11px; color:var(--text-3); }
.cr-card-dono--pool { font-style:italic; }
.cr-card-canais { display:flex; gap:6px; }
.cr-card-agenda { margin-top:8px; font-size:11px; color:var(--text-3);
                  border-top:1px dashed var(--border); padding-top:6px; }
.cr-card-agenda--atrasada { color:var(--danger); font-weight:600; }

@media (max-width:720px) { .cr-col { flex-basis:82vw; } }
</style>

<div class="cr-topo">
  <div>
    <h1 style="font-size:22px;font-weight:800;margin:0;">
      Carrinhos abandonados
      <span class="badge" style="background:var(--danger-lt);color:var(--danger);font-size:12px;
            vertical-align:middle;margin-left:6px;"><?= number_format($totalGeral, 0, ',', '.') ?></span>
    </h1>
    <p style="margin:4px 0 0;color:var(--text-3);font-size:13px;">
      Arraste um card para mudar a etapa. Cada coluna carrega
      <?= (int) $porColuna ?> por vez.
    </p>
  </div>

  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <span class="cr-toggle">
      <a href="<?= ADMIN_URL ?>/carrinhos-abandonados<?= $qsFiltros ? '?' . http_build_query($qsFiltros) : '' ?>">Lista</a>
      <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/quadro<?= $qsFiltros ? '?' . http_build_query($qsFiltros) : '' ?>"
         class="ativo">Quadro</a>
    </span>
    <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/dashboard" class="btn">📊 Dashboard</a>
    <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/templates" class="btn">⚙ Templates</a>
  </div>
</div>

<!-- Filtros: os mesmos da lista, menos o status (as colunas SÃO o status) -->
<div class="admin-card" style="margin:16px 0;padding:16px 18px;">
  <form method="get" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end;">
    <div class="form-group" style="grid-column:span 2;">
      <label class="form-label">Buscar</label>
      <input type="text" name="q" class="form-control" value="<?= View::e($filtros['q']) ?>"
             placeholder="cliente, telefone, e-mail ou produto">
    </div>

    <div class="form-group">
      <label class="form-label">Prioridade</label>
      <select name="prioridade" class="form-control">
        <option value="">Todas</option>
        <?php foreach (['imediata' => 'Imediata', 'alta' => 'Alta', 'media' => 'Média', 'baixa' => 'Baixa'] as $v => $l): ?>
        <option value="<?= $v ?>" <?= $filtros['prioridade'] === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label">Responsável</label>
      <select name="responsavel_id" class="form-control">
        <option value="">Todos</option>
        <option value="pool" <?= $filtros['responsavel_id'] === 'pool' ? 'selected' : '' ?>>Sem responsável</option>
        <?php foreach ($responsaveis as $r): ?>
        <option value="<?= (int) $r['id'] ?>" <?= (int) $filtros['responsavel_id'] === (int) $r['id'] ? 'selected' : '' ?>>
          <?= View::e($r['nome']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label">Contato</label>
      <select name="contato" class="form-control">
        <option value="">Qualquer</option>
        <option value="com_telefone" <?= $filtros['contato'] === 'com_telefone' ? 'selected' : '' ?>>Com telefone</option>
        <option value="com_email"    <?= $filtros['contato'] === 'com_email'    ? 'selected' : '' ?>>Com e-mail</option>
        <option value="sem_contato"  <?= $filtros['contato'] === 'sem_contato'  ? 'selected' : '' ?>>Sem contato</option>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label">Ordenar</label>
      <select name="ordenar" class="form-control">
        <?php foreach (['prioridade' => 'Prioridade', 'valor' => 'Maior valor', 'data' => 'Mais recente',
                        'interacao' => 'Última interação', 'score' => 'Score'] as $v => $l): ?>
        <option value="<?= $v ?>" <?= $filtros['ordenar'] === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label">Período</label>
      <div style="display:flex;gap:6px;">
        <input type="date" name="data_de"  class="form-control" value="<?= View::e($filtros['data_de']) ?>">
        <input type="date" name="data_ate" class="form-control" value="<?= View::e($filtros['data_ate']) ?>">
      </div>
    </div>

    <div class="form-group" style="display:flex;gap:8px;">
      <button type="submit" class="btn btn-primary" style="flex:1">Filtrar</button>
      <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/quadro" class="btn">Limpar</a>
    </div>
  </form>
</div>

<div class="cr-board" id="cr-board">
  <?php foreach ($colunas as $chave => $col): ?>
  <?php $r = $resumo[$chave] ?? ['total' => 0, 'valor' => 0]; ?>
  <section class="cr-col" data-coluna="<?= View::e($chave) ?>"
           data-canonico="<?= View::e($col['canonico']) ?>"
           data-pagina="1" data-total="<?= (int) $r['total'] ?>">
    <header class="cr-col-topo">
      <div class="cr-col-titulo">
        <span class="cr-col-ponto" style="background:<?= $col['cor'] ?>"></span>
        <?= View::e($col['titulo']) ?>
        <span class="cr-col-num"><?= number_format((int) $r['total'], 0, ',', '.') ?></span>
      </div>
      <div class="cr-col-valor"><?= PriceHelper::format((float) $r['valor']) ?></div>
    </header>

    <div class="cr-col-corpo">
      <?php foreach ($col['cards'] as $rec): ?>
        <?php View::partial('carrinhos-abandonados/_card', ['rec' => $rec, 'ehGestor' => $ehGestor]); ?>
      <?php endforeach; ?>

      <?php if (!$col['cards']): ?>
      <p class="cr-col-vazia">Nada aqui.</p>
      <?php endif; ?>
    </div>

    <?php if ((int) $r['total'] > count($col['cards'])): ?>
    <button type="button" class="btn btn-sm cr-col-mais">
      Carregar mais (<?= number_format((int) $r['total'] - count($col['cards']), 0, ',', '.') ?>)
    </button>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
</div>

<script>
(function ($) {
  // BASE_URL e CSRF_TOKEN só são declarados pelo layout DEPOIS do conteúdo.
  const BASE = '<?= ADMIN_URL ?>/carrinhos-abandonados';
  const CSRF = '<?= SecurityHelper::generateCsrf() ?>';
  const FILTROS = <?= json_encode($qsFiltros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  // ── Carregar mais: uma página de UMA coluna ───────────────
  $(document).on('click', '.cr-col-mais', function () {
    const $btn  = $(this);
    const $col  = $btn.closest('.cr-col');
    const prox  = parseInt($col.data('pagina'), 10) + 1;

    $btn.prop('disabled', true).text('Carregando...');

    $.get(BASE + '/quadro/coluna',
      Object.assign({ coluna: $col.data('coluna'), pagina: prox }, FILTROS),
      function (res) {
        if (!res.ok) { showToast(res.msg || 'Erro ao carregar.', 'error'); $btn.prop('disabled', false); return; }

        $col.find('.cr-col-corpo').append(res.html);
        $col.data('pagina', prox);
        atualizarBotao($col);
      }, 'json')
      .fail(() => { showToast('Falha de rede.', 'error'); $btn.prop('disabled', false).text('Carregar mais'); });
  });

  function atualizarBotao($col) {
    const total   = parseInt($col.data('total'), 10) || 0;
    const na_tela = $col.find('.cr-card').length;
    const $btn    = $col.find('.cr-col-mais');

    if (na_tela >= total) { $btn.remove(); return; }
    $btn.prop('disabled', false).text('Carregar mais (' + (total - na_tela) + ')');
  }

  function ajustarContador($col, delta) {
    const total = Math.max(0, (parseInt($col.data('total'), 10) || 0) + delta);
    $col.data('total', total);
    $col.find('.cr-col-num').text(total.toLocaleString('pt-BR'));
    $col.find('.cr-col-vazia').toggle($col.find('.cr-card').length === 0);
  }

  // ── Arrastar ──────────────────────────────────────────────
  let arrastando = null;

  $(document).on('dragstart', '.cr-card', function (e) {
    arrastando = this;
    $(this).addClass('cr-card--arrastando');
    e.originalEvent.dataTransfer.effectAllowed = 'move';
    e.originalEvent.dataTransfer.setData('text/plain', $(this).data('id'));
  });

  $(document).on('dragend', '.cr-card', function () {
    $(this).removeClass('cr-card--arrastando');
    $('.cr-col').removeClass('cr-col--alvo');
    arrastando = null;
  });

  $(document).on('dragover', '.cr-col', function (e) {
    e.preventDefault();
    $(this).addClass('cr-col--alvo');
  });

  $(document).on('dragleave', '.cr-col', function () { $(this).removeClass('cr-col--alvo'); });

  $(document).on('drop', '.cr-col', function (e) {
    e.preventDefault();
    const $col = $(this).removeClass('cr-col--alvo');
    if (!arrastando) return;

    const $card  = $(arrastando);
    const $de    = $card.closest('.cr-col');
    const status = $col.data('canonico');

    if ($de.is($col)) return;                       // mesma coluna: nada a gravar

    // "Perdido" exige motivo — a regra é do serviço, a tela só pergunta.
    if (status === 'perdido') {
        pedirMotivo(motivo => { if (motivo !== null) mover($card, $de, $col, status, motivo); });
        return;
    }
    mover($card, $de, $col, status, '');
  });

  function mover($card, $de, $para, status, motivo) {
    const id = $card.data('id');

    $.post(BASE + '/' + id + '/status',
      { status: status, motivo: motivo, _csrf_token: CSRF },
      function (res) {
        if (!res.ok) { showToast(res.msg || 'Não foi possível mudar a etapa.', 'error'); return; }

        $para.find('.cr-col-corpo').prepend($card);
        $card.find('.cr-tag--status').text($para.find('.cr-col-titulo').clone().children().remove().end().text().trim());
        $card.attr('data-status', status);

        ajustarContador($de, -1);
        ajustarContador($para, +1);
        atualizarBotao($de);

        showToast('Carrinho movido.', 'success');
      }, 'json')
      .fail(() => showToast('Falha de rede — nada foi alterado.', 'error'));
  }

  function pedirMotivo(callback) {
    // O onClose dispara TAMBÉM quando fechamos após confirmar; sem esta marca,
    // o cancelamento chegaria depois do motivo e desfaria a escolha.
    let confirmado = false;

    const drawer = adminDrawer({
      titulo  : 'Marcar como perdido',
      tamanho : 'sm',
      conteudo: `
        <div class="form-group">
          <label class="pe-label">Por que este carrinho foi perdido?</label>
          <input type="text" id="cr-motivo" class="form-control" maxlength="120"
                 placeholder="Ex.: comprou em outro lugar, desistiu, sem resposta">
          <p class="pe-field-hint">Obrigatório — fica no histórico do carrinho.</p>
        </div>
        <button type="button" class="btn btn-primary" style="width:100%" id="cr-motivo-ok">Confirmar</button>`,
      onClose : () => { if (!confirmado) callback(null); },
    });

    $(drawer.corpo()).on('click', '#cr-motivo-ok', function () {
      const motivo = ($('#cr-motivo').val() || '').trim();
      if (!motivo) { showToast('Escreva o motivo.', 'error'); return; }
      confirmado = true;
      drawer.fechar('ok', { force: true });
      callback(motivo);
    });
  }
})(jQuery);
</script>
