<?php
/**
 * Detalhe da família: dados, quem está dentro e como mexer no grupo.
 *
 * Vincular/desvincular usa os MESMOS endpoints do card de família no
 * formulário de produto — a família é a mesma coisa vista dos dois lados.
 */
$membros = $familia['membros'] ?? [];
$ativos  = count(array_filter($membros, static fn(array $m) => (int) $m['ativo'] === 1));
?>
<div class="admin-page" id="fam-detalhe" data-id="<?= (int) $familia['id'] ?>">

  <div class="admin-page-header">
    <div>
      <a href="<?= BASE_URL ?>/admin/familias" class="fam-voltar">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="19" y1="12" x2="5" y2="12"/>
          <polyline points="12 19 5 12 12 5"/>
        </svg>
        Famílias
      </a>
      <h1><?= View::e($familia['nome']) ?></h1>
      <p>
        <?= count($membros) ?> produto<?= count($membros) == 1 ? '' : 's' ?> nesta família
        <?php if (count($membros) > $ativos): ?>
        · <?= count($membros) - $ativos ?> inativo(s)
        <?php endif; ?>
        · URL <code>/<?= View::e($familia['slug']) ?></code>
      </p>
    </div>
    <button type="button" class="btn btn-ghost" id="btn-excluir-familia"
            data-nome="<?= View::e($familia['nome']) ?>"
            data-total="<?= count($membros) ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <polyline points="3 6 5 6 21 6"/>
        <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
      </svg>
      Excluir família
    </button>
  </div>

  <?php if (count($membros) < 2): ?>
  <div class="fam-aviso">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <circle cx="12" cy="12" r="10"/>
      <line x1="12" y1="8"  x2="12" y2="13"/>
      <line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    <div>
      <strong>A loja ainda não mostra nada para esta família.</strong>
      O seletor de versões só aparece na página do produto quando há pelo menos
      dois produtos ativos aqui dentro.
    </div>
  </div>
  <?php endif; ?>

  <div class="fam-grid">

    <!-- ── Dados da família ──────────────────────────────── -->
    <div class="admin-card">
      <div class="admin-card-header"><h2 class="admin-card-title">Dados</h2></div>
      <div class="admin-card-body">
        <form id="form-familia">
          <?= SecurityHelper::csrfField() ?>
          <input type="hidden" name="id" value="<?= (int) $familia['id'] ?>">

          <div class="form-group">
            <label class="pe-label" for="fam-nome">Nome da família</label>
            <input type="text" name="nome" id="fam-nome" class="form-control"
                   maxlength="150" required
                   value="<?= View::e($familia['nome']) ?>">
            <small class="form-hint">
              É o nome que aparece na busca de famílias, dentro do produto.
              Use o modelo, não a cor: <em>FF358 Pro Monocolor</em>, não
              <em>FF358 Preto</em>.
            </small>
          </div>

          <div class="form-group">
            <label class="pe-label" for="fam-descricao">Descrição (interna)</label>
            <textarea name="descricao" id="fam-descricao" class="form-control" rows="3"
                      placeholder="Opcional — para quem for mexer nisso depois."><?= View::e((string) ($familia['descricao'] ?? '')) ?></textarea>
          </div>

          <div class="form-group fam-ativo-row">
            <label class="admin-check-label">
              <input type="checkbox" name="ativo" id="fam-ativo" value="1"
                     <?= (int) $familia['ativo'] === 1 ? 'checked' : '' ?>>
              <span class="admin-check-custom"></span>
              Família ativa
            </label>
            <small class="form-hint">
              Inativa, o agrupamento some da loja — os produtos continuam
              publicados, cada um na sua página.
            </small>
          </div>

          <div class="fam-form-foot">
            <button type="submit" class="btn btn-primary" id="btn-salvar-familia">
              Salvar alterações
            </button>
            <span class="fam-slug-nota">
              A URL <code>/<?= View::e($familia['slug']) ?></code> não muda ao renomear.
            </span>
          </div>
        </form>

        <?php if (!empty($familia['agrupadores'])): ?>
        <div class="fam-agrupadores">
          <span class="fam-bloco-titulo">Atributos que agrupam</span>
          <p class="form-hint">
            Configurados no produto. São as variações que a loja usa para montar
            o seletor desta família.
          </p>
          <?php foreach ($familia['agrupadores'] as $ag): ?>
          <span class="admin-badge admin-badge--muted">
            <?= View::e($ag['nome']) ?><?= (int) $ag['obrigatorio'] === 1 ? ' *' : '' ?>
          </span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Produtos vinculados ───────────────────────────── -->
    <div class="admin-card">
      <div class="admin-card-header fam-membros-head">
        <h2 class="admin-card-title">Produtos vinculados</h2>
        <button type="button" class="btn btn-sm btn-outline" id="btn-add-produto">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <line x1="5"  y1="12" x2="19" y2="12"/>
          </svg>
          Adicionar produto
        </button>
      </div>

      <div class="fam-add-box" id="fam-add-box" hidden>
        <div class="admin-search-wrap">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" id="fam-add-input" class="admin-search-input"
                 aria-label="Buscar produto para adicionar à família"
                 placeholder="Buscar produto por nome ou referência...">
        </div>
        <div id="fam-add-results" class="fam-add-results">
          <p class="fam-hint-linha">Digite para buscar. O produto sai da família anterior, se tiver uma.</p>
        </div>
      </div>

      <?php if (empty($membros)): ?>
      <div class="admin-empty-state">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1" stroke-linecap="round">
          <rect x="3" y="3" width="18" height="18" rx="2"/>
          <line x1="8" y1="12" x2="16" y2="12"/>
        </svg>
        <p>Nenhum produto nesta família.</p>
      </div>

      <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table" id="fam-membros-table">
          <thead>
            <tr>
              <th width="56">Foto</th>
              <th>Produto</th>
              <th width="110">Preço</th>
              <th width="90" class="text-center">Situação</th>
              <th width="110">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($membros as $m): ?>
            <tr id="fam-membro-<?= (int) $m['id'] ?>">
              <td>
                <?php if (!empty($m['imagem'])): ?>
                <img src="<?= View::e($m['imagem']) ?>" alt="" loading="lazy" class="prod-thumb">
                <?php else: ?>
                <div class="prod-thumb-empty">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                  </svg>
                </div>
                <?php endif; ?>
              </td>

              <td>
                <a href="<?= BASE_URL ?>/admin/produtos/<?= (int) $m['id'] ?>/editar"
                   class="prod-nome-link"><?= View::e($m['nome']) ?></a>
                <span class="cat-slug">
                  <?= $m['marca'] ? View::e($m['marca']) . ' · ' : '' ?>
                  <?= $m['sku_legado'] ? View::e($m['sku_legado']) : 'sem referência' ?>
                </span>
              </td>

              <td><?= PriceHelper::format((float) $m['preco']) ?></td>

              <td class="text-center">
                <?php if ((int) $m['ativo'] === 1): ?>
                <span class="admin-badge admin-badge--success">Ativo</span>
                <?php else: ?>
                <span class="admin-badge admin-badge--muted">Inativo</span>
                <?php endif; ?>
              </td>

              <td>
                <div class="admin-row-actions">
                  <a href="<?= BASE_URL ?>/produto/<?= View::e($m['slug']) ?>" target="_blank"
                     class="btn btn-sm btn-ghost" title="Ver na loja"
                     aria-label="Ver <?= View::e($m['nome']) ?> na loja">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                      <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
                      <polyline points="15 3 21 3 21 9"/>
                      <line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                  </a>
                  <button type="button" class="btn btn-sm btn-ghost btn-tirar-membro"
                          data-id="<?= (int) $m['id'] ?>"
                          data-nome="<?= View::e($m['nome']) ?>"
                          title="Tirar da família"
                          aria-label="Tirar <?= View::e($m['nome']) ?> da família">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                      <line x1="18" y1="6" x2="6"  y2="18"/>
                      <line x1="6"  y1="6" x2="18" y2="18"/>
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
(function () {
  const raiz = document.getElementById('fam-detalhe');
  if (!raiz) return;
  const familiaId = parseInt(raiz.dataset.id, 10);

  // ── Salvar dados ─────────────────────────────────────────
  $('#form-familia').on('submit', function (e) {
    e.preventDefault();
    const $btn = $('#btn-salvar-familia');
    $btn.prop('disabled', true).text('Salvando...');

    $.post(BASE_URL + '/admin/familias/salvar', {
      id          : familiaId,
      nome        : $('#fam-nome').val(),
      descricao   : $('#fam-descricao').val(),
      ativo       : $('#fam-ativo').is(':checked') ? '1' : '0',
      _csrf_token : CSRF_TOKEN,
    }, function (res) {
      $btn.prop('disabled', false).text('Salvar alterações');
      if (!res.ok) { showToast(res.msg || 'Não foi possível salvar.', 'error'); return; }
      showToast(res.msg, 'success');
      $('.admin-page-header h1').text($('#fam-nome').val());
    }, 'json').fail(function () {
      $btn.prop('disabled', false).text('Salvar alterações');
      showToast('Falha de conexão ao salvar.', 'error');
    });
  });

  // ── Excluir a família ────────────────────────────────────
  $('#btn-excluir-familia').on('click', async function () {
    const total = parseInt($(this).data('total') || 0, 10);
    const ok = await adminConfirm({
      titulo   : 'Excluir a família "' + $(this).data('nome') + '"?',
      mensagem : total > 0
        ? total + ' produto(s) perdem o vínculo e ficam sem família. Nenhum produto é excluído.'
        : 'A família não tem produtos. Nada mais é afetado.',
      tipo      : 'danger',
      confirmar : 'Sim, excluir',
      cancelar  : 'Cancelar',
    });
    if (!ok) return;

    $.post(BASE_URL + '/admin/familias/excluir', {
      id: familiaId, _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (!res.ok) { showToast(res.msg || 'Não foi possível excluir.', 'error'); return; }
      window.location.href = BASE_URL + '/admin/familias';
    }, 'json');
  });

  // ── Tirar um produto da família ──────────────────────────
  $(document).on('click', '.btn-tirar-membro', async function () {
    const id   = $(this).data('id');
    const nome = $(this).data('nome');

    const ok = await adminConfirm({
      titulo    : 'Tirar da família?',
      mensagem  : '"' + nome + '" sai do grupo e continua publicado normalmente.',
      tipo      : 'warning',
      confirmar : 'Sim, tirar',
      cancelar  : 'Cancelar',
    });
    if (!ok) return;

    $.post(BASE_URL + '/admin/familias/desvincular', {
      produto_id: id, _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (!res.ok) { showToast('Não foi possível desvincular.', 'error'); return; }
      $('#fam-membro-' + id).fadeOut(200, function () { $(this).remove(); });
      showToast('Produto tirado da família.', 'info');
    }, 'json');
  });

  // ── Adicionar produto ────────────────────────────────────
  $('#btn-add-produto').on('click', function () {
    const box = document.getElementById('fam-add-box');
    box.hidden = !box.hidden;
    if (!box.hidden) document.getElementById('fam-add-input').focus();
  });

  let timer;
  $('#fam-add-input').on('input', function () {
    clearTimeout(timer);
    const q  = this.value.trim();
    const el = document.getElementById('fam-add-results');

    if (q.length < 2) {
      el.innerHTML = '<p class="fam-hint-linha">Digite ao menos 2 letras.</p>';
      return;
    }
    el.innerHTML = '<p class="fam-hint-linha">Buscando...</p>';

    timer = setTimeout(function () {
      $.get(BASE_URL + '/admin/api/buscar-produtos', { q: q, limit: 8 }, function (res) {
        const itens = (res.items || []);
        if (!itens.length) {
          el.innerHTML = '<p class="fam-hint-linha">Nenhum produto encontrado.</p>';
          return;
        }
        el.innerHTML = '';
        itens.forEach(function (p) {
          const linha = document.createElement('div');
          linha.className = 'fam-add-item';

          const info = document.createElement('div');
          info.className = 'fam-add-item-info';

          if (p.imagem_url) {
            const img = document.createElement('img');
            img.className = 'fam-mini-foto';
            img.loading = 'lazy';
            img.alt = '';
            img.src = p.imagem_url;
            linha.appendChild(img);
          }

          const nome = document.createElement('span');
          nome.className = 'fam-add-item-nome';
          nome.textContent = p.nome;                      // .textContent: nome vem do banco
          const meta = document.createElement('span');
          meta.className = 'fam-add-item-meta';
          meta.textContent = [p.marca, p.sku_legado, p.preco_fmt].filter(Boolean).join(' · ');
          info.appendChild(nome); info.appendChild(meta);

          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn btn-sm btn-primary';
          btn.textContent = 'Adicionar';
          btn.addEventListener('click', function () { vincular(p.id); });

          linha.appendChild(info); linha.appendChild(btn);
          el.appendChild(linha);
        });
      }, 'json');
    }, 300);
  });

  function vincular(produtoId) {
    $.post(BASE_URL + '/admin/familias/vincular', {
      produto_id  : produtoId,
      familia_id  : familiaId,
      _csrf_token : CSRF_TOKEN,
    }, function (res) {
      if (!res.ok) { showToast(res.msg || 'Não foi possível vincular.', 'error'); return; }
      showToast('Produto adicionado à família.', 'success');
      window.location.reload();
    }, 'json');
  }
})();
</script>
