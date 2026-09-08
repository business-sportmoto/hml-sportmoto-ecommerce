<?php
/**
 * Conteúdo de e-mail por segmento — Fase 2.
 * Variáveis: $esqueletos, $filtros, $modelos, $csrf
 *
 * Três passos: base + produtos → gerar → revisar e salvar.
 * O HTML nunca sai daqui de volta: o servidor remonta no salvar.
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
$comVitrine = array_values(array_filter($esqueletos, fn ($e) => !empty($e['vitrine'])));
?>
<div class="ia_wrap">

  <header class="ia_head">
    <div>
      <h1 class="ia_titulo"><?= IconLibrary::render('campaign', 'ia_ico', ['aria-hidden' => 'true']) ?> Conteúdo de e-mail</h1>
      <p class="ia_sub">Escolhe os produtos, a IA escreve a copy, e sai um template de campanha pronto para revisar.</p>
    </div>
    <div class="ia_topo_acoes">
      <a class="ia_btn" href="<?= BASE_URL ?>/admin/ia/email-layout"><?= IconLibrary::render('mail', 'ia_ico', ['aria-hidden' => 'true']) ?> Criar um layout</a>
    </div>
  </header>

  <?php if ($comVitrine === []): ?>
    <div class="ia_aviso_seguro" style="margin-bottom:14px">
      <?= IconLibrary::render('alert-triangle', 'ia_ico', ['aria-hidden' => 'true']) ?>
      <span>Nenhum layout com vitrine de produtos ainda. Crie um em
        <a href="<?= BASE_URL ?>/admin/ia/email-layout">Layout de e-mail</a> — ele
        precisa ter o bloco <code>{{#produtos}}</code>.</span>
    </div>
  <?php endif; ?>

  <div class="ia_aviso_seguro" style="margin-bottom:14px">
    <?= IconLibrary::render('shield-check', 'ia_ico', ['aria-hidden' => 'true']) ?>
    <span><strong>Os preços entram congelados.</strong> O cliente recebe o preço do
      momento em que você montou a campanha — que é o preço anunciado. Só entra
      produto ativo e com estoque.</span>
  </div>

  <!-- ── Passo 1 ─────────────────────────────────────── -->
  <section class="ia_card ia_card_pad">
    <div class="ia_card_head" style="padding:0 0 var(--ia-e-2)">
      <h2 class="ia_card_titulo"><?= IconLibrary::render('stacks', 'ia_ico', ['aria-hidden' => 'true']) ?> Base e vitrine</h2>
      <span class="ia_hint">Quais produtos é consulta, não IA.</span>
    </div>

    <div class="ia_form_grupo">
      <label for="ec-template">Layout de base</label>
      <select id="ec-template" class="ia_input">
        <option value="">— escolha —</option>
        <?php foreach ($comVitrine as $e): ?>
          <option value="<?= (int) $e['id'] ?>"><?= ia_e($e['nome']) ?> (<?= ia_e($e['status']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <span class="ia_ajuda">Só aparecem layouts com bloco de vitrine.</span>
    </div>

    <div class="ia_form_linha">
      <div class="ia_form_grupo">
        <label for="ec-modo">Como escolher os produtos</label>
        <select id="ec-modo" class="ia_input">
          <option value="todos">Mais vendidos</option>
          <option value="aleatorios">Aleatórios</option>
          <option value="categoria">Por categoria</option>
          <option value="marca">Por marca</option>
          <option value="escolhidos">Escolher na mão</option>
        </select>
      </div>
      <div class="ia_form_grupo">
        <label for="ec-limite">Quantos na vitrine</label>
        <input type="number" id="ec-limite" class="ia_input" value="3" min="1" max="12">
      </div>
    </div>

    <div class="ia_form_grupo" id="ec-filtro-wrap" hidden>
      <label for="ec-filtro">Filtro</label>
      <select id="ec-filtro" class="ia_input" multiple size="5"></select>
      <span class="ia_ajuda">Segure Ctrl para escolher mais de um.</span>
    </div>

    <div class="ia_form_grupo" id="ec-ids-wrap" hidden>
      <label for="ec-ids">IDs dos produtos</label>
      <input type="text" id="ec-ids" class="ia_input" placeholder="Ex.: 7, 9, 14">
      <span class="ia_ajuda">A ordem importa — a primeira posição é a que mais converte.</span>
    </div>

    <div class="ia_form_grupo">
      <span class="ia_grupo_rotulo">Prévia da vitrine</span>
      <div id="ec-produtos" class="ia_chips"><span class="ia_ajuda">Clique em “Ver produtos”.</span></div>
    </div>

    <div class="ia_form_grupo">
      <label for="ec-briefing">Briefing da campanha</label>
      <textarea id="ec-briefing" class="ia_input" rows="3"
        placeholder="Ex.: liquidação de fim de estação, foco em quem já comprou capacete, destacar o selo do Inmetro."></textarea>
    </div>

    <div class="ia_form_linha">
      <div class="ia_form_grupo">
        <label for="ec-objetivo">Objetivo</label>
        <input type="text" id="ec-objetivo" class="ia_input" maxlength="200">
      </div>
      <div class="ia_form_grupo">
        <label for="ec-publico">Público</label>
        <input type="text" id="ec-publico" class="ia_input" maxlength="200">
      </div>
    </div>

    <div class="ia_form_rodape ia_form_rodape_split">
      <div class="ia_form_rodape_ctrl">
        <label for="ec-modelo" class="ia_grupo_rotulo" style="margin:0 0 4px">Escrever com</label>
        <select id="ec-modelo" class="ia_input" <?= $modelos === [] ? 'disabled' : '' ?>>
          <?php foreach ($modelos as $m): ?>
            <option value="<?= (int) $m['id'] ?>" <?= !empty($m['padrao']) ? 'selected' : '' ?>>
              <?= ia_e($m['rotulo']) ?><?= !empty($m['padrao']) ? ' — padrão' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ia_form_rodape_acoes">
        <button type="button" class="ia_btn" id="ec-ver">
          <?= IconLibrary::render('search', 'ia_ico', ['aria-hidden' => 'true']) ?> Ver produtos
        </button>
        <button type="button" class="ia_btn ia_btn_primario" id="ec-gerar">
          <?= IconLibrary::render('wand-stars', 'ia_ico', ['aria-hidden' => 'true']) ?> Montar campanha
        </button>
      </div>
    </div>
  </section>

  <!-- ── Passo 2 ─────────────────────────────────────── -->
  <section class="ia_card ia_card_pad" id="ec-resultado" hidden>
    <div class="ia_card_head" style="padding:0 0 var(--ia-e-2)">
      <h2 class="ia_card_titulo"><?= IconLibrary::render('zoom-in', 'ia_ico', ['aria-hidden' => 'true']) ?> Revise e ajuste</h2>
      <span class="ia_hint" id="ec-procedencia"></span>
    </div>

    <div class="ia_form_linha">
      <div class="ia_form_grupo">
        <label for="ec-nome">Nome da campanha</label>
        <input type="text" id="ec-nome" class="ia_input" maxlength="190">
      </div>
      <div class="ia_form_grupo">
        <label for="ec-assunto">Assunto</label>
        <input type="text" id="ec-assunto" class="ia_input" maxlength="255">
      </div>
    </div>
    <div class="ia_form_grupo">
      <label for="ec-preheader">Preheader</label>
      <input type="text" id="ec-preheader" class="ia_input" maxlength="190">
    </div>

    <div class="ia_form_grupo">
      <span class="ia_grupo_rotulo">Textos — edite à vontade e veja mudar</span>
      <div id="ec-valores"></div>
    </div>

    <div class="ia_form_grupo" id="ec-notas-wrap" hidden>
      <span class="ia_grupo_rotulo">O que a IA assumiu</span>
      <p class="ia_ajuda" id="ec-notas" style="margin:0"></p>
    </div>

    <div class="ia_card_head" style="padding:var(--ia-e-2) 0 var(--ia-e-1)">
      <h3 class="ia_card_titulo" style="font-size:var(--ia-t-label)">Prévia</h3>
      <div class="ia_topo_acoes">
        <button type="button" class="ia_btn eml_view eml_view_ativo" data-w="600">Desktop</button>
        <button type="button" class="ia_btn eml_view" data-w="375">Mobile</button>
      </div>
    </div>
    <div class="eml_palco">
      <iframe id="ec-preview" title="Prévia da campanha" sandbox class="eml_frame"></iframe>
    </div>

    <div class="ia_form_rodape ia_form_rodape_split">
      <div class="ia_form_rodape_ctrl"><span class="ia_ajuda" id="ec-tamanho"></span></div>
      <div class="ia_form_rodape_acoes">
        <button type="button" class="ia_btn" id="ec-regerar">
          <?= IconLibrary::render('refresh-cw', 'ia_ico', ['aria-hidden' => 'true']) ?> Regerar copy
        </button>
        <button type="button" class="ia_btn ia_btn_primario" id="ec-salvar">
          <?= IconLibrary::render('check', 'ia_ico', ['aria-hidden' => 'true']) ?> Salvar campanha
        </button>
      </div>
    </div>
  </section>
</div>

<script>
(function () {
  'use strict';

  var CSRF = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
  var BASE = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>;
  var FILTROS = <?= json_encode($filtros, JSON_UNESCAPED_UNICODE) ?>;
  var atual = null;

  function $(id) { return document.getElementById(id); }

  function criterio() {
    var modo = $('ec-modo').value;
    var ids = [];
    if (modo === 'categoria' || modo === 'marca') {
      ids = Array.prototype.map.call($('ec-filtro').selectedOptions, function (o) { return o.value; });
    } else if (modo === 'escolhidos') {
      ids = ($('ec-ids').value || '').split(/[^0-9]+/).filter(Boolean);
    }
    return { modo: modo, limite: $('ec-limite').value || 3, ids: ids };
  }

  function corpoBase() {
    var c = corpo();
    var cr = criterio();
    c.append('modo', cr.modo);
    c.append('limite', cr.limite);
    cr.ids.forEach(function (i) { c.append('ids[]', i); });
    return c;
  }

  function corpo() {
    var c = new URLSearchParams();
    c.append('_csrf_token', CSRF);
    return c;
  }

  function post(url, body) {
    return fetch(BASE + url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  // Filtro muda conforme o modo
  $('ec-modo').addEventListener('change', function () {
    var modo = this.value;
    var ehLista = (modo === 'categoria' || modo === 'marca');
    $('ec-filtro-wrap').hidden = !ehLista;
    $('ec-ids-wrap').hidden    = (modo !== 'escolhidos');
    if (!ehLista) { return; }

    var lista = (modo === 'categoria' ? FILTROS.categorias : FILTROS.marcas) || [];
    var sel = $('ec-filtro');
    sel.textContent = '';
    lista.forEach(function (item) {
      var o = document.createElement('option');
      o.value = item.id;
      o.textContent = item.nome + ' (' + item.n + ')';   // textContent: nome vem do banco
      sel.appendChild(o);
    });
  });

  function mostrarProdutos(lista) {
    var alvo = $('ec-produtos');
    alvo.textContent = '';
    if (!lista.length) {
      var v = document.createElement('span');
      v.className = 'ia_ajuda';
      v.textContent = 'Nenhum produto vendável casou com esse critério.';
      alvo.appendChild(v);
      return;
    }
    lista.forEach(function (p) {
      var c = document.createElement('span');
      c.className = 'ia_chip';
      c.textContent = p.nome + ' · ' + p.preco;
      alvo.appendChild(c);
    });
  }

  $('ec-ver').addEventListener('click', function () {
    var btn = this; btn.disabled = true;
    post('/admin/ia/email-conteudo/produtos', corpoBase())
      .then(function (r) {
        if (!r.ok) { adminToast(r.msg || 'Falha.', 'error'); return; }
        mostrarProdutos(r.produtos || []);
      })
      .catch(function () { adminToast('Falha de comunicação.', 'error'); })
      .finally(function () { btn.disabled = false; });
  });

  function camposDeTexto(valores, classes) {
    var alvo = $('ec-valores');
    alvo.textContent = '';
    var copy = (classes && classes.copy) || [];
    if (!copy.length) {
      var v = document.createElement('span');
      v.className = 'ia_ajuda';
      v.textContent = 'Este layout não tem texto editável — é uma vitrine pura.';
      alvo.appendChild(v);
      return;
    }
    copy.forEach(function (nome) {
      var grupo = document.createElement('div');
      grupo.className = 'ia_form_grupo';
      var lb = document.createElement('label');
      lb.setAttribute('for', 'ecv-' + nome);
      lb.textContent = nome;
      var inp = document.createElement('input');
      inp.type = 'text';
      inp.className = 'ia_input';
      inp.id = 'ecv-' + nome;
      inp.dataset.var = nome;
      inp.value = valores[nome] || '';
      inp.addEventListener('input', repintar);
      grupo.appendChild(lb);
      grupo.appendChild(inp);
      alvo.appendChild(grupo);
    });
  }

  // Repinta pelo MESMO caminho do salvar (endpoint /montar, sem IA e sem
  // custo). Substituir no HTML já montado seria mais rápido, mas a prévia
  // deixaria de ser prova do que vai ser gravado — e é justamente para isso
  // que ela existe.
  var repintarTimer = null;
  function repintar() {
    clearTimeout(repintarTimer);
    repintarTimer = setTimeout(function () {
      if (!atual) { return; }
      post('/admin/ia/email-conteudo/montar', corpoDeMontagem())
        .then(function (r) {
          if (!r.ok) { return; }
          atual.html = r.html;
          $('ec-preview').srcdoc = r.html;
          $('ec-tamanho').textContent = ((r.bytes || 0) / 1024).toFixed(1).replace('.', ',') + ' KB';
        })
        .catch(function () { /* prévia é conveniência: falhar em silêncio */ });
    }, 400);
  }

  /** Corpo comum de /montar e /salvar: produtos da prévia + valores atuais. */
  function corpoDeMontagem() {
    var body = corpoBase();
    body.append('template_id', $('ec-template').value);
    (atual.produtos || []).forEach(function (i) { body.append('produtos[]', i); });
    valoresAtuais().forEach(function (par) { body.append('valores[' + par[0] + ']', par[1]); });
    // As constantes da loja não têm campo na tela, mas precisam ir junto —
    // sem elas o logo e o endereço sumiriam a cada repintura.
    Object.keys(atual.valores || {}).forEach(function (k) {
      if (!document.getElementById('ecv-' + k)) {
        body.append('valores[' + k + ']', atual.valores[k]);
      }
    });
    return body;
  }

  function valoresAtuais() {
    return Array.prototype.map.call(
      document.querySelectorAll('#ec-valores input[data-var]'),
      function (i) { return [i.dataset.var, i.value]; }
    );
  }

  function mostrar(res) {
    atual = res;
    $('ec-assunto').value   = res.assunto || '';
    $('ec-preheader').value = res.preheader || '';
    if (!$('ec-nome').value) {
      $('ec-nome').value = (res.assunto || 'Campanha gerada por IA').slice(0, 190);
    }

    camposDeTexto(res.valores || {}, res.classes);

    var notas = (res.notas || '').trim();
    $('ec-notas-wrap').hidden = notas === '';
    $('ec-notas').textContent = notas;

    $('ec-preview').srcdoc = res.html || '';
    $('ec-tamanho').textContent = ((res.bytes || 0) / 1024).toFixed(1).replace('.', ',') + ' KB';

    var ia = res._ia;
    $('ec-procedencia').textContent = ia
      ? (ia.rotulo || '') + (ia.custo_usd ? ' · US$ ' + Number(ia.custo_usd).toFixed(6) : '')
      : 'sem copy — vitrine pura, nenhuma chamada de IA';

    if (ia && ia.trocou) {
      adminToast('O modelo escolhido falhou; respondeu ' + (ia.rotulo || 'outro da cadeia') + '.', 'warning');
    }

    $('ec-resultado').hidden = false;
    $('ec-resultado').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function gerar(btn) {
    if (!$('ec-template').value) { adminToast('Escolha o layout de base.', 'warning'); return; }
    var rotulo = btn.innerHTML;
    btn.disabled = true; btn.textContent = 'Montando…';

    var body = corpoBase();
    body.append('template_id', $('ec-template').value);
    body.append('briefing', $('ec-briefing').value);
    body.append('objetivo', $('ec-objetivo').value);
    body.append('publico',  $('ec-publico').value);
    body.append('modelo_id', $('ec-modelo').value || '');

    post('/admin/ia/email-conteudo/gerar', body)
      .then(function (r) {
        if (!r.ok) { adminToast(r.msg || 'Falha ao montar.', 'error'); return; }
        mostrar(r);
      })
      .catch(function () { adminToast('Falha de comunicação.', 'error'); })
      .finally(function () { btn.disabled = false; btn.innerHTML = rotulo; });
  }

  $('ec-gerar').addEventListener('click', function () { gerar(this); });
  $('ec-regerar').addEventListener('click', function () { gerar($('ec-gerar')); });

  $('ec-salvar').addEventListener('click', function () {
    if (!atual) { return; }
    var btn = this; btn.disabled = true;

    var body = corpoDeMontagem();
    body.append('nome',      $('ec-nome').value);
    body.append('assunto',   $('ec-assunto').value);
    body.append('preheader', $('ec-preheader').value);
    body.append('briefing',  $('ec-briefing').value);
    body.append('geracao_id', (atual._ia && atual._ia.geracao_id) || 0);

    post('/admin/ia/email-conteudo/salvar', body)
      .then(function (r) {
        if (!r.ok) { adminToast(r.msg || 'Não foi possível salvar.', 'error'); return; }
        adminToast('Campanha criada como rascunho. Faça um envio de teste antes de ativar.', 'success');
        if (r.url) { window.location.href = r.url; }
      })
      .catch(function () { adminToast('Falha de comunicação.', 'error'); })
      .finally(function () { btn.disabled = false; });
  });

  Array.prototype.forEach.call(document.querySelectorAll('.eml_view'), function (b) {
    b.addEventListener('click', function () {
      Array.prototype.forEach.call(document.querySelectorAll('.eml_view'), function (o) {
        o.classList.remove('eml_view_ativo');
      });
      b.classList.add('eml_view_ativo');
      $('ec-preview').style.width = b.dataset.w + 'px';
    });
  });
})();
</script>
