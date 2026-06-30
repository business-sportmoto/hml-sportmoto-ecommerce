<?php
/** @var array $campanha */
/** @var array $variacoes */
/** @var array $templates */
$base = defined('BASE_URL') ? BASE_URL : '';
?>
<div class="em_wrapper em_ab_form" data-base="<?= htmlspecialchars($base) ?>" data-campanha="<?= (int)$campanha['id'] ?>">
    <div class="em_header">
        <div>
            <h1>Variações A/B</h1>
            <p class="em_meta"><?= htmlspecialchars($campanha['nome']) ?>
                <span class="em_badge em_st_<?= htmlspecialchars($campanha['status']) ?>"><?= htmlspecialchars($campanha['status']) ?></span>
                <?php if (!empty($campanha['ab_fase'])): ?>
                    <span class="em_badge">fase A/B: <?= htmlspecialchars($campanha['ab_fase']) ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="em_actions">
            <a href="<?= $base ?>/admin/email-marketing/campanhas/<?= (int)$campanha['id'] ?>/editar" class="em_btn">Voltar</a>
            <a href="<?= $base ?>/admin/email-marketing/campanhas/<?= (int)$campanha['id'] ?>/ab/relatorio" class="em_btn">Relatório</a>
            <button type="button" class="em_btn em_btn_primary" data-em-action="ab-salvar">Salvar configuração</button>
        </div>
    </div>

    <input type="hidden" id="em_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

    <!-- Configuração geral -->
    <div class="em_form">
        <h2 style="margin-top:0;">Configuração do teste</h2>

        <div class="em_form_grid">
            <label>Amostra A (%)
                <input type="number" id="em_ab_pct_a" min="5" max="50" value="<?= (int)($campanha['ab_amostra_pct_a'] ?? 15) ?>">
            </label>
            <label>Amostra B (%)
                <input type="number" id="em_ab_pct_b" min="5" max="50" value="<?= (int)($campanha['ab_amostra_pct_b'] ?? 15) ?>">
            </label>
            <label>Rollout (%)
                <input type="text" id="em_ab_rollout" readonly value="<?= 100 - (int)($campanha['ab_amostra_pct_a'] ?? 15) - (int)($campanha['ab_amostra_pct_b'] ?? 15) ?>">
            </label>
        </div>

        <div class="em_form_grid">
            <label>Métrica do vencedor
                <select id="em_ab_metrica">
                    <option value="abertura" <?= ($campanha['ab_metrica']??'')==='abertura'?'selected':'' ?>>Taxa de abertura</option>
                    <option value="clique"   <?= ($campanha['ab_metrica']??'')==='clique'?'selected':'' ?>>Taxa de clique (recomendado)</option>
                    <option value="manual"   <?= ($campanha['ab_metrica']??'')==='manual'?'selected':'' ?>>Manual (admin decide)</option>
                </select>
            </label>
            <label>Tempo de análise (minutos)
                <input type="number" id="em_ab_tempo" min="10" max="10080" value="<?= (int)($campanha['ab_tempo_analise_min'] ?? 240) ?>">
                <small style="color:var(--em-text-muted); font-size:11px;">240 min = 4h (recomendado para clique)</small>
            </label>
            <label>Mínimo de eventos por variação
                <input type="number" id="em_ab_min" min="1" max="1000" value="<?= (int)($campanha['ab_min_eventos'] ?? 10) ?>">
            </label>
        </div>

        <div class="em_form_grid">
            <label>Em caso de empate
                <select id="em_ab_empate">
                    <option value="a"               <?= ($campanha['ab_em_empate']??'')==='a'?'selected':'' ?>>Usar variação A</option>
                    <option value="b"               <?= ($campanha['ab_em_empate']??'')==='b'?'selected':'' ?>>Usar variação B</option>
                    <option value="random"          <?= ($campanha['ab_em_empate']??'')==='random'?'selected':'' ?>>Aleatório</option>
                    <option value="aguardar_manual" <?= ($campanha['ab_em_empate']??'aguardar_manual')==='aguardar_manual'?'selected':'' ?>>Aguardar escolha manual</option>
                </select>
            </label>
            <label class="em_inline" style="grid-column: span 2;">
                <input type="checkbox" id="em_ab_auto" <?= ($campanha['ab_envio_automatico']??1) ? 'checked' : '' ?>>
                Envio automático do rollout após decisão
            </label>
        </div>

        <div class="em_aviso">
            <strong>Como funciona:</strong> ao enfileirar, <?= (int)($campanha['ab_amostra_pct_a']??15) ?>% da base recebe a variação A e
            <?= (int)($campanha['ab_amostra_pct_b']??15) ?>% recebe B (escolha aleatória). Após
            <?= (int)($campanha['ab_tempo_analise_min']??240) ?> minutos e pelo menos
            <?= (int)($campanha['ab_min_eventos']??10) ?> eventos por variação, o vencedor é
            calculado por <em>taxa de <?= htmlspecialchars($campanha['ab_metrica'] ?? 'clique') ?></em> (descontando complaints).
            Os <?= 100 - (int)($campanha['ab_amostra_pct_a']??15) - (int)($campanha['ab_amostra_pct_b']??15) ?>% restantes recebem a variação vencedora.
        </div>
    </div>

    <!-- Variações lado a lado -->
    <div class="em_ab_grid">
        <?php foreach (['a','b'] as $letra): $v = $variacoes[$letra]; ?>
        <div class="em_form em_ab_card">
            <h2>Variação <?= strtoupper($letra) ?></h2>

            <label>Template
                <select name="<?= $letra ?>[template_id]" class="em_ab_tpl" data-var="<?= $letra ?>">
                    <option value="">— escolher —</option>
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= ((int)($v['template_id']??0))===(int)$t['id']?'selected':'' ?>>
                            <?= htmlspecialchars($t['nome']) ?> (v<?= (int)$t['versao'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Assunto
                <input type="text" name="<?= $letra ?>[assunto]" value="<?= htmlspecialchars($v['assunto'] ?? '') ?>" maxlength="250">
            </label>

            <label>Preheader
                <input type="text" name="<?= $letra ?>[preheader]" value="<?= htmlspecialchars($v['preheader'] ?? '') ?>" maxlength="250">
            </label>

            <details>
                <summary style="cursor:pointer; color:var(--em-blue); font-size:13px;">Sobrescrever remetente</summary>
                <div style="padding-top:8px;">
                    <label>Nome do remetente
                        <input type="text" name="<?= $letra ?>[remetente_nome]" value="<?= htmlspecialchars($v['remetente_nome'] ?? '') ?>" maxlength="120">
                    </label>
                    <label>Email do remetente
                        <input type="email" name="<?= $letra ?>[remetente_email]" value="<?= htmlspecialchars($v['remetente_email'] ?? '') ?>" maxlength="150">
                    </label>
                </div>
            </details>

            <?php if ((int)($v['total_destinatarios'] ?? 0) > 0): ?>
            <div class="em_kpi_mini">
                <span>Destinatários: <strong><?= number_format($v['total_destinatarios'],0,',','.') ?></strong></span>
                <span>Enviados: <strong><?= number_format($v['total_enviados'],0,',','.') ?></strong></span>
                <span>Aberturas: <strong><?= number_format($v['total_aberturas'],0,',','.') ?></strong></span>
                <span>Cliques: <strong><?= number_format($v['total_cliques'],0,',','.') ?></strong></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="em_form_actions">
        <button type="button" class="em_btn em_btn_primary" data-em-action="ab-salvar">Salvar configuração</button>
        <?php if (in_array($campanha['status'], ['rascunho','pausada'], true)): ?>
            <button type="button" class="em_btn em_warn" data-em-action="ab-desativar" data-id="<?= (int)$campanha['id'] ?>">Desativar A/B</button>
        <?php endif; ?>
    </div>
</div>
