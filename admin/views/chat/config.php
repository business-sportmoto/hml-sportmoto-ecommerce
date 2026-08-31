<?php
/**
 * admin/views/chat/config.php
 *
 * @var array $config @var array $saude @var string $webhookUrl
 * @var string $verifyToken @var bool $temSecret @var array $ultimosWebhooks
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$cfg  = fn(string $k, $d = '') => $config[$k] ?? $d;
$on   = fn(string $k, bool $d = false) => in_array(strtolower((string)($config[$k] ?? ($d ? '1' : '0'))), ['1','true','sim','on'], true);
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Chat — Configuração</h1>
      <p>Conexão com a Meta, comportamento do bot e janela de envio.</p>
    </div>
    <div class="ch-head-acoes">
      <button type="button" class="ch-btn" id="ch-testar">Testar conexão</button>
      <a href="<?= $base ?>/admin/chat/templates" class="ch-btn">Templates</a>
    </div>
  </div>

  <?php // ── Passo 1: credenciais ─────────────────────────────────────── ?>
  <div class="ch-card" style="margin-bottom:16px;">
    <div class="ch-card-head">
      <h2>1. Conexão com a Meta</h2>
      <?php if ($saude['meta_ok']): ?>
        <span class="ch-badge ch-badge--ok">Número conectado</span>
      <?php else: ?>
        <span class="ch-badge ch-badge--erro">Não conectado</span>
      <?php endif; ?>
    </div>
    <div class="ch-card-body">
      <p class="ch-sm ch-mut" style="margin:0 0 14px;">
        As credenciais ficam no arquivo <code class="ch-mono">.env</code> — nunca no banco nem nesta tela.
      </p>

      <div class="ch-tabela-wrap">
        <table class="ch-tabela">
          <thead><tr><th>Variável</th><th>Situação</th><th>Onde encontrar</th></tr></thead>
          <tbody>
            <tr>
              <td class="ch-mono">META_PHONE_NUMBER_ID</td>
              <td><?= $saude['meta_ok'] ? '<span class="ch-badge ch-badge--ok">definida</span>' : '<span class="ch-badge ch-badge--erro">faltando</span>' ?></td>
              <td class="ch-sm ch-mut">Meta Business → WhatsApp → Configuração da API</td>
            </tr>
            <tr>
              <td class="ch-mono">META_CLOUD_API_TOKEN</td>
              <td><?= $saude['meta_ok'] ? '<span class="ch-badge ch-badge--ok">definida</span>' : '<span class="ch-badge ch-badge--erro">faltando</span>' ?></td>
              <td class="ch-sm ch-mut">Token permanente do usuário de sistema</td>
            </tr>
            <tr>
              <td class="ch-mono">META_APP_SECRET</td>
              <td><?= $temSecret ? '<span class="ch-badge ch-badge--ok">definida</span>' : '<span class="ch-badge ch-badge--erro">faltando</span>' ?></td>
              <td class="ch-sm ch-mut">Painel do App → Configurações → Básico → “Chave Secreta do App”</td>
            </tr>
            <tr>
              <td class="ch-mono">META_WEBHOOK_VERIFY_TOKEN</td>
              <td><?= $verifyToken !== '' ? '<span class="ch-badge ch-badge--ok">definida</span>' : '<span class="ch-badge ch-badge--erro">faltando</span>' ?></td>
              <td class="ch-sm ch-mut">Você escolhe — já foi gerado um valor no .env</td>
            </tr>
          </tbody>
        </table>
      </div>

      <?php if (!$temSecret): ?>
        <div class="ch-aviso ch-aviso--erro" style="margin:16px 0 0;">
          <div>
            <strong>O bot não vai responder até isso ser resolvido</strong>
            Sem <code>META_APP_SECRET</code> não há como provar que a mensagem veio mesmo da Meta,
            e o webhook recusa tudo. Copie a “Chave Secreta do App” no painel da Meta e cole no
            <code>.env</code>, depois recarregue esta página.
            <div style="margin-top:8px;">
              Se precisar rodar antes de conseguir o segredo, desmarque
              <em>“Exigir assinatura no webhook”</em> abaixo — mas isso deixa o endereço aberto
              para qualquer um forjar mensagem em nome de um cliente. Use só para teste.
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div id="ch-teste-resultado" style="margin-top:14px;"></div>
    </div>
  </div>

  <?php // ── Passo 2: webhook ──────────────────────────────────────────── ?>
  <div class="ch-card" style="margin-bottom:16px;">
    <div class="ch-card-head">
      <h2>2. Webhook</h2>
      <?php if ($saude['ultimo_webhook']): ?>
        <span class="ch-badge ch-badge--ok">
          último em <?= date('d/m H:i', strtotime((string)$saude['ultimo_webhook'])) ?>
        </span>
      <?php else: ?>
        <span class="ch-badge ch-badge--aviso">nenhuma chamada recebida</span>
      <?php endif; ?>
    </div>
    <div class="ch-card-body">
      <p class="ch-sm ch-mut" style="margin:0 0 14px;">
        No painel da Meta: <strong>WhatsApp → Configuração → Webhook → Editar</strong>.
        Cole os dois valores abaixo e assine o campo <code class="ch-mono">messages</code>.
      </p>

      <div class="ch-campo">
        <label class="ch-label">URL de callback</label>
        <div class="ch-flex">
          <input type="text" class="ch-input ch-mono" readonly value="<?= $h($webhookUrl) ?>" id="ch-url">
          <button type="button" class="ch-btn" data-copiar="#ch-url">Copiar</button>
        </div>
        <?php if (!str_starts_with($webhookUrl, 'https://')): ?>
          <div class="ch-ajuda" style="color:var(--warning);">
            A Meta só aceita <strong>https</strong> com certificado válido. Em ambiente local
            este endereço não vai funcionar — publique ou use um túnel (ngrok, Cloudflare Tunnel).
          </div>
        <?php endif; ?>
      </div>

      <div class="ch-campo">
        <label class="ch-label">Verificar token</label>
        <div class="ch-flex">
          <input type="text" class="ch-input ch-mono" readonly value="<?= $h($verifyToken) ?>" id="ch-vt">
          <button type="button" class="ch-btn" data-copiar="#ch-vt">Copiar</button>
        </div>
        <div class="ch-ajuda">Definido em <code class="ch-mono">META_WEBHOOK_VERIFY_TOKEN</code> no .env.</div>
      </div>

      <?php if ((int)$saude['webhooks_recusados_24h'] > 0): ?>
        <div class="ch-aviso ch-aviso--aviso" style="margin-bottom:0;">
          <div>
            <strong><?= (int)$saude['webhooks_recusados_24h'] ?> chamada(s) recusada(s) nas últimas 24h</strong>
            Assinatura inválida. Confira se a <code>META_APP_SECRET</code> é a do MESMO app
            que envia o webhook.
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php // ── Passo 3: comportamento ────────────────────────────────────── ?>
  <form id="ch-form-config">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf_token ?? '') ?>">

    <div class="ch-card" style="margin-bottom:16px;">
      <div class="ch-card-head"><h2>3. Comportamento do bot</h2></div>
      <div class="ch-card-body">

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="bot_ativo" value="1" <?= $on('bot_ativo', true) ? 'checked' : '' ?>>
            <span>
              <strong>Automação ligada</strong>
              <div class="ch-ajuda">Desligado, as mensagens ainda chegam no atendimento, mas nenhum fluxo ou gatilho dispara.</div>
            </span>
          </label>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="auto_marcar_lida" value="1" <?= $on('auto_marcar_lida', true) ? 'checked' : '' ?>>
            <span>
              <strong>Marcar como lida automaticamente</strong>
              <div class="ch-ajuda">Mostra os dois tiques azuis para o cliente assim que a mensagem chega aqui.</div>
            </span>
          </label>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="baixar_midia" value="1" <?= $on('baixar_midia', true) ? 'checked' : '' ?>>
            <span>
              <strong>Baixar mídia recebida</strong>
              <div class="ch-ajuda">
                Guarda fotos, áudios e documentos no servidor. A URL da Meta expira em poucos
                minutos — sem isso, o anexo some do histórico.
              </div>
            </span>
          </label>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="assinatura_obrigatoria" value="1" <?= $on('assinatura_obrigatoria', true) ? 'checked' : '' ?>>
            <span>
              <strong>Exigir assinatura no webhook</strong>
              <div class="ch-ajuda" style="color:var(--danger);">
                Mantenha ligado. Desligado, qualquer pessoa que descobrir a URL pode simular
                mensagens de qualquer número e disparar seus fluxos.
              </div>
            </span>
          </label>
        </div>

        <div class="ch-campo">
          <label class="ch-label">Pausar o bot por quantos minutos quando um atendente responde</label>
          <input type="number" class="ch-input" name="pausa_bot_minutos" min="0" max="1440"
                 value="<?= (int)$cfg('pausa_bot_minutos', 60) ?>" style="max-width:160px;">
          <div class="ch-ajuda">
            Evita o robô falar por cima do atendente. <strong>0</strong> = pausa até alguém
            resolver a conversa manualmente.
          </div>
        </div>

        <div class="ch-campo">
          <label class="ch-label">Palavras que cancelam o recebimento</label>
          <input type="text" class="ch-input" name="optout_palavras"
                 value="<?= $h($cfg('optout_palavras', 'sair,parar,cancelar,descadastrar,stop')) ?>">
          <div class="ch-ajuda">
            Separadas por vírgula. A comparação é <strong>exata</strong> — “não quero parar de
            comprar” não descadastra ninguém.
          </div>
        </div>
      </div>
    </div>

    <div class="ch-card" style="margin-bottom:16px;">
      <div class="ch-card-head"><h2>4. Horário de envio</h2></div>
      <div class="ch-card-body">
        <div class="ch-aviso ch-aviso--info">
          <div>
            Vale apenas para o que a loja <strong>inicia</strong> — campanhas e fluxos agendados.
            Resposta a quem está conversando agora sai a qualquer hora.
          </div>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="quiet_hours_ativo" value="1" <?= $on('quiet_hours_ativo', true) ? 'checked' : '' ?>>
            <span><strong>Respeitar horário comercial</strong></span>
          </label>
        </div>

        <div class="ch-grid-3">
          <div class="ch-campo">
            <label class="ch-label">A partir de</label>
            <select class="ch-select" name="quiet_hours_inicio">
              <?php for ($i = 0; $i < 24; $i++): ?>
                <option value="<?= $i ?>" <?= (int)$cfg('quiet_hours_inicio', 8) === $i ? 'selected' : '' ?>><?= sprintf('%02dh', $i) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="ch-campo">
            <label class="ch-label">Até</label>
            <select class="ch-select" name="quiet_hours_fim">
              <?php for ($i = 1; $i <= 24; $i++): ?>
                <option value="<?= $i ?>" <?= (int)$cfg('quiet_hours_fim', 21) === $i ? 'selected' : '' ?>><?= sprintf('%02dh', $i) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="ch-campo">
            <label class="ch-label">Janela de atendimento (horas)</label>
            <input type="number" class="ch-input" name="janela_horas" min="1" max="24"
                   value="<?= (int)$cfg('janela_horas', 24) ?>">
            <div class="ch-ajuda">A Meta permite 24h. Diminuir só torna a regra mais rígida.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="ch-flex" style="justify-content:flex-end;gap:8px;margin-bottom:20px;">
      <span id="ch-cfg-msg" class="ch-sm"></span>
      <button type="submit" class="ch-btn ch-btn--pri">Salvar configuração</button>
    </div>
  </form>

  <?php // ── Diagnóstico ───────────────────────────────────────────────── ?>
  <div class="ch-card">
    <div class="ch-card-head">
      <h2>Últimas chamadas do webhook</h2>
      <span class="ch-sm ch-mut"><?= (int)$saude['webhooks_24h'] ?> nas últimas 24h</span>
    </div>
    <?php if (!$ultimosWebhooks): ?>
      <div class="ch-vazio">
        <strong>Nenhuma chamada ainda</strong>
        Depois de cadastrar a URL no painel da Meta, mande uma mensagem para o número
        da loja — ela deve aparecer aqui em segundos.
      </div>
    <?php else: ?>
      <div class="ch-tabela-wrap">
        <table class="ch-tabela">
          <thead><tr><th>Quando</th><th>Evento</th><th>Assinatura</th><th>Processado</th><th>Erro</th></tr></thead>
          <tbody>
            <?php foreach ($ultimosWebhooks as $w): ?>
            <tr>
              <td class="ch-sm ch-mut"><?= date('d/m H:i:s', strtotime((string)$w['criado_em'])) ?></td>
              <td class="ch-sm"><?= $h($w['evento'] ?: '—') ?></td>
              <td>
                <?= (int)$w['assinatura_ok']
                      ? '<span class="ch-badge ch-badge--ok">válida</span>'
                      : '<span class="ch-badge ch-badge--erro">inválida</span>' ?>
              </td>
              <td>
                <?= (int)$w['processado']
                      ? '<span class="ch-badge ch-badge--ok">sim</span>'
                      : '<span class="ch-badge ch-badge--neutro">não</span>' ?>
              </td>
              <td class="ch-sm ch-mut"><?= $h(mb_substr((string)$w['erro'], 0, 70)) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="ch-aviso ch-aviso--info ch-mt">
    <div>
      <strong>Não esqueça do cron</strong>
      Esperas, timeouts e campanhas dependem do worker rodando a cada minuto:
      <div class="ch-mono ch-sm" style="margin-top:6px;">
        * * * * * cd <?= $h(defined('ROOT_PATH') ? ROOT_PATH : '/caminho/do/projeto') ?> &amp;&amp; php cli/chat-worker.php &gt;&gt; storage/logs/chat-worker.log 2&gt;&amp;1
      </div>
    </div>
  </div>
</div>

<script>
(function ($) {
  var BASE = window.BASE_URL || '<?= $base ?>';
  var CSRF = $('input[name=csrf_token]').val();

  function aviso(el, texto, ok) {
    $(el).text(texto).css('color', ok ? 'var(--success)' : 'var(--danger)');
    setTimeout(function () { $(el).text(''); }, 4000);
  }

  $('[data-copiar]').on('click', function () {
    var alvo = $($(this).data('copiar'))[0];
    if (!alvo) return;
    var btn = $(this), original = btn.text();
    // navigator.clipboard exige contexto seguro; select+execCommand cobre http local
    var ok = false;
    try {
      alvo.select(); alvo.setSelectionRange(0, 99999);
      ok = document.execCommand('copy');
    } catch (e) { ok = false; }
    if (!ok && navigator.clipboard) { navigator.clipboard.writeText(alvo.value); ok = true; }
    btn.text(ok ? 'Copiado!' : 'Selecione e copie');
    setTimeout(function () { btn.text(original); }, 1800);
  });

  $('#ch-testar').on('click', function () {
    var btn = $(this).prop('disabled', true).text('Testando...');
    $.post(BASE + '/admin/chat/config/testar', { csrf_token: CSRF }, function (r) {
      var box = $('#ch-teste-resultado');
      if (r.ok && r.resultado && r.resultado.ok) {
        var d = r.resultado;
        box.html('<div class="ch-aviso ch-aviso--ok" style="margin:0;"><div>' +
          '<strong>Conectado</strong>' +
          'Número: <strong>' + $('<i>').text(d.numero).html() + '</strong> · ' +
          'Nome: <strong>' + $('<i>').text(d.nome).html() + '</strong> · ' +
          'Qualidade: <strong>' + $('<i>').text(d.qualidade).html() + '</strong> · ' +
          'Limite: <strong>' + $('<i>').text(d.limite).html() + '</strong>' +
          '</div></div>');
      } else {
        var msg = (r.resultado && r.resultado.mensagem) || r.erro || 'Falha desconhecida.';
        box.html('<div class="ch-aviso ch-aviso--erro" style="margin:0;"><div>' +
          '<strong>Não conectou</strong>' + $('<i>').text(msg).html() + '</div></div>');
      }
    }, 'json').fail(function () {
      $('#ch-teste-resultado').html('<div class="ch-aviso ch-aviso--erro" style="margin:0;"><div>Erro de rede.</div></div>');
    }).always(function () {
      btn.prop('disabled', false).text('Testar conexão');
    });
  });

  $('#ch-form-config').on('submit', function (e) {
    e.preventDefault();
    var btn = $(this).find('button[type=submit]').prop('disabled', true);
    $.post(BASE + '/admin/chat/config/salvar', $(this).serialize(), function (r) {
      aviso('#ch-cfg-msg', r.ok ? 'Configuração salva.' : (r.erro || 'Falha ao salvar.'), !!r.ok);
    }, 'json').fail(function () {
      aviso('#ch-cfg-msg', 'Erro de rede.', false);
    }).always(function () { btn.prop('disabled', false); });
  });
})(jQuery);
</script>
