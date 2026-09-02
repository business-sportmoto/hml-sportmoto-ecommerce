<?php
/**
 * views/mapa-do-site.php — mapa do site para gente.
 *
 * Recebe: $secoes (do SitemapService).
 *
 * Seção sem item não é desenhada: um título "Marcas" seguido de nada só
 * comunica que algo quebrou.
 */
?>
<div class="container">
  <div class="mapa">

    <header class="mapa_topo">
      <h1>Mapa do site</h1>
      <p>Tudo o que existe na loja, em uma página.</p>
    </header>

    <?php foreach ($secoes as $sec): ?>
      <?php if (empty($sec['itens'])) continue; ?>
      <section class="mapa_secao">
        <h2><?= View::e($sec['titulo']) ?> <span><?= count($sec['itens']) ?></span></h2>
        <ul class="mapa_lista">
          <?php foreach ($sec['itens'] as $i): ?>
            <li>
              <a href="<?= View::e($i['url']) ?>"><?= View::e($i['label']) ?></a>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endforeach; ?>

  </div>
</div>
