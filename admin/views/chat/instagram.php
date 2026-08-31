<?php
/**
 * admin/views/chat/instagram.php
 * @var array $contas @var array $kpis @var array $regras
 * @var string $webhookUrl @var array $diagnostico
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n    = fn($v) => number_format((float)$v, 0, ',', '.');
$d    = $diagnostico;
$pronto = $d['token_valido'] && empty($d['faltando']) && $d['contas'] > 0;
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Instagram</h1>
      <p>Direct e automação de comentários — a régua que responde quem comenta nos seus posts.</p>
    </div>
    <div class="ch-head-acoes">
      <a href="<?= $base ?>/admin/chat/instagram/regras" class="ch-btn">Automação de comentários</a>
      <a href="<?= $base ?>/admin/chat/instagram/comentarios" class="ch-btn">Comentários</a>
      <button type="button" class="ch-btn ch-btn--pri" id="ch-ig-conectar">Conectar conta</button>
    </div>
  </div>

  <?php // ── Diagnóstico: é aqui que 90% dos problemas aparecem ────────── ?>
  <?php if (!$pronto): ?>
    <div class="ch-aviso ch-aviso--<?= empty($d['faltando']) && $d['token_valido'] ? 'aviso' : 'erro' ?>">
      <div>
        <strong>O canal ainda não está pronto</strong>

        <?php if (!$d['token_definido']): ?>
          Falta <code>META_ACCESS_TOKEN</code> no <code>.env</code>.
        <?php elseif (!$d['token_valido']): ?>
          O token em <code>META_ACCESS_TOKEN</code> é inválido ou expirou.
        <?php else: ?>

          <?php if (!empty($d['faltando'])): ?>
            <div style="margin:8px 0;">
              Faltam <?= count($d['faltando']) ?> permissão(ões) obrigatória(s) no token:
              <ul style="margin:6px 0 0;padding-left:20px;">
                <?php foreach ($d['faltando'] as $escopo => $paraQue): ?>
                  <li><code><?= $h($escopo) ?></code> — <?= $h($paraQue) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <?php if ($d['token_expira'] !== 'nunca'): ?>
            <div style="margin:8px 0;color:var(--warning);">
              <strong>Este token expira em <?= $h($d['token_expira']) ?>.</strong>
              Tokens de usuário são temporários. Para produção, gere um token de
              <strong>Usuário do Sistema</strong> no Business Manager — ele não expira.
            </div>
          <?php endif; ?>

          <?php if (empty($d['faltando']) && $d['contas'] === 0): ?>
            Permissões OK, mas nenhuma conta foi conectada ainda.
            Clique em <strong>Conectar conta</strong> acima.
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="ch-aviso ch-aviso--ok">
      <div><strong>Canal pronto</strong> <?= $d['contas'] ?> conta(s) conectada(s) e permissões completas.</div>
    </div>
  <?php endif; ?>

  <?php // Recomendados: dá para viver sem, com um contorno manual ?>
  <?php if (!empty($d['recomendados'])): ?>
    <div class="ch-aviso ch-aviso--info">
      <div>
        <strong>Permissões opcionais ausentes</strong>
        O canal funciona sem elas, com um contorno:
        <ul style="margin:6px 0 0;padding-left:20px;">
          <?php foreach ($d['recomendados'] as $escopo => $paraQue): ?>
            <li><code><?= $h($escopo) ?></code> — <?= $h($paraQue) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <?php // ── KPIs ───────────────────────────────────────────────────────── ?>
  <div class="ch-kpis">
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Contas conectadas</div>
      <div class="ch-kpi-val"><?= $n($kpis['contas']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Contatos do Instagram</div>
      <div class="ch-kpi-val"><?= $n($kpis['contatos']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Comentários hoje</div>
      <div class="ch-kpi-val"><?= $n($kpis['comentarios_hoje']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">DMs por comentário hoje</div>
      <div class="ch-kpi-val"><?= $n($kpis['dms_hoje']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Regras ativas</div>
      <div class="ch-kpi-val"><?= $n($kpis['regras_ativas']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Falhas hoje</div>
      <div class="ch-kpi-val" style="<?= (int)$kpis['falhas_hoje'] > 0 ? 'color:var(--danger)' : '' ?>">
        <?= $n($kpis['falhas_hoje']) ?>
      </div>
    </div>
  </div>

  <?php // ── Contas ─────────────────────────────────────────────────────── ?>
  <div class="ch-card" style="margin-bottom:16px;">
    <div class="ch-card-head">
      <h2>Contas conectadas</h2>
      <span id="ch-ig-msg" class="ch-sm"></span>
    </div>

    <?php if (!$contas): ?>
      <div class="ch-vazio">
        <strong>Nenhuma conta conectada</strong>
        <p style="max-width:56ch;margin:0 auto;">
          A conta do Instagram precisa estar no modo <strong>Profissional</strong> (Empresa ou
          Criador) e vinculada a uma <strong>página do Facebook</strong>. É a página que
          fornece o token usado para enviar direct.
        </p>
      </div>
    <?php else: ?>
      <div class="ch-tabela-wrap">
        <table class="ch-tabela">
          <thead><tr>
            <th>Conta</th><th>Página</th><th>Webhook</th><th class="ch-num">Seguidores</th>
            <th>Situação</th><th style="width:1%;"></th>
          </tr></thead>
          <tbody>
            <?php foreach ($contas as $c): ?>
            <tr style="<?= (int)$c['ativo'] ? '' : 'opacity:.55;' ?>">
              <td>
                <div class="ch-flex">
                  <?php if ($c['foto_url']): ?>
                    <img src="<?= $h($c['foto_url']) ?>" alt="" width="34" height="34"
                         style="border-radius:50%;flex:none;">
                  <?php endif; ?>
                  <div>
                    <div class="ch-b">@<?= $h($c['username']) ?></div>
                    <div class="ch-sm ch-mut"><?= $h($c['nome']) ?></div>
                  </div>
                </div>
              </td>
              <td class="ch-sm"><?= $h($c['page_nome'] ?: '—') ?></td>
              <td>
                <?= (int)$c['webhook_assinado']
                      ? '<span class="ch-badge ch-badge--ok">assinado</span>'
                      : '<span class="ch-badge ch-badge--aviso">não assinado</span>' ?>
              </td>
              <td class="ch-num"><?= $c['seguidores'] !== null ? $n($c['seguidores']) : '—' ?></td>
              <td>
                <?php if ((int)$c['ativo']): ?>
                  <span class="ch-badge ch-badge--ok">ativa</span>
                <?php else: ?>
                  <span class="ch-badge ch-badge--neutro">desativada</span>
                <?php endif; ?>
                <?php if ($c['ultimo_erro']): ?>
                  <div class="ch-sm" style="color:var(--danger);margin-top:3px;">
                    <?= $h(mb_substr($c['ultimo_erro'], 0, 70)) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <div class="ch-flex" style="flex-wrap:wrap;gap:5px;">
                  <button type="button" class="ch-btn ch-btn--sm ch-ig-testar" data-id="<?= (int)$c['id'] ?>">Testar</button>
                  <button type="button" class="ch-btn ch-btn--sm ch-ig-assinar" data-id="<?= (int)$c['id'] ?>">Assinar webhook</button>
                  <button type="button" class="ch-btn ch-btn--sm ch-ig-sinc" data-id="<?= (int)$c['id'] ?>">Sincronizar posts</button>
                  <button type="button" class="ch-btn ch-btn--sm ch-ig-ativo" data-id="<?= (int)$c['id'] ?>">
                    <?= (int)$c['ativo'] ? 'Desativar' : 'Ativar' ?>
                  </button>
                  <button type="button" class="ch-btn ch-btn--sm ch-btn--perigo ch-ig-del" data-id="<?= (int)$c['id'] ?>">×</button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div id="ch-ig-resultado"></div>

  <?php // ── Passo a passo ──────────────────────────────────────────────── ?>
  <div class="ch-card" style="margin-bottom:16px;">
    <div class="ch-card-head"><h2>Como ligar o canal</h2></div>
    <div class="ch-card-body">
      <ol style="margin:0;padding-left:20px;line-height:1.9;font-size:13px;color:var(--text-2);">
        <li>
          A conta do Instagram precisa estar em modo <strong>Profissional</strong> e vinculada
          a uma página do Facebook.
        </li>
        <li>
          No painel da Meta, gere um token de <strong>Usuário do Sistema</strong> com estes escopos:
          <div class="ch-mono ch-sm" style="margin:6px 0;">
            instagram_basic, instagram_manage_messages, instagram_manage_comments,<br>
            pages_show_list, pages_manage_metadata, pages_messaging
          </div>
          Coloque em <code>META_ACCESS_TOKEN</code> no <code>.env</code>.
        </li>
        <li>Clique em <strong>Conectar conta</strong> — o sistema descobre a página e guarda o token dela.</li>
        <li>Clique em <strong>Assinar webhook</strong> na conta, para os eventos começarem a chegar.</li>
        <li>
          No painel do app, em <strong>Webhooks → Instagram</strong>, use a mesma URL do WhatsApp
          e assine os campos <code>messages</code> e <code>comments</code>:
          <div class="ch-flex" style="margin-top:6px;">
            <input type="text" class="ch-input ch-mono" readonly value="<?= $h($webhookUrl) ?>" id="ch-ig-url">
            <button type="button" class="ch-btn ch-btn--sm" id="ch-ig-copiar">Copiar</button>
          </div>
        </li>
        <li>Crie uma regra em <a href="<?= $base ?>/admin/chat/instagram/regras">Automação de comentários</a>.</li>
      </ol>
    </div>
  </div>

  <?php // ── Regras resumidas ───────────────────────────────────────────── ?>
  <div class="ch-card">
    <div class="ch-card-head">
      <h2>Automação de comentários</h2>
      <a href="<?= $base ?>/admin/chat/instagram/regras" class="ch-btn ch-btn--sm">Gerenciar</a>
    </div>
    <?php if (!$regras): ?>
      <div class="ch-vazio">
        <strong>Nenhuma regra criada</strong>
        <p style="max-width:52ch;margin:0 auto;">
          É aqui que mora o "comente <em>QUERO</em> e receba no direct": a regra reage ao
          comentário, responde em público e abre um direct com a pessoa.
        </p>
      </div>
    <?php else: ?>
      <div class="ch-tabela-wrap">
        <table class="ch-tabela">
          <thead><tr><th>Regra</th><th>Palavras</th><th>Faz o quê</th><th class="ch-num">Disparos</th></tr></thead>
          <tbody>
            <?php foreach (array_slice($regras, 0, 8) as $r): ?>
            <tr style="<?= (int)$r['ativo'] ? '' : 'opacity:.55;' ?>">
              <td class="ch-b"><?= $h($r['nome']) ?></td>
              <td class="ch-mono ch-sm ch-mut"><?= $h($r['palavras'] ?: 'qualquer comentário') ?></td>
              <td class="ch-sm">
                <?php
                $acoes = [];
                if ((int)$r['responder_publico']) $acoes[] = 'responde';
                if ((int)$r['enviar_dm'])         $acoes[] = 'manda DM';
                if ($r['fluxo_nome'])             $acoes[] = 'fluxo: ' . $r['fluxo_nome'];
                if ($r['tag_nome'])               $acoes[] = 'tag: ' . $r['tag_nome'];
                echo $h(implode(' · ', $acoes) ?: '—');
                ?>
              </td>
              <td class="ch-num"><?= $n($r['total_disparos']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function ($) {
  var BASE = window.BASE_URL || '<?= $base ?>';
  var CSRF = '<?= $h($csrf_token ?? '') ?>';

  function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
  function post(rota, dados) { return $.post(BASE + rota, $.extend({ csrf_token: CSRF }, dados || {}), null, 'json'); }

  function aviso(html, tipo) {
    $('#ch-ig-resultado').html('<div class="ch-aviso ch-aviso--' + tipo + '"><div>' + html + '</div></div>');
    if (tipo === 'ok') setTimeout(function () { $('#ch-ig-resultado').empty(); }, 5000);
  }

  $('#ch-ig-conectar').on('click', function () {
    var b = $(this).prop('disabled', true).text('Procurando...');
    post('/admin/chat/instagram/conectar').done(function (r) {
      if (r.ok) {
        aviso('<strong>' + r.contas + ' conta(s) conectada(s).</strong> Agora assine o webhook.', 'ok');
        setTimeout(function () { location.reload(); }, 1400);
      } else {
        aviso('<strong>Não deu para conectar</strong>' + esc(r.erro || ''), 'erro');
      }
    }).fail(function () {
      aviso('Erro de rede.', 'erro');
    }).always(function () { b.prop('disabled', false).text('Conectar conta'); });
  });

  $('.ch-ig-testar').on('click', function () {
    var b = $(this).prop('disabled', true);
    post('/admin/chat/instagram/' + $(this).data('id') + '/testar').done(function (r) {
      if (r.ok && r.resultado && r.resultado.ok) {
        var d = r.resultado;
        aviso('<strong>@' + esc(d.username) + ' respondendo</strong>' +
              esc(d.seguidores) + ' seguidores · ' + esc(d.publicacoes) + ' publicações', 'ok');
      } else {
        aviso('<strong>Falhou</strong>' + esc((r.resultado && r.resultado.mensagem) || r.erro || ''), 'erro');
      }
    }).always(function () { b.prop('disabled', false); });
  });

  $('.ch-ig-assinar').on('click', function () {
    var b = $(this).prop('disabled', true).text('Assinando...');
    post('/admin/chat/instagram/' + $(this).data('id') + '/assinar').done(function (r) {
      if (r.ok) {
        aviso('<strong>Webhook assinado.</strong>', 'ok');
        setTimeout(function () { location.reload(); }, 1200);
      } else if (r.parcial) {
        // Falta só a permissão da assinatura de página — comentários seguem funcionando
        aviso('<strong>Assinatura de página não concluída</strong>' + esc(r.erro || ''), 'aviso');
      } else {
        aviso('<strong>Não assinou</strong>' + esc(r.erro || ''), 'erro');
      }
    }).always(function () { b.prop('disabled', false).text('Assinar webhook'); });
  });

  $('.ch-ig-sinc').on('click', function () {
    var b = $(this).prop('disabled', true).text('Sincronizando...');
    post('/admin/chat/instagram/' + $(this).data('id') + '/sincronizar').done(function (r) {
      aviso(r.ok ? '<strong>' + esc(r.msg) + '</strong>' : '<strong>Falhou</strong>' + esc(r.erro || ''),
            r.ok ? 'ok' : 'erro');
    }).always(function () { b.prop('disabled', false).text('Sincronizar posts'); });
  });

  $('.ch-ig-ativo').on('click', function () {
    post('/admin/chat/instagram/' + $(this).data('id') + '/ativo').done(function () { location.reload(); });
  });

  $('.ch-ig-del').on('click', function () {
    if (!confirm('Desconectar esta conta?\n\nOs contatos e conversas já recebidos são preservados.')) return;
    post('/admin/chat/instagram/' + $(this).data('id') + '/desconectar').done(function () { location.reload(); });
  });

  $('#ch-ig-copiar').on('click', function () {
    var el = document.getElementById('ch-ig-url'), btn = $(this), txt = btn.text(), ok = false;
    try { el.select(); el.setSelectionRange(0, 99999); ok = document.execCommand('copy'); } catch (e) {}
    if (!ok && navigator.clipboard) { navigator.clipboard.writeText(el.value); ok = true; }
    btn.text(ok ? 'Copiado!' : 'Selecione e copie');
    setTimeout(function () { btn.text(txt); }, 1600);
  });
})(jQuery);
</script>
