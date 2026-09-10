/* ════════════════════════════════════════════════════════
 * sino.js — notificações no header do site
 *
 * Fala com as MESMAS rotas do painel (`/notificacoes/*`); o controller
 * resolve o destinatário pela sessão, então cliente e admin nunca veem a
 * caixa um do outro.
 *
 * ── POLLING EM TRÊS VELOCIDADES ─────────────────────────
 *
 *   painel aberto  → 10s   (o cliente está olhando)
 *   aba ativa      → 30s
 *   aba em segundo plano → pausado
 *
 * Mais um refresh imediato ao abrir o painel e ao voltar para a aba. Uma aba
 * esquecida aberta a noite inteira não deve bater no servidor 2.880 vezes.
 *
 * Sem dependência de jQuery — o header do site carrega antes dele em algumas
 * páginas, e um sino que só funciona às vezes é pior que nenhum.
 * ════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var btn = document.getElementById('sino-btn');
  if (!btn) return;                      // visitante não logado: nem existe

  var painel      = document.getElementById('sino-painel');
  var badge       = document.getElementById('sino-badge');
  var lista       = document.getElementById('sino-lista');
  var btnMais     = document.getElementById('sino-mais');
  var btnTodas    = document.getElementById('sino-marcar-todas');

  // Do elemento, não de global do layout: `const BASE_URL` declarado num
  // <script> clássico não vira `window.BASE_URL`, e o CSRF_TOKEN do
  // views/layouts/main.php é string vazia (vem de `$csrf_token ?? ''`, que
  // nenhum controller passa). A partial já sabe os dois valores certos.
  var raiz = btn.closest('.sino-wrap');
  var BASE = (raiz.getAttribute('data-base') || '').replace(/\/$/, '');
  var CSRF = raiz.getAttribute('data-csrf') || '';

  var IV_ABERTO = 10000;
  var IV_ATIVA  = 30000;

  var aberto   = false;
  var abaAtiva = !document.hidden;
  var pagina   = 1;
  var timer    = null;
  var buscando = false;

  // ── Rede ──────────────────────────────────────────────
  function pegar(url) {
    return fetch(BASE + url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return null; });
  }

  function enviar(url, dados) {
    var corpo = new URLSearchParams(dados || {});
    corpo.append('_csrf_token', CSRF);
    return fetch(BASE + url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: corpo.toString(),
    }).then(function (r) { return r.json(); }).catch(function () { return null; });
  }

  // ── Badge ─────────────────────────────────────────────
  function atualizarBadge() {
    pegar('/notificacoes/contador').then(function (r) {
      if (!r || !r.ok) return;
      var n = parseInt(r.total, 10) || 0;
      if (n > 0) {
        badge.textContent = n > 99 ? '99+' : String(n);
        badge.hidden = false;
      } else {
        badge.hidden = true;
      }
    });
  }

  // ── Lista ─────────────────────────────────────────────
  function esc(t) {
    var d = document.createElement('div');
    d.textContent = t == null ? '' : String(t);
    return d.innerHTML;
  }

  function linha(n) {
    var lida = String(n.lida) === '1';
    return ''
      + '<' + (n.url ? 'a href="' + esc(n.url) + '"' : 'div') + ' class="sino-item'
      +   (lida ? '' : ' sino-item--nova') + '" data-nu="' + esc(n.nu_id) + '">'
      +   '<span class="sino-ponto" style="background:' + esc(n.cor || '#71717a') + '"></span>'
      +   '<span class="sino-item-corpo">'
      +     '<span class="sino-item-titulo">' + esc(n.titulo) + '</span>'
      +     (n.mensagem ? '<span class="sino-item-msg">' + esc(n.mensagem) + '</span>' : '')
      +     '<span class="sino-item-meta">' + esc(n.categoria_label || '')
      +       (n.tempo ? ' · ' + esc(n.tempo) : '') + '</span>'
      +   '</span>'
      + '</' + (n.url ? 'a' : 'div') + '>';
  }

  // O controller pagina por `pagina` (20 por vez), ignora limite/offset, e já
  // devolve `tem_mais` e o `tempo` relativo formatado. Contar item aqui para
  // deduzir se há mais páginas daria errado justamente na página cheia.
  function carregar(anexar) {
    if (buscando) return;
    buscando = true;
    if (!anexar) pagina = 1;

    pegar('/notificacoes/listar?pagina=' + pagina).then(function (r) {
      buscando = false;
      if (!r || !r.ok) {
        if (!anexar) lista.innerHTML = '<p class="sino-vazio">Não foi possível carregar agora.</p>';
        return;
      }

      var itens = r.itens || [];
      btnMais.hidden = !r.tem_mais;

      if (!anexar && itens.length === 0) {
        lista.innerHTML = '<p class="sino-vazio">Nada por aqui ainda.</p>';
        return;
      }

      var html = itens.map(linha).join('');
      if (anexar) lista.insertAdjacentHTML('beforeend', html);
      else        lista.innerHTML = html;
    });
  }

  // ── Marcar como lida ──────────────────────────────────
  //
  // No clique, não no hover nem ao abrir o painel: abrir para conferir não é
  // o mesmo que ler, e o cliente que só espia perderia o rastro do que ainda
  // não viu. A navegação não espera a resposta — o link leva embora e a
  // marcação chega sozinha.
  lista.addEventListener('click', function (e) {
    var item = e.target.closest('.sino-item');
    if (!item || !item.classList.contains('sino-item--nova')) return;

    item.classList.remove('sino-item--nova');
    enviar('/notificacoes/marcar-lida', { nu_id: item.getAttribute('data-nu') })
      .then(atualizarBadge);
  });

  btnTodas.addEventListener('click', function () {
    enviar('/notificacoes/marcar-todas', {}).then(function () {
      Array.prototype.forEach.call(
        lista.querySelectorAll('.sino-item--nova'),
        function (el) { el.classList.remove('sino-item--nova'); }
      );
      atualizarBadge();
    });
  });

  btnMais.addEventListener('click', function () { pagina += 1; carregar(true); });

  // ── Abrir e fechar ────────────────────────────────────
  function fechar() {
    aberto = false;
    painel.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    agendar();
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    aberto = !aberto;
    painel.hidden = !aberto;
    btn.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    if (aberto) { carregar(false); atualizarBadge(); }
    agendar();
  });

  document.addEventListener('click', function (e) {
    if (aberto && !e.target.closest('.sino-wrap')) fechar();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && aberto) { fechar(); btn.focus(); }
  });

  // ── Ritmo ─────────────────────────────────────────────
  function agendar() {
    clearTimeout(timer);
    if (!abaAtiva) return;
    timer = setTimeout(tick, aberto ? IV_ABERTO : IV_ATIVA);
  }

  function tick() {
    atualizarBadge();
    if (aberto) carregar(false);
    agendar();
  }

  document.addEventListener('visibilitychange', function () {
    abaAtiva = !document.hidden;
    if (abaAtiva) {
      atualizarBadge();
      if (aberto) carregar(false);
      agendar();
    } else {
      clearTimeout(timer);
    }
  });

  atualizarBadge();
  agendar();
}());
