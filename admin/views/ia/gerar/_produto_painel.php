<?php
/**
 * Painel do produto + formulário de geração (retornado via AJAX).
 * Variáveis: $ctx (contexto do IAPromptBuilder), $tipos, $angulos, $csrf
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('ia_brl')) {
    function ia_brl($v): string { return 'R$ ' . number_format((float) $v, 2, ',', '.'); }
}

$av = $ctx['avaliacoes'] ?? null;

$grupos = [];
foreach ($tipos as $t) {
    $grupos[$t['grupo'] ?: 'outros'][] = $t;
}
$rotuloGrupo = [
    'social'   => 'Redes sociais',
    'anuncio'  => 'Anúncios',
    'seo'      => 'SEO',
    'produto'  => 'Página do produto',
    'mensagem' => 'Mensagens',
    'email'    => 'E-mail',
    'video'    => 'Vídeo',
    'outros'   => 'Outros',
];
?>
<div class="ia_card">
  <div class="ia_produto">
    <div class="ia_produto_thumb">
      <?php if (!empty($imagem['url'])): ?>
        <img src="<?= ia_e($imagem['url']) ?>" alt="" loading="lazy">
      <?php else: ?>
        <?= IconLibrary::render('view-in-ar', 'ia_ico ia_ico_lg', ['aria-hidden' => 'true']) ?>
      <?php endif; ?>
    </div>
    <div class="ia_produto_info">
      <p class="ia_produto_nome"><?= ia_e($ctx['nome']) ?> <span class="ia_celula_sub" style="display:inline">#<?= (int) $ctx['produto_id'] ?></span></p>
      <div class="ia_resultado_meta">
        <?php if (!empty($ctx['marca'])): ?><span><?= IconLibrary::render('label', 'ia_ico', ['aria-hidden' => 'true']) ?> <?= ia_e($ctx['marca']) ?></span><?php endif; ?>
        <?php if (!empty($ctx['categoria'])): ?><span><?= IconLibrary::render('stacks', 'ia_ico', ['aria-hidden' => 'true']) ?> <?= ia_e($ctx['categoria']) ?></span><?php endif; ?>
        <span class="ia_preco">
          <?php if ($ctx['preco_promo'] !== null): ?>
            <span class="ia_preco_de"><?= ia_e(ia_brl($ctx['preco'])) ?></span><?= ia_e(ia_brl($ctx['preco_promo'])) ?>
          <?php else: ?>
            <?= ia_e(ia_brl($ctx['preco'])) ?>
          <?php endif; ?>
        </span>
        <span><?= IconLibrary::render('stacks', 'ia_ico', ['aria-hidden' => 'true']) ?> <?= (int) $ctx['estoque_total'] ?> em estoque</span>
      </div>
      <div class="ia_produto_meta">
        <?php if (!empty($ctx['lancamento'])): ?><span class="ia_pill ia_pill_azul"><?= IconLibrary::render('rocket-launch', 'ia_ico', ['aria-hidden' => 'true']) ?> Lançamento</span><?php endif; ?>
        <?php if ($ctx['preco_promo'] !== null): ?>
          <span class="ia_pill ia_pill_aviso"><?= IconLibrary::render('zap', 'ia_ico', ['aria-hidden' => 'true']) ?> Promoção<?= !empty($ctx['promo_fim']) ? ' até ' . ia_e(date('d/m', strtotime($ctx['promo_fim']))) : '' ?></span>
        <?php endif; ?>
        <?php if (is_array($av) && !empty($av['total'])): ?>
          <span class="ia_pill ia_pill_ok"><?= IconLibrary::render('star', 'ia_ico', ['aria-hidden' => 'true']) ?> <?= number_format((float) $av['media'], 1, ',', '') ?> (<?= (int) $av['total'] ?>)</span>
        <?php endif; ?>
        <?php if (!empty($ctx['compatibilidade'])): ?>
          <span class="ia_pill ia_pill_off" title="<?= ia_e(implode('; ', $ctx['compatibilidade'])) ?>">
            <?= IconLibrary::render('check-circle', 'ia_ico', ['aria-hidden' => 'true']) ?> <?= count($ctx['compatibilidade']) ?> compatibilidade(s)
          </span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="ia_card">
  <p class="ia_card_titulo"><?= IconLibrary::render('wand-stars', 'ia_ico', ['aria-hidden' => 'true']) ?> Nova geração</p>

  <form id="ia_form_gerar" autocomplete="off">
    <?= SecurityHelper::csrfField() ?>
    <input type="hidden" name="produto_id" value="<?= (int) $ctx['produto_id'] ?>">

<?php if (!empty($imagem) && !empty($imagem['url'])): ?>
    <div class="ia_foto_strip">
      <img src="<?= ia_e($imagem['url']) ?>" alt="Foto principal do produto" loading="lazy">
      <div>
        <p class="ia_foto_titulo">Foto principal do produto</p>
        <p class="ia_ajuda">Fonte do recorte (fundo removido) e da geração com referência.</p>
        <button type="button" class="ia_btn" id="ia_btn_recorte">
          <?= IconLibrary::render('ink-eraser', 'ia_ico', ['aria-hidden' => 'true']) ?> Remover fundo (recorte)
        </button>
      </div>
    </div>
<?php endif; ?>

    <div class="ia_form_linha">
      <div class="ia_form_grupo">
        <label for="ia_g_tipo">Tipo de conteúdo</label>
        <select id="ia_g_tipo" name="tipo_conteudo_id" class="ia_input" required>
          <option value="">Selecione…</option>
          <?php foreach ($grupos as $grupo => $itens): ?>
            <optgroup label="<?= ia_e($rotuloGrupo[$grupo] ?? ucfirst($grupo)) ?>">
              <?php foreach ($itens as $t): ?>
                <?php
                  $cap        = (string) $t['capacidade'];
                  $habilitado = in_array($cap, ['texto', 'imagem'], true);
                  $sufixo     = $habilitado ? '' : (($cap === 'video') ? ' (Fase 4)' : ' (em breve)');
                ?>
                <option value="<?= (int) $t['id'] ?>" data-cap="<?= ia_e($cap) ?>" <?= $habilitado ? '' : 'disabled' ?>>
                  <?= ia_e($t['nome']) ?><?= $sufixo ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ia_form_grupo" id="ia_g_angulo_wrap">
        <label for="ia_g_angulo">Ângulo criativo</label>
        <select id="ia_g_angulo" name="angulo" class="ia_input">
          <option value="">Automático (sem ângulo específico)</option>
          <?php foreach ($angulos as $a): ?>
            <option value="<?= ia_e($a['angulo']) ?>"><?= ia_e($a['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ia_form_grupo" id="ia_g_proporcao_wrap" style="display:none">
        <label for="ia_g_proporcao">Proporção</label>
        <select id="ia_g_proporcao" name="proporcao" class="ia_input">
          <?php
          // Só as proporções que o modelo primário declara aceitar. A lista
          // fixa daqui oferecia 7 opções enquanto o backend aceitava 3: as
          // demais eram trocadas em silêncio ou voltavam em HTTP 422.
          $rotulos = [
              '1:1'  => 'Quadrado (1:1) — feed',
              '3:2'  => 'Paisagem (3:2) — banner/site',
              '2:3'  => 'Retrato (2:3) — story base',
              '9:16' => 'Story (9:16)',
              '16:9' => 'Widescreen (16:9)',
              '3:4'  => 'Retrato (3:4)',
              '4:3'  => 'Paisagem (4:3)',
          ];
          foreach (($proporcoes ?? ['1:1']) as $i => $p): ?>
            <option value="<?= ia_e($p) ?>"<?= $i === 0 ? ' selected' : '' ?>><?= ia_e($rotulos[$p] ?? $p) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="ia_ajuda">Formatos exatos (1920×800 etc.) saem do compositor na Fase 2C.</p>
        <?php if (!empty($imagem) && !empty($imagem['url'])): ?>
          <label class="ia_check" style="margin-top:8px">
            <input type="checkbox" name="usar_referencia" value="1">
            Usar a foto do produto como referência (FLUX.2)
          </label>
        <?php endif; ?>
      </div>
    </div>

    <div class="ia_form_linha">
      <div class="ia_form_grupo">
        <label for="ia_g_objetivo">Objetivo</label>
        <input type="text" id="ia_g_objetivo" name="briefing_objetivo" class="ia_input" maxlength="200"
               placeholder="ex.: girar estoque antes do fim da promoção">
      </div>
      <div class="ia_form_grupo">
        <label for="ia_g_publico">Público-alvo</label>
        <input type="text" id="ia_g_publico" name="briefing_publico" class="ia_input" maxlength="200"
               placeholder="ex.: motociclistas urbanos, CG/Fan 160">
      </div>
    </div>

    <div class="ia_form_linha">
      <div class="ia_form_grupo">
        <label for="ia_g_tom">Tom de comunicação</label>
        <input type="text" id="ia_g_tom" name="briefing_tom" class="ia_input" maxlength="120"
               placeholder="ex.: direto e técnico / descontraído" list="ia_tons">
        <datalist id="ia_tons">
          <option value="Direto e técnico"></option>
          <option value="Descontraído"></option>
          <option value="Urgente"></option>
          <option value="Institucional"></option>
        </datalist>
      </div>
      <div class="ia_form_grupo">
        <label for="ia_g_condicao">Condição especial</label>
        <input type="text" id="ia_g_condicao" name="briefing_condicao" class="ia_input" maxlength="200"
               placeholder="ex.: frete grátis Sul, cupom MOTO10">
      </div>
    </div>

    <div class="ia_form_grupo">
      <label for="ia_g_prompt">Prompt <span style="font-weight:400;color:var(--em-texto-sub)">(opcional — em branco, montamos automaticamente)</span></label>
      <textarea id="ia_g_prompt" name="prompt_custom" class="ia_input ia_input_mono" rows="5" spellcheck="false"
                placeholder="Clique em &quot;Montar prompt&quot; para pré-visualizar e editar, ou deixe em branco."></textarea>
      <p class="ia_ajuda">
        Aceita <span class="ia_mono">{{produto_nome}}</span>, <span class="ia_mono">{{marca}}</span>,
        <span class="ia_mono">{{categoria}}</span>, <span class="ia_mono">{{preco}}</span>,
        <span class="ia_mono">{{preco_promo}}</span> e <span class="ia_mono">{{estoque}}</span>.
      </p>
    </div>

    <div class="ia_form_rodape" style="justify-content:space-between;align-items:center">
      <div class="ia_form_grupo" style="margin:0;display:flex;align-items:center;gap:10px">
        <label for="ia_g_variacoes" style="margin:0">Variações</label>
        <select id="ia_g_variacoes" name="variacoes" class="ia_input" style="width:auto">
          <option value="1" selected>1</option>
          <option value="3">3</option>
          <option value="5">5</option>
        </select>
      </div>
      <div style="display:flex;gap:8px">
        <button type="button" id="ia_btn_preview" class="ia_btn"><?= IconLibrary::render('zoom-in', 'ia_ico', ['aria-hidden' => 'true']) ?> Montar prompt</button>
        <button type="submit" class="ia_btn ia_btn_primario"><?= IconLibrary::render('wand-stars', 'ia_ico', ['aria-hidden' => 'true']) ?> Gerar</button>
      </div>
    </div>
  </form>
</div>
