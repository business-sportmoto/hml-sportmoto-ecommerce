<?php
/**
 * admin/views/configuracoes/rodape.php — editor do rodapé da loja.
 *
 * Recebe: $cfg (chaves footer_*), $loja (chaves da loja), $icones, $pagamentos.
 *
 * As listas (colunas, benefícios, selos, tags) são montadas pelo rodape.js e
 * viajam em campos escondidos como JSON. Ver a nota no controller sobre por quê.
 */
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/**
 * JSON para dentro de <script>.
 *
 * Aqui NÃO se usa htmlspecialchars: o conteúdo de <script> não passa pelo
 * decodificador de entidades do HTML, então `&quot;` chegaria literal no
 * parser de JS e derrubaria o bloco inteiro — a tela abria sem lista nenhuma.
 * Os flags HEX_* fazem o papel oposto e correto: impedem que um `</script>`
 * dentro de um texto feche a tag.
 */
$j = static fn($v) => json_encode(
    $v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int) $s . 'px">'
    . (class_exists('IconLibrary') ? IconLibrary::ref($n, '') : '') . '</span>';
?>

<div class="admin-page rod_page">

  <div class="admin-page-header">
    <div>
      <h1 class="admin-page-title"><?= $ico('docs', 22) ?> Rodapé da loja</h1>
      <p class="admin-page-sub">O que aparece no fim de toda página pública</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
      <a href="<?= BASE_URL ?>" target="_blank" rel="noopener" class="btn btn-secondary">
        <?= $ico('open-in-new', 15) ?> Ver a loja
      </a>
      <button type="button" class="btn btn-primary" id="rodSalvar">
        <?= $ico('save', 15) ?> Salvar rodapé
      </button>
    </div>
  </div>

  <form id="rodForm" autocomplete="off" onsubmit="return false;">
    <?= SecurityHelper::csrfField() ?>

    <!-- ── identidade e contato ───────────────────────────────────── -->
    <div class="admin-card rod_card">
      <div class="admin-card-header"><h3>Identidade e contato</h3></div>
      <div class="admin-card-body">
        <p class="rod_nota">
          <?= $ico('info', 14) ?>
          Estes campos são os dados da loja — os mesmos usados na nota fiscal, no
          frete e no SEO. Editar aqui muda em todo lugar.
        </p>

        <div class="rod_grid">
          <div class="ap-form-group">
            <label class="ap-form-label" for="site_nome">Nome da loja</label>
            <input type="text" class="form-control" id="site_nome" name="site_nome"
                   maxlength="80" value="<?= $e($loja['site_nome']) ?>">
          </div>
          <div class="ap-form-group">
            <label class="ap-form-label" for="site_cnpj">CNPJ</label>
            <input type="text" class="form-control" id="site_cnpj" name="site_cnpj"
                   maxlength="20" value="<?= $e($loja['site_cnpj']) ?>">
            <span class="rod_hint">O rodapé formata sozinho: 00.000.000/0001-00</span>
          </div>
        </div>

        <div class="ap-form-group">
          <label class="ap-form-label" for="footer_descricao">Parágrafo de apresentação</label>
          <textarea class="form-control" id="footer_descricao" name="footer_descricao"
                    rows="3" maxlength="400"><?= $e($cfg['footer_descricao']) ?></textarea>
          <span class="rod_hint">Aparece embaixo do logo, na primeira coluna.</span>
        </div>

        <div class="rod_grid">
          <div class="ap-form-group">
            <label class="ap-form-label" for="site_telefone">Telefone</label>
            <input type="text" class="form-control" id="site_telefone" name="site_telefone"
                   maxlength="30" value="<?= $e($loja['site_telefone']) ?>">
          </div>
          <div class="ap-form-group">
            <label class="ap-form-label" for="footer_whatsapp">WhatsApp</label>
            <input type="text" class="form-control" id="footer_whatsapp" name="footer_whatsapp"
                   maxlength="30" value="<?= $e($cfg['footer_whatsapp']) ?>"
                   placeholder="Vazio = usa o telefone">
            <span class="rod_hint">Sem DDI o link recebe 55 automaticamente.</span>
          </div>
          <div class="ap-form-group">
            <label class="ap-form-label" for="site_email">E-mail</label>
            <input type="email" class="form-control" id="site_email" name="site_email"
                   maxlength="120" value="<?= $e($loja['site_email']) ?>">
          </div>
        </div>

        <div class="rod_grid rod_grid--4">
          <div class="ap-form-group" style="grid-column:span 2">
            <label class="ap-form-label" for="endereco_logradouro">Endereço</label>
            <input type="text" class="form-control" id="endereco_logradouro" name="endereco_logradouro"
                   maxlength="150" value="<?= $e($loja['endereco_logradouro']) ?>">
          </div>
          <div class="ap-form-group">
            <label class="ap-form-label" for="endereco_cidade">Cidade</label>
            <input type="text" class="form-control" id="endereco_cidade" name="endereco_cidade"
                   maxlength="80" value="<?= $e($loja['endereco_cidade']) ?>">
          </div>
          <div class="ap-form-group">
            <label class="ap-form-label" for="endereco_uf">UF</label>
            <input type="text" class="form-control" id="endereco_uf" name="endereco_uf"
                   maxlength="2" value="<?= $e($loja['endereco_uf']) ?>">
          </div>
          <div class="ap-form-group">
            <label class="ap-form-label" for="endereco_cep">CEP</label>
            <input type="text" class="form-control" id="endereco_cep" name="endereco_cep"
                   maxlength="10" value="<?= $e($loja['endereco_cep']) ?>">
          </div>
        </div>

        <div class="rod_grid rod_grid--4">
          <div class="ap-form-group">
            <label class="ap-form-label" for="horario_semana_abre">Seg–Sex abre</label>
            <input type="time" class="form-control" id="horario_semana_abre"
                   name="horario_semana_abre" value="<?= $e($loja['horario_semana_abre']) ?>">
          </div>
          <div class="ap-form-group">
            <label class="ap-form-label" for="horario_semana_fecha">Seg–Sex fecha</label>
            <input type="time" class="form-control" id="horario_semana_fecha"
                   name="horario_semana_fecha" value="<?= $e($loja['horario_semana_fecha']) ?>">
          </div>
          <div class="ap-form-group">
            <label class="ap-form-label" for="horario_sabado_abre">Sábado abre</label>
            <input type="time" class="form-control" id="horario_sabado_abre"
                   name="horario_sabado_abre" value="<?= $e($loja['horario_sabado_abre']) ?>">
          </div>
          <div class="ap-form-group">
            <label class="ap-form-label" for="horario_sabado_fecha">Sábado fecha</label>
            <input type="time" class="form-control" id="horario_sabado_fecha"
                   name="horario_sabado_fecha" value="<?= $e($loja['horario_sabado_fecha']) ?>">
          </div>
        </div>
      </div>
    </div>

    <!-- ── redes sociais ──────────────────────────────────────────── -->
    <div class="admin-card rod_card">
      <div class="admin-card-header"><h3>Redes sociais</h3></div>
      <div class="admin-card-body">
        <p class="rod_nota"><?= $ico('info', 14) ?> Rede sem URL não aparece no rodapé.</p>
        <div class="rod_grid">
          <?php foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook',
                          'youtube' => 'YouTube', 'tiktok' => 'TikTok'] as $k => $rot): ?>
            <div class="ap-form-group">
              <label class="ap-form-label" for="social_<?= $k ?>"><?= $rot ?></label>
              <input type="url" class="form-control" id="social_<?= $k ?>" name="social_<?= $k ?>"
                     placeholder="https://" value="<?= $e($loja['social_' . $k]) ?>">
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ── newsletter ─────────────────────────────────────────────── -->
    <div class="admin-card rod_card">
      <div class="admin-card-header"><h3>Newsletter</h3></div>
      <div class="admin-card-body">
        <label class="toggle-field" style="margin-bottom:12px">
          <input type="checkbox" id="footer_newsletter_ativo" name="footer_newsletter_ativo"
                 value="1" <?= !empty($cfg['footer_newsletter_ativo']) ? 'checked' : '' ?>>
          <span class="toggle-slider"></span>
          <span>Exibir o bloco de newsletter</span>
        </label>

        <div class="rod_grid">
          <div class="ap-form-group">
            <label class="ap-form-label" for="footer_newsletter_badge">Selo</label>
            <input type="text" class="form-control" id="footer_newsletter_badge"
                   name="footer_newsletter_badge" maxlength="60"
                   value="<?= $e($cfg['footer_newsletter_badge']) ?>">
          </div>
          <div class="ap-form-group">
            <label class="ap-form-label" for="footer_newsletter_botao">Rótulo do botão</label>
            <input type="text" class="form-control" id="footer_newsletter_botao"
                   name="footer_newsletter_botao" maxlength="40"
                   value="<?= $e($cfg['footer_newsletter_botao']) ?>">
          </div>
        </div>
        <div class="ap-form-group">
          <label class="ap-form-label" for="footer_newsletter_titulo">Título</label>
          <input type="text" class="form-control" id="footer_newsletter_titulo"
                 name="footer_newsletter_titulo" maxlength="140"
                 value="<?= $e($cfg['footer_newsletter_titulo']) ?>">
        </div>
        <div class="ap-form-group">
          <label class="ap-form-label" for="footer_newsletter_texto">Texto de apoio</label>
          <textarea class="form-control" id="footer_newsletter_texto"
                    name="footer_newsletter_texto" rows="2" maxlength="300"><?= $e($cfg['footer_newsletter_texto']) ?></textarea>
        </div>
      </div>
    </div>

    <!-- ── benefícios ─────────────────────────────────────────────── -->
    <div class="admin-card rod_card">
      <div class="admin-card-header">
        <h3>Faixa de benefícios</h3>
        <button type="button" class="btn btn-secondary btn-sm js-rod-add" data-lista="beneficios">
          <?= $ico('add', 14) ?> Adicionar
        </button>
      </div>
      <div class="admin-card-body">
        <div class="rod_lista" id="rodBeneficios"></div>
        <input type="hidden" name="footer_beneficios" id="rodBeneficiosJson">
      </div>
    </div>

    <!-- ── colunas de links ───────────────────────────────────────── -->
    <div class="admin-card rod_card">
      <div class="admin-card-header">
        <h3>Colunas de links</h3>
        <button type="button" class="btn btn-secondary btn-sm js-rod-add" data-lista="colunas">
          <?= $ico('add', 14) ?> Nova coluna
        </button>
      </div>
      <div class="admin-card-body">
        <p class="rod_nota">
          <?= $ico('info', 14) ?>
          URL começando com <code>/</code> é interna e recebe o endereço da loja
          na frente; <code>https://</code> abre em nova aba.
        </p>
        <div class="rod_colunas" id="rodColunas"></div>
        <input type="hidden" name="footer_colunas" id="rodColunasJson">
      </div>
    </div>

    <!-- ── buscas populares ───────────────────────────────────────── -->
    <div class="admin-card rod_card">
      <div class="admin-card-header"><h3>Buscas populares</h3></div>
      <div class="admin-card-body">
        <div class="rod_tags" id="rodBuscas" data-placeholder="Digite e tecle Enter"></div>
        <input type="hidden" name="footer_buscas" id="rodBuscasJson">
        <span class="rod_hint">Cada termo vira um link para a busca da loja.</span>
      </div>
    </div>

    <!-- ── pagamento, logística e selos ───────────────────────────── -->
    <div class="admin-card rod_card">
      <div class="admin-card-header"><h3>Formas de pagamento</h3></div>
      <div class="admin-card-body">
        <div class="rod_bandeiras">
          <?php foreach ($pagamentos as $chave => $p): ?>
            <label class="rod_bandeira">
              <input type="checkbox" class="js-rod-pag" value="<?= $e($chave) ?>"
                     <?= in_array($chave, (array) $cfg['footer_pagamentos'], true) ? 'checked' : '' ?>>
              <span class="rod_bandeira_art"><?= FooterService::pagamento($chave) ?></span>
              <span class="rod_bandeira_nome"><?= $e($p[0]) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="footer_pagamentos" id="rodPagamentosJson">

        <div class="ap-form-group" style="margin-top:14px">
          <label class="ap-form-label" for="footer_pagamento_nota">Texto abaixo das bandeiras</label>
          <input type="text" class="form-control" id="footer_pagamento_nota"
                 name="footer_pagamento_nota" maxlength="140"
                 value="<?= $e($cfg['footer_pagamento_nota']) ?>">
        </div>
      </div>
    </div>

    <div class="admin-card rod_card">
      <div class="admin-card-header"><h3>Entrega e logística</h3></div>
      <div class="admin-card-body">
        <div class="rod_tags" id="rodLogistica" data-placeholder="Ex.: Correios"></div>
        <input type="hidden" name="footer_logistica" id="rodLogisticaJson">
        <div class="ap-form-group" style="margin-top:14px">
          <label class="ap-form-label" for="footer_logistica_nota">Texto abaixo</label>
          <input type="text" class="form-control" id="footer_logistica_nota"
                 name="footer_logistica_nota" maxlength="140"
                 value="<?= $e($cfg['footer_logistica_nota']) ?>">
        </div>
      </div>
    </div>

    <div class="admin-card rod_card">
      <div class="admin-card-header">
        <h3>Selos de segurança</h3>
        <button type="button" class="btn btn-secondary btn-sm js-rod-add" data-lista="selos">
          <?= $ico('add', 14) ?> Adicionar
        </button>
      </div>
      <div class="admin-card-body">
        <p class="rod_nota rod_nota--alerta">
          <?= $ico('alerta', 14) ?>
          Só declare o que a loja realmente tem. Selo falso é o tipo de detalhe
          que o cliente confere.
        </p>
        <div class="rod_lista" id="rodSelos"></div>
        <input type="hidden" name="footer_selos" id="rodSelosJson">
        <div class="ap-form-group" style="margin-top:14px">
          <label class="ap-form-label" for="footer_selos_nota">Texto abaixo</label>
          <input type="text" class="form-control" id="footer_selos_nota"
                 name="footer_selos_nota" maxlength="140"
                 value="<?= $e($cfg['footer_selos_nota']) ?>">
        </div>
      </div>
    </div>

    <!-- ── barra inferior ─────────────────────────────────────────── -->
    <div class="admin-card rod_card">
      <div class="admin-card-header">
        <h3>Barra inferior</h3>
        <button type="button" class="btn btn-secondary btn-sm js-rod-add" data-lista="legais">
          <?= $ico('add', 14) ?> Novo link
        </button>
      </div>
      <div class="admin-card-body">
        <div class="rod_lista" id="rodLegais"></div>
        <input type="hidden" name="footer_links_legais" id="rodLegaisJson">

        <div class="rod_grid" style="margin-top:14px">
          <div class="ap-form-group">
            <label class="ap-form-label" for="footer_copyright_extra">Frase ao lado do copyright</label>
            <input type="text" class="form-control" id="footer_copyright_extra"
                   name="footer_copyright_extra" maxlength="140"
                   value="<?= $e($cfg['footer_copyright_extra']) ?>">
          </div>
          <div class="ap-form-group">
            <label class="ap-form-label" for="footer_assinatura">Assinatura final</label>
            <input type="text" class="form-control" id="footer_assinatura"
                   name="footer_assinatura" maxlength="80"
                   value="<?= $e($cfg['footer_assinatura']) ?>"
                   placeholder="Opcional">
          </div>
        </div>
      </div>
    </div>

  </form>
</div>

<script>
  window.ROD = {
    salvarUrl : '<?= BASE_URL ?>/admin/configuracoes/rodape/salvar',
    icones    : <?= $j(array_map(fn($i) => $i[0], $icones)) ?>,
    iconesSvg : <?= $j(array_map(fn($k) => FooterService::icone($k), array_combine(array_keys($icones), array_keys($icones)))) ?>,
    dados     : {
      beneficios : <?= $j($cfg['footer_beneficios']) ?>,
      colunas    : <?= $j($cfg['footer_colunas']) ?>,
      buscas     : <?= $j($cfg['footer_buscas']) ?>,
      logistica  : <?= $j($cfg['footer_logistica']) ?>,
      selos      : <?= $j($cfg['footer_selos']) ?>,
      legais     : <?= $j($cfg['footer_links_legais']) ?>
    }
  };
</script>
<script src="<?= PerformanceHelper::assetVersion('js/rodape.js', true) ?>"></script>
