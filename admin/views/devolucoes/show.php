<?php
// views/admin/devolucoes/show.php
$statusLabels = [
    'solicitado'             => ['cor'=>'warning', 'label'=>'Solicitado'],
    'pre_aprovado'           => ['cor'=>'info',    'label'=>'Pré-aprovado'],
    'aguardando_aprovacao'   => ['cor'=>'warning', 'label'=>'Aguardando aprovação'],
    'aprovado'               => ['cor'=>'info',    'label'=>'Aprovado'],
    'negado'                 => ['cor'=>'danger',  'label'=>'Negado'],
    'aguardando_postagem'    => ['cor'=>'warning', 'label'=>'Aguardando postagem'],
    'em_transito_reverso'    => ['cor'=>'primary', 'label'=>'Em trânsito reverso'],
    'item_recebido'          => ['cor'=>'info',    'label'=>'Item recebido'],
    'inspecionado_aprovado'  => ['cor'=>'success', 'label'=>'Inspeção aprovada'],
    'inspecionado_reprovado' => ['cor'=>'danger',  'label'=>'Inspeção reprovada'],
    'concluido'              => ['cor'=>'success', 'label'=>'Concluído'],
    'concluido_reprovado'    => ['cor'=>'danger',  'label'=>'Concluído (reprovado)'],
    'cancelado'              => ['cor'=>'danger',  'label'=>'Cancelado'],
    'expirado'               => ['cor'=>'gray',    'label'=>'Expirado'],
];
$st      = $statusLabels[$sol['status']] ?? ['cor'=>'info','label'=>$sol['status']];
$status  = $sol['status'];
$podeAprovar        = in_array($status, ['aguardando_aprovacao','solicitado','pre_aprovado']);
$podeGerarPostagem  = in_array($status, ['aprovado','aguardando_postagem'])
                      && empty($sol['codigo_postagem_reversa']);
$podeNegar     = $podeAprovar;
$podeReceber   = $status === 'em_transito_reverso';
$podeInspecionar = $status === 'item_recebido';
$podeReembolsar  = $status === 'inspecionado_aprovado';
?>
<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/devolucoes" class="back-link">← Devoluções</a>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px;">
        <h1 class="admin-page-title" style="margin:0;">
          Solicitação #<?= (int)$sol['id'] ?>
          <span style="font-size:14px;font-weight:500;color:var(--c-text-muted);">
            — <?= ucfirst($sol['tipo']) ?>
          </span>
        </h1>
        <span class="badge badge-<?= $st['cor'] ?> badge-lg"><?= $st['label'] ?></span>
      </div>
      <p class="admin-page-sub">
        <?= View::e($sol['cliente_nome']) ?> · <?= View::e($sol['cliente_email']) ?>
        · Pedido <a href="<?= ADMIN_URL ?>/pedidos/<?= (int)$sol['pedido_id'] ?>" class="link-subtle">
          #<?= View::e($sol['pedido_codigo'] ?? '') ?></a>
        · <?= date('d/m/Y H:i', strtotime($sol['criado_em'])) ?>
      </p>
    </div>
  </div>
 
  <div class="ap-grid">
    <div class="ap-main">
 
      <!-- Itens -->
      <div class="admin-card">
        <h3 class="ap-card-title">Itens solicitados</h3>
        <?php foreach ($itens as $item):
          $img = ImageHelper::getCartItemImage($item['produto_id']);
        ?>
        <div class="ap-item">
          <img src="<?= View::e($img) ?>" class="ap-item-img">
          <div class="ap-item-info">
            <div class="ap-item-name"><?= View::e($item['nome_produto']) ?></div>
            <?php if (!empty($item['sku'])): ?>
              <span class="ap-item-sku">SKU: <?= View::e($item['sku']) ?></span>
            <?php endif; ?>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <span style="font-size:13px;color:var(--c-text-muted);"><?= (int)$item['quantidade'] ?>× </span>
            <strong><?= PriceHelper::format((float)$item['valor_final']) ?></strong>
          </div>
        </div>
        <?php endforeach; ?>
        <div style="padding:12px 20px;border-top:1px solid var(--c-border);display:flex;justify-content:space-between;">
          <strong>Total solicitado</strong>
          <strong><?= PriceHelper::format((float)$sol['valor_solicitado']) ?></strong>
        </div>
      </div>
 
      <!-- Descrição + fotos -->
      <?php if (!empty($sol['descricao']) || !empty($sol['fotos_json'])): ?>
      <div class="admin-card" style="margin-top:16px;">
        <h3 class="ap-card-title">Detalhes informados pelo cliente</h3>
        <div style="padding:14px 20px;">
          <?php if (!empty($sol['descricao'])): ?>
            <p style="font-size:14px;color:var(--c-text);line-height:1.6;margin:0 0 12px;">
              <?= View::e($sol['descricao']) ?>
            </p>
          <?php endif; ?>
          <?php if (!empty($sol['fotos_json'])):
            $fotos = json_decode($sol['fotos_json'], true) ?: [];
          ?>
          <div class="form-label-xs" style="margin-bottom:8px;">Fotos (<?= count($fotos) ?>)</div>
          <div class="dev-fotos-grid">
            <?php foreach ($fotos as $foto): ?>
            <a href="<?= BASE_URL ?>/uploads/devolucoes/<?= View::e($foto) ?>"
               target="_blank" class="dev-foto-thumb">
              <img src="<?= BASE_URL ?>/uploads/devolucoes/<?= View::e($foto) ?>"
                   alt="Foto do produto">
            </a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
 
      <!-- Histórico -->
      <div class="admin-card" style="margin-top:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--c-border);">
          <h3 style="margin:0;font-size:14px;font-weight:800;">Histórico</h3>
          <span class="odh-count-badge"><?= count($historico) ?> eventos</span>
        </div>
        <div style="padding:8px 20px 12px;">
          <?php foreach ($historico as $h):
            $hSt = $statusLabels[$h['status_novo']] ?? ['cor'=>'info','label'=>$h['status_novo']];
          ?>
          <div class="ap-hist-item">
            <div class="ap-hist-dot ap-hist-dot--<?= $hSt['cor'] ?>"></div>
            <div class="ap-hist-body">
              <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">
                <span class="badge badge-<?= $hSt['cor'] ?>"><?= $hSt['label'] ?></span>
                <?php if (!empty($h['admin_nome'])): ?>
                  <small class="txt-muted">por <?= View::e($h['admin_nome']) ?></small>
                <?php endif; ?>
              </div>
              <?php if (!empty($h['observacao'])): ?>
                <p style="font-size:13px;color:var(--c-text-muted);margin:2px 0 0;"><?= View::e($h['observacao']) ?></p>
              <?php endif; ?>
              <time style="font-size:11.5px;color:var(--text-3);"><?= date('d/m/Y H:i', strtotime($h['criado_em'])) ?></time>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
 
    </div><!-- /.ap-main -->
 
    <div class="ap-aside">
 
      <!-- Motivo -->
      <div class="admin-card ap-action-card">
        <h3 class="ap-card-title">Motivo</h3>
        <div style="padding:14px 18px;">
          <strong><?= View::e($sol['motivo_label']) ?></strong>
          <?php if ($sol['responsavel_frete'] === 'loja'): ?>
            <div style="margin-top:6px;"><span class="badge badge-info">Frete pago pela loja</span></div>
          <?php else: ?>
            <div style="margin-top:6px;"><span class="badge badge-warning">Frete pago pelo cliente</span></div>
          <?php endif; ?>
        </div>
      </div>
 
      <!-- Logística reversa -->
      <?php if (!empty($sol['codigo_postagem_reversa']) || $podeGerarPostagem): ?>
      <div class="admin-card ap-action-card" style="margin-top:14px;">
        <h3 class="ap-card-title">Logística reversa</h3>
 
        <?php if ($podeGerarPostagem): ?>
        <!-- ── Sem código: oferece geração manual ────────── -->
        <div style="padding:16px 18px;">
          <div style="display:flex;align-items:flex-start;gap:10px;background:var(--warning-lt);border:1px solid var(--warning-bd);border-radius:10px;padding:12px 14px;margin-bottom:14px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#d97706"
                 stroke-width="2" stroke-linecap="round" style="flex-shrink:0;margin-top:1px;">
              <circle cx="12" cy="12" r="10"/>
              <line x1="12" y1="8" x2="12" y2="12"/>
              <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <div>
              <strong style="font-size:13px;color:var(--warning);display:block;margin-bottom:2px;">
                Código de postagem não gerado
              </strong>
              <span style="font-size:12.5px;color:var(--warning);line-height:1.5;">
                A geração automática falhou ao aprovar a solicitação.
                <?php if ($status === 'aguardando_postagem'): ?>
                  O status já foi avançado, mas o código ainda não foi gerado.
                <?php endif; ?>
              </span>
            </div>
          </div>
          <button type="button" class="btn btn-primary" id="btn-gerar-postagem">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" style="margin-right:5px;">
              <polyline points="23 4 23 10 17 10"/>
              <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>
            </svg>
            Gerar código PAC reverso agora
          </button>
          <div id="postagem-result" style="display:none;margin-top:10px;"></div>
        </div>
 
        <?php else: ?>
        <!-- ── Código gerado: exibe normalmente ─────────── -->
        <div style="padding:14px 18px;display:flex;flex-direction:column;gap:10px;">
          <?php
            // Codigos 'FAKE######' vieram do stub LogisticaReversa, que apontava
            // para uma URL de exemplo nunca configurada e caia em modo falso.
            // Nao existem nos Correios: o cliente nao consegue postar com eles.
            $codigoFalso = (bool) preg_match('/^FAKE\d+$/i', (string)($sol['codigo_postagem_reversa'] ?? ''));
          ?>
          <?php if ($codigoFalso): ?>
          <div style="background:var(--danger-lt);border:1px solid var(--danger-bd);border-radius:10px;padding:12px 14px;">
            <strong style="color:var(--danger)">Código inválido</strong>
            <div style="font-size:12.5px;color:var(--text-2);margin-top:3px;">
              Gerado pelo integrador antigo em modo de teste — não existe nos Correios,
              e o cliente não consegue postar com ele. Gere um código novo e avise o cliente.
            </div>
          </div>
          <?php endif; ?>
          <div class="dev-codigo-box">
            <div class="dev-codigo-label">Código de postagem</div>
            <code class="dev-codigo-value"<?= $codigoFalso ? ' style="text-decoration:line-through;opacity:.55"' : '' ?>><?= View::e($sol['codigo_postagem_reversa']) ?></code>
            <?php if (!empty($sol['codigo_validade_dias'])): ?>
              <div class="dev-codigo-validade">
                Válido por <?= (int)$sol['codigo_validade_dias'] ?> dias
              </div>
            <?php endif; ?>
          </div>
          <?php if (!empty($sol['codigo_rastreio_reverso'])): ?>
          <div style="display:flex;align-items:center;gap:8px;font-size:13.5px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            Rastreio: <code style="font-weight:700;"><?= View::e($sol['codigo_rastreio_reverso']) ?></code>
          </div>
          <?php elseif ($sol['status'] === 'aguardando_postagem'): ?>
          <div style="font-size:12.5px;color:var(--c-text-muted);">
            Aguardando o cliente informar o rastreio após a postagem.
          </div>
          <?php endif; ?>
          <?php if (!empty($sol['item_postado_em'])): ?>
          <div style="font-size:12px;color:var(--c-text-muted);">
            Postado em: <?= date('d/m/Y H:i', strtotime($sol['item_postado_em'])) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
 
      </div>
      <?php endif; ?>
 
      <!-- Ações -->
      <div class="admin-card ap-action-card" style="margin-top:14px;">
        <h3 class="ap-card-title">Ações</h3>
        <div style="padding:14px 18px;display:flex;flex-direction:column;gap:10px;">
 
          <?php if ($podeAprovar): ?>
          <div>
            <label class="form-label-xs">Observação (opcional)</label>
            <textarea id="obs-aprovacao" class="form-control" rows="2" style="resize:vertical;"></textarea>
          </div>
          <button type="button" class="btn btn-primary" id="btn-aprovar">
            ✓ Aprovar e gerar código de postagem
          </button>
          <?php endif; ?>
 
          <?php if ($podeNegar): ?>
          <div>
            <label class="form-label-xs">Motivo da negação (obrigatório)</label>
            <textarea id="obs-negar" class="form-control" rows="2" style="resize:vertical;"></textarea>
          </div>
          <button type="button" class="btn btn-outline" id="btn-negar" style="color:var(--danger);border-color:var(--danger-bd);">
            ✗ Negar solicitação
          </button>
          <?php endif; ?>
 
          <?php if ($podeReceber): ?>
          <button type="button" class="btn btn-outline" id="btn-receber">
            📦 Confirmar recebimento do item
          </button>
          <?php endif; ?>
 
          <?php if ($podeInspecionar): ?>
          <?php
            $prazoStr = !empty($sol['inspecao_prazo_ate'])
                ? date('d/m/Y \à\s H:i', strtotime($sol['inspecao_prazo_ate']))
                : null;
          ?>
          <?php if ($prazoStr): ?>
          <div class="dev-prazo-badge" style="margin-bottom:4px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Prazo: <?= $prazoStr ?>
          </div>
          <?php endif; ?>
          <div>
            <label class="form-label-xs" style="margin-bottom:6px;">Resultado da inspeção</label>
            <div class="insp-toggle">
              <label class="insp-opt insp-opt--aprovado" id="insp-label-aprovado">
                <input type="radio" name="insp_resultado" value="aprovado" id="insp-aprovado"
                       onchange="document.getElementById('insp-label-aprovado').classList.add('is-selected');document.getElementById('insp-label-reprovado').classList.remove('is-selected');">
                ✓ Aprovado
              </label>
              <label class="insp-opt insp-opt--reprovado" id="insp-label-reprovado">
                <input type="radio" name="insp_resultado" value="reprovado" id="insp-reprovado"
                       onchange="document.getElementById('insp-label-reprovado').classList.add('is-selected');document.getElementById('insp-label-aprovado').classList.remove('is-selected');">
                ✗ Reprovado
              </label>
            </div>
          </div>
          <div>
            <label class="form-label-xs">Valor a reembolsar (R$)</label>
            <input type="text" id="val-aprovado" class="form-control"
                   value="<?= number_format((float)$sol['valor_solicitado'],2,',','.') ?>"
                   placeholder="Pode ser menor que o solicitado">
          </div>
          <div>
            <label class="form-label-xs">Observação (aparece no e-mail do cliente)</label>
            <textarea id="obs-inspecao" class="form-control" rows="2" style="resize:vertical;"
                      placeholder="Ex: Produto aprovado conforme política de trocas."></textarea>
          </div>
          <button type="button" class="btn btn-primary" id="btn-inspecionar">
            Registrar resultado
          </button>
          <?php endif; ?>
 
          <?php if ($podeReembolsar): ?>
          <div style="background:var(--success-lt);border:1.5px solid var(--success-bd);border-radius:12px;padding:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
              <span style="font-size:12px;font-weight:800;text-transform:uppercase;color:var(--success);">
                Processar reembolso
              </span>
              <strong style="font-size:16px;color:var(--text);">
                <?= PriceHelper::format((float)$sol['valor_aprovado']) ?>
              </strong>
            </div>
            <div class="dev-reembolso-opts">
              <button type="button" class="dev-reembolso-opt is-sel" data-tipo="credito"
                      onclick="selecionarReembolso(this)">
                💳 Crédito na conta
                <small>Saldo disponível para próximas compras</small>
              </button>
              <button type="button" class="dev-reembolso-opt" data-tipo="pix"
                      onclick="selecionarReembolso(this)">
                ⚡ Pix automático
                <small>Transferência imediata via Pix</small>
              </button>
              <button type="button" class="dev-reembolso-opt" data-tipo="gateway"
                      onclick="selecionarReembolso(this)">
                💳 Estorno no cartão
                <small>Via gateway de pagamento</small>
              </button>
              <button type="button" class="dev-reembolso-opt" data-tipo="boleto_manual"
                      onclick="selecionarReembolso(this)">
                🏦 Transferência manual
                <small>TED/PIX manual pelo banco</small>
              </button>
            </div>
            <input type="hidden" id="tipo-reembolso-sel" value="credito">
            <button type="button" class="btn btn-primary btn-full" id="btn-reembolsar">
              Processar reembolso
            </button>
          </div>
          <?php endif; ?>
 
          <div id="acao-msg" class="form-alert" style="display:none;"></div>
        </div>
      </div>
 
    </div>
  </div>
</div>

<script>

var SOL_DEV_ID = <?= (int)$sol['id'] ?>;
function selecionarReembolso(el) {
  document.querySelectorAll('.dev-reembolso-opt').forEach(function (b) { b.classList.remove('is-sel'); });
  el.classList.add('is-sel');
  document.getElementById('tipo-reembolso-sel').value = el.dataset.tipo;
}

</script>