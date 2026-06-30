/**
 * clip-card-btn.js
 *
 * Ao clicar no botão .pc-clip-btn[data-clip-produto],
 * busca os clips do produto e abre o ClipFeed.
 *
 * Inclua APÓS clips.js (ClipFeed precisa estar no window).
 *
 *   <script src="clips.js" defer></script>
 *   <script src="clip-card-btn.js" defer></script>
 */

/**
 * Clips v2
 * - Comentários em tempo real no feed
 * - Múltiplos produtos (carrossel)
 * - Áudio ao clicar no play
 * - Share box com redes sociais + URL direta
 * - Auto-open via site.com/clip/{id}
 */
;(function (window, $) {
  'use strict';

  const BASE = BASE_URL   || '';
  const CSRF = CSRF_TOKEN || '';

  

  // ── Utilitários ────────────────────────────────────────
  const Utils = {
    fmt: n => { n = parseInt(n)||0; return n>=1000 ? (n/1000).toFixed(1)+'k' : String(n); },
    esc: s  => { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; },
    post: (url, data, cb) => {
      data._csrf_token = CSRF;
      $.post(url, data, cb, 'json').fail(() => console.warn('Clip req failed:', url));
    },
    timeAgo: dt => {
      const s = Math.floor((Date.now()-new Date(dt))/1000);
      if (s<60)    return 'agora';
      if (s<3600)  return Math.floor(s/60)+'min';
      if (s<86400) return Math.floor(s/3600)+'h';
      return Math.floor(s/86400)+'d';
    },
    copyToClipboard: async text => {
      try { await navigator.clipboard.writeText(text); return true; }
      catch(e) {
        const ta=document.createElement('textarea');
        ta.value=text; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); document.body.removeChild(ta); return true;
      }
    },
  };

  // ════════════════════════════════════════════════════════
  // ClipActions
  // ════════════════════════════════════════════════════════
  const ClipActions = {
    _viewedIds: new Set(JSON.parse(sessionStorage.getItem('clips_viewed')||'[]')),

    registrarView(id) {
      if (this._viewedIds.has(id)) return;
      this._viewedIds.add(id);
      sessionStorage.setItem('clips_viewed', JSON.stringify([...this._viewedIds]));
      const fd=new FormData(); fd.append('id',id);
      navigator.sendBeacon
        ? navigator.sendBeacon(`${BASE}/clips/view`, fd)
        : Utils.post(`${BASE}/clips/view`,{id},()=>{});
    },

    toggleLike(id, $btn, $count) {
      const was = $btn.hasClass('is-liked');
      $btn.toggleClass('is-liked');
      const cur = parseInt($count.text())||0;
      $count.text(Utils.fmt(was ? Math.max(0,cur-1) : cur+1));
      Utils.post(`${BASE}/clips/like`,{id},res=>{
        if (res.ok) { $count.text(Utils.fmt(res.total)); $btn.toggleClass('is-liked',res.curtiu); }
        else { $btn.toggleClass('is-liked',was); $count.text(Utils.fmt(cur)); if(res.msg)alert(res.msg); }
      });
    },
  };

  /**
   * clip-card-btn.js
   *
   * Ao clicar no botão .pc-clip-btn[data-clip-produto],
   * busca os clips do produto e abre o ClipFeed.
   *
   * Inclua APÓS clips.js (ClipFeed precisa estar no window).
   *
   *   <script src="clips.js" defer></script>
   *   <script src="clip-card-btn.js" defer></script>
   */

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.pc-clip-btn[data-clip-produto]') ?? e.target.closest('.coringa-item-clip[data-clip-produto]');
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation(); // não propaga para o link do card

    const produtoId = parseInt(btn.dataset.clipProduto, 10);
    if (!produtoId) return;

    // Feedback visual imediato
    btn.classList.add('is-loading');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = `
      <span class="pc-clip-btn__icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="white"
             stroke-width="3" stroke-linecap="round" style="animation:pc-spin .8s linear infinite">
          <path d="M21 12a9 9 0 11-6.219-8.56"/>
        </svg>
      </span>
      <span class="pc-clip-btn__label">Carregando…</span>`;

    fetch(`${BASE}/clips/feed?produto_id=${produtoId}`).then(r => r.json())
      .then(function (data) {
        btn.innerHTML = originalHTML;
        btn.classList.remove('is-loading');

        if (!data.ok || !data.clips || !data.clips.length) {
          // Fallback: redireciona para a página do produto
          window.location.href = btn.closest('a')?.href || `${BASE}/produto/`;
          return;
        }

        if (window.ClipFeed) {
          window.ClipFeed.abrir(data.clips, 0, produtoId);
        }
      })
      .catch(function () {
        btn.innerHTML = originalHTML;
        btn.classList.remove('is-loading');
      });
  });

  // Spin keyframe injetado uma vez
  if (!document.getElementById('pc-clip-spin-style')) {
    const style = document.createElement('style');
    style.id = 'pc-clip-spin-style';
    style.textContent = '@keyframes pc-spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(style);
  }


  // ════════════════════════════════════════════════════════
  // ClipShare — box com redes sociais
  // ════════════════════════════════════════════════════════
  const ClipShare = {
    _clipId: null,
    _url:    null,

    init() {
      if (document.getElementById('clip-share-box')) return;
      document.body.insertAdjacentHTML('beforeend', `
<div id="clip-share-box" class="clip-share-box" hidden>
  <div class="clip-share-backdrop"></div>
  <div class="clip-share-panel">
    <div class="clip-share-header">
      <span>Compartilhar</span>
      <button type="button" id="clip-share-close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="clip-share-networks">
      <button class="clip-share-net" data-net="whatsapp">
        <div class="clip-share-net-icon" style="background:#25D366">
          <svg viewBox="0 0 24 24" fill="white"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
        </div>
        WhatsApp
      </button>
      <button class="clip-share-net" data-net="facebook">
        <div class="clip-share-net-icon" style="background:#1877F2">
          <svg viewBox="0 0 24 24" fill="white"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
        </div>
        Facebook
      </button>
      <button class="clip-share-net" data-net="twitter">
        <div class="clip-share-net-icon" style="background:#000">
          <svg viewBox="0 0 24 24" fill="white"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        </div>
        X / Twitter
      </button>
      <button class="clip-share-net" data-net="telegram">
        <div class="clip-share-net-icon" style="background:#2CA5E0">
          <svg viewBox="0 0 24 24" fill="white"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.96 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
        </div>
        Telegram
      </button>
    </div>
    <div class="clip-share-link-row">
      <div class="clip-share-link-icon">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/>
          <path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/>
        </svg>
      </div>
      <input type="text" id="clip-share-link-input" readonly class="clip-share-link-input">
      <button type="button" id="clip-share-copy-btn" class="clip-share-copy-btn">Copiar</button>
    </div>
  </div>
</div>`);

      const $box = $('#clip-share-box');
      $box.find('.clip-share-backdrop, #clip-share-close').on('click', ()=>this.fechar());

      $box.find('.clip-share-net').on('click', function() {
        const net = this.dataset.net;
        const url = ClipShare._url || '';
        const txt = encodeURIComponent('Veja este clip: '+url);
        const map = {
          whatsapp: `https://wa.me/?text=${txt}`,
          facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`,
          twitter:  `https://twitter.com/intent/tweet?text=${txt}`,
          telegram: `https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent('Veja este clip!')}`,
        };
        if (map[net]) window.open(map[net],'_blank','width=600,height=500');
        if (ClipShare._clipId) Utils.post(`${BASE}/clips/share`,{id:ClipShare._clipId},()=>{});
        ClipShare.fechar();
      });

      $('#clip-share-copy-btn').on('click', async function() {
        await Utils.copyToClipboard($('#clip-share-link-input').val());
        const $b=$(this); $b.text('Copiado ✓').addClass('is-copied');
        setTimeout(()=>$b.text('Copiar').removeClass('is-copied'),2000);
        if (ClipShare._clipId) Utils.post(`${BASE}/clips/share`,{id:ClipShare._clipId},()=>{});
      });

      $(document).on('keydown',e=>{if(e.key==='Escape')this.fechar();});
    },

    abrir(clipId, clipUrl) {
      this._clipId = clipId;
      this._url    = clipUrl || `${BASE}/clip/${clipId}`;
      $('#clip-share-link-input').val(this._url);
      $('#clip-share-box').prop('hidden',false);
      document.body.style.overflow='hidden';
    },

    fechar() {
      $('#clip-share-box').prop('hidden',true);
      document.body.style.overflow='';
    },
  };

  // ════════════════════════════════════════════════════════
  // ClipComments
  // ════════════════════════════════════════════════════════
  const ClipComments = {
    _clipId:  null,
    _page:    1,
    _loading: false,

    init() {
      const $form = $('#clips-comments-form');
      const $list = $('#clips-comments-list');

      $('#clips-comments-backdrop, #clips-comments-close').on('click', ()=>this.fechar());

      $form.on('submit', e => {
        e.preventDefault();
        const $btn  = $form.find('.clips-comments-send');
        const texto = $form.find('[name="texto"]').val().trim();
        const nome  = $form.find('[name="nome"]').val().trim() || 'Visitante';
        if (!texto || !this._clipId) return;
        $btn.prop('disabled',true);

        Utils.post(`${BASE}/clips/comentar`,{id:this._clipId, nome, texto}, res=>{
          $btn.prop('disabled',false);
          if (res.ok) {
            $form.find('[name="texto"]').val('').css('height','auto');
            if (res.aprovado) {
              // Adiciona na lista em tempo real
              const $empty = $list.find('.clips-comments-empty');
              if ($empty.length) $empty.remove();
              const $novo = $(this._renderItem(res.comentario));
              $novo.addClass('is-new');
              $list.append($novo);
              $list.scrollTop($list[0].scrollHeight);
            } else {
              // Pendente: avisa mas não adiciona na lista
              const $n = $(`<p class="clips-comment-pending">⏳ ${res.msg||'Aguardando moderação.'}</p>`);
              $form.prepend($n);
              setTimeout(()=>$n.fadeOut(400,function(){$(this).remove();}),3500);
            }
          } else { alert(res.msg||'Erro.'); }
        });
      });

      $form.find('textarea').on('input', function(){
        this.style.height='auto';
        this.style.height=Math.min(this.scrollHeight,100)+'px';
      });
    },

    abrir(clipId) {
      this._clipId = clipId; this._page = 1;
      $('#clips-comment-clip-id').val(clipId);
      $('#clips-comments-list').html('<div class="clips-comments-empty">Carregando…</div>');
      $('#clips-comments-backdrop').addClass('is-open');
      $('.clips-comments-panel').addClass('is-open');
      this._load();
    },

    fechar() {
      $('#clips-comments-backdrop').removeClass('is-open');
      $('.clips-comments-panel').removeClass('is-open');
    },

    _load() {
      if (this._loading) return;
      this._loading = true;
      $.get(`${BASE}/clips/comentarios`,{id:this._clipId,page:this._page},res=>{
        this._loading = false;
        const $l = $('#clips-comments-list');
        if (!res.comentarios.length && this._page===1) {
          $l.html('<div class="clips-comments-empty">Sem comentários. Seja o primeiro!</div>');
          return;
        }
        if (this._page===1) $l.empty();
        res.comentarios.forEach(c=>$l.append(this._renderItem(c)));
        if (res.comentarios.length===20) this._page++;
      },'json');
    },

    _renderItem(c) {
      return `<div class="comment-item">
        <div class="comment-avatar">${Utils.esc((c.nome||'?').charAt(0).toUpperCase())}</div>
        <div class="comment-body">
          <div class="comment-nome">${Utils.esc(c.nome)}</div>
          <div class="comment-texto">${Utils.esc(c.texto)}</div>
          <div class="comment-data">${Utils.timeAgo(c.criado_em)}</div>
        </div>
      </div>`;
    },
  };

  // ════════════════════════════════════════════════════════
  // ClipFeed — feed fullscreen
  // ════════════════════════════════════════════════════════
  const ClipFeed = {
    _clips:       [],
    _page:        1,
    _loading:     false,
    _hasMore:     true,
    _isMuted:     true,
    _userPlayed:  false,   // usuário já clicou play = desmutar daqui pra frente
    _produtoId:   null,
    _observer:    null,

    init() {
      const $c = $('#clips-feed-container');

      $('#clips-feed-close').on('click', ()=>this.fechar());
      $(document).on('keydown',e=>{if(e.key==='Escape')this.fechar();});

      const cont = $c[0];
      if (cont) cont.addEventListener('scroll',()=>this._onScroll(cont),{passive:true});

      this._observer = new IntersectionObserver(entries=>{
        entries.forEach(entry=>{
          const item  = entry.target;
          const video = item.querySelector('.clip-item-video');
          if (!video) return;
          if (entry.isIntersecting && entry.intersectionRatio>0.7) this._ativarItem(item);
          else this._pausarItem(item);
        });
      },{root:cont,threshold:[0,0.7]});

      // Like
      $c.on('click','.clip-like-btn',e=>{
        const $item=$(e.currentTarget).closest('.clip-item');
        const $btn=$(e.currentTarget);
        const $count=$btn.closest('.clip-action').find('.clip-like-count');
        ClipActions.toggleLike(parseInt($item.data('clip-id')),$btn,$count);
      });

      // Comentar
      $c.on('click','.clip-comment-btn',e=>{
        ClipComments.abrir(parseInt($(e.currentTarget).closest('.clip-item').data('clip-id')));
      });

      // Compartilhar
      $c.on('click','.clip-share-btn',e=>{
        const $item=$(e.currentTarget).closest('.clip-item');
        ClipShare.abrir(
          parseInt($item.data('clip-id')),
          $item.data('clip-url') || `${BASE}/clip/${$item.data('clip-id')}`
        );
      });

      // Mute toggle
      $c.on('click','.clip-mute-btn',e=>{
        this._isMuted = !this._isMuted;
        this._userPlayed = !this._isMuted;
        $(e.currentTarget).toggleClass('is-muted', this._isMuted);
        $c.find('video').each((_,v)=>{ v.muted=this._isMuted; });
      });

      // TAP: pausar/retomar — na primeira retomada, liga o áudio
      $c.on('click','.clip-item-tap-overlay',e=>{
        const item  = $(e.currentTarget).closest('.clip-item')[0];
        const video = item.querySelector('.clip-item-video');
        if (!video) return;
        if (video.paused) {
          if (!this._userPlayed) {
            // Primeira interação do usuário: desmuta
            this._isMuted    = false;
            this._userPlayed = true;
            $c.find('.clip-mute-btn').removeClass('is-muted');
          }
          video.muted = this._isMuted;
          video.play().catch(()=>{});
          $(item).removeClass('is-paused');
        } else {
          video.pause();
          $(item).addClass('is-paused');
        }
      });

      // Carrossel de produtos (prev/next)
      $c.on('click','.clip-prod-prev',e=>{
        const $p=$(e.currentTarget).closest('.clip-item-produto');
        const $car=$p.find('.clip-produtos-carousel');
        const total=parseInt($p.data('total')||1);
        let idx=parseInt($p.data('idx')||0);
        if (idx>0) { idx--; $car.css('transform',`translateX(${-idx*100}%)`); $p.data('idx',idx); $p.find('.clip-prod-counter').text(`${idx+1}/${total}`); }
      });
      $c.on('click','.clip-prod-next',e=>{
        const $p=$(e.currentTarget).closest('.clip-item-produto');
        const $car=$p.find('.clip-produtos-carousel');
        const total=parseInt($p.data('total')||1);
        let idx=parseInt($p.data('idx')||0);
        if (idx<total-1) { idx++; $car.css('transform',`translateX(${-idx*100}%)`); $p.data('idx',idx); $p.find('.clip-prod-counter').text(`${idx+1}/${total}`); }
      });
    },

    abrir(clips, startIndex=0, produtoId=null) {
      this._clips=[]; this._page=1; this._hasMore=true;
      this._loading=false; this._produtoId=produtoId;
      const $c=$('#clips-feed-container'); $c.empty();
      this._adicionarClips(clips);
      requestAnimationFrame(()=>{
        const items=$c.find('.clip-item');
        if (items.length>startIndex) items.get(startIndex).scrollIntoView({behavior:'auto',block:'start'});
        if (clips.length<10) this._buscarMais();
      });
      document.getElementById('clips-feed-overlay').hidden=false;
      document.body.style.overflow='hidden';
    },

    fechar() {
      document.querySelectorAll('#clips-feed-container video').forEach(v=>{v.pause();v.src='';});
      document.getElementById('clips-feed-overlay').hidden=true;
      document.body.style.overflow='';
    },

    _adicionarClips(clips) {
      const $c = $('#clips-feed-container');
      const tpl = document.getElementById('clip-item-template');
      clips.forEach(c=>{
        if (this._clips.find(x=>x.id===c.id)) return;
        this._clips.push(c);
        const item = tpl.content.cloneNode(true).querySelector('.clip-item');
        item.dataset.clipId  = c.id;
        item.dataset.clipUrl = c.clip_url || `${BASE}/clip/${c.id}`;
        this._preencher(item, c);
        $c.append(item);
        this._observer?.observe(item);
      });
    },

    _preencher(item, c) {
      const $i = $(item);
      const $poster = $i.find('.clip-item-poster');
      c.poster_url ? $poster.attr('src',c.poster_url) : $poster.hide();
      $i.find('.clip-item-video').attr('data-src', c.video_url||'');
      $i.find('.clip-item-titulo').text(c.titulo||'');
      $i.find('.clip-item-descricao').text(c.descricao||'');
      $i.find('.clip-like-count').text(Utils.fmt(c.total_likes||0));
      $i.find('.clip-comment-count').text(Utils.fmt(c.total_comentarios||0));
      $i.find('.clip-like-btn').toggleClass('is-liked',!!c.curtiu);
      this._renderProdutos($i, c.produtos||[], c.cta_texto, c.cta_link);
    },

    _renderProdutos($item, produtos, ctaTxt, ctaLink) {
      if (!produtos.length) {
        if (ctaTxt && ctaLink) {
          $item.find('.clip-item-cta-generic').show();
          $item.find('.clip-item-cta-link').attr('href',ctaLink).text(ctaTxt);
        }
        return;
      }

      const $wrap = $item.find('.clip-item-produto').show();

      if (produtos.length===1) {
        // Produto único
        const p = produtos[0];
        if (p.img_url) $wrap.find('.clip-produto-img').attr('src',p.img_url);
        $wrap.find('.clip-produto-nome').text(p.produto_nome||'');
        $wrap.find('.clip-produto-cta').attr('href',p.produto_url||'#');
        if (p.preco_promo_fmt) {
          $wrap.find('.clip-produto-preco').addClass('is-riscado').text(p.preco_fmt||'');
          $wrap.find('.clip-produto-preco-promo').show().text(p.preco_promo_fmt);
        } else {
          $wrap.find('.clip-produto-preco').text(p.preco_fmt||'');
        }
      } else {
        // Múltiplos produtos — carrossel
        $wrap.addClass('is-multi').data('idx',0).data('total',produtos.length);
        $wrap.html(`
          <div class="clip-produtos-carousel" style="display:flex;transition:transform .3s ease;width:100%">
            ${produtos.map(p=>`
              <div class="clip-produto-slide" style="min-width:100%;display:flex;align-items:center;gap:8px;padding:4px 0">
                <div class="clip-produto-img-wrap" style="width:44px;height:44px;border-radius:8px;overflow:hidden;flex-shrink:0;background:rgba(255,255,255,.1)">
                  ${p.img_url?`<img src="${Utils.esc(p.img_url)}" style="width:100%;height:100%;object-fit:cover">`:''}
                </div>
                <div class="clip-produto-info" style="flex:1;min-width:0">
                  <span class="clip-produto-nome">${Utils.esc(p.produto_nome||'')}</span>
                  <div class="clip-produto-precos">
                    ${p.preco_promo_fmt
                      ? `<span class="clip-produto-preco-promo">${Utils.esc(p.preco_promo_fmt)}</span><span class="clip-produto-preco is-riscado">${Utils.esc(p.preco_fmt||'')}</span>`
                      : `<span class="clip-produto-preco">${Utils.esc(p.preco_fmt||'')}</span>`}
                  </div>
                </div>
                <a class="clip-produto-cta" href="${Utils.esc(p.produto_url||'#')}" target="_blank">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
                  Comprar
                </a>
              </div>`).join('')}
          </div>
          <div class="clip-prod-nav">
            <button type="button" class="clip-prod-prev">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <span class="clip-prod-counter">1/${produtos.length}</span>
            <button type="button" class="clip-prod-next">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
          </div>`);
      }
    },

    _ativarItem(item) {
      const $item  = $(item);
      const clipId = parseInt($item.data('clip-id'));
      const video  = item.querySelector('.clip-item-video');
      if (!video) return;
      const src = video.dataset.src||'';
      if (src && !video.src.includes(src.split('/').pop())) {
        video.preload='auto'; video.src=src; video.muted=this._isMuted; video.load();
      }
      video.muted = this._isMuted;
      video.play().then(()=>{
        $item.find('.clip-item-poster').addClass('is-hidden');
        $item.removeClass('is-paused');
        this._progress(video, item);
      }).catch(()=>$item.addClass('is-paused'));
      this._preloadNext(item);
      ClipActions.registrarView(clipId);
    },

    _pausarItem(item) {
      const v = item.querySelector('.clip-item-video');
      if (v&&!v.paused) { v.pause(); $(item).addClass('is-paused'); }
    },

    _preloadNext(item) {
      const items = document.querySelectorAll('#clips-feed-container .clip-item');
      const next  = items[Array.from(items).indexOf(item)+1];
      if (!next) return;
      const v = next.querySelector('.clip-item-video');
      if (!v||v.readyState>0) return;
      const src = v.dataset.src||'';
      if (src) { v.preload='metadata'; v.src=src; }
    },

    _progress(video, item) {
      const bar = item.querySelector('.clip-item-progress-bar');
      if (!bar) return;
      video.addEventListener('timeupdate',()=>{
        if (video.duration) bar.style.width=(video.currentTime/video.duration*100)+'%';
      },{passive:true});
      video.addEventListener('ended',()=>{bar.style.width='100%';},{once:true});
    },

    _onScroll(cont) {
      const {scrollTop,scrollHeight,clientHeight}=cont;
      if (!this._loading&&this._hasMore&&(scrollHeight-scrollTop-clientHeight)<clientHeight*2) this._buscarMais();
    },

    _buscarMais() {
      if (this._loading||!this._hasMore) return;
      this._loading=true;
      $('#clips-feed-loading').addClass('is-visible');
      const p={page:this._page};
      if (this._produtoId) p.produto_id=this._produtoId;
      $.get(`${BASE}/clips/feed`,p,res=>{
        this._loading=false;
        $('#clips-feed-loading').removeClass('is-visible');
        if (res.ok) { this._adicionarClips(res.clips); this._hasMore=res.has_more; this._page++; }
      },'json').fail(()=>{this._loading=false;$('#clips-feed-loading').removeClass('is-visible');});
    },
  };

  // ════════════════════════════════════════════════════════
  // ClipCarousel — vitrine na home
  // ════════════════════════════════════════════════════════
  const ClipCarousel = {
    _loading: false,
    init() {
      const $c=$('#clips-carousel'); if(!$c.length)return;
      $c.on('click keydown','.clip-card',e=>{
        if (e.type==='keydown'&&e.key!=='Enter') return;
        this._abrir(parseInt($(e.currentTarget).data('index')));
      });
      $('#clips-prev').on('click',()=>$c[0].scrollBy({left:-350,behavior:'smooth'}));
      $('#clips-next').on('click',()=>$c[0].scrollBy({left:350,behavior:'smooth'}));
      let sx=0;
      $c[0].addEventListener('touchstart',e=>{sx=e.touches[0].clientX;},{passive:true});
      $c[0].addEventListener('touchend',e=>{
        const d=sx-e.changedTouches[0].clientX;
        if(Math.abs(d)>60) $c[0].scrollBy({left:d>0?300:-300,behavior:'smooth'});
      },{passive:true});
    },
    _abrir(idx) {
      if (this._loading) return;
      this._loading=true;
      $.get(`${BASE}/clips/feed?destaque=1`,res=>{
        this._loading=false;
        if (res.ok&&res.clips.length) ClipFeed.abrir(res.clips,idx);
      },'json').fail(()=>{this._loading=false;});
    },
  };

  // ════════════════════════════════════════════════════════
  // Auto-open via URL direta /clip/{id}
  // ════════════════════════════════════════════════════════
  function handleAutoOpen() {
    if (!window.AUTO_OPEN_CLIP_ID) return;
    const data = window.AUTO_OPEN_CLIP_DATA;
    if (!data) return;
    const tryOpen = () => {
      if (!document.getElementById('clips-feed-overlay')) return setTimeout(tryOpen,100);
      ClipFeed.abrir([data], 0);
    };
    setTimeout(tryOpen, 500);
  }

  // ════════════════════════════════════════════════════════
  // Init
  // ════════════════════════════════════════════════════════
  $(document).ready(()=>{
    ClipCarousel.init();
    ClipFeed.init();
    ClipComments.init();
    ClipShare.init();
    handleAutoOpen();
  });

  window.ClipFeed     = ClipFeed;
  window.ClipCarousel = ClipCarousel;
  window.ClipActions  = ClipActions;
  window.ClipComments = ClipComments;
  window.ClipShare    = ClipShare;


  function initStories() {
    if (!window.ClipFeed || !window.ClipCarousel) {
      return setTimeout(initStories, 80);
    }

    document.querySelectorAll('#product-clips-stories-row .product-story-circle')
      .forEach(function (btn) {
        btn.addEventListener('click', function () {
          const idx       = parseInt(this.dataset.idx, 10);
          const produtoId = parseInt(
            document.getElementById('product-clips-stories').dataset.produtoId, 10
          );

          // Marca como visto
          this.classList.add('is-seen');

          // Busca os clips completos e abre o feed
          fetch(BASE_URL + '/clips/feed?produto_id=' + produtoId)
            .then(r => r.json())
            .then(data => {
              if (data.ok && data.clips.length) {
                window.ClipFeed.abrir(data.clips, idx, produtoId);
              }
            });
        });
      });
  }

  document.addEventListener('DOMContentLoaded', initStories);

}(window, jQuery));