<link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/checkout-premium.css') ?>">

<?php
// views/checkout/index.php
// Checkout em 3 etapas: identificação → endereço+frete → pagamento
$etapa = Session::get('checkout_etapa', 1);
if (Session::isClienteLogado()) $etapa = max($etapa, 2);
?>

<div class="checkout-body">

<header class="checkout-header">
  <a href="<?= BASE_URL ?>" class="checkout-logo">
    <span><?= View::e($config['nome']) ?></span>
  </a>
  <div class="checkout-steps" id="checkout-steps">
    <div class="checkout-step <?= $etapa >= 1 ? 'done' : '' ?> <?= $etapa == 1 ? 'active' : '' ?>"
         data-step="1">
      <span class="step-num">1</span>
      <span class="step-label">Identificação</span>
    </div>
    <div class="checkout-step-sep"></div>
    <div class="checkout-step <?= $etapa >= 2 ? 'done' : '' ?> <?= $etapa == 2 ? 'active' : '' ?>"
         data-step="2">
      <span class="step-num">2</span>
      <span class="step-label">Entrega</span>
    </div>
    <div class="checkout-step-sep"></div>
    <div class="checkout-step <?= $etapa >= 3 ? 'active' : '' ?>" data-step="3">
      <span class="step-num">3</span>
      <span class="step-label">Pagamento</span>
    </div>
  </div>
  <a href="<?= BASE_URL ?>/carrinho" class="checkout-back-cart">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    Voltar ao carrinho
  </a>
</header>

<main class="checkout-main">

  <?= View::partial('partials/banner-render', ['zona' => 'checkout_topos']); ?>
  <div class="checkout-container">
    <div class="checkout-layout">

      <!-- ── Coluna esquerda: etapas ──────────────────── -->
      <div class="checkout-steps-content">

        <!-- ETAPA 1: Identificação -->
        <!-- ETAPA 1: Identificação -->
        <section class="checkout-section" id="section-identificacao"
                style="<?= $etapa > 1 ? 'display:none' : '' ?>">
          <div class="section-head">
            <h2>
              <span class="section-num">1</span>
              Acesso à sua conta
            </h2>
            <p class="section-sub">Crie sua conta em poucos segundos para continuar sua compra com segurança.</p>
          </div>
        
          <?php if (!$cliente_logado): ?>
        
          <!-- ══════════ TABS LOGIN / CADASTRO ══════════ -->
          <div class="ident-tabs">
            <button class="ident-tab active" data-tab="login">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                  stroke-width="2.5" stroke-linecap="round">
                <path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/>
                <polyline points="10 17 15 12 10 7"/>
                <line x1="15" y1="12" x2="3" y2="12"/>
              </svg>
              Já tenho conta
            </button>
            <button class="ident-tab" data-tab="cadastro">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                  stroke-width="2.5" stroke-linecap="round">
                <path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                <circle cx="8.5" cy="7" r="4"/>
                <line x1="20" y1="8" x2="20" y2="14"/>
                <line x1="23" y1="11" x2="17" y2="11"/>
              </svg>
              Criar conta rápida
            </button>
          </div>
        
          <!-- ── PAINEL LOGIN ───────────────────────────── -->
          <div class="ident-panel" id="panel-login">
            <form id="form-checkout-login" novalidate>
              <?= SecurityHelper::csrfField() ?>
              <input type="hidden" name="acao" value="login">
              <div class="form-group">
                <label for="login-email">E-mail</label>
                <input type="email" id="login-email" name="email" class="form-control"
                      placeholder="seu@email.com" required autocomplete="email">
                <span class="field-error" id="err-login-email"></span>
              </div>
              <div class="form-group">
                <label for="login-senha">
                  Senha
                  <a href="<?= BASE_URL ?>/recuperar-senha" class="label-link" target="_blank">Esqueceu?</a>
                </label>
                <div class="input-password-wrapper">
                  <input type="password" id="login-senha" name="senha" class="form-control"
                        placeholder="Sua senha" required autocomplete="current-password">
                  <button type="button" class="toggle-password" data-target="login-senha" aria-label="Mostrar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
                <span class="field-error" id="err-login-senha"></span>
              </div>
              <div id="login-error" class="form-alert form-alert--error" style="display:none;"></div>
              <button type="submit" class="btn btn-primary btn-full">
                Entrar e continuar
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.5" stroke-linecap="round">
                  <line x1="5" y1="12" x2="19" y2="12"/>
                  <polyline points="12 5 19 12 12 19"/>
                </svg>
              </button>
            </form>
          </div>
        
          <!-- ── PAINEL CADASTRO RÁPIDO (sem senha) ─────── -->
          <div class="ident-panel" id="panel-cadastro" style="display:none;">
        
            <div class="ident-info-box">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                  stroke-width="2" stroke-linecap="round">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
              </svg>
              Sem senha agora. Validamos pelo seu e-mail e você define depois.
            </div>
        
            <form id="form-checkout-cadastro" novalidate>
              <?= SecurityHelper::csrfField() ?>
              <input type="hidden" name="acao" value="cadastro_rapido">
        
              <div class="form-group">
                <label for="cad-nome">Nome completo</label>
                <input type="text" id="cad-nome" name="nome" class="form-control"
                      placeholder="Seu nome completo" required autocomplete="name">
                <span class="field-error" id="err-cad-nome"></span>
              </div>
        
              <div class="form-group">
                <label for="cad-email">E-mail</label>
                <input type="email" id="cad-email" name="email" class="form-control"
                      placeholder="seu@email.com" required autocomplete="email">
                <span class="field-error" id="err-cad-email"></span>
              </div>
        
              <div class="form-group">
                <label for="cad-whatsapp">WhatsApp</label>
                <input type="tel" id="cad-whatsapp" name="whatsapp"
                      class="form-control phone-mask"
                      placeholder="(00) 00000-0000" maxlength="15"
                      required autocomplete="tel">
                <span class="field-error" id="err-cad-whatsapp"></span>
                <small class="form-help">Para acompanhar o status do pedido</small>
              </div>
        
              <div id="cad-error" class="form-alert form-alert--error" style="display:none;"></div>
        
              <button type="submit" class="btn btn-primary btn-full" id="btn-cad-submit">
                <span class="btn-text">Continuar</span>
                <svg class="btn-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <line x1="5" y1="12" x2="19" y2="12"/>
                  <polyline points="12 5 19 12 12 19"/>
                </svg>
              </button>
        
              <p class="ident-legal">
                Ao criar sua conta, você aceita nossos
                <a href="<?= BASE_URL ?>/termos-de-uso" target="_blank">Termos</a> e a
                <a href="<?= BASE_URL ?>/politica-privacidade" target="_blank">Política de Privacidade</a>.
              </p>
            </form>
          </div>
        
          <!-- ── PAINEL VERIFICAÇÃO DE E-MAIL (novo) ────── -->
          <div class="ident-panel" id="panel-verificacao" style="display:none;">
        
            <div class="verify-icon-wrap">
              <div class="verify-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                  <polyline points="22,6 12,13 2,6"/>
                </svg>
              </div>
            </div>
        
            <h3 class="verify-title">Confirme seu e-mail</h3>
            <p class="verify-text">
              Enviamos um código de 6 dígitos para
              <strong id="verify-email-display">seu e-mail</strong>.
              Essa etapa protege sua conta e garante que você receba atualizações do pedido.
            </p>
        
            <form id="form-checkout-verify" novalidate>
              <?= SecurityHelper::csrfField() ?>
              <input type="hidden" name="acao" value="verificar_codigo">
              <input type="hidden" name="codigo" id="verify-codigo-hidden">
        
              <!-- Inputs de 6 dígitos -->
              <div class="verify-code-wrap">
                <input type="text" class="verify-digit" maxlength="1" inputmode="numeric"
                      pattern="[0-9]" autocomplete="one-time-code" data-index="0">
                <input type="text" class="verify-digit" maxlength="1" inputmode="numeric"
                      pattern="[0-9]" data-index="1">
                <input type="text" class="verify-digit" maxlength="1" inputmode="numeric"
                      pattern="[0-9]" data-index="2">
                <span class="verify-sep">-</span>
                <input type="text" class="verify-digit" maxlength="1" inputmode="numeric"
                      pattern="[0-9]" data-index="3">
                <input type="text" class="verify-digit" maxlength="1" inputmode="numeric"
                      pattern="[0-9]" data-index="4">
                <input type="text" class="verify-digit" maxlength="1" inputmode="numeric"
                      pattern="[0-9]" data-index="5">
              </div>
        
              <div id="verify-error" class="form-alert form-alert--error" style="display:none;"></div>
              <div id="verify-success" class="form-alert form-alert--success" style="display:none;"></div>
        
              <button type="submit" class="btn btn-primary btn-full" id="btn-verify-submit" disabled>
                Validar e continuar
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.5" stroke-linecap="round">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
              </button>
        
              <div class="verify-actions">
                <button type="button" class="btn-link" id="btn-resend-code">
                  Reenviar código
                  <span id="resend-timer" style="display:none">(em <span id="resend-seconds">30</span>s)</span>
                </button>
                <span class="verify-actions-sep">·</span>
                <button type="button" class="btn-link" id="btn-edit-email">
                  Editar e-mail
                </button>
              </div>
        
            </form>
          </div>
        
          <?php else: ?>
          <!-- ══════════ JÁ LOGADO ══════════ -->
          <div class="ident-logged">
            <div class="ident-logged-avatar">
              <?= strtoupper(mb_substr($cliente_nome, 0, 1)) ?>
            </div>
            <div class="ident-logged-info">
              <strong><?= View::e($cliente_nome) ?></strong>
              <span><?= View::e($cliente_email) ?></span>
            </div>
            <button type="button" class="btn btn-outline btn-sm" id="btn-next-step-1">
              Continuar
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
          </div>
          <?php endif; ?>
        </section>

        <?php
          // ════════════════════════════════════════════════════════
          // SUBSTITUIR o conteúdo da <section id="section-entrega">
          // até o início de <!-- Seleção de frete --> em
          // views/checkout/index.php.
          //
          // Mantém todos os IDs do JS original:
          //   saved-addresses, address-card, address-radio,
          //   address-form, end-nome, end-cep, end-logradouro,
          //   end-numero, end-comp, end-bairro, end-cidade,
          //   end-estado, end-tel, btn-save-address,
          //   btn-toggle-new-address
          //
          // ADICIONA:
          //   - CEP em hero (destaque)
          //   - Feedback "Endereço encontrado"
          //   - Mensagem de confiança
          //   - Campo de observações para entrega
          //   - Apelido do endereço (Casa, Trabalho...)
          // ════════════════════════════════════════════════════════
          ?>

          <!-- ETAPA 2: Endereço + Frete -->
          <section class="checkout-section" id="section-entrega"
                  style="<?= $etapa < 2 ? 'display:none' : '' ?>">
            <div class="section-head">
              <h2>
                <span class="section-num">2</span>
                Endereço de entrega
              </h2>
              <p class="section-sub">Para onde enviamos seu pedido?</p>
            </div>

            <?php if (!empty($enderecos)): ?>
            <!-- ── ENDEREÇOS SALVOS ─────────────────────────── -->
            <div class="saved-addresses" id="saved-addresses">
              <?php foreach ($enderecos as $end):
                // Apelido (Casa, Trabalho...) — pode vir do banco se já existir,
                // ou cair em fallback baseado em principal/posição
                $apelido = $end['apelido'] ?? ($end['principal'] ? 'Endereço principal' : 'Endereço alternativo');
              ?>
              <label class="address-card" data-end-id="<?= (int)$end['id'] ?>">
                <input type="radio" name="endereco_entrega" value="<?= (int)$end['id'] ?>"
                      class="address-radio" <?= $end['principal'] ? 'checked' : '' ?>>

                <div class="address-card-body">
                  <div class="address-card-header">
                    <span class="address-icon">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                          stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                      </svg>
                    </span>
                    <strong><?= View::e($end['nome_destinatario']) ?></strong>
                    <?php if ($end['principal']): ?>
                      <span class="address-badge">Principal</span>
                    <?php endif; ?>
                  </div>

                  <p class="address-line">
                    <?= View::e("{$end['logradouro']}, {$end['numero']}") ?>
                    <?php if ($end['complemento']): ?> — <?= View::e($end['complemento']) ?><?php endif; ?>
                  </p>
                  <p class="address-line">
                    <?= View::e("{$end['bairro']} — {$end['cidade']}/{$end['estado']}") ?>
                  </p>
                  <p class="address-line address-line--cep">
                    CEP <?= View::e($end['cep']) ?>
                  </p>
                </div>

                <div class="address-card-check">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                      stroke-width="2.5" stroke-linecap="round">
                    <polyline points="20 6 9 17 4 12"/>
                  </svg>
                </div>
              </label>
              <?php endforeach; ?>
            </div>

            <button type="button" class="btn-add-address" id="btn-toggle-new-address">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                  stroke-width="2.5" stroke-linecap="round">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
              </svg>
              Adicionar novo endereço
            </button>
            <?php endif; ?>

            <!-- ── FORMULÁRIO DE ENDEREÇO ─────────────────────── -->
            <form class="address-form" id="address-form"
                  style="<?= empty($enderecos) ? '' : 'display:none;' ?>" novalidate>
              <?= SecurityHelper::csrfField() ?>
              <input type="hidden" name="tipo" value="entrega">

              <!-- CEP em destaque (hero) -->
              <div class="cep-hero" id="cep-hero">
                <div class="cep-hero-icon">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                      stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                  </svg>
                </div>
                <div class="cep-hero-body">
                  <label for="end-cep" class="cep-hero-label">
                    Comece pelo seu CEP
                    <a href="https://buscacepinter.correios.com.br/app/endereco/index.php"
                      target="_blank" rel="noopener" class="cep-hero-find">Não sei meu CEP</a>
                  </label>
                  <div class="cep-hero-input-wrap">
                    <input type="text" id="end-cep" name="cep"
                          class="form-control cep-mask cep-hero-input"
                          placeholder="00000-000" maxlength="9"
                          inputmode="numeric" autocomplete="postal-code" required>

                    <!-- Loading -->
                    <span class="cep-loading" id="cep-loading" style="display:none;">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                          stroke-width="2.5" stroke-linecap="round" style="animation:ck-spin .8s linear infinite">
                        <path d="M21 12a9 9 0 11-6.219-8.56"/>
                      </svg>
                    </span>

                    <!-- Sucesso -->
                    <span class="cep-success" id="cep-success" style="display:none;">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                          stroke-width="3" stroke-linecap="round">
                        <polyline points="20 6 9 17 4 12"/>
                      </svg>
                    </span>
                  </div>

                  <!-- Mensagem de sucesso "Endereço encontrado" -->
                  <div class="cep-found" id="cep-found" style="display:none;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2.5" stroke-linecap="round">
                      <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    <span>Endereço encontrado · <strong id="cep-found-summary"></strong></span>
                  </div>

                  <span class="field-error" id="err-end-cep"></span>
                </div>
              </div>

              <!-- Campos do endereço (revelados após CEP encontrado, ou se vier endereço salvo) -->
              <div class="address-fields" id="address-fields">

                <div class="form-row">
                  <div class="form-group form-col">
                    <label for="end-nome">Nome do destinatário <span class="required">*</span></label>
                    <input type="text" id="end-nome" name="nome_destinatario" class="form-control"
                          placeholder="Quem vai receber" required autocomplete="name">
                    <span class="field-error" id="err-end-nome"></span>
                  </div>
                </div>

                <div class="form-row">
                  <div class="form-group" style="flex:2">
                    <label for="end-logradouro">Endereço <span class="required">*</span></label>
                    <input type="text" id="end-logradouro" name="logradouro" class="form-control"
                          placeholder="Rua, Av., Alameda..." required autocomplete="address-line1">
                    <span class="field-error" id="err-end-logradouro"></span>
                  </div>
                  <div class="form-group" style="flex:0 0 110px">
                    <label for="end-numero">Número <span class="required">*</span></label>
                    <input type="text" id="end-numero" name="numero" class="form-control"
                          placeholder="Nº" required>
                  </div>
                </div>

                <div class="form-row">
                  <div class="form-group form-col">
                    <label for="end-comp">
                      Complemento
                      <span class="label-opt">opcional</span>
                    </label>
                    <input type="text" id="end-comp" name="complemento" class="form-control"
                          placeholder="Apto, bloco, casa..." autocomplete="address-line2">
                  </div>
                  <div class="form-group form-col">
                    <label for="end-bairro">Bairro <span class="required">*</span></label>
                    <input type="text" id="end-bairro" name="bairro" class="form-control" required>
                  </div>
                </div>

                <div class="form-row">
                  <div class="form-group" style="flex:2">
                    <label for="end-cidade">Cidade <span class="required">*</span></label>
                    <input type="text" id="end-cidade" name="cidade" class="form-control" required>
                  </div>
                  <div class="form-group" style="flex:0 0 90px">
                    <label for="end-estado">UF <span class="required">*</span></label>
                    <select id="end-estado" name="estado" class="form-control" required>
                      <option value="">UF</option>
                      <?php foreach (['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'] as $uf): ?>
                        <option value="<?= $uf ?>"><?= $uf ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="form-row">
                  <div class="form-group form-col">
                    <label for="end-tel">
                      Telefone de contato
                      <span class="label-opt">opcional · ajuda na entrega</span>
                    </label>
                    <input type="tel" id="end-tel" name="telefone" class="form-control phone-mask"
                          placeholder="(00) 00000-0000" maxlength="15" autocomplete="tel">
                  </div>
                  <div class="form-group form-col">
                    <label for="end-apelido">
                      Apelido
                      <span class="label-opt">opcional</span>
                    </label>
                    <input type="text" id="end-apelido" name="apelido" class="form-control"
                          placeholder="Ex: Casa, Trabalho" maxlength="40">
                  </div>
                </div>

                <!-- Observações para entrega -->
                <div class="form-group">
                  <label for="end-obs">
                    Observações para o entregador
                    <span class="label-opt">opcional</span>
                  </label>
                  <textarea id="end-obs" name="observacao_entrega" class="form-control"
                            rows="2" maxlength="200"
                            placeholder="Ex: portão azul, deixar com porteiro, tocar interfone..."></textarea>
                </div>

                <!-- Trust badge -->
                <div class="address-trust">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                      stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                  </svg>
                  Entrega segura para este endereço
                </div>

                <div id="address-error" class="form-alert form-alert--error" style="display:none;"></div>

                <button type="submit" class="btn btn-primary btn-full" id="btn-save-address">
                  <span class="btn-text">Salvar e calcular frete</span>
                  <svg class="btn-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="5" y1="12" x2="19" y2="12"/>
                    <polyline points="12 5 19 12 12 19"/>
                  </svg>
                </button>
              </div>
            </form>

            <!-- O bloco do frete continua igual ao original abaixo deste ponto -->
            <!-- ↓↓↓ MANTER o <div class="frete-section" id="frete-section"> existente ↓↓↓ -->

              <?php
            // ════════════════════════════════════════════════════════
            // SUBSTITUIR o conteúdo do <div class="frete-section" id="frete-section">
            // dentro de views/checkout/index.php.
            //
            // Mantém o ID frete-section (já usado pelo JS).
            // O conteúdo dos cards é renderizado dinamicamente pelo
            // checkout-frete.js quando o endereço é salvo.
            // ════════════════════════════════════════════════════════
            ?>

            <!-- ── BLOCO DE FRETE ──────────────────────────────── -->
            <div class="frete-section" id="frete-section"
                style="<?= empty($enderecoEntregaId) ? 'display:none;' : '' ?>">

              <div class="frete-header">
                <h3 class="frete-title">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                      stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="1" y="3" width="15" height="13" rx="1"/>
                    <path d="M16 8h4l3 3v5h-7V8z"/>
                    <circle cx="5.5" cy="18.5" r="2.5"/>
                    <circle cx="18.5" cy="18.5" r="2.5"/>
                  </svg>
                  Como você quer receber
                </h3>
                <span class="frete-cep-tag" id="frete-cep-tag" style="display:none;">
                  CEP <strong id="frete-cep-display">--</strong>
                  <button type="button" class="frete-cep-edit" id="frete-cep-edit"
                          title="Trocar CEP">trocar</button>
                </span>
              </div>

              <!-- Loading skeleton -->
              <div class="frete-skeleton" id="frete-skeleton" style="display:none;">
                <div class="frete-skel-card"></div>
                <div class="frete-skel-card"></div>
                <div class="frete-skel-card"></div>
              </div>

              <!-- Empty state -->
              <div class="frete-empty" id="frete-empty" style="display:none;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="1.5" stroke-linecap="round">
                  <circle cx="12" cy="12" r="10"/>
                  <line x1="12" y1="8" x2="12" y2="12"/>
                  <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <strong>Nenhuma opção de frete disponível</strong>
                <span>Tente novamente ou contate o suporte.</span>
              </div>

              <!-- Cards (preenchidos via JS) -->
              <div class="frete-cards" id="frete-cards"></div>

              <!-- Botão continuar -->
              <button type="button" class="btn btn-primary btn-full" id="btn-confirm-frete"
                      disabled style="margin-top:14px;">
                <span class="btn-text">Continuar para pagamento</span>
                <svg class="btn-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <line x1="5" y1="12" x2="19" y2="12"/>
                  <polyline points="12 5 19 12 12 19"/>
                </svg>
              </button>

            </div>

        </section>

        <?php
        // ════════════════════════════════════════════════════════
        // SUBSTITUIR a seção <section id="section-pagamento">
        // em views/checkout/index.php por este bloco.
        //
        // Mantém IDs/classes legadas:
        //   payment-methods, payment-method-card, card-fields,
        //   credit-card-preview, card-prev-number, card-prev-holder,
        //   card-prev-expiry, btn-place-order, form-place-order
        //
        // ADICIONA:
        //   - Detecção visual de bandeira em tempo real
        //   - Preview animado do cartão com flip no CVV
        //   - Painéis informativos Pix (verde) e Boleto (laranja)
        //   - Mensagens de segurança próximas ao botão
        //   - Parcelamento com simulação visual
        // ════════════════════════════════════════════════════════
        ?>

        <!-- ETAPA 3: Pagamento -->
        <section class="checkout-section" id="section-pagamento"
                style="<?= $etapa < 3 ? 'display:none' : '' ?>">

          <div class="section-head">
            <h2>
              <span class="section-num">3</span>
              Forma de pagamento
            </h2>
            <p class="section-sub">Escolha como prefere pagar. Seus dados são protegidos por criptografia.</p>
          </div>

          <form id="form-place-order" novalidate>
            <?= SecurityHelper::csrfField() ?>
            <input type="hidden" name="endereco_entrega_id" id="endereco_entrega_id"
                  value="<?= (int)($enderecoEntregaId ?? 0) ?>">
            <input type="hidden" name="endereco_cobranca_id" id="endereco_cobranca_id"
                  value="<?= (int)($enderecoEntregaId ?? 0) ?>">

            <!-- ── MÉTODOS DE PAGAMENTO ─────────────────────── -->
            <div class="payment-methods">

              <!-- Cartão de Crédito -->
              <label class="payment-method-card" data-method="cartao">
                <input type="radio" name="forma_pagamento" value="cartao" checked>
                <div class="payment-icon payment-icon--card">💳</div>
                <div class="payment-method-body">
                  <div class="payment-method-header">
                    <strong>Cartão de crédito</strong>
                    <span class="payment-badge-blue">Em até <?= $maxParcelas ?? 12 ?>x</span>
                  </div>
                  <div class="payment-desc">Aprovação imediata · Visa, Master, Amex, Elo, Hipercard</div>
                </div>
                <div class="payment-method-check">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                      stroke-width="2.5" stroke-linecap="round">
                    <polyline points="20 6 9 17 4 12"/>
                  </svg>
                </div>
              </label>

              <!-- Pix -->
              <label class="payment-method-card" data-method="pix">
                <input type="radio" name="forma_pagamento" value="pix">
                <div class="payment-icon payment-icon--pix">PIX</div>
                <div class="payment-method-body">
                  <div class="payment-method-header">
                    <strong>Pix</strong>
                    <span class="payment-badge-green">Aprovação na hora</span>
                    <?php if (!empty($descontoPix) && $descontoPix > 0): ?>
                    <span class="payment-badge-green">-<?= (int)$descontoPix ?>%</span>
                    <?php endif; ?>
                  </div>
                  <div class="payment-desc">QR Code gerado após finalizar. Pague em segundos.</div>
                </div>
                <div class="payment-method-check">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                      stroke-width="2.5" stroke-linecap="round">
                    <polyline points="20 6 9 17 4 12"/>
                  </svg>
                </div>
              </label>

              <!-- Boleto -->
              <label class="payment-method-card" data-method="boleto">
                <input type="radio" name="forma_pagamento" value="boleto">
                <div class="payment-icon payment-icon--boleto">|||</div>
                <div class="payment-method-body">
                  <div class="payment-method-header">
                    <strong>Boleto bancário</strong>
                    <span class="payment-badge-gray">À vista</span>
                  </div>
                  <div class="payment-desc">Compensação em até 2 dias úteis. Sem custos adicionais.</div>
                </div>
                <div class="payment-method-check">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                      stroke-width="2.5" stroke-linecap="round">
                    <polyline points="20 6 9 17 4 12"/>
                  </svg>
                </div>
              </label>
            </div>

            <!-- ════════════════════════════════════════════════
                FORM CARTÃO
                ════════════════════════════════════════════════ -->
            <div class="card-fields" id="payment-form-cartao">

              <!-- Cartões salvos (se houver) -->
              <?php if (!empty($cartoesSalvos)): ?>
              <div class="saved-cards-title">Seus cartões salvos</div>
              <div class="saved-cards-list">
                <?php foreach ($cartoesSalvos as $card): ?>
                <label class="saved-card-item">
                  <input type="radio" name="cartao_salvo_id" value="<?= (int)$card['id'] ?>">
                  <div class="saved-card-body">
                    <span class="card-brand"><?= View::e($card['bandeira']) ?></span>
                    <span class="card-number">•••• <?= View::e($card['ultimos_4']) ?></span>
                    <span class="card-holder"><?= View::e($card['nome_titular']) ?></span>
                    <span class="card-expiry"><?= View::e($card['validade']) ?></span>
                  </div>
                  <div class="saved-card-check">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2.5" stroke-linecap="round">
                      <polyline points="20 6 9 17 4 12"/>
                    </svg>
                  </div>
                </label>
                <?php endforeach; ?>
                <label class="saved-card-item">
                  <input type="radio" name="cartao_salvo_id" value="novo" checked>
                  <div class="saved-card-body">
                    <strong style="font-weight:800;color:var(--c-dark);">+ Adicionar novo cartão</strong>
                  </div>
                </label>
              </div>
              <?php endif; ?>

              <!-- Preview do cartão (flip 3D) -->
              <div class="card-preview-3d-wrap" id="card-preview-wrap">
                <div class="card-preview-3d" id="card-preview-3d">
                  <!-- Frente -->
                  <div class="credit-card-preview card-preview-front">
                    <div class="card-prev-brand" id="card-prev-brand">
                      <span class="card-prev-brand-placeholder">CARTÃO</span>
                    </div>
                    <div class="card-prev-number" id="card-prev-number">•••• •••• •••• ••••</div>
                    <div class="card-prev-bottom">
                      <div>
                        <div class="card-prev-label">Titular</div>
                        <div class="card-prev-holder" id="card-prev-holder">NOME COMPLETO</div>
                      </div>
                      <div>
                        <div class="card-prev-label">Validade</div>
                        <div class="card-prev-expiry" id="card-prev-expiry">MM/AA</div>
                      </div>
                    </div>
                  </div>
                  <!-- Verso -->
                  <div class="credit-card-preview card-preview-back">
                    <div class="card-prev-stripe"></div>
                    <div class="card-prev-cvv-box">
                      <span class="card-prev-cvv-label">CVV</span>
                      <span class="card-prev-cvv" id="card-prev-cvv">•••</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Campos do cartão -->
              <div class="form-group">
                <label for="numero_cartao">Número do cartão</label>
                <div class="input-with-brand">
                  <input type="text" id="numero_cartao" name="numero_cartao"
                        class="form-control card-mask"
                        placeholder="0000 0000 0000 0000" maxlength="19"
                        inputmode="numeric" autocomplete="cc-number">
                  <span class="card-brand-detected" id="card-brand-detected"></span>
                </div>
                <span class="field-error" id="err-numero-cartao"></span>
              </div>

              <div class="form-group">
                <label for="nome_cartao">Nome impresso no cartão</label>
                <input type="text" id="nome_cartao" name="nome_cartao" class="form-control"
                      placeholder="Como está no cartão" autocomplete="cc-name"
                      style="text-transform:uppercase;">
              </div>

              <div class="form-row">
                <div class="form-group form-col">
                  <label for="validade_cartao">Validade</label>
                  <input type="text" id="validade_cartao" name="validade_cartao"
                        class="form-control validade-mask"
                        placeholder="MM/AA" maxlength="5"
                        inputmode="numeric" autocomplete="cc-exp">
                </div>
                <div class="form-group form-col">
                  <label for="cvv_cartao">
                    CVV
                    <span class="cvv-tip" title="3 ou 4 dígitos no verso do cartão">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                          stroke-width="2" stroke-linecap="round">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                      </svg>
                    </span>
                  </label>
                  <input type="text" id="cvv_cartao" name="cvv_cartao"
                        class="form-control cvv-mask"
                        placeholder="000" maxlength="4"
                        inputmode="numeric" autocomplete="cc-csc">
                </div>
              </div>

              <!-- Parcelas -->
              <div class="parcelamento-section">
                <label for="parcelas">Parcelas</label>
                <select id="parcelas" name="parcelas" class="form-control parcelas-select">
                  <!-- Preenchido via JS conforme o total -->
                </select>
              </div>

              <!-- Salvar cartão -->
              <label class="save-card-toggle">
                <input type="checkbox" name="salvar_cartao" value="1">
                <span class="save-card-toggle-box">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                      stroke-width="3" stroke-linecap="round">
                    <polyline points="20 6 9 17 4 12"/>
                  </svg>
                </span>
                <span class="save-card-toggle-text">
                  <strong>Salvar este cartão</strong>
                  <small>Próximas compras com 1 clique. Salvo de forma criptografada.</small>
                </span>
              </label>
            </div>

            <!-- ════════════════════════════════════════════════
                PAINEL PIX
                ════════════════════════════════════════════════ -->
            <div class="payment-info-panel payment-info-panel--pix"
                id="payment-form-pix" style="display:none;">
              <div class="payment-info-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M9 12l2 2 4-4"/>
                  <circle cx="12" cy="12" r="10"/>
                </svg>
              </div>
              <h3 class="payment-info-title">Pagamento via Pix · Instantâneo</h3>
              <p class="payment-info-text">
                Após finalizar, geramos o QR Code e o código copia-e-cola para você pagar pelo banco.
                Seu pedido é confirmado em segundos.
              </p>
              <ul class="payment-info-benefits">
                <li>
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                      stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                  Confirmação automática em segundos
                </li>
                <li>
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                      stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                  Sem taxas adicionais
                </li>
                <li>
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                      stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                  QR Code válido por 30 minutos
                </li>
              </ul>
            </div>

            <!-- ════════════════════════════════════════════════
                PAINEL BOLETO
                ════════════════════════════════════════════════ -->
            <div class="payment-info-panel payment-info-panel--boleto"
                id="payment-form-boleto" style="display:none;">
              <div class="payment-info-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round">
                  <rect x="3" y="4" width="18" height="16" rx="1"/>
                  <line x1="7"  y1="8" x2="7"  y2="20"/>
                  <line x1="11" y1="8" x2="11" y2="20"/>
                  <line x1="15" y1="8" x2="15" y2="20"/>
                  <line x1="19" y1="8" x2="19" y2="20"/>
                </svg>
              </div>
              <h3 class="payment-info-title">Pagamento via Boleto</h3>
              <p class="payment-info-text">
                O boleto será gerado após finalizar.
                <strong>Compensação em até 2 dias úteis</strong> após o pagamento.
                Seu pedido só será enviado após a confirmação bancária.
              </p>
              <div class="payment-info-warn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.2" stroke-linecap="round">
                  <circle cx="12" cy="12" r="10"/>
                  <line x1="12" y1="8"  x2="12" y2="12"/>
                  <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                Para receber mais rápido, prefira Pix ou Cartão.
              </div>
            </div>

            <!-- ════════════════════════════════════════════════
                OBSERVAÇÕES + BOTÃO + SEGURANÇA
                ════════════════════════════════════════════════ -->
            <div class="obs-section">
              <label for="observacao">
                Observações do pedido <span class="label-opt">opcional</span>
              </label>
              <textarea id="observacao" name="observacao" class="form-control"
                        rows="2" maxlength="500" placeholder="Algo importante para o nosso time?"></textarea>
            </div>

            <div id="payment-error" class="form-alert form-alert--error" style="display:none;"></div>

            <!-- Selos de segurança -->
            <div class="payment-security-row">
              <div class="payment-security-item">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round">
                  <rect x="3" y="11" width="18" height="11" rx="2"/>
                  <path d="M7 11V7a5 5 0 0110 0v4"/>
                </svg>
                Pagamento criptografado
              </div>
              <div class="payment-security-item">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                Ambiente seguro
              </div>
              <div class="payment-security-item">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
                Dados protegidos
              </div>
            </div>

            <button type="submit" class="btn btn-primary btn-full btn-place-order" id="btn-place-order">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                  stroke-width="2.5" stroke-linecap="round">
                <rect x="3" y="11" width="18" height="11" rx="2"/>
                <path d="M7 11V7a5 5 0 0110 0v4"/>
              </svg>
              Finalizar compra com segurança
            </button>

            <p class="payment-terms">
              Ao finalizar, você concorda com nossos
              <a href="<?= BASE_URL ?>/termos-de-uso" target="_blank">Termos de Uso</a>.
              Receba atualizações do pedido por e-mail e WhatsApp.
            </p>
          </form>
        </section>
      </div>

      <!-- ── Resumo lateral ──────────────────────────────── -->
      <aside class="checkout-summary">
        <div class="checkout-summary-inner" id="checkout-summary">
          <h3 class="summary-title">Resumo do pedido</h3>

          <!-- Itens -->
          <ul class="checkout-items-list">
            <?php foreach ($totals['items'] as $item):
              $imgUrl = !empty($item['imagem'])
                        ? View::upload('products/' . $item['imagem'])
                        : View::asset('images/placeholder.jpg');
            ?>
            <li class="checkout-item">
              <div class="checkout-item-img">
                <img src="<?= $imgUrl ?>" alt="<?= View::e($item['nome_produto']) ?>"
                     loading="lazy" width="60" height="60">
                <span class="checkout-item-qty"><?= (int)$item['quantidade'] ?></span>
              </div>
              <div class="checkout-item-info">
                <span class="checkout-item-name"><?= View::e($item['nome_produto']) ?></span>
                <?php if (!empty($item['opcoes'])): ?>
                <span class="checkout-item-opts">
                  <?= View::e(implode(' / ', array_map(fn($k,$v) => "$v", array_keys($item['opcoes']), $item['opcoes']))) ?>
                </span>
                <?php endif; ?>
              </div>
              <span class="checkout-item-price"><?= PriceHelper::format($item['subtotal']) ?></span>
            </li>
            <?php endforeach; ?>
          </ul>

          <!-- Cupom -->
          <?php if (empty($totals['cupom'])): ?>
          <div class="summary-coupon">
            <input type="text" class="form-control" id="summary-coupon-input"
                   placeholder="Código do cupom" style="text-transform:uppercase">
            <button type="button" class="btn btn-outline btn-sm" id="summary-btn-coupon">Aplicar</button>
            <span class="coupon-msg" id="summary-coupon-msg"></span>
          </div>
          <?php else: ?>
          <div class="coupon-applied" style="margin:14px 0;">
            <div class="coupon-applied-info">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              Cupom <strong><?= View::e($totals['cupom']['codigo']) ?></strong>
            </div>
          </div>
          <?php endif; ?>

          <div class="summary-divider"></div>

          <!-- Totais -->
          <div class="summary-totals">
            <div class="summary-row">
              <span>Subtotal</span>
              <span id="ck-subtotal"><?= $totals['subtotal_fmt'] ?></span>
            </div>
            <div class="summary-row" id="ck-row-frete">
              <span>Frete</span>
              <span id="ck-frete"><?= !empty($totals['frete_servico']) ? $totals['frete_fmt'] : '—' ?></span>
            </div>
            <?php if ($totals['desconto'] > 0): ?>
            <div class="summary-row summary-row--discount" id="ck-row-desconto">
              <span>Desconto</span>
              <span id="ck-desconto"><?= $totals['desconto_fmt'] ?></span>
            </div>
            <?php endif; ?>
            <div class="summary-divider"></div>
            <div class="summary-row summary-row--total">
              <span>Total</span>
              <span id="ck-total"><?= $totals['total_fmt'] ?></span>
            </div>
          </div>

          <div class="summary-security">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Pagamento 100% seguro e criptografado
          </div>
        </div>
      </aside>

    </div>
  </div>
</main>

</div>

<script src="<?= PerformanceHelper::assetVersion('js/checkout-identify.js') ?>" defer></script>
<script>

</script>