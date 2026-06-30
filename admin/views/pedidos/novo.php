<?php
// views/admin/pedidos/novo.php
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/pedidos" class="back-link">← Pedidos</a>
      <h1 class="admin-page-title">Novo pedido manual</h1>
      <p class="admin-page-sub">Crie um pedido em nome de um cliente existente.</p>
    </div>
  </div>

  <form id="form-novo-pedido" novalidate>

    <div class="ap-grid">

      <!-- ═══ COLUNA PRINCIPAL ════════════════════════════ -->
      <div class="ap-main">

        <!-- 1. CLIENTE ─────────────────────────────────── -->
        <div class="admin-card" style="margin-bottom:16px;z-index: 6;">
          <h3 class="ap-card-title" style="padding:16px 20px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Cliente
          </h3>
          <div style="padding:14px 20px 18px;">
            <div style="position:relative;">
              <input type="text" id="busca-cliente" class="form-control"
                     placeholder="Buscar por nome, e-mail ou CPF…" autocomplete="off">
              <div id="dropdown-clientes" class="np-dropdown" style="display:none;"></div>
            </div>
            <input type="hidden" id="cliente-id" name="cliente_id">

            <!-- Card do cliente selecionado -->
            <div id="cliente-selecionado" style="display:none;margin-top:12px;"
                 class="np-selected-card">
              <div id="cliente-info"></div>
              <button type="button" class="btn btn-ghost btn-sm" id="btn-limpar-cliente">
                Trocar cliente
              </button>
            </div>

            <!-- Endereços do cliente -->
            <div id="bloco-enderecos" style="display:none;margin-top:14px;">
              <label class="form-label-xs" style="margin-bottom:6px;">Endereço de entrega</label>
              <div id="lista-enderecos"></div>
              <input type="hidden" id="endereco-id" name="endereco_id">
            </div>
          </div>
        </div>

        <!-- 2. PRODUTOS ─────────────────────────────────── -->
        <div class="admin-card" style="margin-bottom:16px;z-index: 5;">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--c-border);">
            <h3 style="font-size:14px;font-weight:800;margin:0;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
              Produtos
            </h3>
          </div>

          <!-- Busca de produto -->
          <div style="padding:14px 20px;border-bottom:1px solid #f8fafc;">
            <div style="position:relative;">
              <input type="text" id="np-busca-produto" class="form-control"
                     placeholder="Buscar produto por nome ou SKU…" autocomplete="off">
              <div id="np-dropdown-produto" class="np-dropdown" style="display:none;"></div>
            </div>
          </div>

          <!-- Lista de itens adicionados -->
          <div id="np-itens-list">
            <div id="np-itens-empty" style="padding:24px 20px;text-align:center;color:var(--c-text-muted);font-size:13.5px;">
              Nenhum item adicionado ainda.
            </div>
          </div>
          <input type="hidden" id="itens-json" name="itens" value="[]">

          <!-- Totais dinâmicos -->
          <div id="np-totais-block" style="padding:14px 20px;border-top:1px solid var(--c-border);display:none;">
            <div style="display:flex;justify-content:space-between;font-size:13.5px;margin-bottom:6px;">
              <span>Subtotal</span><strong id="np-subtotal">R$ 0,00</strong>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13.5px;color:#16a34a;margin-bottom:6px;" id="np-desconto-row" style="display:none;">
              <span>Desconto</span><strong id="np-desconto-val">R$ 0,00</strong>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13.5px;margin-bottom:10px;">
              <span>Frete</span><strong id="np-frete-val">R$ 0,00</strong>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:18px;font-weight:900;color:var(--c-dark);padding-top:10px;border-top:2px solid var(--c-border);">
              <strong>Total</strong><strong id="np-total">R$ 0,00</strong>
            </div>
          </div>
        </div>

        <!-- 3. OBSERVAÇÕES ──────────────────────────────── -->
        <div class="admin-card">
          <h3 class="ap-card-title" style="padding:14px 20px;">Observações</h3>
          <div style="padding:14px 20px 16px;display:flex;flex-direction:column;gap:12px;">
            <div>
              <label class="form-label-xs">Observação para o cliente</label>
              <textarea name="observacao_cliente" class="form-control" rows="2"
                        placeholder="Visível ao cliente…" style="resize:vertical;"></textarea>
            </div>
            <div>
              <label class="form-label-xs">Observação interna (admin)</label>
              <textarea name="observacao_interna" class="form-control" rows="2"
                        placeholder="Somente para a equipe…" style="resize:vertical;"></textarea>
            </div>
          </div>
        </div>

      </div><!-- /.ap-main -->

      <!-- ═══ COLUNA LATERAL ══════════════════════════════ -->
      <div class="ap-aside">

        <!-- PAGAMENTO ──────────────────────────────────── -->
        <div class="admin-card ap-action-card">
          <h3 class="ap-card-title">Pagamento</h3>
          <div style="padding:14px 18px 16px;display:flex;flex-direction:column;gap:10px;">

            <div>
              <label class="form-label-xs">Forma de pagamento</label>
              <select name="forma_pagamento" id="np-forma-pag" class="form-control">
                <option value="manual">Manual / Outro</option>
                <option value="pix">Pix</option>
                <option value="boleto">Boleto</option>
                <option value="cartao">Cartão de crédito</option>
                <option value="dinheiro">Dinheiro</option>
              </select>
            </div>

            <div id="np-campos-cartao" style="display:none;">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div>
                  <label class="form-label-xs">Bandeira</label>
                  <input type="text" name="cartao_bandeira" class="form-control"
                         placeholder="visa, master…">
                </div>
                <div>
                  <label class="form-label-xs">Últimos 4</label>
                  <input type="text" name="cartao_ultimos_4" class="form-control"
                         maxlength="4" placeholder="0000">
                </div>
              </div>
              <div style="margin-top:8px;">
                <label class="form-label-xs">Parcelas</label>
                <input type="number" name="parcelas" class="form-control" value="1" min="1" max="24">
              </div>
            </div>

            <div>
              <label class="form-label-xs">Status do pagamento</label>
              <select name="status_pagamento" class="form-control">
                <option value="pendente">Pendente</option>
                <option value="aguardando">Aguardando</option>
                <option value="aprovado">Aprovado</option>
              </select>
            </div>

            <div>
              <label class="form-label-xs">Data do pagamento</label>
              <input type="datetime-local" name="pago_em" class="form-control">
            </div>

          </div>
        </div>

        <!-- FRETE ─────────────────────────────────────── -->
        <div class="admin-card ap-action-card" style="margin-top:14px;">
          <h3 class="ap-card-title">Frete e desconto</h3>
          <div style="padding:14px 18px 16px;display:flex;flex-direction:column;gap:10px;">
            <div>
              <label class="form-label-xs">Valor do frete (R$)</label>
              <input type="text" name="frete" id="np-frete" class="form-control"
                     placeholder="0,00" value="0">
            </div>
            <div>
              <label class="form-label-xs">Descrição do frete</label>
              <input type="text" name="frete_descricao" class="form-control"
                     placeholder="Ex: Correios Sedex">
            </div>
            <div>
              <label class="form-label-xs">Desconto manual (R$)</label>
              <input type="text" name="desconto" id="np-desconto" class="form-control"
                     placeholder="0,00" value="0">
            </div>
          </div>
        </div>

        <!-- STATUS PEDIDO ─────────────────────────────── -->
        <div class="admin-card ap-action-card" style="margin-top:14px;">
          <h3 class="ap-card-title">Status do pedido</h3>
          <div style="padding:14px 18px 16px;">
            <select name="status_pedido" class="form-control">
              <option value="aguardando_pagamento">Aguardando pagamento</option>
              <option value="pagamento_aprovado">Pagamento aprovado</option>
              <option value="em_separacao">Em separação</option>
            </select>
          </div>
        </div>

        <!-- AÇÕES FINAIS ──────────────────────────────── -->
        <div class="admin-card ap-action-card" style="margin-top:14px;">
          <div style="padding:16px 18px;display:flex;flex-direction:column;gap:10px;">
            <label class="toggle-field">
              <input type="checkbox" name="notificar_cliente" value="1" checked>
              <span class="toggle-slider"></span>
              <span>Notificar cliente por e-mail</span>
            </label>
            <div id="np-error" class="form-alert" style="display:none;"></div>
            <button type="submit" class="btn btn-primary btn-full" id="btn-criar-pedido">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
              Criar pedido
            </button>
            <a href="<?= ADMIN_URL ?>/pedidos" class="btn btn-outline btn-full">Cancelar</a>
          </div>
        </div>

      </div><!-- /.ap-aside -->
    </div><!-- /.ap-grid -->
  </form>
</div>