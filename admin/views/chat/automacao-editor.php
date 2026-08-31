<?php
/**
 * admin/views/chat/automacao-editor.php
 *
 * Editor em passos, com prévia ao vivo — o formato do ManyChat:
 *   1. Quando alguém faz um comentário
 *   2. E esse comentário possui
 *   3. Eles receberão
 *
 * Os passos que aparecem dependem da RECEITA: uma automação de story não tem
 * resposta pública, uma de live não escolhe publicação.
 *
 * @var array $automacao @var array|null $receita @var array $campos
 * @var array $pastas @var array $contas @var array $midias
 * @var array $fluxos @var array $tags @var bool $ehGestor
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$a    = $automacao;
$usa  = fn(string $c) => in_array($c, $campos, true);

$badge = ['ativa' => ['ATIVA', 'ok'], 'rascunho' => ['RASCUNHO', 'neutro'], 'parada' => ['PARADA', 'aviso']];
[$stLbl, $stCor] = $badge[$a['status']] ?? [$a['status'], 'neutro'];

// Respostas públicas são guardadas separadas por | e editadas em linhas
$variacoes = array_values(array_filter(array_map('trim',
    explode('|', (string)($a['resposta_publica'] ?? '')))));
if (!$variacoes) $variacoes = [''];

$midiasSel = array_map('strval', $a['midias'] ?? []);
$ehStory   = $a['gatilho_tipo'] === 'story_reply';
$ehLive    = $a['gatilho_tipo'] === 'live';
?>

<div class="ch">

  <?php // ── Barra superior ─────────────────────────────────────────────── ?>
  <div class="ch-fx-toolbar">
    <div class="ch-fx-titulo">
      <a href="<?= $base ?>/admin/chat/automacoes" class="ch-btn ch-btn--ico" title="Voltar">←</a>
      <input type="text" class="ch-fx-nome-input" id="ch-a-nome"
             value="<?= $h($a['nome']) ?>" maxlength="140" title="Clique para renomear">
      <span class="ch-badge ch-badge--<?= $stCor ?>" id="ch-a-status-badge"><?= $h($stLbl) ?></span>
    </div>

    <?php if ($pastas): ?>
      <select class="ch-select" id="ch-a-pasta" style="width:auto;padding:6px 10px;font-size:12.5px;">
        <option value="0">Sem pasta</option>
        <?php foreach ($pastas as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)($a['pasta_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
            <?= $h($p['nome']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <a href="<?= $base ?>/admin/chat/automacoes/<?= (int)$a['id'] ?>" class="ch-btn">Insights</a>
    <button type="button" class="ch-btn" id="ch-a-salvar">Salvar</button>
    <?php if ($a['status'] === 'ativa'): ?>
      <button type="button" class="ch-btn ch-a-status" data-status="parada">Parar</button>
    <?php else: ?>
      <button type="button" class="ch-btn ch-btn--pri ch-a-status" data-status="ativa">Ativar</button>
    <?php endif; ?>
  </div>

  <div id="ch-a-msg"></div>

  <form id="ch-form-a">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf_token ?? '') ?>">

    <div class="ch-ed">

      <?php // ══ Coluna dos passos ═══════════════════════════════════════ ?>
      <div class="ch-ed-col">

        <?php // ── Passo 1: gatilho ──────────────────────────────────── ?>
        <div class="ch-ed-passo">
          <div class="ch-ed-passo-head">
            <span class="ch-ed-passo-n">1</span>
            <span class="ch-ed-passo-tit">
              <?php if ($ehStory): ?>Quando alguém responde um Story
              <?php elseif ($ehLive): ?>Quando alguém comenta na Live
              <?php else: ?>Quando alguém faz um comentário<?php endif; ?>
            </span>
          </div>
          <div class="ch-ed-passo-body">

            <?php if ($ehStory || $ehLive): ?>
              <p class="ch-sm ch-mut" style="margin:0;">
                <?= $ehStory
                      ? 'Vale para respostas a qualquer story da conta.'
                      : 'Vale para comentários enquanto a live está no ar.' ?>
              </p>
            <?php else: ?>
              <label class="ch-ed-opcao <?= $a['escopo'] === 'midia' ? 'ativa' : '' ?>">
                <input type="radio" name="escopo" value="midia" <?= $a['escopo'] === 'midia' ? 'checked' : '' ?>>
                <span>uma publicação ou Reel específico</span>
              </label>

              <div id="ch-a-posts" style="<?= $a['escopo'] === 'midia' ? '' : 'display:none;' ?>margin:0 0 10px;">
                <?php if (!$midias): ?>
                  <div class="ch-ajuda" style="color:var(--warning);">
                    Nenhuma publicação sincronizada.
                    <a href="<?= $base ?>/admin/chat/instagram">Sincronize os posts</a> para escolher.
                  </div>
                <?php else: ?>
                  <div class="ch-ed-posts">
                    <?php foreach (array_slice($midias, 0, 40) as $m):
                      $sel = in_array((string)$m['media_id'], $midiasSel, true); ?>
                      <label class="ch-ed-post <?= $sel ? 'sel' : '' ?>" title="<?= $h(mb_substr((string)$m['legenda'], 0, 90)) ?>">
                        <input type="checkbox" name="midias[]" value="<?= $h($m['media_id']) ?>" <?= $sel ? 'checked' : '' ?>>
                        <?php if ($m['thumb_url']): ?>
                          <img src="<?= $h($m['thumb_url']) ?>" alt="" loading="lazy">
                        <?php endif; ?>
                        <?php if ($m['tipo'] === 'REELS'): ?>
                          <span class="ch-ed-post-tipo">REEL</span>
                        <?php endif; ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <div class="ch-ajuda"><span id="ch-a-nsel"><?= count($midiasSel) ?></span> selecionada(s)</div>
                <?php endif; ?>
              </div>

              <label class="ch-ed-opcao <?= $a['escopo'] === 'todas' ? 'ativa' : '' ?>">
                <input type="radio" name="escopo" value="todas" <?= $a['escopo'] === 'todas' ? 'checked' : '' ?>>
                <span>qualquer publicação ou Reel</span>
              </label>

              <label class="ch-ed-opcao <?= $a['escopo'] === 'novas' ? 'ativa' : '' ?>">
                <input type="radio" name="escopo" value="novas" <?= $a['escopo'] === 'novas' ? 'checked' : '' ?>>
                <span>
                  próxima publicação ou Reel
                  <span class="ch-ajuda" style="margin:2px 0 0;">Só o que for publicado a partir de agora.</span>
                </span>
              </label>
            <?php endif; ?>

            <?php if (count($contas) > 1): ?>
              <div class="ch-campo" style="margin-top:12px;">
                <label class="ch-label">Conta</label>
                <select class="ch-select" name="conta_id">
                  <option value="0">Todas as contas</option>
                  <?php foreach ($contas as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)($a['conta_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                      @<?= $h($c['username']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <?php // ── Passo 2: condição ─────────────────────────────────── ?>
        <div class="ch-ed-passo">
          <div class="ch-ed-passo-head">
            <span class="ch-ed-passo-n">2</span>
            <span class="ch-ed-passo-tit">
              <?= $ehStory ? 'E essa resposta possui' : 'E esse comentário possui' ?>
            </span>
          </div>
          <div class="ch-ed-passo-body">
            <label class="ch-ed-opcao <?= trim((string)$a['palavras']) !== '' ? 'ativa' : '' ?>" id="ch-op-palavra">
              <input type="radio" name="_modo_palavra" value="especifica" <?= trim((string)$a['palavras']) !== '' ? 'checked' : '' ?>>
              <span style="flex:1;">
                uma palavra ou expressão específica
                <input type="text" class="ch-input" name="palavras" id="ch-a-palavras"
                       value="<?= $h($a['palavras']) ?>" placeholder="Digite uma ou mais palavras"
                       style="margin-top:8px;">
                <span class="ch-ajuda">Use vírgulas para separar. Acentos e maiúsculas são ignorados.</span>
                <span class="ch-flex" style="margin-top:7px;gap:5px;flex-wrap:wrap;">
                  <span class="ch-sm ch-mut">Por exemplo:</span>
                  <?php foreach (['Preço', 'Link', 'Comprar', 'Quero'] as $ex): ?>
                    <button type="button" class="ch-pill ch-a-exemplo" data-p="<?= $h(mb_strtolower($ex)) ?>"><?= $h($ex) ?></button>
                  <?php endforeach; ?>
                </span>
              </span>
            </label>

            <label class="ch-ed-opcao <?= trim((string)$a['palavras']) === '' ? 'ativa' : '' ?>">
              <input type="radio" name="_modo_palavra" value="qualquer" <?= trim((string)$a['palavras']) === '' ? 'checked' : '' ?>>
              <span>qualquer palavra</span>
            </label>

            <div class="ch-campo" style="margin-top:10px;">
              <label class="ch-label">Como comparar</label>
              <select class="ch-select" name="modo_match" id="ch-a-modo">
                <option value="contem" <?= $a['modo_match'] === 'contem' ? 'selected' : '' ?>>Contém a palavra</option>
                <option value="exato"  <?= $a['modo_match'] === 'exato'  ? 'selected' : '' ?>>É exatamente a palavra</option>
                <option value="comeca" <?= $a['modo_match'] === 'comeca' ? 'selected' : '' ?>>Começa com a palavra</option>
                <option value="regex"  <?= $a['modo_match'] === 'regex'  ? 'selected' : '' ?>>Expressão regular</option>
              </select>
            </div>

            <?php if ($usa('resposta_publica')): ?>
              <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
                <label class="ch-check" style="margin-bottom:10px;">
                  <input type="checkbox" name="responder_publico" id="ch-a-rp" value="1"
                         <?= (int)$a['responder_publico'] ? 'checked' : '' ?>>
                  <span><strong>interagir com os comentários deles na publicação</strong></span>
                </label>

                <div id="ch-a-rp-box" style="<?= (int)$a['responder_publico'] ? '' : 'display:none;' ?>">
                  <div id="ch-a-variacoes">
                    <?php foreach ($variacoes as $v): ?>
                      <div class="ch-ed-var">
                        <input type="text" class="ch-input ch-a-var" value="<?= $h($v) ?>"
                               placeholder="Obrigado! Por favor, veja DMs." maxlength="180">
                        <button type="button" class="ch-fx-lista-rm" data-rm-var>&times;</button>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <button type="button" class="ch-btn ch-btn--sm" id="ch-a-add-var">+ variação</button>
                  <div class="ch-ajuda">
                    O sistema sorteia uma a cada comentário — repetir a mesma resposta
                    embaixo do post chama atenção do algoritmo.
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <?php // ── Passo 3: ação ─────────────────────────────────────── ?>
        <div class="ch-ed-passo">
          <div class="ch-ed-passo-head">
            <span class="ch-ed-passo-n">3</span>
            <span class="ch-ed-passo-tit">Eles receberão</span>
          </div>
          <div class="ch-ed-passo-body">

            <?php if ($usa('exigir_seguidor')): ?>
              <label class="ch-check" style="margin-bottom:12px;">
                <input type="checkbox" name="exigir_seguidor" id="ch-a-seg" value="1"
                       <?= (int)$a['exigir_seguidor'] ? 'checked' : '' ?>>
                <span>
                  <strong>Só entregar para quem segue o perfil</strong>
                  <div class="ch-ajuda">
                    Quem ainda não segue recebe o convite e um botão “Já segui!”.
                    O link só sai depois que a gente confirma que seguiu mesmo.
                  </div>
                </span>
              </label>

              <div class="ch-campo" id="ch-a-seg-box" style="<?= (int)$a['exigir_seguidor'] ? '' : 'display:none;' ?>">
                <label class="ch-label">Mensagem para quem ainda não segue</label>
                <textarea class="ch-textarea" name="mensagem_nao_seguidor" id="ch-a-segtxt" rows="3"
                          placeholder="Me segue aqui rapidinho que eu já te mando o link!"><?= $h($a['mensagem_nao_seguidor']) ?></textarea>
              </div>
            <?php endif; ?>

            <label class="ch-check" style="margin-bottom:10px;">
              <input type="checkbox" name="enviar_dm" id="ch-a-dm" value="1" <?= (int)$a['enviar_dm'] ? 'checked' : '' ?>>
              <span><strong>uma mensagem no direct</strong></span>
            </label>

            <div id="ch-a-dm-box" style="<?= (int)$a['enviar_dm'] ? '' : 'display:none;' ?>">
              <div class="ch-campo">
                <textarea class="ch-textarea" name="mensagem_dm" id="ch-a-dmtxt" rows="5"
                          placeholder="Oi! Vi seu comentário 😊"><?= $h($a['mensagem_dm']) ?></textarea>
                <div class="ch-ajuda">
                  Aceita <code>{{usuario}}</code>, <code>{{saudacao}}</code> e <code>{{comentario}}</code>.
                </div>
              </div>

              <div class="ch-grid-2">
                <div class="ch-campo">
                  <label class="ch-label">Link (opcional)</label>
                  <input type="url" class="ch-input" name="link_destino" id="ch-a-link"
                         value="<?= $h($a['link_destino']) ?>" placeholder="https://sportmoto.com.br/...">
                  <div class="ch-ajuda">Encurtado automaticamente para medir o CTR.</div>
                </div>
                <div class="ch-campo">
                  <label class="ch-label">Texto antes do link</label>
                  <input type="text" class="ch-input" name="link_texto" id="ch-a-ltxt"
                         value="<?= $h($a['link_texto']) ?>" placeholder="Ver agora" maxlength="60">
                </div>
              </div>
            </div>

            <?php if ($usa('tag') || $usa('fluxo')): ?>
              <div class="ch-grid-2" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">
                <?php if ($usa('tag')): ?>
                  <div class="ch-campo">
                    <label class="ch-label">Marcar com a tag</label>
                    <select class="ch-select" name="tag_id">
                      <option value="0">Nenhuma</option>
                      <?php foreach ($tags as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= (int)($a['tag_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>>
                          <?= $h($t['nome']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>
                <?php if ($usa('fluxo')): ?>
                  <div class="ch-campo">
                    <label class="ch-label">Continuar num fluxo</label>
                    <select class="ch-select" name="fluxo_id">
                      <option value="0">Nenhum</option>
                      <?php foreach ($fluxos as $f): ?>
                        <option value="<?= (int)$f['id'] ?>" <?= (int)($a['fluxo_id'] ?? 0) === (int)$f['id'] ? 'selected' : '' ?>>
                          <?= $h($f['nome']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <?php // ── Ajustes finos ──────────────────────────────────────── ?>
        <div class="ch-ed-passo">
          <div class="ch-ed-passo-head">
            <span class="ch-ed-passo-n">⚙</span>
            <span class="ch-ed-passo-tit">Ajustes</span>
          </div>
          <div class="ch-ed-passo-body">
            <label class="ch-check" style="margin-bottom:9px;">
              <input type="checkbox" name="uma_vez_por_pessoa" value="1" <?= (int)$a['uma_vez_por_pessoa'] ? 'checked' : '' ?>>
              <span>Só uma vez por pessoa</span>
            </label>
            <label class="ch-check" style="margin-bottom:9px;">
              <input type="checkbox" name="ignorar_respostas" value="1" <?= (int)$a['ignorar_respostas'] ? 'checked' : '' ?>>
              <span>Ignorar respostas dentro de threads</span>
            </label>
            <label class="ch-check" style="margin-bottom:12px;">
              <input type="checkbox" name="ignorar_proprios" value="1" <?= (int)$a['ignorar_proprios'] ? 'checked' : '' ?>>
              <span>Ignorar comentários da própria conta</span>
            </label>
            <div class="ch-campo" style="max-width:140px;margin:0;">
              <label class="ch-label">Prioridade</label>
              <input type="number" class="ch-input" name="prioridade" value="<?= (int)$a['prioridade'] ?>" min="0" max="999">
              <div class="ch-ajuda">Menor número é avaliado antes.</div>
            </div>
          </div>
        </div>
      </div>

      <?php // ══ Prévia ═════════════════════════════════════════════════ ?>
      <div class="ch-preview">
        <div>
          <div class="ch-sm ch-mut" style="text-align:center;margin-bottom:10px;">Visualização</div>
          <div class="ch-fone">
            <div class="ch-fone-tela">
              <div class="ch-fone-topo">
                <span>‹</span>
                <div style="flex:1;text-align:center;">
                  <div style="font-size:9px;opacity:.6;text-transform:uppercase;">
                    <?= $h($contas[0]['username'] ?? 'sua conta') ?>
                  </div>
                  <div style="font-weight:700;" id="ch-pv-titulo">Publicação</div>
                </div>
                <span style="opacity:.5;">⋯</span>
              </div>

              <div class="ch-fone-corpo">
                <?php // Aba publicação ?>
                <div id="ch-pv-post">
                  <div class="ch-fone-post">
                    <?php $capa = $midias[0]['thumb_url'] ?? null; ?>
                    <?php if ($capa): ?>
                      <img src="<?= $h($capa) ?>" alt="">
                    <?php else: ?>
                      <div style="aspect-ratio:1;background:#1c232e;border-radius:7px;display:grid;place-items:center;color:#59657a;font-size:12px;">
                        sem publicação sincronizada
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="ch-fone-legenda">
                    <?= $h(mb_substr((string)($midias[0]['legenda'] ?? 'Legenda da publicação'), 0, 110)) ?>
                  </div>
                  <div class="ch-fone-coment">
                    <span class="ch-fone-av"></span>
                    <span class="ch-fone-txt"><b>cliente</b> <span id="ch-pv-coment">quero</span></span>
                  </div>
                  <div class="ch-fone-coment" id="ch-pv-resp-box">
                    <span class="ch-fone-av" style="background:linear-gradient(135deg,#833ab4,#e1306c);"></span>
                    <span class="ch-fone-txt">
                      <b><?= $h($contas[0]['username'] ?? 'sua conta') ?></b>
                      <span id="ch-pv-resp">Te chamei no direct!</span>
                    </span>
                  </div>
                </div>

                <?php // Aba direct ?>
                <div id="ch-pv-dm" style="display:none;">
                  <div class="ch-fone-dm ch-fone-dm--nossa" id="ch-pv-dmtxt">Sua mensagem aparece aqui</div>
                  <div id="ch-pv-qr" style="display:none;text-align:right;">
                    <span class="ch-fone-qr">Já segui!</span>
                  </div>
                </div>
              </div>

              <div class="ch-fone-abas">
                <button type="button" class="ch-fone-aba ativa" data-pv="post">Publicar</button>
                <button type="button" class="ch-fone-aba" data-pv="dm">DM</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
  window.CHED = {
    base: '<?= $base ?>',
    csrf: '<?= $h($csrf_token ?? '') ?>',
    id:   <?= (int)$a['id'] ?>
  };
</script>
