<?php
/**
 * View: Configuração de envios com logística própria.
 *
 * Recebe: $transportadoras, $transportadora, $dados, $cep_loja
 *
 * O corpo (áreas, operação, feriados, mapa) é pintado por logistica.js a
 * partir de window.COB — um template só, como no log do Bling: dois
 * templates, um em PHP e outro em JS, divergem no primeiro ajuste.
 */
$e   = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">'
     . (class_exists('IconLibrary') ? IconLibrary::ref($n, '') : '') . '</span>';
?>

<div class="log_shell" id="logCob" data-base="/admin/logistica/cobertura">

    <div class="log_head">
        <div>
            <h1><?= $ico('caminhao', 15) ?> Configuração de envios com sua logística</h1>
            <p>Áreas de cobertura, custo por área, operação diária e feriados.</p>
        </div>
        <div class="log_head_actions">
            <a href="/admin/logistica/transportadoras" class="log_btn log_btn--sm">
                <?= $ico('caminhao', 15) ?> Transportadoras
            </a>
            <a href="/admin/logistica/regras" class="log_btn log_btn--sm">
                <?= $ico('regras', 15) ?> Regras de frete
            </a>
        </div>
    </div>

<?php if (empty($transportadoras)): ?>

    <div class="log_card">
        <div class="log_state">
            <div class="log_state_ico"><?= $ico('caminhao', 15) ?></div>
            <div class="log_state_title">Nenhuma transportadora com cotação própria</div>
            <div class="log_state_desc">
                Esta tela vale para transportadoras cujo preço é definido aqui — hoje a LogManager.
                Quem cota por API traz o próprio preço e se configura em Transportadoras.
            </div>
        </div>
    </div>

<?php else: ?>

    <?php /* Sempre visível, mesmo com uma transportadora só: esta configuração
             pertence a UMA transportadora, e esconder o seletor faria a tela
             parecer global. Com um item só ele fica desabilitado — informa sem
             fingir que há escolha. */ ?>
    <div class="log_filters cob_escopo">
        <div class="log_field">
            <label>Configurando a transportadora</label>
            <select class="log_select" id="cobTransp"<?= count($transportadoras) > 1 ? '' : ' disabled' ?>>
                <?php foreach ($transportadoras as $t): ?>
                <option value="<?= (int)$t['id'] ?>"<?= (int)$t['id'] === (int)($transportadora['id'] ?? 0) ? ' selected' : '' ?>>
                    <?= $e($t['nome']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <p class="log_muted cob_escopo_nota">
            Áreas, custos e operação valem <strong>só para esta transportadora</strong>.
            A lista traz apenas quem tem preço próprio — quem cota por API traz o preço dela.
        </p>
        <div class="log_filters_spacer"></div>
    </div>

    <!-- Cartões do topo -->
    <div class="log_card cob_resumo" id="cobResumo"></div>

    <!-- Áreas + mapa -->
    <h2 class="cob_sec">Escolha suas áreas de cobertura</h2>
    <div class="cob_cobertura">
        <div class="log_card cob_areas">
            <div class="cob_areas_head">
                <strong>Ativar áreas</strong>
                <button type="button" class="log_btn log_btn--primary log_btn--sm" id="cobNovaArea">
                    <?= $ico('add', 15) ?> Nova área
                </button>
            </div>
            <div id="cobListaAreas"></div>
            <div class="cob_teste">
                <label>Testar um CEP</label>
                <div class="log_cep_row">
                    <input class="log_input" id="cobCepTeste" placeholder="00000-000" maxlength="9">
                    <button type="button" class="log_btn log_btn--sm" id="cobTestar">
                        <?= $ico('search', 15) ?> Ver área
                    </button>
                </div>
                <div class="cob_teste_res" id="cobTesteRes" hidden></div>
            </div>
        </div>
        <div class="log_card cob_mapa_wrap">
            <div id="cobMapa" class="cob_mapa"></div>
            <p class="log_muted cob_mapa_nota">
                Os círculos são <strong>aproximação visual</strong>, posicionados à mão.
                Quem define a cobertura de verdade são as faixas de CEP de cada área.
            </p>
        </div>
    </div>

    <!-- Operação diária -->
    <h2 class="cob_sec">Ajuste sua operação diária</h2>
    <div class="log_card">
        <div class="log_table_wrap">
            <table class="log_table cob_op">
                <thead>
                    <tr>
                        <th style="width:190px">Dias de operação</th>
                        <th style="width:150px">Entrega no mesmo dia</th>
                        <th style="width:140px">Horário de corte</th>
                        <th style="width:200px">Horários de entrega</th>
                        <th style="width:140px">Máximo de envios</th>
                    </tr>
                </thead>
                <tbody id="cobOperacao"></tbody>
            </table>
        </div>
    </div>

    <!-- Extras -->
    <h2 class="cob_sec">Configurações adicionais</h2>
    <div class="cob_extras">
        <div class="log_card">
            <div class="cob_bloco_tit"><?= $ico('calendar-today', 15) ?> Operação nos próximos feriados</div>
            <div id="cobFeriados"></div>
        </div>
        <div class="log_card">
            <div class="cob_bloco_tit"><?= $ico('package', 15) ?> Dimensões máximas por envio</div>
            <div class="log_form_grid cob_dims">
                <div class="log_field"><label>Largura</label>
                    <div class="cob_unidade"><input class="log_input" type="number" step="0.1" id="cobLargura"><span>cm</span></div></div>
                <div class="log_field"><label>Altura</label>
                    <div class="cob_unidade"><input class="log_input" type="number" step="0.1" id="cobAltura"><span>cm</span></div></div>
                <div class="log_field"><label>Profundidade</label>
                    <div class="cob_unidade"><input class="log_input" type="number" step="0.1" id="cobProfundidade"><span>cm</span></div></div>
                <div class="log_field"><label>Peso</label>
                    <div class="cob_unidade"><input class="log_input" type="number" step="0.1" id="cobPeso"><span>kg</span></div></div>
            </div>
            <p class="log_muted cob_dims_nota">
                É o teto exibido ao operador. O que de fato limita um envio é a
                embalagem escolhida, em <a href="/admin/logistica/embalagens">Embalagens</a>.
            </p>
        </div>
    </div>

    <div class="cob_salvar">
        <span class="log_muted" id="cobStatus"></span>
        <button type="button" class="log_btn log_btn--primary" id="cobSalvar">
            <?= $ico('save', 15) ?> Salvar alterações
        </button>
    </div>

<?php endif; ?>

</div>

<?php // Leaflet + OpenStreetMap: sem chave de API, e só nesta tela. Carregado
      // aqui no corpo porque o layout só injeta os scripts do módulo depois —
      // então L já existe quando o logistica.js roda. Mesmo padrão do Drawflow
      // em /admin/fluxos. ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
    window.COB = {
        base    : '/admin/logistica/cobertura',
        transp  : <?= (int)($transportadora['id'] ?? 0) ?>,
        cepLoja : <?= json_encode($cep_loja ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        // Os JSON_HEX_* impedem que um nome de área feche a tag <script>.
        dados   : <?= json_encode($dados ?? null,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
</script>
