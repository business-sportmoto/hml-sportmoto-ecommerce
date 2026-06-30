<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <h1>Moderação de fotos</h1>
      <p>
        <?= $totalPendentes ?> foto<?= $totalPendentes !== 1 ? 's' : '' ?>
        aguardando aprovação para o feed público
      </p>
    </div>
    <div class="mod-tabs">
      <a href="<?= BASE_URL ?>/admin/moderacao/fotos"
         class="mod-tab is-active">
        Pendentes
        <?php if ($totalPendentes > 0): ?>
        <span class="mod-tab-badge"><?= $totalPendentes ?></span>
        <?php endif; ?>
      </a>
      <a href="<?= BASE_URL ?>/admin/moderacao/fotos?filtro=rejeitadas"
         class="mod-tab">Rejeitadas</a>
      <a href="<?= BASE_URL ?>/admin/moderacao/fotos?filtro=aprovadas"
         class="mod-tab">Aprovadas</a>
    </div>
  </div>

  <?php if (empty($pendentes)): ?>
  <div class="admin-empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1" stroke-linecap="round">
      <polyline points="20 6 9 17 4 12"/>
    </svg>
    <h3>Tudo em dia!</h3>
    <p>Não há fotos aguardando moderação no momento.</p>
  </div>

  <?php else: ?>
  <div class="mod-grid">
    <?php foreach ($pendentes as $foto): ?>
    <article class="mod-card" data-id="<?= $foto['id'] ?>">

      <!-- Foto -->
      <div class="mod-card-img">
        <img src="<?= View::upload('garagem/' . $foto['arquivo_medium']) ?>"
             alt="" loading="lazy"
             data-full="<?= View::upload('garagem/' . $foto['arquivo_full']) ?>">
        <button type="button" class="mod-card-zoom" title="Ver em tamanho real">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="15 3 21 3 21 9"/>
            <polyline points="9 21 3 21 3 15"/>
            <line x1="21" y1="3" x2="14" y2="10"/>
            <line x1="3" y1="21" x2="10" y2="14"/>
          </svg>
        </button>
      </div>

      <!-- Info -->
      <div class="mod-card-body">
        <div class="mod-card-cliente">
          <div class="mod-cliente-avatar">
            <?= mb_strtoupper(mb_substr($foto['cliente_nome'], 0, 1)) ?>
          </div>
          <div>
            <strong><?= View::e($foto['cliente_nome']) ?></strong>
            <span><?= View::e($foto['cliente_email']) ?></span>
          </div>
        </div>

        <div class="mod-card-moto">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="5.5" cy="17.5" r="3.5"/>
            <circle cx="18.5" cy="17.5" r="3.5"/>
            <path d="M15 6h-2l-3 8H5.5"/>
          </svg>
          <span>
            <?php if ($foto['moto_apelido']): ?>
              <strong><?= View::e($foto['moto_apelido']) ?></strong> ·
            <?php endif; ?>
            <?= View::e($foto['montadora_nome']) ?>
            <?php if ($foto['modelo_nome']): ?>
              <?= View::e($foto['modelo_nome']) ?>
            <?php endif; ?>
            <?php if ($foto['moto_ano']): ?>
              · <?= $foto['moto_ano'] ?>
            <?php endif; ?>
          </span>
        </div>

        <?php if (!empty($foto['legenda'])): ?>
        <div class="mod-card-legenda">
          "<?= View::e($foto['legenda']) ?>"
        </div>
        <?php endif; ?>

        <div class="mod-card-meta">
          <span>
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <circle cx="12" cy="12" r="10"/>
              <polyline points="12 6 12 12 16 14"/>
            </svg>
            <?= date('d/m H:i', strtotime($foto['criado_em'])) ?>
          </span>
          <span>
            <?= $foto['largura'] ?>×<?= $foto['altura'] ?>px
            · <?= round($foto['tamanho_bytes'] / 1024) ?>KB
          </span>
        </div>
      </div>

      <!-- Ações -->
      <div class="mod-card-actions">
        <button type="button" class="mod-btn mod-btn--rejeitar" data-id="<?= $foto['id'] ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="18" y1="6" x2="6"  y2="18"/>
            <line x1="6"  y1="6" x2="18" y2="18"/>
          </svg>
          Rejeitar
        </button>
        <button type="button" class="mod-btn mod-btn--aprovar" data-id="<?= $foto['id'] ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="3" stroke-linecap="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          Aprovar
        </button>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<!-- Lightbox de zoom -->
<div class="mod-lightbox" id="mod-lightbox" hidden>
  <button type="button" class="mod-lightbox-close">×</button>
  <img src="" alt="">
</div>

<!-- Modal de rejeição -->
<div class="mod-modal" id="mod-modal-rejeitar" hidden>
  <div class="mod-modal-backdrop"></div>
  <div class="mod-modal-content">
    <h3>Rejeitar foto</h3>
    <p>Informe o motivo da rejeição (será visível para o cliente):</p>

    <div class="mod-motivos-presets">
      <button type="button" data-motivo="Conteúdo inadequado para o site">Inadequado</button>
      <button type="button" data-motivo="Imagem com baixa qualidade">Baixa qualidade</button>
      <button type="button" data-motivo="Contém placa visível ou dados pessoais">Dados pessoais</button>
      <button type="button" data-motivo="Foto não é de moto">Não é moto</button>
      <button type="button" data-motivo="Marca d'água de terceiros">Marca d'água</button>
    </div>

    <textarea id="mod-motivo-input" rows="3"
              placeholder="Ou descreva outro motivo..." maxlength="255"></textarea>

    <div class="mod-modal-footer">
      <button type="button" class="btn-cancel" id="mod-cancel-reject">Cancelar</button>
      <button type="button" class="btn-confirm-reject" id="mod-confirm-reject">
        Rejeitar foto
      </button>
    </div>
  </div>
</div>