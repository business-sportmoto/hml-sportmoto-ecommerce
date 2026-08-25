<?php
// views/home/index.php — Página inicial completa
?>

<!-- ── Hero Banner ────────────────────────────────────────── -->
<?= View::partial('partials/banner-render', ['zona' => 'home_hero']); ?>


<!-- ── Benefícios ──────────────────────────────────────────── -->
 <?php View::partial('partials/benefits-slider') ?>


<?php View::partial('partials/hero-busca-moto') ?>
<?php View::partial('partials/home-sections', ['sections' => $sectionPorHistorico,'sl_container'=>'porHistorico']) ?>
<?php 
//View::partial('partials/clips-em-alta') 
?>

<?php View::partial('partials/clips-home-section') ?>

<?php View::partial('partials/home-sections', ['sections' => $sectionPorClips]) ?>

<!-- ── Categorias em destaque ──────────────────────────────── -->
<?php if (!empty($categorias)): ?>
<section class="section categories-section">
  <div class="container">
    <div class="section-header">
      <div>
        <span class="section-eyebrow">Explore</span>
        <h2 class="section-title">Categorias em destaque</h2>
        <div class="section-subtitle">Tudo que você precisa, organizado para você encontrar rápido.</div>
      </div>
    </div>
    <div class="categories-grid">
      <?php foreach ($categorias as $cat): ?>
      <a href="<?= BASE_URL ?>/categoria/<?= View::e($cat['slug']) ?>"
         class="category-card">
        <?php if (!empty($cat['imagem'])): ?>
          <div class="category-card-img">
            <img src="<?= View::upload('categories/' . $cat['imagem']) ?>"
                 alt="<?= View::e($cat['nome']) ?>" loading="lazy">
          </div>
        <?php else: ?>
          <div class="category-card-img category-card-img--placeholder"></div>
        <?php endif; ?>
        <span class="category-card-name"><?= View::e($cat['nome']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<div class="banner-min-home container">
<?php 
// View::partial('partials/home-melhores-avaliados') 
View::partial('partials/banner-render', ['zona' => 'home_min_1']);
?>
</div>

<!-- ── Produtos em destaque ────────────────────────────────── -->
<?php if (!empty($lancamentos)): ?>
<?php View::partial('partials/home-sections', ['sections' => $lancamentos,'sl_container'=>'lancamentos']) ?>
<?php endif; ?>

<?php if (!empty($produtos_destaque)): ?>
<?php View::partial('partials/home-sections', ['sections' => $produtos_destaque,'sl_container'=>'produtos_destaque']) ?>
<?php endif; ?>

<!-- ── Banner do meio ──────────────────────────────────────── -->
<?php if (!empty($banners_meio)): ?>
<section class="mid-banners-section">
  <div class="container">
    <div class="mid-banners-grid mid-banners-grid--<?= min(count($banners_meio), 3) ?>">
      <?php foreach (array_slice($banners_meio, 0, 3) as $banner): ?>
      <a href="<?= !empty($banner['link']) ? View::e($banner['link']) : '#' ?>"
         class="mid-banner" target="<?= View::e($banner['link_target']) ?>">
        <img src="<?= View::upload('banners/' . $banner['imagem']) ?>"
             alt="<?= View::e($banner['titulo'] ?? '') ?>" loading="lazy">
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<div class="banner-fine-home container">
<?php 
// View::partial('partials/home-melhores-avaliados') 
View::partial('partials/banner-render', ['zona' => 'banner_duplo_home']);
?>
</div>
<!-- ── Baseado nos favoritos  ───────────────────────────────────────────── -->
<?php if (!empty($produtos_promocao)): ?>
<?php View::partial('partials/home-sections', ['sections' => $produtos_promocao, 'sl_container'=>'produtos_promocao']) ?>
<?php endif; ?>
<!-- ── Promoções ───────────────────────────────────────────── -->
<?php View::partial('partials/home-sections', ['sections' => $sectionPorFavoritos]) ?>
<div class="banner-triple-home container">
<?php 
// View::partial('partials/home-melhores-avaliados') 
View::partial('partials/banner-render', ['zona' => 'home_mid_2']);
?>
</div>

<!-- ── Mais vendidos ───────────────────────────────────────── -->
<?php if (!empty($mais_vendidos)): ?>

<?php View::partial('partials/home-sections', ['sections' => $mais_vendidos, 'sl_container'=>'produtos_promocao', 'menu_grid'=> $categorias]) ?>  


<?php endif; ?>

<div class="banner-fine-home container">
<?php 
// View::partial('partials/home-melhores-avaliados') 
View::partial('partials/banner-render', ['zona' => 'home_mid_1']);
?>
</div>

<?php View::partial('partials/home-sections', ['sections' => $sectionFavoritos]) ?>

<!-- ── Depoimentos ─────────────────────────────────────────── -->
<?php if (!empty($depoimentos)): ?>
<section class="section testimonials-section">
  <div class="container">
    <div class="section-header section-header--center">
      <h2 class="section-title">O que nossos clientes dizem</h2>
      <p class="section-subtitle">Mais de 10.000 clientes satisfeitos</p>
    </div>
    <div class="testimonials-grid">
      <?php foreach ($depoimentos as $dep): ?>
      <div class="testimonial-card">
        <div class="testimonial-stars">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <span class="star <?= $i <= (int)$dep['nota'] ? 'star--filled' : '' ?>">★</span>
          <?php endfor; ?>
        </div>
        <blockquote class="testimonial-text"><?= View::e($dep['texto']) ?></blockquote>
        <div class="testimonial-author">
          <?php if (!empty($dep['foto'])): ?>
            <img src="<?= View::upload('testimonials/' . $dep['foto']) ?>"
                 alt="<?= View::e($dep['nome']) ?>" class="testimonial-avatar" loading="lazy">
          <?php else: ?>
            <div class="testimonial-avatar testimonial-avatar--initial">
              <?= strtoupper(mb_substr($dep['nome'], 0, 1)) ?>
            </div>
          <?php endif; ?>
          <div class="testimonial-author-info">
            <strong><?= View::e($dep['nome']) ?></strong>
            <?php if (!empty($dep['cargo'])): ?>
              <span><?= View::e($dep['cargo']) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>


<!-- Configurada -->
<?php View::partial('partials/nossos-clientes', [
    'titulo'    => 'A nossa comunidade',
    'subtitulo' => 'Mais de 2.000 motociclistas já estão com a gente',
    'limite'    => 12,
    'dark'      => true,
    'mostrarCta'=> true,
    'ctaLabel'  => 'Envie sua foto',
]) ?>



<?php View::partial('partials/home-sections', ['sections' => $sectionPorBuscas]) ?>
<?php View::partial('partials/home-sections', ['sections' => $sectionPorCarrinho]) ?>

<!-- Após os banners hero -->
<?php View::partial('partials/brands-section') ?>
<?php View::partial('partials/home-sections', ['sections' => $sectionPorMarcas]) ?>


