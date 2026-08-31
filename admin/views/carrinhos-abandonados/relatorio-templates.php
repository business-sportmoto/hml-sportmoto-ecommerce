<?php
// views/admin/carrinhos-abandonados/relatorio-templates.php
// Variáveis: $linhas (relatorioConversaoTemplates), $de, $ate

$canalCfg = [
  'whatsapp' => ['💬 WhatsApp', 'var(--success)', 'var(--success-lt)'],
  'email'    => ['✉ E-mail',   'var(--blue)', 'var(--blue-lt)'],
];
?>
<div class="ap-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div style="display:flex;align-items:center;gap:14px;">
    <a href="<?= ADMIN_URL ?>/carrinhos-abandonados" class="btn">← Central</a>
    <div>
      <h1 style="font-size:20px;font-weight:800;margin:0;">Conversão por template</h1>
      <p style="color:var(--c-text-muted);margin:4px 0 0;font-size:13px;">
        Qual mensagem realmente recupera carrinhos
      </p>
    </div>
  </div>
  <form method="get" style="display:flex;gap:8px;align-items:center;">
    <input type="date" name="de"  value="<?= View::e($de)  ?>" class="form-control" style="width:auto;">
    <input type="date" name="ate" value="<?= View::e($ate) ?>" class="form-control" style="width:auto;">
    <button class="btn btn-primary">Aplicar</button>
  </form>
</div>

<div style="background:var(--blue-lt);border:1px solid var(--blue-bd);border-radius:10px;
     padding:11px 16px;margin:14px 0;font-size:12.5px;color:var(--blue);">
  📐 <strong>Metodologia:</strong> conversões usam atribuição <em>last-touch</em> —
  cada recuperação pertence ao <em>último</em> template enviado antes da compra.
  "Envios" e "Carrinhos" são contagem bruta. Período aplicado sobre a data do envio.
</div>

<?php if (empty($linhas)): ?>
<div class="admin-card" style="padding:48px;text-align:center;color:var(--c-text-muted);">
  <div style="font-size:40px;margin-bottom:8px;">📈</div>
  <strong style="color:var(--c-dark);">Nenhum envio no período</strong>
  <p style="margin:6px 0 0;font-size:13.5px;">
    Os números aparecem aqui conforme a equipe usa os templates na central.</p>
</div>
<?php else: ?>

<div class="admin-card">
  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead>
      <tr style="text-align:left;color:var(--c-text-muted);font-size:11.5px;
                 text-transform:uppercase;letter-spacing:.4px;">
        <th style="padding:12px 18px;">Template</th>
        <th style="padding:12px;text-align:center;">Envios</th>
        <th style="padding:12px;text-align:center;">Carrinhos</th>
        <th style="padding:12px;text-align:center;">Conversões</th>
        <th style="padding:12px;width:180px;">Taxa</th>
        <th style="padding:12px 18px;text-align:right;">Valor recuperado</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($linhas as $l):
        [$cLabel, $cCor, $cBg] = $canalCfg[$l['canal']] ?? ['?', 'var(--text-2)', 'var(--bg)'];
        $taxa = (int)$l['carrinhos'] > 0
            ? round((int)$l['conversoes'] / (int)$l['carrinhos'] * 100, 1)
            : 0.0;
        $corTaxa = $taxa >= 20 ? 'var(--success)' : ($taxa >= 8 ? 'var(--warning)' : 'var(--text-3)');
      ?>
      <tr style="border-top:1px solid var(--c-border);">
        <td style="padding:12px 18px;">
          <div style="font-weight:700;"><?= View::e($l['nome']) ?></div>
          <span class="badge" style="background:<?= $cBg ?>;color:<?= $cCor ?>;
                font-size:10px;font-weight:800;"><?= $cLabel ?></span>
        </td>
        <td style="padding:12px;text-align:center;"><?= (int)$l['envios'] ?></td>
        <td style="padding:12px;text-align:center;"><?= (int)$l['carrinhos'] ?></td>
        <td style="padding:12px;text-align:center;font-weight:800;
                   color:<?= (int)$l['conversoes'] > 0 ? 'var(--success)' : 'var(--c-text-muted)' ?>;">
          <?= (int)$l['conversoes'] ?></td>
        <td style="padding:12px;">
          <div style="display:flex;align-items:center;gap:8px;">
            <div style="flex:1;height:6px;background:rgba(0,0,0,.07);
                        border-radius:99px;overflow:hidden;">
              <div style="width:<?= min(100, $taxa) ?>%;height:100%;
                          background:<?= $corTaxa ?>;border-radius:99px;"></div>
            </div>
            <span style="font-size:11.5px;font-weight:700;color:<?= $corTaxa ?>;
                         min-width:42px;text-align:right;">
              <?= number_format($taxa, 1, ',', '') ?>%</span>
          </div>
        </td>
        <td style="padding:12px 18px;text-align:right;font-weight:800;
                   color:<?= (float)$l['valor_recuperado'] > 0 ? 'var(--success)' : 'var(--c-text-muted)' ?>;">
          R$ <?= number_format((float)$l['valor_recuperado'], 2, ',', '.') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<p style="font-size:12px;color:var(--c-text-muted);margin-top:10px;">
  💡 Taxa = conversões ÷ carrinhos alcançados. Templates com poucos envios
  (&lt;20) ainda não têm significância estatística — não desative cedo demais.
</p>
<?php endif; ?>