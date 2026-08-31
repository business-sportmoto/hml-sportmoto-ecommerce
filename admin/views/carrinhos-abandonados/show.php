<?php
// views/admin/carrinhos-abandonados/show.php
// Variáveis: $rec, $itens, $eventos, $responsaveis, $templatesWpp, $templatesMail

$statusCfg = [
  'novo' => ['Novo','var(--text-2)'], 'abandonado' => ['Abandonado','var(--danger)'],
  'em_recuperacao' => ['Em recuperação','var(--warning)'], 'msg_enviada' => ['Msg enviada','var(--blue)'],
  'aguardando_resposta' => ['Aguardando resposta','var(--purple)'], 'respondeu' => ['Respondeu','var(--info)'],
  'negociacao' => ['Negociação','var(--purple)'], 'recuperado' => ['Recuperado','var(--success)'],
  'perdido' => ['Perdido','var(--text-2)'], 'ignorado' => ['Ignorado','var(--text-3)'],
  'sem_contato' => ['Sem contato','var(--danger)'],
];
$eventoIcones = [
  'abandono_detectado' => '🛒', 'status_alterado' => '🔄', 'whatsapp_enviado' => '💬',
  'email_enviado' => '✉️', 'anotacao' => '📝', 'responsavel_alterado' => '👤',
  'agendamento' => '📅', 'cliente_retornou' => '👋', 'recuperado' => '✅', 'perdido' => '❌',
];
$semOptIn = isset($rec['aceita_marketing']) && !(int)$rec['aceita_marketing'];
?>
<div class="ap-page-header" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
  <a href="<?= ADMIN_URL ?>/carrinhos-abandonados" class="btn">← Voltar</a>
  <h1 style="font-size:20px;font-weight:800;margin:0;">
    Carrinho #<?= (int)$rec['carrinho_id'] ?>
  </h1>
  <span class="badge" id="status-badge" style="background:<?= $statusCfg[$rec['status']][1] ?>22;
        color:<?= $statusCfg[$rec['status']][1] ?>;font-weight:700;">
    <?= $statusCfg[$rec['status']][0] ?></span>
  <span style="color:var(--c-text-muted);font-size:13px;">
    abandonado em <?= date('d/m/Y H:i', strtotime($rec['abandonado_em'])) ?>
  </span>
</div>

<?php if ($semOptIn && ($rec['cliente_telefone'] || $rec['cliente_email'])): ?>
<div style="background:var(--warning-lt);border:1px solid var(--warning-bd);border-radius:10px;
     padding:12px 16px;margin:14px 0;font-size:13px;color:var(--warning);">
  ⚠️ <strong>LGPD:</strong> este cliente não possui opt-in de marketing registrado.
  Contato de recuperação é permitido com base em legítimo interesse, mas registre
  a justificativa e interrompa imediatamente se o cliente pedir.
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start;margin-top:14px;">

  <!-- COLUNA PRINCIPAL -->
  <div style="display:flex;flex-direction:column;gap:16px;">

    <!-- Itens -->
    <div class="admin-card">
      <h3 class="ap-card-title">Itens no carrinho
        <span style="float:right;font-weight:800;">
          R$ <?= number_format((float)$rec['valor_snapshot'], 2, ',', '.') ?></span>
      </h3>
      <?php foreach ($itens as $i): ?>
      <div style="display:flex;gap:14px;align-items:center;padding:12px 18px;
                  border-top:1px solid var(--c-border);">
        <div style="width:56px;height:56px;border-radius:8px;background:var(--surface2);
                    overflow:hidden;flex-shrink:0;">
          <?php if ($i['imagem']): ?>
          <img src="<?= UPLOAD_URL ?>/produtos/<?= View::e($i['imagem']) ?>"
               style="width:100%;height:100%;object-fit:cover;" alt="">
          <?php endif; ?>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;font-size:13.5px;"><?= View::e($i['produto_nome']) ?></div>
          <div style="font-size:12px;color:var(--c-text-muted);">
            <?= $i['sku_codigo'] ? 'SKU ' . View::e($i['sku_codigo']) . ' · ' : '' ?>
            <?= (int)$i['quantidade'] ?>× R$ <?= number_format((float)$i['preco_unitario'], 2, ',', '.') ?>
          </div>
        </div>
        <div style="font-weight:800;">R$ <?= number_format((float)$i['subtotal'], 2, ',', '.') ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Timeline -->
    <div class="admin-card">
      <h3 class="ap-card-title">Linha do tempo</h3>
      <div style="padding:6px 18px 16px;">
        <?php if (empty($eventos)): ?>
          <p style="color:var(--c-text-muted);font-size:13px;">Sem eventos ainda.</p>
        <?php endif; ?>
        <?php foreach ($eventos as $e): ?>
        <div style="display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--c-border);">
          <div style="font-size:18px;flex-shrink:0;"><?= $eventoIcones[$e['tipo']] ?? '•' ?></div>
          <div style="flex:1;">
            <div style="font-size:13px;"><?= View::e($e['descricao']) ?></div>
            <div style="font-size:11.5px;color:var(--c-text-muted);margin-top:2px;">
              <?= date('d/m/Y H:i', strtotime($e['criado_em'])) ?>
              <?= $e['admin_nome'] ? ' · ' . View::e($e['admin_nome']) : ' · sistema' ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ASIDE: cliente + ações -->
  <div style="display:flex;flex-direction:column;gap:16px;">

    <div class="admin-card">
      <h3 class="ap-card-title">Cliente</h3>
      <div style="padding:14px 18px;font-size:13.5px;display:flex;flex-direction:column;gap:8px;">
        <?php if ($rec['cliente_id']): ?>
          <div style="font-weight:700;font-size:15px;"><?= View::e($rec['cliente_nome']) ?></div>
          <div>📱 <?= $rec['cliente_telefone'] ? View::e($rec['cliente_telefone'])
                 : '<span style="color:var(--danger);">sem telefone</span>' ?></div>
          <div>✉ <?= $rec['cliente_email'] ? View::e($rec['cliente_email'])
                 : '<span style="color:var(--danger);">sem e-mail</span>' ?></div>
          <?php if ($rec['cliente_cpf']): ?><div>🪪 <?= View::e($rec['cliente_cpf']) ?></div><?php endif; ?>
        <?php else: ?>
          <p style="color:var(--c-text-muted);margin:0;">Visitante não identificado —
            recuperação por contato direto indisponível.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="admin-card">
      <h3 class="ap-card-title">Ações <?= Session::get('usuario_id') ?></h3>
      <div style="padding:14px 18px;display:flex;flex-direction:column;gap:8px;">
        <?php if (empty($rec['responsavel_id'])): ?>
        <button class="btn" id="btn-capturar"
                style="background:var(--text);color:var(--surface);">⚡ Capturar este carrinho</button>
        <?php elseif ((int)$rec['responsavel_id'] !== (int)Session::get('usuario_id')): ?>
        <div style="background:var(--warning-lt);border:1px solid var(--warning-bd);border-radius:8px;
            padding:10px 12px;font-size:12.5px;color:var(--warning);">
          🔒 Capturado por <strong><?= View::e($rec['responsavel_nome']) ?></strong>
        </div>
        <?php elseif ((int)$rec['responsavel_id'] == (int)Session::get('usuario_id')): ?>
        <div style="background:var(--warning-lt);border:1px solid var(--warning-bd);border-radius:8px;
            padding:10px 12px;font-size:12.5px;color:var(--warning);">
          🔒 Meu carrinho
        </div>
        <?php endif; ?>
        <button class="btn" id="btn-whatsapp" style="background:var(--success);color:var(--surface);"
                <?= !$rec['cliente_telefone'] ? 'disabled title="Sem telefone"' : '' ?>>
          💬 Enviar WhatsApp</button>

        <button class="btn" id="btn-email" style="background:var(--blue);color:var(--surface);"
                <?= !$rec['cliente_email'] ? 'disabled title="Sem e-mail"' : '' ?>>
          ✉ Enviar e-mail</button>              

        <button class="btn" id="btn-link">🔗 Copiar link do carrinho</button>

        <select class="form-control" id="sel-status">
          <option value="">Alterar status…</option>
          <?php foreach ($statusCfg as $slug => [$label]): ?>
            <?php if ($slug !== $rec['status']): ?>
            <option value="<?= $slug ?>"><?= $label ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>

        <?php
          $temDono   = !empty($rec['responsavel_id']);
          $podeMexer = $ehGestor && (!$temDono || $ehSuper);
        ?>
        <?php if ($podeMexer): ?>
          <div class="form-group" style="margin:0;">
            <label class="form-label" style="font-size:12px;">
              <?= $temDono ? '🔄 Transferir para (super admin)' : '👤 Atribuir responsável' ?>
            </label>
            <select class="form-control" id="sel-responsavel">
              <option value="">
                <?= $temDono ? 'Transferir para…' : 'Selecionar vendedor…' ?></option>
              <?php foreach ($responsaveis as $r):
                if ((int)$r['id'] === (int)($rec['responsavel_id'] ?? 0)) continue; // pula o dono atual
                $tag = match($r['nivel']) {
                  'super'   => ' (super)',
                  'gerente' => ' (gerente)',
                  default   => '',
                };
              ?>
              <option value="<?= (int)$r['id'] ?>">
                <?= View::e($r['nome']) . $tag ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php elseif ($temDono && $ehGestor && !$ehSuper): ?>
          <div style="background:var(--bg);border:1px solid var(--c-border);border-radius:8px;
              padding:10px 12px;font-size:12px;color:var(--c-text-muted);">
            🔒 Carrinho de <strong><?= View::e($rec['responsavel_nome'] ?? 'vendedor') ?></strong>.
            Apenas um super admin pode transferir.
          </div>
        <?php endif; ?>

        <input type="datetime-local" class="form-control" id="inp-agendar"
               title="Agendar próximo contato">

        <textarea class="form-control" id="inp-anotacao" rows="3"
                  placeholder="Anotação interna…" maxlength="1000"></textarea>
        <button class="btn" id="btn-anotar">📝 Salvar anotação</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal de templates (WhatsApp / e-mail) -->
<div id="modal-tpl" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);
     z-index:200;align-items:center;justify-content:center;padding:20px;">
  <div style="background:var(--surface);border-radius:14px;max-width:520px;width:100%;
              max-height:85vh;overflow-y:auto;padding:22px;">
    <h3 style="margin:0 0 4px;font-size:16px;font-weight:800;" id="tpl-titulo"></h3>
    <p style="margin:0 0 14px;font-size:12.5px;color:var(--c-text-muted);">
      Escolha um template — a prévia usa os dados reais deste carrinho.</p>
    <div id="tpl-lista" style="display:flex;flex-direction:column;gap:8px;"></div>
    <div id="tpl-preview" style="display:none;margin-top:14px;background:var(--bg);
         border:1px solid var(--c-border);border-radius:10px;padding:14px;
         font-size:13px;white-space:pre-wrap;"></div>
    <div style="display:flex;gap:10px;margin-top:16px;justify-content:flex-end;">
      <button class="btn" id="tpl-cancelar">Cancelar</button>
      <button class="btn btn-primary" id="tpl-enviar" disabled>Enviar</button>
    </div>
  </div>
</div>

<script>
jQuery(function ($) {
  var RID   = <?= (int)$rec['id'] ?>;
  var BASE  = '<?= ADMIN_URL ?>/carrinhos-abandonados/' + RID;
  var CSRF  = $('meta[name="csrf-token"]').attr('content');
  var tplWpp  = <?= json_encode($templatesWpp,  JSON_UNESCAPED_UNICODE) ?>;
  var tplMail = <?= json_encode($templatesMail, JSON_UNESCAPED_UNICODE) ?>;
  var canal = null, templateSel = null;

  function post(url, data) {
    return $.post(url, $.extend({ _csrf: CSRF }, data), null, 'json');
  }
  function reload(msg) { if (msg) alert(msg); location.reload(); }

  /* Status */
  $('#sel-status').on('change', function () {
    var st = $(this).val();
    if (!st) return;
    var motivo = '';
    if (st === 'perdido') {
      motivo = prompt('Motivo da perda (obrigatório):') || '';
      if (!motivo.trim()) { $(this).val(''); return; }
    }
    post(BASE + '/status', { status: st, motivo: motivo })
      .done(function (r) { r.ok ? reload() : alert(r.msg); });
  });

  $('#btn-capturar').on('click', function () {
    post(BASE + '/capturar', {})
      .done(function (r) { r.ok ? reload() : alert(r.msg); });
  });

  /* Responsável */
  $('#sel-responsavel').on('change', function () {
    var id = $(this).val();
    if (!id) return;
    post(BASE + '/responsavel', { responsavel_id: id })
      .done(function (r) { r.ok ? reload() : alert(r.msg); });
  });

  /* Agendamento */
  $('#inp-agendar').on('change', function () {
    var quando = $(this).val();
    if (!quando) return;
    post(BASE + '/agendar', { quando: quando.replace('T', ' ') + ':00' })
      .done(function (r) { r.ok ? reload() : alert(r.msg); });
  });

  /* Anotação */
  $('#btn-anotar').on('click', function () {
    var t = $('#inp-anotacao').val().trim();
    if (!t) return;
    post(BASE + '/anotacao', { texto: t })
      .done(function (r) { r.ok ? reload() : alert(r.msg); });
  });

  /* Link de recuperação */
  $('#btn-link').on('click', function () {
    post(BASE + '/link', {}).done(function (r) {
      if (!r.ok) return alert(r.msg);
      navigator.clipboard.writeText(r.link).then(function () {
        alert('Link copiado!\n' + r.link);
      });
    });
  });

  /* Modal de templates */
  function abrirModal(c) {
    canal = c; templateSel = null;
    $('#tpl-titulo').text(c === 'whatsapp' ? '💬 Enviar WhatsApp' : '✉ Enviar e-mail');
    $('#tpl-enviar').prop('disabled', true);
    $('#tpl-preview').hide();
    var lista = c === 'whatsapp' ? tplWpp : tplMail;
    var $l = $('#tpl-lista').empty();
    lista.forEach(function (t) {
      $('<button class="btn" style="text-align:left;display:block;width:100%;">')
        .html('<strong>' + $('<i>').text(t.nome).html() + '</strong><br>' +
              '<span style="font-size:11.5px;color:var(--text-2);">' +
              $('<i>').text(t.uso_recomendado || '').html() + '</span>')
        .on('click', function () {
          templateSel = t;
          $('#tpl-lista .btn').css('outline', '');
          $(this).css('outline', '2px solid #1d4ed8');
          $('#tpl-preview').text(t.conteudo).show();
          $('#tpl-enviar').prop('disabled', false);
        })
        .appendTo($l);
    });
    $('#modal-tpl').css('display', 'flex');
  }
  $('#btn-whatsapp').on('click', function () { abrirModal('whatsapp'); });
  $('#btn-email').on('click',   function () { abrirModal('email'); });
  $('#tpl-cancelar').on('click', function () { $('#modal-tpl').hide(); });

  $('#tpl-enviar').on('click', function () {
    if (!templateSel) return;
    var $btn = $(this).prop('disabled', true).text('Enviando…');
    post(BASE + '/' + canal, { template_id: templateSel.id })
      .done(function (r) {
        if (!r.ok) { alert(r.msg); $btn.prop('disabled', false).text('Enviar'); return; }
        if (canal === 'whatsapp' && r.url) {
          window.open(r.url, '_blank'); // abre wa.me com a mensagem pronta
        }
        reload(canal === 'email' ? 'E-mail enviado!' : null);
      })
      .fail(function () { alert('Erro de rede.'); $btn.prop('disabled', false).text('Enviar'); });
  });
});
</script>