<?php // views/admin/promocoes/form.php
$editando = !empty($promocao);
$cfg      = $editando ? ($promocao['configuracao'] ?? []) : [];
$action   = $editando
    ? ADMIN_URL . '/promocoes/' . $promocao['id']
    : ADMIN_URL . '/promocoes/nova';
?>

<?php if (!empty($_GET['salvo'])): ?>
<div class="flash flash-success" style="margin-bottom:14px;">✓ Promoção salva com sucesso.</div>
<?php endif; ?>
<?php if (!empty($_GET['criada'])): ?>
<div class="flash flash-success" style="margin-bottom:14px;">✓ Promoção criada! Configure os detalhes abaixo.</div>
<?php endif; ?>
<?php if (!empty($_GET['erro'])): ?>
<div class="flash flash-danger" style="margin-bottom:14px;">Erro: <?= View::e($_GET['erro']) ?></div>
<?php endif; ?>

<div class="admin-page-header">
  <div>
    <a href="<?= ADMIN_URL ?>/promocoes" style="font-size:13px;color:var(--c-text-muted);">← Promoções</a>
    <h1 style="margin-top:4px;"><?= View::e($titulo) ?></h1>
  </div>
  <?php if ($editando): ?>
  <label class="toggle-switch" title="<?= $promocao['ativo'] ? 'Ativa' : 'Inativa' ?>" style="margin-top:8px;">
    <input type="checkbox" id="toggle-ativo-header" <?= $promocao['ativo'] ? 'checked' : '' ?>>
    <span class="toggle-slider"></span>
  </label>
  <?php endif; ?>
</div>

<form method="POST" action="<?= $action ?>" id="promo-form" novalidate>
  <?= SecurityHelper::csrfField() ?>

<div class="promo-grid">

  <!-- ══ Coluna principal ════════════════════════════════ -->
  <div class="promo-main">

    <!-- Informações básicas -->
    <div class="admin-card" style="margin-bottom:16px;">
      <h3 class="ap-card-title">Informações básicas</h3>
      <div style="padding:18px;display:grid;gap:16px;">

        <div class="form-row">
          <div class="form-group" style="flex:2;">
            <label class="form-label">Nome da promoção *</label>
            <input type="text" name="nome" class="form-control" required
                   value="<?= View::e($promocao['nome'] ?? '') ?>"
                   placeholder="Ex: Leve 2 capacetes ASX ganhe 10%">
          </div>
          <div class="form-group">
            <label class="form-label">Prioridade</label>
            <input type="number" name="prioridade" class="form-control" min="0"
                   value="<?= (int)($promocao['prioridade'] ?? 0) ?>"
                   placeholder="0">
            <span class="form-hint">Maior número = avaliada primeiro</span>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Tipo *</label>
          <select name="tipo" id="promo-tipo" class="form-control" required>
            <?php
            $tipos = [
              'desconto_progressivo' => 'Desconto progressivo por quantidade',
              'frete_gratis'         => 'Frete grátis automático',
              'brinde'               => 'Brinde (produto grátis por regra)',
              'compre_ganhe'         => 'Compre X leve Y',
              'cashback'             => 'Cashback em crédito',
              'relampago'            => 'Promoção relâmpago (em breve)',
              'fidelidade'           => 'Por fidelidade/score (em breve)',
            ];
            foreach ($tipos as $val => $label):
              $sel = ($promocao['tipo'] ?? '') === $val ? 'selected' : '';
            ?>
            <option value="<?= $val ?>" <?= $sel ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Descrição interna</label>
          <textarea name="descricao" class="form-control" rows="2"
                    placeholder="Anotação interna sobre a promoção (não exibida ao cliente)"
                    ><?= View::e($promocao['descricao'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <!-- ══ Configuração por tipo (dinâmica) ════════════ -->

    <!-- PROGRESSIVO -->
    <div class="admin-card promo-section" id="section-desconto_progressivo"
         style="margin-bottom:16px;<?= ($promocao['tipo'] ?? '') !== 'desconto_progressivo' ? 'display:none;' : '' ?>">
      <h3 class="ap-card-title">
        Configuração — Desconto progressivo
      </h3>
      <div style="padding:18px;display:grid;gap:18px;">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Modo de contagem</label>
            <div class="radio-group">
              <?php foreach (['unidades' => 'Unidades totais (3x o mesmo = 3)', 'distintos' => 'Itens distintos (3 produtos diferentes = 3)'] as $val => $lbl): ?>
              <label class="radio-label">
                <input type="radio" name="modo_contagem" value="<?= $val ?>"
                       <?= ($cfg['modo_contagem'] ?? 'unidades') === $val ? 'checked' : '' ?>>
                <?= $lbl ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Tipo de desconto</label>
            <div class="radio-group">
              <label class="radio-label">
                <input type="radio" name="tipo_desconto" value="percentual"
                       <?= ($cfg['tipo_desconto'] ?? 'percentual') === 'percentual' ? 'checked' : '' ?>>
                Percentual (%)
              </label>
              <label class="radio-label">
                <input type="radio" name="tipo_desconto" value="fixo_por_item"
                       <?= ($cfg['tipo_desconto'] ?? '') === 'fixo_por_item' ? 'checked' : '' ?>>
                Valor fixo por item (R$)
              </label>
            </div>
          </div>
        </div>

        <!-- Tabela de faixas -->
        <div class="form-group">
          <label class="form-label">Faixas de desconto *</label>
          <div style="border:1px solid var(--c-border);border-radius:10px;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;" id="faixas-table">
              <thead>
                <tr style="background:#f8fafc;font-size:12.5px;font-weight:700;color:var(--c-text-muted);">
                  <th style="padding:10px 14px;text-align:left;">A partir de (qtd)</th>
                  <th style="padding:10px 14px;text-align:left;">Desconto (%)</th>
                  <th style="padding:10px 14px;width:40px;"></th>
                </tr>
              </thead>
              <tbody id="faixas-body">
                <?php
                $faixas = $cfg['faixas'] ?? [['qtd'=>2,'pct'=>5]];
                foreach ($faixas as $fi => $faixa):
                ?>
                <tr class="faixa-row">
                  <td style="padding:8px 14px;">
                    <input type="number" name="faixa_qtd[]" class="form-control form-control--sm"
                           value="<?= (int)$faixa['qtd'] ?>" min="1" placeholder="2" required>
                  </td>
                  <td style="padding:8px 14px;">
                    <input type="number" name="faixa_pct[]" class="form-control form-control--sm"
                           value="<?= (float)$faixa['pct'] ?>" min="0.1" step="0.1" placeholder="5" required>
                  </td>
                  <td style="padding:8px 14px;">
                    <button type="button" class="btn-icon btn-icon--danger js-remove-faixa"
                            title="Remover faixa">×</button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button type="button" id="btn-add-faixa" class="btn btn-ghost btn-sm" style="margin-top:8px;">
            + Adicionar faixa
          </button>
          <span class="form-hint">As faixas são avaliadas pela maior quantidade que o carrinho satisfaz.</span>
        </div>

        <div class="form-group">
          <label class="checkbox-label">
            <input type="checkbox" name="cfg_frete_gratis" <?= !empty($cfg['frete_gratis']) ? 'checked' : '' ?>>
            Incluir frete grátis nesta promoção
          </label>
        </div>
      </div>
    </div>

    <!-- FRETE GRÁTIS -->
    <div class="admin-card promo-section" id="section-frete_gratis"
         style="margin-bottom:16px;<?= ($promocao['tipo'] ?? '') !== 'frete_gratis' ? 'display:none;' : '' ?>">
      <h3 class="ap-card-title">Configuração — Frete grátis</h3>
      <div style="padding:18px;">
        <div class="form-group">
          <label class="form-label">Valor mínimo do carrinho (R$)</label>
          <input type="number" name="cfg_valor_minimo" class="form-control"
                 value="<?= number_format((float)($cfg['valor_minimo'] ?? 0), 2, '.', '') ?>"
                 step="0.01" min="0" placeholder="299.90">
          <span class="form-hint">0 = frete grátis sem valor mínimo (não recomendado)</span>
        </div>
      </div>
    </div>

    <!-- BRINDE -->
    <?php $cfgBrinde = ($promocao['tipo'] ?? '') === 'brinde' ? $cfg : []; ?>
    <div class="admin-card promo-section" id="section-brinde"
         style="margin-bottom:16px;<?= ($promocao['tipo'] ?? '') !== 'brinde' ? 'display:none;' : '' ?>">
      <h3 class="ap-card-title">Configuração — Brinde</h3>
      <div style="padding:18px;display:grid;gap:18px;">

        <!-- Produto brinde -->
        <div class="form-group">
          <label class="form-label">Produto brinde *</label>
          <div class="tag-input-container" id="container-brinde-produto">
            <div class="tag-input-wrap" id="wrap-brinde-produto" style="cursor:pointer;">
              <div class="tag-input-tags" id="tags-brinde-produto"></div>
              <input type="text" placeholder="Buscar produto…" class="tag-input-search"
                     id="search-brinde-produto" autocomplete="off">
            </div>
            <div class="tag-suggestions" id="sug-brinde-produto"></div>
          </div>
          <input type="hidden" name="brinde_produto_id" id="hidden-brinde-produto"
                 value="<?= (int)($cfgBrinde['produto_brinde_id'] ?? 0) ?>">
          <span class="form-hint">Apenas um produto por promoção de brinde.</span>
        </div>

        <div class="form-group" style="max-width:180px;">
          <label class="form-label">Quantidade do brinde</label>
          <input type="number" name="brinde_quantidade" class="form-control"
                 value="<?= max(1, (int)($cfgBrinde['quantidade_brinde'] ?? 1)) ?>"
                 min="1" placeholder="1">
        </div>

        <!-- Gatilho -->
        <div class="form-group">
          <label class="form-label">Gatilho — condição para ganhar o brinde</label>
          <div class="radio-group" id="brinde-gatilho-group">
            <?php foreach ([
              'valor'      => 'Valor mínimo do carrinho',
              'quantidade' => 'Quantidade mínima de itens elegíveis',
              'ambos'      => 'Ambos (valor E quantidade)',
            ] as $val => $lbl): ?>
            <label class="radio-label">
              <input type="radio" name="brinde_gatilho" value="<?= $val ?>"
                     <?= ($cfgBrinde['gatilho'] ?? 'valor') === $val ? 'checked' : '' ?>>
              <?= $lbl ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Valor mínimo (mostrado para gatilho valor/ambos) -->
        <div class="form-group brinde-gatilho-valor" id="brinde-field-valor">
          <label class="form-label">Valor mínimo do carrinho (R$)</label>
          <input type="number" name="brinde_valor_minimo" class="form-control"
                 value="<?= number_format((float)($cfgBrinde['valor_minimo'] ?? 0), 2, '.', '') ?>"
                 step="0.01" min="0" placeholder="299.90">
        </div>

        <!-- Qtd mínima (mostrado para gatilho quantidade/ambos) -->
        <div class="form-group brinde-gatilho-qtd" id="brinde-field-qtd">
          <label class="form-label">Quantidade mínima de itens</label>
          <div class="form-row" style="gap:14px;">
            <input type="number" name="brinde_qtd_minima" class="form-control"
                   value="<?= max(1, (int)($cfgBrinde['qtd_minima'] ?? 1)) ?>"
                   min="1" placeholder="3">
            <div class="form-group">
              <label class="form-label">Modo de contagem</label>
              <select name="brinde_modo_contagem" class="form-control">
                <option value="unidades"  <?= ($cfgBrinde['modo_contagem'] ?? 'unidades') === 'unidades'  ? 'selected' : '' ?>>Unidades totais</option>
                <option value="distintos" <?= ($cfgBrinde['modo_contagem'] ?? '') === 'distintos' ? 'selected' : '' ?>>Itens distintos</option>
              </select>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- COMPRE X LEVE Y -->
    <?php $cfgCG = ($promocao['tipo'] ?? '') === 'compre_ganhe' ? $cfg : []; ?>
    <div class="admin-card promo-section" id="section-compre_ganhe"
         style="margin-bottom:16px;<?= ($promocao['tipo'] ?? '') !== 'compre_ganhe' ? 'display:none;' : '' ?>">
      <h3 class="ap-card-title">Configuração — Compre X leve Y</h3>
      <div style="padding:18px;display:grid;gap:18px;">

        <!-- X e Y -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
          <div class="form-group">
            <label class="form-label">Comprar (X) *</label>
            <input type="number" name="cg_comprar" class="form-control"
                   value="<?= max(2, (int)($cfgCG['comprar'] ?? 3)) ?>"
                   min="2" placeholder="3" id="cg-comprar">
            <span class="form-hint">Quantidade mínima de itens elegíveis</span>
          </div>
          <div class="form-group">
            <label class="form-label">Leva grátis/off (Y) *</label>
            <input type="number" name="cg_levar" class="form-control"
                   value="<?= max(1, (int)($cfgCG['levar'] ?? 1)) ?>"
                   min="1" placeholder="1" id="cg-levar">
            <span class="form-hint">Qtd de itens mais baratos com desconto</span>
          </div>
          <div class="form-group">
            <label class="form-label">% de desconto no Y</label>
            <input type="number" name="cg_desconto_pct" class="form-control"
                   value="<?= min(100, max(1, (float)($cfgCG['desconto_pct'] ?? 100))) ?>"
                   min="1" max="100" step="1" placeholder="100" id="cg-pct">
            <span class="form-hint">100 = grátis · 50 = metade do preço</span>
          </div>
        </div>

        <!-- Preview dinâmico da regra -->
        <div class="form-group">
          <div id="cg-preview" style="background:#eff6ff;border:1px solid #bfdbfe;
               border-radius:8px;padding:10px 14px;font-size:13px;color:#1e40af;">
          </div>
        </div>

      </div>
    </div>

    <!-- CASHBACK -->
    <?php $cfgCB = ($promocao['tipo'] ?? '') === 'cashback' ? $cfg : []; ?>
    <div class="admin-card promo-section" id="section-cashback"
         style="margin-bottom:16px;<?= ($promocao['tipo'] ?? '') !== 'cashback' ? 'display:none;' : '' ?>">
      <h3 class="ap-card-title">Configuração — Cashback em crédito</h3>
      <div style="padding:18px;display:grid;gap:16px;">

        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;
                    padding:10px 14px;font-size:13px;color:#15803d;">
          💡 O cashback <strong>não desconta no checkout</strong> — o cliente recebe
          créditos na conta <strong>7 dias após o pedido ser marcado como entregue</strong>
          (respeitando o prazo do CDC para devolução).
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Percentual de cashback (%) *</label>
            <input type="number" name="cb_percentual" class="form-control"
                   value="<?= max(0.01, (float)($cfgCB['percentual'] ?? 5)) ?>"
                   min="0.01" max="100" step="0.01" placeholder="5"
                   id="cb-pct">
            <span class="form-hint">Ex: 5 = o cliente recebe 5% do valor elegível como crédito</span>
          </div>
          <div class="form-group">
            <label class="form-label">Validade do crédito (dias) *</label>
            <input type="number" name="cb_validade_dias" class="form-control"
                   value="<?= max(1, (int)($cfgCB['validade_dias'] ?? 90)) ?>"
                   min="1" placeholder="90">
            <span class="form-hint">A partir da data em que o crédito for liberado</span>
          </div>
        </div>

        <!-- Preview dinâmico -->
        <div id="cb-preview" style="background:#eff6ff;border:1px solid #bfdbfe;
             border-radius:8px;padding:10px 14px;font-size:13px;color:#1e40af;"></div>

      </div>
    </div>

    <!-- Condições de gatilho do carrinho -->
    <div class="admin-card" style="margin-bottom:16px;">
      <h3 class="ap-card-title">Condições do carrinho</h3>
      <div style="padding:18px;" class="form-row">
        <div class="form-group">
          <label class="form-label">Valor mínimo do carrinho (R$)</label>
          <input type="number" name="valor_minimo_carrinho" class="form-control"
                 value="<?= $promocao['valor_minimo_carrinho'] ?? '' ?>"
                 step="0.01" min="0" placeholder="Sem mínimo">
        </div>
        <div class="form-group">
          <label class="form-label">Qtd mínima de itens</label>
          <input type="number" name="qtd_minima_itens" class="form-control"
                 value="<?= $promocao['qtd_minima_itens'] ?? '' ?>"
                 min="1" placeholder="Sem mínimo">
        </div>
      </div>
    </div>

    <!-- Escopo de produtos elegíveis -->
    <div class="admin-card" style="margin-bottom:16px;">
      <h3 class="ap-card-title">Escopo — produtos elegíveis</h3>
      <div style="padding:18px;display:grid;gap:16px;">
        <p style="font-size:13px;color:var(--c-text-muted);margin:0;">
          Deixe em branco para que a promoção valha para todos os produtos.
          Ao preencher múltiplas dimensões, o produto precisa satisfazer <strong>todas</strong> (AND).
        </p>

        <div class="form-group">
          <label class="form-label">Marcas</label>
          <div class="tag-input-container">
            <div class="tag-input-wrap" id="wrap-marcas">
              <div class="tag-input-tags" id="tags-marcas"></div>
              <input type="text" placeholder="Buscar marca…" class="tag-input-search"
                     id="search-marcas" autocomplete="off">
            </div>
            <div class="tag-suggestions" id="sug-marcas"></div>
          </div>
          <input type="hidden" name="escopo_marcas" id="hidden-marcas"
                 value="<?= implode(',', $promocao['escopo_marcas'] ?? []) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Categorias</label>
          <div class="tag-input-container">
            <div class="tag-input-wrap" id="wrap-categorias">
              <div class="tag-input-tags" id="tags-categorias"></div>
              <input type="text" placeholder="Buscar categoria…" class="tag-input-search"
                     id="search-categorias" autocomplete="off">
            </div>
            <div class="tag-suggestions" id="sug-categorias"></div>
          </div>
          <input type="hidden" name="escopo_categorias" id="hidden-categorias"
                 value="<?= implode(',', $promocao['escopo_categorias'] ?? []) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Produtos específicos</label>
          <div class="tag-input-container">
            <div class="tag-input-wrap" id="wrap-produtos">
              <div class="tag-input-tags" id="tags-produtos"></div>
              <input type="text" placeholder="Buscar produto…" class="tag-input-search"
                     id="search-produtos" autocomplete="off">
            </div>
            <div class="tag-suggestions" id="sug-produtos"></div>
          </div>
          <input type="hidden" name="escopo_produtos" id="hidden-produtos"
                 value="<?= implode(',', $promocao['escopo_produtos'] ?? []) ?>">
        </div>

      </div>
    </div>

  </div>

  <!-- ══ Coluna lateral ══════════════════════════════════ -->
  <div class="promo-aside">

    <!-- Ações -->
    <div class="admin-card" style="margin-bottom:16px;padding:18px;">
      <label class="checkbox-label" style="margin-bottom:14px;">
        <input type="checkbox" name="ativo" <?= ($promocao['ativo'] ?? 1) ? 'checked' : '' ?>>
        Promoção ativa
      </label>
      <button type="submit" class="btn btn-primary btn-full">
        <?= $editando ? 'Salvar alterações' : 'Criar promoção' ?>
      </button>
      <a href="<?= ADMIN_URL ?>/promocoes" class="btn btn-ghost btn-full" style="margin-top:8px;">
        Cancelar
      </a>
    </div>

    <!-- Validade -->
    <div class="admin-card" style="margin-bottom:16px;">
      <h3 class="ap-card-title">Validade</h3>
      <div style="padding:14px 18px;display:grid;gap:12px;">
        <div class="form-group">
          <label class="form-label">Início</label>
          <input type="datetime-local" name="data_inicio" class="form-control"
                 value="<?= $promocao['data_inicio'] ? date('Y-m-d\TH:i', strtotime($promocao['data_inicio'])) : '' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Fim</label>
          <input type="datetime-local" name="data_fim" class="form-control"
                 value="<?= $promocao['data_fim'] ? date('Y-m-d\TH:i', strtotime($promocao['data_fim'])) : '' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Horário (relâmpago)</label>
          <div class="form-row" style="gap:8px;">
            <input type="time" name="horario_inicio" class="form-control"
                   value="<?= $promocao['horario_inicio'] ?? '' ?>" placeholder="00:00">
            <span style="line-height:38px;color:var(--c-text-muted);">até</span>
            <input type="time" name="horario_fim" class="form-control"
                   value="<?= $promocao['horario_fim'] ?? '' ?>" placeholder="23:59">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Dias da semana</label>
          <div style="display:flex;flex-wrap:wrap;gap:6px;">
            <?php foreach (['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'] as $i => $dia): ?>
            <label style="display:flex;align-items:center;gap:4px;font-size:12.5px;cursor:pointer;">
              <input type="checkbox" name="dias_semana[]" value="<?= $i ?>"
                     <?= in_array($i, $promocao['dias_semana'] ?? [], true) ? 'checked' : '' ?>>
              <?= $dia ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Regras de audiência -->
    <div class="admin-card" style="margin-bottom:16px;">
      <h3 class="ap-card-title">Audiência</h3>
      <div style="padding:14px 18px;display:grid;gap:12px;">
        <label class="checkbox-label">
          <input type="checkbox" name="apenas_primeira_compra"
                 <?= !empty($promocao['apenas_primeira_compra']) ? 'checked' : '' ?>>
          Apenas primeira compra
        </label>
        <div class="form-group">
          <label class="form-label">Score mínimo</label>
          <select name="score_minimo" class="form-control">
            <option value="">Todos os clientes</option>
            <option value="0"   <?= ($promocao['score_minimo'] ?? null) === 0   ? 'selected' : '' ?>>Bronze (0+)</option>
            <option value="100" <?= ($promocao['score_minimo'] ?? null) === 100  ? 'selected' : '' ?>>Silver (100+)</option>
            <option value="250" <?= ($promocao['score_minimo'] ?? null) === 250  ? 'selected' : '' ?>>Gold (250+)</option>
            <option value="450" <?= ($promocao['score_minimo'] ?? null) === 450  ? 'selected' : '' ?>>Platinum (450+)</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Acumulação -->
    <div class="admin-card" style="margin-bottom:16px;">
      <h3 class="ap-card-title">Acumulação</h3>
      <div style="padding:14px 18px;display:grid;gap:10px;">
        <label class="checkbox-label">
          <input type="checkbox" name="acumulavel"
                 <?= !empty($promocao['acumulavel']) ? 'checked' : '' ?>>
          Acumula com outras promoções
          <span class="form-hint" style="display:block;margin-left:22px;">
            Se desmarcado, bloqueia promoções de menor prioridade.
          </span>
        </label>
        <label class="checkbox-label">
          <input type="checkbox" name="acumula_cupom"
                 <?= !empty($promocao['acumula_cupom']) ? 'checked' : '' ?>>
          Acumula com cupom manual
        </label>
      </div>
    </div>

    <?php if ($editando && !empty($aplicacoes)): ?>
    <!-- Histórico de aplicações -->
    <div class="admin-card">
      <h3 class="ap-card-title">Últimas aplicações</h3>
      <div style="padding:4px 0;">
        <?php foreach (array_slice($aplicacoes, 0, 10) as $ap): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;
                    padding:10px 18px;border-bottom:1px solid var(--c-border);font-size:12.5px;">
          <div>
            <a href="<?= ADMIN_URL ?>/pedidos/<?= $ap['pedido_id'] ?>" style="font-weight:600;">
              #<?= View::e($ap['pedido_codigo']) ?>
            </a>
            <div style="color:var(--c-text-muted);"><?= View::e($ap['cliente_nome'] ?? '—') ?></div>
          </div>
          <div style="text-align:right;">
            <div style="color:#16a34a;font-weight:700;">−<?= PriceHelper::format((float)$ap['valor_desconto']) ?></div>
            <div style="color:var(--c-text-muted);"><?= date('d/m H:i', strtotime($ap['criado_em'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

</form>

<style>
.promo-grid {
  display: grid;
  grid-template-columns: 1fr 320px;
  gap: 0 20px;
  align-items: start;
}
@media (max-width: 900px) {
  .promo-grid { grid-template-columns: 1fr; }
  .promo-aside { order: -1; }
}
.form-row { display:flex; gap:14px; }
.form-row .form-group { flex:1; }
.form-group { display:flex; flex-direction:column; gap:4px; }
.form-label { font-size:13px; font-weight:700; color:var(--c-dark); }
.form-hint  { font-size:11.5px; color:var(--c-text-muted); }
.form-control--sm { padding:6px 10px; font-size:13px; }
.radio-group { display:flex; flex-direction:column; gap:6px; margin-top:2px; }
.radio-label { display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer; }
.checkbox-label { display:flex; align-items:flex-start; gap:8px; font-size:13px; cursor:pointer; }

/* Toggle */
.toggle-switch { position:relative; display:inline-block; width:42px; height:24px; cursor:pointer; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider { position:absolute; inset:0; background:#d1d5db; border-radius:99px; transition:.2s; }
.toggle-slider:before {
  content:''; position:absolute; width:18px; height:18px;
  left:3px; top:3px; background:#fff; border-radius:50%; transition:.2s;
}
.toggle-switch input:checked + .toggle-slider { background:#16a34a; }
.toggle-switch input:checked + .toggle-slider:before { transform:translateX(18px); }

/* Faixas */
.btn-icon { width:26px; height:26px; border:none; border-radius:6px;
            cursor:pointer; font-size:16px; line-height:1;
            display:flex; align-items:center; justify-content:center; }
.btn-icon--danger { background:#fef2f2; color:#dc2626; }
.btn-icon--danger:hover { background:#fee2e2; }

/* Tag input (escopos) */
.tag-input-container {
  position: relative;  /* âncora o dropdown absolute */
}
.tag-input-wrap {
  border:1px solid var(--c-border); border-radius:8px;
  padding:6px 10px; display:flex; flex-wrap:wrap; gap:6px; cursor:text;
  min-height:40px; align-items:center;
}
.tag-input-wrap:focus-within { border-color:#3b82f6; box-shadow:0 0 0 3px #dbeafe; }
.tag-input-tags { display:flex; flex-wrap:wrap; gap:5px; }
.tag-chip {
  display:flex; align-items:center; gap:5px;
  background:#eff6ff; color:#1e40af; border-radius:99px;
  padding:2px 10px; font-size:12px; font-weight:600;
}
.tag-chip button { border:none; background:none; cursor:pointer;
                   color:#93c5fd; font-size:15px; line-height:1; padding:0; }
.tag-input-search { border:none; outline:none; font-size:13px;
                    background:transparent; min-width:120px; flex:1; }
.tag-suggestions {
  position: absolute;       /* flutua sobre o conteúdo abaixo */
  top: calc(100% + 4px);   /* logo abaixo do input */
  left: 0;
  right: 0;
  z-index: 200;
  border:1px solid var(--c-border); border-radius:8px; background:#fff;
  box-shadow:0 8px 24px rgba(0,0,0,.12);
  display:none;
  max-height:200px; overflow-y:auto;
}
.tag-suggestion-item {
  padding:9px 14px; font-size:13px; cursor:pointer;
  border-bottom: 1px solid var(--c-border, #f1f5f9);
}
.tag-suggestion-item:last-child { border-bottom: none; }
.tag-suggestion-item:hover { background:#f1f5f9; }

.btn-full { width:100%; justify-content:center; }
.flash { padding:10px 16px; border-radius:8px; font-size:13.5px; font-weight:600; }
.flash-success { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
.flash-danger  { background:#fef2f2; border:1px solid #fecaca; color:#dc2626; }
</style>

<script>
(function () {
  // ── Tipo dinâmico ────────────────────────────────────
  var tipoSel = document.getElementById('promo-tipo');
  function mostrarSecao(tipo) {
    document.querySelectorAll('.promo-section').forEach(function (el) {
      el.style.display = el.id === 'section-' + tipo ? '' : 'none';
    });
  }
  tipoSel.addEventListener('change', function () { mostrarSecao(this.value); });
  mostrarSecao(tipoSel.value);

  // ── Gerenciador de faixas ────────────────────────────
  var faixasBody = document.getElementById('faixas-body');
  var rowTemplate = '<tr class="faixa-row">' +
    '<td style="padding:8px 14px;">' +
    '<input type="number" name="faixa_qtd[]" class="form-control form-control--sm" min="1" placeholder="2" required></td>' +
    '<td style="padding:8px 14px;">' +
    '<input type="number" name="faixa_pct[]" class="form-control form-control--sm" min="0.1" step="0.1" placeholder="5" required></td>' +
    '<td style="padding:8px 14px;">' +
    '<button type="button" class="btn-icon btn-icon--danger js-remove-faixa">×</button></td></tr>';

  document.getElementById('btn-add-faixa').addEventListener('click', function () {
    faixasBody.insertAdjacentHTML('beforeend', rowTemplate);
  });
  faixasBody.addEventListener('click', function (e) {
    if (e.target.classList.contains('js-remove-faixa')) {
      var rows = faixasBody.querySelectorAll('.faixa-row');
      if (rows.length > 1) e.target.closest('tr').remove();
    }
  });

  // ── Tag inputs (marcas, categorias, produtos) ────────
  var BASE = '<?= ADMIN_URL ?>';
  var CSRF = '<?= SecurityHelper::generateCsrf() ?>';

  var endpoints = {
    marcas:     BASE + '/api/buscar-marcas',
    categorias: BASE + '/api/buscar-categorias',
    produtos:   BASE + '/api/buscar-produtos',
  };

  // Inicializa tags existentes ao EDITAR uma promoção.
  // Os nomes reais vêm do servidor ($escopoNomes) — sem chamada de API extra.
  // IMPORTANTE: o hidden input já vem pré-populado do PHP (value="3,5,...").
  // adicionarTag usa esses IDs como dedup — se não limparmos antes, ele vê
  // os IDs como "já existentes" e pula a criação dos chips sem erros.
  // Solução: limpar o hidden antes, deixar adicionarTag reconstruir tudo.
  <?php
  $escopoNomes = $escopoNomes ?? ['marcas' => [], 'categorias' => [], 'produtos' => []];
  foreach (['marcas', 'categorias', 'produtos'] as $escopoKey):
      $itens = $escopoNomes[$escopoKey] ?? [];
      if (empty($itens)) continue;
  ?>
  (function () {
    var hidden = document.getElementById('hidden-<?= $escopoKey ?>');
    if (hidden) hidden.value = ''; // limpa o pré-populado do PHP antes de re-construir
    var itens = <?= json_encode(array_values($itens), JSON_UNESCAPED_UNICODE) ?>;
    itens.forEach(function (item) {
      adicionarTag('<?= $escopoKey ?>', item.id, item.nome);
    });
  })();
  <?php endforeach; ?>

  function adicionarTag(tipo, id, nome) {
    var hidden = document.getElementById('hidden-' + tipo);
    var ids    = hidden.value ? hidden.value.split(',').map(Number) : [];
    if (ids.includes(id)) return;
    ids.push(id);
    hidden.value = ids.join(',');

    var chip = document.createElement('div');
    chip.className = 'tag-chip';
    chip.dataset.id = id;
    chip.innerHTML = nome + '<button type="button" aria-label="Remover">×</button>';
    chip.querySelector('button').addEventListener('click', function () {
      var ids2 = hidden.value.split(',').map(Number).filter(function (i) { return i !== id; });
      hidden.value = ids2.join(',');
      chip.remove();
    });
    document.getElementById('tags-' + tipo).appendChild(chip);
    document.getElementById('search-' + tipo).value = '';
    document.getElementById('sug-' + tipo).style.display = 'none';
  }

  ['marcas', 'categorias', 'produtos'].forEach(function (tipo) {
    var input  = document.getElementById('search-' + tipo);
    var sugBox = document.getElementById('sug-' + tipo);
    var timer;

    // Elemento pode não existir numa versão futura do form — guard defensivo
    if (!input || !sugBox) return;

    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = this.value.trim();
      if (q.length < 2) { sugBox.style.display = 'none'; return; }
      timer = setTimeout(function () {
        fetch(endpoints[tipo] + '?q=' + encodeURIComponent(q), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          sugBox.innerHTML = '';
          (data.items || []).forEach(function (item) {
            var div = document.createElement('div');
            div.className = 'tag-suggestion-item';
            // Usa label rico (produtos) ou nome simples
            div.textContent = item.label || item.path || item.nome;
            div.addEventListener('mousedown', function (e) {
              // mousedown em vez de click: evita que o blur do input
              // feche o dropdown antes do click ser registrado
              e.preventDefault();
              adicionarTag(tipo, item.id, item.nome);
            });
            sugBox.appendChild(div);
          });
          sugBox.style.display = (data.items && data.items.length) ? 'block' : 'none';
        })
        .catch(function () { sugBox.style.display = 'none'; });
      }, 280);
    });

    // Fecha ao perder o foco (usa blur com delay pra não conflitar com mousedown)
    input.addEventListener('blur', function () {
      setTimeout(function () { sugBox.style.display = 'none'; }, 150);
    });
    input.addEventListener('focus', function () {
      if (input.value.trim().length >= 2) input.dispatchEvent(new Event('input'));
    });
  });

  // ── Brinde: produto único (um só produto permitido) ──
  (function () {
    var input  = document.getElementById('search-brinde-produto');
    var sugBox = document.getElementById('sug-brinde-produto');
    var hidden = document.getElementById('hidden-brinde-produto');
    if (!input || !sugBox || !hidden) return;

    var timer;
    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = this.value.trim();
      if (q.length < 2) { sugBox.style.display = 'none'; return; }
      timer = setTimeout(function () {
        fetch(BASE + '/api/buscar-produtos?q=' + encodeURIComponent(q), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          sugBox.innerHTML = '';
          (data.items || []).forEach(function (item) {
            var div = document.createElement('div');
            div.className = 'tag-suggestion-item';
            div.textContent = item.label || item.nome;
            div.addEventListener('mousedown', function (e) {
              e.preventDefault();
              // Brinde suporta apenas 1 produto — substitui o anterior
              document.getElementById('tags-brinde-produto').innerHTML = '';
              hidden.value = item.id;
              var chip = document.createElement('div');
              chip.className = 'tag-chip';
              chip.innerHTML = item.nome +
                '<button type="button" aria-label="Remover">×</button>';
              chip.querySelector('button').addEventListener('click', function () {
                hidden.value = '';
                chip.remove();
              });
              document.getElementById('tags-brinde-produto').appendChild(chip);
              input.value = '';
              sugBox.style.display = 'none';
            });
            sugBox.appendChild(div);
          });
          sugBox.style.display = (data.items && data.items.length) ? 'block' : 'none';
        })
        .catch(function () { sugBox.style.display = 'none'; });
      }, 280);
    });
    input.addEventListener('blur', function () {
      setTimeout(function () { sugBox.style.display = 'none'; }, 150);
    });

    // Pré-carrega produto brinde ao editar — nome vem do servidor
    // (evita fetch com limite_id que a API não suporta)
    <?php
    $brindeProduto = $brindeProduto ?? null;
    if (!empty($cfgBrinde['produto_brinde_id']) && !empty($brindeProduto)):
    ?>
    (function () {
      var id   = <?= (int)$cfgBrinde['produto_brinde_id'] ?>;
      var nome = <?= json_encode($brindeProduto['nome']) ?>;
      var chip = document.createElement('div');
      chip.className = 'tag-chip';
      chip.innerHTML = nome + '<button type="button" aria-label="Remover">×</button>';
      chip.querySelector('button').addEventListener('click', function () {
        hidden.value = '';
        chip.remove();
      });
      document.getElementById('tags-brinde-produto').appendChild(chip);
      // hidden já vem pré-populado do PHP com o ID — não precisamos setar aqui
    })();
    <?php endif; ?>
  })();

  // ── Brinde: toggle de campos de gatilho ──────────────
  function atualizarCamposBrindeGatilho() {
    var gatilho = document.querySelector('input[name="brinde_gatilho"]:checked');
    if (!gatilho) return;
    var val = gatilho.value;
    var mostraValor = (val === 'valor' || val === 'ambos');
    var mostraQtd   = (val === 'quantidade' || val === 'ambos');
    var fv = document.getElementById('brinde-field-valor');
    var fq = document.getElementById('brinde-field-qtd');
    if (fv) fv.style.display = mostraValor ? '' : 'none';
    if (fq) fq.style.display = mostraQtd   ? '' : 'none';
  }
  document.querySelectorAll('input[name="brinde_gatilho"]').forEach(function (r) {
    r.addEventListener('change', atualizarCamposBrindeGatilho);
  });
  atualizarCamposBrindeGatilho(); // estado inicial

  // ── Compre X leve Y: preview dinâmico da regra ──────
  function atualizarPreviewCG() {
    var el = document.getElementById('cg-preview');
    if (!el) return;
    var x   = parseInt(document.getElementById('cg-comprar').value) || 3;
    var y   = parseInt(document.getElementById('cg-levar').value)   || 1;
    var pct = parseFloat(document.getElementById('cg-pct').value)   || 100;
    if (y >= x) {
      el.textContent = '⚠️ Y (leva) deve ser menor que X (comprar).';
      el.style.cssText += 'background:#fef2f2;border-color:#fecaca;color:#dc2626;';
      return;
    }
    el.style.cssText += 'background:#eff6ff;border-color:#bfdbfe;color:#1e40af;';
    var paga  = x - y;
    var label = pct >= 100
      ? (y === 1 ? '1 item grátis' : y + ' itens grátis')
      : (y === 1 ? '1 item com ' + pct + '% off' : y + ' itens com ' + pct + '% off');
    el.textContent = '📋 Compre ' + x + ', pague ' + paga +
      ' → o(s) ' + y + ' item(ns) mais barato(s) ganha(m) ' + label + '.';
  }
  ['cg-comprar','cg-levar','cg-pct'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', atualizarPreviewCG);
  });
  atualizarPreviewCG();

  // ── Cashback: preview dinâmico ───────────────────────
  function atualizarPreviewCB() {
    var el = document.getElementById('cb-preview');
    if (!el) return;
    var pct = parseFloat(document.getElementById('cb-pct').value) || 5;
    el.textContent = '📋 A cada R$100 em itens elegíveis (após descontos), o cliente '
      + 'recebe R$' + pct.toFixed(2).replace('.', ',')
      + ' em créditos, disponíveis 7 dias após a entrega.';
  }
  var cbPctEl = document.getElementById('cb-pct');
  if (cbPctEl) cbPctEl.addEventListener('input', atualizarPreviewCB);
  atualizarPreviewCB();

  var toggleHeader = document.getElementById('toggle-ativo-header');
  if (toggleHeader) {
    toggleHeader.addEventListener('change', function () {
      fetch(BASE + '/promocoes/<?= $editando ? $promocao['id'] : 0 ?>/toggle', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ _token: CSRF }),
        credentials: 'same-origin',
      });
      // Sincroniza com o checkbox do form
      var ckForm = document.querySelector('input[name="ativo"]');
      if (ckForm) ckForm.checked = this.checked;
    });
  }
})();
</script>