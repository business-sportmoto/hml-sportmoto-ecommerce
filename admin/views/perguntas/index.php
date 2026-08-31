<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <h1>Perguntas e Respostas</h1>
      <p>
        <?= $contadores['aguardando_admin'] ?> aguardando ·
        <?= $contadores['respondida']        ?> respondidas
      </p>
    </div>
  </div>

  <div style="display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap;">
    <?php
    $tabs = [
      'aguardando_admin' => ['label' => 'Aguardando',  'cor' => 'warning'],
      'respondida'       => ['label' => 'Respondidas', 'cor' => 'success'],
      'rejeitada'        => ['label' => 'Rejeitadas',  'cor' => 'muted'],
    ];
    foreach ($tabs as $key => $tab):
    ?>
    <a href="<?= BASE_URL ?>/admin/perguntas?status=<?= $key ?>"
       class="mod-tab <?= $filtro === $key ? 'is-active' : '' ?>">
      <?= $tab['label'] ?>
      <?php if (($contadores[$key] ?? 0) > 0): ?>
      <span class="mod-tab-badge"><?= $contadores[$key] ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($perguntas)): ?>
  <div class="admin-empty-state">
    <h3>Nenhuma pergunta neste status.</h3>
  </div>
  <?php else: ?>

  <div class="qa-admin-list">
    <?php foreach ($perguntas as $p): ?>
    <article class="qa-admin-card" id="qa-card-<?= $p['id'] ?>">

      <div class="qa-admin-head">
        <div class="qa-admin-author">
          <div class="qa-admin-avatar">
            <?= mb_strtoupper(mb_substr($p['autor_nome'], 0, 1)) ?>
          </div>
          <div>
            <strong><?= View::e($p['autor_nome']) ?></strong>
            <span><?= View::e($p['autor_email']) ?></span>
            <?php if ($p['autor_telefone']): ?>
            <span>· <?= View::e($p['autor_telefone']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <a href="<?= BASE_URL ?>/produto/<?= View::e($p['produto_slug']) ?>"
           target="_blank" class="qa-admin-produto">
          <?= View::e($p['produto_nome']) ?>
        </a>
      </div>

      <div class="qa-admin-pergunta">
        <strong>Pergunta:</strong>
        <p><?= View::e($p['pergunta']) ?></p>
        <small>
          <?= date('d/m/Y H:i', strtotime($p['criado_em'])) ?>
          · IP: <?= View::e($p['ip_origem']) ?>
        </small>
      </div>

      <?php if ($p['status'] === 'aguardando_admin'): ?>
      <form class="qa-admin-form" data-id="<?= $p['id'] ?>">
        <input type="hidden" name="_csrf_token" value="<?= SecurityHelper::generateCsrf() ?>">
        <input type="hidden" name="id" value="<?= $p['id'] ?>">
        <textarea name="resposta" required minlength="10" maxlength="2000" rows="4"
                  placeholder="Escreva a resposta. Será enviada por e-mail ao cliente e publicada no site."></textarea>
        <div class="qa-admin-actions">
          <button type="button" class="btn btn-ghost qa-rejeitar" data-id="<?= $p['id'] ?>">
            Rejeitar
          </button>
          <button type="submit" class="btn btn-primary">
            Responder e enviar e-mail
          </button>
        </div>
      </form>
      <?php elseif ($p['resposta']): ?>
      <div class="qa-admin-resposta">
        <strong>Resposta (<?= $p['resposta_fonte'] === 'ia' ? 'IA' : 'Admin' ?>):</strong>
        <p><?= View::e($p['resposta']) ?></p>
        <small><?= date('d/m/Y H:i', strtotime($p['respondida_em'])) ?></small>
      </div>
      <?php endif; ?>

    </article>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>
</div>

<script>

</script>