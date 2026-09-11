<?php
// ════════════════════════════════════════════════════════
// views/partials/sino-notificacoes.php
//
// O sino do SITE, que le a caixa do CLIENTE em /notificacoes/*. O painel
// tem o seu, em admin/views/layouts/admin.php, lendo a caixa do ADMIN em
// /admin/notificacoes/*. Ver 03-funcionalidades/Notificacoes Controller.
//
// Só renderiza para cliente logado: sem sessão não há destinatário, e o
// contador responderia 0 eternamente. Quem não está logado nem vê o botão.
// ════════════════════════════════════════════════════════

if (!Session::isClienteLogado()) return;

// Base e token saem DAQUI, não de global do layout.
//
// Os layouts declaram `const BASE_URL` / `const CSRF_TOKEN` num <script>
// clássico — e `const` no topo de script clássico cria binding léxico global,
// que NÃO aparece em `window`. Pior: em views/layouts/main.php o token vem de
// `$csrf_token ?? ''`, variável que nenhum controller passa — ou seja, string
// vazia, e todo POST que dependesse dela tomaria 403.
//
// Emitir aqui torna o sino independente de qual layout o renderizou.
$sinoCsrf = SecurityHelper::generateCsrf();
?>
<div class="sino-wrap"
     data-base="<?= View::e(rtrim(BASE_URL, '/')) ?>"
     data-csrf="<?= View::e($sinoCsrf) ?>">
  <button type="button" class="header-action sino-btn" id="sino-btn"
          aria-label="Notificações" aria-expanded="false" aria-haspopup="true">
    <span class="sino-icon-wrap">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
      </svg>
      <span class="sino-badge" id="sino-badge" hidden>0</span>
    </span>
    <span class="action-label">Avisos</span>
  </button>

  <div class="sino-painel" id="sino-painel" hidden role="dialog" aria-label="Suas notificações">
    <div class="sino-head">
      <strong>Notificações</strong>
      <button type="button" class="sino-link" id="sino-marcar-todas">Marcar todas como lidas</button>
    </div>

    <div class="sino-lista" id="sino-lista">
      <p class="sino-vazio">Carregando…</p>
    </div>

    <div class="sino-foot">
      <button type="button" class="sino-link" id="sino-mais" hidden>Carregar mais</button>
    </div>
  </div>
</div>
