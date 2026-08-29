<?php
/**
 * Sprite de icones do modulo de logistica.
 *
 * Impresso uma unica vez por pagina, logo apos <body>. Views e JS referenciam
 * os simbolos por <use href="#i-chave"> em vez de repetir o SVG a cada uso —
 * o que importa nas telas de lista, onde o mesmo icone reaparece em cada linha.
 *
 * Manter em ordem alfabetica. Chave inexistente e ignorada pelo sprite e
 * registrada por IconLibrary (LogService, canal app) em vez de sumir calada.
 */

if (!class_exists('IconLibrary')) {
    return;
}

echo IconLibrary::sprite([
    'add', 'alerta', 'arrow-down', 'arrow-forward', 'arrow-up', 'calculadora',
    'calendar-today', 'caminhao', 'cancel', 'cash', 'check', 'check-circle',
    'close', 'copy', 'delete', 'divergencia', 'docs', 'edit', 'etiqueta',
    'flag', 'folder-check', 'format-list-bulleted', 'globe-location', 'inbox',
    'info', 'ink-eraser', 'lock', 'mail', 'open-in-new', 'package', 'payments',
    'pencil', 'person-circle', 'plug', 'power', 'printer', 'regras', 'reload',
    'relogio', 'reversa', 'rotate-left', 'rule', 'save', 'scale', 'search',
    'simulador', 'stacks', 'sync', 'sync-disabled', 'timeline', 'torre-pack',
    'tower-control', 'trash', 'undo', 'webhook', 'whatsapp', 'wifi-off',
]);
