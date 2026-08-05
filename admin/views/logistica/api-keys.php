<?php
/**
 * View: API — chaves de acesso (admin).
 * Recebe: $escopos (lista disponível). Lógica em api-keys.js.
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">' . (class_exists('IconLibrary') ? IconLibrary::render($n) : '') . '</span>';
?>
<link rel="stylesheet" href="/assets/css/logistica.css">

<div class="log_shell" id="logApi" data-base="/admin/logistica/api-keys">

    <div class="log_head">
        <div>
            <h1><?= $ico('plug', 22) ?> API</h1>
            <p>Chaves de acesso para integrações consultarem cotação, emitirem etiqueta e acompanharem rastreio.</p>
        </div>
        <div class="log_head_actions">
            <a href="/admin/logistica" class="log_btn log_btn--sm"><?= $ico('caminhao', 15) ?> Torre</a>
            <button type="button" class="log_btn log_btn--primary log_btn--sm" id="logApiNova"><i class="bi bi-plus-lg"></i> Nova chave</button>
        </div>
    </div>

    <div class="log_card">
        <div class="log_table_wrap">
            <table class="log_table" id="logApiTabela">
                <thead><tr>
                    <th>Nome</th>
                    <th style="width:150px">Prefixo</th>
                    <th>Escopos</th>
                    <th style="width:110px">Limite/min</th>
                    <th style="width:100px">Uso 24h</th>
                    <th style="width:100px">Situação</th>
                    <th style="width:150px" class="log_col_acoes">Ações</th>
                </tr></thead>
                <tbody id="logApiBody"><tr><td colspan="7"><div class="log_state"><div class="log_spinner"></div></div></td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="log_card" style="margin-top:16px">
        <div style="padding:16px 18px">
            <h3 style="margin:0 0 8px;font-size:14px">Como usar</h3>
            <p class="log_muted" style="margin:0 0 10px">Envie a chave no header <code>Authorization: Bearer sua_chave</code>. Base: <code>/api/logistica/v1</code>.</p>
            <pre class="logapi_pre">POST /api/logistica/v1/cotacoes
Authorization: Bearer sk_live_...
Content-Type: application/json

{ "cep_destino": "01001000", "uf": "SP",
  "itens": [ { "peso_g": 800, "altura_cm": 4, "largura_cm": 20, "comprimento_cm": 30, "valor": 300, "quantidade": 1 } ] }</pre>
        </div>
    </div>
</div>

<script>
    window.LOG_API_BASE = '/admin/logistica/api-keys';
    window.LOG_API_ESCOPOS = <?= json_encode($escopos ?? [], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/assets/js/api-keys.js" defer></script>
