<?php
/**
 * views/pagina-conteudo.php — corpo de uma página de conteúdo do banco.
 *
 * Recebe: $pagina (linha de `paginas`).
 *
 * O conteúdo sai SEM escape, de propósito: é HTML rico digitado no painel. A
 * segurança não está aqui e sim no HtmlHelper::sanitizeRich() do
 * PaginaService, que passa tudo pelo HTML Purifier antes de gravar. Escapar
 * aqui mostraria as tags na tela; não escapar sem sanitizar na gravação seria
 * XSS armazenado. É o segundo caso que importa, e ele está coberto.
 */
?>
<div class="container">
  <article class="pg-conteudo">
    <header class="pg-conteudo_topo">
      <h1><?= View::e($pagina['titulo']) ?></h1>
      <?php if (!empty($pagina['atualizado_em'])): ?>
        <p class="pg-conteudo_data">
          Atualizada em
          <time datetime="<?= View::e(date('Y-m-d', strtotime((string) $pagina['atualizado_em']))) ?>">
            <?= View::e(date('d/m/Y', strtotime((string) $pagina['atualizado_em']))) ?>
          </time>
        </p>
      <?php endif; ?>
    </header>

    <div class="pg-conteudo_corpo">
      <?= $pagina['conteudo'] ?>
    </div>
  </article>
</div>
