<?php
// admin/views/pagamentos/adquirentes.php
// $adquirentes e $suportadas injetados pelo AdminPagamentoConfigController
//
// SEGREDOS: os campos de chave chegam aqui SEM valor — o model já os removeu.
// A tela mostra apenas "configurada" ou "pendente". Um segredo renderizado num
// input acaba em cache do navegador, em print de suporte e no autocomplete.
//
// A TELA RESPONDE UMA PERGUNTA ANTES DE QUALQUER CLIQUE:
//   "minha malha de pagamento está de pé?" — daí a faixa de resumo no topo e
//   o ponto de estado em cada card. Configurar é a exceção, não a regra: por
//   isso o formulário mora no drawer, e não empilhado na página.
//
// Os campos de cada adquirente vêm de PagamentoAdquirente::camposDe(), e não
// de uma lista escrita aqui. Duas listas divergem — foi assim que a chave
// pública do Mercado Pago ficou sem lugar na tela.

$total      = count($adquirentes);
$ativas     = 0;
$pendentes  = 0;
$emProducao = 0;

foreach ($adquirentes as $a) {
    if ($a['ativo']) $ativas++;
    if (empty($a['merchant_id']) && empty($a['api_key_preenchido'])) $pendentes++;
    if ($a['ativo'] && empty($a['sandbox'])) $emProducao++;
}
?>

<div class="admin-page adq-page">

  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/configuracoes" class="back-link">← Configurações</a>
      <h1 class="admin-page-title">Adquirentes</h1>
      <p class="admin-page-sub">
        Quem processa o pagamento. As credenciais ficam aqui; as regras comerciais
        ficam em Formas de pagamento; e a ordem em que cada uma é tentada, no fluxo.
      </p>
    </div>
    <a href="<?= ADMIN_URL ?>/pagamentos/formas" class="btn btn-outline">← Formas de pagamento</a>
  </div>

  <?php if ($total): ?>
  <!-- Resumo: o estado da malha em três números, antes de rolar a página. -->
  <div class="adq-resumo">
    <div class="adq-resumo-item">
      <span class="adq-resumo-num"><?= $ativas ?><small>/<?= $total ?></small></span>
      <span class="adq-resumo-rot">ativas</span>
    </div>
    <div class="adq-resumo-item <?= $emProducao ? 'is-forte' : '' ?>">
      <span class="adq-resumo-num"><?= $emProducao ?></span>
      <span class="adq-resumo-rot">em produção</span>
    </div>
    <div class="adq-resumo-item <?= $pendentes ? 'is-alerta' : '' ?>">
      <span class="adq-resumo-num"><?= $pendentes ?></span>
      <span class="adq-resumo-rot">sem credencial</span>
    </div>
    <p class="adq-resumo-nota">
      <?php if ($emProducao): ?>
        Cobranças reais estão habilitadas.
      <?php elseif ($ativas): ?>
        Tudo em sandbox — nenhuma cobrança real acontece.
      <?php else: ?>
        Nenhuma adquirente ativa. O checkout não processa pagamentos.
      <?php endif; ?>
    </p>
  </div>

  <div class="adq-grid">
    <?php foreach ($adquirentes as $a):
        $configurada = !empty($a['merchant_id']) || !empty($a['api_key_preenchido']);
        $emUso       = !empty($a['fluxos']);
        $semAdapter  = empty($a['tem_adapter']);
        $logo        = trim((string) ($a['logo_url'] ?? ''));

        // O ponto de estado resume o card numa cor só, para varrer a grade
        // sem ler tag nenhuma.
        $estado = !$a['ativo'] ? 'off'
                : (($semAdapter || !$configurada) ? 'atencao'
                : (empty($a['sandbox']) ? 'live' : 'teste'));
    ?>
    <article class="adq-card is-<?= $estado ?>" data-id="<?= (int) $a['id'] ?>">

      <header class="adq-head">
        <div class="adq-marca<?= $logo ? ' tem-logo' : '' ?>">
          <?php if ($logo): ?>
            <img src="<?= View::e($logo) ?>" alt="<?= View::e($a['nome']) ?>" loading="lazy">
          <?php else: ?>
            <?= IconLibrary::adquirente($a['codigo']) ?>
          <?php endif; ?>
        </div>

        <div class="adq-ident">
          <h3>
            <span class="adq-ponto" aria-hidden="true"></span>
            <?= View::e($a['nome']) ?>
          </h3>
          <code><?= View::e($a['codigo']) ?></code>
        </div>

        <!-- Interruptor no card: ativar e desativar é a ação mais frequente
             desta tela e não merece dois cliques dentro de um drawer. -->
        <label class="adq-switch" title="<?= $a['ativo'] ? 'Desativar' : 'Ativar' ?>">
          <input type="checkbox" class="adq-toggle" <?= $a['ativo'] ? 'checked' : '' ?>
                 data-id="<?= (int) $a['id'] ?>"
                 aria-label="<?= $a['ativo'] ? 'Desativar' : 'Ativar' ?> <?= View::e($a['nome']) ?>">
          <span class="adq-trilho"><span class="adq-bola"></span></span>
        </label>
      </header>

      <div class="adq-tags">
        <span class="adq-tag <?= $a['ativo'] ? 'ok' : 'neutra' ?>"><?= $a['ativo'] ? 'Ativa' : 'Inativa' ?></span>

        <?php if (!empty($a['sandbox'])): ?>
          <span class="adq-tag aviso" title="Nenhuma cobrança real é feita">Sandbox</span>
        <?php else: ?>
          <span class="adq-tag forte" title="Cobranças aqui movimentam dinheiro real">Produção</span>
        <?php endif; ?>

        <?php if ($semAdapter): ?>
          <span class="adq-tag erro" title="Não existe código de integração — esta adquirente não processa nada">Sem adapter</span>
        <?php endif; ?>

        <span class="adq-tag <?= $configurada ? 'ok' : 'erro' ?>"
              title="<?= $configurada ? 'Merchant ID ou chave presentes' : 'Faltam as credenciais' ?>">
          <?= $configurada ? 'Credenciais ok' : 'Sem credencial' ?>
        </span>
      </div>

      <p class="adq-uso <?= $emUso ? '' : 'vazio' ?>">
        <?php if ($emUso): ?>
          Em uso no fluxo <strong><?= View::e(implode(', ', array_column($a['fluxos'], 'nome'))) ?></strong>
        <?php else: ?>
          Fora de qualquer fluxo publicado
        <?php endif; ?>
      </p>

      <footer class="adq-pe">
        <button type="button" class="btn btn-outline btn-sm adq-testar" data-id="<?= (int) $a['id'] ?>">
          Testar
        </button>
        <button type="button" class="btn btn-primary btn-sm adq-configurar" data-id="<?= (int) $a['id'] ?>">
          Configurar
        </button>
      </footer>

      <!-- Formulário do drawer, em <template> para nascer do HTML já escapado
           pelo PHP em vez de concatenado em JS. -->
      <template class="adq-tpl">
        <form class="form-adquirente" data-id="<?= (int) $a['id'] ?>"
              data-codigo="<?= View::e($a['codigo']) ?>">
          <?= SecurityHelper::csrfField() ?>
          <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">

          <!-- ── Logo ────────────────────────────────────────────── -->
          <div class="adq-logo-bloco">
            <div class="adq-logo-previa">
              <?php if ($logo): ?>
                <img src="<?= View::e($logo) ?>" alt="">
              <?php else: ?>
                <?= IconLibrary::adquirente($a['codigo'], 56) ?>
              <?php endif; ?>
            </div>
            <div class="adq-logo-acoes">
              <strong>Logo</strong>
              <p>PNG, JPG ou WebP. Vai para o Cloudflare R2 e é convertido para WebP.
                 Sem logo, fica o monograma.</p>
              <div class="adq-logo-btns">
                <label class="btn btn-outline btn-sm">
                  Enviar imagem
                  <input type="file" class="adq-logo-input" accept="image/*" hidden>
                </label>
                <button type="button" class="btn btn-ghost btn-sm adq-logo-remover"
                        <?= $logo ? '' : 'hidden' ?>>Remover</button>
              </div>
            </div>
          </div>

          <div class="adq-aviso">
            As chaves nunca são exibidas. Deixe em branco para manter a atual —
            preencher só é necessário para trocá-la.
          </div>

          <div class="form-group">
            <label>Nome de exibição</label>
            <input type="text" name="nome" class="form-control" value="<?= View::e($a['nome']) ?>">
          </div>

          <?php foreach (PagamentoAdquirente::camposDe($a['codigo']) as $campo):
              $col     = $campo['coluna'];
              $segredo = $campo['tipo'] === 'segredo';
              $jaTem   = $segredo && !empty($a[$col . '_preenchido']);
          ?>
          <div class="form-group">
            <label>
              <?= View::e($campo['rotulo']) ?>
              <?php if (!empty($campo['obrigatorio'])): ?>
                <span class="adq-obrig">obrigatório</span>
              <?php endif; ?>
              <?php if ($jaTem): ?>
                <span class="adq-ok-inline">• já configurado</span>
              <?php endif; ?>
            </label>

            <?php if ($segredo): ?>
              <input type="password" name="<?= View::e($col) ?>" class="form-control"
                     autocomplete="new-password"
                     placeholder="<?= $jaTem ? 'deixe em branco para manter' : (!empty($campo['obrigatorio']) ? 'obrigatório' : 'opcional') ?>">
            <?php else: ?>
              <input type="text" name="<?= View::e($col) ?>" class="form-control" autocomplete="off"
                     value="<?= View::e((string) ($a[$col] ?? '')) ?>"
                     <?= $campo['tipo'] === 'url'
                         ? 'placeholder="' . View::e(BASE_URL . '/webhooks/' . $a['codigo']) . '"'
                         : '' ?>>
            <?php endif; ?>

            <?php if (!empty($campo['ajuda'])): ?>
              <small class="form-help"><?= View::e($campo['ajuda']) ?></small>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <?php
          $extras = PagamentoAdquirente::extrasDe($a['codigo']);
          $cfg    = json_decode((string) ($a['config_extra'] ?? ''), true) ?: [];
          if ($extras):
          ?>
          <h4 class="adq-secao">Opções desta adquirente</h4>
          <div class="adq-grid-2">
            <?php foreach ($extras as $ex): $valor = $cfg[$ex['chave']] ?? $ex['padrao'] ?? ''; ?>
            <div class="form-group">
              <label><?= View::e($ex['rotulo']) ?></label>
              <?php if ($ex['tipo'] === 'select'): ?>
                <select name="extra[<?= View::e($ex['chave']) ?>]" class="form-control">
                  <?php foreach ($ex['opcoes'] as $v => $rot): ?>
                    <option value="<?= View::e($v) ?>" <?= (string) $valor === (string) $v ? 'selected' : '' ?>>
                      <?= View::e($rot) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input type="number" name="extra[<?= View::e($ex['chave']) ?>]"
                       class="form-control" value="<?= View::e((string) $valor) ?>">
              <?php endif; ?>
              <?php if (!empty($ex['ajuda'])): ?>
                <small class="form-help"><?= View::e($ex['ajuda']) ?></small>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <h4 class="adq-secao">Ambiente</h4>
          <label class="check-label adq-sandbox">
            <input type="checkbox" name="sandbox" value="1" <?= !empty($a['sandbox']) ? 'checked' : '' ?>>
            <span class="check-custom"></span>
            <span>
              Ambiente de testes (sandbox)
              <small>Desmarcado, as cobranças passam a ser reais.</small>
            </span>
          </label>
        </form>
      </template>
    </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="admin-card adq-vazio">
    Nenhuma adquirente cadastrada. Rode <code>migration-pagamentos.sql</code>.
  </div>
  <?php endif; ?>

</div>

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

  function card(id) { return document.querySelector('.adq-card[data-id="' + id + '"]'); }

  /**
   * FormData da adquirente.
   *
   * Recebe o formulário quando a chamada parte do drawer; sem ele, monta a
   * partir do <template>. Escopar assim, em vez de procurar no documento
   * inteiro, evita pegar o formulário de um drawer que ainda não saiu do DOM.
   */
  function dadosDe(id, form, extra) {
    var origem = form || card(id).querySelector('.adq-tpl').content.querySelector('form');
    var fd = new FormData(origem);
    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
    return fd;
  }

  // ── Ativar / desativar direto no card ──────────────────────────────
  document.querySelectorAll('.adq-toggle').forEach(function (sw) {
    sw.addEventListener('change', function () {
      var id = sw.dataset.id, ativar = sw.checked ? '1' : '0';
      sw.disabled = true;

      var enviar = function (confirmado) {
        return post('/pagamentos/adquirentes/alternar',
                    dadosDe(id, null, { ativar: ativar, confirmado: confirmado ? '1' : '' }));
      };

      enviar(false).then(function (res) {
        // Desativar adquirente que está num fluxo publicado deixa o roteamento
        // sem saída. Pergunta antes, mas deixa seguir: pode ser exatamente o
        // que o lojista quer quando a adquirente cai.
        if (!res.ok && res.confirmar) {
          if (!confirm(res.msg)) { sw.checked = !sw.checked; sw.disabled = false; return; }
          return enviar(true).then(function (r2) {
            aviso(r2.ok, r2.msg);
            if (r2.ok) location.reload(); else { sw.checked = !sw.checked; sw.disabled = false; }
          });
        }
        aviso(res.ok, res.msg);
        if (res.ok) location.reload();
        else { sw.checked = !sw.checked; sw.disabled = false; }
      }).catch(function () {
        sw.checked = !sw.checked; sw.disabled = false;
        aviso(false, 'Erro de conexão.');
      });
    });
  });

  // ── Testar sem abrir o drawer ──────────────────────────────────────
  document.querySelectorAll('.adq-testar').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.dataset.id, txt = b.textContent;
      b.disabled = true; b.textContent = 'Testando…';
      post('/pagamentos/adquirentes/testar', dadosDe(id, null)).then(function (res) {
        b.disabled = false; b.textContent = txt;
        aviso(res.ok, res.msg);
      }).catch(function () {
        b.disabled = false; b.textContent = txt;
        aviso(false, 'Erro de conexão.');
      });
    });
  });

  // ── Configurar: drawer ─────────────────────────────────────────────
  document.querySelectorAll('.adq-configurar').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.dataset.id, el = card(id);

      var drawer = adminDrawer({
        titulo:    el.querySelector('.adq-ident h3').textContent.trim(),
        subtitulo: 'Credenciais e ambiente · ' + el.querySelector('.adq-ident code').textContent,
        tamanho:   'md',
        conteudo:  el.querySelector('.adq-tpl').content.cloneNode(true),
        acoes:     '<div class="adq-drawer-pe">'
                 +   '<button type="button" class="btn btn-outline btn-sm" data-acao="testar">Testar conexão</button>'
                 +   '<button type="button" class="btn btn-primary btn-sm" data-acao="salvar">Salvar</button>'
                 + '</div>'
      });

      // O formulário vive no corpo DESTE drawer.
      var corpo  = drawer.corpo();
      var form   = corpo.querySelector('.form-adquirente');
      var previa = corpo.querySelector('.adq-logo-previa');

      // ── Logo ─────────────────────────────────────────────────────
      // Sobe na hora da escolha, não no Salvar: a resposta traz a URL do R2 e
      // a prévia passa a mostrar o arquivo que o servidor realmente guardou,
      // não um preview local que pode divergir dele.
      function enviarLogo(fd) {
        previa.classList.add('enviando');
        fd.append('id', id);
        fd.append('_csrf_token', form.querySelector('[name="_csrf_token"]').value);

        return post('/pagamentos/adquirentes/logo', fd).then(function (res) {
          previa.classList.remove('enviando');
          aviso(res.ok, res.msg);
          if (!res.ok) return res;

          var marca    = el.querySelector('.adq-marca');
          var remover  = corpo.querySelector('.adq-logo-remover');

          if (res.url) {
            previa.innerHTML = '<img src="' + res.url + '" alt="">';
            marca.classList.add('tem-logo');
            marca.innerHTML = '<img src="' + res.url + '" alt="" loading="lazy">';
            if (remover) remover.hidden = false;
          } else {
            // Voltou ao monograma: recarrega para o SVG vir do servidor, em
            // vez de o JS tentar redesenhar a marca.
            location.reload();
          }
          return res;
        }).catch(function () {
          previa.classList.remove('enviando');
          aviso(false, 'Erro de conexão.');
        });
      }

      drawer.escutar('change', '.adq-logo-input', function (e) {
        var arq = e.target.files && e.target.files[0];
        if (!arq) return;
        var fd = new FormData();
        fd.append('logo', arq);
        enviarLogo(fd);
        e.target.value = '';   // permite reenviar o mesmo arquivo
      });

      drawer.escutar('click', '.adq-logo-remover', function () {
        var fd = new FormData();
        fd.append('remover', '1');
        enviarLogo(fd);
      });

      // ── Salvar / testar ──────────────────────────────────────────
      drawer.escutar('click', '[data-acao="salvar"]', function (e) {
        var btn = e.currentTarget;
        btn.disabled = true;
        post('/pagamentos/adquirentes/salvar', dadosDe(id, form)).then(function (res) {
          btn.disabled = false;
          aviso(res.ok, res.msg || 'Não foi possível salvar.');
          if (res.ok) location.reload();
        }).catch(function () {
          btn.disabled = false;
          aviso(false, 'Erro de conexão.');
        });
      });

      drawer.escutar('click', '[data-acao="testar"]', function (e) {
        var btn = e.currentTarget, txt = btn.textContent;
        btn.disabled = true; btn.textContent = 'Testando…';
        post('/pagamentos/adquirentes/testar', dadosDe(id, form)).then(function (res) {
          btn.disabled = false; btn.textContent = txt;
          aviso(res.ok, res.msg);
        }).catch(function () {
          btn.disabled = false; btn.textContent = txt;
          aviso(false, 'Erro de conexão.');
        });
      });
    });
  });
})();
</script>
