<?php
/**
 * View: app/views/help/categoria.php
 *
 * $categoria  — array da categoria atual
 * $perguntas  — array de perguntas ativas
 * $categorias — todas as categorias ativas (sidebar)
 */
?>

<div class="hc-cat-hero">
    <div class="hc-cat-hero-inner">
        <nav class="hc-breadcrumb" aria-label="Navegação estrutural">
            <a href="/ajuda">
                <i class="bi bi-house-door" aria-hidden="true"></i>
                Central de Ajuda
            </a>
            <span class="hc-breadcrumb-sep" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
            <span><?= htmlspecialchars($categoria['nome']) ?></span>
        </nav>

        <h1>
            <i class="bi <?= htmlspecialchars($categoria['icone']) ?>" aria-hidden="true"></i>
            <?= htmlspecialchars($categoria['nome']) ?>
        </h1>
        <?php if (!empty($categoria['descricao'])): ?>
            <p><?= htmlspecialchars($categoria['descricao']) ?></p>
        <?php endif; ?>
    </div>
    <div class="hc-cat-hero-wave"></div>
</div>

<div class="hc-cat-layout">

    <!-- Sidebar -->
    <aside class="hc-sidebar" aria-label="Categorias">
        <div class="hc-sidebar-label">Categorias</div>
        <?php foreach ($categorias as $cat): ?>
        <a
            href="/ajuda/categoria/<?= $cat['slug'] ?>"
            class="hc-sidebar-link <?= $cat['slug'] === $categoria['slug'] ? 'is-active' : '' ?>"
            <?= $cat['slug'] === $categoria['slug'] ? 'aria-current="page"' : '' ?>
        >
            <i class="bi <?= htmlspecialchars($cat['icone']) ?>" aria-hidden="true">
                <?= IconLibrary::render($cat['icone']) ?>
            </i>
            <?= htmlspecialchars($cat['nome']) ?>
        </a>
        <?php endforeach; ?>

        <hr class="hc-sidebar-divider">
        <a href="/ajuda" class="hc-sidebar-link">
            <i class="bi bi-arrow-left" aria-hidden="true">
                <?= IconLibrary::render('arrow-back') ?>
            </i>
            Todas as categorias
        </a>
    </aside>

    <!-- Conteúdo -->
    <main>
        <div class="hc-cat-main-head">
            <div class="hc-cat-main-icon" aria-hidden="true">
                <i class="bi">
                    <?= IconLibrary::render($categoria['icone']); ?>
                </i>
            </div>
            <div>
                <h2><?= htmlspecialchars($categoria['nome']) ?></h2>
                <p>
                    <?= count($perguntas) ?> pergunta<?= count($perguntas) != 1 ? 's' : '' ?> nesta categoria
                </p>
            </div>
        </div>

        <?php if (empty($perguntas)): ?>
            <div class="hc-empty">
                <i class="bi bi-chat-square-dots" aria-hidden="true">
                    <?= IconLibrary::render('chat-dashed') ?>
                </i>
                Nenhuma pergunta disponível nesta categoria ainda.
            </div>
        <?php else: ?>
            <div class="hc-faq-list">
                <?php foreach ($perguntas as $i => $p): ?>
                <div class="hc-faq-item <?= $i === 0 ? 'is-open' : '' ?>">
                    <button
                        class="hc-faq-trigger"
                        type="button"
                        aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>"
                        aria-controls="hccat-<?= $p['id'] ?>"
                    >
                        <span><?= htmlspecialchars($p['pergunta']) ?></span>
                        <span class="hc-faq-icon" aria-hidden="true">
                            <i class="bi bi-chevron-down">
                                <?= IconLibrary::render('key-arrpw-down') ?>
                            </i>
                        </span>
                    </button>
                    <div class="hc-faq-body" id="hccat-<?= $p['id'] ?>" role="region">
                        <?= $p['resposta'] ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Feedback -->
        <div class="hc-feedback" id="hcFeedback" role="region" aria-label="Avaliação desta página">
            <p>Esta página foi útil?</p>
            <div class="hc-feedback-btns">
                <button class="hc-feedback-btn" type="button" onclick="hcVote(true)">
                    <i class="bi bi-hand-thumbs-up" aria-hidden="true"></i> Sim
                </button>
                <button class="hc-feedback-btn" type="button" onclick="hcVote(false)">
                    <i class="bi bi-hand-thumbs-down" aria-hidden="true"></i> Não
                </button>
            </div>
        </div>
    </main>

</div>

<script>
(function () {
    // Accordion independente (cada item abre/fecha por conta própria)
    document.querySelectorAll('.hc-cat-layout .hc-faq-trigger').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            var item   = trigger.closest('.hc-faq-item');
            var isOpen = item.classList.contains('is-open');
            item.classList.toggle('is-open', !isOpen);
            trigger.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
        });
    });
})();

function hcVote(positivo) {
    var box = document.getElementById('hcFeedback');
    box.innerHTML = '<p style="color:var(--hc-blue);font-weight:500">'
        + '<i class="bi bi-check-circle me-1"></i>'
        + (positivo ? 'Obrigado pelo feedback!' : 'Anotado. Vamos melhorar esta página.')
        + '</p>';
}
</script>