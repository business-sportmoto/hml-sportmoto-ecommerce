<?php
/**
 * View: Dashboard Analytics Power BI Style
 *
 * Arquivo feito para ser incluído dentro do layout admin do seu MVC.
 * Não possui <html>, <head> ou <body>.
 * CSS usa apenas classes com prefixo pwb_.
 */

if (!function_exists('pwb_e')) {
    function pwb_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pwb_badge_class')) {
    function pwb_badge_class(string $status): string
    {
        $normalized = mb_strtolower($status, 'UTF-8');

        if (str_contains($normalized, 'entregue') || str_contains($normalized, 'ativo') || str_contains($normalized, 'vip')) {
            return 'pwb_badge_success';
        }

        if (str_contains($normalized, 'trânsito') || str_contains($normalized, 'frequente')) {
            return 'pwb_badge_primary';
        }

        if (str_contains($normalized, 'baixo') || str_contains($normalized, 'pendente') || str_contains($normalized, 'ocasional')) {
            return 'pwb_badge_warning';
        }

        if (str_contains($normalized, 'crítico') || str_contains($normalized, 'sem estoque') || str_contains($normalized, 'cancelado')) {
            return 'pwb_badge_danger';
        }

        return 'pwb_badge_default';
    }
}

if (!function_exists('pwb_icon')) {
    function pwb_icon(string $name): string
    {
        $icons = [
            'dashboard' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 0h7v7h-7v-7Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
            'cart' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.5L21 8H6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" fill="currentColor"/></svg>',
            'box' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M4.5 7.7 12 12l7.5-4.3M12 12v8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
            'users' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M16 19a4 4 0 0 0-8 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M12 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM20 18a3.4 3.4 0 0 0-4-3.3M16.5 5.6a2.5 2.5 0 0 1 0 4.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
            'access' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M5 20V10M12 20V4M19 20v-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M4 20h17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
            'ai' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M8 8V6m8 2V6M7 12h10M8 16h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M6 8h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8"/><path d="M9 4h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
            'faq' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M4 5h16v12H8l-4 4V5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
            'stock' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M5 9.5 12 5l7 4.5v9L12 23 5 18.5v-9Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 14v7M5.5 10 12 14l6.5-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
            'settings' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" stroke="currentColor" stroke-width="1.8"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 0 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1A2 2 0 0 1 4.2 17l.1-.1A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.6-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.3 7A2 2 0 0 1 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1A2 2 0 0 1 19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.1a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.6 1Z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'currency' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M12 3v18M16.5 7.5H10a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6H7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
            'trend' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M4 16 9 11l4 4 7-8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 7h5v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'eye' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="1.8"/><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="currentColor" stroke-width="1.8"/></svg>',
            'percent' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="m19 5-14 14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M8 8.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm8 11a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" stroke="currentColor" stroke-width="1.8"/></svg>',
            'alert' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M12 4 21 20H3L12 4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 10v4M12 17h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
            'search' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="m21 21-4.2-4.2M10.8 18a7.2 7.2 0 1 0 0-14.4 7.2 7.2 0 0 0 0 14.4Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
            'refresh' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M20 12a8 8 0 0 1-14.7 4.4M4 12A8 8 0 0 1 18.7 7.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M6 20v-4H2M18 4v4h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'bell' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M18 9a6 6 0 1 0-12 0c0 7-3 7-3 7h18s-3 0-3-7Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 20a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
            'calendar' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="M5 5h14a2 2 0 0 1 2 2v14H3V7a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8"/><path d="M8 3v4M16 3v4M3 10h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
            'chevron' => '<svg class="pwb_icon_svg" viewBox="0 0 24 24" fill="none"><path d="m15 18-6-6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        ];

        return $icons[$name] ?? $icons['dashboard'];
    }
}

if (!function_exists('pwb_render_kpi')) {
    function pwb_render_kpi(array $kpi): void
    {
        $type = $kpi['type'] ?? 'default';
        $cardClass = 'pwb_kpi_card pwb_kpi_' . preg_replace('/[^a-z0-9_-]/i', '', $type);
        ?>
        <article class="<?= pwb_e($cardClass) ?>">
            <div class="pwb_kpi_content">
                <span class="pwb_kpi_label"><?= pwb_e($kpi['label'] ?? '') ?></span>
                <strong class="pwb_kpi_value"><?= pwb_e($kpi['value'] ?? '') ?></strong>
                <?php if (!empty($kpi['trend'])): ?>
                    <span class="pwb_kpi_trend">
                        <?= pwb_icon('trend') ?>
                        <span class="pwb_kpi_trend_value"><?= pwb_e($kpi['trend']) ?></span>
                        <span class="pwb_kpi_trend_label"><?= pwb_e($kpi['trend_label'] ?? '') ?></span>
                    </span>
                <?php endif; ?>
            </div>
            <span class="pwb_kpi_icon"><?= pwb_icon($kpi['icon'] ?? 'dashboard') ?></span>
        </article>
        <?php
    }
}

if (!function_exists('pwb_render_metric_card')) {
    function pwb_render_metric_card(array $item): void
    {
        ?>
        <article class="pwb_metric_card">
            <span class="pwb_metric_label"><?= pwb_e($item['label'] ?? '') ?></span>
            <strong class="pwb_metric_value"><?= pwb_e($item['value'] ?? '') ?></strong>
            <span class="pwb_metric_hint"><?= pwb_e($item['hint'] ?? '') ?></span>
        </article>
        <?php
    }
}

$pwb_dashboard_data = $pwb_dashboard_data ?? [];

if (!$pwb_dashboard_data && class_exists('PwbDashboardAnalyticsService')) {
    $pwb_dashboard_data = (new PwbDashboardAnalyticsService())->getDashboardData();
}

$pwb_dashboard_config = array_replace([
    'api_url' => '',
    'settings_url' => '#',
    'user_initials' => 'AD',
    'search_placeholder' => 'Buscar...',
], $pwb_dashboard_config ?? []);

$pwb_kpis = $pwb_dashboard_data['kpis'] ?? [];
$pwb_tables = $pwb_dashboard_data['tables'] ?? [];
$pwb_metrics = $pwb_dashboard_data['metrics'] ?? [];
$pwb_meta = $pwb_dashboard_data['meta'] ?? [];
?>

<div class="pwb_dashboard" data-pwb-api-url="<?= pwb_e($pwb_dashboard_config['api_url']) ?>">
    <script type="application/json" id="pwb_dashboard_payload"><?= json_encode($pwb_dashboard_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

    <aside class="pwb_sidebar">
        <div class="pwb_brand">
            <span class="pwb_brand_icon"><?= pwb_icon('access') ?></span>
            <strong class="pwb_brand_text">Analytics</strong>
            <button class="pwb_sidebar_toggle" type="button" aria-label="Recolher menu"><?= pwb_icon('chevron') ?></button>
        </div>

        <nav class="pwb_nav" aria-label="Navegação do dashboard">
            <button class="pwb_nav_item pwb_nav_active" type="button" data-pwb-view="overview"><?= pwb_icon('dashboard') ?><span class="pwb_nav_label">Visão Geral</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="orders"><?= pwb_icon('cart') ?><span class="pwb_nav_label">Pedidos</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="products"><?= pwb_icon('box') ?><span class="pwb_nav_label">Produtos</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="customers"><?= pwb_icon('users') ?><span class="pwb_nav_label">Clientes</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="access"><?= pwb_icon('access') ?><span class="pwb_nav_label">Acessos</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="ai"><?= pwb_icon('ai') ?><span class="pwb_nav_label">Uso de IA</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="faq"><?= pwb_icon('faq') ?><span class="pwb_nav_label">Perguntas</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="stock"><?= pwb_icon('stock') ?><span class="pwb_nav_label">Estoque</span></button>
        </nav>

        <a class="pwb_sidebar_link" href="<?= pwb_e($pwb_dashboard_config['settings_url']) ?>"><?= pwb_icon('settings') ?><span class="pwb_nav_label">Configurações</span></a>
    </aside>

    <main class="pwb_main">
        <header class="pwb_header">
            <div class="pwb_header_title_box">
                <h1 class="pwb_title"><?= pwb_e($pwb_meta['title'] ?? 'Visão Geral') ?></h1>
                <p class="pwb_subtitle"><?= pwb_e($pwb_meta['subtitle'] ?? 'Resumo completo do seu e-commerce') ?></p>
            </div>

            <div class="pwb_header_actions">
                <label class="pwb_search_box">
                    <?= pwb_icon('search') ?>
                    <input class="pwb_search_input" type="search" placeholder="<?= pwb_e($pwb_dashboard_config['search_placeholder']) ?>" data-pwb-search>
                </label>

                <label class="pwb_period_box">
                    <?= pwb_icon('calendar') ?>
                    <select class="pwb_period_select" data-pwb-period>
                        <option value="7d" <?= (($pwb_meta['period'] ?? '') === '7d') ? 'selected' : '' ?>>Últimos 7 dias</option>
                        <option value="30d" <?= (($pwb_meta['period'] ?? '30d') === '30d') ? 'selected' : '' ?>>Últimos 30 dias</option>
                        <option value="90d" <?= (($pwb_meta['period'] ?? '') === '90d') ? 'selected' : '' ?>>Últimos 90 dias</option>
                        <option value="12m" <?= (($pwb_meta['period'] ?? '') === '12m') ? 'selected' : '' ?>>Últimos 12 meses</option>
                    </select>
                </label>

                <button class="pwb_icon_button" type="button" data-pwb-refresh aria-label="Atualizar dashboard"><?= pwb_icon('refresh') ?></button>
                <button class="pwb_icon_button pwb_notification_button" type="button" aria-label="Notificações"><?= pwb_icon('bell') ?><span class="pwb_notification_count">3</span></button>
                <span class="pwb_avatar"><?= pwb_e($pwb_dashboard_config['user_initials']) ?></span>
            </div>
        </header>

        <section class="pwb_view pwb_view_active" data-pwb-panel="overview">
            <div class="pwb_kpi_grid">
                <?php foreach ($pwb_kpis as $pwb_kpi): ?>
                    <?php pwb_render_kpi($pwb_kpi); ?>
                <?php endforeach; ?>
            </div>

            <div class="pwb_chart_grid pwb_chart_grid_overview">
                <article class="pwb_panel pwb_panel_wide">
                    <div class="pwb_panel_header">
                        <h2 class="pwb_panel_title">Receita Mensal</h2>
                        <span class="pwb_panel_hint">Atualizado em <?= pwb_e($pwb_meta['updated_at'] ?? '') ?></span>
                    </div>
                    <div class="pwb_chart_box pwb_chart_tall"><canvas class="pwb_chart_canvas" id="pwb_chart_monthly_revenue"></canvas></div>
                </article>

                <article class="pwb_panel">
                    <div class="pwb_panel_header">
                        <h2 class="pwb_panel_title">Pedidos por Status</h2>
                    </div>
                    <div class="pwb_chart_box"><canvas class="pwb_chart_canvas" id="pwb_chart_order_status"></canvas></div>
                    <div class="pwb_legend" data-pwb-legend="order_status"></div>
                </article>
            </div>

            <article class="pwb_panel">
                <div class="pwb_panel_header">
                    <h2 class="pwb_panel_title">Produtos em Destaque</h2>
                    <span class="pwb_panel_hint">Ranking por receita</span>
                </div>
                <div class="pwb_table_wrap">
                    <table class="pwb_table" data-pwb-table>
                        <thead class="pwb_thead"><tr class="pwb_tr"><th class="pwb_th">Produto</th><th class="pwb_th">SKU</th><th class="pwb_th">Vendas</th><th class="pwb_th">Receita</th><th class="pwb_th">Estoque</th><th class="pwb_th">Status</th></tr></thead>
                        <tbody class="pwb_tbody">
                        <?php foreach (($pwb_tables['top_products'] ?? []) as $row): ?>
                            <tr class="pwb_tr">
                                <td class="pwb_td"><?= pwb_e($row['name']) ?></td><td class="pwb_td"><?= pwb_e($row['sku']) ?></td><td class="pwb_td"><?= pwb_e($row['sales']) ?></td><td class="pwb_td"><?= pwb_e($row['revenue']) ?></td><td class="pwb_td"><?= pwb_e($row['stock']) ?></td>
                                <td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($row['status'])) ?>"><?= pwb_e($row['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <section class="pwb_view" data-pwb-panel="orders">
            <div class="pwb_metric_grid">
                <?php foreach (array_slice($pwb_kpis, 0, 4) as $item): ?><?php pwb_render_kpi($item); ?><?php endforeach; ?>
            </div>
            <div class="pwb_chart_grid">
                <article class="pwb_panel"><h2 class="pwb_panel_title">Pedidos por Mês</h2><div class="pwb_chart_box"><canvas class="pwb_chart_canvas" id="pwb_chart_orders_by_month"></canvas></div></article>
                <article class="pwb_panel"><h2 class="pwb_panel_title">Métodos de Pagamento</h2><div class="pwb_chart_box"><canvas class="pwb_chart_canvas" id="pwb_chart_payment_methods"></canvas></div><div class="pwb_legend" data-pwb-legend="payment_methods"></div></article>
            </div>
            <article class="pwb_panel">
                <div class="pwb_panel_header"><h2 class="pwb_panel_title">Pedidos Recentes</h2></div>
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table><thead class="pwb_thead"><tr class="pwb_tr"><th class="pwb_th">Pedido</th><th class="pwb_th">Cliente</th><th class="pwb_th">Valor</th><th class="pwb_th">Pagamento</th><th class="pwb_th">Status</th><th class="pwb_th">Data</th></tr></thead><tbody class="pwb_tbody">
                    <?php foreach (($pwb_tables['recent_orders'] ?? []) as $row): ?><tr class="pwb_tr"><td class="pwb_td"><?= pwb_e($row['order']) ?></td><td class="pwb_td"><?= pwb_e($row['customer']) ?></td><td class="pwb_td"><?= pwb_e($row['value']) ?></td><td class="pwb_td"><?= pwb_e($row['payment']) ?></td><td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($row['status'])) ?>"><?= pwb_e($row['status']) ?></span></td><td class="pwb_td"><?= pwb_e($row['date']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </article>
        </section>

        <section class="pwb_view" data-pwb-panel="products">
            <div class="pwb_metric_grid">
                <?php foreach (array_slice($pwb_kpis, 4, 4) as $item): ?><?php pwb_render_kpi($item); ?><?php endforeach; ?>
            </div>
            <article class="pwb_panel">
                <div class="pwb_panel_header"><h2 class="pwb_panel_title">Catálogo de Produtos</h2><span class="pwb_panel_hint">Vendas, receita e estoque</span></div>
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table><thead class="pwb_thead"><tr class="pwb_tr"><th class="pwb_th">Produto</th><th class="pwb_th">SKU</th><th class="pwb_th">Vendas</th><th class="pwb_th">Receita</th><th class="pwb_th">Estoque</th><th class="pwb_th">Status</th></tr></thead><tbody class="pwb_tbody">
                    <?php foreach (($pwb_tables['top_products'] ?? []) as $row): ?><tr class="pwb_tr"><td class="pwb_td"><?= pwb_e($row['name']) ?></td><td class="pwb_td"><?= pwb_e($row['sku']) ?></td><td class="pwb_td"><?= pwb_e($row['sales']) ?></td><td class="pwb_td"><?= pwb_e($row['revenue']) ?></td><td class="pwb_td"><span class="pwb_stock_bar"><span class="pwb_stock_fill" style="width: <?= max(2, min(100, (int)$row['stock'])) ?>%"></span></span><?= pwb_e($row['stock']) ?></td><td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($row['status'])) ?>"><?= pwb_e($row['status']) ?></span></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </article>
        </section>

        <section class="pwb_view" data-pwb-panel="customers">
            <div class="pwb_chart_grid">
                <article class="pwb_panel"><h2 class="pwb_panel_title">Segmentação de Clientes</h2><div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table><thead class="pwb_thead"><tr class="pwb_tr"><th class="pwb_th">Segmento</th><th class="pwb_th">Clientes</th><th class="pwb_th">Ticket Médio</th><th class="pwb_th">Retenção</th></tr></thead><tbody class="pwb_tbody">
                    <?php foreach (($pwb_tables['customers'] ?? []) as $row): ?><tr class="pwb_tr"><td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($row['segment'])) ?>"><?= pwb_e($row['segment']) ?></span></td><td class="pwb_td"><?= pwb_e($row['customers']) ?></td><td class="pwb_td"><?= pwb_e($row['ticket']) ?></td><td class="pwb_td"><?= pwb_e($row['retention']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div></article>
                <article class="pwb_panel"><h2 class="pwb_panel_title">Fontes de Tráfego</h2><div class="pwb_chart_box"><canvas class="pwb_chart_canvas" id="pwb_chart_traffic_sources"></canvas></div><div class="pwb_legend" data-pwb-legend="traffic_sources"></div></article>
            </div>
        </section>

        <section class="pwb_view" data-pwb-panel="access">
            <div class="pwb_metric_grid">
                <?php foreach (($pwb_metrics['access'] ?? []) as $item): ?><?php pwb_render_metric_card($item); ?><?php endforeach; ?>
            </div>
            <article class="pwb_panel"><h2 class="pwb_panel_title">Origem dos Acessos</h2><div class="pwb_chart_box pwb_chart_tall"><canvas class="pwb_chart_canvas" id="pwb_chart_traffic_sources_bar"></canvas></div></article>
        </section>

        <section class="pwb_view" data-pwb-panel="ai">
            <div class="pwb_metric_grid">
                <?php foreach (($pwb_metrics['ai'] ?? []) as $item): ?><?php pwb_render_metric_card($item); ?><?php endforeach; ?>
            </div>
            <article class="pwb_panel"><h2 class="pwb_panel_title">Uso de IA por Dia</h2><div class="pwb_chart_box pwb_chart_tall"><canvas class="pwb_chart_canvas" id="pwb_chart_ai_usage"></canvas></div></article>
        </section>

        <section class="pwb_view" data-pwb-panel="faq">
            <article class="pwb_panel">
                <div class="pwb_panel_header"><h2 class="pwb_panel_title">Perguntas Mais Frequentes</h2><span class="pwb_panel_hint">Automação, volume e satisfação</span></div>
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table><thead class="pwb_thead"><tr class="pwb_tr"><th class="pwb_th">Pergunta</th><th class="pwb_th">Volume</th><th class="pwb_th">Resposta automática</th><th class="pwb_th">Satisfação</th></tr></thead><tbody class="pwb_tbody">
                    <?php foreach (($pwb_tables['faq'] ?? []) as $row): ?><tr class="pwb_tr"><td class="pwb_td"><?= pwb_e($row['question']) ?></td><td class="pwb_td"><?= pwb_e($row['volume']) ?></td><td class="pwb_td"><?= pwb_e($row['auto']) ?></td><td class="pwb_td"><?= pwb_e($row['satisfaction']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </article>
        </section>

        <section class="pwb_view" data-pwb-panel="stock">
            <div class="pwb_chart_grid">
                <article class="pwb_panel"><h2 class="pwb_panel_title">Movimentação de Estoque</h2><div class="pwb_chart_box"><canvas class="pwb_chart_canvas" id="pwb_chart_stock_flow"></canvas></div></article>
                <article class="pwb_panel">
                    <div class="pwb_panel_header"><h2 class="pwb_panel_title">Alertas de Estoque</h2><span class="pwb_panel_hint">Crítico e baixo</span></div>
                    <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table><thead class="pwb_thead"><tr class="pwb_tr"><th class="pwb_th">Produto</th><th class="pwb_th">SKU</th><th class="pwb_th">Atual</th><th class="pwb_th">Mínimo</th><th class="pwb_th">Nível</th></tr></thead><tbody class="pwb_tbody">
                        <?php foreach (($pwb_tables['stock_alerts'] ?? []) as $row): ?><tr class="pwb_tr"><td class="pwb_td"><?= pwb_e($row['product']) ?></td><td class="pwb_td"><?= pwb_e($row['sku']) ?></td><td class="pwb_td"><?= pwb_e($row['stock']) ?></td><td class="pwb_td"><?= pwb_e($row['minimum']) ?></td><td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($row['level'])) ?>"><?= pwb_e($row['level']) ?></span></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </article>
            </div>
        </section>
    </main>
</div>
