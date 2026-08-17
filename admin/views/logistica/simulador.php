<?php
/**
 * View: Simulador de frete.
 * Roda a Calculadora (empacotamento + transportadoras ativas + regras).
 * Resultado renderizado por frete.js.
 */
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">' . (class_exists('IconLibrary') ? IconLibrary::render($n) : '') . '</span>';
?>


<div class="log_shell" id="logSim" data-base="/admin/logistica/simulador">

    <div class="log_head">
        <div>
            <h1><?= $ico('simulador', 22) ?> Simulador de frete</h1>
            <p>Cotação real das transportadoras ativas, com o motor de regras aplicado.</p>
        </div>
        <div class="log_head_actions">
            <a href="/admin/logistica/regras" class="log_btn log_btn--sm"><?= $ico('rule', 25) ?> Regras</a>
        </div>
    </div>

    <div class="log_grid_sim">
        <!-- Formulário -->
        <div class="log_card">
            <div class="log_card_head"><h3>Parâmetros</h3></div>
            <div class="log_card_body">
                <form id="logSimForm" onsubmit="return false;">
                    <div class="log_form_grid">
                        <div class="log_field"><label>CEP destino *</label><input class="log_input" name="cep_destino" placeholder="00000-000"></div>
                        <div class="log_field"><label>CEP origem</label><input class="log_input" name="cep_origem" placeholder="(usa o da transportadora)"></div>
                        <div class="log_field"><label>UF</label><input class="log_input" name="uf" maxlength="2" placeholder="RS"></div>
                        <div class="log_field"><label>Cidade</label><input class="log_input" name="cidade"></div>
                        <div class="log_field"><label>Canal</label>
                            <select class="log_select" name="canal">
                                <option value="site">site</option>
                                <option value="app">app</option>
                                <option value="marketplace">marketplace</option>
                            </select>
                        </div>
                        <div class="log_field"><label>Tipo de cliente</label>
                            <select class="log_select" name="tipo_cliente">
                                <option value="">—</option>
                                <option value="novo">novo</option>
                                <option value="recorrente">recorrente</option>
                                <option value="vip">vip</option>
                            </select>
                        </div>
                        <div class="log_field"><label>Valor da mercadoria (R$)</label><input class="log_input" type="number" step="0.01" name="valor_mercadoria" placeholder="(soma dos itens)"></div>
                        <div class="log_field log_field--check">
                            <label class="log_check"><input type="checkbox" name="seguro"> Seguro / valor declarado</label>
                        </div>
                    </div>

                    <div class="log_fieldset" style="margin-top:14px;">
                        <h4>Itens <button type="button" class="log_btn log_btn--sm js-item-add"><i class="bi bi-plus-lg"></i> Adicionar</button></h4>
                        <div id="logSimItens"></div>
                    </div>

                    <div style="margin-top:16px;display:flex;gap:8px;">
                        <button type="button" class="log_btn log_btn--primary js-cotar"><?= $ico('calculadora', 15) ?> Cotar</button>
                        <button type="button" class="log_btn js-limpar">Limpar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Resultado -->
        <div class="log_card">
            <div class="log_card_head">
                <h3>Resultado</h3>
                <span class="log_muted" id="logSimResumo"></span>
            </div>
            <div class="log_card_body" id="logSimResultado">
                <div class="log_state">
                    <div class="log_state_ico"><?= $ico('calculadora', 22) ?></div>
                    <div class="log_state_title">Preencha os parâmetros e clique em Cotar</div>
                    <div class="log_state_desc">As opções aparecem aqui, já com as regras aplicadas.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>window.LOG_SIM_BASE = '/admin/logistica/simulador';</script>

