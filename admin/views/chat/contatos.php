<?php
/**
 * admin/views/chat/contatos.php
 * @var array $contatos @var int $total @var int $pagina @var int $porPagina
 * @var array $filtros @var array $tags @var bool $podeGerir
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n    = fn($v) => number_format((float)$v, 0, ',', '.');

$paginas = (int)ceil($total / max(1, $porPagina));
$qs      = fn(array $extra = []) => '?' . http_build_query(array_merge($_GET, $extra));
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Contatos</h1>
      <p><?= $n($total) ?> contato(s) no filtro atual.</p>
    </div>
    <div class="ch-head-acoes">
      <a href="<?= $base ?>/admin/chat/tags" class="ch-btn">Tags</a>
      <?php if ($podeGerir): ?>
        <a href="<?= $base ?>/admin/chat/contatos/exportar<?= $qs() ?>" class="ch-btn">Exportar CSV</a>
      <?php endif; ?>
      <a href="<?= $base ?>/admin/chat/inbox" class="ch-btn ch-btn--wa">Atendimento</a>
    </div>
  </div>

  <form method="get" class="ch-filtros">
    <div class="ch-campo" style="flex:2 1 220px;">
      <label class="ch-label">Buscar</label>
      <input type="text" class="ch-input" name="q" value="<?= $h($filtros['busca']) ?>"
             placeholder="nome, telefone ou e-mail">
    </div>
    <div class="ch-campo">
      <label class="ch-label">Recebe mensagens</label>
      <select class="ch-select" name="optin">
        <option value="">Todos</option>
        <option value="1" <?= $filtros['optin'] === '1' ? 'selected' : '' ?>>Sim</option>
        <option value="0" <?= $filtros['optin'] === '0' ? 'selected' : '' ?>>Opt-out</option>
      </select>
    </div>
    <div class="ch-campo">
      <label class="ch-label">Janela 24h</label>
      <select class="ch-select" name="janela">
        <option value="">Todas</option>
        <option value="aberta"  <?= $filtros['janela'] === 'aberta'  ? 'selected' : '' ?>>Aberta</option>
        <option value="fechada" <?= $filtros['janela'] === 'fechada' ? 'selected' : '' ?>>Fechada</option>
      </select>
    </div>
    <div class="ch-campo">
      <label class="ch-label">Cliente da loja</label>
      <select class="ch-select" name="com_cliente">
        <option value="">Todos</option>
        <option value="1" <?= $filtros['com_cliente'] === '1' ? 'selected' : '' ?>>Sim</option>
        <option value="0" <?= $filtros['com_cliente'] === '0' ? 'selected' : '' ?>>Não</option>
      </select>
    </div>
    <div class="ch-campo">
      <label class="ch-label">Tag</label>
      <select class="ch-select" name="tags[]">
        <option value="">Todas</option>
        <?php foreach ($tags as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $filtros['tags'], true) ? 'selected' : '' ?>>
            <?= $h($t['nome']) ?> (<?= (int)$t['total'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ch-campo" style="flex:0;">
      <button type="submit" class="ch-btn ch-btn--pri">Filtrar</button>
    </div>
    <?php if (array_filter($filtros, fn($v) => $v !== '' && $v !== [])): ?>
      <div class="ch-campo" style="flex:0;">
        <a href="<?= $base ?>/admin/chat/contatos" class="ch-btn">Limpar</a>
      </div>
    <?php endif; ?>
  </form>

  <div class="ch-card">
    <?php if (!$contatos): ?>
      <div class="ch-vazio">
        <strong>Nenhum contato encontrado</strong>
        Contatos são criados sozinhos quando alguém manda mensagem para o número da loja.
      </div>
    <?php else: ?>
    <div class="ch-tabela-wrap">
      <table class="ch-tabela">
        <thead>
          <tr>
            <th>Contato</th><th>Telefone</th><th>Tags</th><th>Situação</th>
            <th class="ch-num">Msgs</th><th>Última entrada</th><th style="width:1%;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($contatos as $c): ?>
          <tr>
            <td>
              <a href="<?= $base ?>/admin/chat/contatos/<?= (int)$c['id'] ?>" class="ch-b"><?= $h($c['nome_exibicao']) ?></a>
              <?php if ($c['cliente_id']): ?>
                <span class="ch-badge ch-badge--info" title="Vinculado a um cadastro da loja">cliente</span>
              <?php endif; ?>
              <?php if ($c['email']): ?>
                <div class="ch-sm ch-mut"><?= $h($c['email']) ?></div>
              <?php endif; ?>
            </td>
            <td class="ch-mono ch-sm"><?= $h($c['telefone_exibicao'] ?: $c['wa_id']) ?></td>
            <td>
              <div class="ch-tags-linha">
                <?php foreach (array_slice($c['tags'], 0, 3) as $t): ?>
                  <span class="ch-tag" style="color:<?= $h($t['cor']) ?>;background:<?= $h($t['cor']) ?>22;"><?= $h($t['nome']) ?></span>
                <?php endforeach; ?>
                <?php if (count($c['tags']) > 3): ?>
                  <span class="ch-tag ch-badge--neutro">+<?= count($c['tags']) - 3 ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <?php if ((int)$c['bloqueado']): ?>
                <span class="ch-badge ch-badge--erro">bloqueado</span>
              <?php elseif (!(int)$c['optin']): ?>
                <span class="ch-badge ch-badge--neutro">opt-out</span>
              <?php elseif ($c['na_janela']): ?>
                <span class="ch-badge ch-badge--ok">janela aberta</span>
              <?php else: ?>
                <span class="ch-badge ch-badge--neutro">só template</span>
              <?php endif; ?>
            </td>
            <td class="ch-num ch-sm">
              <?= $n($c['total_entrada']) ?> ↓ / <?= $n($c['total_saida']) ?> ↑
            </td>
            <td class="ch-sm ch-mut">
              <?= $c['ultima_entrada_em'] ? date('d/m/Y H:i', strtotime((string)$c['ultima_entrada_em'])) : '—' ?>
            </td>
            <td>
              <?php if (!empty($c['conversa_id'])): ?>
                <a href="<?= $base ?>/admin/chat/inbox?conversa=<?= (int)$c['conversa_id'] ?>" class="ch-btn ch-btn--sm">Abrir</a>
              <?php else: ?>
                <a href="<?= $base ?>/admin/chat/contatos/<?= (int)$c['id'] ?>" class="ch-btn ch-btn--sm">Ver</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($paginas > 1): ?>
      <div class="ch-pag">
        <?php if ($pagina > 1): ?>
          <a href="<?= $qs(['pagina' => $pagina - 1]) ?>">‹</a>
        <?php endif; ?>
        <?php
        $ini = max(1, $pagina - 2);
        $fim = min($paginas, $pagina + 2);
        for ($p = $ini; $p <= $fim; $p++): ?>
          <?php if ($p === $pagina): ?>
            <span class="atual"><?= $p ?></span>
          <?php else: ?>
            <a href="<?= $qs(['pagina' => $p]) ?>"><?= $p ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($pagina < $paginas): ?>
          <a href="<?= $qs(['pagina' => $pagina + 1]) ?>">›</a>
        <?php endif; ?>
        <span class="ch-sm ch-mut" style="border:none;background:none;">de <?= $paginas ?></span>
      </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
