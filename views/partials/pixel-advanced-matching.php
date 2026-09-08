<?php
/**
 * views/partials/pixel-advanced-matching.php
 *
 * Advanced Matching do Pixel: publica em `window.SM_AM` a PII do
 * cliente logado, JÁ HASHEADA, para o pixel.js passar no fbq('init').
 *
 * POR QUE EXISTE: quando o mesmo event_id chega do navegador e do
 * servidor, a Meta descarta um dos dois — e na prática mantém o do
 * NAVEGADOR. Sem isto, o evento que sobrevive carrega só IP e user
 * agent, enquanto o do servidor (descartado) levava e-mail, telefone,
 * nome, CEP... O match quality do que fica é o pior dos dois.
 *
 * PRIVACIDADE (não afrouxar):
 *  - Só sai com CONSENTIMENTO DE MARKETING. Sem ele o pixel nem
 *    carrega, e a PII — mesmo hasheada — não deve ir para o HTML.
 *  - Só sai para cliente LOGADO (é dele o dado).
 *  - Só SHA-256, nunca texto puro. O hash vem do ConversionService,
 *    a mesma fonte que o CAPI usa, para os dois lados casarem.
 *
 * Incluir no <head> ANTES do pixel.js.
 */

if (!class_exists('ConversionService') || !class_exists('ConsentService')) {
    return;
}

// 1. Consentimento de marketing — o mesmo gate do pixel e do dispatcher.
try {
    $smAmEstado = (new ConsentService())->estadoAtual();
} catch (\Throwable $e) {
    return; // sem conseguir ler o consentimento, não publica nada
}
if (!$smAmEstado || empty($smAmEstado['marketing'])) {
    return;
}

// 2. Cliente logado.
$smAmClienteId = (int) (Session::get('cliente_id') ?? 0);
if ($smAmClienteId <= 0) {
    return;
}

// 3. Hashes — mesma normalização do MetaCapiAdapter.
$smAmDados = ConversionService::piiHasheada($smAmClienteId);
if (empty($smAmDados)) {
    return;
}

// O Pixel espera escalares (o CAPI é que embrulha em array).
// JSON_HEX_* fecha qualquer vetor de quebra do <script>.
$smAmJson = json_encode(
    $smAmDados,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($smAmJson === false) {
    return;
}
?>
<script>window.SM_AM = <?= $smAmJson ?>;</script>
