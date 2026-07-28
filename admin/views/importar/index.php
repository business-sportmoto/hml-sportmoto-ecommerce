<?php
// views/admin/importar/index.php
// $tipoLabels = ['produtos' => 'Produtos', 'variacoes' => 'Variações'];
$tipoLabels = [
    'produtos'  => 'Produtos',
    'variacoes' => 'Variações',
    'clientes'  => 'Clientes',
    'pedidos'   => 'Pedidos',
];
$statusLabels = [
    'aguardando'   => ['cor'=>'warning', 'label'=>'Aguardando'],
    'processando'  => ['cor'=>'primary', 'label'=>'Processando'],
    'concluido'    => ['cor'=>'success', 'label'=>'Concluído'],
    'erro'         => ['cor'=>'danger',  'label'=>'Erro'],
];
?>
<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <h1 class="admin-page-title">Importação Tray</h1>
      <p class="admin-page-sub">Importe produtos e variações exportados da plataforma Tray.</p>
    </div>
  </div>

  <!-- Alertas de instrução -->
  <div class="import-instruction-card">
    <div class="import-step">
      <div class="import-step-num">1</div>
      <div>
        <strong>Exporte os produtos na Tray</strong>
        <p>Painel Tray → Catálogo → Produtos → Exportar CSV</p>
      </div>
    </div>
    <div class="import-step-sep">→</div>
    <div class="import-step">
      <div class="import-step-num">2</div>
      <div>
        <strong>Importe o CSV de produtos aqui</strong>
        <p>Use a aba "Produtos" abaixo</p>
      </div>
    </div>
    <div class="import-step-sep">→</div>
    <div class="import-step">
      <div class="import-step-num">3</div>
      <div>
        <strong>Exporte as variações na Tray</strong>
        <p>Painel Tray → Catálogo → Variações → Exportar CSV</p>
      </div>
    </div>
    <div class="import-step-sep">→</div>
    <div class="import-step">
      <div class="import-step-num">4</div>
      <div>
        <strong>Importe o CSV de variações</strong>
        <p>Use a aba "Variações" abaixo</p>
      </div>
    </div>
  </div>

  <!-- Abas -->
  <div class="admin-tabs" style="margin-bottom:0;">
    <button class="admin-tab is-active" data-tab="produtos">📦 Produtos</button>
    <button class="admin-tab" data-tab="variacoes">🔀 Variações</button>
    <button class="admin-tab" data-tab="clientes">👥 Clientes</button>
    <button class="admin-tab" data-tab="pedidos">📦 Pedidos</button>
    <button class="admin-tab" data-tab="imagens">🖼️ Imagens</button>
    <button class="admin-tab" data-tab="historico">📋 Histórico</button>
  </div>

  <!-- ── Aba Produtos ────────────────────────────── -->
  <div class="admin-tab-content is-active" id="tab-produtos">
    <div class="admin-card import-upload-card">
      <div class="import-upload-area" id="upload-area-produtos">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
          <polyline points="17 8 12 3 7 8"/>
          <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        <p>Arraste o <strong>CSV de produtos</strong> da Tray aqui</p>
        <small>Separador ponto e vírgula · Encoding Latin-1</small>
        <label class="btn btn-outline" style="margin-top:12px;cursor:pointer;">
          Selecionar arquivo
          <input type="file" id="file-produtos" accept=".csv" style="display:none;">
        </label>
        <div id="file-produtos-nome" style="display:none;margin-top:10px;font-size:13px;color:var(--c-text-muted);"></div>
      </div>
    </div>

    <!-- Preview -->
    <div id="preview-produtos" style="display:none;">
      <div class="admin-card" style="margin-top:16px;">
        <div style="padding:14px 20px;border-bottom:1px solid var(--c-border);display:flex;align-items:center;justify-content:space-between;">
          <h3 style="margin:0;font-size:14px;font-weight:800;">Preview — primeiras 5 linhas</h3>
          <span id="preview-prod-total" class="odh-count-badge"></span>
        </div>
        <div class="table-wrap">
          <table class="admin-table" id="preview-prod-table">
            <thead><tr><th>ID Tray</th><th>Nome</th><th>Marca</th><th>Categoria</th><th>Preço</th><th>Estoque</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div style="padding:14px 20px;border-top:1px solid var(--c-border);">
          <button type="button" class="btn btn-primary" id="btn-importar-produtos">
            Importar produtos
          </button>
        </div>
      </div>
    </div>

    <!-- Progresso -->
    <div id="progresso-produtos" style="display:none;margin-top:16px;">
      <?php echo renderProgressCard('produtos'); ?>
    </div>
  </div>

  <!-- ── Aba Variações ───────────────────────────── -->
  <div class="admin-tab-content" id="tab-variacoes">
    <div class="admin-card import-upload-card">
      <div class="import-upload-area" id="upload-area-variacoes">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.5" stroke-linecap="round"><polyline points="17 1 21 5 17 9"/>
          <path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/>
          <path d="M21 13v2a4 4 0 01-4 4H3"/>
        </svg>
        <p>Arraste o <strong>CSV de variações</strong> da Tray aqui</p>
        <small>Importe os produtos primeiro antes das variações</small>
        <label class="btn btn-outline" style="margin-top:12px;cursor:pointer;">
          Selecionar arquivo
          <input type="file" id="file-variacoes" accept=".csv" style="display:none;">
        </label>
        <div id="file-variacoes-nome" style="display:none;margin-top:10px;font-size:13px;color:var(--c-text-muted);"></div>
      </div>
    </div>

    <div id="preview-variacoes" style="display:none;">
      <div class="admin-card" style="margin-top:16px;">
        <div style="padding:14px 20px;border-bottom:1px solid var(--c-border);display:flex;align-items:center;justify-content:space-between;">
          <h3 style="margin:0;font-size:14px;font-weight:800;">Preview — primeiras 5 linhas</h3>
          <span id="preview-var-total" class="odh-count-badge"></span>
        </div>
        <div class="table-wrap">
          <table class="admin-table" id="preview-var-table">
            <thead><tr><th>Produto Tray</th><th>Variação</th><th>Valor</th><th>SKU</th><th>Preço</th><th>Estoque</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div style="padding:14px 20px;border-top:1px solid var(--c-border);">
          <button type="button" class="btn btn-primary" id="btn-importar-variacoes">
            Importar variações
          </button>
        </div>
      </div>
    </div>

    <div id="progresso-variacoes" style="display:none;margin-top:16px;">
      <?php echo renderProgressCard('variacoes'); ?>
    </div>
  </div>

  <!-- ── Aba Clientes ───────────────────────────── -->
  <div class="admin-tab-content" id="tab-clientes">
    <div class="admin-card import-upload-card">
      <div class="import-upload-area" id="upload-area-clientes">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.5" stroke-linecap="round">
          <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
        </svg>
        <p>Arraste o <strong>CSV de clientes</strong> da Tray aqui</p>
        <small>Separador ponto e vírgula · Encoding Latin-1</small>
        <label class="btn btn-outline" style="margin-top:12px;cursor:pointer;">
          Selecionar arquivo
          <input type="file" id="file-clientes" accept=".csv" style="display:none;">
        </label>
        <div id="file-clientes-nome" style="display:none;margin-top:10px;font-size:13px;color:var(--c-text-muted);"></div>
      </div>
    </div>

    <!-- Alerta informativo -->
    <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:12px;padding:14px 18px;margin-top:14px;font-size:13.5px;color:#78350f;">
      <strong>⚠ Sobre as senhas:</strong> Os clientes serão importados sem senha definida.
      Eles precisarão usar <strong>"Esqueci minha senha"</strong> para criar uma senha no primeiro acesso.
    </div>

    <!-- Preview -->
    <div id="preview-clientes" style="display:none;">
      <div class="admin-card" style="margin-top:16px;">
        <div style="padding:14px 20px;border-bottom:1px solid var(--c-border);display:flex;align-items:center;justify-content:space-between;">
          <h3 style="margin:0;font-size:14px;font-weight:800;">Preview — primeiras 5 linhas</h3>
          <span id="preview-cli-total" class="odh-count-badge"></span>
        </div>
        <div class="table-wrap">
          <table class="admin-table" id="preview-cli-table">
            <thead>
              <tr>
                <th>ID Tray</th><th>Nome</th><th>E-mail</th>
                <th>CPF</th><th>Cidade</th><th>Estado</th><th>Bloqueado</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
        <div style="padding:14px 20px;border-top:1px solid var(--c-border);">
          <button type="button" class="btn btn-primary" id="btn-importar-clientes">
            Importar clientes
          </button>
        </div>
      </div>
    </div>

    <!-- Progresso -->
    <div id="progresso-clientes" style="display:none;margin-top:16px;">
      <?php echo renderProgressCard('clientes'); ?>
    </div>
  </div>

  <!-- ── Aba Pedidos ───────────────────────────── -->
  <div class="admin-tab-content" id="tab-pedidos">
    <div class="admin-card import-upload-card">
      <p class="ap-form-hint" style="margin-bottom:12px;">
        Envie os dois arquivos exportados da Tray simultaneamente.
      </p>
      <div class="ped-upload-row">
        <div class="pwa-drop-zone" id="upload-area-ped-pedidos" style="flex:1;">
          <input type="file" id="file-ped-pedidos" accept=".csv">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/></svg>
          <p><strong>pedidos.csv</strong></p>
          <small id="ped-nome-pedidos">Nenhum arquivo</small>
        </div>
        <div class="pwa-drop-zone" id="upload-area-ped-produtos" style="flex:1;">
          <input type="file" id="file-ped-produtos" accept=".csv">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/></svg>
          <p><strong>produtos_vendidos.csv</strong></p>
          <small id="ped-nome-produtos">Nenhum arquivo</small>
        </div>
      </div>
      <div style="margin-top:12px;display:flex;gap:8px;align-items:center;">
        <button type="button" class="btn btn-primary btn-sm" id="btn-upload-pedidos" disabled>
          Iniciar importação
        </button>
        <span id="ped-upload-status" style="font-size:13px;color:var(--c-text-muted);"></span>
      </div>
    </div>

    <div id="preview-pedidos" style="display:none;">
      <div class="admin-card" style="margin-top:14px;">
        <div style="padding:14px 20px;border-bottom:1px solid var(--c-border);display:flex;align-items:center;justify-content:space-between;">
          <h3 style="margin:0;font-size:14px;font-weight:800;">Preview</h3>
          <span id="preview-ped-total" class="odh-count-badge"></span>
        </div>
        <div class="table-wrap">
          <table class="admin-table" id="preview-ped-table">
            <thead><tr><th>ID Tray</th><th>Data</th><th>Cliente</th><th>Status</th><th>Total</th><th>Itens</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div style="padding:14px 20px;border-top:1px solid var(--c-border);">
          <button type="button" class="btn btn-primary" id="btn-importar-pedidos">
            Importar pedidos
          </button>
        </div>
      </div>
    </div>

    <div id="progresso-pedidos" style="display:none;margin-top:16px;">
      <?php echo renderProgressCard('pedidos'); ?>
    </div>
  </div>

  <!-- ── Aba Imagens ─────────────────────────────── -->
  <div class="admin-tab-content" id="tab-imagens">
    <div class="admin-card" style="padding:24px;">
      <h3 style="margin:0 0 16px;font-size:14px;font-weight:800;">Fila de download de imagens</h3>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;" id="img-fila-stats">
        <div class="stat-card">
          <div class="stat-card-body">
            <span class="stat-card-value" id="img-pendentes"><?= (int)($fila['pendentes'] ?? 0) ?></span>
            <span class="stat-card-label">Pendentes</span>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-card-body">
            <span class="stat-card-value" id="img-concluidos"><?= (int)($fila['concluidos'] ?? 0) ?></span>
            <span class="stat-card-label">Baixadas</span>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-card-body">
            <span class="stat-card-value" id="img-erros"><?= (int)($fila['erros'] ?? 0) ?></span>
            <span class="stat-card-label">Erros</span>
          </div>
        </div>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" id="btn-baixar-imagens">
          ↓ Baixar próximas 30 imagens
        </button>
        <button type="button" class="btn btn-outline" id="btn-baixar-100">
          ↓ Baixar 100 de uma vez
        </button>
        <div id="img-msg" style="font-size:13px;color:var(--c-text-muted);"></div>
      </div>
      <p style="font-size:12.5px;color:var(--c-text-muted);margin-top:14px;">
        💡 Você também pode configurar um cron para automatizar o download:<br>
        <code style="background:#f8fafc;padding:4px 8px;border-radius:5px;font-size:12px;">
          * * * * * curl -s https://ecommerce.test/admin/importar/processar-imagens
        </code>
      </p>
    </div>
  </div>

  <!-- ── Aba Histórico ───────────────────────────── -->
  <div class="admin-tab-content" id="tab-historico">
    <div class="admin-card">
      <?php if (empty($jobs)): ?>
      <div class="empty-state" style="padding:40px;">Nenhum import realizado ainda.</div>
      <?php else: ?>
      <table class="admin-table">
        <thead><tr><th>Tipo</th><th>Status</th><th>Total</th><th>Criados</th><th>Atualizados</th><th>Ignorados</th><th>Erros</th><th>Iniciado em</th><th>Duração</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $j):
          $st = $statusLabels[$j['status']] ?? ['cor'=>'info','label'=>$j['status']];
          $erros = json_decode($j['erros_json'] ?? '[]', true) ?: [];
          $duracao = '';
          if ($j['concluido_em'] && $j['criado_em']) {
            $secs = strtotime($j['concluido_em']) - strtotime($j['criado_em']);
            $duracao = $secs < 60 ? "{$secs}s" : round($secs/60, 1).'min';
          }
        ?>
        <tr>
          <td><span class="badge badge-info"><?= View::e($tipoLabels[$j['tipo']] ?? ucfirst($j['tipo'])) ?></span></td>
          <td><span class="badge badge-<?= $st['cor'] ?>"><?= $st['label'] ?></span></td>
          <td><?= (int)$j['total_linhas'] ?></td>
          <td style="color:#16a34a;font-weight:700;"><?= (int)$j['criados'] ?></td>
          <td style="color:#2563eb;font-weight:700;"><?= (int)$j['atualizados'] ?></td>
          <td><?= (int)$j['ignorados'] ?></td>
          <td>
            <?php if (count($erros)): ?>
            <button type="button" class="btn-icon btn-show-erros"
                    data-erros="<?= View::e(json_encode($erros)) ?>"
                    style="color:#dc2626;">
              <?= count($erros) ?> erros
            </button>
            <?php else: ?>—
            <?php endif; ?>
          </td>
          <td><small><?= date('d/m/Y H:i', strtotime($j['criado_em'])) ?></small></td>
          <td><small><?= $duracao ?: '—' ?></small></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php function renderProgressCard(string $tipo): string { return "
<div class='admin-card' style='padding:20px;'>
  <div style='display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;'>
    <h3 style='margin:0;font-size:14px;font-weight:800;'>Importando {$tipo}…</h3>
    <span id='prog-{$tipo}-pct' style='font-size:22px;font-weight:900;color:#2563eb;'>0%</span>
  </div>
  <div style='height:10px;background:#f1f5f9;border-radius:99px;overflow:hidden;margin-bottom:14px;'>
    <div id='prog-{$tipo}-bar'
         style='height:100%;width:0%;background:linear-gradient(90deg,#2563eb,#06b6d4);border-radius:99px;transition:width .3s;'></div>
  </div>
  <div style='display:grid;grid-template-columns:repeat(4,1fr);gap:8px;font-size:12.5px;'>
    <div style='text-align:center;'>
      <div id='prog-{$tipo}-processadas' style='font-size:18px;font-weight:900;'>0</div>
      <div style='color:var(--c-text-muted);'>Processadas</div>
    </div>
    <div style='text-align:center;'>
      <div id='prog-{$tipo}-criados' style='font-size:18px;font-weight:900;color:#16a34a;'>0</div>
      <div style='color:var(--c-text-muted);'>Criados</div>
    </div>
    <div style='text-align:center;'>
      <div id='prog-{$tipo}-atualizados' style='font-size:18px;font-weight:900;color:#2563eb;'>0</div>
      <div style='color:var(--c-text-muted);'>Atualizados</div>
    </div>
    <div style='text-align:center;'>
      <div id='prog-{$tipo}-ignorados' style='font-size:18px;font-weight:900;color:#94a3b8;'>0</div>
      <div style='color:var(--c-text-muted);'>Ignorados</div>
    </div>
  </div>
  <div id='prog-{$tipo}-msg' style='margin-top:12px;font-size:13px;color:var(--c-text-muted);text-align:center;'></div>
</div>
"; } ?>

<style>
.import-instruction-card {
  display:flex;align-items:center;gap:12px;background:#fff;
  border:1px solid var(--c-border);border-radius:14px;padding:18px 22px;
  margin-bottom:20px;flex-wrap:wrap;
}
.import-step { display:flex;align-items:flex-start;gap:10px;flex:1;min-width:160px; }
.import-step-num {
  width:28px;height:28px;border-radius:50%;background:var(--c-accent);color:#fff;
  display:flex;align-items:center;justify-content:center;font-weight:900;
  font-size:13px;flex-shrink:0;
}
.import-step p { margin:2px 0 0;font-size:12px;color:var(--c-text-muted); }
.import-step-sep { color:#cbd5e1;font-size:20px;flex-shrink:0; }

.admin-tabs { display:flex;gap:2px;background:#f8fafc;border:1px solid var(--c-border);
  border-bottom:none;border-radius:12px 12px 0 0;padding:6px 6px 0; }
.admin-tab {
  padding:8px 18px;font-size:13.5px;font-weight:600;border-radius:8px 8px 0 0;
  background:transparent;border:none;color:var(--c-muted);cursor:pointer;transition:all .12s;
}
.admin-tab:hover { background:#fff;color:var(--c-dark); }
.admin-tab.is-active { background:#fff;color:var(--c-accent);box-shadow:0 -1px 0 0 var(--c-accent); }

.admin-tab-content { display:none; }
.admin-tab-content.is-active { display:block; }

.import-upload-card { padding:0 !important; }
.import-upload-area {
  border:2px dashed var(--c-border);border-radius:14px;
  padding:40px 20px;text-align:center;transition:border-color .15s;
  cursor:pointer;
}
.import-upload-area:hover, .import-upload-area.drag-over {
  border-color:var(--c-accent);background:var(--c-accent-l);
}
.import-upload-area p { font-size:15px;color:var(--c-dark);margin:10px 0 4px; }
.import-upload-area small { color:var(--c-text-muted);font-size:12.5px; }
</style>

<script>

</script>