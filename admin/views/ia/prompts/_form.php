<?php
/**
 * Formulário de prompt (drawer).
 * Variáveis: $p (linha do banco, pré-preenchimento de leitura, ou null), $tipos
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
$p         = is_array($p ?? null) ? $p : [];
$novo      = (int) ($p['id'] ?? 0) === 0;
$natureza  = (string) ($p['natureza'] ?? 'prompt');
$cap       = (string) ($p['capacidade'] ?? 'texto');
$tipoAtual = (int) ($p['tipo_conteudo_id'] ?? 0);
$sistema   = ($p['origem'] ?? '') === 'sistema';
$daLeitura = $novo && (int) ($p['geracao_id'] ?? 0) > 0;
?>
<form class="ia_form ia_c_form_prompt" autocomplete="off">
  <?= SecurityHelper::csrfField() ?>
  <input type="hidden" name="id" value="<?= (int) ($p['id'] ?? 0) ?>">
  <?php if ($daLeitura): ?>
    <input type="hidden" name="geracao_id" value="<?= (int) $p['geracao_id'] ?>">
  <?php endif; ?>

  <?php if (!empty($p['imagem_arquivo_id'])): ?>
    <div class="ia_foto_strip">
      <img src="/admin/ia/arquivo?id=<?= (int) $p['imagem_arquivo_id'] ?>" alt="Imagem de origem do prompt" loading="lazy">
      <div>
        <p class="ia_foto_titulo">Lido desta imagem</p>
        <p class="ia_ajuda">O prompt fica ligado à leitura que o gerou — dá para voltar à origem pelo histórico.</p>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($novo && !$daLeitura): ?>
    <div class="ia_form_grupo">
      <label for="ia_pf_natureza">O que você está salvando</label>
      <select id="ia_pf_natureza" name="natureza" class="ia_input">
        <option value="prompt" selected>Prompt completo — ocupa o lugar da montagem automática</option>
        <option value="angulo">Ângulo criativo — só ajusta o tom de um texto montado</option>
      </select>
    </div>
  <?php else: ?>
    <input type="hidden" name="natureza" id="ia_pf_natureza" value="<?= ia_e($natureza) ?>">
  <?php endif; ?>

  <div class="ia_form_linha">
    <div class="ia_form_grupo">
      <label for="ia_pf_nome">Nome</label>
      <input type="text" id="ia_pf_nome" name="nome" class="ia_input" maxlength="100" required
             value="<?= ia_e($p['nome'] ?? '') ?>" placeholder="ex.: Capacete girando no estúdio">
    </div>
    <div class="ia_form_grupo" data-so="prompt">
      <label for="ia_pf_capacidade">Serve para</label>
      <select id="ia_pf_capacidade" name="capacidade" class="ia_input">
        <?php foreach (IAPromptService::CAPACIDADES as $valor => $rotulo): ?>
          <option value="<?= ia_e($valor) ?>"<?= $cap === $valor ? ' selected' : '' ?>><?= ia_e($rotulo) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ia_form_grupo" data-so="angulo">
      <label for="ia_pf_angulo">Código do ângulo</label>
      <input type="text" id="ia_pf_angulo" name="angulo" class="ia_input ia_input_mono" maxlength="40"
             pattern="[a-z0-9_]{2,40}" value="<?= ia_e($p['angulo'] ?? '') ?>"
             placeholder="ex.: tom_oficina"<?= $sistema ? ' readonly' : '' ?>>
    </div>
  </div>

  <div class="ia_form_grupo">
    <label for="ia_pf_descricao">Descrição <span class="ia_label_nota">(uma linha em português — aparece na lista)</span></label>
    <input type="text" id="ia_pf_descricao" name="descricao" class="ia_input" maxlength="255"
           value="<?= ia_e($p['descricao'] ?? '') ?>">
  </div>

  <div class="ia_form_grupo">
    <label for="ia_pf_tipo">Tipo de conteúdo <span class="ia_label_nota">(opcional — obrigatório para ser o padrão)</span></label>
    <select id="ia_pf_tipo" name="tipo_conteudo_id" class="ia_input">
      <option value="">Qualquer tipo da mesma capacidade</option>
      <?php foreach ($tipos as $t): ?>
        <option value="<?= (int) $t['id'] ?>" data-cap="<?= ia_e($t['capacidade']) ?>"<?= $tipoAtual === (int) $t['id'] ? ' selected' : '' ?>>
          <?= ia_e($t['nome']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="ia_form_grupo">
    <label for="ia_pf_corpo">Prompt</label>
    <textarea id="ia_pf_corpo" name="corpo" class="ia_input ia_input_mono" rows="10" required spellcheck="false"><?= ia_e($p['corpo'] ?? '') ?></textarea>
    <p class="ia_ajuda">
      Aceita <span class="ia_mono">{{produto_nome}}</span>, <span class="ia_mono">{{marca}}</span>,
      <span class="ia_mono">{{categoria}}</span>, <span class="ia_mono">{{preco}}</span>,
      <span class="ia_mono">{{preco_promo}}</span> e <span class="ia_mono">{{estoque}}</span> — trocados pelos
      dados do produto na hora de gerar. Para imagem e vídeo, prefira inglês: os modelos obedecem melhor.
    </p>
  </div>

  <div class="ia_form_grupo" data-so="prompt">
    <label class="ia_check">
      <input type="checkbox" name="padrao" value="1"<?= !empty($p['padrao']) ? ' checked' : '' ?>>
      Padrão deste tipo — entra sozinho no campo ao escolher o tipo no Gerar
    </label>
  </div>
  <div class="ia_form_grupo">
    <label class="ia_check">
      <input type="checkbox" name="ativo" value="1"<?= ($novo || (int) ($p['ativo'] ?? 0) === 1) ? ' checked' : '' ?>>
      Ativo
    </label>
  </div>

  <div class="ia_form_rodape">
    <button type="submit" class="ia_btn ia_btn_primario">
      <?= IconLibrary::render('save', 'ia_ico', ['aria-hidden' => 'true']) ?> <?= $novo ? 'Salvar na biblioteca' : 'Salvar' ?>
    </button>
  </div>
</form>
