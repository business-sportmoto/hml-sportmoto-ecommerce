<?php
// admin/views/errors/403.php
//
// Negação de permissão em navegação normal. O caminho Ajax não passa por
// aqui — o AuthHelper responde JSON antes, porque um $.post que recebe
// HTML quebra no parse e esconde o motivo real (CLAUDE.md §4.8.5).
//
// Deliberadamente NÃO diz qual cargo seria necessário nem o que existe do
// outro lado: confirmar o que há atrás da porta já é informação para quem
// não deveria passar.
?>
<div class="erro-pagina">
  <div class="erro-card">
    <div class="erro-icone"><?= IconLibrary::render('lock') ?></div>

    <h1 class="erro-titulo">Sem permissão</h1>

    <p class="erro-texto">
      Seu cargo não dá acesso a esta área do painel.
      Se você precisa dela para trabalhar, peça a um administrador.
    </p>

    <div class="erro-acoes">
      <a class="btn btn-primary" href="<?= ADMIN_URL ?>">Voltar ao painel</a>
    </div>
  </div>
</div>

<style>
/* Escopo próprio: o layout minimal não carrega a folha de estilo das
   telas internas, e esta página precisa se sustentar sozinha. */
.erro-pagina {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: #f1f5f9;
  font-family: Inter, system-ui, -apple-system, Segoe UI, Arial, sans-serif;
}
.erro-card {
  max-width: 460px;
  width: 100%;
  background: #fff;
  border-radius: 14px;
  padding: 40px 32px;
  text-align: center;
  box-shadow: 0 1px 3px rgba(15, 23, 42, .1), 0 8px 24px rgba(15, 23, 42, .06);
}
.erro-icone {
  width: 56px;
  height: 56px;
  margin: 0 auto 20px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #fef2f2;
  color: #dc2626;
}
.erro-icone svg { width: 26px; height: 26px; }
.erro-titulo {
  margin: 0 0 10px;
  font-size: 21px;
  font-weight: 700;
  color: #0f172a;
}
.erro-texto {
  margin: 0 0 26px;
  font-size: 14.5px;
  line-height: 1.6;
  color: #64748b;
}
.erro-acoes .btn {
  display: inline-block;
  padding: 10px 22px;
  border-radius: 8px;
  background: #2563eb;
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
}
.erro-acoes .btn:hover { background: #1d4ed8; }
</style>
