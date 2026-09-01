<?php
/**
 * View: estação de bipagem.
 *
 * Uma tela só, o dia inteiro numa máquina de bancada. O operador bipa e o
 * pedido aparece à esquerda; à direita fica o que já passou na sessão.
 *
 * A sessão vive no navegador: é a memória do turno, não um dado do sistema.
 * "Limpar & continuar" zera a lista sem tocar em nada no banco.
 */
$e   = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">' . (class_exists('IconLibrary') ? IconLibrary::ref($n, '') : '') . '</span>';
?>

<div class="est_tela" id="sepEstacao">

  <div class="est_barra">
    <a href="<?= BASE_URL ?>/admin/pedidos/checkout" class="est_fechar" title="Voltar para a fila" aria-label="Voltar para a fila">
      <?= $ico('close', 18) ?>
    </a>
    <div class="est_titulo"><?= $ico('barcode-scanner', 18) ?> Estação de bipagem</div>
    <button type="button" class="btn btn-secondary" id="estLimpar">Limpar &amp; continuar</button>
  </div>

  <div class="est_info">
    <?= $ico('info', 15) ?>
    Bipe o QR da etiqueta de separação, o código de rastreio, a chave da NF-e ou digite o número do pedido.
  </div>

  <div class="est_busca">
    <select id="estMetodo" class="form-control est_metodo">
      <option value="">Todos os métodos de envio</option>
      <?php foreach (($metodos ?? []) as $m): ?>
        <option value="<?= $e($m) ?>"><?= $e($m) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" id="estCodigo" class="form-control est_input"
           placeholder="Escanear nº de rastreio / chave da NF-e / nº de pedido"
           autocomplete="off" autofocus>
    <button type="button" class="btn btn-primary" id="estBuscar">Buscar</button>
  </div>

  <div class="est_status" id="estStatus"></div>

  <div class="est_grid">

    <!-- ── escaneado agora ────────────────────────────── -->
    <div class="admin-card est_atual">
      <div class="admin-card-header"><h3>Escaneado agora</h3></div>
      <div class="admin-card-body">
        <div class="est_vazio" id="estVazio">
          <?= $ico('barcode-scanner', 34) ?>
          <p>Aguardando leitura.</p>
        </div>
        <div id="estPedido" hidden></div>
      </div>
    </div>

    <!-- ── sessão ─────────────────────────────────────── -->
    <div class="admin-card est_sessao">
      <div class="admin-card-header est_sessao_head">
        <h3>Já escaneado: <span id="estTotal">0</span></h3>
      </div>
      <div class="admin-card-body est_sessao_body">
        <table class="admin-table est_lista">
          <thead>
            <tr>
              <th style="width:44px">#</th>
              <th>Nº do pedido</th>
              <th>Nº de rastreio</th>
              <th>Método de envio</th>
            </tr>
          </thead>
          <tbody id="estLista">
            <tr class="est_lista_vazia"><td colspan="4">Nada escaneado nesta sessão.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<script>
  window.SEP_BASE = '<?= BASE_URL ?>/admin/pedidos/checkout';
</script>
