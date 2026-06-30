<?php
/** @var array $fluxo */
/** @var array $passos */
/** @var array $templates */
/** @var array $config */
$base = defined('BASE_URL') ? BASE_URL : '';

// Labels amigáveis por tipo
$labels = [
    'carrinho_abandonado'    => ['delays_horas' => 'Delays (horas)', 'show_delays' => true],
    'produto_visitado'       => ['delays_horas' => 'Delays (horas)', 'show_delays' => true, 'show_min_visitas' => true],
    'categoria_visitada'     => ['delays_horas' => 'Delay (horas)', 'show_delays' => true],
    'wishlist'               => ['delays_horas' => 'Delay (horas)', 'show_delays' => true],
    'aniversario'            => ['show_cupom' => true],
    'reengajamento'          => ['show_cupom' => true, 'show_dias_sem_compra' => true, 'delays_dias' => 'Delays (dias)'],
    'pos_compra_complementar'=> ['show_delay_dias' => true],
    'pos_compra_avaliacao'   => ['show_delay_dias' => true],
    'boas_vindas'            => ['delays_horas' => 'Delays (horas)', 'show_delays' => true],
    'lancamento_moto'        => [],
];
$opcoes = $labels[$fluxo['tipo']] ?? [];
?>
<div class="em_wrapper em_auto_form" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <div>
            <h1><?= htmlspecialchars($fluxo['nome']) ?></h1>
            <p class="em_meta"><code><?= htmlspecialchars($fluxo['tipo']) ?></code></p>
        </div>
        <div class="em_actions">
            <a href="<?= $base ?>/admin/email-marketing/automacoes" class="em_btn">Voltar</a>
            <a href="<?= $base ?>/admin/email-marketing/automacoes/<?= (int)$fluxo['id'] ?>/relatorio" class="em_btn">Relatório</a>
            <button type="button" class="em_btn em_btn_primary" data-em-action="auto-salvar">Salvar</button>
        </div>
    </div>

    <input type="hidden" id="em_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <input type="hidden" id="em_fluxo_id" value="<?= (int)$fluxo['id'] ?>">

    <div class="em_form">
        <h2 style="margin-top:0;">Configuração</h2>

        <div class="em_form_grid">
            <label>Nome
                <input type="text" id="em_auto_nome" value="<?= htmlspecialchars($fluxo['nome']) ?>">
            </label>
            <label class="em_inline" style="align-self:end; padding-bottom:8px;">
                <input type="checkbox" id="em_auto_ativo" <?= $fluxo['ativo'] ? 'checked' : '' ?>>
                Ativo
            </label>
        </div>

        <?php if (!empty($opcoes['show_delays'])): ?>
        <label>
            <?= htmlspecialchars($opcoes['delays_horas'] ?? 'Delays (horas)') ?>
            <small style="color:var(--em-text-muted);">— um por passo, separados por vírgula</small>
            <?php
            $chaveDelay = isset($opcoes['delays_dias']) ? 'delays_dias' : 'delays_horas';
            $valoresDelay = $config[$chaveDelay] ?? [];
            ?>
            <input type="text" id="em_auto_delays"
                   value="<?= htmlspecialchars(implode(', ', $valoresDelay)) ?>">
        </label>
        <?php endif; ?>

        <?php if (!empty($opcoes['show_delay_dias'])): ?>
        <label>Delay pós-entrega (dias)
            <input type="number" id="em_auto_delay_dias"
                   value="<?= (int)($config['delay_dias'] ?? 7) ?>" min="1" max="90">
        </label>
        <?php endif; ?>

        <?php if (!empty($opcoes['show_min_visitas'])): ?>
        <label>Mínimo de visitas para disparar
            <input type="number" id="em_auto_min_visitas"
                   value="<?= (int)($config['min_visitas'] ?? 2) ?>" min="1" max="20">
        </label>
        <?php endif; ?>

        <?php if (!empty($opcoes['show_dias_sem_compra'])): ?>
        <label>Dias sem compra para iniciar reengajamento
            <input type="number" id="em_auto_dias_sem_compra"
                   value="<?= (int)($config['dias_sem_compra'] ?? 60) ?>" min="7" max="365">
        </label>
        <?php endif; ?>

        <?php if (!empty($opcoes['show_cupom'])): ?>
        <div class="em_form_grid">
            <label>Desconto do cupom (%)
                <input type="number" id="em_auto_cupom_pct" step="0.5" min="1" max="100"
                       value="<?= (float)($config['cupom_pct'] ?? 10) ?>">
            </label>
            <label>Validade do cupom (dias)
                <input type="number" id="em_auto_cupom_dias" min="1" max="90"
                       value="<?= (int)($config['cupom_dias_validade'] ?? 7) ?>">
            </label>
        </div>
        <?php endif; ?>
    </div>

    <!-- Passos -->
    <div class="em_form" style="margin-top:16px;">
        <h2 style="margin-top:0;">Passos e templates</h2>
        <p class="em_meta">Associe um template de email a cada passo do fluxo.</p>

        <table class="em_table">
            <thead><tr>
                <th>#</th><th>Nome</th><th>Delay</th><th>Template</th>
            </tr></thead>
            <tbody>
            <?php foreach ($passos as $p): ?>
                <tr>
                    <td><?= (int)$p['ordem'] ?></td>
                    <td><?= htmlspecialchars($p['nome']) ?></td>
                    <td><?= (int)$p['delay_horas'] ?>h</td>
                    <td>
                        <select name="passo[<?= (int)$p['id'] ?>][template_id]"
                                class="em_passo_template" data-passo="<?= (int)$p['id'] ?>">
                            <option value="">— nenhum —</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"
                                    <?= ((int)$p['template_id'] === (int)$t['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="em_form_actions">
        <button type="button" class="em_btn em_btn_primary" data-em-action="auto-salvar">Salvar configuração</button>
    </div>
</div>
