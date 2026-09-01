<?php
/**
 * Sprite de ícones do painel.
 *
 * Impresso uma vez por página, logo após <body>. As views usam o helper
 * $ico() (que chama IconLibrary::ref()) e o JS usa LOG.ico(); os dois emitem
 * <use href="#i-chave"> e dependem destes <symbol> existirem na página.
 *
 * ── Por que é do painel e não de um módulo ─────────────────────────────────
 * Este arquivo nasceu dentro de admin/views/logistica/ e o layout só o incluía
 * em /admin/logistica. Quando o checkout de expedição passou a usar o mesmo
 * helper, os ícones dele saíram vazios: a referência apontava para símbolos
 * que não estavam na página. Não é um erro que apareça em teste de sintaxe nem
 * em log — a página carrega, o ícone só some.
 *
 * Por isso o sprite é único e sempre presente. Uma tela nova que use $ico()
 * funciona sem ninguém lembrar de registrar nada.
 *
 * ── Manutenção ─────────────────────────────────────────────────────────────
 * A lista abaixo é a união do que as views e o JS realmente referenciam.
 * `php sql/validar-icones.php` confere se algo usado ficou de fora daqui.
 * Chave inexistente é ignorada e registrada por IconLibrary::avisarAusente().
 */

if (!class_exists('IconLibrary')) {
    return;
}

echo IconLibrary::sprite([
    'add', 'alerta', 'arrow-back', 'arrow-down', 'arrow-forward', 'arrow-up',
    'barcode-scanner',
    'calculadora', 'calendar-today', 'caminhao', 'cancel',
    'cash', 'check', 'check-circle', 'close', 'copy', 'delete', 'divergencia',
    'docs', 'edit', 'etiqueta', 'flag', 'folder-check', 'format-list-bulleted',
    'globe-location', 'inbox', 'info', 'ink-eraser', 'lock', 'mail',
    'open-in-new', 'package', 'payments', 'pencil', 'person-circle', 'plug',
    'power', 'printer', 'regras', 'reload', 'relogio', 'reversa', 'rotate-left',
    'rule', 'save', 'scale', 'search', 'simulador', 'stacks', 'sync',
    'sync-disabled', 'timeline', 'torre-pack', 'tower-control', 'trash', 'undo',
    'webhook', 'whatsapp', 'wifi-off',
]);
