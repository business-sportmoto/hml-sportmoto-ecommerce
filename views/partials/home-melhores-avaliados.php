<?php
// ════════════════════════════════════════════════════════
// views/partials/home-melhores-avaliados.php
// Seção "Melhores Avaliados" para a home
//
// Estratégia:
//   1. Puxa produtos com nota_media >= 4.0 e total_avaliacoes >= 2
//   2. Ordena por nota DESC, total DESC
//   3. Embaralha dentro de faixas (5★, 4.5★, 4★) pra não fixar a mesma ordem
//   4. Pega até 12 para exibir carrossel/grade
// ════════════════════════════════════════════════════════

$db    = Database::getInstance()->getConnection();
$stmt  = $db->query(
    "SELECT
        p.id, p.nome, p.slug, p.preco, p.preco_promo,
        p.nota_media, p.total_avaliacoes,
        pi.arquivo AS img_capa
     FROM produtos p
     LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
     WHERE p.ativo = 1
       AND p.deleted_at IS NULL
       AND p.nota_media  >= 4.0
       AND p.total_avaliacoes >= 2
     ORDER BY p.nota_media DESC, p.total_avaliacoes DESC
     LIMIT 36"
);
$pool = $stmt->fetchAll();

if (empty($pool)) return;

// Embaralha dentro de faixas pra ter variedade visual
$grupos = ['5.0' => [], '4.5' => [], '4.0' => []];
foreach ($pool as $p) {
    $n = (float)$p['nota_media'];
    if ($n >= 4.75)      $grupos['5.0'][] = $p;
    elseif ($n >= 4.25)  $grupos['4.5'][] = $p;
    else                 $grupos['4.0'][] = $p;
}
foreach ($grupos as &$g) shuffle($g);

// Intercala grupos pra grade parecer variada mas dominada pelos melhores
$produtos = [];
$i = 0;
while (count($produtos) < 12 && ($grupos['5.0'] || $grupos['4.5'] || $grupos['4.0'])) {
    foreach (['5.0','4.5','4.0'] as $faixa) {
        if (count($produtos) >= 12) break;
        if ($grupos[$faixa]) $produtos[] = array_shift($grupos[$faixa]);
    }
}
?>

<section class="home-best-rated" id="home-best-rated">
  <div class="container">

    <div class="home-br-header">
      <div>
        <span class="home-br-kicker">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="#f59e0b">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
          Aprovados pelos clientes
        </span>
        <h2 class="home-br-title">Melhores avaliados</h2>
      </div>
      <a href="<?= BASE_URL ?>/busca?ordenar=avaliacao" class="home-br-link">
        Ver todos
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="5" y1="12" x2="19" y2="12"/>
          <polyline points="12 5 19 12 12 19"/>
        </svg>
      </a>
    </div>

    <!-- Carrossel horizontal no mobile, grade no desktop -->
    <div class="home-br-grid">
      <?php foreach ($produtos as $p):
        $preco     = (float)$p['preco'];
        $promo     = (float)($p['preco_promo'] ?? 0);
        $precoFmt  = 'R$ ' . number_format($preco, 2, ',', '.');
        $promoFmt  = $promo > 0 ? 'R$ ' . number_format($promo, 2, ',', '.') : null;
        $imgUrl    = $p['img_capa']
                   ? UPLOAD_URL . '/products/' . $p['img_capa']
                   : UPLOAD_URL . '/placeholder.webp';
        $media     = round((float)$p['nota_media'], 1);
        $total     = (int)$p['total_avaliacoes'];
        $mediaFmt  = number_format($media, 1, ',', '');
        // Largura de preenchimento da barra (0–5 = 0–100%)
        $pct       = min(100, ($media / 5) * 100);
      ?>

      <a href="<?= BASE_URL ?>/produto/<?= View::e($p['slug']) ?>"
         class="home-br-card">

        <!-- Imagem -->
        <div class="home-br-img">
          <img src="<?= View::e($imgUrl) ?>"
               alt="<?= View::e($p['nome']) ?>"
               loading="lazy">

          <!-- Badge de nota flutuante -->
          <div class="home-br-score-badge" title="<?= $mediaFmt ?> de 5">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
            <?= $mediaFmt ?>
          </div>
        </div>

        <!-- Info -->
        <div class="home-br-info">
          <p class="home-br-nome"><?= View::e(mb_substr($p['nome'], 0, 55)) ?></p>

          <!-- Mini barra de avaliação -->
          <div class="home-br-bar-wrap">
            <div class="home-br-bar">
              <div class="home-br-bar-fill" style="width:<?= $pct ?>%"></div>
            </div>
            <span class="home-br-count"><?= $total ?></span>
          </div>

          <!-- Preço -->
          <div class="home-br-preco">
            <?php if ($promoFmt): ?>
            <span class="home-br-preco-de"><?= $precoFmt ?></span>
            <strong class="home-br-preco-promo"><?= $promoFmt ?></strong>
            <?php else: ?>
            <strong class="home-br-preco-normal"><?= $precoFmt ?></strong>
            <?php endif; ?>
          </div>
        </div>

      </a>
      <?php endforeach; ?>
    </div>

  </div>
</section>