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

        // Status vindos do BI. Sem estas regras tudo caía em
        // 'default' e "Prejuízo" ficava com a mesma cor de "Saudável" —
        // a cor é justamente o que faz a linha ruim saltar aos olhos.
        // Prioridade de alerta primeiro: numa central de alertas a
        // severidade e a informacao principal da linha.
        foreach ([
            'pwb_badge_danger'  => ['crítico', 'critico'],
            'pwb_badge_warning' => ['alto'],
            'pwb_badge_info'    => ['médio', 'medio'],
            'pwb_badge_default' => ['informativo', 'não calculado', 'nao calculado'],
        ] as $classe => $termos) {
            foreach ($termos as $t) {
                if ($normalized === $t) return $classe;
            }
        }

        foreach ([
            'pwb_badge_success' => ['saudável', 'concluído', 'campeo', 'fiei'],
            'pwb_badge_danger'  => ['prejuízo', 'zerado', 'perdido', 'em risco', 'devolvido'],
            'pwb_badge_warning' => ['sem custo', 'margem baixa', 'atencao', 'atenção', 'hibernando'],
            'pwb_badge_info'    => ['novo', 'potenciai', 'primeira compra', 'recorrente'],
        ] as $classe => $termos) {
            foreach ($termos as $t) {
                if (str_contains($normalized, $t)) return $classe;
            }
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
        // Direcao da variacao: quem sabe o sinal e o PHP. O CSS nao
        // consegue ler "+8%" vs "-30%" do texto.
        if (!empty($kpi['trend_dir']) && in_array($kpi['trend_dir'], ['up','down'], true)) {
            $cardClass .= ' pwb_kpi_' . $kpi['trend_dir'];
        }
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
// A view nao extraia 'charts'; o painel executivo le os insights dai.
$pwb_charts = $pwb_dashboard_data['charts'] ?? [];
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
            <button class="pwb_nav_item" type="button" data-pwb-view="geo"><?= pwb_icon('access') ?><span class="pwb_nav_label">Geografia</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="pagamentos"><?= pwb_icon('currency') ?><span class="pwb_nav_label">Pagamentos</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="cupons"><?= pwb_icon('percent') ?><span class="pwb_nav_label">Cupons</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="posvenda"><?= pwb_icon('alert') ?><span class="pwb_nav_label">Pós-venda</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="logistica"><?= pwb_icon('box') ?><span class="pwb_nav_label">Logística</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="rentabilidade"><?= pwb_icon('currency') ?><span class="pwb_nav_label">Rentabilidade</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="metas"><?= pwb_icon('trend') ?><span class="pwb_nav_label">Metas e projeção</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="central"><?= pwb_icon('alert') ?><span class="pwb_nav_label">Central executiva</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="access"><?= pwb_icon('access') ?><span class="pwb_nav_label">Funil</span></button>
            <button class="pwb_nav_item" type="button" data-pwb-view="ai"><?= pwb_icon('ai') ?><span class="pwb_nav_label">Saúde do dado</span></button>
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
                        <thead class="pwb_thead"><tr class="pwb_tr"><th class="pwb_th">Produto</th><th class="pwb_th">ID</th><th class="pwb_th">Qtd vendida</th><th class="pwb_th">Receita</th><th class="pwb_th">Custo conhecido</th><th class="pwb_th">Margem</th></tr></thead>
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
                <article class="pwb_panel"><h2 class="pwb_panel_title">Pedidos por mês</h2><div class="pwb_chart_box"><canvas class="pwb_chart_canvas" id="pwb_chart_orders_by_month"></canvas></div></article>
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
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table><thead class="pwb_thead"><tr class="pwb_tr"><th class="pwb_th">Produto</th><th class="pwb_th">ID</th><th class="pwb_th">Qtd vendida</th><th class="pwb_th">Receita</th><th class="pwb_th">Custo conhecido</th><th class="pwb_th">Margem</th></tr></thead><tbody class="pwb_tbody">
                    <?php foreach (($pwb_tables['top_products'] ?? []) as $row): ?><tr class="pwb_tr"><td class="pwb_td"><?= pwb_e($row['name']) ?></td><td class="pwb_td"><?= pwb_e($row['sku']) ?></td><td class="pwb_td"><?= pwb_e($row['sales']) ?></td><td class="pwb_td"><?= pwb_e($row['revenue']) ?></td><td class="pwb_td"><span class="pwb_stock_bar"><span class="pwb_stock_fill" style="width: <?= max(2, min(100, (int)$row['stock'])) ?>%"></span></span><?= pwb_e($row['stock']) ?></td><td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($row['status'])) ?>"><?= pwb_e($row['status']) ?></span></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </article>
        </section>

        <section class="pwb_view" data-pwb-panel="customers">
            <div class="pwb_chart_grid">
                <article class="pwb_panel"><h2 class="pwb_panel_title">Segmentação de Clientes</h2><div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table><thead class="pwb_thead"><tr class="pwb_tr"><th class="pwb_th">Segmento</th><th class="pwb_th">Clientes</th><th class="pwb_th">Ticket médio</th><th class="pwb_th">Receita</th></tr></thead><tbody class="pwb_tbody">
                    <?php foreach (($pwb_tables['customers'] ?? []) as $row): ?><tr class="pwb_tr"><td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($row['segment'])) ?>"><?= pwb_e($row['segment']) ?></span></td><td class="pwb_td"><?= pwb_e($row['customers']) ?></td><td class="pwb_td"><?= pwb_e($row['ticket']) ?></td><td class="pwb_td"><?= pwb_e($row['retention']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div></article>
                <article class="pwb_panel"><h2 class="pwb_panel_title">Receita por canal</h2><div class="pwb_chart_box"><canvas class="pwb_chart_canvas" id="pwb_chart_traffic_sources"></canvas></div><div class="pwb_legend" data-pwb-legend="traffic_sources"></div></article>
            </div>

            <!-- RFM: quintis sobre a própria base, não faixa fixa -->
            <article class="pwb_panel">
                <div class="pwb_panel_header">
                    <h2 class="pwb_panel_title">Segmentação RFM</h2>
                    <span class="pwb_panel_hint">Recência · Frequência · Monetário, por quintil da base</span>
                </div>
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                    <thead class="pwb_thead"><tr class="pwb_tr">
                        <th class="pwb_th">Segmento</th><th class="pwb_th">Clientes</th>
                        <th class="pwb_th">Receita</th><th class="pwb_th">Ticket</th>
                        <th class="pwb_th">Dias sem comprar</th>
                    </tr></thead>
                    <tbody class="pwb_tbody">
                    <?php foreach (($pwb_tables['rfm'] ?? []) as $row): ?>
                        <tr class="pwb_tr">
                            <td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($row['segment'])) ?>"><?= pwb_e($row['segment']) ?></span></td>
                            <td class="pwb_td"><?= pwb_e($row['customers']) ?></td>
                            <td class="pwb_td"><?= pwb_e($row['revenue']) ?></td>
                            <td class="pwb_td"><?= pwb_e($row['ticket']) ?></td>
                            <td class="pwb_td"><?= pwb_e($row['recency']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </article>

            <div class="pwb_chart_grid">
                <article class="pwb_panel">
                    <h2 class="pwb_panel_title">Recompra</h2>
                    <div class="pwb_metric_grid" style="margin-bottom:12px;">
                        <?php foreach (($pwb_metrics['recompra'] ?? []) as $item): ?><?php pwb_render_metric_card($item); ?><?php endforeach; ?>
                    </div>
                    <div class="pwb_chart_box"><canvas class="pwb_chart_canvas" id="pwb_chart_recompra"></canvas></div>
                </article>

                <article class="pwb_panel">
                    <div class="pwb_panel_header">
                        <h2 class="pwb_panel_title">Clientes em risco</h2>
                        <span class="pwb_panel_hint">Passaram do próprio intervalo médio</span>
                    </div>
                    <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                        <thead class="pwb_thead"><tr class="pwb_tr">
                            <th class="pwb_th">Cliente</th><th class="pwb_th">Compras</th>
                            <th class="pwb_th">Receita</th><th class="pwb_th">Sem comprar há</th>
                            <th class="pwb_th">Atraso</th>
                        </tr></thead>
                        <tbody class="pwb_tbody">
                        <?php if (empty($pwb_tables['risco'])): ?>
                            <tr class="pwb_tr"><td class="pwb_td" colspan="5">Nenhum cliente em risco no momento.</td></tr>
                        <?php else: foreach ($pwb_tables['risco'] as $row): ?>
                            <tr class="pwb_tr">
                                <td class="pwb_td"><?= pwb_e($row['name']) ?></td>
                                <td class="pwb_td"><?= pwb_e($row['orders']) ?></td>
                                <td class="pwb_td"><?= pwb_e($row['revenue']) ?></td>
                                <td class="pwb_td"><?= pwb_e($row['days']) ?></td>
                                <td class="pwb_td"><span class="pwb_badge pwb_badge_danger"><?= pwb_e($row['overdue']) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table></div>
                </article>
            </div>

            <!-- Coorte: cada linha é um mês de aquisição; as colunas são
                 os meses seguintes. A média esconde coorte boa e ruim. -->
            <article class="pwb_panel">
                <div class="pwb_panel_header">
                    <h2 class="pwb_panel_title">Retenção por coorte</h2>
                    <span class="pwb_panel_hint">% da coorte que voltou a comprar</span>
                </div>
                <div class="pwb_table_wrap" id="pwb_coorte"></div>
            </article>
        </section>

        <section class="pwb_view" data-pwb-panel="geo">
            <div class="pwb_chart_grid">
                <article class="pwb_panel">
                    <div class="pwb_panel_header">
                        <h2 class="pwb_panel_title">Por estado</h2>
                        <span class="pwb_panel_hint">Confiabilidade = % com geografia congelada no pedido</span>
                    </div>
                    <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                        <thead class="pwb_thead"><tr class="pwb_tr">
                            <th class="pwb_th">UF</th><th class="pwb_th">Pedidos</th>
                            <th class="pwb_th">Clientes</th><th class="pwb_th">Receita</th>
                            <th class="pwb_th">Ticket</th><th class="pwb_th">Frete médio</th>
                            <th class="pwb_th">Confiabilidade</th>
                        </tr></thead>
                        <tbody class="pwb_tbody">
                        <?php foreach (($pwb_tables['geo_uf'] ?? []) as $row): ?>
                            <tr class="pwb_tr">
                                <td class="pwb_td"><?= pwb_e($row['local']) ?></td>
                                <td class="pwb_td"><?= pwb_e($row['orders']) ?></td>
                                <td class="pwb_td"><?= pwb_e($row['customers']) ?></td>
                                <td class="pwb_td"><?= pwb_e($row['revenue']) ?></td>
                                <td class="pwb_td"><?= pwb_e($row['ticket']) ?></td>
                                <td class="pwb_td"><?= pwb_e($row['freight']) ?></td>
                                <td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($row['trust_level'])) ?>"><?= pwb_e($row['trust']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </article>

                <article class="pwb_panel">
                    <h2 class="pwb_panel_title">Receita por estado</h2>
                    <div class="pwb_chart_box"><canvas class="pwb_chart_canvas" id="pwb_chart_geo_uf"></canvas></div>
                </article>
            </div>

            <article class="pwb_panel">
                <div class="pwb_panel_header">
                    <h2 class="pwb_panel_title">Top cidades</h2>
                    <span class="pwb_panel_hint">Por receita no período</span>
                </div>
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                    <thead class="pwb_thead"><tr class="pwb_tr">
                        <th class="pwb_th">Cidade</th><th class="pwb_th">Pedidos</th>
                        <th class="pwb_th">Clientes</th><th class="pwb_th">Receita</th>
                        <th class="pwb_th">Ticket</th><th class="pwb_th">Frete médio</th>
                    </tr></thead>
                    <tbody class="pwb_tbody">
                    <?php foreach (($pwb_tables['geo_cidade'] ?? []) as $row): ?>
                        <tr class="pwb_tr">
                            <td class="pwb_td"><?= pwb_e($row['local']) ?></td>
                            <td class="pwb_td"><?= pwb_e($row['orders']) ?></td>
                            <td class="pwb_td"><?= pwb_e($row['customers']) ?></td>
                            <td class="pwb_td"><?= pwb_e($row['revenue']) ?></td>
                            <td class="pwb_td"><?= pwb_e($row['ticket']) ?></td>
                            <td class="pwb_td"><?= pwb_e($row['freight']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </article>
        </section>

        <section class="pwb_view" data-pwb-panel="pagamentos">
            <article class="pwb_panel">
                <div class="pwb_panel_header">
                    <h2 class="pwb_panel_title">Aprovação por método</h2>
                    <span class="pwb_panel_hint">Sobre TENTATIVAS, inclusive as recusadas</span>
                </div>
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                    <thead class="pwb_thead"><tr class="pwb_tr">
                        <th class="pwb_th">Método</th><th class="pwb_th">Tentativas</th>
                        <th class="pwb_th">Aprovadas</th><th class="pwb_th">Taxa</th>
                        <th class="pwb_th">Valor aprovado</th><th class="pwb_th">Situação</th>
                    </tr></thead>
                    <tbody class="pwb_tbody">
                    <?php foreach (($pwb_tables['pagamentos'] ?? []) as $r): ?>
                        <tr class="pwb_tr">
                            <td class="pwb_td"><?= pwb_e($r['method']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['attempts']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['approved']) ?></td>
                            <td class="pwb_td"><strong><?= pwb_e($r['rate']) ?></strong></td>
                            <td class="pwb_td"><?= pwb_e($r['value']) ?></td>
                            <td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($r['level'])) ?>"><?= pwb_e($r['level']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </article>
            <div class="pwb_chart_grid">
                <article class="pwb_panel"><h2 class="pwb_panel_title">Receita por parcela</h2>
                    <div class="pwb_chart_box"><canvas class="pwb_chart_canvas" id="pwb_chart_parcelas"></canvas></div></article>
                <article class="pwb_panel"><h2 class="pwb_panel_title">Motivos de recusa</h2>
                    <div class="pwb_chart_box"><canvas class="pwb_chart_canvas" id="pwb_chart_recusas"></canvas></div></article>
            </div>
        </section>

        <section class="pwb_view" data-pwb-panel="cupons">
            <article class="pwb_panel">
                <div class="pwb_panel_header">
                    <h2 class="pwb_panel_title">Desempenho por cupom</h2>
                    <span class="pwb_panel_hint">Retorno = receita gerada por real de desconto</span>
                </div>
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                    <thead class="pwb_thead"><tr class="pwb_tr">
                        <th class="pwb_th">Cupom</th><th class="pwb_th">Tipo</th>
                        <th class="pwb_th">Usos</th><th class="pwb_th">Clientes</th>
                        <th class="pwb_th">Desconto</th><th class="pwb_th">Receita</th>
                        <th class="pwb_th">Retorno</th>
                    </tr></thead>
                    <tbody class="pwb_tbody">
                    <?php foreach (($pwb_tables['cupons'] ?? []) as $r): ?>
                        <tr class="pwb_tr">
                            <td class="pwb_td"><strong><?= pwb_e($r['code']) ?></strong></td>
                            <td class="pwb_td"><?= pwb_e($r['type']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['uses']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['clients']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['discount']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['revenue']) ?></td>
                            <td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($r['level'])) ?>"><?= pwb_e($r['roi']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </article>
            <article class="pwb_panel">
                <div class="pwb_panel_header">
                    <h2 class="pwb_panel_title">Com desconto × sem desconto</h2>
                    <span class="pwb_panel_hint">O desconto aumentou o ticket ou só entregou margem?</span>
                </div>
                <div class="pwb_chart_box"><canvas class="pwb_chart_canvas" id="pwb_chart_desconto"></canvas></div>
            </article>
        </section>

        <section class="pwb_view" data-pwb-panel="posvenda">
            <div class="pwb_metric_grid">
                <?php foreach (($pwb_metrics['carrinho'] ?? []) as $item): ?><?php pwb_render_metric_card($item); ?><?php endforeach; ?>
            </div>
            <div class="pwb_chart_grid">
                <article class="pwb_panel">
                    <h2 class="pwb_panel_title">Devoluções por motivo</h2>
                    <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                        <thead class="pwb_thead"><tr class="pwb_tr">
                            <th class="pwb_th">Motivo</th><th class="pwb_th">Tipo</th>
                            <th class="pwb_th">Unidades</th><th class="pwb_th">Valor</th>
                        </tr></thead>
                        <tbody class="pwb_tbody">
                        <?php if (empty($pwb_tables['devolucoes'])): ?>
                            <tr class="pwb_tr"><td class="pwb_td" colspan="4">Nenhuma devolução no período.</td></tr>
                        <?php else: foreach ($pwb_tables['devolucoes'] as $r): ?>
                            <tr class="pwb_tr">
                                <td class="pwb_td"><?= pwb_e($r['reason']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['type']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['units']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['value']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table></div>
                </article>
                <article class="pwb_panel">
                    <div class="pwb_panel_header">
                        <h2 class="pwb_panel_title">Cancelamentos por motivo</h2>
                        <span class="pwb_panel_hint">Motivo estruturado nasceu na Fase 1</span>
                    </div>
                    <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                        <thead class="pwb_thead"><tr class="pwb_tr">
                            <th class="pwb_th">Motivo</th><th class="pwb_th">Pedidos</th><th class="pwb_th">Valor</th>
                        </tr></thead>
                        <tbody class="pwb_tbody">
                        <?php if (empty($pwb_tables['cancelamentos'])): ?>
                            <tr class="pwb_tr"><td class="pwb_td" colspan="3">Nenhum cancelamento no período.</td></tr>
                        <?php else: foreach ($pwb_tables['cancelamentos'] as $r): ?>
                            <tr class="pwb_tr">
                                <td class="pwb_td"><?= pwb_e($r['reason']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['orders']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['value']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table></div>
                </article>
            </div>
            <article class="pwb_panel">
                <h2 class="pwb_panel_title">Produtos mais devolvidos</h2>
                <div class="pwb_chart_box"><canvas class="pwb_chart_canvas" id="pwb_chart_devolvidos"></canvas></div>
            </article>
        </section>

        <section class="pwb_view" data-pwb-panel="logistica">
            <!-- Aviso PERMANENTE enquanto o vínculo não existir: sem ele,
                 uma tabela vazia se leria como "não houve envio". -->
            <div class="form-alert form-alert--warning" style="margin-bottom:14px;font-size:13px;">
                <strong>Custo real de frete ainda sem base.</strong>
                <code>valor_postado</code> — o que a transportadora efetivamente
                cobrou na postagem — está vazio em todas as etiquetas, então
                custo real e subsídio por pedido não têm o que mostrar.
                O <em>vínculo</em> com o pedido funciona: etiquetas de
                <code>canal='pedido'</code> amarram em 100%. Reversa e frete
                avulso aparecem sem pedido por natureza, não por falha.
            </div>
            <article class="pwb_panel">
                <div class="pwb_panel_header">
                    <h2 class="pwb_panel_title">Etiquetas por tipo</h2>
                    <span class="pwb_panel_hint">Somar os três daria um "envios" que não responde nada</span>
                </div>
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                    <thead class="pwb_thead"><tr class="pwb_tr">
                        <th class="pwb_th">Tipo</th><th class="pwb_th">Etiquetas</th>
                        <th class="pwb_th">Com pedido</th><th class="pwb_th">Com rastreio</th>
                        <th class="pwb_th">Custo real médio</th>
                    </tr></thead>
                    <tbody class="pwb_tbody">
                    <?php foreach (($pwb_tables['frete_tipo'] ?? []) as $r): ?>
                        <tr class="pwb_tr">
                            <td class="pwb_td"><strong><?= pwb_e($r['type']) ?></strong></td>
                            <td class="pwb_td"><?= pwb_e($r['labels']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['linked']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['tracked']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['cost']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </article>

            <article class="pwb_panel">
                <h2 class="pwb_panel_title">Transportadoras</h2>
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                    <thead class="pwb_thead"><tr class="pwb_tr">
                        <th class="pwb_th">Transportadora</th><th class="pwb_th">Envios</th>
                        <th class="pwb_th">Custo real médio</th><th class="pwb_th">Dias até entregar</th>
                        <th class="pwb_th">Entregues</th>
                    </tr></thead>
                    <tbody class="pwb_tbody">
                    <?php if (empty($pwb_tables['transportadoras'])): ?>
                        <tr class="pwb_tr"><td class="pwb_td" colspan="5">Nenhuma etiqueta no período.</td></tr>
                    <?php else: foreach ($pwb_tables['transportadoras'] as $r): ?>
                        <tr class="pwb_tr">
                            <td class="pwb_td"><?= pwb_e($r['carrier']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['shipments']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['cost']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['days']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['delivered']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table></div>
            </article>
            <div class="pwb_chart_grid">
                <article class="pwb_panel">
                    <div class="pwb_panel_header">
                        <h2 class="pwb_panel_title">Giro de estoque</h2>
                        <span class="pwb_panel_hint">Cobertura = dias de estoque no ritmo atual</span>
                    </div>
                    <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                        <thead class="pwb_thead"><tr class="pwb_tr">
                            <th class="pwb_th">Produto</th><th class="pwb_th">Saldo</th>
                            <th class="pwb_th">Vendido (90d)</th><th class="pwb_th">Cobertura</th>
                            <th class="pwb_th">Giro</th>
                        </tr></thead>
                        <tbody class="pwb_tbody">
                        <?php foreach (array_slice($pwb_tables['giro'] ?? [], 0, 12) as $r): ?>
                            <tr class="pwb_tr">
                                <td class="pwb_td"><?= pwb_e($r['product']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['stock']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['sold']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['coverage']) ?></td>
                                <td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($r['level'])) ?>"><?= pwb_e($r['level']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </article>
                <article class="pwb_panel">
                    <div class="pwb_panel_header">
                        <h2 class="pwb_panel_title">Estoque parado</h2>
                        <span class="pwb_panel_hint">Com saldo, sem venda há 90+ dias</span>
                    </div>
                    <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                        <thead class="pwb_thead"><tr class="pwb_tr">
                            <th class="pwb_th">Produto</th><th class="pwb_th">Saldo</th>
                            <th class="pwb_th">Valor parado</th><th class="pwb_th">Sem vender há</th>
                        </tr></thead>
                        <tbody class="pwb_tbody">
                        <?php foreach (array_slice($pwb_tables['parado'] ?? [], 0, 12) as $r): ?>
                            <tr class="pwb_tr">
                                <td class="pwb_td"><?= pwb_e($r['product']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['stock']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['value']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['since']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </article>
            </div>
        </section>

        <section class="pwb_view" data-pwb-panel="rentabilidade">
            <div class="pwb_metric_grid">
                <?php foreach (($pwb_metrics['concentracao'] ?? []) as $item): ?><?php pwb_render_metric_card($item); ?><?php endforeach; ?>
            </div>
            <article class="pwb_panel">
                <div class="pwb_panel_header">
                    <h2 class="pwb_panel_title">Curva ABC</h2>
                    <span class="pwb_panel_hint">A = até 80% da receita acumulada · B = até 95% · C = cauda</span>
                </div>
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                    <thead class="pwb_thead"><tr class="pwb_tr">
                        <th class="pwb_th">#</th><th class="pwb_th">Produto</th>
                        <th class="pwb_th">Receita</th><th class="pwb_th">Participação</th>
                        <th class="pwb_th">Acumulado</th><th class="pwb_th">Lucro</th>
                        <th class="pwb_th">Classe</th>
                    </tr></thead>
                    <tbody class="pwb_tbody">
                    <?php foreach (($pwb_tables['abc'] ?? []) as $r): ?>
                        <tr class="pwb_tr">
                            <td class="pwb_td"><?= pwb_e($r['position']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['name']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['revenue']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['share']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['cumulative']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['profit']) ?></td>
                            <td class="pwb_td"><span class="pwb_badge pwb_abc_<?= pwb_e(strtolower($r['class'])) ?>"><?= pwb_e($r['class']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </article>
            <article class="pwb_panel">
                <div class="pwb_panel_header">
                    <h2 class="pwb_panel_title">Canais de venda</h2>
                    <span class="pwb_panel_hint">Canal nasceu na Fase 1 — histórico anterior foi classificado por backfill</span>
                </div>
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                    <thead class="pwb_thead"><tr class="pwb_tr">
                        <th class="pwb_th">Canal</th><th class="pwb_th">Receita</th>
                        <th class="pwb_th">Pedidos</th><th class="pwb_th">Clientes</th>
                        <th class="pwb_th">Ticket</th><th class="pwb_th">Margem</th>
                    </tr></thead>
                    <tbody class="pwb_tbody">
                    <?php foreach (($pwb_tables['canais'] ?? []) as $r): ?>
                        <tr class="pwb_tr">
                            <td class="pwb_td"><strong><?= pwb_e($r['channel']) ?></strong></td>
                            <td class="pwb_td"><?= pwb_e($r['revenue']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['orders']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['clients']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['ticket']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['margin']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </article>
        </section>

        <section class="pwb_view" data-pwb-panel="metas">
            <div class="pwb_metric_grid">
                <?php foreach (($pwb_metrics['projecao'] ?? []) as $item): ?><?php pwb_render_metric_card($item); ?><?php endforeach; ?>
            </div>
            <article class="pwb_panel">
                <div class="pwb_panel_header">
                    <h2 class="pwb_panel_title">Metas do período</h2>
                    <span class="pwb_panel_hint">
                        <a href="<?= pwb_e($pwb_dashboard_config['settings_url']) ?>">Cadastrar metas</a>
                    </span>
                </div>
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                    <thead class="pwb_thead"><tr class="pwb_tr">
                        <th class="pwb_th">Métrica</th><th class="pwb_th">Recorte</th>
                        <th class="pwb_th">Período</th><th class="pwb_th">Meta</th>
                        <th class="pwb_th">Realizado</th><th class="pwb_th">Atingido</th>
                        <th class="pwb_th">Falta</th><th class="pwb_th">Situação</th>
                    </tr></thead>
                    <tbody class="pwb_tbody">
                    <?php if (empty($pwb_tables['metas'])): ?>
                        <tr class="pwb_tr"><td class="pwb_td" colspan="8">
                            Nenhuma meta cadastrada para este período.
                        </td></tr>
                    <?php else: foreach ($pwb_tables['metas'] as $r): ?>
                        <tr class="pwb_tr">
                            <td class="pwb_td"><?= pwb_e($r['metric']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['target']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['period']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['goal']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['done']) ?></td>
                            <td class="pwb_td"><strong><?= pwb_e($r['pct']) ?></strong></td>
                            <td class="pwb_td"><?= pwb_e($r['missing']) ?></td>
                            <td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($r['level'])) ?>"><?= pwb_e($r['level']) ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table></div>
            </article>
        </section>

        <section class="pwb_view" data-pwb-panel="central">
            <!-- Insights primeiro: é o que se lê em 5 segundos -->
            <article class="pwb_panel">
                <h2 class="pwb_panel_title">Insights do negócio</h2>
                <ul class="pwb_insights">
                    <?php foreach (($pwb_charts['insights'] ?? []) as $i): ?>
                        <li class="pwb_insight"><?= pwb_e($i) ?></li>
                    <?php endforeach; ?>
                </ul>
            </article>

            <article class="pwb_panel">
                <div class="pwb_panel_header">
                    <h2 class="pwb_panel_title">Central de alertas</h2>
                    <span class="pwb_panel_hint">Alerta que não pôde ser avaliado aparece como informativo — nunca some</span>
                </div>
                <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                    <thead class="pwb_thead"><tr class="pwb_tr">
                        <th class="pwb_th">Prioridade</th><th class="pwb_th">Alerta</th><th class="pwb_th">Detalhe</th>
                    </tr></thead>
                    <tbody class="pwb_tbody">
                    <?php if (empty($pwb_tables['alertas'])): ?>
                        <tr class="pwb_tr"><td class="pwb_td" colspan="3">Nenhum alerta.</td></tr>
                    <?php else: foreach ($pwb_tables['alertas'] as $r): ?>
                        <tr class="pwb_tr">
                            <td class="pwb_td"><span class="pwb_badge <?= pwb_e(pwb_badge_class($r['level'])) ?>"><?= pwb_e($r['level']) ?></span></td>
                            <td class="pwb_td"><?= pwb_e($r['title']) ?></td>
                            <td class="pwb_td"><?= pwb_e($r['detail']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table></div>
            </article>

            <div class="pwb_chart_grid">
                <article class="pwb_panel">
                    <div class="pwb_panel_header">
                        <h2 class="pwb_panel_title">Produtos em alta</h2>
                        <span class="pwb_panel_hint">30 dias vs. 30 anteriores</span>
                    </div>
                    <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                        <thead class="pwb_thead"><tr class="pwb_tr">
                            <th class="pwb_th">Produto</th><th class="pwb_th">Antes</th>
                            <th class="pwb_th">Agora</th><th class="pwb_th">Variação</th>
                        </tr></thead>
                        <tbody class="pwb_tbody">
                        <?php if (empty($pwb_tables['alta'])): ?>
                            <tr class="pwb_tr"><td class="pwb_td" colspan="4">Base insuficiente para comparar períodos.</td></tr>
                        <?php else: foreach ($pwb_tables['alta'] as $r): ?>
                            <tr class="pwb_tr">
                                <td class="pwb_td"><?= pwb_e($r['name']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['before']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['now']) ?></td>
                                <td class="pwb_td"><span class="pwb_badge pwb_badge_success"><?= pwb_e($r['change']) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table></div>
                </article>
                <article class="pwb_panel">
                    <div class="pwb_panel_header">
                        <h2 class="pwb_panel_title">Produtos em queda</h2>
                        <span class="pwb_panel_hint">30 dias vs. 30 anteriores</span>
                    </div>
                    <div class="pwb_table_wrap"><table class="pwb_table" data-pwb-table>
                        <thead class="pwb_thead"><tr class="pwb_tr">
                            <th class="pwb_th">Produto</th><th class="pwb_th">Antes</th>
                            <th class="pwb_th">Agora</th><th class="pwb_th">Variação</th>
                        </tr></thead>
                        <tbody class="pwb_tbody">
                        <?php if (empty($pwb_tables['queda'])): ?>
                            <tr class="pwb_tr"><td class="pwb_td" colspan="4">Base insuficiente para comparar períodos.</td></tr>
                        <?php else: foreach ($pwb_tables['queda'] as $r): ?>
                            <tr class="pwb_tr">
                                <td class="pwb_td"><?= pwb_e($r['name']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['before']) ?></td>
                                <td class="pwb_td"><?= pwb_e($r['now']) ?></td>
                                <td class="pwb_td"><span class="pwb_badge pwb_badge_danger"><?= pwb_e($r['change']) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table></div>
                </article>
            </div>
        </section>

        <section class="pwb_view" data-pwb-panel="access">
            <div class="pwb_metric_grid">
                <?php foreach (($pwb_metrics['access'] ?? []) as $item): ?><?php pwb_render_metric_card($item); ?><?php endforeach; ?>
            </div>
            <article class="pwb_panel"><h2 class="pwb_panel_title">Marcas por receita</h2><div class="pwb_chart_box pwb_chart_tall"><canvas class="pwb_chart_canvas" id="pwb_chart_traffic_sources_bar"></canvas></div></article>
        </section>

        <section class="pwb_view" data-pwb-panel="ai">
            <div class="pwb_metric_grid">
                <?php foreach (($pwb_metrics['ai'] ?? []) as $item): ?><?php pwb_render_metric_card($item); ?><?php endforeach; ?>
            </div>
            <article class="pwb_panel"><h2 class="pwb_panel_title">Faturamento por dia</h2><div class="pwb_chart_box pwb_chart_tall"><canvas class="pwb_chart_canvas" id="pwb_chart_ai_usage"></canvas></div></article>
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

<script>
(function () {
  var raiz = document.querySelector('.pwb_dashboard');
  if (!raiz) return;

  // ── Navegacao entre paineis ───────────────────────────
  // O markup ja trazia data-pwb-view / data-pwb-panel, mas nao havia
  // JS: so o painel "overview" aparecia e os outros 7 botoes eram
  // decorativos.
  raiz.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-pwb-view]');
    if (!btn) return;

    var alvo = btn.getAttribute('data-pwb-view');
    raiz.querySelectorAll('[data-pwb-view]').forEach(function (b) {
      b.classList.toggle('pwb_nav_active', b === btn);
    });
    raiz.querySelectorAll('[data-pwb-panel]').forEach(function (p) {
      p.classList.toggle('pwb_view_active', p.getAttribute('data-pwb-panel') === alvo);
    });
  });

  // ── Busca nas tabelas visiveis ────────────────────────
  var busca = raiz.querySelector('[data-pwb-search]');
  if (busca) {
    busca.addEventListener('input', function () {
      var termo = this.value.toLowerCase().trim();
      raiz.querySelectorAll('[data-pwb-panel].pwb_view_active [data-pwb-table] tbody tr')
        .forEach(function (tr) {
          tr.style.display = (!termo || tr.textContent.toLowerCase().indexOf(termo) > -1) ? '' : 'none';
        });
    });
  }

  // ── Periodo ───────────────────────────────────────────
  // Recarrega a pagina com ?periodo=. Poderia consumir o endpoint
  // JSON (data-pwb-api-url) sem reload, mas isso exigiria reconstruir
  // todo o HTML no cliente — e ai a formatacao passaria a existir em
  // dois lugares, PHP e JS, que divergem com o tempo. O servidor
  // continua sendo o unico que formata.
  var periodo = raiz.querySelector('[data-pwb-period]');
  if (periodo) {
    periodo.addEventListener('change', function () {
      var u = new URL(window.location.href);
      u.searchParams.set('periodo', this.value);
      window.location.href = u.toString();
    });
  }


  // ── Graficos em SVG puro ──────────────────────────────
  // O markup traz <canvas> mas o projeto nao carrega Chart.js, e a
  // CSP/offline do admin nao garante CDN. Em vez de deixar retangulos
  // vazios (ou puxar uma biblioteca de 200KB para tres graficos de
  // barra), desenha-se SVG inline a partir do payload que o servidor
  // ja publica.
  var payloadEl = document.getElementById('pwb_dashboard_payload');
  var dados = {};
  try { dados = JSON.parse(payloadEl ? payloadEl.textContent : '{}') || {}; } catch (e) { dados = {}; }
  var charts = dados.charts || {};

  function brl(v) {
    return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  }

  function esc(t) {
    return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /** Barras horizontais: legivel com rotulo longo, que e o caso aqui. */
  function barras(canvasId, itens, corHex) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !itens || !itens.length) return;

    var max = Math.max.apply(null, itens.map(function (i) { return Number(i.valor) || 0; })) || 1;
    var linhas = itens.map(function (i) {
      var pct = Math.max(1, (Number(i.valor) || 0) / max * 100);
      return '<div class="pwb_bar_row">' +
               '<span class="pwb_bar_label" title="' + esc(i.rotulo) + '">' + esc(i.rotulo) + '</span>' +
               '<span class="pwb_bar_track"><span class="pwb_bar_fill" style="width:' + pct + '%;background:' + corHex + '"></span></span>' +
               '<span class="pwb_bar_value">' + esc(i.texto) + '</span>' +
             '</div>';
    }).join('');

    var box = document.createElement('div');
    box.className = 'pwb_bars';
    box.innerHTML = linhas;
    canvas.replaceWith(box);
  }

  /** Linha simples para a serie diaria. */
  function linha(canvasId, serie) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !serie || serie.length < 2) return;

    var vals = serie.map(function (p) { return Number(p.faturamento) || 0; });
    var max = Math.max.apply(null, vals) || 1;
    var w = 600, h = 170, pad = 6;
    var passo = (w - pad * 2) / (vals.length - 1);

    var pontos = vals.map(function (v, i) {
      return (pad + i * passo).toFixed(1) + ',' + (h - pad - (v / max) * (h - pad * 2)).toFixed(1);
    });

    var area = 'M' + pad + ',' + (h - pad) + ' L' + pontos.join(' L') +
               ' L' + (w - pad) + ',' + (h - pad) + ' Z';

    var svg = '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" class="pwb_svg_line">' +
      '<path d="' + area + '" fill="rgba(37,99,235,.10)"/>' +
      '<polyline points="' + pontos.join(' ') + '" fill="none" stroke="#2563eb" stroke-width="2" ' +
      'stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/></svg>' +
      '<div class="pwb_line_legend"><span>' + esc(serie[0].data) + '</span>' +
      '<span>pico ' + brl(max) + '</span>' +
      '<span>' + esc(serie[serie.length - 1].data) + '</span></div>';

    var box = document.createElement('div');
    box.className = 'pwb_linebox';
    box.innerHTML = svg;
    canvas.replaceWith(box);
  }

  barras('pwb_chart_monthly_revenue', (charts.monthly || []).map(function (m) {
    return { rotulo: m.ano_mes, valor: m.faturamento, texto: brl(m.faturamento) };
  }), '#2563eb');

  barras('pwb_chart_orders_by_month', (charts.monthly || []).map(function (m) {
    return { rotulo: m.ano_mes, valor: m.pedidos, texto: m.pedidos };
  }), '#0ea472');

  barras('pwb_chart_order_status', (charts.by_status || []).map(function (r) {
    return { rotulo: r.status_pedido, valor: r.pedidos, texto: r.pedidos };
  }), '#7c3aed');

  barras('pwb_chart_payment_methods', (charts.by_payment || []).map(function (r) {
    return { rotulo: r.forma, valor: r.receita, texto: brl(r.receita) };
  }), '#7c3aed');

  barras('pwb_chart_traffic_sources', (charts.by_channel || []).map(function (r) {
    return { rotulo: r.nome, valor: r.receita, texto: brl(r.receita) };
  }), '#0ea472');

  barras('pwb_chart_traffic_sources_bar', (charts.top_brands || []).map(function (r) {
    return { rotulo: r.nome, valor: r.receita, texto: brl(r.receita) };
  }), '#2563eb');

  barras('pwb_chart_stock_flow', (charts.top_categories || []).map(function (r) {
    return { rotulo: r.nome, valor: r.receita, texto: brl(r.receita) };
  }), '#d97706');

  barras('pwb_chart_recompra', (charts.recompra || []).map(function (r) {
    return { rotulo: r.faixa, valor: r.clientes, texto: r.clientes + ' cli.' };
  }), '#0ea472');

  barras('pwb_chart_geo_uf', (charts.geo_uf || []).map(function (r) {
    return { rotulo: r.local, valor: r.receita, texto: brl(r.receita) };
  }), '#2563eb');

  barras('pwb_chart_parcelas', (charts.parcelas || []).map(function (r) {
    return { rotulo: r.parcelas + 'x', valor: r.receita, texto: brl(r.receita) };
  }), '#2563eb');

  barras('pwb_chart_recusas', (charts.recusas || []).map(function (r) {
    return { rotulo: r.motivo, valor: r.ocorrencias, texto: r.ocorrencias + 'x' };
  }), '#dc2626');

  barras('pwb_chart_desconto', (charts.desconto || []).map(function (r) {
    return { rotulo: r.grupo, valor: r.ticket, texto: brl(r.ticket) + ' de ticket' };
  }), '#d97706');

  barras('pwb_chart_devolvidos', (charts.devolvidos || []).map(function (r) {
    return { rotulo: r.produto, valor: r.valor, texto: brl(r.valor) };
  }), '#dc2626');

  linha('pwb_chart_ai_usage', charts.daily || []);

  // ── Matriz de coorte ──────────────────────────────────
  // Uma linha por mês de aquisição, uma coluna por mês seguinte. A
  // intensidade da cor mostra a retenção — ler 60 numeros soltos numa
  // tabela nao revela o padrao que a matriz revela de imediato.
  (function () {
    var alvo = document.getElementById('pwb_coorte');
    var dados = charts.coorte || [];
    if (!alvo) return;
    if (!dados.length) { alvo.innerHTML = '<p class="pwb_chart_vazio">Sem dados de coorte no período</p>'; return; }

    var coortes = [], maxOffset = 0, mapa = {};
    dados.forEach(function (d) {
      if (coortes.indexOf(d.coorte) === -1) coortes.push(d.coorte);
      maxOffset = Math.max(maxOffset, Number(d.mes_offset));
      mapa[d.coorte + '|' + d.mes_offset] = d;
    });

    var html = '<table class="pwb_table pwb_coorte_tabela"><thead class="pwb_thead"><tr class="pwb_tr">' +
               '<th class="pwb_th">Coorte</th><th class="pwb_th">Clientes</th>';
    for (var o = 0; o <= maxOffset; o++) html += '<th class="pwb_th">M+' + o + '</th>';
    html += '</tr></thead><tbody class="pwb_tbody">';

    coortes.forEach(function (c) {
      var base = mapa[c + '|0'];
      html += '<tr class="pwb_tr"><td class="pwb_td"><strong>' + esc(c) + '</strong></td>' +
              '<td class="pwb_td">' + esc(base ? base.tamanho_coorte : '—') + '</td>';
      for (var o = 0; o <= maxOffset; o++) {
        var cel = mapa[c + '|' + o];
        if (!cel) { html += '<td class="pwb_td"></td>'; continue; }
        var pct = Number(cel.retencao_pct) || 0;
        // Opacidade proporcional: 100% fica solido, 5% quase branco.
        var alpha = Math.max(0.06, Math.min(1, pct / 100));
        html += '<td class="pwb_td pwb_coorte_cel" style="background:rgba(37,99,235,' + alpha.toFixed(2) + ');' +
                (pct > 55 ? 'color:#fff;' : '') + '">' + pct + '%</td>';
      }
      html += '</tr>';
    });

    alvo.innerHTML = html + '</tbody></table>';
  })();

  // Painel de grafico que continuou sem dado nao fica um retangulo
  // mudo: diz que nao ha dado, que e informacao diferente de zero.
  raiz.querySelectorAll('.pwb_chart_box').forEach(function (box) {
    if (box.querySelector('canvas')) {
      box.innerHTML = '<p class="pwb_chart_vazio">Sem dados no período</p>';
    }
  });

  var refresh = raiz.querySelector('[data-pwb-refresh]');
  if (refresh) refresh.addEventListener('click', function () { window.location.reload(); });
})();
</script>
