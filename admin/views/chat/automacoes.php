<?php
/**
 * admin/views/chat/automacoes.php
 * @var array $automacoes @var int $total @var int $pagina @var int $porPagina
 * @var array $filtros @var array $pastas @var array $contadores @var array $gatilhos
 * @var bool $ehGestor @var bool $contaOk
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n    = fn($v) => number_format((float)$v, 0, ',', '.');

$paginas = (int)ceil($total / max(1, $porPagina));
$qs = fn(array $e = []) => '?' . http_build_query(array_merge($_GET, $e));

$badgeStatus = [
    'ativa'    => ['ATIVA',    'ok'],
    'rascunho' => ['RASCUNHO', 'neutro'],
    'parada'   => ['PARADA',   'neutro'],
];

/** "há 5 meses" — a listagem do ManyChat mostra assim, e é mais legível. */
$haQuanto = function (?string $dt) {
    if (!$dt) return '—';
    $s = time() - strtotime($dt);
    if ($s < 60)      return 'agora';
    if ($s < 3600)    return 'há ' . intdiv($s, 60) . ' min';
    if ($s < 86400)   return 'há ' . intdiv($s, 3600) . 'h';
    if ($s < 2592000) return 'há ' . intdiv($s, 86400) . ' dias';
    $m = intdiv($s, 2592000);
    if ($m < 12)      return 'há ' . $m . ($m === 1 ? ' mês' : ' meses');
    $a = intdiv($m, 12);
    return 'há ' . $a . ($a === 1 ? ' ano' : ' anos');
};
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Automações</h1>
      <p>Regras que respondem comentários, stories e lives do Instagram.</p>
    </div>
    <div class="ch-head-acoes">
      <a href="<?= $base ?>/admin/chat/instagram" class="ch-btn">Conta</a>
      <a href="<?= $base ?>/admin/chat/instagram/comentarios" class="ch-btn">Comentários</a>
      <a href="<?= $base ?>/admin/chat/automacoes/nova" class="ch-btn ch-btn--pri">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        Nova Automação
      </a>
    </div>
  </div>

  <?php if (!$contaOk): ?>
    <div class="ch-aviso ch-aviso--erro">
      <div>
        <strong class="ch-aviso-tit">Nenhuma conta do Instagram conectada</strong>
        As automações não disparam sem uma conta ativa.
        <a href="<?= $base ?>/admin/chat/instagram">Conectar agora</a>
      </div>
    </div>
  <?php endif; ?>

  <?php // ── Filtros ────────────────────────────────────────────────────── ?>
  <form method="get" class="ch-filtros">
    <?php if (!empty($filtros['lixeira'])): ?><input type="hidden" name="lixeira" value="1"><?php endif; ?>
    <?php // Sem rótulos acima dos campos: o texto do placeholder já diz o que
          // cada controle faz, e a linha fica com a mesma altura do início ao fim. ?>
    <div class="ch-campo ch-busca" style="flex:2 1 240px;">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input type="text" class="ch-input" name="q" value="<?= $h($filtros['busca']) ?>"
             placeholder="Pesquisar todas as automações" aria-label="Pesquisar automações">
    </div>
    <div class="ch-campo">
      <select class="ch-select" name="gatilho" aria-label="Gatilho" data-auto>
        <option value="">Qualquer gatilho</option>
        <?php foreach ($gatilhos as $g => $meta): ?>
          <option value="<?= $h($g) ?>" <?= $filtros['gatilho'] === $g ? 'selected' : '' ?>>
            <?= $h($meta['rotulo']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ch-campo">
      <select class="ch-select" name="status" aria-label="Estado do gatilho" data-auto>
        <option value="">Estados variados do gatilho</option>
        <option value="ativa"    <?= $filtros['status'] === 'ativa'    ? 'selected' : '' ?>>Ativa</option>
        <option value="rascunho" <?= $filtros['status'] === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
        <option value="parada"   <?= $filtros['status'] === 'parada'   ? 'selected' : '' ?>>Parada</option>
      </select>
    </div>
    <?php // Botão só aparece sem JS: com JS os selects já aplicam sozinhos e a
          // busca vai no Enter, como na referência. ?>
    <noscript><button type="submit" class="ch-btn">Filtrar</button></noscript>
  </form>

  <?php // ── Pastas ─────────────────────────────────────────────────────── ?>
  <div class="ch-pastas">
    <a href="<?= $base ?>/admin/chat/automacoes"
       class="ch-pasta <?= $filtros['pasta_id'] === null && empty($filtros['lixeira']) ? 'ativa' : '' ?>">
      <span class="ch-pasta-ico" style="background:var(--text-3)"></span>
      Todas <span class="ch-aba-n"><?= $n($contadores['todas']) ?></span>
    </a>

    <?php foreach ($pastas as $p): ?>
      <a href="<?= $base ?>/admin/chat/automacoes?pasta=<?= (int)$p['id'] ?>"
         class="ch-pasta <?= (int)($filtros['pasta_id'] ?? -1) === (int)$p['id'] ? 'ativa' : '' ?>"
         data-pasta="<?= (int)$p['id'] ?>">
        <span class="ch-pasta-ico" style="background:<?= $h($p['cor']) ?>"></span>
        <?= $h($p['nome']) ?>
        <span class="ch-aba-n"><?= $n($p['total']) ?></span>
        <?php if ($ehGestor || $p['usuario_id'] !== null): ?>
          <button type="button" class="ch-pasta-x" data-excluir-pasta="<?= (int)$p['id'] ?>"
                  data-nome="<?= $h($p['nome']) ?>" title="Excluir pasta">&times;</button>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>

    <a href="<?= $base ?>/admin/chat/automacoes?pasta=0"
       class="ch-pasta <?= (int)($filtros['pasta_id'] ?? -1) === 0 ? 'ativa' : '' ?>">
      <span class="ch-pasta-ico" style="background:var(--border2)"></span>
      Sem pasta
    </a>

    <button type="button" class="ch-pasta ch-pasta--nova" id="ch-nova-pasta">+ Nova Pasta</button>
  </div>

  <?php // ── Abas de estado + lixeira ───────────────────────────────────── ?>
  <div class="ch-flex-sb" style="margin-bottom:12px;flex-wrap:wrap;gap:10px;">
    <div class="ch-lista-filtros">
      <?php
      $abas = [
        ''         => ['Todas',     $contadores['todas']],
        'ativa'    => ['Ativas',    $contadores['ativa']],
        'rascunho' => ['Rascunhos', $contadores['rascunho']],
        'parada'   => ['Paradas',   $contadores['parada']],
      ];
      foreach ($abas as $v => [$lbl, $qtd]): ?>
        <a href="<?= $qs(['status' => $v, 'pagina' => 1, 'lixeira' => null]) ?>"
           class="ch-pill <?= (!$filtros['lixeira'] && $filtros['status'] === $v) ? 'ativa' : '' ?>"
           style="text-decoration:none;">
          <?= $h($lbl) ?> <span class="ch-aba-n"><?= $n($qtd) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <a href="<?= $base ?>/admin/chat/automacoes?lixeira=1"
       class="ch-btn ch-btn--sm <?= !empty($filtros['lixeira']) ? 'ch-btn--pri' : 'ch-btn--texto' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>
      Lixeira <?= (int)$contadores['lixeira'] > 0 ? '(' . $n($contadores['lixeira']) . ')' : '' ?>
    </a>
  </div>

  <?php // ── Lista ──────────────────────────────────────────────────────── ?>
  <?php if (!$automacoes): ?>
    <div class="ch-card">
      <div class="ch-vazio">
        <?php if (!empty($filtros['lixeira'])): ?>
          <strong>Lixeira vazia</strong>
          Automações excluídas ficam aqui e podem ser restauradas.
        <?php elseif ($filtros['busca'] || $filtros['status'] || $filtros['gatilho'] || $filtros['pasta_id'] !== null): ?>
          <strong>Nenhuma automação neste filtro</strong>
          <div style="margin-top:12px;">
            <a href="<?= $base ?>/admin/chat/automacoes" class="ch-btn ch-btn--sm">Limpar filtros</a>
          </div>
        <?php else: ?>
          <strong>Nenhuma automação ainda</strong>
          <p style="max-width:54ch;margin:0 auto 16px;">
            Comece por um modelo pronto — "comente QUERO e receba no direct" leva
            menos de um minuto para configurar.
          </p>
          <a href="<?= $base ?>/admin/chat/automacoes/nova" class="ch-btn ch-btn--pri">Criar a primeira</a>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>

    <?php // Cabeçalho das colunas fora dos cartões. As larguras vivem no CSS
          // (.ch-aut-cab-nums e .ch-aut-nums usam o mesmo valor). ?>
    <div class="ch-aut-cab">
      <span class="ch-aut-cab-nome">Nome</span>
      <span class="ch-aut-cab-nums">
        <span>Execuções</span>
        <span>CTR</span>
        <span>Modificado</span>
      </span>
    </div>

    <div class="ch-aut-lista">
      <?php foreach ($automacoes as $a):
        [$stLbl, $stCor] = $badgeStatus[$a['status']] ?? [$a['status'], 'neutro'];
        $naLixeira = !empty($a['excluido_em']);
      ?>
      <div class="ch-aut <?= $a['status'] === 'ativa' ? '' : 'ch-aut--inativa' ?>" data-id="<?= (int)$a['id'] ?>">
        <div class="ch-aut-topo">
          <span class="ch-badge ch-badge--estado ch-badge--<?= $stCor ?>"><?= $h($stLbl) ?></span>

          <a href="<?= $base ?>/admin/chat/automacoes/<?= (int)$a['id'] ?>" class="ch-aut-nome">
            <?= $h($a['nome']) ?>
          </a>

          <?php if ($a['pasta_nome']): ?>
            <span class="ch-tag" style="color:<?= $h($a['pasta_cor']) ?>;background:<?= $h($a['pasta_cor']) ?>22;">
              <?= $h($a['pasta_nome']) ?>
            </span>
          <?php endif; ?>

          <div class="ch-aut-nums">
            <span title="Execuções"><?= $n($a['total_envios']) ?></span>
            <span title="Taxa de clique">
              <?= $a['ctr'] !== null ? number_format($a['ctr'], 0, ',', '.') . '%' : '—' ?>
            </span>
            <span class="ch-mut" title="Última alteração"><?= $h($haQuanto($a['atualizado_em'])) ?></span>
          </div>

          <div class="ch-aut-acoes">
            <?php if ($naLixeira): ?>
              <button type="button" class="ch-btn ch-btn--sm ch-restaurar" data-id="<?= (int)$a['id'] ?>">Restaurar</button>
              <?php if ($ehGestor): ?>
                <button type="button" class="ch-btn ch-btn--sm ch-btn--perigo ch-remover"
                        data-id="<?= (int)$a['id'] ?>" data-nome="<?= $h($a['nome']) ?>">Apagar</button>
              <?php endif; ?>
            <?php else: ?>
              <?php if ($a['status'] === 'ativa'): ?>
                <button type="button" class="ch-btn ch-btn--sm ch-status" data-id="<?= (int)$a['id'] ?>" data-status="parada">Parar</button>
              <?php else: ?>
                <button type="button" class="ch-btn ch-btn--sm ch-btn--wa ch-status" data-id="<?= (int)$a['id'] ?>" data-status="ativa">Ativar</button>
              <?php endif; ?>
              <a href="<?= $base ?>/admin/chat/automacoes/<?= (int)$a['id'] ?>/editar" class="ch-btn ch-btn--sm">Editar</a>
              <button type="button" class="ch-btn ch-btn--sm ch-menu-btn" data-id="<?= (int)$a['id'] ?>">⋯</button>
            <?php endif; ?>
          </div>
        </div>

        <div class="ch-aut-sub">
          <span class="ch-aut-gat"><?= $a['gatilho_icone'] ?></span>
          <?= $h($a['gatilho_rotulo']) ?>
          <?php if ($a['palavras']): ?>
            e o comentário contém <strong class="ch-mono"><?= $h(mb_substr($a['palavras'], 0, 50)) ?></strong>
          <?php else: ?>
            <span class="ch-mut">— qualquer comentário</span>
          <?php endif; ?>

          <?php if ($a['escopo'] === 'midia'): ?>
            <span class="ch-mut">· <?= count($a['midias']) ?> publicação(ões)</span>
          <?php endif; ?>

          <?php if ($a['dono_nome']): ?>
            <span class="ch-aut-dono" title="Criada por">· <?= $h($a['dono_nome']) ?></span>
          <?php elseif (!$naLixeira): ?>
            <span class="ch-aut-dono" title="Sem dono definido">· do time</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($paginas > 1): ?>
      <div class="ch-pag">
        <?php if ($pagina > 1): ?><a href="<?= $qs(['pagina' => $pagina - 1]) ?>">‹</a><?php endif; ?>
        <?php for ($p = max(1, $pagina - 2); $p <= min($paginas, $pagina + 2); $p++): ?>
          <?= $p === $pagina ? '<span class="atual">' . $p . '</span>'
                             : '<a href="' . $qs(['pagina' => $p]) . '">' . $p . '</a>' ?>
        <?php endfor; ?>
        <?php if ($pagina < $paginas): ?><a href="<?= $qs(['pagina' => $pagina + 1]) ?>">›</a><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php // ── Menu de contexto ─────────────────────────────────────────────── ?>
<div class="ch-menu" id="ch-menu">
  <button type="button" data-acao="duplicar">Duplicar</button>
  <button type="button" data-acao="mover">Mover para pasta</button>
  <button type="button" data-acao="rascunho">Voltar para rascunho</button>
  <?php if ($ehGestor): ?><button type="button" data-acao="transferir">Trocar dono</button><?php endif; ?>
  <button type="button" data-acao="excluir" class="perigo">Excluir</button>
</div>

<?php // ── Modal: pasta ─────────────────────────────────────────────────── ?>
<div class="ch-modal" id="ch-modal-pasta">
  <div class="ch-modal-cx" style="max-width:400px;">
    <div class="ch-modal-head">
      <h3>Nova pasta</h3>
      <button type="button" class="ch-modal-x" data-fechar>&times;</button>
    </div>
    <div class="ch-modal-body">
      <div class="ch-campo">
        <label class="ch-label">Nome</label>
        <input type="text" class="ch-input" id="ch-pasta-nome" maxlength="80" placeholder="Ex.: Natal, Lançamentos">
      </div>
      <div class="ch-campo">
        <label class="ch-label">Cor</label>
        <input type="color" class="ch-input" id="ch-pasta-cor" value="#2563eb" style="height:40px;padding:4px;">
      </div>
      <div id="ch-pasta-erro" class="ch-sm" style="color:var(--danger);"></div>
    </div>
    <div class="ch-modal-pe">
      <button type="button" class="ch-btn" data-fechar>Cancelar</button>
      <button type="button" class="ch-btn ch-btn--pri" id="ch-pasta-salvar">Criar</button>
    </div>
  </div>
</div>

<?php // ── Modal: mover / transferir ────────────────────────────────────── ?>
<div class="ch-modal" id="ch-modal-mover">
  <div class="ch-modal-cx" style="max-width:400px;">
    <div class="ch-modal-head">
      <h3 id="ch-mover-titulo">Mover para pasta</h3>
      <button type="button" class="ch-modal-x" data-fechar>&times;</button>
    </div>
    <div class="ch-modal-body">
      <div class="ch-campo" id="ch-mover-pasta-box">
        <label class="ch-label">Pasta</label>
        <select class="ch-select" id="ch-mover-pasta">
          <option value="0">Sem pasta</option>
          <?php foreach ($pastas as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= $h($p['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($ehGestor): ?>
      <div class="ch-campo" id="ch-mover-dono-box" style="display:none;">
        <label class="ch-label">Novo dono</label>
        <select class="ch-select" id="ch-mover-dono">
          <option value="0">Do time (sem dono)</option>
          <?php foreach (($donos ?? []) as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= $h($d['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="ch-ajuda">Quem não é gestor passa a ver esta automação só se for o dono.</div>
      </div>
      <?php endif; ?>
    </div>
    <div class="ch-modal-pe">
      <button type="button" class="ch-btn" data-fechar>Cancelar</button>
      <button type="button" class="ch-btn ch-btn--pri" id="ch-mover-salvar">Salvar</button>
    </div>
  </div>
</div>

<script>
  window.CHAUT = {
    base: '<?= $base ?>',
    csrf: '<?= $h($csrf_token ?? '') ?>',
    gestor: <?= $ehGestor ? 'true' : 'false' ?>
  };
</script>
