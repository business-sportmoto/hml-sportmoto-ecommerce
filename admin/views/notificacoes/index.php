<?php
/**
 * views/admin/notificacoes/index.php
 *
 * @var array $historico
 * @var array $categorias  slug => label
 * @var array $estilos     slug => [icone, cor]
 */
$base = defined('BASE_URL') ? BASE_URL : '';
?>
<div class="em_wrapper">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
    <div>
      <h2 style="font-size:19px;font-weight:600;margin:0 0 3px;">Notificações</h2>
      <p style="font-size:13px;color:var(--em-text-muted);margin:0;">Envie avisos in-app para clientes e administradores.</p>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

    <!-- ── Composição ─────────────────────────────────────────────────── -->
    <div class="cl-card">
      <div class="cl-card-header">Nova notificação</div>
      <div style="padding:16px;">

        <label class="ntfa-label">Título *</label>
        <input type="text" id="ntfa-titulo" class="ntfa-input" maxlength="160" placeholder="Ex: Promoção relâmpago de capacetes!">

        <label class="ntfa-label">Mensagem</label>
        <textarea id="ntfa-mensagem" class="ntfa-input" rows="3" placeholder="Texto complementar (opcional)"></textarea>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <label class="ntfa-label">Categoria *</label>
            <select id="ntfa-categoria" class="ntfa-input">
              <?php foreach ($categorias as $slug => $label): ?>
                <option value="<?= $slug ?>"><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="ntfa-label">Link de destino</label>
            <input type="text" id="ntfa-url" class="ntfa-input" placeholder="/promocoes ou URL completa">
          </div>
        </div>

        <label class="ntfa-label">Imagem (opcional, máx 2 MB)</label>
        <div style="display:flex;gap:10px;align-items:center;">
          <input type="file" id="ntfa-img-file" accept="image/jpeg,image/png,image/webp" style="display:none;">
          <button type="button" id="ntfa-img-btn" class="ntfa-btn-sec">
            <i class="bi bi-image"></i> Escolher imagem
          </button>
          <span id="ntfa-img-nome" style="font-size:12px;color:var(--em-text-muted);"></span>
        </div>
        <input type="hidden" id="ntfa-img-url" value="">
        <img id="ntfa-img-preview" style="display:none;max-width:100%;max-height:140px;border-radius:10px;margin-top:10px;" alt="">

        <hr style="border:none;border-top:0.5px solid var(--em-border);margin:18px 0;">

        <label class="ntfa-label">Destinatários *</label>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px;">
          <label class="ntfa-radio"><input type="radio" name="ntfa-modo" value="todos" checked> Todos (clientes + admins)</label>
          <label class="ntfa-radio"><input type="radio" name="ntfa-modo" value="todos_clientes"> Todos os clientes</label>
          <label class="ntfa-radio"><input type="radio" name="ntfa-modo" value="todos_admins"> Todos os admins</label>
          <label class="ntfa-radio"><input type="radio" name="ntfa-modo" value="selecionados"> Selecionar destinatários…</label>
        </div>

        <div id="ntfa-sel-box" style="display:none;margin-bottom:14px;">
          <input type="text" id="ntfa-busca" class="ntfa-input" placeholder="Buscar por nome ou email…" autocomplete="off">
          <div id="ntfa-sugestoes" class="ntfa-sugestoes" style="display:none;"></div>
          <div id="ntfa-selecionados" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;"></div>
        </div>

        <button type="button" id="ntfa-enviar" class="ntfa-btn-pri">
          <i class="bi bi-send"></i> Enviar notificação
        </button>
        <p id="ntfa-feedback" style="font-size:12px;margin:10px 0 0;display:none;"></p>
      </div>
    </div>

    <!-- ── Histórico ──────────────────────────────────────────────────── -->
    <div class="cl-card">
      <div class="cl-card-header">Últimos envios</div>
      <div style="max-height:560px;overflow-y:auto;">
        <?php if (empty($historico)): ?>
          <p style="padding:24px;text-align:center;color:var(--em-text-muted);font-size:13px;">Nenhum envio ainda.</p>
        <?php else: foreach ($historico as $h):
          $est = $estilos[$h['categoria']] ?? ['icone' => 'bi-bell', 'cor' => '#71717a'];
          $alvoLabel = [
            'individual'     => 'Individual',
            'selecionados'   => 'Selecionados',
            'todos_clientes' => 'Todos os clientes',
            'todos_admins'   => 'Todos os admins',
            'todos'          => 'Todos',
          ][$h['alvo_tipo']] ?? $h['alvo_tipo'];
          $fanoutBadge = [
            'pendente'    => ['Aguardando envio', '#f59e0b'],
            'processando' => ['Enviando…', '#0a66c2'],
            'erro'        => ['Erro no envio', '#dc2626'],
          ][$h['fanout_status']] ?? null;
        ?>
        <div style="display:flex;gap:11px;padding:13px 16px;border-bottom:0.5px solid var(--em-border);">
          <div style="width:32px;height:32px;border-radius:9px;background:<?= $est['cor'] ?>18;color:<?= $est['cor'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px;">
            <i class="bi <?= $est['icone'] ?>"></i>
          </div>
          <div style="flex:1;min-width:0;">
            <p style="font-size:13px;font-weight:600;margin:0 0 2px;"><?= htmlspecialchars($h['titulo']) ?></p>
            <p style="font-size:11.5px;color:var(--em-text-muted);margin:0;">
              <?= htmlspecialchars($alvoLabel) ?>
              · <?= (int)$h['fanout_total'] ?> destinatário(s)
              · <?= (int)$h['total_lidas'] ?> lida(s)
              · <?= date('d/m H:i', strtotime($h['criado_em'])) ?>
              <?php if ($fanoutBadge): ?>
                · <span style="color:<?= $fanoutBadge[1] ?>;font-weight:600;"><?= $fanoutBadge[0] ?></span>
              <?php endif; ?>
            </p>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<style>
.ntfa-label{display:block;font-size:12px;font-weight:600;color:var(--em-text,#111827);margin:0 0 5px;}
.ntfa-input{width:100%;padding:9px 12px;font-size:13px;border:0.5px solid var(--em-border,#e5e7eb);border-radius:9px;margin-bottom:13px;background:var(--em-bg-input,#fff);color:inherit;outline:none;box-sizing:border-box;}
.ntfa-input:focus{border-color:#0a66c2;box-shadow:0 0 0 3px rgba(10,102,194,.1);}
.ntfa-radio{display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;}
.ntfa-btn-pri{width:100%;background:#0a66c2;color:#fff;border:none;border-radius:10px;padding:11px;font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;}
.ntfa-btn-pri:hover{background:#0857a6;}
.ntfa-btn-pri:disabled{opacity:.6;cursor:default;}
.ntfa-btn-sec{background:var(--em-bg,#f4f4f5);border:0.5px solid var(--em-border);border-radius:9px;padding:8px 14px;font-size:12.5px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;color:inherit;}
.ntfa-sugestoes{border:0.5px solid var(--em-border);border-radius:9px;background:var(--em-bg-input,#fff);max-height:180px;overflow-y:auto;margin-top:-8px;box-shadow:0 6px 20px rgba(0,0,0,.08);}
.ntfa-sug-item{padding:9px 12px;font-size:12.5px;cursor:pointer;}
.ntfa-sug-item:hover{background:var(--em-bg,#f4f4f5);}
.ntfa-tag{display:inline-flex;align-items:center;gap:6px;background:var(--em-bg,#f4f4f5);border:0.5px solid var(--em-border);border-radius:14px;font-size:12px;padding:4px 10px;}
.ntfa-tag button{background:none;border:none;cursor:pointer;color:var(--em-text-muted);font-size:13px;padding:0;line-height:1;}
</style>

<script>
(function ($) {
  var BASE = '<?= addslashes($base) ?>';
  var CSRF = $('meta[name=csrf-token]').attr('content') || window.CSRF_TOKEN || '';
  var selecionados = {}; // valor -> label

  // ── Upload de imagem ──────────────────────────────────────────────────────
  $('#ntfa-img-btn').on('click', function () { $('#ntfa-img-file').trigger('click'); });

  $('#ntfa-img-file').on('change', function () {
    var file = this.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
      feedback('Imagem muito grande (máx 2 MB).', false); return;
    }
    var fd = new FormData();
    fd.append('imagem', file);
    fd.append('csrf_token', CSRF);

    $('#ntfa-img-nome').text('Enviando…');
    $.ajax({
      url: BASE + '/admin/notificacoes/upload-img',
      method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json'
    }).done(function (r) {
      if (r.ok) {
        $('#ntfa-img-url').val(r.url);
        $('#ntfa-img-nome').text(file.name);
        $('#ntfa-img-preview').attr('src', r.url).show();
      } else {
        $('#ntfa-img-nome').text('');
        feedback(r.erro || 'Falha no upload.', false);
      }
    }).fail(function () {
      $('#ntfa-img-nome').text('');
      feedback('Erro de conexão no upload.', false);
    });
  });

  // ── Modo de envio ─────────────────────────────────────────────────────────
  $('input[name=ntfa-modo]').on('change', function () {
    $('#ntfa-sel-box').toggle($(this).val() === 'selecionados');
  });

  // ── Autocomplete de destinatários ────────────────────────────────────────
  var buscaTimer = null;
  $('#ntfa-busca').on('input', function () {
    var q = $(this).val().trim();
    clearTimeout(buscaTimer);
    if (q.length < 2) { $('#ntfa-sugestoes').hide(); return; }
    buscaTimer = setTimeout(function () {
      $.get(BASE + '/admin/notificacoes/buscar-destinatarios', { q: q }, function (r) {
        var $s = $('#ntfa-sugestoes').empty();
        if (!r.ok || !r.itens.length) { $s.hide(); return; }
        r.itens.forEach(function (it) {
          if (selecionados[it.valor]) return;
          $s.append(
            $('<div class="ntfa-sug-item"></div>')
              .text(it.label)
              .attr('data-valor', it.valor)
              .attr('data-label', it.label)
          );
        });
        $s.toggle($s.children().length > 0);
      }, 'json');
    }, 300);
  });

  $(document).on('click', '.ntfa-sug-item', function () {
    var valor = $(this).data('valor');
    var label = $(this).data('label');
    selecionados[valor] = label;
    renderTags();
    $('#ntfa-busca').val('');
    $('#ntfa-sugestoes').hide();
  });

  function renderTags() {
    var $c = $('#ntfa-selecionados').empty();
    $.each(selecionados, function (valor, label) {
      $c.append(
        $('<span class="ntfa-tag"></span>')
          .text(label)
          .append(
            $('<button type="button">×</button>').on('click', function () {
              delete selecionados[valor];
              renderTags();
            })
          )
      );
    });
  }

  // ── Envio ─────────────────────────────────────────────────────────────────
  $('#ntfa-enviar').on('click', function () {
    var titulo = $('#ntfa-titulo').val().trim();
    if (!titulo) { feedback('Informe o título.', false); return; }

    var modo = $('input[name=ntfa-modo]:checked').val();
    var dados = {
      titulo:     titulo,
      mensagem:   $('#ntfa-mensagem').val().trim(),
      categoria:  $('#ntfa-categoria').val(),
      url:        $('#ntfa-url').val().trim(),
      imagem_url: $('#ntfa-img-url').val(),
      modo_envio: modo,
      csrf_token: CSRF
    };

    if (modo === 'selecionados') {
      dados.destinatarios = Object.keys(selecionados);
      if (!dados.destinatarios.length) {
        feedback('Selecione ao menos um destinatário.', false); return;
      }
    }

    var $btn = $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Enviando…');

    $.post(BASE + '/admin/notificacoes/enviar', dados, function (r) {
      $btn.prop('disabled', false).html('<i class="bi bi-send"></i> Enviar notificação');
      if (r.ok) {
        feedback(r.msg || 'Enviado!', true);
        // limpa o formulário
        $('#ntfa-titulo, #ntfa-mensagem, #ntfa-url, #ntfa-img-url').val('');
        $('#ntfa-img-nome').text('');
        $('#ntfa-img-preview').hide();
        selecionados = {};
        renderTags();
        setTimeout(function () { location.reload(); }, 1800);
      } else {
        feedback(r.erro || 'Falha ao enviar.', false);
      }
    }, 'json').fail(function () {
      $btn.prop('disabled', false).html('<i class="bi bi-send"></i> Enviar notificação');
      feedback('Erro de conexão.', false);
    });
  });

  function feedback(msg, ok) {
    $('#ntfa-feedback')
      .css('color', ok ? '#16a34a' : '#dc2626')
      .text(msg).show();
    setTimeout(function () { $('#ntfa-feedback').fadeOut(); }, 4000);
  }
})(jQuery);
</script>
