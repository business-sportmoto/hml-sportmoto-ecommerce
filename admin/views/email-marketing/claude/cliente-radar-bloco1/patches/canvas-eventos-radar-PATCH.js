/* ═══════════════════════════════════════════════════════════════════════════
 *  Eventos do radar + cond_perfil no canvas — patch de public/js/fluxo-canvas.js
 * ═══════════════════════════════════════════════════════════════════════════
 *  2 edições: os eventos novos no dropdown do trigger/esperar_evento, e o nó
 *  cond_perfil na paleta com seu formulário de config.
 * ═══════════════════════════════════════════════════════════════════════════ */


/* ─────────────────────────────────────────────────────────────────────────────
   EDIÇÃO 1 — eventos do radar na lista de eventos selecionáveis.

   ACHE o array de eventos que alimenta os <select> de evento (no objeto de
   configuração da UI; algo como FLUXO_UI.eventos ou a lista EVENTOS). Ele tem
   os eventos de navegação. ADICIONE os do radar:
────────────────────────────────────────────────────────────────────────────── */

  // ── Eventos do radar de clientes (Bloco 1) ──
  // Emitidos pelo cli/cliente-radar.php; use em trigger_evento.
  { valor: 'aniversario',      rotulo: 'Aniversário do cliente',        grupo: 'Cliente' },
  { valor: 'inativo_30d',      rotulo: 'Inativo há 30 dias',            grupo: 'Cliente' },
  { valor: 'inativo_60d',      rotulo: 'Inativo há 60 dias',            grupo: 'Cliente' },
  { valor: 'inativo_90d',      rotulo: 'Inativo há 90 dias',            grupo: 'Cliente' },
  { valor: 'saldo_expirando',  rotulo: 'Saldo prestes a expirar',       grupo: 'Cliente' },

/* Se a sua lista for um array simples de strings em vez de objetos, use:

  'aniversario', 'inativo_30d', 'inativo_60d', 'inativo_90d', 'saldo_expirando',

   E, se houver um mapa de rótulos amigáveis (tipo LABELS_EVENTO), acrescente:

  aniversario:     'Aniversário do cliente',
  inativo_30d:     'Inativo há 30 dias',
  inativo_60d:     'Inativo há 60 dias',
  inativo_90d:     'Inativo há 90 dias',
  saldo_expirando: 'Saldo prestes a expirar',
*/


/* ─────────────────────────────────────────────────────────────────────────────
   EDIÇÃO 2 — nó cond_perfil na paleta + formulário de config.

   2a. ACHE a definição da paleta de nós (a lista que o usuário arrasta, com
   os nós agrupados por categoria). Na categoria de CONDIÇÕES, ADICIONE:
────────────────────────────────────────────────────────────────────────────── */

  {
    tipo: 'cond_perfil',
    rotulo: 'Perfil do cliente',
    icone: 'bi-person-badge',
    grupo: 'condicao',            // mesma cor âmbar das outras condições
    portas: ['true', 'false'],
    descricao: 'Ramifica por gênero, saldo, newsletter ou verificação.'
  },

/* ─────────────────────────────────────────────────────────────────────────────
   2b. ACHE o switch/mapa que monta o FORMULÁRIO de config de cada nó no painel
   lateral (onde cada 'tipo' desenha seus campos). ADICIONE o caso cond_perfil.

   Este HTML segue o padrão dos outros formulários do painel (classe .fx-campo).
   Ajuste os nomes das classes/helpers aos do seu canvas se diferirem.
────────────────────────────────────────────────────────────────────────────── */

  function formCondPerfil(cfg) {
    cfg = cfg || {};
    var campo = cfg.campo || 'genero';
    var op    = cfg.operador || '=';
    var valor = (cfg.valor !== undefined ? cfg.valor : '');

    // Campos permitidos (espelham a allowlist do backend)
    var campos = [
      ['genero',           'Gênero'],
      ['saldo_disponivel', 'Saldo disponível (R$)'],
      ['newsletter',       'Aceita newsletter (1/0)'],
      ['verificado',       'Conta verificada (1/0)']
    ];
    var numericos = ['saldo_disponivel', 'newsletter', 'verificado'];
    var ehNum = numericos.indexOf(campo) !== -1;

    var opsNum   = ['=', '!=', '>=', '>', '<=', '<'];
    var opsTexto = ['=', '!='];
    var ops = ehNum ? opsNum : opsTexto;

    var h = '';
    h += '<label class="fx-campo"><span>Campo</span>';
    h += '<select data-cfg="campo" class="fx-cond-perfil-campo">';
    campos.forEach(function (c) {
      h += '<option value="' + c[0] + '"' + (c[0] === campo ? ' selected' : '') + '>' + c[1] + '</option>';
    });
    h += '</select></label>';

    h += '<label class="fx-campo"><span>Operador</span>';
    h += '<select data-cfg="operador">';
    ops.forEach(function (o) {
      h += '<option value="' + o + '"' + (o === op ? ' selected' : '') + '>' + o + '</option>';
    });
    h += '</select></label>';

    // Valor: para gênero, um select M/F/O/N; senão, um input
    if (campo === 'genero') {
      var gs = [['M', 'Masculino'], ['F', 'Feminino'], ['O', 'Outro'], ['N', 'Não informado']];
      h += '<label class="fx-campo"><span>Valor</span><select data-cfg="valor">';
      gs.forEach(function (g) {
        h += '<option value="' + g[0] + '"' + (String(valor) === g[0] ? ' selected' : '') + '>' + g[1] + '</option>';
      });
      h += '</select></label>';
    } else {
      var tipoInput = ehNum ? 'number' : 'text';
      h += '<label class="fx-campo"><span>Valor</span>';
      h += '<input type="' + tipoInput + '" data-cfg="valor" value="' + String(valor).replace(/"/g, '&quot;') + '"';
      h += (ehNum ? ' step="0.01"' : '') + '></label>';
    }

    h += '<p class="fx-dica">Ex.: gênero = F para uma campanha; ou saldo disponível &gt;= 0.01 ' +
         'após um evento de inatividade, para falar do saldo parado.</p>';
    return h;
  }

/* 2c. LIGUE o redesenho quando o campo muda (troca operadores/valor conforme o
   tipo). No handler de mudança do painel, quando o select .fx-cond-perfil-campo
   mudar, re-render o formulário do nó. Se o seu canvas já re-renderiza o form a
   cada change de [data-cfg], nada a fazer. Senão:

   $('#fx-painel').on('change', '.fx-cond-perfil-campo', function () {
     // relê o cfg atual do nó e redesenha o form
     redesenharFormDoNoAtual();
   });

   E no dispatcher que escolhe qual form desenhar por tipo, ACHE algo como:
       case 'cond_tem_moto': return formCondTemMoto(cfg);
   ADICIONE:
       case 'cond_perfil':   return formCondPerfil(cfg);
*/
