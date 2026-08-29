<?php
// admin/views/pagamentos/formas.php
// $metodos e $simulacoes injetados pelo AdminPagamentoConfigController
//
// Mesma escolha da tela de adquirentes: card para ler, drawer para editar.
// Aqui pesa ainda mais, porque a informação que o lojista busca de relance é
// comparativa — quanto de desconto cada forma dá, em quantas vezes parcela —
// e formulários empilhados escondem justamente essa comparação.

/** Centavos → "1.234,56" para os inputs de dinheiro. */
$dinheiro = static fn(?int $c): string => $c === null ? '' : number_format($c / 100, 2, ',', '.');

/** Rótulo visual por método. O ícone vem do IconLibrary quando existe. */
$rotulos = [
    'pix'            => ['nome' => 'Pix',    'cor' => '#0f766e', 'bg' => '#f0fdfa'],
    'cartao_credito' => ['nome' => 'Cartão', 'cor' => '#1d4ed8', 'bg' => '#eff6ff'],
    'boleto'         => ['nome' => 'Boleto', 'cor' => '#92400e', 'bg' => '#fffbeb'],
];
?>

<div class="admin-page fp-page">

  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/configuracoes" class="back-link">← Configurações</a>
      <h1 class="admin-page-title">Formas de pagamento</h1>
      <p class="admin-page-sub">
        Política comercial da loja: taxa, desconto e parcelamento. Vale para qualquer
        adquirente que processar — quem processa é definido no fluxo de pagamento.
      </p>
    </div>
    <a href="<?= ADMIN_URL ?>/pagamentos/adquirentes" class="btn btn-outline">Adquirentes →</a>
  </div>

  <div class="fp-legenda">
    <span><strong>Taxa</strong> juros repassados nas parcelas com juros</span>
    <span><strong>Desconto</strong> abatimento por escolher esta forma</span>
    <span><strong>Teto</strong> limite somando desconto do método + cupom</span>
    <span><strong>Parcela mínima</strong> abaixo disso a opção não é oferecida</span>
  </div>

  <div class="fp-grid">
    <?php foreach ($metodos as $m):
        $r        = $rotulos[$m['codigo']] ?? ['nome' => $m['nome'], 'cor' => '#475569', 'bg' => '#f8fafc'];
        $ehCartao = str_starts_with($m['codigo'], 'cartao');
        $desconto = (float) $m['desconto_percentual'];
    ?>
    <article class="fp-card <?= $m['ativo'] ? 'is-ativa' : '' ?>" data-codigo="<?= View::e($m['codigo']) ?>">

      <header class="fp-card-head">
        <span class="fp-selo" style="background:<?= $r['bg'] ?>;color:<?= $r['cor'] ?>;">
          <?= View::e($r['nome']) ?>
        </span>
        <div class="fp-ident">
          <h3><?= View::e($m['nome']) ?></h3>
          <code><?= View::e($m['codigo']) ?></code>
        </div>
        <label class="fp-switch" title="<?= $m['ativo'] ? 'Desativar no checkout' : 'Ativar no checkout' ?>">
          <input type="checkbox" class="fp-toggle" <?= $m['ativo'] ? 'checked' : '' ?>
                 data-codigo="<?= View::e($m['codigo']) ?>">
          <span class="fp-switch-trilho"><span class="fp-switch-bola"></span></span>
        </label>
      </header>

      <div class="fp-tags">
        <?php if ($m['ativo']): ?>
          <span class="fp-tag fp-tag--ok">No checkout</span>
        <?php else: ?>
          <span class="fp-tag fp-tag--neutra">Oculta</span>
        <?php endif; ?>

        <?php if ($desconto > 0): ?>
          <span class="fp-tag fp-tag--desconto"><?= number_format($desconto, 2, ',', '') ?>% off</span>
        <?php endif; ?>

        <?php if ($ehCartao): ?>
          <span class="fp-tag fp-tag--info">até <?= (int) $m['parcelas_max'] ?>x</span>
          <span class="fp-tag fp-tag--ok"><?= (int) $m['parcelas_sem_juros'] ?>x sem juros</span>
        <?php endif; ?>
      </div>

      <!-- Os números que respondem "quanto isto custa" sem abrir nada. -->
      <dl class="fp-resumo">
        <div><dt>Valor mínimo</dt><dd>R$ <?= $dinheiro((int) $m['valor_min_centavos']) ?: '0,00' ?></dd></div>
        <div><dt>Valor máximo</dt>
          <dd><?= $m['valor_max_centavos'] !== null ? 'R$ ' . $dinheiro((int) $m['valor_max_centavos']) : 'sem limite' ?></dd></div>
        <?php if ($ehCartao): ?>
        <div><dt>Taxa/parcela</dt><dd><?= number_format((float) $m['taxa_percentual'], 2, ',', '') ?>%</dd></div>
        <div><dt>Parcela mínima</dt><dd>R$ <?= $dinheiro((int) $m['parcela_min_centavos']) ?: '0,00' ?></dd></div>
        <?php endif; ?>
      </dl>

      <?php if ($ehCartao && !empty($simulacoes[$m['codigo']])): ?>
      <div class="fp-parcelas">
        <span class="fp-parcelas-rot">R$ 500,00 fica</span>
        <?php foreach (array_slice($simulacoes[$m['codigo']], 0, 4) as $p): ?>
          <span class="fp-parcela <?= $p['com_juros'] ? 'com-juros' : '' ?>">
            <?= (int) $p['parcela'] ?>x <?= number_format($p['valor_parcela'] / 100, 2, ',', '.') ?>
          </span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <footer class="fp-card-pe">
        <button type="button" class="btn btn-primary btn-sm fp-editar"
                data-codigo="<?= View::e($m['codigo']) ?>">Editar regras</button>
      </footer>

      <template class="fp-form-tpl">
        <form class="form-metodo" data-codigo="<?= View::e($m['codigo']) ?>">
          <?= SecurityHelper::csrfField() ?>
          <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
          <input type="hidden" name="ativo" value="<?= $m['ativo'] ? '1' : '0' ?>">

          <div class="form-group">
            <label>Nome exibido no checkout</label>
            <input type="text" name="nome" class="form-control" value="<?= View::e($m['nome']) ?>">
          </div>

          <h4 class="fp-secao">Desconto</h4>
          <div class="fp-grid-2">
            <div class="form-group">
              <label>Desconto (%)</label>
              <input type="text" name="desconto_percentual" class="form-control"
                     value="<?= number_format($desconto, 2, ',', '') ?>">
            </div>
            <div class="form-group">
              <label>Teto de desconto (%)</label>
              <input type="text" name="desconto_max_percent" class="form-control"
                     value="<?= number_format((float) $m['desconto_max_percent'], 2, ',', '') ?>">
              <small class="form-help">Limite somando com cupom.</small>
            </div>
          </div>

          <h4 class="fp-secao">Faixa de valor</h4>
          <div class="fp-grid-2">
            <div class="form-group">
              <label>Valor mínimo (R$)</label>
              <input type="text" name="valor_min" class="form-control money"
                     value="<?= $dinheiro((int) $m['valor_min_centavos']) ?>">
            </div>
            <div class="form-group">
              <label>Valor máximo (R$)</label>
              <input type="text" name="valor_max" class="form-control money" placeholder="sem limite"
                     value="<?= $m['valor_max_centavos'] !== null ? $dinheiro((int) $m['valor_max_centavos']) : '' ?>">
            </div>
          </div>

          <?php if ($ehCartao): ?>
          <h4 class="fp-secao">Parcelamento</h4>
          <div class="fp-grid-2">
            <div class="form-group">
              <label>Parcelamento máximo</label>
              <input type="number" name="parcelas_max" class="form-control" min="1" max="24"
                     value="<?= (int) $m['parcelas_max'] ?>">
            </div>
            <div class="form-group">
              <label>Parcelas sem juros</label>
              <input type="number" name="parcelas_sem_juros" class="form-control" min="1" max="24"
                     value="<?= (int) $m['parcelas_sem_juros'] ?>">
            </div>
            <div class="form-group">
              <label>Taxa por parcela (%)</label>
              <input type="text" name="taxa_percentual" class="form-control"
                     value="<?= number_format((float) $m['taxa_percentual'], 2, ',', '') ?>">
            </div>
            <div class="form-group">
              <label>Parcela mínima (R$)</label>
              <input type="text" name="parcela_min" class="form-control money"
                     value="<?= $dinheiro((int) $m['parcela_min_centavos']) ?>">
            </div>
          </div>

          <!-- Simulação ao vivo: o lojista vê o efeito da regra antes de
               salvar, em vez de descobrir no checkout que a 12ª parcela
               ficou abaixo do mínimo e a opção sumiu. -->
          <div class="fp-simulacao">
            <div class="fp-simulacao-cab">
              Simulação para R$ 500,00
              <small>atualiza enquanto você digita</small>
            </div>
            <div class="simulacao-linhas">
              <?php foreach (($simulacoes[$m['codigo']] ?? []) as $p): ?>
                <span class="fp-parcela <?= $p['com_juros'] ? 'com-juros' : '' ?>">
                  <?= (int) $p['parcela'] ?>x <?= number_format($p['valor_parcela'] / 100, 2, ',', '.') ?>
                </span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php else: ?>
          <input type="hidden" name="parcelas_max" value="1">
          <input type="hidden" name="parcelas_sem_juros" value="1">
          <input type="hidden" name="taxa_percentual" value="0">
          <input type="hidden" name="parcela_min" value="0">
          <?php endif; ?>
        </form>
      </template>
    </article>
    <?php endforeach; ?>
  </div>

  <?php if (!$metodos): ?>
  <div class="admin-card fp-vazio">
    Nenhuma forma de pagamento cadastrada. Rode <code>migration-pagamentos.sql</code>.
  </div>
  <?php endif; ?>

</div>

<style>
/* ── Tema ──────────────────────────────────────────────────────
   As cores moram em variaveis e sao trocadas em bloco no escuro. Suporta os
   dois caminhos: a preferencia do sistema e o atributo data-theme, que o
   painel ja declara como gancho para um interruptor futuro. */
.fp-page{
  --fp-sup:#fff;        --fp-sup2:#f8fafc;
  --fp-bd:#e6e9ef;      --fp-bd2:#cfd6e0;
  --fp-tx:#0f172a;      --fp-tx2:#64748b;
  --fp-sombra:0 6px 20px -8px rgba(15,23,42,.18);
  --fp-juros-bg:#fffbeb; --fp-juros-bd:#fde68a; --fp-juros-tx:#92400e;
}
@media (prefers-color-scheme: dark){
  html:not([data-theme="light"]) .fp-page{
    --fp-sup:#1c1c1e;   --fp-sup2:#161618;
    --fp-bd:#2c2c2e;    --fp-bd2:#3a3a3d;
    --fp-tx:#f4f4f5;    --fp-tx2:#a1a1aa;
    --fp-sombra:0 8px 24px rgba(0,0,0,.55);
    --fp-juros-bg:#2a2013; --fp-juros-bd:#4a3410; --fp-juros-tx:#fcd34d;
  }
}
html[data-theme="dark"] .fp-page{
  --fp-sup:#1c1c1e;   --fp-sup2:#161618;
  --fp-bd:#2c2c2e;    --fp-bd2:#3a3a3d;
  --fp-tx:#f4f4f5;    --fp-tx2:#a1a1aa;
  --fp-sombra:0 8px 24px rgba(0,0,0,.55);
  --fp-juros-bg:#2a2013; --fp-juros-bd:#4a3410; --fp-juros-tx:#fcd34d;
}

.fp-legenda{display:flex;flex-wrap:wrap;gap:8px 22px;font-size:12px;color:var(--fp-tx2);
  background:var(--fp-sup2);border:1px solid var(--fp-bd);border-radius:10px;padding:12px 16px;margin-bottom:18px}
.fp-legenda strong{color:var(--fp-tx);font-weight:650}

.fp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px}

.fp-card{background:var(--fp-sup);border:1px solid var(--fp-bd);border-radius:14px;padding:18px;
  display:flex;flex-direction:column;gap:12px;transition:border-color .15s,box-shadow .15s}
.fp-card:hover{border-color:var(--fp-bd2);box-shadow:var(--fp-sombra)}
.fp-card.is-ativa{border-left:3px solid #16a34a}

.fp-card-head{display:flex;align-items:center;gap:12px}
.fp-selo{font-size:12px;font-weight:700;padding:6px 11px;border-radius:8px;flex:0 0 auto}
.fp-ident{flex:1;min-width:0}
.fp-ident h3{margin:0;font-size:15px;font-weight:650;color:var(--fp-tx);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fp-ident code{font-size:11px;color:var(--fp-tx2)}

.fp-switch{flex:0 0 auto;cursor:pointer;display:inline-flex}
.fp-switch input{position:absolute;opacity:0;width:0;height:0}
.fp-switch-trilho{width:40px;height:23px;border-radius:99px;background:#cbd5e1;
  display:inline-block;position:relative;transition:background .18s}
.fp-switch-bola{position:absolute;top:3px;left:3px;width:17px;height:17px;border-radius:50%;
  background:#fff;transition:transform .18s;box-shadow:0 1px 3px rgba(0,0,0,.25)}
.fp-switch input:checked + .fp-switch-trilho{background:#16a34a}
.fp-switch input:checked + .fp-switch-trilho .fp-switch-bola{transform:translateX(17px)}
.fp-switch input:disabled + .fp-switch-trilho{opacity:.5;cursor:wait}
.fp-switch input:focus-visible + .fp-switch-trilho{outline:2px solid #2563eb;outline-offset:2px}

.fp-tags{display:flex;flex-wrap:wrap;gap:6px}
.fp-tag{font-size:10.5px;font-weight:700;letter-spacing:.02em;padding:3px 9px;border-radius:20px;
  text-transform:uppercase}
.fp-tag--ok{background:#f0fdf4;color:#15803d}
.fp-tag--neutra{background:#f1f5f9;color:#64748b}
.fp-tag--info{background:#eff6ff;color:#1d4ed8}
.fp-tag--desconto{background:#fdf4ff;color:#a21caf}

.fp-resumo{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;margin:0;
  padding:12px 0;border-top:1px solid var(--fp-bd);border-bottom:1px solid var(--fp-bd)}
.fp-resumo div{min-width:0}
.fp-resumo dt{font-size:11px;color:var(--fp-tx2);margin:0}
.fp-resumo dd{margin:1px 0 0;font-size:13px;font-weight:600;color:var(--fp-tx);
  font-variant-numeric:tabular-nums}

.fp-parcelas{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
.fp-parcelas-rot{font-size:11px;color:var(--fp-tx2)}
.fp-parcela{font-size:11.5px;padding:4px 9px;border-radius:6px;background:var(--fp-sup2);
  border:1px solid var(--fp-bd);color:var(--fp-tx);white-space:nowrap;
  font-variant-numeric:tabular-nums}
.fp-parcela.com-juros{background:var(--fp-juros-bg);border-color:var(--fp-juros-bd);color:var(--fp-juros-tx)}

.fp-card-pe{margin-top:auto;padding-top:4px}
.fp-card-pe .btn{width:100%}
.btn-sm{padding:7px 12px;font-size:12.5px}

.fp-vazio{padding:40px;text-align:center;color:var(--fp-tx2)}

/* ── dentro do drawer ── */
.fp-secao{margin:22px 0 10px;font-size:11px;font-weight:700;text-transform:uppercase;
  letter-spacing:.06em;color:var(--fp-tx2);
  padding-bottom:7px;border-bottom:1px solid var(--fp-bd)}
.fp-secao:first-of-type{margin-top:14px}
.fp-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:520px){.fp-grid-2,.fp-resumo{grid-template-columns:1fr}}
.fp-simulacao{margin-top:16px;padding:14px;background:var(--fp-sup2);border-radius:10px;
  border:1px solid var(--fp-bd)}
.fp-simulacao-cab{font-size:12px;font-weight:700;color:var(--fp-tx);margin-bottom:10px}
.fp-simulacao-cab small{font-weight:400;color:var(--fp-tx2)}
.simulacao-linhas{display:flex;flex-wrap:wrap;gap:6px}

/* Tags e selos no escuro. */
@media (prefers-color-scheme: dark){
  html:not([data-theme="light"]) .fp-tag--ok{background:#0f2a18;color:#4ade80}
  html:not([data-theme="light"]) .fp-tag--neutra{background:#232326;color:#a1a1aa}
  html:not([data-theme="light"]) .fp-tag--info{background:#12203a;color:#7dabf8}
  html:not([data-theme="light"]) .fp-tag--desconto{background:#2a1430;color:#e879f9}
  html:not([data-theme="light"]) .fp-selo{background:#232326 !important;color:#e4e4e7 !important}
}
html[data-theme="dark"] .fp-tag--ok{background:#0f2a18;color:#4ade80}
html[data-theme="dark"] .fp-tag--neutra{background:#232326;color:#a1a1aa}
html[data-theme="dark"] .fp-tag--info{background:#12203a;color:#7dabf8}
html[data-theme="dark"] .fp-tag--desconto{background:#2a1430;color:#e879f9}
html[data-theme="dark"] .fp-selo{background:#232326 !important;color:#e4e4e7 !important}
</style>

<script>
(function () {
  'use strict';
  var BASE = '<?= ADMIN_URL ?>';

  function post(url, dados) {
    return fetch(BASE + url, {
      method: 'POST', body: dados, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); });
  }

  function aviso(ok, msg) {
    if (window.Toast) { ok ? Toast.success(msg) : Toast.error(msg); }
    else { alert(msg); }
  }

  function card(codigo) {
    return document.querySelector('.fp-card[data-codigo="' + codigo + '"]');
  }



  /** Renderiza as parcelas simuladas no destino informado. */
  function pintarParcelas(alvo, parcelas) {
    alvo.innerHTML = parcelas.map(function (p) {
      var reais = (p.valor_parcela / 100).toFixed(2).replace('.', ',');
      return '<span class="fp-parcela' + (p.com_juros ? ' com-juros' : '') + '">'
           + p.parcela + 'x ' + reais + '</span>';
    }).join('');
  }

  // ── Ativar / desativar no card ─────────────────────────────────────
  // Reusa o mesmo endpoint de salvar: o metodo inteiro vai junto, entao
  // alternar aqui nao perde as regras comerciais que estao no template.
  document.querySelectorAll('.fp-toggle').forEach(function (sw) {
    sw.addEventListener('change', function () {
      var codigo = sw.dataset.codigo;
      var tpl    = card(codigo).querySelector('.fp-form-tpl');
      var fd     = new FormData(tpl.content.querySelector('form'));

      fd.set('ativo', sw.checked ? '1' : '0');
      sw.disabled = true;

      post('/pagamentos/formas/salvar', fd).then(function (res) {
        sw.disabled = false;
        aviso(res.ok, res.msg || 'Não foi possível salvar.');
        if (res.ok) location.reload();
        else sw.checked = !sw.checked;
      }).catch(function () {
        sw.disabled = false;
        sw.checked = !sw.checked;
        aviso(false, 'Erro de conexão.');
      });
    });
  });

  // ── Editar regras no drawer ────────────────────────────────────────
  document.querySelectorAll('.fp-editar').forEach(function (b) {
    b.addEventListener('click', function () {
      var codigo = b.dataset.codigo;
      var el     = card(codigo);
      var tpl    = el.querySelector('.fp-form-tpl');

      var drawer = adminDrawer({
        titulo:    el.querySelector('.fp-ident h3').textContent,
        subtitulo: 'Regras comerciais · ' + codigo,
        tamanho:   'md',
        conteudo:  tpl.content.cloneNode(true),
        acoes:     '<button type="button" class="btn btn-primary btn-sm" data-acao="salvar">Salvar</button>'
      });

      // Escopado ao corpo deste drawer: procurar no documento inteiro
      // acharia o formulario de um drawer anterior que ainda nao saiu do DOM.
      var form = drawer.corpo().querySelector('.form-metodo');
      var alvo = form ? form.querySelector('.simulacao-linhas') : null;

      // ── Simulação ao vivo ────────────────────────────────────────
      if (alvo) {
        var recalcular = function () {
          var fd = new FormData();
          fd.append('_csrf_token', form.querySelector('[name="_csrf_token"]').value);
          fd.append('valor', '500,00');
          ['parcelas_max', 'parcelas_sem_juros', 'taxa_percentual', 'parcela_min']
            .forEach(function (n) {
              var i = form.querySelector('[name="' + n + '"]');
              if (i) fd.append(n, i.value);
            });

          post('/pagamentos/formas/simular', fd).then(function (res) {
            if (res.ok) pintarParcelas(alvo, res.parcelas);
          }).catch(function () { /* simulação é conveniência: falha calada */ });
        };

        ['parcelas_max', 'parcelas_sem_juros', 'taxa_percentual', 'parcela_min']
          .forEach(function (n) {
            var i = form.querySelector('[name="' + n + '"]');
            if (i) i.addEventListener('input', recalcular);
          });
      }

      drawer.escutar('click', '[data-acao="salvar"]', function (e) {
        var btn = e.currentTarget;
        btn.disabled = true;

        post('/pagamentos/formas/salvar', new FormData(form)).then(function (res) {
          btn.disabled = false;
          aviso(res.ok, res.msg || 'Não foi possível salvar.');
          if (res.ok) location.reload();
        }).catch(function () {
          btn.disabled = false;
          aviso(false, 'Erro de conexão.');
        });
      });
    });
  });
})();
</script>
