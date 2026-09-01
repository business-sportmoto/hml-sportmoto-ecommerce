<?php
/**
 * admin/views/chat/contato-show.php
 * @var array $contato @var array|null $conversa @var array $sessoes
 * @var array $notas @var array $tags @var array|null $cliente @var bool $podeGerir
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n    = fn($v) => number_format((float)$v, 0, ',', '.');

$statusSessao = [
    'ativo'               => ['Em andamento', 'info'],
    'dormindo'            => ['Esperando',    'neutro'],
    'aguardando_resposta' => ['Aguardando resposta', 'aviso'],
    'concluido'           => ['Concluída',    'ok'],
    'saiu'                => ['Saiu',         'neutro'],
    'erro'                => ['Erro',         'erro'],
];
$tagsDoContato = array_column($contato['tags'], 'id');
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1><?= $h($contato['nome_exibicao']) ?></h1>
      <p class="ch-mono"><?= $h($contato['telefone_exibicao'] ?: $contato['wa_id']) ?></p>
    </div>
    <div class="ch-head-acoes">
      <a href="<?= $base ?>/admin/chat/contatos" class="ch-btn">← Contatos</a>
      <?php if ($conversa): ?>
        <a href="<?= $base ?>/admin/chat/inbox?conversa=<?= (int)$conversa['id'] ?>" class="ch-btn ch-btn--wa">Abrir conversa</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ((int)$contato['bloqueado']): ?>
    <div class="ch-aviso ch-aviso--erro">
      <div><strong class="ch-aviso-tit">Contato bloqueado</strong> Nenhuma mensagem será enviada para este número.</div>
    </div>
  <?php elseif (!(int)$contato['optin']): ?>
    <div class="ch-aviso ch-aviso--aviso">
      <div>
        <strong class="ch-aviso-tit">Fez opt-out</strong>
        <?= $contato['optout_em'] ? 'Em ' . date('d/m/Y H:i', strtotime((string)$contato['optout_em'])) . '. ' : '' ?>
        <?= $h($contato['optout_motivo'] ?: '') ?>
        Só mensagens transacionais podem ser enviadas.
      </div>
    </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:16px;align-items:start;">

    <?php // ── Coluna principal ─────────────────────────────────────────── ?>
    <div>
      <div class="ch-card" style="margin-bottom:16px;">
        <div class="ch-card-head"><h2>Dados</h2></div>
        <div class="ch-card-body">
          <?php if ($podeGerir): ?>
          <form id="ch-form-dados">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf_token ?? '') ?>">
            <div class="ch-grid-2">
              <div class="ch-campo">
                <label class="ch-label">Nome</label>
                <input type="text" class="ch-input" name="nome" value="<?= $h($contato['nome']) ?>"
                       placeholder="<?= $h($contato['nome_perfil'] ?: 'sem nome') ?>">
                <?php if ($contato['nome_perfil']): ?>
                  <div class="ch-ajuda">Nome no WhatsApp: <?= $h($contato['nome_perfil']) ?></div>
                <?php endif; ?>
              </div>
              <div class="ch-campo">
                <label class="ch-label">E-mail</label>
                <input type="email" class="ch-input" name="email" value="<?= $h($contato['email']) ?>">
              </div>
            </div>
            <div class="ch-flex" style="justify-content:flex-end;gap:8px;">
              <span id="ch-dados-msg" class="ch-sm"></span>
              <button type="submit" class="ch-btn ch-btn--pri ch-btn--sm">Salvar</button>
            </div>
          </form>
          <?php else: ?>
            <div class="ch-dado"><dt>Nome</dt><dd><?= $h($contato['nome_exibicao']) ?></dd></div>
            <div class="ch-dado"><dt>E-mail</dt><dd><?= $h($contato['email'] ?: '—') ?></dd></div>
          <?php endif; ?>
        </div>
      </div>

      <?php // ── Campos personalizados ─────────────────────────────────── ?>
      <div class="ch-card" style="margin-bottom:16px;">
        <div class="ch-card-head">
          <h2>Campos personalizados</h2>
          <span class="ch-sm ch-mut">preenchidos pelos fluxos ou à mão</span>
        </div>
        <div class="ch-card-body">
          <div id="ch-campos-lista">
            <?php if (!$contato['campos']): ?>
              <div class="ch-sm ch-mut" style="margin-bottom:12px;">Nenhum campo preenchido.</div>
            <?php else: ?>
              <?php foreach ($contato['campos'] as $k => $v): ?>
                <div class="ch-dado">
                  <dt class="ch-mono"><?= $h($k) ?></dt>
                  <dd>
                    <?= $h($v) ?>
                    <?php if ($podeGerir): ?>
                      <button type="button" class="ch-fx-lista-rm ch-campo-rm" data-k="<?= $h($k) ?>"
                              style="display:inline-grid;vertical-align:middle;margin-left:6px;">×</button>
                    <?php endif; ?>
                  </dd>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <?php if ($podeGerir): ?>
          <div class="ch-flex" style="margin-top:12px;">
            <input type="text" class="ch-input" id="ch-campo-k" placeholder="nome_do_campo" style="max-width:200px;">
            <input type="text" class="ch-input" id="ch-campo-v" placeholder="valor">
            <button type="button" class="ch-btn ch-btn--sm" id="ch-campo-add">Adicionar</button>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php // ── Histórico de fluxos ───────────────────────────────────── ?>
      <div class="ch-card">
        <div class="ch-card-head"><h2>Fluxos por que passou</h2></div>
        <?php if (!$sessoes): ?>
          <div class="ch-vazio">Este contato nunca entrou em um fluxo.</div>
        <?php else: ?>
        <div class="ch-tabela-wrap">
          <table class="ch-tabela">
            <thead><tr><th>Fluxo</th><th>Situação</th><th>Parou em</th><th>Quando</th></tr></thead>
            <tbody>
              <?php foreach ($sessoes as $s):
                [$lbl, $cor] = $statusSessao[$s['status']] ?? [$s['status'], 'neutro']; ?>
              <tr>
                <td><?= $h($s['fluxo_nome'] ?: 'fluxo removido') ?> <span class="ch-sm ch-mut">v<?= (int)$s['versao'] ?></span></td>
                <td><span class="ch-badge ch-badge--<?= $cor ?>"><?= $h($lbl) ?></span></td>
                <td class="ch-mono ch-sm"><?= $h($s['no_atual']) ?></td>
                <td class="ch-sm ch-mut"><?= date('d/m/Y H:i', strtotime((string)$s['criado_em'])) ?></td>
              </tr>
              <?php if ($s['erro_detalhe']): ?>
              <tr><td colspan="4" class="ch-sm" style="color:var(--danger);padding-top:0;"><?= $h($s['erro_detalhe']) ?></td></tr>
              <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php // ── Coluna lateral ───────────────────────────────────────────── ?>
    <div>
      <div class="ch-card" style="margin-bottom:16px;">
        <div class="ch-card-head"><h2>Situação</h2></div>
        <div class="ch-card-body">
          <div class="ch-dado"><dt>Janela 24h</dt><dd>
            <?= $contato['na_janela']
                  ? '<span style="color:var(--success)">aberta</span>'
                  : '<span class="ch-mut">fechada</span>' ?>
          </dd></div>
          <div class="ch-dado"><dt>Recebidas</dt><dd><?= $n($contato['total_entrada']) ?></dd></div>
          <div class="ch-dado"><dt>Enviadas</dt><dd><?= $n($contato['total_saida']) ?></dd></div>
          <div class="ch-dado"><dt>Origem</dt><dd><?= $h($contato['origem'] ?: '—') ?></dd></div>
          <div class="ch-dado"><dt>Cadastrado</dt><dd><?= date('d/m/Y', strtotime((string)$contato['criado_em'])) ?></dd></div>

          <?php if ($podeGerir): ?>
          <div style="margin-top:14px;display:flex;flex-direction:column;gap:7px;">
            <button type="button" class="ch-btn ch-btn--sm" id="ch-optin"
                    data-valor="<?= (int)$contato['optin'] ? 0 : 1 ?>">
              <?= (int)$contato['optin'] ? 'Registrar opt-out' : 'Reativar recebimento' ?>
            </button>
            <button type="button" class="ch-btn ch-btn--sm <?= (int)$contato['bloqueado'] ? '' : 'ch-btn--perigo' ?>"
                    id="ch-bloquear" data-valor="<?= (int)$contato['bloqueado'] ? 0 : 1 ?>">
              <?= (int)$contato['bloqueado'] ? 'Desbloquear' : 'Bloquear contato' ?>
            </button>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="ch-card" style="margin-bottom:16px;">
        <div class="ch-card-head"><h2>Tags</h2></div>
        <div class="ch-card-body">
          <div class="ch-tags-linha" id="ch-tags-atuais" style="margin-bottom:10px;">
            <?php if (!$contato['tags']): ?>
              <span class="ch-sm ch-mut">Sem tags</span>
            <?php else: foreach ($contato['tags'] as $t): ?>
              <span class="ch-tag" style="color:<?= $h($t['cor']) ?>;background:<?= $h($t['cor']) ?>22;">
                <?= $h($t['nome']) ?>
                <?php if ($podeGerir): ?>
                  <a href="#" class="ch-tag-rm" data-id="<?= (int)$t['id'] ?>" style="color:inherit;text-decoration:none;">×</a>
                <?php endif; ?>
              </span>
            <?php endforeach; endif; ?>
          </div>

          <?php if ($podeGerir): ?>
          <select class="ch-select" id="ch-tag-add">
            <option value="">+ aplicar tag...</option>
            <?php foreach ($tags as $t): ?>
              <?php if (in_array((int)$t['id'], $tagsDoContato, true)) continue; ?>
              <option value="<?= (int)$t['id'] ?>"><?= $h($t['nome']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
        </div>
      </div>

      <div class="ch-card" style="margin-bottom:16px;">
        <div class="ch-card-head"><h2>Cliente da loja</h2></div>
        <div class="ch-card-body">
          <?php if ($cliente): ?>
            <div class="ch-dado"><dt>Nome</dt><dd><?= $h($cliente['nome']) ?></dd></div>
            <div class="ch-dado"><dt>E-mail</dt><dd><?= $h($cliente['email']) ?></dd></div>
            <div class="ch-dado"><dt>Pedidos</dt><dd><?= $n($cliente['pedidos']) ?></dd></div>
            <div class="ch-dado"><dt>Total gasto</dt><dd>R$ <?= number_format((float)$cliente['gasto'], 2, ',', '.') ?></dd></div>
            <div style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap;">
              <a href="<?= $base ?>/admin/clientes/<?= (int)$cliente['id'] ?>" class="ch-btn ch-btn--sm">Ver cadastro</a>
              <?php if ($podeGerir): ?>
                <button type="button" class="ch-btn ch-btn--sm" id="ch-desvincular">Desvincular</button>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <p class="ch-sm ch-mut" style="margin:0 0 12px;">
              Não vinculado. O sistema tenta ligar sozinho pelo telefone quando a pessoa escreve.
            </p>
            <?php if ($podeGerir): ?>
              <input type="text" class="ch-input" id="ch-busca-cliente" placeholder="Buscar cliente por nome, e-mail ou CPF">
              <div id="ch-res-cliente" style="margin-top:8px;"></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="ch-card">
        <div class="ch-card-head"><h2>Notas internas</h2></div>
        <div class="ch-card-body">
          <?php if (!$notas): ?>
            <div class="ch-sm ch-mut">Nenhuma nota.</div>
          <?php else: foreach ($notas as $nt): ?>
            <div class="ch-nota">
              <?= nl2br($h($nt['nota'])) ?>
              <div class="ch-nota-meta"><?= $h($nt['autor'] ?: 'Sistema') ?> · <?= date('d/m/Y H:i', strtotime((string)$nt['criado_em'])) ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function ($) {
  var BASE = window.BASE_URL || '<?= $base ?>';
  var CSRF = '<?= $h($csrf_token ?? '') ?>';
  var ID   = <?= (int)$contato['id'] ?>;

  function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
  function post(rota, dados) { return $.post(BASE + rota, $.extend({ csrf_token: CSRF }, dados), null, 'json'); }

  $('#ch-form-dados').on('submit', function (e) {
    e.preventDefault();
    post('/admin/chat/contatos/' + ID + '/salvar', $(this).serialize()).done(function (r) {
      $('#ch-dados-msg').text(r.ok ? 'Salvo.' : 'Falha.').css('color', r.ok ? 'var(--success)' : 'var(--danger)');
      setTimeout(function () { $('#ch-dados-msg').text(''); }, 2500);
    });
  });

  $('#ch-tag-add').on('change', function () {
    var id = $(this).val();
    if (!id) return;
    post('/admin/chat/contatos/' + ID + '/tag', { tag_id: id, acao: 'adicionar' })
      .done(function () { location.reload(); });
  });

  $('.ch-tag-rm').on('click', function (e) {
    e.preventDefault();
    post('/admin/chat/contatos/' + ID + '/tag', { tag_id: $(this).data('id'), acao: 'remover' })
      .done(function () { location.reload(); });
  });

  $('#ch-campo-add').on('click', function () {
    var k = ($('#ch-campo-k').val() || '').trim();
    var v = $('#ch-campo-v').val() || '';
    if (!k) return;
    post('/admin/chat/contatos/' + ID + '/campo', { chave: k, valor: v })
      .done(function () { location.reload(); });
  });

  $('.ch-campo-rm').on('click', function () {
    if (!confirm('Apagar este campo?')) return;
    post('/admin/chat/contatos/' + ID + '/campo', { chave: $(this).data('k'), valor: '' })
      .done(function () { location.reload(); });
  });

  $('#ch-optin').on('click', function () {
    var v = $(this).data('valor');
    if (!v && !confirm('Registrar opt-out? O contato deixa de receber mensagens de marketing.')) return;
    post('/admin/chat/contatos/' + ID + '/optin', { optin: v }).done(function () { location.reload(); });
  });

  $('#ch-bloquear').on('click', function () {
    post('/admin/chat/contatos/' + ID + '/bloquear', { bloqueado: $(this).data('valor') })
      .done(function () { location.reload(); });
  });

  $('#ch-desvincular').on('click', function () {
    if (!confirm('Desvincular deste cliente?')) return;
    post('/admin/chat/contatos/' + ID + '/vincular', { cliente_id: 0 }).done(function () { location.reload(); });
  });

  var t = null;
  $('#ch-busca-cliente').on('input', function () {
    var q = $(this).val();
    clearTimeout(t);
    if (q.length < 2) { $('#ch-res-cliente').empty(); return; }
    t = setTimeout(function () {
      $.get(BASE + '/admin/chat/contatos/buscar-clientes', { q: q }, function (r) {
        if (!r.ok || !r.itens.length) { $('#ch-res-cliente').html('<div class="ch-sm ch-mut">Nada encontrado.</div>'); return; }
        $('#ch-res-cliente').html(r.itens.map(function (c) {
          return '<div class="ch-flex-sb" style="padding:6px 0;border-bottom:1px solid var(--border);">' +
                 '<div><div class="ch-sm ch-b">' + esc(c.nome) + '</div>' +
                 '<div class="ch-sm ch-mut">' + esc(c.email) + '</div></div>' +
                 '<button type="button" class="ch-btn ch-btn--sm ch-vincular" data-id="' + c.id + '">Vincular</button></div>';
        }).join(''));
      }, 'json');
    }, 350);
  });

  $(document).on('click', '.ch-vincular', function () {
    post('/admin/chat/contatos/' + ID + '/vincular', { cliente_id: $(this).data('id') })
      .done(function () { location.reload(); });
  });
})(jQuery);
</script>
