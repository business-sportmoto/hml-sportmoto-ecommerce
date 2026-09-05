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

// ── O trilho ────────────────────────────────────────────────────────────────
// Onze status contam uma história de cinco atos. O trilho mostra o ato, não o
// status cru: "Aguardando" diz mais a quem vende do que "aguardando_resposta".
$etapas = [
    ['rotulo' => 'Abandonado', 'ico' => '🛒'],
    ['rotulo' => 'Contato',    'ico' => '💬'],
    ['rotulo' => 'Aguardando', 'ico' => '⏳'],
    ['rotulo' => 'Respondeu',  'ico' => '🙋'],
    ['rotulo' => 'Fechado',    'ico' => '🏁'],
];
$etapaDoStatus = [
    'novo' => 0, 'abandonado' => 0, 'sem_contato' => 0,
    'em_recuperacao' => 1, 'msg_enviada' => 1,
    'aguardando_resposta' => 2,
    'respondeu' => 3, 'negociacao' => 3,
    'recuperado' => 4, 'perdido' => 4, 'ignorado' => 4,
];
$etapaAtual = $etapaDoStatus[$rec['status']] ?? 0;
$ganhou     = $rec['status'] === 'recuperado';
$encerrou   = in_array($rec['status'], ['recuperado', 'perdido', 'ignorado'], true);

// Marcos: QUANDO cada ato aconteceu, tirado da trilha de eventos. É isto que
// separa um trilho de verdade de uma barra de progresso decorativa — o
// vendedor lê "msg enviada 14:32" e sabe há quanto tempo está sem resposta.
$marcos   = [];
$porEtapa = [
    'abandono_detectado' => 0,
    'whatsapp_enviado'   => 1, 'email_enviado' => 1, 'chat_cupom_enviado' => 1,
    'cliente_retornou'   => 3,
    'recuperado'         => 4, 'perdido' => 4,
];
foreach ($eventos as $e) {
    $idx = $porEtapa[$e['tipo']] ?? null;

    // Mudança de status carrega o destino no meta — é como se sabe quando a
    // conversa entrou em "aguardando" ou "negociação", que não têm evento próprio.
    if ($idx === null && $e['tipo'] === 'status_alterado') {
        // getEventos() JÁ devolve o meta decodificado — tratar como string aqui
        // dava "Array to string conversion" e, pior, o json_decode devolvia
        // null em silêncio: a etapa "Aguardando" nunca ganhava data.
        $meta = is_array($e['meta'] ?? null) ? $e['meta'] : [];
        $idx  = $etapaDoStatus[(string)($meta['para'] ?? '')] ?? null;
    }
    if ($idx === null) continue;

    $ts = strtotime((string)$e['criado_em']);
    // O primeiro de cada ato é o que importa: a hora em que virou aquilo
    if (!isset($marcos[$idx]) || $ts < $marcos[$idx]) $marcos[$idx] = $ts;
}
if (!isset($marcos[0])) $marcos[0] = strtotime((string)$rec['abandonado_em']);

$score   = (int)($rec['score'] ?? 0);
$prioCfg = [
    'imediata' => ['Imediata', 'var(--danger)'], 'alta'  => ['Alta',  'var(--warning)'],
    'media'    => ['Média',    'var(--blue)'],   'baixa' => ['Baixa', 'var(--text-2)'],
];
[$prioLabel, $prioCor] = $prioCfg[$rec['prioridade'] ?? 'baixa'] ?? ['—', 'var(--text-2)'];

$temDono  = !empty($rec['responsavel_id']);
$souODono = $temDono && (int)$rec['responsavel_id'] === (int)Session::get('usuario_id');
?>
<style>
/* ══ Trilho da recuperação ═════════════════════════════════════════════════
   O bloco responde uma pergunta só: onde este carrinho está, e o que dá para
   fazer agora. Por isso valor, etapa e ações moram juntos e no topo. */
.rec-topo { padding:18px 20px 16px; }

.rec-resumo { display:flex;align-items:center;justify-content:space-between;
              gap:16px;flex-wrap:wrap;margin-bottom:20px; }

.rec-valor  { font-size:27px;font-weight:800;letter-spacing:-.6px;line-height:1;
              color:var(--c-text);display:flex;align-items:baseline;gap:8px; }
.rec-cifra  { font-size:14px;font-weight:700;color:var(--c-text-muted);margin-right:-4px; }
.rec-valor small { font-size:12.5px;font-weight:600;color:var(--c-text-muted);
                   letter-spacing:0; }

.rec-tags   { display:flex;align-items:center;gap:9px;flex-wrap:wrap; }
.rec-tag    { display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;
              padding:5px 11px;border-radius:99px;color:var(--cor);
              background:color-mix(in srgb, var(--cor) 12%, transparent);
              border:1px solid color-mix(in srgb, var(--cor) 30%, transparent); }
.rec-tag i  { width:6px;height:6px;border-radius:50%;background:var(--cor);display:block; }

/* Score como anel: o número sozinho não diz se 60 é muito ou pouco */
.rec-score  { position:relative;width:38px;height:38px;display:inline-flex;
              align-items:center;justify-content:center; }
.rec-score svg { position:absolute;inset:0;transform:rotate(-90deg); }
.rec-score circle { fill:none;stroke-width:3.2;}
.rec-score .tr { stroke:var(--c-border); }
.rec-score .vl { stroke:var(--c-primary);stroke-linecap:round;
                 transition:stroke-dasharray .5s ease; }
.rec-score b   { font-size:12px;font-weight:800;color:var(--c-text); }

.rec-dono, .rec-agendado, .rec-tentativas {
  font-size:11.5px;font-weight:600;padding:5px 11px;border-radius:99px;
  background:var(--bg);border:1px solid var(--c-border);color:var(--c-text-muted); }
.rec-dono.meu { background:var(--success-lt);border-color:var(--success-bd);color:var(--success); }

/* ── O trilho ── */
.rec-trilho { list-style:none;margin:0 0 18px;padding:0;display:grid;
              grid-template-columns:repeat(5,1fr);position:relative; }

/* A linha de trás e a que preenche até a etapa atual. Duas camadas em vez de
   borda por item: assim o preenchimento é uma transição só, sem degrau. */
.rec-trilho::before, .rec-trilho::after {
  content:'';position:absolute;top:17px;height:3px;border-radius:2px;
  left:10%;right:10%; }
.rec-trilho::before { background:var(--c-border); }
.rec-trilho::after  { right:auto;background:linear-gradient(90deg,var(--c-primary),var(--blue));
                      width:calc((80% / 4) * var(--ate));transition:width .6s cubic-bezier(.4,0,.2,1); }
.rec-trilho.perdeu::after { background:var(--c-text-muted);opacity:.55; }

.rec-trilho li { position:relative;z-index:1;display:flex;flex-direction:column;
                 align-items:center;gap:5px;text-align:center; }

.rec-no  { width:35px;height:35px;border-radius:50%;display:flex;align-items:center;
           justify-content:center;font-size:15px;background:var(--c-surface);
           border:3px solid var(--c-border);transition:all .3s ease; }
.rec-rot { font-size:11.5px;font-weight:700;color:var(--c-text-muted);line-height:1.25; }
.rec-quando { font-size:10.5px;color:var(--c-text-muted);opacity:.75;font-variant-numeric:tabular-nums; }

.rec-trilho .feito .rec-no { border-color:var(--c-primary);
                             background:color-mix(in srgb, var(--c-primary) 12%, var(--c-surface)); }
.rec-trilho .feito .rec-rot { color:var(--c-text); }

/* A etapa atual é a única que pulsa — mais de um ponto pulsando não destaca
   nada. O anel fica fora do fluxo, então o trilho não muda de altura. */
.rec-trilho .agora .rec-no {
  border-color:var(--c-primary);background:var(--c-primary);color:#fff;
  box-shadow:0 0 0 5px color-mix(in srgb, var(--c-primary) 18%, transparent);
  animation:recPulso 2.2s ease-in-out infinite; }
.rec-trilho .agora .rec-rot { color:var(--c-primary);font-weight:800; }

.rec-trilho .venceu .rec-no { border-color:var(--success);background:var(--success);
                              color:#fff;animation:none;
                              box-shadow:0 0 0 5px color-mix(in srgb, var(--success) 20%, transparent); }
.rec-trilho .venceu .rec-rot { color:var(--success); }
.rec-trilho.perdeu .agora .rec-no { border-color:var(--c-text-muted);
                                    background:var(--c-text-muted);animation:none;box-shadow:none; }

@keyframes recPulso {
  0%,100% { box-shadow:0 0 0 5px color-mix(in srgb, var(--c-primary) 18%, transparent); }
  50%     { box-shadow:0 0 0 9px color-mix(in srgb, var(--c-primary) 6%, transparent); }
}
@media (prefers-reduced-motion:reduce) {
  .rec-trilho .agora .rec-no { animation:none; }
  .rec-trilho::after { transition:none; }
}

/* ── Barra de ações ── */
.rec-acoes { display:flex;align-items:center;gap:9px;flex-wrap:wrap;
             padding-top:15px;border-top:1px solid var(--c-border); }
.rec-sep   { flex:1; }

.rec-bt { display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:700;
          padding:9px 16px;border-radius:9px;border:1px solid var(--c-border);
          background:var(--c-surface);color:var(--c-text);cursor:pointer;
          transition:transform .12s ease, box-shadow .12s ease, background .12s ease; }
.rec-bt span { font-size:14px;line-height:1; }
.rec-bt:hover:not(:disabled)  { transform:translateY(-1px);box-shadow:0 3px 10px rgba(15,23,42,.10); }
.rec-bt:active:not(:disabled) { transform:none;box-shadow:none; }
.rec-bt:disabled { opacity:.45;cursor:not-allowed; }

.rec-bt-capturar { background:var(--c-text);border-color:var(--c-text);color:var(--c-surface); }
.rec-bt-wpp  { background:var(--success);border-color:var(--success);color:#fff; }
.rec-bt-mail { background:var(--blue);border-color:var(--blue);color:#fff; }
.rec-bt-mais { background:var(--bg); }

.rec-travado { font-size:12px;font-weight:600;color:var(--warning);
               background:var(--warning-lt);border:1px solid var(--warning-bd);
               padding:8px 13px;border-radius:9px; }

@media (max-width:900px) {
  .rec-trilho .rec-rot, .rec-trilho .rec-quando { font-size:10px; }
  .rec-valor { font-size:23px; }
}
</style>

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

    <!-- ═══ Trilho da recuperação + ações ═══ -->
    <div class="admin-card rec-topo">
      <div class="rec-resumo">
        <div class="rec-valor">
          <span class="rec-cifra">R$</span><?= number_format((float)$rec['valor_snapshot'], 2, ',', '.') ?>
          <small><?= (int)$rec['itens_snapshot'] ?> <?= (int)$rec['itens_snapshot'] === 1 ? 'item' : 'itens' ?></small>
        </div>

        <div class="rec-tags">
          <span class="rec-tag" style="--cor:<?= $prioCor ?>;"><i></i><?= $prioLabel ?></span>

          <?php /* O score já existe e decide o roteamento da automação —
                   mostrá-lo aqui evita abrir a lista para saber o peso deste. */ ?>
          <span class="rec-score" title="Score de recuperação: <?= $score ?>/100">
            <svg viewBox="0 0 36 36" aria-hidden="true">
              <circle class="tr" cx="18" cy="18" r="15.5"></circle>
              <circle class="vl" cx="18" cy="18" r="15.5"
                      style="stroke-dasharray:<?= round($score * 0.974, 2) ?> 200;"></circle>
            </svg>
            <b><?= $score ?></b>
          </span>

          <?php if ($temDono): ?>
          <span class="rec-dono<?= $souODono ? ' meu' : '' ?>">
            <?= $souODono ? '★' : '👤' ?> <?= View::e($rec['responsavel_nome'] ?? '') ?>
          </span>
          <?php endif; ?>

          <?php if (!empty($rec['proximo_contato_em'])): ?>
          <span class="rec-agendado" title="Próximo contato agendado">
            📅 <?= date('d/m H:i', strtotime((string)$rec['proximo_contato_em'])) ?>
          </span>
          <?php endif; ?>
        </div>
      </div>

      <ol class="rec-trilho<?= $encerrou && !$ganhou ? ' perdeu' : '' ?>" style="--ate:<?= $etapaAtual ?>;">
        <?php foreach ($etapas as $i => $et): ?>
          <?php
            $feito  = $i < $etapaAtual || ($i === $etapaAtual && isset($marcos[$i]));
            $classe = $i === $etapaAtual ? 'agora' : ($feito ? 'feito' : 'futuro');
            if ($i === 4 && $ganhou) $classe .= ' venceu';
          ?>
        <li class="<?= $classe ?>">
          <span class="rec-no"><?= $i === 4 && $ganhou ? '🎉' : $et['ico'] ?></span>
          <span class="rec-rot"><?= $i === 4 && $encerrou ? $statusCfg[$rec['status']][0] : $et['rotulo'] ?></span>
          <?php /* Etapa futura não mostra data: um carrinho reaberto guarda o
                   marco antigo, e exibi-lo num passo que ainda não aconteceu
                   diz ao vendedor o contrário do que é verdade. */ ?>
          <span class="rec-quando"><?= $i <= $etapaAtual && isset($marcos[$i])
                ? date('d/m H:i', $marcos[$i]) : '—' ?></span>
        </li>
        <?php endforeach; ?>
      </ol>

      <div class="rec-acoes">
        <?php if (!$temDono): ?>
          <button class="btn rec-bt rec-bt-capturar" id="btn-capturar"><span>⚡</span> Capturar</button>
        <?php elseif (!$souODono): ?>
          <span class="rec-travado">🔒 Com <strong><?= View::e($rec['responsavel_nome']) ?></strong></span>
        <?php endif; ?>

        <button class="btn rec-bt rec-bt-wpp" id="btn-whatsapp"
                <?= !$rec['cliente_telefone'] ? 'disabled title="Cliente sem telefone"' : '' ?>>
          <span>💬</span> WhatsApp</button>

        <button class="btn rec-bt rec-bt-mail" id="btn-email"
                <?= !$rec['cliente_email'] ? 'disabled title="Cliente sem e-mail"' : '' ?>>
          <span>✉</span> E-mail</button>

        <button class="btn rec-bt" id="btn-link"><span>🔗</span> Copiar link</button>

        <span class="rec-sep"></span>

        <?php if ((int)($rec['tentativas_contato'] ?? 0) > 0): ?>
        <span class="rec-tentativas"><?= (int)$rec['tentativas_contato'] ?>ª tentativa</span>
        <?php endif; ?>

        <button class="btn rec-bt rec-bt-mais" id="btn-mais"><span>⋯</span> Mais ações</button>
      </div>
    </div>

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

    <!-- Ações secundárias: vivem aqui escondidas e o drawer as empresta.
         Ficarem no DOM desde o início é o que mantém os handlers ligados —
         conteúdo criado na abertura exigiria religar tudo toda vez. -->
    <div id="acoes-mais" hidden>
      <div style="display:flex;flex-direction:column;gap:14px;">

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

        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:12px;">📅 Agendar próximo contato</label>
          <input type="datetime-local" class="form-control" id="inp-agendar">
        </div>

        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:12px;">📝 Anotação interna</label>
          <textarea class="form-control" id="inp-anotacao" rows="4"
                    placeholder="O que aconteceu nesta conversa…" maxlength="1000"></textarea>
        </div>
        <button class="btn btn-primary" id="btn-anotar">Salvar anotação</button>
      </div>
    </div>
  </div>
</div>

<div id="acoes-guarda" style="display:none;"></div>

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

  /* ── Mais ações: o drawer empresta o bloco que já está no DOM ──
     Passar o elemento (e não HTML novo) é o que mantém os handlers abaixo
     ligados. O adminDrawer devolve o nó ao fechar. */
  $('#btn-mais').on('click', function () {
    var $bloco = $('#acoes-mais');
    $bloco.prop('hidden', false);

    var drawer = adminDrawer({
      titulo: 'Ações do carrinho',
      subtitulo: 'Status, responsável, agendamento e anotações',
      conteudo: $bloco,
      tamanho: 'md',
      onClose: function () {
        // De volta para o DOM da página, escondido, com os handlers intactos
        $bloco.prop('hidden', true).appendTo('#acoes-guarda');
      }
    });
  });

  /* Handlers delegados: os campos abaixo entram e saem do drawer, e um bind
     direto morreria na primeira movimentação do nó. */

  /* Status */
  $(document).on('change', '#sel-status', function () {
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

  $(document).on('click', '#btn-capturar', function () {
    post(BASE + '/capturar', {})
      .done(function (r) { r.ok ? reload() : alert(r.msg); });
  });

  /* Responsável */
  $(document).on('change', '#sel-responsavel', function () {
    var id = $(this).val();
    if (!id) return;
    post(BASE + '/responsavel', { responsavel_id: id })
      .done(function (r) { r.ok ? reload() : alert(r.msg); });
  });

  /* Agendamento */
  $(document).on('change', '#inp-agendar', function () {
    var quando = $(this).val();
    if (!quando) return;
    post(BASE + '/agendar', { quando: quando.replace('T', ' ') + ':00' })
      .done(function (r) { r.ok ? reload() : alert(r.msg); });
  });

  /* Anotação */
  $(document).on('click', '#btn-anotar', function () {
    var t = $('#inp-anotacao').val().trim();
    if (!t) return;
    post(BASE + '/anotacao', { texto: t })
      .done(function (r) { r.ok ? reload() : alert(r.msg); });
  });

  /* Link de recuperação */
  $(document).on('click', '#btn-link', function () {
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