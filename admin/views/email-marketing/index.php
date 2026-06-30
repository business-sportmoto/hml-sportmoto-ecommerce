<?php
/**
 * admin/views/email-marketing/index.php  (v2 — dashboard ampliado)
 * SUBSTITUI o existente.
 */
/** @var array $dash */
$base = defined('BASE_URL') ? BASE_URL : '';

function _fmt($n) { return number_format((int)$n, 0, ',', '.'); }
function _pct($n) { return number_format((float)$n, 2, ',', '.') . '%'; }
?>
<div class="em_wrapper em_dashboard" data-base="<?= htmlspecialchars($base) ?>">

    <div class="em_header">
        <h1>Painel de Email Marketing</h1>
        <div class="em_actions">
            <a href="<?= $base ?>/admin/email-marketing/contatos"   class="em_btn">Contatos</a>
            <a href="<?= $base ?>/admin/email-marketing/listas"     class="em_btn">Listas</a>
            <a href="<?= $base ?>/admin/email-marketing/templates"  class="em_btn">Templates</a>
            <a href="<?= $base ?>/admin/email-marketing/campanhas"  class="em_btn">Campanhas</a>
            <a href="<?= $base ?>/admin/email-marketing/csv"        class="em_btn">Importações</a>
            <a href="<?= $base ?>/admin/email-marketing/provedores"        class="em_btn">Provedores</a>
            <a href="<?= $base ?>/admin/email-marketing/automacoes"        class="em_btn">Automações</a>
        </div>
    </div>

    <!-- Alertas (se houver) -->
    <?php if (!empty($dash['alertas_reputacao'])): ?>
        <div class="em_alertas_box">
            <?php foreach ($dash['alertas_reputacao'] as $a): ?>
                <div class="em_alerta em_alerta_<?= htmlspecialchars($a['nivel']) ?>">
                    <strong><?= htmlspecialchars($a['titulo']) ?>:</strong>
                    <?= htmlspecialchars($a['mensagem']) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- KPIs principais -->
    <h2>Visão geral (últimos 30 dias)</h2>
    <div class="em_kpi_grid">
        <div class="em_card">
            <span class="em_card_label">Enviados</span>
            <span class="em_card_value"><?= _fmt($dash['taxas']['enviados']) ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label">Entregues</span>
            <span class="em_card_value"><?= _fmt($dash['taxas']['entregues']) ?></span>
            <span class="em_card_sub"><?= _pct($dash['taxas']['taxa_entrega']) ?></span>
        </div>
        <div class="em_card em_card_hl">
            <span class="em_card_label">Aberturas</span>
            <span class="em_card_value"><?= _fmt($dash['taxas']['aberturas']) ?></span>
            <span class="em_card_sub"><?= _pct($dash['taxas']['taxa_abertura']) ?></span>
        </div>
        <div class="em_card em_card_hl">
            <span class="em_card_label">Cliques</span>
            <span class="em_card_value"><?= _fmt($dash['taxas']['cliques']) ?></span>
            <span class="em_card_sub"><?= _pct($dash['taxas']['taxa_clique']) ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label em_warn">Bounces</span>
            <span class="em_card_value"><?= _fmt($dash['taxas']['bounces']) ?></span>
            <span class="em_card_sub"><?= _pct($dash['taxas']['taxa_bounce']) ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label em_warn">Complaints</span>
            <span class="em_card_value"><?= _fmt($dash['taxas']['complaints']) ?></span>
            <span class="em_card_sub"><?= _pct($dash['taxas']['taxa_complaint']) ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label em_warn">Descadastros</span>
            <span class="em_card_value"><?= _fmt($dash['taxas']['descadastros']) ?></span>
            <span class="em_card_sub"><?= _pct($dash['taxas']['taxa_descadastro']) ?></span>
        </div>
    </div>

    <!-- Stats secundários -->
    <div class="em_dash_cols">
        <!-- Coluna esquerda: contatos -->
        <div class="em_form">
            <h3 style="margin-top:0;">Contatos</h3>
            <table class="em_table em_table_compact">
                <tr><td>Total cadastrado</td><td><strong><?= _fmt($dash['contatos']['total']) ?></strong></td></tr>
                <tr><td>Ativos</td><td><strong style="color:var(--em-green-text);"><?= _fmt($dash['contatos']['ativos']) ?></strong></td></tr>
                <tr><td>Descadastrados</td><td><?= _fmt($dash['contatos']['descadastrados']) ?></td></tr>
                <tr><td>Bounce</td><td><?= _fmt($dash['contatos']['bounces']) ?></td></tr>
                <tr><td>Complaint</td><td><?= _fmt($dash['contatos']['complaints']) ?></td></tr>
                <tr><td>Bloqueados</td><td><?= _fmt($dash['contatos']['bloqueados']) ?></td></tr>
                <tr><td>Em supressão</td><td><?= _fmt($dash['contatos']['supressoes']) ?></td></tr>
            </table>

            <?php
            $cresc = $dash['crescimento'];
            $deltaIcon = $cresc['delta'] > 0 ? '↑' : ($cresc['delta'] < 0 ? '↓' : '·');
            $deltaCor = $cresc['delta'] > 0 ? 'var(--em-green-text)' : ($cresc['delta'] < 0 ? 'var(--em-warn-text)' : 'var(--em-text-muted)');
            ?>
            <div style="padding:10px 12px; background:var(--em-bg-subtle); border-radius:var(--em-r-md); font-size:13px;">
                <strong><?= _fmt($cresc['novos_30d']) ?></strong> novos contatos nos últimos 30d
                <?php if ($cresc['delta_pct'] !== null): ?>
                    <span style="color:<?= $deltaCor ?>;">(<?= $deltaIcon ?> <?= _pct(abs($cresc['delta_pct'])) ?> vs 30d anteriores)</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Coluna direita: campanhas + templates -->
        <div class="em_form">
            <h3 style="margin-top:0;">Campanhas</h3>
            <table class="em_table em_table_compact">
                <tr><td>Em rascunho</td><td><?= _fmt($dash['campanhas']['rascunho']) ?></td></tr>
                <tr><td>Agendadas</td><td><?= _fmt($dash['campanhas']['agendada']) ?></td></tr>
                <tr><td>Em envio</td><td><strong style="color:var(--em-blue);"><?= _fmt($dash['campanhas']['em_envio']) ?></strong></td></tr>
                <tr><td>Pausadas</td><td><?= _fmt($dash['campanhas']['pausada']) ?></td></tr>
                <tr><td>Concluídas</td><td><?= _fmt($dash['campanhas']['concluida']) ?></td></tr>
                <tr><td>A/B em andamento</td><td><strong><?= _fmt($dash['campanhas']['ab_em_andamento']) ?></strong></td></tr>
            </table>

            <h3>Templates</h3>
            <table class="em_table em_table_compact">
                <tr><td>Total</td><td><?= _fmt($dash['templates']['total']) ?></td></tr>
                <tr><td>Ativos</td><td><?= _fmt($dash['templates']['ativos']) ?></td></tr>
                <tr><td>Visuais (GrapesJS)</td><td><?= _fmt($dash['templates']['visuais']) ?></td></tr>
                <?php if ($dash['templates']['com_aviso'] > 0): ?>
                    <tr><td>Com avisos</td><td style="color:var(--em-warn-text);"><?= _fmt($dash['templates']['com_aviso']) ?></td></tr>
                <?php endif; ?>
                <?php if ($dash['templates']['com_erro'] > 0): ?>
                    <tr><td>Com erro</td><td style="color:var(--em-warn-text);"><strong><?= _fmt($dash['templates']['com_erro']) ?></strong></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- Últimas campanhas -->
    <h2>Últimas campanhas</h2>
    <table class="em_table">
        <thead><tr>
            <th>Nome</th><th>Status</th><th>A/B</th>
            <th>Destinatários</th><th>Enviados</th><th>Aberturas</th><th>Cliques</th>
            <th>Data</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($dash['ultimas_campanhas'])): ?>
            <tr><td colspan="9" class="em_empty">Nenhuma campanha criada ainda.</td></tr>
        <?php else: foreach ($dash['ultimas_campanhas'] as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['nome']) ?></td>
                <td><span class="em_badge em_st_<?= htmlspecialchars($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                <td>
                    <?php if ($c['ab_ativo']): ?>
                        <span class="em_badge">A/B
                            <?= $c['ab_vencedor'] ? '· venc. ' . strtoupper($c['ab_vencedor']) : ($c['ab_fase'] ?: '') ?>
                        </span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= _fmt($c['total_destinatarios']) ?></td>
                <td><?= _fmt($c['total_enviados']) ?></td>
                <td><?= _fmt($c['total_aberturas']) ?></td>
                <td><?= _fmt($c['total_cliques']) ?></td>
                <td><?= date('d/m H:i', strtotime($c['data_ref'])) ?></td>
                <td class="em_actions_cell">
                    <a href="<?= $base ?>/admin/email-marketing/campanhas/<?= (int)$c['id'] ?>/editar" class="em_link">Ver</a>
                    <?php if ($c['ab_ativo']): ?>
                        <a href="<?= $base ?>/admin/email-marketing/campanhas/<?= (int)$c['id'] ?>/ab/relatorio" class="em_link">A/B</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <!-- Últimas importações -->
    <h2>Últimas importações</h2>
    <table class="em_table">
        <thead><tr>
            <th>Arquivo</th><th>Status</th><th>Linhas</th><th>Inseridos</th>
            <th>Atualizados</th><th>Inválidos</th><th>Data</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($dash['ultimas_importacoes'])): ?>
            <tr><td colspan="8" class="em_empty">Nenhuma importação realizada.</td></tr>
        <?php else: foreach ($dash['ultimas_importacoes'] as $i): ?>
            <tr>
                <td><?= htmlspecialchars($i['arquivo'] ?: '—') ?></td>
                <td>
                    <span class="em_badge em_st_<?= htmlspecialchars($i['status']) ?>"><?= htmlspecialchars($i['status']) ?></span>
                    <?php if (in_array($i['status'], ['processando','fila'], true)): ?>
                        <span style="color:var(--em-blue); font-size:11px;">(<?= (int)$i['progresso_pct'] ?>%)</span>
                    <?php endif; ?>
                </td>
                <td><?= _fmt($i['total_linhas']) ?></td>
                <td><?= _fmt($i['inseridos']) ?></td>
                <td><?= _fmt($i['atualizados']) ?></td>
                <td><?= _fmt($i['invalidos']) ?></td>
                <td><?= date('d/m H:i', strtotime($i['criado_em'])) ?></td>
                <td class="em_actions_cell">
                    <a href="<?= $base ?>/admin/email-marketing/csv/<?= (int)$i['id'] ?>" class="em_link">Detalhes</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
