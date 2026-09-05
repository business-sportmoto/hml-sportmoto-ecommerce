<?php
/**
 * Formulário do agente (drawer).
 * Variáveis: $agente (null = novo), $ferramentas (catálogo público), $paginas,
 *            $ocupadas (pagina => codigo de OUTRO agente ativo), $modelos,
 *            $efforts, $perguntasTexto
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
$novo = $agente === null;
$v = fn(string $k, $padrao = '') => ia_e($agente[$k] ?? $padrao);
$marcadas = $agente['ferramentas'] ?? [];
$paginasDo = $agente['paginas'] ?? [];

// Ferramentas agrupadas por domínio, na ordem que faz sentido ler.
$porDominio = [];
foreach ($ferramentas as $nome => $f) $porDominio[$f['dominio']][$nome] = $f;
$ordemDominios = ['Financeiro', 'Produtos', 'Estoque', 'Logística', 'Conversão', 'Clientes', 'Todos', 'Outros'];
// array_search devolve 0 para o primeiro item — `?:` trataria como "não achou".
$posDominio = fn($d) => ($p = array_search($d, $ordemDominios, true)) === false ? 99 : $p;
uksort($porDominio, fn($a, $b) => $posDominio($a) <=> $posDominio($b));
?>
<form class="ia_form ia_form_agente" autocomplete="off">
  <?= SecurityHelper::csrfField() ?>
  <input type="hidden" name="id" value="<?= (int) ($agente['id'] ?? 0) ?>">

  <h4 class="ia_secao_titulo">Identidade</h4>

  <div class="ia_form_linha">
    <div class="ia_form_grupo">
      <label for="ia_ag_nome">Nome de exibição <span class="ia_obrigatorio">*</span></label>
      <input type="text" id="ia_ag_nome" name="nome_exibicao" class="ia_input" required minlength="3" maxlength="80"
             value="<?= $v('nome_exibicao') ?>" placeholder="Analista Financeiro">
      <p class="ia_ajuda">Título do painel lateral no BI.</p>
    </div>
    <div class="ia_form_grupo">
      <label for="ia_ag_curto">Rótulo curto <span class="ia_obrigatorio">*</span></label>
      <input type="text" id="ia_ag_curto" name="rotulo_curto" class="ia_input" required minlength="2" maxlength="30"
             value="<?= $v('rotulo_curto') ?>" placeholder="IA Financeira">
      <p class="ia_ajuda">Texto do botão "Analisar com IA".</p>
    </div>
  </div>

  <div class="ia_form_linha">
    <div class="ia_form_grupo">
      <label for="ia_ag_codigo">Código</label>
      <?php if ($novo): ?>
        <input type="text" id="ia_ag_codigo" name="codigo" class="ia_input ia_input_mono" required maxlength="40"
               pattern="[a-z0-9_]+" placeholder="pricing" title="letras minúsculas, números e _">
        <p class="ia_ajuda">Vira <code>agente_&lt;código&gt;</code>. Não muda depois — é a chave das conversas.</p>
      <?php else: ?>
        <input type="text" id="ia_ag_codigo" class="ia_input ia_input_mono" value="<?= $v('codigo') ?>" disabled>
        <p class="ia_ajuda">Chave das conversas e do painel — não muda.</p>
      <?php endif; ?>
    </div>
    <div class="ia_form_grupo">
      <label for="ia_ag_desc">Descrição</label>
      <input type="text" id="ia_ag_desc" name="descricao" class="ia_input" maxlength="255" value="<?= $v('descricao') ?>"
             placeholder="O que este agente analisa, em uma linha">
    </div>
  </div>

  <h4 class="ia_secao_titulo">Persona</h4>
  <div class="ia_form_grupo">
    <label for="ia_ag_persona">Instruções de sistema <span class="ia_obrigatorio">*</span></label>
    <textarea id="ia_ag_persona" name="instrucoes_sistema" class="ia_input ia_input_mono" rows="14" required
              minlength="<?= IAAgenteCatalogoService::PERSONA_MIN ?>" maxlength="<?= IAAgenteCatalogoService::PERSONA_MAX ?>"><?= $v('instrucoes_sistema') ?></textarea>
    <p class="ia_ajuda">Quem o agente é, o que pode e o que <strong>não</strong> pode afirmar, e o formato da resposta
      (RESUMO · INDICADORES · CAUSAS PROVÁVEIS · IMPACTO · RECOMENDAÇÕES · PRIORIDADE). As regras de "nunca inventar
      número" e "só o próprio domínio" moram aqui — quem apagar, apaga a guarda.</p>
  </div>

  <h4 class="ia_secao_titulo">Ferramentas <span class="ia_hint">— o que o agente pode consultar (spec §25: só o próprio domínio)</span></h4>
  <?php foreach ($porDominio as $dominio => $lista): ?>
  <div class="ia_ag_dominio ia_form_grupo">
    <div class="ia_grupo_rotulo">
      <span><?= ia_e($dominio) ?></span>
      <button type="button" class="ia_btn ia_btn_icone ia_ag_dominio_todos" title="Marcar/desmarcar todas de <?= ia_e($dominio) ?>"><?= IconLibrary::render('check', 'ia_ico', ['aria-hidden' => 'true']) ?></button>
    </div>
    <?php foreach ($lista as $nome => $f): ?>
      <label class="ia_check ia_check_solto" title="<?= ia_e($f['descricao']) ?>">
        <input type="checkbox" name="ferramentas[]" value="<?= ia_e($nome) ?>" <?= in_array($nome, $marcadas, true) ? 'checked' : '' ?>>
        <span class="ia_mono"><?= ia_e(preg_replace('/^consultar_/', '', $nome)) ?></span>
        <span class="ia_celula_sub"><?= ia_e(mb_substr($f['descricao'], 0, 110)) ?><?= mb_strlen($f['descricao']) > 110 ? '…' : '' ?></span>
      </label>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <h4 class="ia_secao_titulo">Modelo</h4>
  <div class="ia_form_linha">
    <div class="ia_form_grupo">
      <label for="ia_ag_modelo">Modelo pinado</label>
      <select id="ia_ag_modelo" name="modelo_id" class="ia_input">
        <option value="0">padrão da capacidade (o primeiro ativo)</option>
        <?php foreach ($modelos as $m): ?>
          <option value="<?= (int) $m['id'] ?>" <?= (int) ($agente['modelo_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>>
            <?= ia_e($m['nome']) ?> · <?= ia_e($m['codigo_modelo']) ?><?= ((int) $m['provedor_ativo'] !== 1 || (int) $m['tem_chave'] !== 1) ? ' (provedor sem chave/inativo)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="ia_ajuda">Só modelos de capacidade <code>agente</code>. Se o pinado falhar, o orquestrador cai no próximo.</p>
    </div>
    <div class="ia_form_grupo">
      <label for="ia_ag_effort">Esforço (tempo real)</label>
      <select id="ia_ag_effort" name="effort" class="ia_input">
        <?php foreach ($efforts as $e): ?>
          <option value="<?= ia_e($e) ?>" <?= ($agente['effort'] ?? 'medium') === $e ? 'selected' : '' ?>><?= ia_e($e) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="ia_ajuda">A rodada agendada sempre usa <code>high</code>.</p>
    </div>
    <div class="ia_form_grupo">
      <label for="ia_ag_tokens">Máx. tokens de saída</label>
      <input type="number" id="ia_ag_tokens" name="max_tokens" class="ia_input" min="500" max="8000" step="100"
             value="<?= (int) ($agente['max_tokens'] ?? 2500) ?>">
    </div>
  </div>

  <h4 class="ia_secao_titulo">Páginas do BI <span class="ia_hint">— onde o botão chama este agente (uma página, um agente)</span></h4>
  <div class="ia_form_grupo ia_ag_paginas">
    <?php foreach ($paginas as $slug => $rotulo): $dono = $ocupadas[$slug] ?? null; ?>
      <label class="ia_check ia_check_solto" <?= $dono ? 'title="Já atendida por ' . ia_e($dono) . '"' : '' ?>>
        <input type="checkbox" name="paginas[]" value="<?= ia_e($slug) ?>" <?= in_array($slug, $paginasDo, true) ? 'checked' : '' ?> <?= $dono ? 'disabled' : '' ?>>
        <?= ia_e($rotulo) ?> <span class="ia_celula_sub ia_mono"><?= ia_e($slug) ?></span>
        <?php if ($dono): ?><span class="ia_pill ia_pill_off"><?= ia_e($dono) ?></span><?php endif; ?>
      </label>
    <?php endforeach; ?>
  </div>

  <h4 class="ia_secao_titulo">Perguntas</h4>
  <div class="ia_form_grupo">
    <label for="ia_ag_sug">Sugestões rápidas <span class="ia_hint">(até 4, uma por linha)</span></label>
    <textarea id="ia_ag_sug" name="sugestoes" class="ia_input" rows="4"><?= ia_e(implode("\n", $agente['sugestoes'] ?? [])) ?></textarea>
  </div>
  <div class="ia_form_grupo">
    <label for="ia_ag_perg">Lista completa por tema</label>
    <textarea id="ia_ag_perg" name="perguntas" class="ia_input ia_input_mono" rows="12"
              placeholder="Faturamento e crescimento:&#10;- Como foi o faturamento contra o período anterior?&#10;- Qual categoria mais cresceu?&#10;&#10;Margem:&#10;- Por que minha margem caiu?"><?= ia_e($perguntasTexto ?? '') ?></textarea>
    <p class="ia_ajuda">Linha terminada em <code>:</code> abre um tema; as linhas seguintes (com ou sem <code>-</code>) são as perguntas. É a lista do dropdown "Perguntas" do painel.</p>
  </div>

  <h4 class="ia_secao_titulo">Rodada agendada</h4>
  <div class="ia_form_grupo">
    <label class="ia_check">
      <input type="checkbox" name="agendado_ativo" value="1" <?= (int) ($agente['agendado_ativo'] ?? 1) === 1 ? 'checked' : '' ?>>
      Entra na rodada diária do worker (<code>cli/ia-agentes-worker.php --agente=…</code>) e no "Resumo Executivo de Hoje"
    </label>
  </div>
  <div class="ia_form_linha">
    <div class="ia_form_grupo">
      <label for="ia_ag_perg_ag">Pergunta padrão da rodada</label>
      <textarea id="ia_ag_perg_ag" name="pergunta_agendada" class="ia_input" rows="3"><?= $v('pergunta_agendada') ?></textarea>
    </div>
    <div class="ia_form_grupo">
      <label for="ia_ag_pag_ag">Página de contexto</label>
      <select id="ia_ag_pag_ag" name="pagina_agendada" class="ia_input">
        <?php foreach ($paginas as $slug => $rotulo): ?>
          <option value="<?= ia_e($slug) ?>" <?= ($agente['pagina_agendada'] ?? '') === $slug ? 'selected' : '' ?>><?= ia_e($rotulo) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="ia_ajuda">Define os dados pré-carregados da rodada.</p>
    </div>
  </div>

  <div class="ia_form_linha ia_form_linha_compacta">
    <div class="ia_form_grupo">
      <label for="ia_ag_ordem">Ordem</label>
      <input type="number" id="ia_ag_ordem" name="ordem" class="ia_input" min="0" max="999" value="<?= (int) ($agente['ordem'] ?? 0) ?>">
    </div>
    <div class="ia_form_grupo">
      <label class="ia_check">
        <input type="checkbox" name="ativo" value="1" <?= (int) ($agente['ativo'] ?? 1) === 1 ? 'checked' : '' ?>>
        Agente ativo
      </label>
    </div>
  </div>

  <div class="ia_form_rodape">
    <div class="ia_form_rodape_acoes">
      <button type="submit" class="ia_btn ia_btn_primario"><?= IconLibrary::render('save', 'ia_ico', ['aria-hidden' => 'true']) ?> <?= $novo ? 'Criar agente' : 'Salvar' ?></button>
    </div>
  </div>
</form>
