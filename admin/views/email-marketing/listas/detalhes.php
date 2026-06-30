<?php
/**
 * admin/views/email-marketing/listas/detalhes.php
 *
 * @var array $lista
 * @var array $resultado
 * @var array $filtros
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$itens = $resultado['itens'];
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>" data-lista="<?= (int)$lista['id'] ?>">
    <div class="em_header">
        <div>
            <h1><?= htmlspecialchars($lista['nome']) ?></h1>
            <?php if (!empty($lista['descricao'])): ?>
                <p class="em_meta" style="margin-top:6px;"><?= htmlspecialchars($lista['descricao']) ?></p>
            <?php endif; ?>
        </div>
        <div class="em_actions">
            <a href="<?= $base ?>/admin/email-marketing/listas" class="em_btn">Voltar</a>
            <button type="button" class="em_btn em_btn_primary" data-em-action="lista-add-modal">
                + Adicionar contatos
            </button>
        </div>
    </div>

    <div class="em_kpi_grid">
        <div class="em_card">
            <span class="em_card_label">Contatos ativos</span>
            <span class="em_card_value"><?= number_format($lista['total_contatos'], 0, ',', '.') ?></span>
            <small>na lista</small>
        </div>
        <div class="em_card">
            <span class="em_card_label">Status</span>
            <span class="em_card_value" style="font-size:18px;">
                <?= $lista['ativo'] ? 'Ativa' : 'Inativa' ?>
            </span>
            <small>Criada em <?= date('d/m/Y', strtotime($lista['criado_em'])) ?></small>
        </div>
        <div class="em_card">
            <span class="em_card_label">Última atualização</span>
            <span class="em_card_value" style="font-size:18px;">
                <?= date('d/m/Y', strtotime($lista['atualizado_em'])) ?>
            </span>
            <small><?= date('H:i', strtotime($lista['atualizado_em'])) ?></small>
        </div>
    </div>

    <form class="em_filtros" method="get" style="margin-top:24px;">
        <input type="text" name="busca" placeholder="Buscar email ou nome..." value="<?= htmlspecialchars($filtros['busca']) ?>">
        <select name="status_contato">
            <option value="">Todos os status</option>
            <?php foreach (['ativo','descadastrado','bounce','complaint','bloqueado','pendente'] as $s): ?>
                <option value="<?= $s ?>" <?= $filtros['status_contato'] === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <button class="em_btn" type="submit">Filtrar</button>
    </form>

    <p class="em_meta">Mostrando <?= count($itens) ?> de <?= number_format($resultado['total'], 0, ',', '.') ?></p>

    <table class="em_table">
        <thead>
            <tr>
                <th>Email</th>
                <th>Nome</th>
                <th>Origem</th>
                <th>Status</th>
                <th>Adicionado</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($itens)): ?>
            <tr><td colspan="6" class="em_empty">
                Esta lista ainda não tem contatos.
                Clique em <strong>Adicionar contatos</strong> acima para começar.
            </td></tr>
        <?php else: foreach ($itens as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['email']) ?></td>
                <td><?= htmlspecialchars($c['nome'] ?: '—') ?></td>
                <td><span class="em_badge em_or_<?= htmlspecialchars($c['origem']) ?>"><?= htmlspecialchars($c['origem']) ?></span></td>
                <td><span class="em_badge em_st_<?= htmlspecialchars($c['status_contato']) ?>"><?= htmlspecialchars($c['status_contato']) ?></span></td>
                <td><?= date('d/m/Y H:i', strtotime($c['criado_em'])) ?></td>
                <td>
                    <button type="button" class="em_link em_warn"
                            data-em-action="lista-remover-contato"
                            data-contato-id="<?= (int)$c['id'] ?>"
                            data-email="<?= htmlspecialchars($c['email']) ?>">Remover</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php
    $totalPag = max(1, (int)ceil($resultado['total'] / $resultado['por_pagina']));
    if ($totalPag > 1):
    ?>
    <div class="em_pag">
        <?php for ($i = 1; $i <= $totalPag; $i++):
            if ($i > 10 && $i !== $totalPag && abs($i - $resultado['pagina']) > 3) continue; ?>
            <a href="?<?= http_build_query(array_merge($filtros, ['pagina' => $i])) ?>"
               class="<?= $i === $resultado['pagina'] ? 'em_pag_atual' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ===================== MODAL "ADICIONAR CONTATOS" ===================== -->
<div id="em_modal_lista_add" class="em_modal" style="display:none;">
    <div class="em_modal_box em_modal_grande">
        <h3>Adicionar contatos à lista</h3>

        <!-- Tabs -->
        <div class="em_tabs" role="tablist">
            <button type="button" class="em_tab em_tab_ativa" data-tab="busca" role="tab">
                Buscar existentes
            </button>
            <button type="button" class="em_tab" data-tab="lote" role="tab">
                Colar lista
            </button>
            <button type="button" class="em_tab" data-tab="csv" role="tab">
                Importar CSV
            </button>
        </div>

        <!-- Aba: Buscar existentes -->
        <div class="em_tab_painel em_tab_painel_ativa" data-painel="busca">
            <p class="em_meta">
                Busque por email ou nome em contatos já cadastrados. Selecione um ou mais e clique em Adicionar.
            </p>
            <input type="text" id="em_busca_contato" placeholder="Digite ao menos 2 caracteres..." autocomplete="off">

            <div id="em_busca_resultados" class="em_busca_lista" style="margin-top:12px;"></div>

            <div class="em_form_actions">
                <button type="button" class="em_btn" data-em-close>Fechar</button>
                <button type="button" class="em_btn em_btn_primary" id="em_busca_add_btn" disabled>
                    Adicionar selecionados
                </button>
            </div>
        </div>

        <!-- Aba: Colar lista -->
        <div class="em_tab_painel" data-painel="lote">
            <p class="em_meta">
                Cole abaixo um email por linha (ou separados por vírgula/ponto-e-vírgula).
                Emails novos serão criados como contatos com origem <strong>admin</strong>
                e base legal <strong>consentimento</strong>.
            </p>
            <form id="em_form_lote">
                <?= SecurityHelper::csrfField() ?>
                <input type="hidden" name="lista_id" value="<?= (int)$lista['id'] ?>">
                <label>
                    Emails
                    <textarea name="emails" rows="10" placeholder="cliente1@exemplo.com&#10;cliente2@exemplo.com&#10;cliente3@exemplo.com" required></textarea>
                </label>

                <div class="em_aviso">
                    <strong>Importante:</strong> só inclua emails que aceitaram receber sua comunicação.
                    Emails na lista de supressão serão automaticamente ignorados.
                </div>

                <div class="em_form_actions">
                    <button type="button" class="em_btn" data-em-close>Cancelar</button>
                    <button type="submit" class="em_btn em_btn_primary">Adicionar</button>
                </div>
            </form>
        </div>

        <!-- Aba: Importar CSV -->
        <div class="em_tab_painel" data-painel="csv">
            <p class="em_meta">
                Envie um arquivo CSV com a coluna <code>email</code> (obrigatória) e opcionalmente <code>nome</code>.
                Separador pode ser vírgula ou ponto-e-vírgula. Tamanho máximo: 10MB.
            </p>

            <details style="margin-bottom:14px;">
                <summary style="cursor:pointer; font-size:13px; color:var(--em-blue);">Ver exemplo de CSV</summary>
                <pre style="margin-top:8px; padding:12px; background:var(--em-bg-subtle); border-radius:8px; font-size:12px; overflow:auto;"><code>email,nome
joao@exemplo.com,João Silva
maria@exemplo.com,Maria Santos
pedro@exemplo.com,</code></pre>
            </details>

            <form id="em_form_csv" enctype="multipart/form-data">
                <?= SecurityHelper::csrfField() ?>
                <input type="hidden" name="lista_id" value="<?= (int)$lista['id'] ?>">
                <label>
                    Arquivo CSV
                    <input type="file" name="arquivo" accept=".csv,.txt" required>
                </label>

                <div class="em_aviso">
                    Emails inválidos, duplicados e suprimidos serão automaticamente filtrados.
                    O resultado da importação aparecerá ao final.
                </div>

                <div class="em_form_actions">
                    <button type="button" class="em_btn" data-em-close>Cancelar</button>
                    <button type="submit" class="em_btn em_btn_primary">Importar</button>
                </div>
            </form>

            <div id="em_csv_resultado" style="display:none; margin-top:16px;"></div>
        </div>
    </div>
</div>
