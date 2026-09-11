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

// Chegam do controller; o padrão mantém a view renderizável sem eles.
$video      = $video ?? null;
$teto_video = $teto_video ?? null;
$prompts    = $prompts ?? [];
$modelos_escolha = $modelos_escolha ?? [];

// Modelos por capacidade, na ordem da fila (o primeiro é o principal).
$porCap = [];
foreach ($modelos_escolha as $m) {
    $porCap[(string) $m['capacidade']][] = $m;
}

if (!function_exists('ia_arte_modelo')) {
    /**
     * Arte conceitual do modelo, desenhada aqui: nenhum logo de terceiro,
     * nenhuma imagem externa. Cor pelo FABRICANTE (o dono do modelo, não o
     * provedor — o Replicate hospeda modelos de várias casas), desenho pela
     * CAPACIDADE, e uma variação estável tirada do código do modelo, para dois
     * modelos da mesma casa não saírem iguais.
     */
    function ia_arte_modelo(array $m): string
    {
        $codigo = (string) ($m['codigo_modelo'] ?? '');
        $fab    = str_contains($codigo, '/')
            ? strtolower((string) strtok($codigo, '/'))
            : strtolower((string) ($m['provedor_codigo'] ?? ''));
        $paletas = [
            'automatico'        => ['#1e3a8a', '#2563eb'],
            'openai'            => ['#083d34', '#10a37f'],
            'gemini'            => ['#1f3fb0', '#8e5fd0'],
            'google'            => ['#1f3fb0', '#8e5fd0'],
            'claude'            => ['#6b2f1a', '#d97757'],
            'anthropic'         => ['#6b2f1a', '#d97757'],
            'bytedance'         => ['#0c1f4a', '#1fb6d5'],
            'black-forest-labs' => ['#1c1917', '#d97706'],
            'kwaivgi'           => ['#3b0764', '#db2777'],
        ];
        [$c1, $c2] = $paletas[$fab] ?? ['#1e293b', '#64748b'];

        $h   = crc32($codigo);
        $gid = 'ia_arte_' . dechex($h);
        $cx1 = 96 + ($h % 50);        $cy1 = 8 + (($h >> 6) % 30);   $r1 = 24 + (($h >> 12) % 18);
        $cx2 = 18 + (($h >> 3) % 40); $cy2 = 58 + (($h >> 9) % 18);  $r2 = 10 + (($h >> 15) % 12);

        $motivo = match ((string) ($m['capacidade'] ?? 'texto')) {
            'imagem' => '<rect x="104" y="20" width="44" height="34" rx="4" fill="none" stroke="#fff" stroke-opacity=".75" stroke-width="2"/>'
                      . '<circle cx="136" cy="29" r="4" fill="#fff" fill-opacity=".85"/>'
                      . '<path d="M106 52l12-13 9 9 7-6 12 10z" fill="#fff" fill-opacity=".55"/>',
            'video'  => '<rect x="100" y="18" width="52" height="38" rx="5" fill="#fff" fill-opacity=".14" stroke="#fff" stroke-opacity=".7" stroke-width="1.5"/>'
                      . '<path d="M120 28v18l15-9z" fill="#fff" fill-opacity=".9"/>'
                      . '<path d="M104 22h4M112 22h4M136 22h4M144 22h4M104 52h4M112 52h4M136 52h4M144 52h4" stroke="#fff" stroke-opacity=".6" stroke-width="2"/>',
            default  => '<path d="M104 24h40M104 32h32M104 40h38M104 48h22" stroke="#fff" stroke-opacity=".7" stroke-width="3" stroke-linecap="round"/>'
                      . '<rect x="129" y="44" width="2.5" height="8" fill="#fff" fill-opacity=".9"/>',
        };

        // Família (primeira palavra do nome), curta o bastante para não
        // encostar no desenho: 9 caracteres a 17px cabem antes do x=100.
        $familia = mb_strimwidth((string) strtok((string) ($m['nome'] ?? ''), ' '), 0, 9, '');

        return '<svg viewBox="0 0 160 70" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" preserveAspectRatio="xMidYMid slice">'
             . '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="1" y2="1">'
             . '<stop offset="0" stop-color="' . $c1 . '"/><stop offset="1" stop-color="' . $c2 . '"/></linearGradient></defs>'
             . '<rect width="160" height="70" fill="url(#' . $gid . ')"/>'
             . '<circle cx="' . $cx1 . '" cy="' . $cy1 . '" r="' . $r1 . '" fill="#fff" fill-opacity=".08"/>'
             . '<circle cx="' . $cx2 . '" cy="' . $cy2 . '" r="' . $r2 . '" fill="#fff" fill-opacity=".10"/>'
             . $motivo
             . '<text x="12" y="42" fill="#fff" fill-opacity=".95" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="17" font-weight="700">'
             . htmlspecialchars($familia, ENT_QUOTES, 'UTF-8') . '</text>'
             . '</svg>';
    }
}

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
<div class="ia_card ia_card_pad">
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

<div class="ia_card ia_card_pad">
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
                  $cap = (string) $t['capacidade'];

                  // Banner depende do Imagick. Desabilitar aqui, com o motivo
                  // no rótulo, é melhor que deixar clicar e falhar depois.
                  $semImagick = ($cap === 'composicao' && !IACompositorService::disponivel());
                  // Vídeo só com modelo de vídeo ativo e provedor configurado.
                  $semVideo   = ($cap === 'video' && empty($video));
                  $habilitado = in_array($cap, ['texto', 'imagem', 'composicao', 'video'], true) && !$semImagick && !$semVideo;

                  if ($habilitado)        { $sufixo = ''; }
                  elseif ($semImagick)    { $sufixo = ' (requer Imagick no servidor)'; }
                  elseif ($semVideo)      { $sufixo = ' (nenhum modelo de vídeo ativo)'; }
                  else                    { $sufixo = ' (em breve)'; }
                ?>
                <option value="<?= (int) $t['id'] ?>" data-cap="<?= ia_e($cap) ?>" data-modelo="<?= (int) ($t['modelo_id'] ?? 0) ?>" <?= $habilitado ? '' : 'disabled' ?>>
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
        <p class="ia_ajuda">Para formato exato (1920×800 etc.), use o tipo <strong>Banner do produto</strong> — ele monta no tamanho do layout.</p>
        <?php if (!empty($imagem) && !empty($imagem['url'])): ?>
          <label class="ia_check ia_check_solto">
            <input type="checkbox" name="usar_referencia" value="1">
            Usar a foto do produto como referência
          </label>
        <?php endif; ?>
      </div>

      <?php // Vídeo: opções do modelo PRIMÁRIO; o servidor reajusta se cair no fallback. ?>
      <?php if (!empty($video)): $vMeta = $video['meta']; ?>
      <div class="ia_form_grupo" id="ia_g_video_wrap" style="display:none">
        <div class="ia_form_linha">
          <div class="ia_form_grupo">
            <label for="ia_g_duracao">Duração</label>
            <select id="ia_g_duracao" name="duracao" class="ia_input">
              <?php foreach ($vMeta['duracoes'] as $i => $d): ?>
                <option value="<?= (int) $d ?>"<?= $i === 0 ? ' selected' : '' ?>><?= (int) $d ?> segundos</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ia_form_grupo">
            <label for="ia_g_resolucao">Qualidade</label>
            <select id="ia_g_resolucao" name="resolucao" class="ia_input">
              <?php
              $rotRes = ['480p' => '480p — rascunho, mais barato', '720p' => '720p — versão final', '1080p' => '1080p'];
              foreach ($vMeta['resolucoes'] as $i => $r): ?>
                <option value="<?= ia_e($r) ?>"<?= $i === 0 ? ' selected' : '' ?>><?= ia_e($rotRes[$r] ?? $r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ia_form_grupo">
            <label for="ia_g_vproporcao">Formato</label>
            <select id="ia_g_vproporcao" name="proporcao_video" class="ia_input">
              <?php
              $rotProp = ['9:16' => 'Vertical 9:16 — Reels e Stories', '16:9' => 'Horizontal 16:9 — site', '1:1' => 'Quadrado 1:1 — feed'];
              foreach ($vMeta['proporcoes'] as $i => $pr): ?>
                <option value="<?= ia_e($pr) ?>"<?= $i === 0 ? ' selected' : '' ?>><?= ia_e($rotProp[$pr] ?? $pr) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <?php if (!empty($imagem['url'])): ?>
          <label class="ia_check ia_check_solto">
            <input type="checkbox" name="usar_foto" value="1" checked>
            Partir da foto real do produto (primeiro quadro) — é o que garante que o vídeo mostra o produto à venda
          </label>
        <?php else: ?>
          <p class="ia_ajuda">Este produto não tem foto: o vídeo sairá só do texto e pode não mostrar o produto real.</p>
        <?php endif; ?>
        <?php if (!empty($vMeta['audio'])): ?>
          <label class="ia_check ia_check_solto">
            <input type="checkbox" name="audio" value="1" checked>
            Som ambiente (motor, vento, rua) — sem narração
          </label>
        <?php endif; ?>
        <p class="ia_ajuda" id="ia_g_video_custo" aria-live="polite"></p>
        <script type="application/json" id="ia_video_dados"><?= json_encode([
            'nome'                  => $video['nome'],
            'usd_segundo'           => $video['usd_segundo'],
            'usd_segundo_sem_audio' => $video['usd_segundo_sem_audio'],
            'teto'                  => $teto_video,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
      </div>
      <?php endif; ?>

      <?php // Compositor de banners (Fase 2C): só aparece no tipo de capacidade 'composicao'. ?>
      <div class="ia_form_grupo" id="ia_g_layout_wrap" style="display:none">
        <label for="ia_g_layout">Layout do banner</label>
        <select id="ia_g_layout" name="layout" class="ia_input">
          <?php foreach (($layouts ?? []) as $l): ?>
            <option value="<?= ia_e($l['codigo']) ?>"><?= ia_e($l['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="banner_headline" class="ia_input ia_input_empilhado" maxlength="80"
               placeholder="Headline (vazio = nome do produto)">
        <input type="text" name="banner_subtitulo" class="ia_input ia_input_empilhado" maxlength="120"
               placeholder="Subtítulo (opcional)">
        <p class="ia_ajuda">
          Foto real recortada + cena de IA + preço, montados no tamanho exato do layout.
          O preço vem do banco; a cena nunca contém o produto nem texto.
        </p>
      </div>
    </div>

    <?php // Quem vai gerar: um grupo de blocos por capacidade; o JS mostra o do tipo escolhido. ?>
    <?php if ($porCap !== []):
      // Para o JS: o que muda na tela conforme a IA — proporções (imagem),
      // durações, resoluções e preço por segundo (vídeo).
      $dadosModelos = [];
      foreach ($modelos_escolha as $m) {
          $cfgM = json_decode((string) ($m['custo_config'] ?? ''), true);
          $cfgM = is_array($cfgM) ? $cfgM : [];
          $d = ['cap' => (string) $m['capacidade'], 'nome' => (string) $m['nome']];
          if ($m['capacidade'] === 'imagem') {
              $d['proporcoes'] = IAModelo::meta($m['params_padrao'] ?? null)['proporcoes'];
          }
          if ($m['capacidade'] === 'video') {
              $d['video']                 = IAModelo::metaVideo($m['params_padrao'] ?? null);
              $d['usd_segundo']           = is_array($cfgM['usd_segundo'] ?? null) ? $cfgM['usd_segundo'] : [];
              $d['usd_segundo_sem_audio'] = is_array($cfgM['usd_segundo_sem_audio'] ?? null) ? $cfgM['usd_segundo_sem_audio'] : [];
          }
          $dadosModelos[(string) $m['id']] = $d;
      }
    ?>
    <div class="ia_form_grupo" id="ia_g_modelo_wrap" style="display:none">
      <p class="ia_grupo_rotulo" id="ia_g_modelo_rot">Quem vai gerar</p>
      <?php foreach ($porCap as $capM => $lista): ?>
        <div class="ia_modelos" role="radiogroup" aria-labelledby="ia_g_modelo_rot" data-cap="<?= ia_e($capM) ?>" hidden>
          <label class="ia_modelo">
            <input type="radio" name="modelo_id" value="">
            <span class="ia_modelo_arte"><?= ia_arte_modelo(['codigo_modelo' => 'automatico/' . $capM, 'capacidade' => $capM, 'nome' => 'Automático']) ?></span>
            <span class="ia_modelo_corpo">
              <span class="ia_modelo_nome">Automático</span>
              <span class="ia_modelo_meta">O padrão do tipo e, se ele falhar, o próximo da fila.</span>
            </span>
          </label>
          <?php foreach ($lista as $i => $m):
              $img   = IAModelo::imagemConceito($m['params_padrao'] ?? null);
              $fatos = [];
              // Tempo médio só em texto: em imagem e vídeo ele mede a SUBMISSÃO
              // ao provedor (1–2 s), não a espera real de minutos.
              if ($m['capacidade'] === 'texto' && (int) $m['tempo_medio_ms'] > 0) {
                  $fatos[] = '~' . number_format((int) $m['tempo_medio_ms'] / 1000, 1, ',', '') . ' s';
              }
              if ($m['capacidade'] === 'video') {
                  $mv = IAModelo::metaVideo($m['params_padrao'] ?? null);
                  $fatos[] = implode('/', $mv['duracoes']) . ' s · ' . implode('/', $mv['resolucoes']) . ($mv['audio'] ? ' · com som' : '');
              }
              if ($m['capacidade'] === 'imagem' && IAModelo::meta($m['params_padrao'] ?? null)['aceita_referencia']) {
                  $fatos[] = 'aceita a foto como referência';
              }
              $usos = (int) $m['total_execucoes'];
              if ($usos >= 5) {
                  $fatos[] = round(100 * (1 - (int) $m['total_falhas'] / $usos)) . '% sem falha em ' . $usos . ' usos';
              }
          ?>
          <label class="ia_modelo">
            <input type="radio" name="modelo_id" value="<?= (int) $m['id'] ?>">
            <span class="ia_modelo_arte"><?php if ($img !== null): ?><img src="<?= ia_e($img) ?>" alt="" loading="lazy"><?php else: ?><?= ia_arte_modelo($m) ?><?php endif; ?></span>
            <span class="ia_modelo_corpo">
              <span class="ia_modelo_nome"><?= ia_e($m['nome']) ?></span>
              <span class="ia_modelo_meta"><?= ia_e($m['provedor_nome']) ?> · <span class="ia_mono"><?= ia_e($m['codigo_modelo']) ?></span></span>
              <span class="ia_modelo_meta"><?= ia_e(IAModelo::resumoCusto($m['custo_config'])) ?></span>
              <?php if ($fatos !== []): ?><span class="ia_modelo_meta"><?= ia_e(implode(' · ', $fatos)) ?></span><?php endif; ?>
              <span class="ia_modelo_selos">
                <?php if ($i === 0): ?><span class="ia_pill ia_pill_neutra">1ª da fila</span><?php endif; ?>
                <span class="ia_pill ia_pill_azul ia_c_modelo_padrao" hidden>Padrão deste tipo</span>
              </span>
            </span>
          </label>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <p class="ia_ajuda">Se a IA escolhida falhar, a Central passa para a próxima da fila — o resultado mostra quem gerou de fato.</p>
      <script type="application/json" id="ia_modelos_dados"><?= json_encode($dadosModelos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    </div>
    <?php endif; ?>

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

    <?php // Biblioteca: o JS filtra pela capacidade e pelo tipo escolhidos, e aplica o padrão do tipo. ?>
    <div class="ia_form_grupo" id="ia_g_salvo_wrap" style="display:none">
      <label for="ia_g_salvo">Prompt salvo <span class="ia_label_nota">(<a href="/admin/ia/prompts" target="_blank" rel="noopener">abrir a biblioteca</a>)</span></label>
      <select id="ia_g_salvo" name="prompt_salvo_id" class="ia_input">
        <option value="">Nenhum — montar automaticamente</option>
      </select>
      <script type="application/json" id="ia_prompts_dados"><?= json_encode(array_map(fn($p) => [
          'id'     => (int) $p['id'],
          'nome'   => (string) $p['nome'],
          'cap'    => (string) $p['capacidade'],
          'tipo'   => $p['tipo_conteudo_id'] !== null ? (int) $p['tipo_conteudo_id'] : null,
          'padrao' => (int) $p['padrao'] === 1,
          'corpo'  => (string) $p['corpo'],
      ], $prompts), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    </div>

    <div class="ia_form_grupo">
      <label for="ia_g_prompt">Prompt <span class="ia_label_nota">(opcional — em branco, montamos automaticamente)</span></label>
      <textarea id="ia_g_prompt" name="prompt_custom" class="ia_input ia_input_mono" rows="5" spellcheck="false"
                placeholder="Clique em &quot;Montar prompt&quot; para pré-visualizar e editar, ou deixe em branco."></textarea>
      <p class="ia_ajuda">
        Aceita <span class="ia_mono">{{produto_nome}}</span>, <span class="ia_mono">{{marca}}</span>,
        <span class="ia_mono">{{categoria}}</span>, <span class="ia_mono">{{preco}}</span>,
        <span class="ia_mono">{{preco_promo}}</span> e <span class="ia_mono">{{estoque}}</span>.
      </p>
    </div>

    <div class="ia_form_rodape ia_form_rodape_split">
      <div class="ia_form_rodape_ctrl">
        <label for="ia_g_variacoes">Variações</label>
        <select id="ia_g_variacoes" name="variacoes" class="ia_input">
          <option value="1" selected>1</option>
          <option value="3">3</option>
          <option value="5">5</option>
        </select>
      </div>
      <div class="ia_form_rodape_acoes">
        <button type="button" id="ia_btn_preview" class="ia_btn"><?= IconLibrary::render('zoom-in', 'ia_ico', ['aria-hidden' => 'true']) ?> Montar prompt</button>
        <button type="submit" class="ia_btn ia_btn_primario"><?= IconLibrary::render('wand-stars', 'ia_ico', ['aria-hidden' => 'true']) ?> Gerar</button>
      </div>
    </div>
  </form>
</div>
