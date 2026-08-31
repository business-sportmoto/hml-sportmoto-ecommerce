<?php
/**
 * admin/views/chat/instagram-regras.php
 * @var array $regras @var array $contas @var array $midias
 * @var array $fluxos @var array $tags @var array $kpis
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n    = fn($v) => number_format((float)$v, 0, ',', '.');

$rotuloEscopo = ['todas' => 'Todos os posts', 'midia' => 'Posts escolhidos', 'novas' => 'Só posts novos'];
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Automação de comentários</h1>
      <p>Alguém comenta uma palavra no post e recebe direct automático — com resposta pública opcional.</p>
    </div>
    <div class="ch-head-acoes">
      <a href="<?= $base ?>/admin/chat/instagram" class="ch-btn">← Instagram</a>
      <button type="button" class="ch-btn" id="ch-simular">Testar comentário</button>
      <button type="button" class="ch-btn ch-btn--pri" id="ch-nova">Nova regra</button>
    </div>
  </div>

  <?php if (!$contas): ?>
    <div class="ch-aviso ch-aviso--erro">
      <div>
        <strong>Nenhuma conta do Instagram conectada</strong>
        As regras só funcionam com uma conta ativa.
        <a href="<?= $base ?>/admin/chat/instagram">Conectar agora</a>
      </div>
    </div>
  <?php elseif (!$midias): ?>
    <div class="ch-aviso ch-aviso--aviso">
      <div>
        <strong>Nenhuma publicação sincronizada</strong>
        Sem isso, só dá para criar regras que valem para <em>todos</em> os posts.
        <a href="<?= $base ?>/admin/chat/instagram">Sincronizar publicações</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="ch-kpis">
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Regras ativas</div>
      <div class="ch-kpi-val"><?= $n($kpis['regras_ativas']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Comentários hoje</div>
      <div class="ch-kpi-val"><?= $n($kpis['comentarios_hoje']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">DMs enviados hoje</div>
      <div class="ch-kpi-val"><?= $n($kpis['dms_hoje']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Falhas hoje</div>
      <div class="ch-kpi-val" style="<?= (int)$kpis['falhas_hoje'] > 0 ? 'color:var(--danger)' : '' ?>">
        <?= $n($kpis['falhas_hoje']) ?>
      </div>
      <?php if ((int)$kpis['falhas_hoje'] > 0): ?>
        <div class="ch-kpi-sub"><a href="<?= $base ?>/admin/chat/instagram/comentarios?so_erro=1">ver o que falhou</a></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="ch-card">
    <div class="ch-card-head">
      <h2>Regras</h2>
      <span class="ch-sm ch-mut">avaliadas por prioridade — a primeira que casar vence</span>
    </div>

    <?php if (!$regras): ?>
      <div class="ch-vazio">
        <strong>Nenhuma regra ainda</strong>
        <p style="max-width:54ch;margin:0 auto;">
          Exemplo típico: no post de lançamento, quem comentar <em>QUERO</em> recebe no direct
          o link do produto e entra num fluxo de venda.
        </p>
      </div>
    <?php else: ?>
    <div class="ch-tabela-wrap">
      <table class="ch-tabela">
        <thead><tr>
          <th style="width:1%;">Ordem</th><th>Regra</th><th>Onde</th><th>Palavras</th>
          <th>O que faz</th><th class="ch-num">Disparos</th><th style="width:1%;"></th>
        </tr></thead>
        <tbody>
          <?php foreach ($regras as $r): ?>
          <tr style="<?= (int)$r['ativo'] ? '' : 'opacity:.55;' ?>">
            <td class="ch-num ch-mono"><?= (int)$r['prioridade'] ?></td>
            <td>
              <span class="ch-b"><?= $h($r['nome']) ?></span>
              <?php if (!(int)$r['ativo']): ?>
                <span class="ch-badge ch-badge--neutro">inativa</span>
              <?php endif; ?>
              <?php if ((int)$r['uma_vez_por_pessoa']): ?>
                <div class="ch-sm ch-mut">uma vez por pessoa</div>
              <?php endif; ?>
            </td>
            <td class="ch-sm">
              <?= $h($rotuloEscopo[$r['escopo']] ?? $r['escopo']) ?>
              <?php if ($r['escopo'] === 'midia'):
                $qtd = count(json_decode($r['midias_json'] ?? '[]', true) ?: []); ?>
                <div class="ch-mut"><?= $qtd ?> post(s)</div>
              <?php endif; ?>
              <?php if ($r['conta_username']): ?>
                <div class="ch-mut">@<?= $h($r['conta_username']) ?></div>
              <?php endif; ?>
            </td>
            <td class="ch-mono ch-sm ch-mut">
              <?= $h($r['palavras'] ?: 'qualquer') ?>
              <?php if ($r['palavras']): ?>
                <div>(<?= $h($r['modo_match']) ?>)</div>
              <?php endif; ?>
            </td>
            <td class="ch-sm">
              <?php if ((int)$r['responder_publico']): ?>
                <div>💬 responde no post</div>
              <?php endif; ?>
              <?php if ((int)$r['enviar_dm']): ?>
                <div>✉️ manda direct</div>
              <?php endif; ?>
              <?php if ($r['fluxo_nome']): ?>
                <div>⚙️ <?= $h($r['fluxo_nome']) ?></div>
              <?php endif; ?>
              <?php if ($r['tag_nome']): ?>
                <div><span class="ch-tag" style="color:<?= $h($r['tag_cor']) ?>;background:<?= $h($r['tag_cor']) ?>22;"><?= $h($r['tag_nome']) ?></span></div>
              <?php endif; ?>
            </td>
            <td class="ch-num">
              <?= $n($r['total_disparos']) ?>
              <?php if ($r['ultimo_disparo_em']): ?>
                <div class="ch-sm ch-mut"><?= date('d/m H:i', strtotime((string)$r['ultimo_disparo_em'])) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="ch-flex">
                <button type="button" class="ch-btn ch-btn--sm ch-editar"
                        data-json="<?= $h(json_encode($r, JSON_UNESCAPED_UNICODE)) ?>">Editar</button>
                <button type="button" class="ch-btn ch-btn--sm ch-toggle" data-id="<?= (int)$r['id'] ?>">
                  <?= (int)$r['ativo'] ? 'Desligar' : 'Ligar' ?>
                </button>
                <button type="button" class="ch-btn ch-btn--sm ch-btn--perigo ch-excluir" data-id="<?= (int)$r['id'] ?>">×</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="ch-aviso ch-aviso--info ch-mt">
    <div>
      <strong>O que a Meta permite</strong>
      O direct a partir de um comentário só pode ser aberto <strong>uma vez por comentário</strong>,
      dentro de 7 dias. O sistema respeita isso automaticamente — se a mesma pessoa comentar
      de novo, aí sim vale um novo direct.
      <div style="margin-top:6px;">
        Responder em público com o mesmo texto sempre chama atenção do algoritmo.
        Separe variações com <code>|</code> que o sistema sorteia uma.
      </div>
    </div>
  </div>
</div>

<?php // ── Modal do formulário ──────────────────────────────────────────── ?>
<div class="ch-modal" id="ch-modal-regra">
  <div class="ch-modal-cx" style="max-width:620px;">
    <div class="ch-modal-head">
      <h3 id="ch-r-titulo">Nova regra</h3>
      <button type="button" class="ch-modal-x" data-fechar>&times;</button>
    </div>
    <div class="ch-modal-body">
      <form id="ch-form-regra">
        <input type="hidden" name="id" id="ch-r-id">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf_token ?? '') ?>">

        <div class="ch-campo">
          <label class="ch-label">Nome da regra</label>
          <input type="text" class="ch-input" name="nome" id="ch-r-nome" required
                 placeholder="Ex.: Lançamento — comente QUERO">
        </div>

        <?php if (count($contas) > 1): ?>
        <div class="ch-campo">
          <label class="ch-label">Conta</label>
          <select class="ch-select" name="conta_id" id="ch-r-conta">
            <option value="0">Todas as contas</option>
            <?php foreach ($contas as $c): ?>
              <option value="<?= (int)$c['id'] ?>">@<?= $h($c['username']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div class="ch-campo">
          <label class="ch-label">Em quais publicações</label>
          <select class="ch-select" name="escopo" id="ch-r-escopo">
            <option value="todas">Todos os posts e reels</option>
            <option value="midia">Só nos posts que eu escolher</option>
            <option value="novas">Só em posts publicados a partir de agora</option>
          </select>
        </div>

        <div class="ch-campo" id="ch-r-midias-box" style="display:none;">
          <label class="ch-label">Publicações</label>
          <?php if (!$midias): ?>
            <div class="ch-ajuda" style="color:var(--warning);">
              Nenhuma publicação sincronizada. Volte em Instagram e clique em “Sincronizar posts”.
            </div>
          <?php else: ?>
            <div style="max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:8px;">
              <?php foreach ($midias as $m): ?>
                <label class="ch-check" style="padding:5px 0;">
                  <input type="checkbox" name="midias[]" value="<?= $h($m['media_id']) ?>" class="ch-r-midia">
                  <span style="display:flex;gap:8px;align-items:center;min-width:0;">
                    <?php if ($m['thumb_url']): ?>
                      <img src="<?= $h($m['thumb_url']) ?>" alt="" width="34" height="34"
                           style="border-radius:5px;object-fit:cover;flex:none;">
                    <?php endif; ?>
                    <span style="min-width:0;">
                      <span class="ch-sm" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:380px;">
                        <?= $h(mb_substr((string)$m['legenda'], 0, 70) ?: '(sem legenda)') ?>
                      </span>
                      <span class="ch-sm ch-mut">
                        <?= $h($m['tipo']) ?> ·
                        <?= $m['publicado_em'] ? date('d/m/Y', strtotime((string)$m['publicado_em'])) : '' ?> ·
                        <?= (int)$m['total_comentarios'] ?> comentário(s)
                      </span>
                    </span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="ch-campo">
          <label class="ch-label">Palavras que ativam (vazio = qualquer comentário)</label>
          <input type="text" class="ch-input" name="palavras" id="ch-r-palavras"
                 placeholder="quero, eu quero, link, preço">
          <div class="ch-ajuda">Acentos e maiúsculas são ignorados.</div>
        </div>

        <div class="ch-campo">
          <label class="ch-label">Como comparar</label>
          <select class="ch-select" name="modo_match" id="ch-r-modo">
            <option value="contem">Contém a palavra</option>
            <option value="exato">É exatamente a palavra</option>
            <option value="comeca">Começa com a palavra</option>
            <option value="regex">Expressão regular</option>
          </select>
        </div>

        <hr style="border:none;border-top:1px solid var(--border);margin:18px 0;">

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="responder_publico" id="ch-r-rp" value="1">
            <span><strong>Responder o comentário em público</strong></span>
          </label>
        </div>
        <div class="ch-campo" id="ch-r-rp-box" style="display:none;">
          <label class="ch-label">Resposta pública</label>
          <textarea class="ch-textarea" name="resposta_publica" id="ch-r-rptxt" rows="2"
                    placeholder="Te chamei no direct! 💜 | Mandei tudo no seu direct 😉"></textarea>
          <div class="ch-ajuda">Separe variações com <code>|</code>. Use {{usuario}} para o @ da pessoa.</div>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="enviar_dm" id="ch-r-dm" value="1" checked>
            <span><strong>Mandar mensagem no direct</strong></span>
          </label>
        </div>
        <div class="ch-campo" id="ch-r-dm-box">
          <label class="ch-label">Mensagem do direct</label>
          <textarea class="ch-textarea" name="mensagem_dm" id="ch-r-dmtxt" rows="3"
                    placeholder="Oi! Vi seu comentário 😊 Aqui está o link: ..."></textarea>
          <div class="ch-ajuda">
            Aceita {{usuario}}, {{saudacao}} e {{comentario}}.
            Se escolher um fluxo abaixo, ele continua a conversa depois desta mensagem.
          </div>
        </div>

        <div class="ch-grid-2">
          <div class="ch-campo">
            <label class="ch-label">Iniciar fluxo (opcional)</label>
            <select class="ch-select" name="fluxo_id" id="ch-r-fluxo">
              <option value="0">Nenhum</option>
              <?php foreach ($fluxos as $f): ?>
                <option value="<?= (int)$f['id'] ?>"><?= $h($f['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ch-campo">
            <label class="ch-label">Aplicar tag (opcional)</label>
            <select class="ch-select" name="tag_id" id="ch-r-tag">
              <option value="0">Nenhuma</option>
              <?php foreach ($tags as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= $h($t['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="ch-grid-2">
          <div class="ch-campo">
            <label class="ch-label">Prioridade</label>
            <input type="number" class="ch-input" name="prioridade" id="ch-r-prio" value="50" min="0" max="999">
          </div>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="uma_vez_por_pessoa" id="ch-r-uma" value="1">
            <span>
              <strong>Só uma vez por pessoa</strong>
              <div class="ch-ajuda">Quem já recebeu o direct desta regra não recebe de novo.</div>
            </span>
          </label>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="ignorar_respostas" id="ch-r-ir" value="1">
            <span>Ignorar respostas dentro de threads (só comentários de primeiro nível)</span>
          </label>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="ignorar_proprios" id="ch-r-ip" value="1" checked>
            <span>Ignorar comentários da própria conta</span>
          </label>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="ativo" id="ch-r-ativo" value="1" checked>
            <span><strong>Ativa</strong></span>
          </label>
        </div>

        <div id="ch-r-erro" class="ch-sm" style="color:var(--danger);"></div>
      </form>
    </div>
    <div class="ch-modal-pe">
      <button type="button" class="ch-btn" data-fechar>Cancelar</button>
      <button type="button" class="ch-btn ch-btn--pri" id="ch-r-salvar">Salvar</button>
    </div>
  </div>
</div>

<?php // ── Modal do simulador ───────────────────────────────────────────── ?>
<div class="ch-modal" id="ch-modal-sim">
  <div class="ch-modal-cx" style="max-width:460px;">
    <div class="ch-modal-head">
      <h3>Testar um comentário</h3>
      <button type="button" class="ch-modal-x" data-fechar>&times;</button>
    </div>
    <div class="ch-modal-body">
      <div class="ch-campo">
        <label class="ch-label">O que a pessoa comentaria</label>
        <input type="text" class="ch-input" id="ch-sim-txt" placeholder="quero!!">
      </div>
      <div id="ch-sim-res"></div>
    </div>
    <div class="ch-modal-pe">
      <button type="button" class="ch-btn" data-fechar>Fechar</button>
      <button type="button" class="ch-btn ch-btn--pri" id="ch-sim-run">Testar</button>
    </div>
  </div>
</div>

<script>
(function ($) {
  var BASE = window.BASE_URL || '<?= $base ?>';
  var CSRF = '<?= $h($csrf_token ?? '') ?>';

  function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
  $(document).on('click', '[data-fechar]', function () { $(this).closest('.ch-modal').removeClass('aberto'); });
  $(document).on('click', '.ch-modal', function (e) { if (e.target === this) $(this).removeClass('aberto'); });

  function ajustar() {
    $('#ch-r-midias-box').toggle($('#ch-r-escopo').val() === 'midia');
    $('#ch-r-rp-box').toggle($('#ch-r-rp').is(':checked'));
    $('#ch-r-dm-box').toggle($('#ch-r-dm').is(':checked'));
  }
  $('#ch-r-escopo, #ch-r-rp, #ch-r-dm').on('change', ajustar);

  function abrir(d) {
    $('#ch-r-erro').text('');
    $('#ch-r-titulo').text(d ? 'Editar regra' : 'Nova regra');
    $('#ch-r-id').val(d ? d.id : '');
    $('#ch-r-nome').val(d ? d.nome : '');
    $('#ch-r-conta').val(d ? (d.conta_id || 0) : 0);
    $('#ch-r-escopo').val(d ? d.escopo : 'todas');
    $('#ch-r-palavras').val(d ? (d.palavras || '') : '');
    $('#ch-r-modo').val(d ? d.modo_match : 'contem');
    $('#ch-r-rp').prop('checked', d ? d.responder_publico == 1 : false);
    $('#ch-r-rptxt').val(d ? (d.resposta_publica || '') : '');
    $('#ch-r-dm').prop('checked', d ? d.enviar_dm == 1 : true);
    $('#ch-r-dmtxt').val(d ? (d.mensagem_dm || '') : '');
    $('#ch-r-fluxo').val(d ? (d.fluxo_id || 0) : 0);
    $('#ch-r-tag').val(d ? (d.tag_id || 0) : 0);
    $('#ch-r-prio').val(d ? d.prioridade : 50);
    $('#ch-r-uma').prop('checked', d ? d.uma_vez_por_pessoa == 1 : false);
    $('#ch-r-ir').prop('checked', d ? d.ignorar_respostas == 1 : false);
    $('#ch-r-ip').prop('checked', d ? d.ignorar_proprios == 1 : true);
    $('#ch-r-ativo').prop('checked', d ? d.ativo == 1 : true);

    $('.ch-r-midia').prop('checked', false);
    if (d && d.midias_json) {
      var ids = [];
      try { ids = JSON.parse(d.midias_json) || []; } catch (e) {}
      $('.ch-r-midia').each(function () {
        if (ids.indexOf($(this).val()) >= 0) $(this).prop('checked', true);
      });
    }
    ajustar();
    $('#ch-modal-regra').addClass('aberto');
  }

  $('#ch-nova').on('click', function () { abrir(null); });
  $('.ch-editar').on('click', function () { abrir($(this).data('json')); });

  $('#ch-r-salvar').on('click', function () {
    var b = $(this).prop('disabled', true);
    $.post(BASE + '/admin/chat/instagram/regras/salvar', $('#ch-form-regra').serialize(), function (r) {
      if (r.ok) location.reload();
      else $('#ch-r-erro').text(r.erro || 'Falha ao salvar.');
    }, 'json').fail(function () {
      $('#ch-r-erro').text('Erro de rede.');
    }).always(function () { b.prop('disabled', false); });
  });

  $('.ch-toggle').on('click', function () {
    $.post(BASE + '/admin/chat/instagram/regras/' + $(this).data('id') + '/ativo',
      { csrf_token: CSRF }, function () { location.reload(); }, 'json');
  });

  $('.ch-excluir').on('click', function () {
    if (!confirm('Excluir esta regra?')) return;
    $.post(BASE + '/admin/chat/instagram/regras/' + $(this).data('id') + '/excluir',
      { csrf_token: CSRF }, function () { location.reload(); }, 'json');
  });

  $('#ch-simular').on('click', function () {
    $('#ch-sim-res').empty();
    $('#ch-modal-sim').addClass('aberto');
  });

  $('#ch-sim-run').on('click', function () {
    var t = ($('#ch-sim-txt').val() || '').trim();
    if (!t) return;
    $.post(BASE + '/admin/chat/instagram/regras/simular',
      { csrf_token: CSRF, texto: t }, function (r) {
        if (!r.ok) { $('#ch-sim-res').html('<div class="ch-aviso ch-aviso--erro" style="margin:0;"><div>' + esc(r.erro) + '</div></div>'); return; }
        var d = r.resultado;
        if (!d.casou) {
          $('#ch-sim-res').html('<div class="ch-aviso ch-aviso--aviso" style="margin:0;"><div>' +
            '<strong>Nada aconteceria</strong>' + esc(d.motivo) + '</div></div>');
        } else {
          $('#ch-sim-res').html('<div class="ch-aviso ch-aviso--ok" style="margin:0;"><div>' +
            '<strong>' + esc(d.regra.nome) + '</strong>' + esc(d.acoes.join(' · ')) + '</div></div>');
        }
      }, 'json');
  });

  $('#ch-sim-txt').on('keydown', function (e) { if (e.key === 'Enter') $('#ch-sim-run').click(); });
})(jQuery);
</script>
