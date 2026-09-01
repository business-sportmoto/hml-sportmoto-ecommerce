<?php
/**
 * admin/views/chat/instagram-comentarios.php
 * @var array $comentarios @var array $regras @var array $kpis @var array $filtros
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n    = fn($v) => number_format((float)$v, 0, ',', '.');
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Comentários</h1>
      <p>Tudo que chegou nos posts e o que a automação fez com cada um.</p>
    </div>
    <div class="ch-head-acoes">
      <a href="<?= $base ?>/admin/chat/instagram" class="ch-btn">← Instagram</a>
      <a href="<?= $base ?>/admin/chat/instagram/regras" class="ch-btn">Regras</a>
    </div>
  </div>

  <div class="ch-kpis">
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Comentários hoje</div>
      <div class="ch-kpi-val"><?= $n($kpis['comentarios_hoje']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Viraram direct</div>
      <div class="ch-kpi-val"><?= $n($kpis['dms_hoje']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Falhas</div>
      <div class="ch-kpi-val" style="<?= (int)$kpis['falhas_hoje'] > 0 ? 'color:var(--danger)' : '' ?>">
        <?= $n($kpis['falhas_hoje']) ?>
      </div>
    </div>
  </div>

  <form method="get" class="ch-filtros">
    <div class="ch-campo">
      <label class="ch-label">Regra</label>
      <select class="ch-select" name="regra">
        <option value="0">Todas</option>
        <?php foreach ($regras as $r): ?>
          <option value="<?= (int)$r['id'] ?>" <?= (int)$filtros['regra'] === (int)$r['id'] ? 'selected' : '' ?>>
            <?= $h($r['nome']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ch-campo" style="flex:0;">
      <label class="ch-check" style="margin-top:22px;white-space:nowrap;">
        <input type="checkbox" name="so_dm" value="1" <?= $filtros['so_dm'] ? 'checked' : '' ?>>
        <span>Só os que viraram DM</span>
      </label>
    </div>
    <div class="ch-campo" style="flex:0;">
      <label class="ch-check" style="margin-top:22px;white-space:nowrap;">
        <input type="checkbox" name="so_erro" value="1" <?= $filtros['so_erro'] ? 'checked' : '' ?>>
        <span>Só falhas</span>
      </label>
    </div>
    <div class="ch-campo" style="flex:0;">
      <button type="submit" class="ch-btn ch-btn--pri">Filtrar</button>
    </div>
  </form>

  <div class="ch-card">
    <?php if (!$comentarios): ?>
      <div class="ch-vazio">
        <strong>Nenhum comentário registrado</strong>
        <p style="max-width:52ch;margin:0 auto;">
          Comentários aparecem aqui assim que o webhook estiver assinado e alguém
          comentar num post da conta conectada.
        </p>
      </div>
    <?php else: ?>
    <div class="ch-tabela-wrap">
      <table class="ch-tabela">
        <thead><tr>
          <th>Quando</th><th>Post</th><th>Quem</th><th>Comentário</th>
          <th>Regra</th><th>O que foi feito</th>
        </tr></thead>
        <tbody>
          <?php foreach ($comentarios as $c): ?>
          <tr>
            <td class="ch-sm ch-mut" style="white-space:nowrap;">
              <?= date('d/m H:i', strtotime((string)$c['criado_em'])) ?>
            </td>
            <td>
              <?php if ($c['permalink']): ?>
                <a href="<?= $h($c['permalink']) ?>" target="_blank" rel="noopener" title="Abrir no Instagram">
                  <?php if ($c['thumb_url']): ?>
                    <img src="<?= $h($c['thumb_url']) ?>" alt="" width="34" height="34"
                         style="border-radius:5px;object-fit:cover;">
                  <?php else: ?>
                    ver post
                  <?php endif; ?>
                </a>
              <?php else: ?>
                <span class="ch-mut">—</span>
              <?php endif; ?>
            </td>
            <td class="ch-sm">
              <?php if ($c['contato_id']): ?>
                <a href="<?= $base ?>/admin/chat/contatos/<?= (int)$c['contato_id'] ?>">
                  @<?= $h($c['from_username'] ?: '?') ?>
                </a>
              <?php else: ?>
                @<?= $h($c['from_username'] ?: '?') ?>
              <?php endif; ?>
              <?php if ($c['parent_id']): ?>
                <div class="ch-mut" style="font-size:11px;">resposta em thread</div>
              <?php endif; ?>
            </td>
            <td class="ch-sm" style="max-width:280px;">
              <?= $h(mb_substr((string)$c['texto'], 0, 120)) ?>
            </td>
            <td class="ch-sm">
              <?= $c['regra_nome'] ? $h($c['regra_nome']) : '<span class="ch-mut">nenhuma</span>' ?>
            </td>
            <td class="ch-sm">
              <?php if ((int)$c['respondido_publico']): ?>
                <span class="ch-badge ch-badge--info">respondido</span>
              <?php endif; ?>
              <?php if ((int)$c['dm_enviado']): ?>
                <span class="ch-badge ch-badge--ok">DM enviado</span>
              <?php endif; ?>
              <?php if ($c['dm_erro']): ?>
                <div style="color:var(--danger);margin-top:3px;">
                  <?= $h(mb_substr((string)$c['dm_erro'], 0, 80)) ?>
                </div>
              <?php endif; ?>
              <?php if (!(int)$c['respondido_publico'] && !(int)$c['dm_enviado'] && !$c['dm_erro']): ?>
                <span class="ch-mut">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="ch-aviso ch-aviso--info ch-mt">
    <div>
      <strong class="ch-aviso-tit">Por que alguns comentários não viram DM</strong>
      As razões normais são: nenhuma regra casou, o direct daquele comentário já tinha sido
      usado (a Meta permite uma vez só), ou a pessoa bloqueou mensagens de contas que não segue.
      Nenhuma delas é erro de configuração.
    </div>
  </div>
</div>
