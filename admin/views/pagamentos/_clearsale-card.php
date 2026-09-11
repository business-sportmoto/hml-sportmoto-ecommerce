<?php
// ════════════════════════════════════════════════════════
// admin/views/pagamentos/_clearsale-card.php
//
// O card da ClearSale na tela de análise. Um template só, usado em dois
// lugares: a página (analise-detalhe.php inclui) e a resposta do botão
// (AdminAnaliseController::consultarClearSale devolve este HTML e o JS troca
// o card inteiro). Assim não existe uma versão PHP e outra JS para divergir.
//
// Recebe: $antifraude (última linha de pgto_antifraude ou null),
//         $clearsale  (['configurado' => bool, 'ambiente' => 'sandbox'|'prod']),
//         $csMensagem, $csMensagemOk (opcionais — resultado do último clique).
// ════════════════════════════════════════════════════════

$cs      = $clearsale ?? ['configurado' => false, 'ambiente' => 'sandbox'];
$csProd  = ($cs['ambiente'] ?? '') === 'prod';
$af      = $antifraude ?? null;
$enviado = $af && !empty($af['enviado_em']) && ($af['status'] ?? '') !== 'erro';
$falhou  = $af && ($af['status'] ?? '') === 'erro';

$st    = $enviado ? ClearSaleApresentacao::status($af['codigo_status'] ?? null) : null;
$score = ($enviado && ($af['score'] ?? null) !== null) ? (float) $af['score'] : null;
$faixa = ClearSaleApresentacao::faixa($score);
$acao  = $enviado ? ClearSaleApresentacao::oQueFazer($af['codigo_status'] ?? null, !$csProd) : '';

$quando = static fn($d) => $d ? date('d/m H:i', strtotime((string) $d)) : '—';
?>
<div class="admin-card cs-card" id="cs-card" aria-live="polite">
  <div class="cs-head">
    <h3 class="cs-titulo">ClearSale</h3>
    <span class="badge <?= $csProd ? 'badge-danger' : 'badge-info' ?>"><?= $csProd ? 'Produção' : 'Homologação' ?></span>
  </div>

  <?php if (!empty($csMensagem)): ?>
    <p class="cs-aviso cs-aviso--<?= !empty($csMensagemOk) ? 'ok' : 'erro' ?>" role="status">
      <?= View::e((string) $csMensagem) ?>
    </p>
  <?php endif; ?>

  <?php if ($enviado): ?>
    <div class="cs-corpo">

      <?php
        $rotuloMedidor = $score !== null
            ? sprintf('Score %s de %d — %s', number_format($score, 0), ClearSaleApresentacao::SCORE_MAX, $faixa['rotulo'])
            : 'Sem score ainda';
        [$mx, $my] = $score !== null
            ? ClearSaleApresentacao::ponto($score / ClearSaleApresentacao::SCORE_MAX)
            : [null, null];
      ?>
      <figure class="cs-medidor" role="img" aria-label="<?= View::e($rotuloMedidor) ?>">
        <?php
          // Atributos de apresentação no próprio SVG: sem o CSS (cache velho,
          // folha que não carregou), <path> pinta o interior de PRETO e o
          // medidor vira mancha. Com eles, degrada para cinza legível; o CSS
          // do painel sobrescreve com as cores de cada faixa.
        ?>
        <svg viewBox="0 0 120 68" class="cs-medidor-svg" aria-hidden="true" focusable="false"
             width="200" height="113">
          <?php foreach (ClearSaleApresentacao::trechos() as $tr): ?>
            <path d="<?= $tr['d'] ?>" class="cs-trecho cs-tom--<?= $tr['tom'] ?>"
                  fill="none" stroke="#94a3b8" stroke-width="10" />
          <?php endforeach; ?>
          <?php if ($mx !== null): ?>
            <circle cx="<?= $mx ?>" cy="<?= $my ?>" r="7" class="cs-marca"
                    fill="#ffffff" stroke="#94a3b8" stroke-width="1.5" />
            <circle cx="<?= $mx ?>" cy="<?= $my ?>" r="3.5" class="cs-marca-miolo cs-tom--<?= $faixa['tom'] ?>"
                    fill="#475569" />
          <?php endif; ?>
        </svg>
        <figcaption class="cs-medidor-texto">
          <?php if ($score !== null): ?>
            <span class="cs-numero"><?= number_format($score, 0) ?></span>
            <span class="cs-de">de <?= ClearSaleApresentacao::SCORE_MAX ?></span>
            <span class="cs-faixa cs-tom--<?= $faixa['tom'] ?>"><?= View::e($faixa['rotulo']) ?></span>
          <?php else: ?>
            <span class="cs-sem-score">sem score</span>
          <?php endif; ?>
        </figcaption>
      </figure>

      <div class="cs-leitura">
        <div class="cs-status cs-tom--<?= $st['tom'] ?>">
          <span class="cs-status-cod"><?= View::e($st['codigo']) ?></span>
          <strong><?= View::e($st['titulo']) ?></strong>
        </div>
        <p class="cs-explica"><?= View::e($st['explicacao']) ?></p>

        <div class="cs-acao">
          <span class="cs-rotulo">O que fazer</span>
          <p><?= View::e($acao) ?></p>
        </div>
      </div>
    </div>

    <dl class="cs-meta">
      <div><dt>Enviado</dt><dd><?= $quando($af['enviado_em'] ?? null) ?></dd></div>
      <div><dt>Último parecer</dt><dd><?= $quando($af['respondido_em'] ?? null) ?></dd></div>
      <div><dt>Consultas</dt><dd><?= (int) ($af['consultas'] ?? 0) ?></dd></div>
    </dl>

  <?php elseif ($falhou): ?>
    <div class="cs-vazio cs-vazio--erro">
      <strong>O último envio falhou.</strong>
      <p><?= View::e(mb_strimwidth((string) ($af['motivo'] ?? 'Sem detalhe.'), 0, 220, '…')) ?></p>
      <p>Corrija o que a ClearSale apontou e envie de novo.</p>
    </div>

  <?php else: ?>
    <div class="cs-vazio">
      <strong>Este pedido ainda não foi enviado à ClearSale.</strong>
      <p>Envie para receber a análise de risco. A ClearSale classifica depois de receber — o parecer e o score aparecem ao consultar de novo, alguns minutos depois.</p>
    </div>
  <?php endif; ?>

  <?php if (empty($cs['configurado'])): ?>
    <p class="cs-aviso cs-aviso--erro">Sem credencial da ClearSale configurada.</p>
  <?php else: ?>
    <div class="cs-acoes">
      <button type="button" class="btn btn-outline" id="btn-clearsale"
              data-modo="<?= $enviado ? 'consulta' : 'envio' ?>"
              data-prod="<?= $csProd ? '1' : '0' ?>">
        <?= $enviado ? 'Consultar parecer' : 'Enviar à ClearSale' ?>
      </button>
      <span class="cs-rodape">Consultar não libera nem recusa o pedido — a decisão continua sendo sua, logo abaixo.</span>
    </div>
  <?php endif; ?>

  <?php if ($enviado && $score !== null): ?>
    <p class="cs-escala"><?= View::e(ClearSaleApresentacao::legendaEscala()) ?></p>
  <?php endif; ?>
</div>
