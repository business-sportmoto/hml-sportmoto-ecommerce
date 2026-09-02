<nav class="admin-nav">
    
    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Visão geral</span>
      <a href="<?= BASE_URL ?>/admin" class="admin-nav-item<?= adminIsActive('/admin') && !adminIsActive('/admin/') ? ' active' : (str_replace(BASE_URL, '', $currentUri) === '/admin' ? ' active' : '') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
            <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
          </svg>
        </span>
        Dashboard
      </a>
    </div>

    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Catálogo</span>
      <a href="<?= BASE_URL ?>/admin/produtos" class="admin-nav-item<?= adminIsActive('/admin/produto') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
          </svg>
        </span>
        Produtos
      </a>      
      <a href="<?= BASE_URL ?>/admin/categorias" class="admin-nav-item<?= adminIsActive('/admin/categori') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
          </svg>
        </span>
        Categorias
      </a>
      <a href="<?= BASE_URL ?>/admin/marcas" class="admin-nav-item<?= adminIsActive('/admin/marca') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/>
            <line x1="7" y1="7" x2="7.01" y2="7"/>
          </svg>
        </span>
        Marcas
      </a>
      <a href="<?= BASE_URL ?>/admin/atributos" class="admin-nav-item<?= adminIsActive('/admin/atributos') ?>">
        <span class="admin-nav-icon">          
          <?= IconLibrary::render('shelves', 'icon icon--md') ?>
        </span>
        Atributos
      </a>
      <a href="<?= BASE_URL ?>/admin/caracteristicas" class="admin-nav-item<?= adminIsActive('/admin/caracteristicas') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('format-list-bulleted', 'icon icon--md') ?>
        </span>
        Caracteristicas
      </a>
      <a href="<?= BASE_URL ?>/admin/motos" class="admin-nav-item<?= adminIsActive('/admin/motos') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('motorcycle', 'icon icon--md') ?>
        </span>
        Motos
      </a>
    </div>

    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Vendas</span>
      <a href="<?= BASE_URL ?>/admin/pedidos" class="admin-nav-item<?= adminIsActive('/admin/pedido') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
            <line x1="3" y1="6" x2="21" y2="6"/>
            <path d="M16 10a4 4 0 01-8 0"/>
          </svg>
        </span>
        Pedidos
      </a>
      <a href="<?= BASE_URL ?>/admin/clientes" class="admin-nav-item<?= adminIsActive('/admin/cliente') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>
          </svg>
        </span>
        Clientes
      </a>
      <a href="<?= BASE_URL ?>/admin/cupons" class="admin-nav-item<?= adminIsActive('/admin/cupom') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/>
          </svg>
        </span>
        Cupons
      </a>
      <a href="<?= BASE_URL ?>/admin/avaliacoes" class="admin-nav-item<?= adminIsActive('/admin/avaliac') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
        </span>
        Avaliações
      </a>
      <a href="<?= BASE_URL ?>/admin/devolucoes" class="admin-nav-item<?= adminIsActive('/admin/devolucoes') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('low-priority', 'icon icon--md') ?>
        </span>
        Devoluções
      </a>
      <a href="<?= BASE_URL ?>/admin/promocoes" class="admin-nav-item<?= adminIsActive('/admin/promocoes') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('discount', 'icon icon--md') ?>
        </span>
        Promoções
      </a>
    </div>

    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Marketing</span>
      <a href="<?= BASE_URL ?>/admin/email-marketing" class="admin-nav-item<?= adminIsActive('/admin/email-marketing/') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('mark_email_read', 'icon icon--md') ?>
        </span>
        E-mail Marketing
      </a>
      <a href="<?= BASE_URL ?>/admin/email-marketing/campanhas" class="admin-nav-item<?= adminIsActive('/admin/email-marketing/campanhas') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('campaign', 'icon icon--md') ?>
        </span>
        Campanhas
      </a>
      <a href="<?= BASE_URL ?>/admin/email-marketing/automacoes" class="admin-nav-item<?= adminIsActive('/admin/email-marketing/automacoes') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('automation', 'icon icon--md') ?>
        </span>
        Automações
      </a>
      <a href="<?= BASE_URL ?>/admin/carrinhos-abandonados" class="admin-nav-item<?= adminIsActive('/admin/carrinhos-abandonados/') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('cart-alert', 'icon icon--md') ?>
        </span>
        Carrinho abandonado
      </a>
      
      <a href="<?= BASE_URL ?>/admin/vida-util" class="admin-nav-item<?= adminIsActive('/admin/vida-util') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('automation-02', 'icon icon--md') ?>
        </span>
        Vida útil
      </a>
    </div>

    <?php
    // ── Chat (WhatsApp) ─────────────────────────────────────────────────────
    // Atender e consultar contatos e operacional (vendedor entra); publicar
    // fluxo, disparar campanha e mexer na config e gestao (super/gerente).
    $chatOperar = AuthHelper::hasLevel('super', 'gerente', 'vendedor');
    $chatGerir  = AuthHelper::hasLevel('super', 'gerente');
    if ($chatOperar):
      // Nao-lidas no sino do menu: o atendente precisa ver sem abrir a tela.
      $chatNaoLidas = 0;
      try {
          $chatNaoLidas = (int) Database::getInstance()->getConnection()
              ->query("SELECT COALESCE(SUM(nao_lidas), 0) FROM chat_conversas")
              ->fetchColumn();
      } catch (Throwable $e) { /* modulo ainda nao migrado */ }
    ?>
    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Chat</span>

      <?php if ($chatGerir): ?>
      <a href="<?= BASE_URL ?>/admin/chat" class="admin-nav-item<?= adminIsActive('/admin/chat') && !str_contains($_SERVER['REQUEST_URI'] ?? '', '/admin/chat/') ? ' active' : '' ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('chat-info', 'icon icon--md') ?>
        </span>
        Visão geral
      </a>
      <?php endif; ?>

      <a href="<?= BASE_URL ?>/admin/chat/inbox" class="admin-nav-item<?= adminIsActive('/admin/chat/inbox') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('business-messages', 'icon icon--md') ?>
        </span>
        Atendimento
        <?php if ($chatNaoLidas > 0): ?>
        <span class="admin-nav-badge admin-nav-badge--alert"><?= $chatNaoLidas > 99 ? '99+' : $chatNaoLidas ?></span>
        <?php endif; ?>
      </a>

      <a href="<?= BASE_URL ?>/admin/chat/contatos" class="admin-nav-item<?= adminIsActive('/admin/chat/contatos') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('user_admin', 'icon icon--md') ?>
        </span>
        Contatos
      </a>

      <?php // Automações do IG são operacionais: vendedor cria e edita as suas ?>
      <a href="<?= BASE_URL ?>/admin/chat/automacoes" class="admin-nav-item<?= adminIsActive('/admin/chat/automacoes') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('automation-02', 'icon icon--md') ?>
        </span>
        Automações IG
      </a>

      <?php if ($chatGerir): ?>
      <a href="<?= BASE_URL ?>/admin/chat/instagram" class="admin-nav-item<?= adminIsActive('/admin/chat/instagram') && !str_contains($_SERVER['REQUEST_URI'] ?? '', '/automacoes') ? ' active' : '' ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('chat-dashed', 'icon icon--md') ?>
        </span>
        Instagram
      </a>
      <a href="<?= BASE_URL ?>/admin/chat/fluxos" class="admin-nav-item<?= adminIsActive('/admin/chat/fluxos') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('automation', 'icon icon--md') ?>
        </span>
        Fluxos
      </a>
      <a href="<?= BASE_URL ?>/admin/chat/gatilhos" class="admin-nav-item<?= adminIsActive('/admin/chat/gatilhos') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('format-list-bulleted', 'icon icon--md') ?>
        </span>
        Gatilhos
      </a>
      <a href="<?= BASE_URL ?>/admin/chat/campanhas" class="admin-nav-item<?= adminIsActive('/admin/chat/campanhas') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('campaign', 'icon icon--md') ?>
        </span>
        Disparos
      </a>
      <a href="<?= BASE_URL ?>/admin/chat/config" class="admin-nav-item<?= adminIsActive('/admin/chat/config') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('settings', 'icon icon--md') ?>
        </span>
        Configuração
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Conteúdo</span>
      <a href="<?= BASE_URL ?>/admin/perguntas" class="admin-nav-item<?= adminIsActive('/admin/pergunta') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('contact-support', 'icon icon--md') ?>
        </span>
        Perguntas&Respostas
      </a>
      <a href="<?= BASE_URL ?>/admin/banners" class="admin-nav-item<?= adminIsActive('/admin/banner') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="3" y="3" width="18" height="18" rx="2"/>
            <circle cx="8.5" cy="8.5" r="1.5"/>
            <polyline points="21 15 16 10 5 21"/>
          </svg>
        </span>
        Banners
      </a>
      <a href="<?= BASE_URL ?>/admin/beneficios" class="admin-nav-item<?= adminIsActive('/admin/beneficio') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="16"/>
            <line x1="8" y1="12" x2="16" y2="12"/>
          </svg>
        </span>
        Benefícios
      </a>

      <a href="<?= BASE_URL ?>/admin/moderacao/fotos" class="admin-nav-item<?= adminIsActive('/admin/moderacao/fotos') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('cinematic-blur', 'icon icon--md') ?>
        </span>
        Moderação de fotos
        <?php
        $count = (int)Database::getInstance()->getConnection()
            ->query("SELECT COUNT(*) FROM cliente_veiculo_fotos WHERE visibilidade='publico' AND status_moderacao='pendente'")
            ->fetchColumn();
        ?>
        <?php if ($count > 0): ?>
        <span class="admin-nav-badge admin-nav-badge--alert"><?= $count ?></span>
        <?php endif; ?>
      </a>

      <a href="<?= BASE_URL ?>/admin/clips" class="admin-nav-item<?= adminIsActive('/admin/clips') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('video-template', 'icon icon--md') ?>
        </span>
        Clips
      </a>

      <a href="<?= BASE_URL ?>/admin/ia/gerar" class="admin-nav-item<?= adminIsActive('/admin/ia') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('wand-stars', 'icon icon--md') ?>
        </span>
        Central de IA
      </a>

      <a href="<?= BASE_URL ?>/admin/fluxos" class="admin-nav-item<?= adminIsActive('/admin/fluxos') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('automation-02', 'icon icon--md') ?>
        </span>
        Central de Automações
      </a>
    </div>
    
    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Logistica</span>
      <a href="<?= BASE_URL ?>/admin/logistica" class="admin-nav-item<?= adminIsActive('/admin/logistica/torre') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('tower-control'); ?> 
        </span>
        Torre de controle
      </a>
      <a href="<?= BASE_URL ?>/admin/logistica/transportadoras" class="admin-nav-item<?= adminIsActive('/admin/logistica/transportadoras') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('delivery-truck-speed'); ?> 
        </span>
        Transportadoras
      </a>
      <a href="<?= BASE_URL ?>/admin/logistica/regras" class="admin-nav-item<?= adminIsActive('/admin/logistica/regras') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('rule'); ?> 
        </span>
        Regras
      </a>
      <a href="<?= BASE_URL ?>/admin/logistica/simulador" class="admin-nav-item<?= adminIsActive('/admin/logistica/simulador') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('drone'); ?> 
        </span>
        Simulador
      </a>
      <a href="<?= BASE_URL ?>/admin/logistica/reversas" class="admin-nav-item<?= adminIsActive('/admin/logistica/reversas') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('returned'); ?> 
        </span>
        Reversas
      </a>
      <a href="<?= BASE_URL ?>/admin/logistica/divergencias" class="admin-nav-item<?= adminIsActive('/admin/logistica/divergencias') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('divergencia'); ?> 
        </span>
        Divergencias
      </a>
      <a href="<?= BASE_URL ?>/admin/logistica/frete-fallback" class="admin-nav-item<?= adminIsActive('/admin/logistica/frete-fallback') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('divergencia'); ?> 
        </span>
        Fallback
      </a>
    </div>

    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Sistema</span>
      <a href="<?= BASE_URL ?>/admin/configuracoes" class="admin-nav-item<?= adminIsActive('/admin/configuracoes') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('settings'); ?> 
        </span>
        Configurações
      </a>
      <a href="<?= BASE_URL ?>/admin/paginas" class="admin-nav-item<?= adminIsActive('/admin/paginas') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('docs'); ?>
        </span>
        Páginas
      </a>
      <a href="<?= BASE_URL ?>/admin/configuracoes/rodape" class="admin-nav-item<?= adminIsActive('/admin/configuracoes/rodape') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('docs'); ?>
        </span>
        Rodapé
      </a>
      <a href="<?= BASE_URL ?>/admin/usuarios" class="admin-nav-item<?= adminIsActive('/admin/usuarios') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('user_admin'); ?> 
        </span>
        Usuários
      </a>
      <a href="<?= BASE_URL ?>/admin/importar" class="admin-nav-item<?= adminIsActive('/admin/importar') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('cloud-download'); ?>
        </span>
        Importações
      </a>
      <a href="<?= BASE_URL ?>/admin/bling/produtos" class="admin-nav-item<?= adminIsActive('/admin/bling/produtos') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('package'); ?>
        </span>
        Catálogo Bling
      </a>
      <a href="<?= BASE_URL ?>/admin/logs" class="admin-nav-item<?= adminIsActive('/admin/logs') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('history-toggle-off'); ?> 
        </span>
        Logs Controller
      </a>
      <a href="<?= BASE_URL ?>/admin/logout" class="admin-nav-item">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
        </span>
        Sair
      </a>
    </div>

  </nav>