<?php
/**
 * views/admin/vida-util/index.php
 *
 * Regras de dica de cuidado. O shell vem do servidor; a tabela e o drawer
 * são montados pelo vida-util-admin.js a partir de VU_DADOS.
 *
 * @var array $dados  ['regras' => [...], 'funil' => [...], 'categorias_livres' => [...]]
 */
$base = defined('BASE_URL') ? BASE_URL : '';
?>
<link rel="stylesheet" href="<?= $base ?>/css/vida-util-admin.css">

<div class="em_wrapper">

  <div class="vu_head">
    <div class="vu_head_txt">
      <h1 class="vu_h1">Dicas de cuidado</h1>
      <p class="vu_sub">
        Alguns meses depois da entrega, o cliente recebe um lembrete para cuidar da peça
        que comprou. Quem clica demonstra interesse — e só aí um fluxo faz a abordagem.
      </p>
    </div>
    <button type="button" class="vu_btn vu_pri" id="vu-novo">
      <i class="bi bi-plus-lg"></i> Nova regra
    </button>
  </div>

  <!-- O percurso de uma dica, em números -->
  <div class="vu_funil">
    <div class="vu_etapa">
      <div class="vu_etapa_num" id="vu-n-agendadas">0</div>
      <div class="vu_etapa_lbl">Agendadas</div>
      <div class="vu_etapa_nota">esperando o prazo vencer</div>
    </div>
    <div class="vu_etapa">
      <div class="vu_etapa_num" id="vu-n-enviadas">0</div>
      <div class="vu_etapa_lbl">Enviadas</div>
      <div class="vu_etapa_nota">chegaram ao sino do cliente</div>
    </div>
    <div class="vu_etapa vu_final">
      <div class="vu_etapa_num" id="vu-n-cliques">0</div>
      <div class="vu_etapa_lbl">Cliques</div>
      <div class="vu_etapa_nota" id="vu-taxa">ainda sem envios</div>
    </div>
  </div>

  <div class="vu_card">
    <div id="vu-conteudo">
      <div class="vu_load">Carregando regras…</div>
    </div>
  </div>

</div>

<script>
  window.VU_DADOS   = <?= json_encode($dados ?? ['regras'=>[],'funil'=>[],'categorias_livres'=>[]],
                          JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.BASE_URL   = window.BASE_URL   || '<?= addslashes($base) ?>';
  window.CSRF_TOKEN = window.CSRF_TOKEN || (document.querySelector('meta[name=csrf-token]') || {}).content || '';
</script>
<script src="<?= $base ?>/js/vida-util-admin.js"></script>
