<?php
/** @var array $listas */
$base = defined('BASE_URL') ? BASE_URL : '';
$camposDisp = CsvImportService::camposDisponiveis();
?>
<div class="em_wrapper em_csv_wizard" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1>Nova importação CSV</h1>
        <a href="<?= $base ?>/admin/email-marketing/csv" class="em_btn">Cancelar</a>
    </div>

    <!-- Steps -->
    <div class="em_steps">
        <div class="em_step em_step_ativo" data-step="1"><span>1</span> Upload</div>
        <div class="em_step" data-step="2"><span>2</span> Mapeamento</div>
        <div class="em_step" data-step="3"><span>3</span> Opções</div>
        <div class="em_step" data-step="4"><span>4</span> Processamento</div>
    </div>

    <!-- ================== STEP 1: UPLOAD ================== -->
    <div class="em_step_painel em_step_ativo" data-painel="1">
        <div class="em_form">
            <p class="em_meta">Envie um arquivo CSV (ou TXT) com seus contatos. Limite: 50MB.</p>

            <form id="em_form_upload" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                <label>
                    Arquivo
                    <input type="file" name="arquivo" accept=".csv,.txt" required>
                </label>

                <details style="margin-bottom:16px;">
                    <summary style="cursor:pointer; color:var(--em-blue); font-size:13px;">Ver formato esperado</summary>
                    <pre style="margin-top:8px; padding:12px; background:var(--em-bg-subtle); border-radius:8px; font-size:12px; overflow:auto;">
email,nome,telefone
joao@exemplo.com,João Silva,(11) 99999-9999
maria@exemplo.com,Maria Santos,
pedro@exemplo.com,Pedro Costa,</pre>
                    <p style="font-size:12px; color:var(--em-text-muted); margin-top:8px;">
                        • Coluna <code>email</code> é obrigatória<br>
                        • Aceita separador <code>,</code> ou <code>;</code><br>
                        • Detecta UTF-8, ISO-8859-1 e Windows-1252 automaticamente
                    </p>
                </details>

                <div class="em_form_actions">
                    <button type="submit" class="em_btn em_btn_primary">Enviar e analisar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================== STEP 2: MAPEAMENTO ================== -->
    <div class="em_step_painel" data-painel="2">
        <div class="em_form">
            <h2 style="margin-top:0;">Mapeamento de colunas</h2>
            <p class="em_meta">Indique qual coluna do seu CSV corresponde a cada campo do sistema.</p>

            <div id="em_preview_box" style="margin-bottom:20px;"></div>

            <form id="em_form_mapeamento">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="importacao_id" id="em_importacao_id" value="">

                <table class="em_table" style="margin-bottom:20px;">
                    <thead>
                        <tr><th>Campo do sistema</th><th>Coluna do CSV</th><th>Obrigatório</th></tr>
                    </thead>
                    <tbody id="em_map_tbody">
                    <?php foreach ($camposDisp as $campo => $info): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($info['label']) ?></strong></td>
                            <td>
                                <select name="mapeamento[<?= $campo ?>]" class="em_map_select" data-campo="<?= $campo ?>">
                                    <option value="">— não mapear —</option>
                                </select>
                            </td>
                            <td><?= $info['obrigatorio'] ? '<span class="em_badge em_st_falhou">Obrigatório</span>' : '<span class="em_badge">Opcional</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="em_form_actions">
                    <button type="button" class="em_btn" data-em-action="csv-voltar" data-para="1">Voltar</button>
                    <button type="button" class="em_btn em_btn_primary" data-em-action="csv-ir" data-para="3">Avançar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================== STEP 3: OPÇÕES ================== -->
    <div class="em_step_painel" data-painel="3">
        <div class="em_form">
            <h2 style="margin-top:0;">Opções de importação</h2>

            <div class="em_form_grid">
                <label>
                    Origem
                    <select name="origem" id="em_op_origem">
                        <option value="importacao" selected>Importação</option>
                        <option value="admin">Admin (manual)</option>
                        <option value="api">API</option>
                        <option value="legado">Legado</option>
                    </select>
                </label>

                <label>
                    Base legal (LGPD)
                    <select name="base_legal" id="em_op_base_legal">
                        <option value="consentimento" selected>Consentimento</option>
                        <option value="legitimo_interesse">Legítimo interesse</option>
                        <option value="execucao_contrato">Execução de contrato</option>
                    </select>
                </label>
            </div>

            <fieldset>
                <legend>Lista de destino</legend>
                <label>
                    Adicionar a uma lista existente
                    <select name="lista_id" id="em_op_lista_id">
                        <option value="">— nenhuma —</option>
                        <?php foreach ($listas as $l): ?>
                            <option value="<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['nome']) ?> (<?= (int)$l['total_contatos'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="em_inline">
                    <input type="checkbox" name="criar_lista" id="em_op_criar_lista" value="1"> Criar lista nova
                </label>

                <label id="em_op_nome_lista_wrap" style="display:none;">
                    Nome da nova lista
                    <input type="text" name="nome_nova_lista" id="em_op_nome_nova_lista" maxlength="120">
                </label>
            </fieldset>

            <fieldset>
                <legend>Comportamento</legend>
                <label class="em_inline">
                    <input type="checkbox" name="atualizar_existentes" id="em_op_atualizar" value="1" checked>
                    Atualizar contatos existentes com dados do CSV
                </label>
                <label class="em_inline">
                    <input type="checkbox" name="ignorar_suprimidos" id="em_op_supressoes" value="1" checked>
                    Ignorar silenciosamente emails em supressão
                </label>
                <label class="em_inline">
                    <input type="checkbox" name="registrar_consentimento" id="em_op_consent" value="1" checked>
                    Registrar consentimento (LGPD)
                </label>
            </fieldset>

            <div class="em_aviso">
                <strong>Importante:</strong> contatos com status <code>descadastrado</code>, <code>bounce</code>,
                <code>complaint</code> ou <code>bloqueado</code> NUNCA serão reativados pela importação —
                isso protege sua reputação. Emails em supressão global também são respeitados.
            </div>

            <div class="em_form_actions">
                <button type="button" class="em_btn" data-em-action="csv-voltar" data-para="2">Voltar</button>
                <button type="button" class="em_btn em_btn_primary" data-em-action="csv-confirmar">Iniciar importação</button>
            </div>
        </div>
    </div>

    <!-- ================== STEP 4: PROCESSAMENTO ================== -->
    <div class="em_step_painel" data-painel="4">
        <div class="em_form">
            <h2 style="margin-top:0;">Processando</h2>
            <p class="em_meta">A importação foi colocada na fila. O worker em background vai processá-la — você pode fechar esta página, ela continua.</p>

            <div class="em_progress">
                <div class="em_progress_bar" id="em_progress_bar" style="width:0%;"></div>
            </div>
            <p class="em_meta" id="em_progress_text">Aguardando worker...</p>

            <div class="em_kpi_grid" style="margin-top:20px;">
                <div class="em_card"><span class="em_card_label">Processadas</span><span class="em_card_value" id="em_cnt_proc">0</span></div>
                <div class="em_card"><span class="em_card_label">Inseridos</span><span class="em_card_value" id="em_cnt_ins">0</span></div>
                <div class="em_card"><span class="em_card_label">Atualizados</span><span class="em_card_value" id="em_cnt_upd">0</span></div>
                <div class="em_card"><span class="em_card_label">Duplicados</span><span class="em_card_value" id="em_cnt_dup">0</span></div>
                <div class="em_card"><span class="em_card_label em_warn">Inválidos</span><span class="em_card_value em_warn" id="em_cnt_inv">0</span></div>
                <div class="em_card"><span class="em_card_label">Suprimidos</span><span class="em_card_value" id="em_cnt_sup">0</span></div>
            </div>

            <div class="em_form_actions">
                <a href="<?= $base ?>/admin/email-marketing/csv" class="em_btn">Ver lista de importações</a>
                <a href="" id="em_link_detalhes" class="em_btn em_btn_primary">Ver detalhes</a>
            </div>
        </div>
    </div>
</div>
