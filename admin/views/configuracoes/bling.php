<?php // views/admin/configuracoes/bling.php ?>

<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <h1 class="admin-page-title">Integração Bling</h1>
      <p class="admin-page-sub">ERP, NF-e e sincronização de estoque</p>
    </div>
    <?php if ($conectado): ?>
    <div style="display:flex;gap:8px;">
      <button type="button" class="btn btn-outline btn-sm" id="btn-sync-estoque">
        <?= IconLibrary::render('sync') ?>
        Sync estoque agora
      </button>

      <button type="button" class="btn btn-primary btn-sm" id="btn-vincular-contatos">
        <?= IconLibrary::render('person-serach') ?>
        Vincular contatos
      </button>
      <button type="button" class="btn btn-outline btn-sm btn-danger" id="btn-desconectar">
        <?= IconLibrary::render('sync-disabled') ?>
        Desconectar
      </button>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($_GET['sucesso'])): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:16px;">
    ✓ Conta Bling conectada com sucesso!
  </div>
  <?php elseif (!empty($_GET['erro'])): ?>
  <div class="admin-alert admin-alert--danger" style="margin-bottom:16px;">
    Erro: <?= View::e($_GET['erro']) ?>
  </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start;">

    <!-- ── Coluna principal ─────────────────────────── -->
    <div>

      <!-- Status da conexão -->
      <div class="admin-card" style="margin-bottom:14px;">
        <h3 class="ap-card-title">Status da conexão</h3>
        <div style="padding:16px 20px;">
          <?php if ($conectado): ?>
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="width:10px;height:10px;border-radius:50%;background:var(--success);box-shadow:0 0 0 3px rgba(22,163,74,.2);"></div>
            <strong style="color:var(--success);">Conectado ao Bling</strong>
            <?php if ($tokenInfo): ?>
            <span style="font-size:12px;color:var(--c-text-muted);">
              Token expira em <?= date('d/m H:i', strtotime($tokenInfo['expires_at'])) ?>
            </span>
            <?php endif; ?>
          </div>
          <div style="font-size:13px;color:var(--c-text-muted);">
            Última sincronização de estoque:
            <strong><?= $ultimaSync ? date('d/m/Y H:i', strtotime($ultimaSync)) : 'Nunca' ?></strong>
          </div>
          <?php else: ?>
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <div style="width:10px;height:10px;border-radius:50%;background:var(--text-3);"></div>
            <strong style="color:var(--c-text-muted);">Não conectado</strong>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Credenciais OAuth -->
      <?php if (!$conectado): ?>
      <div class="admin-card" style="margin-bottom:14px;">
        <h3 class="ap-card-title">1. Configure suas credenciais</h3>
        <div style="padding:16px 20px;">
          <p style="font-size:13px;color:var(--c-text-muted);margin-bottom:16px;line-height:1.6;">
            Acesse <strong>Bling → Preferências → API → Aplicativos OAuth</strong> e crie um app
            com a URL de callback: <code><?= BASE_URL ?>/admin/configuracoes/bling/callback</code>
          </p>
          <div class="ap-form-group">
            <label class="ap-form-label">Client ID</label>
            <input type="text" id="bling-client-id" class="form-control" placeholder="xxxxxxxxxxxxxxxx">
          </div>
          <div class="ap-form-group">
            <label class="ap-form-label">Client Secret</label>
            <input type="password" id="bling-client-secret" class="form-control" placeholder="••••••••••••••••">
          </div>
          <div style="display:flex;gap:8px;margin-top:4px;">
            <button type="button" class="btn btn-outline btn-sm" id="btn-salvar-creds">Salvar credenciais</button>
          </div>
          <span id="creds-status" style="font-size:13px;"></span>
        </div>
      </div>

      <div class="admin-card" style="margin-bottom:14px;">
        <h3 class="ap-card-title">2. Autorize o acesso</h3>
        <div style="padding:16px 20px;">
          <p style="font-size:13px;color:var(--c-text-muted);margin-bottom:14px;">
            Após salvar as credenciais, clique abaixo para ser redirecionado ao Bling e autorizar o acesso.
          </p>
          <a href="<?= ADMIN_URL ?>/configuracoes/bling/autorizar" class="btn btn-primary">
            Conectar ao Bling
          </a>
        </div>
      </div>
      <?php endif; ?>

      <!-- Webhook URL -->
      <div class="admin-card" style="margin-bottom:14px;">
        <h3 class="ap-card-title">URL do Webhook</h3>
        <div style="padding:16px 20px;">
          <p style="font-size:13px;color:var(--c-text-muted);margin-bottom:10px;line-height:1.6;">
            Configure esta URL em <strong>Bling → Preferências → Webhooks</strong>
            para receber atualizações de status e NF-e em tempo real.
          </p>
          <div style="display:flex;gap:8px;align-items:center;">
            <code class="ap-code-block" id="webhook-url" style="flex:1;background:var(--c-bg-alt);padding:8px 12px;border-radius:8px;font-size:13px;">
              <?= BASE_URL ?>/webhook/bling
            </code>
            <button type="button" class="btn btn-outline btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('webhook-url').textContent.trim());showToast('Copiado!','success');">
              Copiar
            </button>
          </div>
          <p style="font-size:12px;color:var(--c-text-muted);margin-top:10px;">
            Eventos para ativar: <strong>Pedido alterado</strong>, <strong>Nota Fiscal autorizada</strong>, <strong>Estoque alterado</strong>
          </p>
        </div>
      </div>

      <!-- Mapeamento de status -->
      <div class="admin-card" style="margin-bottom:14px;">
        <div style="padding:14px 20px;border-bottom:1px solid var(--c-border);display:flex;align-items:center;justify-content:space-between;">
          <h3 style="margin:0;font-size:13px;font-weight:800;">Mapeamento de status</h3>
          <button type="button" class="btn btn-outline btn-sm" id="btn-abrir-status-map">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4z"/></svg>
            Configurar
          </button>
        </div>
        <div style="padding:14px 20px;font-size:13px;color:var(--c-text-muted);line-height:1.6;">
          Configure como cada situação do Bling corresponde a um status do site.
          Usado ao receber webhooks de atualização de pedido.
        </div>
      </div>

      <!-- Log de operações -->
      <div class="admin-card">
        <div style="padding:14px 20px;border-bottom:1px solid var(--c-border);display:flex;align-items:center;justify-content:space-between;">
          <h3 style="margin:0;font-size:13px;font-weight:800;">Log de operações</h3>
          <span class="odh-count-badge"><?= count($logs) ?></span>
        </div>
        <div class="table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th>Tipo</th><th>Direção</th><th>Ref.</th>
                <th>Status</th><th>Erro</th><th>Data</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $log): ?>
              <tr>
                <td><span class="badge"><?= View::e($log['tipo']) ?></span></td>
                <td><?= View::e($log['direcao']) ?></td>
                <td><code style="font-size:11px;"><?= View::e($log['referencia_id'] ?? '—') ?></code></td>
                <td>
                  <span class="badge badge-<?= $log['status'] === 'ok' ? 'success' : ($log['status'] === 'erro' ? 'danger' : 'warning') ?>">
                    <?= $log['status'] ?>
                  </span>
                </td>
                <td style="font-size:12px;color:var(--c-danger);max-width:200px;overflow:hidden;text-overflow:ellipsis;">
                  <?= View::e($log['msg_erro'] ?? '') ?>
                </td>
                <td style="font-size:12px;color:var(--c-text-muted);">
                  <?= date('d/m H:i', strtotime($log['criado_em'])) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ── Aside: informações ───────────────────────── -->
    <aside>

      <!-- Saúde da integração -->
      <?php
        $cobOk   = !empty($cobertura['ok']);
        $temFila = !empty($pedidosFalha);
        $corTopo = ($cobOk && !$temFila) ? 'var(--success)' : 'var(--danger)';
      ?>
      <div class="admin-card" style="margin-bottom:14px;border-top:3px solid <?= $corTopo ?>;">
        <h3 class="ap-card-title">Saúde da integração</h3>
        <div style="padding:14px 20px 18px;">

          <p style="margin:0 0 14px;font-size:12.5px;color:var(--c-text-muted);line-height:1.6;">
            O Bling é o dono do estoque. Produto sem vínculo não recebe saldo
            <strong>nem dá baixa</strong> — ele vai ao Bling como texto livre e
            segue à venda em todos os canais com um número que não existe mais.
          </p>

          <!-- Cobertura de vínculo -->
          <div style="display:flex;gap:10px;margin-bottom:12px;">
            <div style="flex:1;text-align:center;padding:10px 6px;border:1px solid var(--c-border);border-radius:8px;
                        <?= (int)$cobertura['produtos_sem'] > 0 ? 'background:#fef2f2;border-color:#fecaca;' : '' ?>">
              <div style="font-size:20px;font-weight:900;color:<?= (int)$cobertura['produtos_sem'] > 0 ? 'var(--danger)' : 'var(--success)' ?>;">
                <?= (int)$cobertura['produtos_sem'] ?>
              </div>
              <div style="font-size:11.5px;color:var(--c-text-muted);line-height:1.4;">
                produtos ativos<br>sem vínculo
              </div>
              <div style="font-size:10.5px;color:var(--c-text-muted);margin-top:3px;">
                de <?= (int)$cobertura['produtos_total'] ?>
              </div>
            </div>
            <div style="flex:1;text-align:center;padding:10px 6px;border:1px solid var(--c-border);border-radius:8px;
                        <?= (int)$cobertura['skus_sem'] > 0 ? 'background:#fef2f2;border-color:#fecaca;' : '' ?>">
              <div style="font-size:20px;font-weight:900;color:<?= (int)$cobertura['skus_sem'] > 0 ? 'var(--danger)' : 'var(--success)' ?>;">
                <?= (int)$cobertura['skus_sem'] ?>
              </div>
              <div style="font-size:11.5px;color:var(--c-text-muted);line-height:1.4;">
                SKUs ativos<br>sem vínculo
              </div>
              <div style="font-size:10.5px;color:var(--c-text-muted);margin-top:3px;">
                de <?= (int)$cobertura['skus_total'] ?>
              </div>
            </div>
          </div>

          <?php if (!$cobOk): ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
            <?php if ((int)$cobertura['produtos_sem'] > 0): ?>
            <a href="<?= ADMIN_URL ?>/produtos?bling_sync=pai_nao_sync" class="btn btn-outline btn-sm">
              Ver produtos sem vínculo
            </a>
            <?php endif; ?>
            <?php if ((int)$cobertura['skus_sem'] > 0): ?>
            <a href="<?= ADMIN_URL ?>/produtos?bling_sync=sku_nao_sync" class="btn btn-outline btn-sm">
              Ver SKUs sem vínculo
            </a>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <div style="font-size:12.5px;color:var(--success);font-weight:700;margin-bottom:14px;">
            ✓ Catálogo vendável 100% vinculado
          </div>
          <?php endif; ?>

          <!-- Fila de pedidos -->
          <div style="border-top:1px solid var(--c-border);padding-top:12px;">
            <div style="display:flex;align-items:center;justify-content:space-between;font-size:13px;margin-bottom:8px;">
              <span style="color:var(--c-text-muted);">Pedidos na fila</span>
              <strong><?= (int)$filaPendente ?></strong>
            </div>

            <?php if ($temFila): ?>
            <div style="font-size:12.5px;color:var(--danger);font-weight:700;margin-bottom:8px;">
              <?= count($pedidosFalha) ?> pedido(s) falharam — não baixaram estoque
            </div>
            <div style="max-height:190px;overflow:auto;">
              <?php foreach ($pedidosFalha as $pf): ?>
              <div style="padding:8px 10px;border:1px solid #fecaca;background:#fef2f2;border-radius:7px;margin-bottom:6px;">
                <a href="<?= ADMIN_URL ?>/pedidos/<?= (int)$pf['id'] ?>"
                   style="font-weight:800;font-size:12.5px;">#<?= View::e($pf['codigo']) ?></a>
                <span style="font-size:11px;color:var(--c-text-muted);">
                  · <?= (int)$pf['bling_sync_tentativas'] ?> tentativas
                </span>
                <div style="font-size:11px;color:var(--c-text-muted);margin-top:3px;word-break:break-word;">
                  <?= View::e(mb_substr((string)$pf['bling_sync_erro'], 0, 160)) ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="font-size:12.5px;color:var(--success);font-weight:700;">
              ✓ Nenhum pedido travado
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="admin-card" style="margin-bottom:14px;">
        <h3 class="ap-card-title">O que está integrado</h3>
        <div style="padding:14px 20px;">
          <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:10px;font-size:13.5px;">
            <?php
            $itens = [
              ['Pedido aprovado → Bling',        true,  'Automático ao confirmar pagamento'],
              ['Status Bling → site',             true,  'Via webhook em tempo real'],
              ['NF-e autorizada → site',          true,  'URL do PDF salva automaticamente'],
              ['Sync de estoque',                 true,  'Cron a cada 15min ou manual'],
              ['Sync de preços',                  false, 'Sprint futuro'],
              ['Pedidos antigos → Bling',         false, 'Manual por pedido'],
            ];
            foreach ($itens as [$label, $ativo, $detalhe]):
            ?>
            <li style="display:flex;gap:10px;">
              <span style="color:<?= $ativo ? 'var(--success)' : 'var(--text-3)' ?>;">
                <?= $ativo ? '✓' : '○' ?>
              </span>
              <div>
                <strong style="color:<?= $ativo ? 'var(--c-heading)' : 'var(--text-3)' ?>"><?= $label ?></strong>
                <div style="font-size:12px;color:var(--c-text-muted);"><?= $detalhe ?></div>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>

      <div class="admin-card" style="margin-bottom:14px;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px 0;">
          <h3 class="ap-card-title" style="margin:0;">Vincular produtos ao Bling</h3>
          <button type="button" class="btn btn-primary btn-sm" id="btn-vincular-produtos">
            Vincular agora
          </button>
        </div>
        <div style="padding:14px 20px 18px;">
          <p style="font-size:13px;color:var(--c-text-muted);line-height:1.6;margin:0;">
            Lista o catálogo do Bling e preenche o <code>bling_id</code> dos produtos e SKUs
            que casam por código. Operação única — rode após importar da Tray.
            Não estoura o limite: usa listagem em lote, não busca item a item.
          </p>
        </div>
      </div>
      

      <!-- Depósitos do Bling -->
      <div class="admin-card" style="margin-bottom:14px;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px 0;">
          <h3 class="ap-card-title" style="margin:0;">Depósitos</h3>
          <button type="button" class="btn btn-outline btn-sm" id="btn-sync-depositos">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
              <path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
            </svg>
            Sincronizar
          </button>
        </div>

        <div style="padding:14px 20px 18px;">
          <p style="font-size:13px;color:var(--c-text-muted);margin-bottom:14px;line-height:1.6;">
            O depósito marcado como <strong>padrão</strong> é a fonte do saldo que o sync de estoque reflete no site.
          </p>

          <div id="depositos-lista">
            <?php if (empty($depositos)): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:14px;border:1px dashed var(--c-border);border-radius:8px;font-size:13px;color:var(--c-text-muted);">
              <div style="width:9px;height:9px;border-radius:50%;background:var(--text-3);flex-shrink:0;"></div>
              Nenhum depósito sincronizado. Clique em <strong style="margin:0 3px;">Sincronizar</strong> para buscar da sua conta Bling.
            </div>
            <?php else: ?>
              <?php foreach ($depositos as $dep): ?>
              <div style="display:flex;align-items:center;gap:12px;padding:11px 14px;border:1px solid var(--c-border);border-radius:8px;margin-bottom:8px;<?= $dep['padrao'] ? 'background:#f0fdf4;border-color:#bbf7d0;' : '' ?>">
                <div style="width:9px;height:9px;border-radius:50%;flex-shrink:0;background:<?= $dep['ativo'] ? 'var(--success)' : 'var(--border2)' ?>;<?= $dep['padrao'] ? 'box-shadow:0 0 0 3px rgba(22,163,74,.2);' : '' ?>"></div>
                <div style="flex:1;min-width:0;">
                  <div style="font-weight:700;font-size:13.5px;color:var(--c-dark);">
                    <?= View::e($dep['descricao'] ?: 'Depósito sem nome') ?>
                    <?php if ($dep['padrao']): ?>
                    <span style="font-size:10.5px;font-weight:800;color:var(--success);background:var(--success-lt);padding:1px 7px;border-radius:99px;margin-left:6px;vertical-align:middle;">PADRÃO</span>
                    <?php endif; ?>
                  </div>
                  <code style="font-size:11.5px;color:var(--c-text-muted);">ID: <?= View::e($dep['bling_deposito_id']) ?></code>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="admin-card">
        <h3 class="ap-card-title">Cron de estoque</h3>
        <div style="padding:14px 20px;">
          <p style="font-size:13px;color:var(--c-text-muted);margin-bottom:12px;line-height:1.6;">
            Adicione ao crontab do servidor para sync automático:
          </p>
          <code style="display:block;background:var(--c-bg-alt);padding:10px 12px;border-radius:8px;font-size:11.5px;line-height:1.8;">
            */15 * * * * php <?= ROOT_PATH ?>/cron/bling-sync-estoque.php
          </code>
        </div>
      </div>
    </aside>
  </div>
</div>

<script>
var ADMIN_URL = '<?= ADMIN_URL ?>';
// ── Mapeamento de status ──────────────────────────────
$('#btn-abrir-status-map').on('click', function () {
  var drawer = window.adminDrawer({
    titulo  : 'Mapeamento de status',
    tamanho : 'md',
    conteudo:
      '<div style="padding:4px 0 12px;font-size:13px;color:var(--c-text-muted);">' +
        'Configure qual situação do Bling corresponde a qual status do site.' +
      '</div>' +
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">' +
        '<span id="sm-count" style="font-size:12px;color:var(--c-text-muted);"></span>' +
        '<button type="button" class="btn btn-ghost btn-sm" id="btn-importar-situacoes">' +
          '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>' +
          ' Carregar situações do Bling' +
        '</button>' +
      '</div>' +
      '<div id="sm-table-wrap"></div>' +
      '<div id="sm-error" style="display:none;color:var(--danger);font-size:13px;margin-top:10px;"></div>' +
      '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">' +
        '<button type="button" class="btn btn-ghost btn-sm" id="btn-add-linha">+ Adicionar linha</button>' +
        '<button type="button" class="btn btn-primary btn-sm" id="btn-salvar-sm">Salvar mapeamento</button>' +
      '</div>',
  });

  var _statusLocais = [];

  // Carrega mapeamento atual
  $.get(ADMIN_URL + '/configuracoes/bling/status-map')
  .done(function (r) {
    if (!r.ok) return;
    _statusLocais = r.status_locais || [];
    renderTabela(r.mapa || []);
    $('#sm-count').text(r.mapa.length + ' mapeamento' + (r.mapa.length !== 1 ? 's' : ''));
  });

  // Importa situações do Bling e adiciona as que não existem
  $(document).on('click', '#btn-importar-situacoes', function () {
    var $btn = $(this);
    CK.btnLoading($btn);
    $.get(ADMIN_URL + '/configuracoes/bling/situacoes')
    .done(function (r) {
      CK.btnLoading($btn, false);
      if (!r.ok) { showToast(r.msg, 'error'); return; }
      // Adiciona apenas as que ainda não estão na tabela
      var existentes = $('#sm-table-wrap tbody tr').map(function () {
        return $(this).find('[data-field="bling_id"]').val();
      }).get();
      var novas = r.situacoes.filter(function (s) {
        return !existentes.includes(s.id);
      });
      novas.forEach(function (s) {
        appendLinha({ bling_id: s.id, bling_label: s.descricao, status_local: '' });
      });
      if (novas.length) showToast(novas.length + ' situações adicionadas.', 'success');
      else showToast('Nenhuma situação nova.', 'info');
    })
    .fail(function () { CK.btnLoading($btn, false); showToast('Erro ao buscar situações.', 'error'); });
  });

  // Adiciona linha manual
  $(document).on('click', '#btn-add-linha', function () {
    appendLinha({ bling_id: '', bling_label: '', status_local: '' });
  });

  // Remove linha
  $(document).on('click', '.sm-rm', function () {
    $(this).closest('tr').remove();
  });

  // Salva
  $(document).on('click', '#btn-salvar-sm', function () {
    var $btn = $(this);
    var mapa = [];
    var valido = true;

    $('#sm-table-wrap tbody tr').each(function () {
      var bId = ($(this).find('[data-field="bling_id"]').val());
      var bLb = ($(this).find('[data-field="bling_label"]').val());
      var lSt = $(this).find('[data-field="status_local"]').val();
      if (!bId || !lSt) { valido = false; return; }
      mapa.push({ bling_id: bId, bling_label: bLb, status_local: lSt });
    });

    if (!valido) {
      $('#sm-error').text('Preencha o ID e o status de todas as linhas.').show();
      return;
    }
    $('#sm-error').hide();

    CK.btnLoading($btn);
    $.post(ADMIN_URL + '/configuracoes/bling/status-map', {
      _token: CSRF_TOKEN,
      mapa  : JSON.stringify(mapa),
    })
    .done(function (r) {
      CK.btnLoading($btn, false);
      showToast(r.msg, r.ok ? 'success' : 'error');
      if (r.ok) drawer.close();
    })
    .fail(function () { CK.btnLoading($btn, false); showToast('Erro.', 'error'); });
  });

  // ── helpers ──────────────────────────────────────────
  function renderTabela(mapa) {
    var $wrap = $('#sm-table-wrap');
    $wrap.html(
      '<table class="admin-table" style="table-layout:fixed;">' +
        '<thead><tr>' +
          '<th style="width:90px;">ID Bling</th>' +
          '<th>Situação Bling</th>' +
          '<th>Status no site</th>' +
          '<th style="width:36px;"></th>' +
        '</tr></thead>' +
        '<tbody id="sm-tbody"></tbody>' +
      '</table>'
    );
    mapa.forEach(function (row) { appendLinha(row); });
  }

  function appendLinha(row) {
    if (!$('#sm-tbody').length) renderTabela([]);

    var opts = _statusLocais.map(function (s) {
      var sel = s.slug === row.status_local ? ' selected' : '';
      return '<option value="' + s.slug + '"' + sel + '>' + s.label + '</option>';
    }).join('');

    var tr =
      '<tr>' +
        '<td><input type="text" data-field="bling_id" class="form-control form-control-sm"' +
             ' value="' + (row.bling_id || '') + '" placeholder="ex: 15" style="font-family:monospace;"></td>' +
        '<td><input type="text" data-field="bling_label" class="form-control form-control-sm"' +
             ' value="' + (row.bling_label || '') + '" placeholder="Nome da situação"></td>' +
        '<td>' +
          '<select data-field="status_local" class="form-control form-control-sm">' +
            '<option value="">— selecione —</option>' + opts +
          '</select>' +
        '</td>' +
        '<td style="text-align:center;">' +
          '<button type="button" class="sm-rm" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:16px;" title="Remover">×</button>' +
        '</td>' +
      '</tr>';

    $('#sm-tbody').append(tr);
  }
});

// ── Salvar credenciais ────────────────────────────────
$('#btn-salvar-creds').on('click', function() {
  var $btn = $(this);
  CK.btnLoading($btn);
  $.post(ADMIN_URL + '/configuracoes/bling/credenciais', {
    _token       : CSRF_TOKEN,
    client_id    : $('#bling-client-id').val().trim(),
    client_secret: $('#bling-client-secret').val().trim(),
  })
  .done(function(r) {
    CK.btnLoading($btn, false);
    showToast(r.msg, r.ok ? 'success' : 'error');
  })
  .fail(function() { CK.btnLoading($btn, false); showToast('Erro.', 'error'); });
});

// ── Sync estoque ──────────────────────────────────────
$('#btn-sync-estoque').on('click', function() {
  var $btn = $(this);
  CK.btnLoading($btn);
  $.post(ADMIN_URL + '/configuracoes/bling/sync-estoque', { _token: CSRF_TOKEN })
  .done(function(r) {
    CK.btnLoading($btn, false);
    showToast(r.msg, r.ok ? 'success' : 'error');
    if (r.ok) setTimeout(function() { location.reload(); }, 2000);
  })
  .fail(function() { CK.btnLoading($btn, false); showToast('Erro.', 'error'); });
});

// ── Desconectar ───────────────────────────────────────
$('#btn-desconectar').on('click', function() {
  if (!confirm('Desconectar a conta Bling?')) return;
  $.post(ADMIN_URL + '/configuracoes/bling/desconectar', { _token: CSRF_TOKEN })
  .done(function(r) {
    if (r.ok) location.reload();
  });
});


$('#btn-sync-depositos').on('click', function () {
  var $btn = $(this);
  CK.btnLoading($btn);
  $.post(ADMIN_URL + '/configuracoes/bling/sync-depositos', { _token: CSRF_TOKEN })
    .done(function (r) {
      CK.btnLoading($btn, false);
      showToast(r.msg, r.ok ? 'success' : 'error');
      if (r.ok) setTimeout(function () { location.reload(); }, 1200);
    })
    .fail(function () { CK.btnLoading($btn, false); showToast('Erro de rede.', 'error'); });
});

$('#btn-vincular-produtos').on('click', function () {
  var $btn = $(this);
  if (!confirm('Vincular produtos e SKUs ao Bling? Pode levar alguns minutos.')) return;
  CK.btnLoading($btn);
  $.ajax({
    url: ADMIN_URL + '/configuracoes/bling/vincular-produtos',
    method: 'POST',
    data: { _token: CSRF_TOKEN },
    timeout: 300000   // 5min — a operação lista o catálogo inteiro
  })
  .done(function (r) {
    CK.btnLoading($btn, false);
    showToast(r.msg, r.ok ? 'success' : 'error');
  })
  .fail(function (xhr, status) {
    CK.btnLoading($btn, false);
    showToast(status === 'timeout' ? 'Demorou demais — verifique os logs.' : 'Erro de rede.', 'error');
  });
});


$('#btn-vincular-contatos').on('click', function () {
  var $btn = $(this);
  if (!confirm('Vincular contatos ao Bling por CPF? Pode levar alguns minutos.')) return;
  CK.btnLoading($btn);
  $.ajax({ url: ADMIN_URL + '/configuracoes/bling/vincular-contatos',
           method: 'POST', data: { _token: CSRF_TOKEN }, timeout: 300000 })
  .done(function (r) { CK.btnLoading($btn, false); showToast(r.msg, r.ok ? 'success' : 'error'); })
  .fail(function (xhr, s) { CK.btnLoading($btn, false);
    showToast(s === 'timeout' ? 'Demorou demais — veja os logs.' : 'Erro de rede.', 'error'); });
});
</script>