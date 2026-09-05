<?php
/**
 * views/admin/fluxos/atividade.php
 *
 * Timeline do que as automações estão fazendo — o "tá funcionando?".
 *
 * @var array $fluxos  [{id, nome, status}]
 * @var array $kpis    {iniciadas_hoje, envios_hoje, erros_24h, ativas, dormindo, aguardando}
 */
$base = defined('BASE_URL') ? BASE_URL : '';
?>
<link rel="stylesheet" href="<?= $base ?>/css/fluxo-atividade.css">

<div class="em_wrapper">

  <div class="fxa_head">
    <div class="fxa_head_txt">
      <h1 class="fxa_h1">Atividade das automações</h1>
      <p class="fxa_sub">Cada passo que o motor dá, em ordem: jornadas iniciadas,
        emails e mensagens enviados, condições avaliadas, erros. Atualiza sozinho.</p>
    </div>
    <a href="<?= $base ?>/admin/fluxos" class="fxa_btn">
      <i class="bi bi-diagram-3"></i> Fluxos
    </a>
  </div>

  <!-- KPIs -->
  <div class="fxa_kpis">
    <div class="fxa_kpi">
      <div class="fxa_kpi_n" id="fxa-k-iniciadas"><?= (int)($kpis['iniciadas_hoje'] ?? 0) ?></div>
      <div class="fxa_kpi_l">Jornadas hoje</div>
    </div>
    <div class="fxa_kpi">
      <div class="fxa_kpi_n" id="fxa-k-envios"><?= (int)($kpis['envios_hoje'] ?? 0) ?></div>
      <div class="fxa_kpi_l">Envios hoje</div>
    </div>
    <div class="fxa_kpi <?= ((int)($kpis['erros_24h'] ?? 0)) > 0 ? 'fxa_kpi_alerta' : '' ?>">
      <div class="fxa_kpi_n" id="fxa-k-erros"><?= (int)($kpis['erros_24h'] ?? 0) ?></div>
      <div class="fxa_kpi_l">Erros · 24h</div>
    </div>
    <div class="fxa_kpi">
      <div class="fxa_kpi_n" id="fxa-k-vivas"><?=
        (int)($kpis['ativas'] ?? 0) + (int)($kpis['dormindo'] ?? 0) + (int)($kpis['aguardando'] ?? 0) ?></div>
      <div class="fxa_kpi_l">Jornadas em curso</div>
      <div class="fxa_kpi_nota" id="fxa-k-vivas-nota"><?=
        (int)($kpis['dormindo'] ?? 0) ?> dormindo · <?= (int)($kpis['aguardando'] ?? 0) ?> aguardando evento</div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="fxa_filtros">
    <select class="fxa_input" id="fxa-f-fluxo">
      <option value="">Todos os fluxos</option>
      <?php foreach (($fluxos ?? []) as $f): ?>
        <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['nome']) ?></option>
      <?php endforeach; ?>
    </select>
    <input class="fxa_input" id="fxa-f-cliente" type="number" min="1"
           placeholder="ID do cliente" style="max-width:150px">
    <label class="fxa_check">
      <input type="checkbox" id="fxa-f-erros"> <span>Só erros</span>
    </label>
    <span class="fxa_ao_vivo" id="fxa-ao-vivo" title="Atualizando a cada 15s">
      <span class="fxa_pulso"></span> ao vivo
    </span>
  </div>

  <!-- Timeline -->
  <div class="fxa_card">
    <div id="fxa-lista">
      <div class="fxa_load">Carregando atividade…</div>
    </div>
    <div class="fxa_mais_wrap">
      <button type="button" class="fxa_btn" id="fxa-mais" style="display:none">
        Carregar mais
      </button>
    </div>
  </div>

</div>

<script>
  window.BASE_URL   = window.BASE_URL   || '<?= addslashes($base) ?>';
  window.CSRF_TOKEN = window.CSRF_TOKEN || (document.querySelector('meta[name=csrf-token]') || {}).content || '';
</script>
<script src="<?= $base ?>/js/fluxo-atividade.js"></script>
