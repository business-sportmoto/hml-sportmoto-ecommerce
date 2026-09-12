<?php
/**
 * View: configuração de SEO da loja — /admin/seo
 *
 * $campos, $valores, $loja, $exemplo, $sitemap, $robots
 * (SeoConfigAdminController::index)
 *
 * A pré-visualização é montada no navegador com os mesmos textos dos campos:
 * quem escreve vê o resultado antes de salvar, sem recarregar.
 */
$v = static fn(string $k): string => (string) ($valores[$k] ?? '');
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <h1>SEO da loja</h1>
      <p>Títulos, descrições e imagem de compartilhamento. Vale para as páginas que não têm texto próprio.</p>
    </div>
    <div style="display:flex;gap:8px;">
      <a href="<?= BASE_URL ?>/admin/seo/404" class="btn btn-outline">Erros 404</a>
      <a href="<?= BASE_URL ?>/admin/seo/redirecionamentos" class="btn btn-outline">Redirecionamentos</a>
    </div>
  </div>

  <form id="seoForm" autocomplete="off" onsubmit="return false;">
    <?= SecurityHelper::csrfField() ?>

    <!-- ═══════════ A LOJA ═══════════ -->
    <div class="admin-card">
      <div class="admin-card-header"><h3>A loja</h3></div>
      <div class="admin-card-body">

        <?php foreach (['seo_titulo_home', 'seo_description', 'seo_title_sufixo'] as $k):
          $d = $campos[$k]; ?>
        <div class="ap-form-group seo-campo">
          <label class="ap-form-label" for="<?= $k ?>"><?= View::e($d['rotulo']) ?></label>
          <?php if ($d['tipo'] === 'text'): ?>
            <textarea class="form-control" id="<?= $k ?>" name="<?= $k ?>" rows="2"
                      maxlength="<?= (int) $d['limite'] ?>"
                      data-ideal="<?= (int) $d['ideal'] ?>"><?= View::e($v($k)) ?></textarea>
          <?php else: ?>
            <input type="text" class="form-control" id="<?= $k ?>" name="<?= $k ?>"
                   maxlength="<?= (int) $d['limite'] ?>" data-ideal="<?= (int) $d['ideal'] ?>"
                   value="<?= View::e($v($k)) ?>">
          <?php endif; ?>
          <small class="seo-dica"><?= View::e($d['dica']) ?></small>
          <small class="seo-conta" data-para="<?= $k ?>"></small>
        </div>
        <?php endforeach; ?>

        <?php // Como isso aparece na busca — com os textos que estão nos campos. ?>
        <div class="seo-preview" aria-live="polite">
          <span class="seo-preview-rotulo">Como aparece na busca</span>
          <div class="seo-preview-url"><?= View::e(preg_replace('~^https?://~', '', rtrim(BASE_URL, '/'))) ?></div>
          <div class="seo-preview-titulo" id="pv-titulo"></div>
          <div class="seo-preview-desc" id="pv-desc"></div>
        </div>
      </div>
    </div>

    <!-- ═══════════ COMPARTILHAMENTO ═══════════ -->
    <div class="admin-card">
      <div class="admin-card-header"><h3>Compartilhamento (WhatsApp e redes)</h3></div>
      <div class="admin-card-body">
        <?php $d = $campos['seo_og_imagem']; ?>
        <div class="ap-form-group seo-campo">
          <label class="ap-form-label" for="seo_og_imagem"><?= View::e($d['rotulo']) ?></label>
          <input type="text" class="form-control" id="seo_og_imagem" name="seo_og_imagem"
                 maxlength="<?= (int) $d['limite'] ?>" placeholder="https://…"
                 value="<?= View::e($v('seo_og_imagem')) ?>">
          <small class="seo-dica"><?= View::e($d['dica']) ?></small>
        </div>

        <p class="seo-nota">
          <strong>Página de produto e de categoria não usam esta imagem:</strong> elas
          mostram a foto do próprio produto, convertida para JPEG 1200×630 na hora.
          Esta aqui entra quando não há foto nenhuma — a home, por exemplo.
        </p>

        <?php if ($v('seo_og_imagem') !== ''): ?>
        <div class="seo-og-previa">
          <img src="<?= View::e($v('seo_og_imagem')) ?>" alt="Imagem de compartilhamento configurada">
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ═══════════ MODELOS ═══════════ -->
    <div class="admin-card">
      <div class="admin-card-header">
        <h3>Modelos de título e descrição</h3>
      </div>
      <div class="admin-card-body">
        <p class="seo-nota">
          Valem só para páginas <strong>sem texto próprio</strong>. Título escrito na
          ficha do produto ou da categoria sempre vence o modelo — e, por ser
          completo, não recebe o sufixo.
          Clique em um campo entre colchetes para inserir no cursor.
        </p>

        <?php foreach (['seo_titulo_produto','seo_titulo_categoria','seo_titulo_busca',
                        'seo_desc_categoria','seo_desc_busca'] as $k):
          $d = $campos[$k]; ?>
        <div class="ap-form-group seo-campo">
          <label class="ap-form-label" for="<?= $k ?>"><?= View::e($d['rotulo']) ?></label>
          <?php if ($d['tipo'] === 'text'): ?>
            <textarea class="form-control" id="<?= $k ?>" name="<?= $k ?>" rows="2"
                      maxlength="<?= (int) $d['limite'] ?>"
                      data-ideal="<?= (int) $d['ideal'] ?>"><?= View::e($v($k)) ?></textarea>
          <?php else: ?>
            <input type="text" class="form-control" id="<?= $k ?>" name="<?= $k ?>"
                   maxlength="<?= (int) $d['limite'] ?>" data-ideal="<?= (int) $d['ideal'] ?>"
                   value="<?= View::e($v($k)) ?>">
          <?php endif; ?>
          <small class="seo-dica"><?= View::e($d['dica']) ?></small>
          <?php if (!empty($d['campos'])): ?>
          <div class="seo-chips">
            <?php foreach ($d['campos'] as $c): ?>
              <button type="button" class="seo-chip" data-campo="<?= $k ?>" data-valor="<?= View::e($c) ?>"><?= View::e($c) ?></button>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <small class="seo-conta" data-para="<?= $k ?>"></small>
          <small class="seo-exemplo" data-exemplo="<?= $k ?>"></small>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ═══════════ SITEMAP E ROBOTS ═══════════ -->
    <div class="admin-card">
      <div class="admin-card-header"><h3>Sitemap e robots</h3></div>
      <div class="admin-card-body">
        <p class="seo-nota">
          Os dois são gerados pelo sistema, sempre atualizados — não há o que
          preencher aqui. Use os links para conferir o que os buscadores leem.
        </p>
        <div class="seo-links">
          <a href="<?= View::e($sitemap) ?>" target="_blank" rel="noopener" class="btn btn-outline">Ver sitemap.xml</a>
          <a href="<?= View::e($robots) ?>" target="_blank" rel="noopener" class="btn btn-outline">Ver robots.txt</a>
        </div>
        <p class="seo-nota" style="margin-top:12px;">
          <strong>Fora de produção o site inteiro sai do índice</strong> por um
          cabeçalho <code>X-Robots-Tag: noindex</code> — homologação não disputa
          posição com a loja real.
        </p>
      </div>
    </div>

    <div class="seo-rodape">
      <button type="button" class="btn btn-primary" id="seoSalvar">Salvar configuração</button>
      <span class="seo-salvo" id="seoSalvo" hidden>Salvo.</span>
    </div>
  </form>
</div>

<style>
.seo-campo{margin-bottom:18px}
.seo-dica{display:block;color:var(--text-3);font-size:12px;margin-top:4px;line-height:1.45}
.seo-conta{display:block;font-size:11.5px;margin-top:3px;color:var(--text-3)}
.seo-conta.longo{color:#b45309;font-weight:700}
.seo-exemplo{display:block;font-size:12px;margin-top:4px;color:var(--blue);word-break:break-word}
.seo-nota{font-size:12.5px;color:var(--text-2);line-height:1.55;margin:0 0 14px}
.seo-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
.seo-chip{border:1px solid var(--border);background:var(--bg);border-radius:20px;padding:2px 10px;
  font:600 11.5px ui-monospace,monospace;color:var(--text-2);cursor:pointer}
.seo-chip:hover{border-color:var(--blue);color:var(--blue)}
.seo-preview{border:1px solid var(--border);border-radius:12px;padding:14px 16px;background:#fff;margin-top:6px}
.seo-preview-rotulo{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);margin-bottom:8px}
.seo-preview-url{font-size:12.5px;color:#202124}
.seo-preview-titulo{font-size:18px;color:#1a0dab;line-height:1.3;margin:2px 0 3px}
.seo-preview-desc{font-size:13px;color:#4d5156;line-height:1.5}
.seo-og-previa{margin-top:10px;max-width:360px}
.seo-og-previa img{width:100%;border:1px solid var(--border);border-radius:10px;display:block}
.seo-links{display:flex;gap:8px;flex-wrap:wrap}
.seo-rodape{display:flex;align-items:center;gap:12px;margin:6px 0 30px}
.seo-salvo{color:#047857;font-weight:700;font-size:13px}
.seo-salvo[hidden]{display:none}
@media (max-width:560px){ .seo-preview-titulo{font-size:16px} }
</style>

<script>
(function () {
  var LOJA    = <?= json_encode($loja, JSON_UNESCAPED_UNICODE) ?>;
  var EXEMPLO = <?= json_encode($exemplo, JSON_UNESCAPED_UNICODE) ?>;
  var SALVAR  = <?= json_encode(BASE_URL . '/admin/seo/salvar') ?>;

  // Recorta como o Google recorta: por caractere não é exato (ele mede em
  // pixels), mas erra pouco e é o que o time consegue conferir.
  function corta(txt, max) { return txt.length > max ? txt.slice(0, max - 1).trimEnd() + '…' : txt; }

  function conta($campo) {
    var el    = $campo[0];
    var ideal = parseInt(el.dataset.ideal || '0', 10);
    var n     = (el.value || '').length;
    var $out  = $('.seo-conta[data-para="' + el.id + '"]');
    if (!$out.length) return;
    if (!ideal) { $out.text(n + ' caracteres'); return; }
    var sobra = ideal - n;
    $out.text(sobra >= 0 ? (n + ' caracteres · cabem ' + sobra + ' até o limite recomendado de ' + ideal)
                         : (n + ' caracteres · ' + (-sobra) + ' além do recomendado (' + ideal + '), o Google corta'));
    $out.toggleClass('longo', sobra < 0);
  }

  // O que o modelo produz com dado real da loja.
  function exemploDoModelo(id, valor) {
    if (!valor) return '';
    var trocas = {
      '[nome_produto]':   EXEMPLO.produto,
      '[marca]':          EXEMPLO.marca,
      '[nome_categoria]': EXEMPLO.categoria,
      '[termo]':          'capacete',
      '[total]':          '42',
      '[loja]':           LOJA
    };
    var saida = valor;
    Object.keys(trocas).forEach(function (k) { saida = saida.split(k).join(trocas[k]); });
    return 'Fica assim: ' + saida;
  }

  function pinta() {
    var titulo = $('#seo_titulo_home').val() || LOJA;
    var desc   = $('#seo_description').val() || '';
    $('#pv-titulo').text(corta(titulo, 60));
    $('#pv-desc').text(corta(desc, 155) || 'Sem descrição — o Google inventa uma a partir da página.');

    $('.seo-campo').find('input,textarea').each(function () {
      conta($(this));
      var $ex = $('.seo-exemplo[data-exemplo="' + this.id + '"]');
      if ($ex.length) $ex.text(exemploDoModelo(this.id, this.value));
    });
  }

  $(document).on('input', '#seoForm input, #seoForm textarea', pinta);

  // Insere o campo entre colchetes onde o cursor está.
  $(document).on('click', '.seo-chip', function () {
    var alvo = document.getElementById(this.dataset.campo);
    if (!alvo) return;
    var ini = alvo.selectionStart || alvo.value.length;
    alvo.value = alvo.value.slice(0, ini) + this.dataset.valor + alvo.value.slice(alvo.selectionEnd || ini);
    alvo.focus();
    alvo.selectionStart = alvo.selectionEnd = ini + this.dataset.valor.length;
    pinta();
  });

  $('#seoSalvar').on('click', function () {
    var $b = $(this).prop('disabled', true).text('Salvando…');
    $('#seoSalvo').prop('hidden', true);

    $.post(SALVAR, $('#seoForm').serialize(), null, 'json')
      .done(function (r) {
        if (r && r.ok) {
          $('#seoSalvo').prop('hidden', false);
          if (typeof adminToast === 'function') adminToast(r.msg || 'Salvo.', 'success');
        } else {
          var msg = (r && r.msg) || 'Não foi possível salvar.';
          if (typeof adminToast === 'function') adminToast(msg, 'error'); else alert(msg);
        }
      })
      .fail(function () {
        if (typeof adminToast === 'function') adminToast('Erro de conexão.', 'error');
        else alert('Erro de conexão.');
      })
      .always(function () { $b.prop('disabled', false).text('Salvar configuração'); });
  });

  pinta();
})();
</script>
