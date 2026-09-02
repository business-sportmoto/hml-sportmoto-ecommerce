<?php
/**
 * admin/views/chat/config.php
 *
 * @var array $config @var array $saude @var string $webhookUrl
 * @var string $verifyToken @var bool $temSecret @var array $ultimosWebhooks
 * @var string $meuNome
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
            <strong class="ch-aviso-tit">O bot não vai responder até isso ser resolvido</strong>
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
            <strong class="ch-aviso-tit"><?= (int)$saude['webhooks_recusados_24h'] ?> chamada(s) recusada(s) nas últimas 24h</strong>
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

    <div class="ch-card" style="margin-bottom:16px;">
      <div class="ch-card-head"><h2>5. Assinatura do atendente</h2></div>
      <div class="ch-card-body">
        <div class="ch-aviso ch-aviso--info">
          <div>
            Vale só para o que um atendente escreve no <strong>Atendimento</strong>. Automação,
            gatilho e campanha continuam saindo sem nome — e template HSM nunca é assinado,
            porque o corpo dele é fixo na Meta.
          </div>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="assinatura_agente" value="1" id="ch-ass-on"
                   <?= $on('assinatura_agente', true) ? 'checked' : '' ?>>
            <span>
              <strong>Assinar as respostas com o nome de quem atendeu</strong>
              <div class="ch-ajuda">
                O nome é o de <strong>quem escreveu</strong>, não o do responsável pela conversa:
                se outra pessoa responde no seu lugar, o cliente lê o nome de quem realmente falou.
              </div>
            </span>
          </label>
        </div>

        <div class="ch-grid-3">
          <div class="ch-campo">
            <label class="ch-label">Quanto do nome mostrar</label>
            <select class="ch-select" name="assinatura_nome" id="ch-ass-nome">
              <?php $modo = (string)$cfg('assinatura_nome', 'dois'); ?>
              <option value="primeiro" <?= $modo === 'primeiro' ? 'selected' : '' ?>>Só o primeiro nome</option>
              <option value="dois"     <?= $modo === 'dois'     ? 'selected' : '' ?>>Primeiro e segundo</option>
              <option value="completo" <?= $modo === 'completo' ? 'selected' : '' ?>>Nome completo</option>
            </select>
          </div>
          <div class="ch-campo">
            <label class="ch-label">Formato</label>
            <input type="text" class="ch-input ch-mono" name="assinatura_formato" id="ch-ass-fmt"
                   maxlength="60" value="<?= $h($cfg('assinatura_formato', '*{nome}:*')) ?>">
            <div class="ch-ajuda">
              Precisa conter <code class="ch-mono">{nome}</code>. Os asteriscos deixam o texto
              em negrito no WhatsApp; no Instagram eles são removidos automaticamente, porque
              lá apareceriam na tela.
            </div>
          </div>
        </div>

        <div class="ch-campo" style="margin-bottom:0;">
          <label class="ch-label">Prévia</label>
          <div class="ch-mono ch-sm" id="ch-ass-previa"
               style="white-space:pre-wrap;padding:10px 12px;border-radius:8px;background:var(--bg-2,#f4f4f5);"></div>
        </div>
      </div>
    </div>

    <div class="ch-card" style="margin-bottom:16px;">
      <div class="ch-card-head">
        <h2>6. Avisos no sino</h2>
        <span class="ch-sm ch-mut">categoria <strong>Atendimento</strong></span>
      </div>
      <div class="ch-card-body">
        <div class="ch-aviso ch-aviso--info">
          <div>
            Cai no sino do topo do painel, junto das outras notificações. Nada disso
            vai para o cliente — é comunicação interna da equipe.
          </div>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="notif_conversa_nova" value="1" <?= $on('notif_conversa_nova', true) ? 'checked' : '' ?>>
            <span>
              <strong>Mensagem sem responsável</strong>
              <div class="ch-ajuda">
                Avisa quem atende (super, gerente, vendedor). Fica quieto quando a
                automação já respondeu — ninguém precisa correr para uma conversa
                que o robô está conduzindo.
              </div>
            </span>
          </label>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="notif_mensagem" value="1" <?= $on('notif_mensagem', true) ? 'checked' : '' ?>>
            <span>
              <strong>Mensagem numa conversa que tem responsável</strong>
              <div class="ch-ajuda">Só o responsável recebe.</div>
            </span>
          </label>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="notif_atribuicao" value="1" <?= $on('notif_atribuicao', true) ? 'checked' : '' ?>>
            <span>
              <strong>Conversa atribuída a você</strong>
              <div class="ch-ajuda">Pegar uma conversa para si não gera aviso.</div>
            </span>
          </label>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="notif_campanha" value="1" <?= $on('notif_campanha', true) ? 'checked' : '' ?>>
            <span>
              <strong>Campanha concluída</strong>
              <div class="ch-ajuda">Vai para quem criou a campanha, com o resumo de enviados e falhas.</div>
            </span>
          </label>
        </div>

        <div class="ch-grid-3">
          <div class="ch-campo">
            <label class="ch-label">Silêncio por conversa (min)</label>
            <input type="number" class="ch-input" name="notif_silencio_min" min="0" max="1440"
                   value="<?= (int)$cfg('notif_silencio_min', 15) ?>">
            <div class="ch-ajuda">
              Cinco mensagens seguidas viram <strong>um</strong> aviso. É o que separa
              um sino útil de um que todo mundo aprende a ignorar. <strong>0</strong> = avisa sempre.
            </div>
          </div>
          <div class="ch-campo">
            <label class="ch-label">Cliente esperando há (min)</label>
            <input type="number" class="ch-input" name="notif_sem_resposta_min" min="0" max="1440"
                   value="<?= (int)$cfg('notif_sem_resposta_min', 30) ?>">
            <div class="ch-ajuda">
              Um aviso por espera, para o responsável — ou para os gestores, se a
              conversa estiver sem dono. Depende do <strong>cron do worker</strong>.
              <strong>0</strong> = desligado.
            </div>
          </div>
          <div class="ch-campo">
            <label class="ch-label">Falhas de envio em 1h</label>
            <input type="number" class="ch-input" name="notif_falhas_min" min="0" max="1000"
                   value="<?= (int)$cfg('notif_falhas_min', 5) ?>">
            <div class="ch-ajuda">
              A partir daqui, avisa os gestores com o último erro — costuma ser o
              diagnóstico inteiro (token expirado, template pausado).
              <strong>0</strong> = desligado.
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="ch-card" style="margin-bottom:16px;">
      <div class="ch-card-head"><h2>7. Agente de IA</h2></div>
      <div class="ch-card-body">
        <div class="ch-aviso ch-aviso--info">
          <div>
            Vale para o bloco <strong>Etapa de IA</strong> dos fluxos. Comentário sem
            pergunta — “top”, “😍”, uma marcação — nem chega ao modelo: num reel viral
            isso é a maior parte do volume, e responder emoji com IA é o jeito mais
            rápido de queimar a cota do dia.
          </div>
        </div>

        <div class="ch-campo" style="margin-bottom:0;max-width:280px;">
          <label class="ch-label">Respostas de IA por dia</label>
          <input type="number" class="ch-input" name="ia_limite_dia" min="0" max="100000"
                 value="<?= (int)$cfg('ia_limite_dia', 500) ?>">
          <div class="ch-ajuda">
            Atingido o teto, o agente devolve as conversas para a régua fixa até o dia
            seguinte, e os gestores recebem um aviso no sino. <strong>0</strong> = sem teto.
            O limite de gasto por provedor continua valendo por cima deste.
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
      <strong class="ch-aviso-tit">Não esqueça do cron</strong>
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

  // ── Prévia da assinatura ────────────────────────────────────────────────
  var MEU_NOME = <?= json_encode((string)($meuNome ?? ''), JSON_UNESCAPED_UNICODE) ?> || 'Maria Souza';

  function recortarNome(nome, modo) {
    var p = nome.trim().split(/\s+/).filter(Boolean);
    if (!p.length) return '';
    if (modo === 'primeiro') return p[0];
    if (modo === 'completo') return p.join(' ');
    return p.slice(0, 2).join(' ');
  }

  function previaAssinatura() {
    var box = $('#ch-ass-previa');
    if (!$('#ch-ass-on').is(':checked')) {
      box.text('Desligado — as respostas saem sem nome.');
      return;
    }
    var nome = recortarNome(MEU_NOME, $('#ch-ass-nome').val());
    var fmt  = ($('#ch-ass-fmt').val() || '').trim();
    if (fmt.indexOf('{nome}') === -1) {
      box.text('O formato precisa conter {nome}.');
      return;
    }
    var pre = fmt.replace('{nome}', nome);
    box.text('WhatsApp:  ' + pre + '\nOlá, tudo bem?\n\n' +
             'Instagram: ' + pre.replace(/[*_~]/g, '') + '\nOlá, tudo bem?');
  }

  $('#ch-ass-on, #ch-ass-nome').on('change', previaAssinatura);
  $('#ch-ass-fmt').on('input', previaAssinatura);
  previaAssinatura();

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
