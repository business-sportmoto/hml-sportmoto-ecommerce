<?php $allIcons = IconLibrary::getAll(); ?>

<div class="icon-finder-modal" id="iconFinderModal" aria-hidden="true">
    <div class="icon-finder-backdrop js-close-icon-finder"></div>

    <div class="icon-finder-dialog">
        <div class="icon-finder-header">
            <h3>Biblioteca de ícones</h3>
            <button type="button" class="icon-finder-close js-close-icon-finder">×</button>
        </div>

        <button type="button" class="btn-add-icon-toggle">
            + Adicionar ícone
        </button>

        <div class="add-icon-box" style="display:none;">
            <input type="text" id="newIconKey" placeholder="Key: ex: favorite">
            <input type="text" id="newIconLabel" placeholder="Nome: ex: Favorito">
            <input type="text" id="newIconTags" placeholder="Tags: coração, favorito, wishlist">

            <textarea id="newIconSvg" placeholder="Cole aqui o SVG completo"></textarea>

            <button type="button" id="btnSaveNewIcon">
                Salvar ícone
            </button>

            <div id="newIconFeedback"></div>
        </div>

        <div class="icon-finder-toolbar">
            <input type="text" id="iconFinderSearch" class="icon-finder-search" placeholder="Buscar por nome, tag ou key...">
        </div>

        <div class="icon-finder-grid" id="iconFinderGrid">
            <?php foreach ($allIcons as $icon): ?>
                <button
                    type="button"
                    class="icon-finder-item"
                    data-key="<?= htmlspecialchars($icon['key'], ENT_QUOTES, 'UTF-8') ?>"
                    data-label="<?= htmlspecialchars($icon['label'] ?? $icon['key'], ENT_QUOTES, 'UTF-8') ?>"
                    data-svg="<?= htmlspecialchars($icon['svg'], ENT_QUOTES, 'UTF-8') ?>"
                    data-tags="<?= htmlspecialchars(implode(' ', $icon['tags'] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                >
                    <span class="icon-finder-item-svg"><?= $icon['svg'] ?></span>
                    <span class="icon-finder-item-label"><?= htmlspecialchars($icon['label'] ?? $icon['key'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="icon-finder-item-key"><?= htmlspecialchars($icon['key'], ENT_QUOTES, 'UTF-8') ?></span>

                    
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div id="iconContextMenu" class="icon-context-menu">
    <button data-action="edit">✏️ Editar</button>
    <button data-action="delete">🗑️ Excluir</button>
    <button data-action="copy">📋 Copiar key</button>
</div>