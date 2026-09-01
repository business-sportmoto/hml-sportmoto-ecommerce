<?php
/**
 * admin/views/chat/inbox.php
 *
 * Live chat em 3 colunas: conversas | thread | ficha do contato.
 * Toda a interação é do chat.js — aqui fica só o esqueleto e o estado inicial.
 *
 * @var array $contadores @var array $agentes @var array $tags @var array $templates
 * @var array $fluxos @var int $meuId @var int $abrirConversa
 * @var bool $envioOk @var string|null $envioErro @var string $assinatura
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>

<div class="ch">

  <?php if (!$envioOk): ?>
    <div class="ch-aviso ch-aviso--erro">
      <div>
        <strong class="ch-aviso-tit">WhatsApp não configurado — só dá para ler</strong>
        <?= $h($envioErro ?: 'Verifique as credenciais da Meta.') ?>
        <a href="<?= $base ?>/admin/chat/config">Abrir configuração</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="ch-head" style="margin-bottom:14px;">
    <div>
      <h1>Atendimento</h1>
      <p>Conversas do WhatsApp em tempo real.</p>
    </div>
    <div class="ch-head-acoes">
      <button type="button" class="ch-btn ch-btn--sm" id="ch-novo-contato">Nova conversa</button>
      <a href="<?= $base ?>/admin/chat/contatos" class="ch-btn ch-btn--sm">Contatos</a>
    </div>
  </div>

  <div class="ch-inbox" id="ch-inbox">

    <?php // ── Coluna 1: conversas ────────────────────────────────────── ?>
    <div class="ch-lista-col">
      <div class="ch-lista-head">
        <div class="ch-lista-busca">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>
          </svg>
          <input type="text" class="ch-input" id="ch-busca" placeholder="Buscar nome, telefone ou mensagem">
        </div>
        <div class="ch-lista-filtros" id="ch-filtros">
          <button type="button" class="ch-pill ativa" data-filtro="status" data-valor="">
            Todas <span class="ch-aba-n" data-cont="total"></span>
          </button>
          <button type="button" class="ch-pill" data-filtro="nao_lidas" data-valor="1">
            Não lidas <span class="ch-aba-n" data-cont="nao_lidas"><?= (int)$contadores['nao_lidas'] ?></span>
          </button>
          <button type="button" class="ch-pill" data-filtro="agente" data-valor="eu">
            Minhas <span class="ch-aba-n" data-cont="minhas"><?= (int)$contadores['minhas'] ?></span>
          </button>
          <button type="button" class="ch-pill" data-filtro="agente" data-valor="sem">
            Sem dono <span class="ch-aba-n" data-cont="sem_agente"><?= (int)$contadores['sem_agente'] ?></span>
          </button>
          <button type="button" class="ch-pill" data-filtro="status" data-valor="pendente">Pendentes</button>
          <button type="button" class="ch-pill" data-filtro="status" data-valor="resolvida">Resolvidas</button>
          <button type="button" class="ch-pill" data-filtro="canal" data-valor="whatsapp">WhatsApp</button>
          <button type="button" class="ch-pill" data-filtro="canal" data-valor="instagram">Instagram</button>
        </div>
      </div>
      <div class="ch-lista" id="ch-lista">
        <div class="ch-carregando">Carregando conversas...</div>
      </div>
    </div>

    <?php // ── Coluna 2: thread ───────────────────────────────────────── ?>
    <div class="ch-thread-col" id="ch-thread-col">
      <div id="ch-sem-conversa" style="flex:1;display:grid;place-items:center;padding:40px;">
        <div class="ch-vazio">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M21 11.5a8.4 8.4 0 01-9 8.4 8.4 8.4 0 01-3.8-.9L3 21l2-4.9A8.4 8.4 0 0121 11.5z"/>
          </svg>
          <strong>Escolha uma conversa</strong>
          Ou aguarde: mensagens novas aparecem sozinhas na lista.
        </div>
      </div>

      <div id="ch-thread" style="display:none;flex-direction:column;flex:1;min-height:0;">
        <div class="ch-thread-head">
          <button type="button" class="ch-btn ch-btn--ico ch-btn--sm" id="ch-voltar" style="display:none;">←</button>
          <div class="ch-avatar" id="ch-t-avatar"></div>
          <div class="ch-thread-id">
            <div class="ch-thread-nome" id="ch-t-nome"></div>
            <div class="ch-thread-sub" id="ch-t-sub"></div>
          </div>
          <div class="ch-thread-acoes">
            <select class="ch-select ch-btn--sm" id="ch-t-agente" style="width:auto;padding:5px 8px;font-size:12px;">
              <option value="0">Sem responsável</option>
              <?php foreach ($agentes as $a): ?>
                <option value="<?= (int)$a['id'] ?>"><?= $h($a['nome']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="ch-btn ch-btn--sm" id="ch-t-bot" title="Pausar ou retomar a automação"></button>
            <button type="button" class="ch-btn ch-btn--sm" id="ch-t-fluxo">Fluxo</button>
            <button type="button" class="ch-btn ch-btn--sm" id="ch-t-resolver">Resolver</button>
          </div>
        </div>

        <div class="ch-msgs" id="ch-msgs"></div>

        <div class="ch-comp" id="ch-comp">
          <div id="ch-comp-livre">
            <?php // Anexo escolhido, antes de enviar: dá para conferir, escrever
                  // legenda no campo de baixo, ou desistir. ?>
            <div class="ch-comp-anexo" id="ch-comp-anexo">
              <div class="ch-comp-anexo-mini" id="ch-anexo-mini"></div>
              <div class="ch-comp-anexo-id">
                <div class="ch-comp-anexo-nome" id="ch-anexo-nome"></div>
                <div class="ch-comp-anexo-meta" id="ch-anexo-meta"></div>
              </div>
              <button type="button" class="ch-comp-anexo-x" id="ch-anexo-x" title="Remover anexo">&times;</button>
            </div>

            <div class="ch-comp-linha">
              <button type="button" class="ch-comp-btn" id="ch-anexar" title="Anexar arquivo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M21.4 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.2-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/>
                </svg>
              </button>
              <?php
              // Uma linha só: o accept é lista separada por vírgula, e quebra de
              // linha dentro do atributo depende do navegador aparar o espaço.
              // O que uma plataforma não reproduz o servidor manda como arquivo,
              // então tudo daqui é enviável nos dois canais.
              $aceita = 'image/jpeg,image/png,image/gif,image/webp,'
                      . 'video/mp4,video/3gpp,video/quicktime,video/webm,video/x-msvideo,'
                      . 'audio/mpeg,audio/ogg,audio/mp4,audio/aac,audio/amr,audio/wav,audio/flac,'
                      . '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.txt,.csv,.zip';
              ?>
              <input type="file" id="ch-arquivo" style="display:none" accept="<?= $aceita ?>">
              <textarea id="ch-texto" rows="1" placeholder="Escreva uma mensagem... (Enter envia, Shift+Enter quebra linha)"></textarea>
              <button type="button" class="ch-comp-btn ch-comp-btn--enviar" id="ch-enviar" title="Enviar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
                </svg>
              </button>
            </div>
            <div class="ch-sm ch-mut" id="ch-comp-dica" style="margin-top:6px;"></div>
          </div>

          <?php // Fora da janela de 24h a Meta recusa texto livre — só template ?>
          <div id="ch-comp-fechado" style="display:none;">
            <div class="ch-comp-bloqueado">
              <strong>Janela de 24 horas fechada</strong>
              Esta pessoa não escreve para a loja há mais de 24h. A Meta só permite
              retomar o contato com um <strong>template aprovado</strong>.
              <div style="margin-top:9px;">
                <button type="button" class="ch-btn ch-btn--pri ch-btn--sm" id="ch-abrir-template">
                  Enviar template
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php // ── Coluna 3: ficha do contato ─────────────────────────────── ?>
    <div class="ch-painel" id="ch-painel">
      <div class="ch-vazio" style="padding:30px 16px;">Nenhuma conversa aberta.</div>
    </div>

  </div>
</div>

<?php // ── Modal: enviar template ───────────────────────────────────────── ?>
<div class="ch-modal" id="ch-modal-template">
  <div class="ch-modal-cx">
    <div class="ch-modal-head">
      <h3>Enviar template</h3>
      <button type="button" class="ch-modal-x" data-fechar>&times;</button>
    </div>
    <div class="ch-modal-body">
      <?php if (!$templates): ?>
        <div class="ch-aviso ch-aviso--aviso" style="margin:0;">
          <div>
            <strong class="ch-aviso-tit">Nenhum template aprovado</strong>
            Sincronize em <a href="<?= $base ?>/admin/chat/templates">Templates</a>
            ou crie um no Gerenciador do WhatsApp.
          </div>
        </div>
      <?php else: ?>
        <div class="ch-campo">
          <label class="ch-label">Template</label>
          <select class="ch-select" id="ch-tpl-nome">
            <option value="">Selecione...</option>
            <?php foreach ($templates as $t): ?>
              <option value="<?= $h($t['nome']) ?>"
                      data-idioma="<?= $h($t['idioma']) ?>"
                      data-vars="<?= (int)$t['vars_body'] ?>"
                      data-header="<?= $h($t['header_tipo'] ?: '') ?>"
                      data-varsheader="<?= (int)$t['vars_header'] ?>"
                      data-btnurl="<?= (int)$t['botoes_url'] ?>"
                      data-corpo="<?= $h($t['corpo_preview']) ?>">
                <?= $h($t['nome']) ?> (<?= $h($t['idioma']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="ch-tpl-campos"></div>
        <div id="ch-tpl-preview" style="display:none;">
          <label class="ch-label">Prévia</label>
          <div class="ch-msg ch-msg--out" style="max-width:100%;">
            <div class="ch-bolha" id="ch-tpl-preview-txt"></div>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <div class="ch-modal-pe">
      <button type="button" class="ch-btn" data-fechar>Cancelar</button>
      <?php if ($templates): ?>
        <button type="button" class="ch-btn ch-btn--pri" id="ch-tpl-enviar">Enviar</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php // ── Modal: iniciar fluxo ─────────────────────────────────────────── ?>
<div class="ch-modal" id="ch-modal-fluxo">
  <div class="ch-modal-cx" style="max-width:460px;">
    <div class="ch-modal-head">
      <h3>Iniciar fluxo</h3>
      <button type="button" class="ch-modal-x" data-fechar>&times;</button>
    </div>
    <div class="ch-modal-body">
      <?php if (!$fluxos): ?>
        <div class="ch-aviso ch-aviso--aviso" style="margin:0;">
          <div>
            <strong class="ch-aviso-tit">Nenhum fluxo publicado</strong>
            <a href="<?= $base ?>/admin/chat/fluxos">Crie e publique um fluxo</a> para poder
            dispará-lo daqui.
          </div>
        </div>
      <?php else: ?>
        <div class="ch-campo">
          <label class="ch-label">Fluxo a disparar</label>
          <select class="ch-select" id="ch-fluxo-id">
            <?php foreach ($fluxos as $f): ?>
              <option value="<?= (int)$f['id'] ?>"><?= $h($f['nome']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="ch-ajuda">
            Isso encerra qualquer fluxo em andamento com este contato e reativa a automação
            na conversa.
          </div>
        </div>
      <?php endif; ?>
    </div>
    <div class="ch-modal-pe">
      <button type="button" class="ch-btn" data-fechar>Cancelar</button>
      <?php if ($fluxos): ?>
        <button type="button" class="ch-btn ch-btn--pri" id="ch-fluxo-iniciar">Iniciar</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php // ── Modal: nova conversa ─────────────────────────────────────────── ?>
<div class="ch-modal" id="ch-modal-contato">
  <div class="ch-modal-cx" style="max-width:440px;">
    <div class="ch-modal-head">
      <h3>Nova conversa</h3>
      <button type="button" class="ch-modal-x" data-fechar>&times;</button>
    </div>
    <div class="ch-modal-body">
      <div class="ch-campo">
        <label class="ch-label">Telefone com DDD</label>
        <input type="text" class="ch-input" id="ch-nc-tel" placeholder="(51) 98765-4321">
      </div>
      <div class="ch-campo">
        <label class="ch-label">Nome (opcional)</label>
        <input type="text" class="ch-input" id="ch-nc-nome">
      </div>
      <div class="ch-aviso ch-aviso--info" style="margin-bottom:0;">
        <div>
          Como a pessoa ainda não escreveu para a loja, a janela de 24h está fechada:
          a primeira mensagem terá que ser um <strong>template aprovado</strong>.
        </div>
      </div>
      <div id="ch-nc-msg" class="ch-sm" style="margin-top:10px;"></div>
    </div>
    <div class="ch-modal-pe">
      <button type="button" class="ch-btn" data-fechar>Cancelar</button>
      <button type="button" class="ch-btn ch-btn--pri" id="ch-nc-criar">Criar</button>
    </div>
  </div>
</div>

<script>
  window.CH = {
    base:      '<?= $base ?>',
    csrf:      '<?= $h($csrf_token ?? '') ?>',
    meuId:     <?= (int)$meuId ?>,
    abrir:     <?= (int)$abrirConversa ?>,
    canal:     '<?= $h($canalInicial ?? '') ?>',
    envioOk:   <?= $envioOk ? 'true' : 'false' ?>,
    assinatura: <?= json_encode((string)($assinatura ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    agentes:   <?= json_encode($agentes, JSON_UNESCAPED_UNICODE) ?>,
    tags:      <?= json_encode($tags, JSON_UNESCAPED_UNICODE) ?>
  };
</script>
