<?php
// views/admin/clientes/score-credito.php
$tierCores = [
    'bronze'   => ['bg'=>'var(--warning-lt)','text'=>'var(--warning)','border'=>'var(--warning-bd)'],
    'silver'   => ['bg'=>'var(--surface2)','text'=>'var(--text-2)','border'=>'var(--border2)'],
    'gold'     => ['bg'=>'var(--warning-lt)','text'=>'var(--warning)','border'=>'var(--warning-bd)'],
    'platinum' => ['bg'=>'var(--blue-lt)','text'=>'var(--blue)','border'=>'var(--blue-bd)'],
];
$tc  = $tierCores[$scoreRow['tier'] ?? 'bronze'];
$max = 600;

?>
<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/clientes/<?= (int)$cliente['id'] ?>" class="back-link">
        ← <?= View::e($cliente['nome']) ?>
      </a>
      <h1 class="admin-page-title">Score & Crédito</h1>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

    <!-- SCORE ──────────────────────────────────────────── -->
    <div class="admin-card">
      <div style="padding:20px;border-bottom:1px solid var(--c-border);display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;font-size:15px;font-weight:800;">Score do cliente</h3>
        <?php if ($scoreRow['override_manual']): ?>
          <span style="font-size:11px;background:var(--danger-lt);color:var(--danger);padding:3px 10px;border-radius:99px;border:1px solid var(--danger-bd);font-weight:700;">
            Override manual ativo
          </span>
        <?php endif; ?>
      </div>
      <div style="padding:20px;">

        <!-- Score + Tier -->
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
          <div style="text-align:center;min-width:80px;">
            <div style="font-size:44px;font-weight:900;color:var(--c-dark);line-height:1;" id="sc-valor">
              <?= (int)($scoreRow['score_total'] ?? 0) ?>
            </div>
            <div style="font-size:11px;color:var(--c-text-muted);font-weight:600;">/ <?= $max ?></div>
          </div>
          <div style="flex:1;">
            <span id="sc-tier-badge"
                  style="display:inline-block;padding:5px 14px;border-radius:99px;font-size:13px;font-weight:800;
                         background:<?= $tc['bg'] ?>;color:<?= $tc['text'] ?>;border:1px solid <?= $tc['border'] ?>;">
              <?= ScoreService::TIERS[$scoreRow['tier'] ?? 'bronze']['label'] ?>
            </span>
            <div style="margin-top:10px;">
              <div style="height:8px;background:var(--surface2);border-radius:99px;overflow:hidden;">
                <div id="sc-barra"
                     style="height:100%;width:<?= min(100,round(($scoreRow['score_total']??0)/$max*100)) ?>%;
                            background:linear-gradient(90deg,#16a34a,#22c55e);border-radius:99px;transition:width .5s ease;"></div>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--c-text-muted);margin-top:3px;">
                <span>0</span><span>100</span><span>250</span><span>450</span><span>600</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Fatores -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;">
          <?php
            $fatores = [
              ['label'=>'LTV Total',         'val'=> PriceHelper::format((float)($scoreRow['ltv_total']??0)),         'pts'=>min((float)($scoreRow['ltv_total']??0)/50,300)],
              ['label'=>'Pedidos concluídos','val'=> (int)($scoreRow['total_pedidos_concluidos']??0).' pedidos',     'pts'=>min((int)($scoreRow['total_pedidos_concluidos']??0)*8,150)],
              ['label'=>'Idade da conta',    'val'=> (int)($scoreRow['dias_conta']??0).' dias',                      'pts'=>min((int)($scoreRow['dias_conta']??0)/20,100)],
              ['label'=>'Pgto. aprovados',   'val'=> round((float)($scoreRow['taxa_aprovacao_pag']??1)*100).'%',     'pts'=>(float)($scoreRow['taxa_aprovacao_pag']??1)*50],
            ];
            $penalidades = [
              ['label'=>'Taxa devolução',    'val'=> round((float)($scoreRow['taxa_devolucao']??0)*100,1).'%',  'pts'=>-min((float)($scoreRow['taxa_devolucao']??0)*200,150)],
              ['label'=>'Insp. reprovadas',  'val'=> (int)($scoreRow['total_reprovadas']??0),                    'pts'=>-(int)($scoreRow['total_reprovadas']??0)*50],
              ['label'=>'Chargebacks',       'val'=> (int)($scoreRow['total_chargebacks']??0),                   'pts'=>-(int)($scoreRow['total_chargebacks']??0)*150],
            ];
            foreach (array_merge($fatores,$penalidades) as $f):
              $pos = $f['pts'] >= 0;
          ?>
          <div style="background:var(--bg);border-radius:8px;padding:9px 12px;border:1px solid var(--c-border);">
            <div style="font-size:11px;color:var(--c-text-muted);font-weight:600;"><?= $f['label'] ?></div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:2px;">
              <span style="font-size:13px;font-weight:700;color:var(--c-dark);"><?= $f['val'] ?></span>
              <span style="font-size:11.5px;font-weight:800;color:<?= $pos?'var(--success)':'var(--danger)' ?>;">
                <?= $pos?'+':'' ?><?= round($f['pts'],1) ?> pts
              </span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Override manual -->
        <?php if ($scoreRow['override_manual']): ?>
        <div style="background:var(--danger-lt);border:1px solid var(--danger-bd);border-radius:8px;padding:10px 12px;margin-bottom:12px;">
          <div style="font-size:12.5px;color:var(--danger);font-weight:700;">Override ativo por: <?= View::e($scoreRow['override_admin_id'] ?? 'admin') ?></div>
          <div style="font-size:12px;color:var(--danger);margin-top:2px;"><?= View::e($scoreRow['override_motivo'] ?? '') ?></div>
          <button type="button" class="btn btn-outline btn-sm" id="btn-remover-override"
                  style="margin-top:8px;">Remover override</button>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button type="button" class="btn btn-outline btn-sm" id="btn-recalcular-score">
            ↺ Recalcular
          </button>
          <button type="button" class="btn btn-outline btn-sm" id="btn-override-score">
            ✎ Ajuste manual
          </button>
        </div>
      </div>
    </div>

    <!-- CRÉDITO ─────────────────────────────────────────── -->
    <div class="admin-card">
      <div style="padding:20px;border-bottom:1px solid var(--c-border);display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;font-size:15px;font-weight:800;">Saldo de crédito</h3>
        <span style="font-size:26px;font-weight:900;color:var(--success);" id="saldo-display">
          <?= PriceHelper::format($saldo) ?>
        </span>
      </div>
      <div style="padding:16px 20px;">
        <?php if (!empty($expirando)): ?>
        <div style="background:var(--warning-lt);border:1px solid var(--warning-bd);border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12.5px;color:var(--warning);">
          ⚠ <?= count($expirando) ?> crédito(s) expiram nos próximos 60 dias.
        </div>
        <?php endif; ?>

        <!-- Lançar crédito -->
        <div style="background:var(--success-lt);border:1px solid var(--success-bd);border-radius:10px;padding:14px;margin-bottom:14px;">
          <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--success);margin-bottom:10px;">
            Lançar crédito
          </div>
          <div style="display:grid;grid-template-columns:1fr 80px;gap:8px;margin-bottom:8px;">
            <input type="text" id="cr-valor" class="form-control" placeholder="Valor R$">
            <input type="number" id="cr-dias" class="form-control" placeholder="Dias"
                   title="Validade em dias (vazio = não expira)">
          </div>
          <input type="text" id="cr-desc" class="form-control" placeholder="Descrição" style="margin-bottom:8px;">
          <button type="button" class="btn btn-primary btn-sm" id="btn-lancar-credito">
            + Lançar crédito
          </button>
        </div>

        <!-- Histórico -->
        <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--c-text-muted);margin-bottom:8px;">
          Últimas transações
        </div>
        <div style="max-height:280px;overflow-y:auto;">
          <?php foreach ($historico as $tx):
            $isCredito = str_starts_with($tx['tipo'], 'credito');
            $cor       = $isCredito ? 'var(--success)' : 'var(--danger)';
          ?>
          <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--bg);gap:8px;">
            <div style="flex:1;min-width:0;">
              <div style="font-size:13px;font-weight:600;color:var(--c-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= View::e($tx['descricao']) ?>
              </div>
              <div style="font-size:11.5px;color:var(--c-text-muted);">
                <?= date('d/m/Y H:i', strtotime($tx['criado_em'])) ?>
                <?php if ($tx['expira_em'] && !$tx['expirado']): ?>
                  · <span style="color:var(--warning);">Expira <?= date('d/m/Y', strtotime($tx['expira_em'])) ?></span>
                <?php elseif ($tx['expirado']): ?>
                  · <span style="color:var(--text-3);">Expirado</span>
                <?php endif; ?>
              </div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
              <div style="font-size:14px;font-weight:800;color:<?= $cor ?>;">
                <?= $isCredito?'+':'-' ?><?= PriceHelper::format((float)$tx['valor']) ?>
              </div>
              <div style="font-size:11px;color:var(--c-text-muted);">
                Saldo: <?= PriceHelper::format((float)$tx['saldo_apos']) ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($historico)): ?>
            <div style="text-align:center;padding:20px;color:var(--c-text-muted);font-size:13.5px;">
              Nenhuma transação ainda.
            </div>
          <?php endif; ?>
        </div>

        <button type="button" class="btn btn-ghost btn-sm"
                id="btn-debitar" style="margin-top:10px;color:var(--danger);">
          − Débito manual
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal override score -->
<div class="od-modal-overlay" id="modal-override" hidden>
  <div class="od-modal-box">
    <div class="od-modal-header">
      <h4>Ajuste manual de score</h4>
      <button type="button" class="od-modal-close" onclick="fecharModal('modal-override')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="od-modal-body">
      <div style="margin-bottom:12px;">
        <label class="form-label-xs">Novo score (0–600)</label>
        <input type="number" id="ov-score" class="form-control" min="0" max="999"
               value="<?= (int)($scoreRow['score_total']??0) ?>">
      </div>
      <div style="margin-bottom:16px;">
        <label class="form-label-xs">Motivo (obrigatório)</label>
        <textarea id="ov-motivo" class="form-control" rows="3"
                  placeholder="Ex: Cliente com histórico VIP importado de sistema anterior"></textarea>
      </div>
      <div style="background:var(--warning-lt);border:1px solid var(--warning-bd);border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12.5px;color:var(--warning);">
        ⚠ O override congela o score automático. O cron não irá sobrescrevê-lo até você remover o override.
      </div>
      <button type="button" class="btn btn-primary" id="btn-confirmar-override">Aplicar override</button>
    </div>
  </div>
</div>

<!-- Modal débito manual -->
<div class="od-modal-overlay" id="modal-debito" hidden>
  <div class="od-modal-box">
    <div class="od-modal-header">
      <h4>Débito manual de crédito</h4>
      <button type="button" class="od-modal-close" onclick="fecharModal('modal-debito')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="od-modal-body">
      <div style="margin-bottom:12px;">
        <label class="form-label-xs">Valor a debitar</label>
        <input type="text" id="db-valor" class="form-control" placeholder="0,00">
      </div>
      <div style="margin-bottom:16px;">
        <label class="form-label-xs">Motivo</label>
        <input type="text" id="db-desc" class="form-control" placeholder="Ex: Crédito lançado indevidamente">
      </div>
      <button type="button" class="btn btn-primary" id="btn-confirmar-debito"
              style="background:var(--danger);border-color:var(--danger);">
        Confirmar débito
      </button>
      <div id="debito-msg" class="form-alert" style="display:none;margin-top:10px;"></div>
    </div>
  </div>
</div>

<script>
var BASE_SCORE_CLIENTE = '<?= BASE_URL ?>/admin/clientes/<?= (int)$cliente['usuario_id'] ?>';
</script>