<?php
/**
 * views/partials/produto-compatibilidade.php
 *
 * "Serve na minha moto?" — a pergunta que o cliente veio fazer.
 *
 * Regras que a cópia desta view precisa respeitar, e que não são estéticas:
 *
 *  1. Produto sem NENHUMA linha de compatibilidade não renderiza nada. Não se
 *     anuncia a lacuna do cadastro. O return no topo é essa decisão.
 *  2. Nunca dizer "não serve". A tabela registra o que foi cadastrado como
 *     compatível; a falta de uma linha é falta de registro. "Não consta" é a
 *     única afirmação que a loja pode fazer.
 *  3. Nada aqui bloqueia a compra. O módulo informa; quem decide é ele.
 *
 * Espera do chamador:
 *   $product     array   produto
 *   $compat      array   retorno de CompatibilidadeService::avaliar()
 *   $compatSvc   CompatibilidadeService
 *   $montadoras  array   [{id, nome}] para o seletor
 *   $waCanal     ?array  canal de WhatsApp da loja, ou null se não cadastrado
 */

if (empty($compat) || ($compat['estado'] ?? '') === CompatibilidadeService::SEM_LINHAS) {
    return;
}

$estado   = $compat['estado'];
$moto     = $compat['moto'] ?? null;
$grupos   = $compatSvc->agrupadoPorMontadora($compat['linhas']);
$totalGrp = count($grupos);
$obs      = $compat['observacoes'] ?? [];
$faixas   = $compat['faixas'] ?? [];

// Link da loja com produto e moto já escritos — o cliente não repete o que
// já disse à página. Só existe se o WhatsApp estiver cadastrado.
$waUrl = null;
if ($waCanal && !empty($waCanal['url'])) {
    $texto = 'Olá! Tenho uma dúvida sobre o produto "' . ($product['nome'] ?? '') . '"';
    if ($moto && !empty($moto['label'])) {
        $texto .= ' — minha moto é ' . $moto['label'] . '.';
    }
    $waUrl = $waCanal['url'] . '?text=' . rawurlencode($texto);
}
?>
<section class="pcm pcm--<?= View::e($estado) ?>" id="tab-compatibilidade" aria-labelledby="pcm-titulo">

  <?php if ($estado === CompatibilidadeService::CONVITE): ?>
    <h2 class="pcm__titulo" id="pcm-titulo">Serve na sua moto?</h2>
    <p class="pcm__sub">Diga qual é a sua e a gente confere no cadastro deste produto.</p>

  <?php elseif ($estado === CompatibilidadeService::SERVE): ?>
    <div class="pcm__veredito">
      <span class="pcm__marca pcm__marca--ok" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
      </span>
      <div>
        <h2 class="pcm__titulo" id="pcm-titulo">Serve na sua <?= View::e($moto['label']) ?></h2>
        <?php if ($faixas): ?>
          <p class="pcm__sub">Consta no cadastro para <?= View::e(implode(' · ', $faixas)) ?>.</p>
        <?php else: ?>
          <p class="pcm__sub">Compatibilidade confirmada no nosso cadastro.</p>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif ($estado === CompatibilidadeService::ANO_FORA): ?>
    <div class="pcm__veredito">
      <span class="pcm__marca pcm__marca--atencao" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 9v4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
      </span>
      <div>
        <h2 class="pcm__titulo" id="pcm-titulo">Confira o ano</h2>
        <p class="pcm__sub">
          Este produto consta para <strong><?= View::e($moto['modelo_nome'] ?: $moto['montadora_nome']) ?></strong><?php
            if ($faixas): ?> <strong><?= View::e(implode(' · ', $faixas)) ?></strong><?php endif; ?>.
          A sua é <strong><?= (int)$moto['ano'] ?></strong>.
        </p>
      </div>
    </div>

  <?php else: /* NAO_CONSTA */ ?>
    <div class="pcm__veredito">
      <span class="pcm__marca pcm__marca--neutro" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.6 2.6 0 0 1 5 .9c0 1.7-2.5 2.1-2.5 3.6"/><path d="M12 17h.01"/></svg>
      </span>
      <div>
        <h2 class="pcm__titulo" id="pcm-titulo">Não consta para a sua <?= View::e($moto['label']) ?></h2>
        <p class="pcm__sub">
          Isso não quer dizer que não serve — quer dizer que essa moto não está
          no cadastro deste produto. Veja abaixo o que consta, ou fale com a loja.
        </p>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($obs): ?>
    <ul class="pcm__obs">
      <?php foreach ($obs as $o): ?>
        <li><?= View::e($o) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <!-- Seletor. Fica visível também depois da resposta: trocar de moto é tão
       comum quanto informar a primeira, e esconder o controle obrigaria a
       recarregar a página. -->
  <form class="pcm__form" id="pcm-form" autocomplete="off"
        data-modelo="<?= $moto['modelo_id'] ?? '' ?>"
        data-ano="<?= $moto['ano'] ?? '' ?>">
    <?= SecurityHelper::csrfField() ?>

    <div class="pcm__campos">
      <label class="pcm__campo">
        <span class="pcm__rotulo">Marca</span>
        <select name="montadora_id" id="pcm-montadora" required>
          <option value="">Selecione</option>
          <?php foreach ($montadoras as $m): ?>
            <option value="<?= (int)$m['id'] ?>" <?= ($moto && (int)$moto['montadora_id'] === (int)$m['id']) ? 'selected' : '' ?>>
              <?= View::e($m['nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="pcm__campo">
        <span class="pcm__rotulo">Modelo</span>
        <select name="modelo_id" id="pcm-modelo" <?= $moto && $moto['montadora_id'] ? '' : 'disabled' ?>>
          <option value="">Selecione a marca</option>
        </select>
      </label>

      <label class="pcm__campo pcm__campo--ano">
        <span class="pcm__rotulo">Ano <span class="pcm__opcional">(opcional)</span></span>
        <select name="ano" id="pcm-ano" <?= $moto && $moto['modelo_id'] ? '' : 'disabled' ?>>
          <option value="">Todos</option>
        </select>
      </label>
    </div>

    <div class="pcm__acoes">
      <button type="submit" class="pcm__btn" id="pcm-conferir">Conferir</button>
      <?php if ($moto): ?>
        <button type="button" class="pcm__limpar" id="pcm-limpar">Trocar moto</button>
      <?php endif; ?>
    </div>

    <p class="pcm__erro" id="pcm-erro" role="alert" hidden></p>
  </form>

  <!-- A prova. É esta lista que o link #tab-compatibilidade prometia e nunca
       tinha destino. <details> em vez de JS: teclado e leitor de tela saem de
       graça e corretos. -->
  <details class="pcm__lista">
    <summary>
      <?php if ($compat['total_motos'] === 1): ?>
        Ver a moto que consta neste produto
      <?php else: ?>
        Ver as <?= (int)$compat['total_motos'] ?> motos que constam neste produto
      <?php endif; ?>
    </summary>

    <div class="pcm__grupos">
      <?php foreach ($grupos as $g): ?>
        <div class="pcm__grupo">
          <h3 class="pcm__montadora"><?= View::e($g['montadora']) ?></h3>
          <ul>
            <?php foreach ($g['itens'] as $it): ?>
              <li>
                <span class="pcm__modelo"><?= View::e($it['modelo'] ?? 'Toda a linha') ?></span>
                <?php if ($it['anos']): ?><span class="pcm__anos"><?= View::e($it['anos']) ?></span><?php endif; ?>
                <?php if ($it['observacao']): ?><span class="pcm__nota"><?= View::e($it['observacao']) ?></span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($totalGrp > 0): ?>
      <p class="pcm__rodape">Cadastro da loja. Na dúvida sobre a sua moto, fale com a gente antes de comprar.</p>
    <?php endif; ?>
  </details>

  <?php if ($waUrl && in_array($estado, [CompatibilidadeService::NAO_CONSTA, CompatibilidadeService::ANO_FORA], true)): ?>
    <a class="pcm__loja" href="<?= View::e($waUrl) ?>" target="_blank" rel="noopener">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-12.3 7.4L3 20.5l1.7-5.5A8.4 8.4 0 1 1 21 11.5Z"/></svg>
      Perguntar para a loja se serve
    </a>
  <?php endif; ?>
</section>
