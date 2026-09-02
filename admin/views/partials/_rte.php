<?php
/**
 * admin/views/partials/_rte.php — editor de texto rico.
 *
 * Recebe:
 *   $rteTarget      id do <textarea> que guarda o valor (obrigatório)
 *   $rtePlaceholder texto de apoio na área vazia (opcional)
 *
 * O <textarea> continua sendo quem o formulário envia; este bloco só desenha
 * uma superfície editável em cima dele. Sem JS, o textarea aparece e continua
 * funcionando — o editor é conforto, não requisito.
 *
 * Pareia com admin/assets/js/rte.js, que inicializa TODOS os .pe-rte da página.
 */
$alvo = (string) ($rteTarget ?? '');
if ($alvo === '') return;

$ph = (string) ($rtePlaceholder ?? 'Escreva aqui…');
$a  = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="pe-rte" data-target="<?= $a($alvo) ?>">
  <div class="pe-rte-toolbar" role="toolbar" aria-label="Formatação">
    <button type="button" class="pe-rte-btn" data-cmd="bold" title="Negrito (Ctrl+B)"><strong>B</strong></button>
    <button type="button" class="pe-rte-btn" data-cmd="italic" title="Itálico (Ctrl+I)"><em>I</em></button>
    <button type="button" class="pe-rte-btn" data-cmd="underline" title="Sublinhado (Ctrl+U)"><u>U</u></button>
    <span class="pe-rte-sep"></span>
    <button type="button" class="pe-rte-btn" data-cmd="formatBlock" data-val="h2" title="Título">H2</button>
    <button type="button" class="pe-rte-btn" data-cmd="formatBlock" data-val="h3" title="Subtítulo">H3</button>
    <button type="button" class="pe-rte-btn" data-cmd="formatBlock" data-val="p" title="Parágrafo">¶</button>
    <span class="pe-rte-sep"></span>
    <button type="button" class="pe-rte-btn" data-cmd="insertUnorderedList" title="Lista">•</button>
    <button type="button" class="pe-rte-btn" data-cmd="insertOrderedList" title="Lista numerada">1.</button>
    <button type="button" class="pe-rte-btn" data-cmd="formatBlock" data-val="blockquote" title="Citação">❝</button>
    <span class="pe-rte-sep"></span>
    <button type="button" class="pe-rte-btn" data-cmd="createLink" title="Inserir link">🔗</button>
    <button type="button" class="pe-rte-btn" data-cmd="unlink" title="Remover link">⛓</button>
    <button type="button" class="pe-rte-btn" data-cmd="removeFormat" title="Limpar formatação">⌫</button>
  </div>

  <div class="pe-rte-area" contenteditable="true" data-placeholder="<?= $a($ph) ?>"></div>
</div>
