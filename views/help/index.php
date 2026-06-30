<?php
/**
 * View: app/views/help/index.php
 *
 * $categorias — array de categorias ativas com total_perguntas
 * $agrupadas  — array slug => [ perguntas ] (vazio em modo busca)
 * $termo      — string de busca sanitizada (pode ser vazio)
 * $resultados — array de perguntas encontradas (modo busca)
 */
?>

<div class="hc-hero">
    <div class="hc-hero-inner">
        <div class="hc-hero-eyebrow">Central de ajuda</div>
        <h1>Como podemos te ajudar?</h1>
        <p>Encontre respostas sobre pedidos, peças, pagamento e muito mais.</p>

        <form action="/ajuda/busca" method="GET" role="search">
            <div class="hc-search">
                <span class="hc-search-icon"><i class="bi bi-search">
                    <?= IconLibrary::render('help') ?>
                </i></span>
                <input
                    type="search"
                    name="q"
                    id="hcSearchInput"
                    placeholder="Ex: como rastrear meu pedido…"
                    value="<?= htmlspecialchars($termo) ?>"
                    autocomplete="off"
                    minlength="3"
                    aria-label="Buscar na central de ajuda"
                >
                <button type="submit">Buscar</button>
            </div>
        </form>
    </div>
    <div class="hc-hero-wave"></div>
</div>

<div class="hc-wrap">

    <!-- Grid de categorias — sempre visível -->
    <div class="hc-cats" role="list">
        <?php foreach ($categorias as $cat): ?>
        <a href="/ajuda/categoria/<?= $cat['slug'] ?>" class="hc-cat-card" role="listitem">
            <div class="hc-cat-icon" aria-hidden="true">
                <i class="bi"> 
                    <?= IconLibrary::render($cat['icone']) ?>
                </i>
            </div>
            <div class="hc-cat-name"><?= htmlspecialchars($cat['nome']) ?></div>
            <div class="hc-cat-count"><?= (int)$cat['total_perguntas'] ?> pergunta<?= $cat['total_perguntas'] != 1 ? 's' : '' ?></div>
            <i class="bi bi-arrow-up-right hc-cat-arrow" aria-hidden="true">
                <?= IconLibrary::render('arrow-forward') ?>
            </i>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- ── MODO BUSCA ───────────────────────────────────── -->
    <?php if ($termo !== ''): ?>

    <div class="hc-search-results is-visible" role="region" aria-label="Resultados da busca">
        <div class="hc-results-heading">
            <?php if (empty($resultados)): ?>
                Nenhum resultado para <strong>"<?= $termo ?>"</strong>
            <?php else: ?>
                <strong><?= count($resultados) ?></strong> resultado<?= count($resultados) != 1 ? 's' : '' ?> para <strong>"<?= $termo ?>"</strong>
            <?php endif; ?>
        </div>

        <?php if (empty($resultados)): ?>
            <div class="hc-empty">
                <i class="bi bi-search" aria-hidden="true"></i>
                Tente outras palavras-chave ou navegue pelas categorias acima.
            </div>
        <?php else: ?>
            <?php foreach ($resultados as $r): ?>
            <a href="/ajuda/categoria/<?= $r['categoria_slug'] ?>" class="hc-result-item">
                <div class="hc-result-cat">
                    <i class="bi <?= htmlspecialchars($r['categoria_icone']) ?>" aria-hidden="true"></i>
                    <?= htmlspecialchars($r['categoria_nome']) ?>
                </div>
                <div class="hc-result-q"><?= htmlspecialchars($r['pergunta']) ?></div>
                <div class="hc-result-preview"><?= htmlspecialchars(mb_strimwidth(strip_tags($r['resposta']), 0, 180, '…')) ?></div>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ── MODO PADRÃO: FAQ AGRUPADO ───────────────────── -->
    <?php else: ?>

        <?php foreach ($categorias as $cat):
            $slug = $cat['slug'];
            $pergs = $agrupadas[$slug] ?? [];
            if (empty($pergs)) continue;
        ?>
        <div class="hc-faq-group">
            <div class="hc-group-title">
                <i class="bi <?= htmlspecialchars($cat['icone']) ?>" aria-hidden="true"></i>
                <?= htmlspecialchars($cat['nome']) ?>
            </div>
            <div class="hc-faq-list">
                <?php foreach ($pergs as $p): ?>
                <div class="hc-faq-item">
                    <button
                        class="hc-faq-trigger"
                        type="button"
                        aria-expanded="false"
                        aria-controls="hcfaq-<?= $p['id'] ?>"
                    >
                        <span><?= htmlspecialchars($p['pergunta']) ?></span>
                        <span class="hc-faq-icon" aria-hidden="true">
                            <i class="bi bi-chevron-down"></i>
                        </span>
                    </button>
                    <div class="hc-faq-body" id="hcfaq-<?= $p['id'] ?>" role="region">
                        <?= $p['resposta'] ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

    <?php endif; ?>

</div>

<script>
(function () {
    function initAccordions(selector, multiOpen) {
        document.querySelectorAll(selector).forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var item    = trigger.closest('.hc-faq-item');
                var isOpen  = item.classList.contains('is-open');

                if (!multiOpen) {
                    var list = trigger.closest('.hc-faq-list');
                    list.querySelectorAll('.hc-faq-item.is-open').forEach(function (i) {
                        i.classList.remove('is-open');
                        i.querySelector('.hc-faq-trigger').setAttribute('aria-expanded', 'false');
                    });
                }

                if (!isOpen) {
                    item.classList.add('is-open');
                    trigger.setAttribute('aria-expanded', 'true');
                }
            });
        });
    }

    initAccordions('.hc-faq-trigger', false);
})();
</script>