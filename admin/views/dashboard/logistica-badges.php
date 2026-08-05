<?php
/**
 * Badges de alerta da logística — para o header do dashboard.
 *
 * Usa as MESMAS classes do painel (`admin-alert-badge` /
 * `admin-alert-badge--warning`), então herda o visual dos alertas que já
 * existem ali (pendentes, não pagos, estoque baixo). Aparece só quando há
 * algo a tratar; com tudo zerado, não renderiza nada.
 *
 * Uso (no header do dashboard, dentro do <div style="display:flex;gap:8px;">):
 *   <?php include ADMIN_PATH . '/views/dashboard/logistica-badges.php'; ?>
 *
 * Os dados vêm de LogisticaService::badgesDashboard(). Se preferir não tocar
 * o banco na view, monte no controller e passe $logisticaBadges — este
 * partial usa essa variável quando ela existe.
 */

$logisticaBadges = $logisticaBadges
    ?? (class_exists('LogisticaService') ? (new LogisticaService())->badgesDashboard() : []);

if (!empty($logisticaBadges)):

    // Ícones vêm do helper central (LogisticaIcones). Fallback embutido caso
    // o helper não esteja carregado no contexto do dashboard.
    $logIcone = static function (string $k): string {
        if (class_exists('LogisticaIcones')) {
            return LogisticaIcones::svg($k, 13, 2.5);
        }
        $fb = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        return $fb;
    };
    ?>
    <?php foreach ($logisticaBadges as $b):
        $cls = 'admin-alert-badge' . (($b['tom'] ?? '') === 'warning' ? ' admin-alert-badge--warning' : '');
    ?>
    <a href="<?= BASE_URL ?><?= htmlspecialchars($b['url'], ENT_QUOTES, 'UTF-8') ?>"
       class="<?= $cls ?>"
       title="Logística">
        <?= $logIcone($b['icone'] ?? 'alerta') ?>
        <?= htmlspecialchars($b['rotulo'], ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php endforeach; ?>
<?php endif; ?>
