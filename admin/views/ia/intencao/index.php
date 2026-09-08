<?php
/**
 * Intenção de compra por cliente — Fase 3.
 * Variáveis: $perfis, $segmentos, $elegiveis, $csrf
 *
 * Tela de leitura. O cálculo em lote é do cron; aqui dá para recalcular um
 * cliente e ver os sinais exatos que levaram ao rótulo — perfilamento tem de
 * ser auditável, não mágico.
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
$porIa = count(array_filter($perfis, fn ($p) => $p['origem'] === 'ia'));
?>
<div class="ia_wrap">

  <header class="ia_head">
    <div>
      <h1 class="ia_titulo"><?= IconLibrary::render('funnel', 'ia_ico', ['aria-hidden' => 'true']) ?> Intenção de compra</h1>
      <p class="ia_sub">O que cada cliente parece querer comprar, a partir do que ele navegou. Vira audiência de campanha por segmento.</p>
    </div>
    <div class="ia_topo_acoes">
      <a class="ia_btn" href="<?= BASE_URL ?>/admin/email-marketing/segmentos"><?= IconLibrary::render('arrow-right', 'ia_ico', ['aria-hidden' => 'true']) ?> Segmentos de e-mail</a>
    </div>
  </header>

  <section class="ia_kpis">
    <div class="ia_kpi"><span class="ia_kpi_rotulo">Perfis calculados</span><span class="ia_kpi_valor"><?= count($perfis) ?></span></div>
    <div class="ia_kpi"><span class="ia_kpi_rotulo">Elegíveis agora</span><span class="ia_kpi_valor"><?= (int) $elegiveis ?><span class="ia_kpi_de"> clientes</span></span></div>
    <div class="ia_kpi"><span class="ia_kpi_rotulo">Segmentos distintos</span><span class="ia_kpi_valor"><?= count($segmentos) ?></span></div>
    <div class="ia_kpi"><span class="ia_kpi_rotulo">Decididos pela IA</span><span class="ia_kpi_valor"><?= $porIa ?><span class="ia_kpi_de">/<?= count($perfis) ?></span></span></div>
  </section>

  <div class="ia_aviso_seguro" style="margin-bottom:14px">
    <?= IconLibrary::render('shield-check', 'ia_ico', ['aria-hidden' => 'true']) ?>
    <span><strong>Só entra quem consentiu.</strong> O cálculo ignora cliente sem
      contato ativo ou que está na lista de supressão — quem pediu para sair da
      lista sai do perfilamento junto. Os sinais de cada perfil ficam visíveis
      aqui para a decisão ser conferível.</span>
  </div>

  <?php if ($perfis === []): ?>
    <div class="ia_aviso_seguro" style="margin-bottom:14px">
      <?= IconLibrary::render('alert-triangle', 'ia_ico', ['aria-hidden' => 'true']) ?>
      <span>Nenhum perfil calculado ainda. Rode <code>php cli/ia-intencao.php --aplicar</code>
        (ou agende no cron) para a primeira rodada.</span>
    </div>
  <?php endif; ?>

  <?php if ($segmentos !== []): ?>
  <section class="ia_card ia_card_pad">
    <div class="ia_card_head" style="padding:0 0 var(--ia-e-2)">
      <h2 class="ia_card_titulo"><?= IconLibrary::render('stacks', 'ia_ico', ['aria-hidden' => 'true']) ?> Segmentos inferidos</h2>
      <span class="ia_hint">Use o rótulo na regra <code>intencao</code> de um segmento de e-mail.</span>
    </div>
    <div class="ia_chips">
      <?php foreach ($segmentos as $s): ?>
        <span class="ia_chip"><?= ia_e($s['segmento']) ?> · <?= (int) $s['clientes'] ?></span>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="ia_card">
    <div class="ia_card_head">
      <h2 class="ia_card_titulo"><?= IconLibrary::render('person-circle', 'ia_ico', ['aria-hidden' => 'true']) ?> Perfis</h2>
      <span class="ia_hint">Clique numa linha para ver os sinais que produziram o rótulo.</span>
    </div>
    <div class="ia_tabela_scroll">
      <table class="ia_tabela">
        <thead>
          <tr>
            <th>Cliente</th><th>Segmento</th><th>Confiança</th>
            <th>Decidido por</th><th class="ia_num">Eventos</th><th>Calculado</th>
            <th class="ia_acoes_th">Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($perfis as $p): ?>
          <tr>
            <td class="ia_celula_principal">
              <?= ia_e($p['cliente_nome'] ?: 'Cliente #' . $p['cliente_id']) ?>
              <span class="ia_celula_sub">#<?= (int) $p['cliente_id'] ?></span>
            </td>
            <td><span class="ia_chip"><?= ia_e($p['segmento']) ?></span></td>
            <td>
              <span class="ia_pill <?= $p['confianca'] === 'alta' ? 'ia_pill_ok' : ($p['confianca'] === 'baixa' ? 'ia_pill_off' : 'ia_pill_neutra') ?>">
                <?= ia_e($p['confianca']) ?>
              </span>
            </td>
            <td><span class="ia_pill <?= $p['origem'] === 'ia' ? 'ia_pill_azul' : 'ia_pill_neutra' ?>">
              <?= $p['origem'] === 'ia' ? 'IA' : 'contagem' ?>
            </span></td>
            <td class="ia_num"><?= (int) $p['eventos'] ?></td>
            <td><?= ia_e(substr((string) $p['calculado_em'], 0, 16)) ?></td>
            <td class="ia_acoes">
              <button type="button" class="ia_btn ia_btn_icone ia_ac_int_ver"
                      data-cliente="<?= (int) $p['cliente_id'] ?>" title="Ver os sinais">
                <?= IconLibrary::render('zoom-in', 'ia_ico', ['aria-hidden' => 'true']) ?>
              </button>
              <button type="button" class="ia_btn ia_btn_icone ia_ac_int_recalc"
                      data-cliente="<?= (int) $p['cliente_id'] ?>" title="Recalcular agora">
                <?= IconLibrary::render('refresh-cw', 'ia_ico', ['aria-hidden' => 'true']) ?>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<script>
(function () {
  'use strict';

  var CSRF = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
  var BASE = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>;

  function bloco(titulo, itens, chaveRotulo, chaveValor) {
    if (!itens || !itens.length) { return null; }
    var box = document.createElement('div');
    box.className = 'ia_form_grupo';
    var t = document.createElement('span');
    t.className = 'ia_grupo_rotulo';
    t.textContent = titulo;
    box.appendChild(t);
    var lista = document.createElement('div');
    lista.className = 'ia_chips';
    itens.forEach(function (i) {
      var c = document.createElement('span');
      c.className = 'ia_chip';
      // textContent: nome de produto e termo de busca vêm do banco
      c.textContent = (i[chaveRotulo] || '?') + ' · ' + (i[chaveValor] || 0);
      lista.appendChild(c);
    });
    box.appendChild(lista);
    return box;
  }

  document.addEventListener('click', function (ev) {
    var ver = ev.target.closest('.ia_ac_int_ver');
    if (ver) {
      var drawer = adminDrawer({ titulo: 'Sinais do cliente', tamanho: 'md' });
      drawer.setCarregando('Carregando…');

      fetch(BASE + '/admin/ia/intencao/detalhe?cliente_id=' + encodeURIComponent(ver.dataset.cliente), {
        credentials: 'same-origin'
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.ok) { drawer.setTexto(res.msg || 'Sem perfil.'); return; }
          var p = res.perfil, s = p.sinais || {};

          drawer.setTitulo(p.cliente_nome || ('Cliente #' + p.cliente_id));
          drawer.setSubtitulo(p.segmento + ' · confiança ' + p.confianca + ' · por ' + (p.origem === 'ia' ? 'IA' : 'contagem'));
          drawer.limparConteudo();

          var corpo = document.createElement('div');
          if (p.resumo) {
            var r = document.createElement('p');
            r.className = 'ia_ajuda';
            r.textContent = p.resumo;
            corpo.appendChild(r);
          }
          [
            ['Categorias', s.categorias, 'nome', 'peso'],
            ['Marcas',     s.marcas,     'nome', 'peso'],
            ['Produtos',   s.produtos,   'nome', 'peso'],
            ['Buscas',     s.buscas,     'termo', 'visitas']
          ].forEach(function (b) {
            var el = bloco(b[0], b[1], b[2], b[3]);
            if (el) { corpo.appendChild(el); }
          });

          var rodape = document.createElement('p');
          rodape.className = 'ia_ajuda';
          rodape.textContent = p.eventos + ' evento(s) na janela · calculado em ' + p.calculado_em;
          corpo.appendChild(rodape);

          drawer.setConteudo(corpo);
        })
        .catch(function () { drawer.setTexto('Falha de comunicação com o servidor.'); });
      return;
    }

    var rec = ev.target.closest('.ia_ac_int_recalc');
    if (!rec) { return; }
    rec.disabled = true;

    var corpo = new URLSearchParams();
    corpo.append('_csrf_token', CSRF);
    corpo.append('cliente_id', rec.dataset.cliente);

    fetch(BASE + '/admin/ia/intencao/recalcular', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: corpo.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        adminToast(res.msg || (res.ok ? 'Recalculado.' : 'Falhou.'), res.ok ? 'success' : 'error');
        if (res.ok) { setTimeout(function () { window.location.reload(); }, 900); }
      })
      .catch(function () { adminToast('Falha de comunicação.', 'error'); })
      .finally(function () { rec.disabled = false; });
  });
})();
</script>
