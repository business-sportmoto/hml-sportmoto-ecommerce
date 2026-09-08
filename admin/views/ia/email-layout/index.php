<?php
/**
 * Gerador de layout de e-mail — Fase 1.
 * Variáveis: $modelos, $csrf
 *
 * Fluxo em dois passos: briefing → gerar → revisar → salvar como rascunho.
 * O preview roda em <iframe sandbox> justamente porque o HTML vem de um
 * modelo: mesmo saneado, ele não executa nada dentro do painel.
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
?>
<div class="ia_wrap">

  <header class="ia_head">
    <div>
      <h1 class="ia_titulo"><?= IconLibrary::render('mail', 'ia_ico', ['aria-hidden' => 'true']) ?> Layout de e-mail</h1>
      <p class="ia_sub">Descreva o layout; a IA monta o esqueleto HTML com os blocos de produto. Você revisa e salva como rascunho.</p>
    </div>
    <div class="ia_topo_acoes">
      <a class="ia_btn" href="<?= BASE_URL ?>/admin/email-marketing/templates"><?= IconLibrary::render('arrow-right', 'ia_ico', ['aria-hidden' => 'true']) ?> Templates de e-mail</a>
    </div>
  </header>

  <?php if ($modelos === []): ?>
    <div class="ia_aviso_seguro" style="margin-bottom:14px">
      <?= IconLibrary::render('alert-triangle', 'ia_ico', ['aria-hidden' => 'true']) ?>
      <span>Nenhum modelo de texto ativo na Central. Configure um provedor em
        <a href="<?= BASE_URL ?>/admin/ia/config">Configurações</a> antes de gerar.</span>
    </div>
  <?php endif; ?>

  <div class="ia_aviso_seguro" style="margin-bottom:14px">
    <?= IconLibrary::render('shield-check', 'ia_ico', ['aria-hidden' => 'true']) ?>
    <span><strong>O template nasce como rascunho.</strong> Antes de ativar,
      mande um envio de teste — o Outlook renderiza com o motor do Word, e é lá
      que layout de e-mail costuma quebrar.</span>
  </div>

  <!-- ── Passo 1: briefing ─────────────────────────────── -->
  <section class="ia_card ia_card_pad">
    <div class="ia_card_head" style="padding:0 0 var(--ia-e-2)">
      <h2 class="ia_card_titulo"><?= IconLibrary::render('pencil', 'ia_ico', ['aria-hidden' => 'true']) ?> O que você quer</h2>
      <span class="ia_hint">Quanto mais concreto, menos regeneração.</span>
    </div>

    <div class="ia_form_grupo">
      <label for="eml-briefing">Briefing do layout</label>
      <textarea id="eml-briefing" class="ia_input" rows="4"
        placeholder="Ex.: cabeçalho com a logo, faixa de destaque com o título da promoção, vitrine de 3 produtos em coluna única com foto, nome, preço e botão, e rodapé com redes sociais."></textarea>
      <span class="ia_ajuda">Mínimo de 15 caracteres. Descreva as seções, não o código.</span>
    </div>

    <div class="ia_form_linha">
      <div class="ia_form_grupo">
        <label for="eml-objetivo">Objetivo</label>
        <input type="text" id="eml-objetivo" class="ia_input" maxlength="200" placeholder="Ex.: liquidação de capacetes">
      </div>
      <div class="ia_form_grupo">
        <label for="eml-publico">Público</label>
        <input type="text" id="eml-publico" class="ia_input" maxlength="200" placeholder="Ex.: quem já comprou capacete">
      </div>
    </div>
    <div class="ia_form_linha">
      <div class="ia_form_grupo">
        <label for="eml-tom">Tom</label>
        <input type="text" id="eml-tom" class="ia_input" maxlength="200" placeholder="Ex.: direto e informal">
      </div>
      <div class="ia_form_grupo">
        <label for="eml-paleta">Paleta</label>
        <input type="text" id="eml-paleta" class="ia_input" maxlength="200" placeholder="Ex.: preto, laranja e branco">
      </div>
    </div>

    <div class="ia_form_rodape ia_form_rodape_split">
      <div class="ia_form_rodape_ctrl">
        <label for="eml-modelo" class="ia_grupo_rotulo" style="margin:0 0 4px">Gerar com</label>
        <select id="eml-modelo" class="ia_input" <?= $modelos === [] ? 'disabled' : '' ?>>
          <?php foreach ($modelos as $m): ?>
            <option value="<?= (int) $m['id'] ?>" <?= !empty($m['padrao']) ? 'selected' : '' ?>>
              <?= ia_e($m['rotulo']) ?><?= !empty($m['padrao']) ? ' — padrão' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ia_form_rodape_acoes">
        <button type="button" class="ia_btn ia_btn_primario" id="eml-gerar" <?= $modelos === [] ? 'disabled' : '' ?>>
          <?= IconLibrary::render('wand-stars', 'ia_ico', ['aria-hidden' => 'true']) ?> Gerar layout
        </button>
      </div>
    </div>
  </section>

  <!-- ── Passo 2: resultado ────────────────────────────── -->
  <section class="ia_card ia_card_pad" id="eml-passo-resultado" hidden>
    <div class="ia_card_head" style="padding:0 0 var(--ia-e-2)">
      <h2 class="ia_card_titulo"><?= IconLibrary::render('zoom-in', 'ia_ico', ['aria-hidden' => 'true']) ?> Revise antes de salvar</h2>
      <span class="ia_hint" id="eml-procedencia"></span>
    </div>

    <div id="eml-avisos"></div>

    <div class="ia_form_linha">
      <div class="ia_form_grupo">
        <label for="eml-nome">Nome do template</label>
        <input type="text" id="eml-nome" class="ia_input" maxlength="190" placeholder="Ex.: Promoção — vitrine 3 produtos">
      </div>
      <div class="ia_form_grupo">
        <label for="eml-assunto">Assunto</label>
        <input type="text" id="eml-assunto" class="ia_input" maxlength="255">
      </div>
    </div>
    <div class="ia_form_grupo">
      <label for="eml-preheader">Preheader</label>
      <input type="text" id="eml-preheader" class="ia_input" maxlength="190">
    </div>

    <div class="ia_form_grupo">
      <span class="ia_grupo_rotulo">Variáveis detectadas</span>
      <div id="eml-variaveis" class="ia_chips"></div>
      <span class="ia_ajuda">A campanha precisa fornecer estes valores no envio.</span>
    </div>

    <div class="ia_form_grupo" id="eml-notas-wrap" hidden>
      <span class="ia_grupo_rotulo">O que a IA assumiu</span>
      <p class="ia_ajuda" id="eml-notas" style="margin:0"></p>
    </div>

    <div class="ia_card_head" style="padding:var(--ia-e-2) 0 var(--ia-e-1)">
      <h3 class="ia_card_titulo" style="font-size:var(--ia-t-label)">Preview</h3>
      <div class="ia_topo_acoes">
        <button type="button" class="ia_btn eml_view eml_view_ativo" data-w="600">Desktop 600px</button>
        <button type="button" class="ia_btn eml_view" data-w="375">Mobile 375px</button>
      </div>
    </div>
    <div class="eml_palco">
      <iframe id="eml-preview" title="Prévia do e-mail" sandbox class="eml_frame"></iframe>
    </div>

    <div class="ia_form_rodape ia_form_rodape_split">
      <div class="ia_form_rodape_ctrl">
        <span class="ia_ajuda" id="eml-tamanho"></span>
      </div>
      <div class="ia_form_rodape_acoes">
        <button type="button" class="ia_btn" id="eml-regerar">
          <?= IconLibrary::render('refresh-cw', 'ia_ico', ['aria-hidden' => 'true']) ?> Regenerar
        </button>
        <button type="button" class="ia_btn ia_btn_primario" id="eml-salvar">
          <?= IconLibrary::render('check', 'ia_ico', ['aria-hidden' => 'true']) ?> Salvar como rascunho
        </button>
      </div>
    </div>
  </section>
</div>

<script>
(function () {
  'use strict';

  var CSRF  = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
  var BASE  = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>;
  var atual = null;   // último layout gerado

  function $(id) { return document.getElementById(id); }

  function briefingAtual() {
    return {
      briefing: $('eml-briefing').value,
      objetivo: $('eml-objetivo').value,
      publico:  $('eml-publico').value,
      tom:      $('eml-tom').value,
      paleta:   $('eml-paleta').value
    };
  }

  function chips(lista) {
    var alvo = $('eml-variaveis');
    alvo.textContent = '';
    if (!lista || !lista.length) {
      var v = document.createElement('span');
      v.className = 'ia_ajuda';
      v.textContent = 'Nenhuma — o layout ficou estático.';
      alvo.appendChild(v);
      return;
    }
    lista.forEach(function (nome) {
      var c = document.createElement('span');
      c.className = 'ia_chip';
      c.textContent = nome;          // textContent: o nome vem do modelo
      alvo.appendChild(c);
    });
  }

  function avisos(lista) {
    var alvo = $('eml-avisos');
    alvo.textContent = '';
    if (!lista || !lista.length) { return; }
    var box = document.createElement('div');
    box.className = 'ia_aviso_seguro';
    box.style.marginBottom = '14px';
    var t = document.createElement('span');
    t.textContent = 'O sanitizador removeu: ' + lista.join(' · ');
    box.appendChild(t);
    alvo.appendChild(box);
  }

  function mostrar(res) {
    atual = res;

    $('eml-assunto').value   = res.assunto || '';
    $('eml-preheader').value = res.preheader || '';
    if (!$('eml-nome').value) {
      $('eml-nome').value = (res.assunto || 'Layout gerado por IA').slice(0, 190);
    }

    chips(res.variaveis);
    avisos(res.avisos);

    var notas = (res.notas || '').trim();
    $('eml-notas-wrap').hidden = notas === '';
    $('eml-notas').textContent = notas;

    // srcdoc + sandbox: o HTML veio de um modelo. Saneado, mas ainda assim
    // não roda nada dentro do painel.
    $('eml-preview').srcdoc = res.html || '';

    var kb = ((res.bytes || 0) / 1024).toFixed(1).replace('.', ',');
    var ia = res._ia || {};
    $('eml-tamanho').textContent = kb + ' KB';
    $('eml-procedencia').textContent =
      (ia.rotulo || '') +
      (ia.custo_usd ? ' · US$ ' + Number(ia.custo_usd).toFixed(6) : '') +
      (ia.tempo_ms ? ' · ' + (ia.tempo_ms / 1000).toFixed(1) + 's' : '');

    if (ia.trocou) {
      adminToast('O modelo escolhido falhou; quem respondeu foi ' + (ia.rotulo || 'outro da cadeia') + '.', 'warning');
    }

    $('eml-passo-resultado').hidden = false;
    $('eml-passo-resultado').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function gerar(btn) {
    var dados = briefingAtual();
    if ((dados.briefing || '').trim().length < 15) {
      adminToast('Descreva o layout com um pouco mais de detalhe.', 'warning');
      return;
    }

    var rotuloOriginal = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = 'Gerando…';

    var corpo = new URLSearchParams();
    corpo.append('_csrf_token', CSRF);
    corpo.append('modelo_id', $('eml-modelo').value || '');
    Object.keys(dados).forEach(function (k) { corpo.append(k, dados[k]); });

    fetch(BASE + '/admin/ia/email-layout/gerar', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: corpo.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) { adminToast(res.msg || 'Falha ao gerar.', 'error'); return; }
        mostrar(res);
      })
      .catch(function () { adminToast('Falha de comunicação com o servidor.', 'error'); })
      .finally(function () { btn.disabled = false; btn.innerHTML = rotuloOriginal; });
  }

  $('eml-gerar').addEventListener('click', function () { gerar(this); });
  $('eml-regerar').addEventListener('click', function () { gerar($('eml-gerar')); });

  $('eml-salvar').addEventListener('click', function () {
    if (!atual) { return; }
    var btn = this;
    btn.disabled = true;

    var corpo = new URLSearchParams();
    corpo.append('_csrf_token', CSRF);
    corpo.append('nome',       $('eml-nome').value);
    corpo.append('assunto',    $('eml-assunto').value);
    corpo.append('preheader',  $('eml-preheader').value);
    corpo.append('html',       atual.html || '');
    corpo.append('geracao_id', (atual._ia && atual._ia.geracao_id) || 0);
    corpo.append('briefing',   $('eml-briefing').value);

    fetch(BASE + '/admin/ia/email-layout/salvar', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: corpo.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) { adminToast(res.msg || 'Não foi possível salvar.', 'error'); return; }
        adminToast('Template criado como rascunho. Faça um envio de teste antes de ativar.', 'success');
        if (res.url) { window.location.href = res.url; }
      })
      .catch(function () { adminToast('Falha de comunicação com o servidor.', 'error'); })
      .finally(function () { btn.disabled = false; });
  });

  Array.prototype.forEach.call(document.querySelectorAll('.eml_view'), function (b) {
    b.addEventListener('click', function () {
      Array.prototype.forEach.call(document.querySelectorAll('.eml_view'), function (o) {
        o.classList.remove('eml_view_ativo');
      });
      b.classList.add('eml_view_ativo');
      $('eml-preview').style.width = b.dataset.w + 'px';
    });
  });
})();
</script>
