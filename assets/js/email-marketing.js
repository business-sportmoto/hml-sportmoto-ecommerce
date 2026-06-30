/* assets/js/email-marketing.js
   Módulo Email Marketing — jQuery puro, sem async/await, sem fetch.
   Todos os POSTs incluem csrf_token a partir do campo hidden ou meta. */

(function ($) {
    'use strict';

    if (!$) {
        console.error('email-marketing.js requer jQuery');
        return;
    }

    var $w = $('.em_wrapper');
    if (!$w.length) return;

    var BASE = $w.data('base') || '';

    /* ------------ Utilitários --------------------------------------- */

    function getCsrf() {
        var $h = $('input[name="csrf_token"]').first();
        if ($h.length) return $h.val();
        var meta = $('meta[name="csrf-token"]').attr('content');
        return meta || '';
    }

    function withCsrf(data) {
        data = data || {};
        if (!data.csrf_token) data.csrf_token = getCsrf();
        return data;
    }

    function post(url, data, cb) {
        $.ajax({
            url: BASE + url,
            type: 'POST',
            data: withCsrf(data),
            dataType: 'json'
        }).done(function (r) {
            cb(r);
        }).fail(function (xhr) {
            cb({ ok: false, erro: 'Erro de comunicação (' + xhr.status + ')' });
        });
    }

    function flash(msg, ok) {
        // Implementação minimalista para não conflitar com o flash do projeto.
        // Substitua/integre se o projeto já tiver um sistema próprio.
        if (typeof window.toast === 'function') return window.toast(msg, ok);
        alert(msg);
    }

    function showError(r) {
        flash(r && r.erro ? r.erro : 'Ocorreu um erro.', false);
    }

    /* ------------ Modais genéricos ---------------------------------- */
    $(document).on('click', '[data-em-close]', function () {
        $(this).closest('.em_modal').hide();
    });
    $(document).on('click', '.em_modal', function (e) {
        if (e.target === this) $(this).hide();
    });

    /* =================================================================
       PROVEDORES
    ================================================================= */
    function abrirModalProvedor(dados) {
        var $modal = $('#em_modal_provedor');
        if (!$modal.length) return;
        var $f = $('#em_form_provedor');
        $f[0].reset();

        // campos texto
        ['id','nome','remetente_email','remetente_nome','reply_to','dominio',
         'regiao','limite_por_minuto','limite_por_dia','webhook_secret'].forEach(function (k) {
            if (dados && dados[k] != null) $f.find('[name="'+k+'"]').val(dados[k]);
        });
        // tipo + ativo + padrao
        if (dados) {
            $f.find('[name="tipo"]').val(dados.tipo || 'ses');
            $f.find('[name="ativo"]').prop('checked',  !!(+dados.ativo));
            $f.find('[name="padrao"]').prop('checked', !!(+dados.padrao));
        } else {
            $f.find('[name="ativo"]').prop('checked', true);
        }
        $('#em_modal_titulo').text(dados ? 'Editar provedor' : 'Novo provedor');
        $modal.show();
        toggleCredFields();
    }

    function toggleCredFields() {
        var tipo = $('#em_tipo').val();
        $('.em_creds_smtp,.em_creds_ses,.em_creds_mailgun,.em_creds_sendgrid,.em_creds_brevo').hide();
        $('.em_creds_' + tipo).show();
    }

    $(document).on('click', '[data-em-action="novo-provedor"]', function () { abrirModalProvedor(null); });
    $(document).on('click', '[data-em-action="editar-provedor"]', function () {
        var dados;
        try { dados = JSON.parse($(this).attr('data-json')); } catch (e) { dados = null; }
        abrirModalProvedor(dados);
    });
    $(document).on('change', '#em_tipo', toggleCredFields);

    $(document).on('submit', '#em_form_provedor', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray().reduce(function (o, kv) {
            // Mantém arrays de credenciais[xxx]
            if (kv.name in o) {
                if (Array.isArray(o[kv.name])) o[kv.name].push(kv.value);
                else o[kv.name] = [o[kv.name], kv.value];
            } else o[kv.name] = kv.value;
            return o;
        }, {});
        post('/admin/email-marketing/provedores/salvar', data, function (r) {
            if (r.ok) { flash('Provedor salvo.', true); window.location.reload(); }
            else showError(r);
        });
    });

    $(document).on('click', '[data-em-action="testar-provedor"]', function () {
        var id = $(this).data('id');
        var email = prompt('Email de destino para teste:');
        if (!email) return;
        post('/admin/email-marketing/provedores/testar', { id: id, email: email }, function (r) {
            if (r.ok) flash('Teste enviado. Message-id: ' + (r.message_id || '—'), true);
            else showError(r);
        });
    });

    /* =================================================================
       CONTATOS
    ================================================================= */
    $(document).on('click', '[data-em-action="sincronizar-contatos"]', function () {
        if (!confirm('Sincronizar contatos a partir de usuários, clientes e newsletter?')) return;
        var $btn = $(this).prop('disabled', true).text('Sincronizando...');
        post('/admin/email-marketing/contatos/sincronizar', {}, function (r) {
            $btn.prop('disabled', false).text('Sincronizar com clientes / newsletter');
            if (r.ok) {
                var t = r.resultado || {};
                flash('Sincronizado: ' + (t.total || 0) + ' contatos processados.', true);
                setTimeout(function () { window.location.reload(); }, 1200);
            } else showError(r);
        });
    });

    $(document).on('click', '[data-em-action="bloquear-contato"]', function () {
        var id = $(this).data('id');
        if (!confirm('Bloquear esse contato?')) return;
        post('/admin/email-marketing/contatos/bloquear', { id: id }, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });
    $(document).on('click', '[data-em-action="desbloquear-contato"]', function () {
        var id = $(this).data('id');
        if (!confirm('Desbloquear esse contato?')) return;
        post('/admin/email-marketing/contatos/desbloquear', { id: id }, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });

    /* =================================================================
       LISTAS
    ================================================================= */
    function abrirModalLista(dados) {
        var $m = $('#em_modal_lista'); if (!$m.length) return;
        var $f = $('#em_form_lista'); $f[0].reset();
        if (dados) {
            $f.find('[name="id"]').val(dados.id);
            $f.find('[name="nome"]').val(dados.nome);
            $f.find('[name="descricao"]').val(dados.descricao || '');
            $f.find('[name="ativo"]').prop('checked', !!(+dados.ativo));
        } else {
            $f.find('[name="ativo"]').prop('checked', true);
        }
        $('#em_modal_titulo_lista').text(dados ? 'Editar lista' : 'Nova lista');
        $m.show();
    }
    $(document).on('click', '[data-em-action="nova-lista"]', function () { abrirModalLista(null); });
    $(document).on('click', '[data-em-action="editar-lista"]', function () {
        var d; try { d = JSON.parse($(this).attr('data-json')); } catch (e) {}
        abrirModalLista(d);
    });
    $(document).on('submit', '#em_form_lista', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray().reduce(function (o, kv) { o[kv.name] = kv.value; return o; }, {});
        post('/admin/email-marketing/listas/salvar', data, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });
    $(document).on('click', '[data-em-action="excluir-lista"]', function () {
        var id = $(this).data('id');
        if (!confirm('Excluir esta lista? Os contatos NÃO serão excluídos.')) return;
        post('/admin/email-marketing/listas/excluir', { id: id }, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });

    /* =================================================================
       SEGMENTOS (builder)
    ================================================================= */
    function getCamposPermitidos() {
        var $j = $('#em_campos_permitidos');
        if (!$j.length) return [];
        try { return JSON.parse($j.text()); } catch (e) { return []; }
    }

    function addLinhaRegra(campo, valor) {
        var campos = getCamposPermitidos();
        var $row = $('<div class="em_regra"></div>');
        var $sel = $('<select class="em_regra_campo"></select>');
        campos.forEach(function (c) {
            $sel.append($('<option/>').val(c).text(c));
        });
        if (campo) $sel.val(campo);
        var $val = $('<input type="text" class="em_regra_valor" placeholder="valor">');
        if (valor !== undefined && valor !== null) $val.val(valor);
        var $rm = $('<button type="button" class="em_btn em_rm">Remover</button>');
        $rm.on('click', function () { $row.remove(); });
        $row.append($sel, $val, $rm);
        $('#em_regras_box').append($row);
    }

    function lerRegras() {
        var match = $('#em_form_segmento [name="match"]').val();
        var regras = [];
        $('#em_regras_box .em_regra').each(function () {
            var c = $(this).find('.em_regra_campo').val();
            var v = $(this).find('.em_regra_valor').val();
            if (c) regras.push({ campo: c, valor: v });
        });
        return { match: match, regras: regras };
    }

    function abrirModalSegmento(dados) {
        var $m = $('#em_modal_segmento'); if (!$m.length) return;
        var $f = $('#em_form_segmento'); $f[0].reset();
        $('#em_regras_box').empty();
        $('#em_estimativa').text('—');

        if (dados) {
            $f.find('[name="id"]').val(dados.id);
            $f.find('[name="nome"]').val(dados.nome);
            $f.find('[name="descricao"]').val(dados.descricao || '');
            $f.find('[name="ativo"]').prop('checked', !!(+dados.ativo));
            var regras;
            try { regras = JSON.parse(dados.regras_json); } catch (e) { regras = null; }
            if (regras) {
                $f.find('[name="match"]').val(regras.match || 'AND');
                (regras.regras || []).forEach(function (r) { addLinhaRegra(r.campo, r.valor); });
            }
        } else {
            $f.find('[name="ativo"]').prop('checked', true);
            addLinhaRegra();
        }
        $('#em_modal_titulo_seg').text(dados ? 'Editar segmento' : 'Novo segmento');
        $m.show();
    }

    $(document).on('click', '[data-em-action="novo-segmento"]', function () { abrirModalSegmento(null); });
    $(document).on('click', '[data-em-action="editar-segmento"]', function () {
        var d; try { d = JSON.parse($(this).attr('data-json')); } catch (e) {}
        abrirModalSegmento(d);
    });
    $(document).on('click', '[data-em-action="add-regra"]', function () { addLinhaRegra(); });

    $(document).on('click', '[data-em-action="preview-seg"]', function () {
        var regras = lerRegras();
        post('/admin/email-marketing/segmentos/preview', { regras_json: JSON.stringify(regras) }, function (r) {
            if (r.ok) $('#em_estimativa').text(r.estimativa.toLocaleString('pt-BR'));
            else showError(r);
        });
    });

    $(document).on('submit', '#em_form_segmento', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray().reduce(function (o, kv) { o[kv.name] = kv.value; return o; }, {});
        data.regras_json = JSON.stringify(lerRegras());
        post('/admin/email-marketing/segmentos/salvar', data, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });

    $(document).on('click', '[data-em-action="excluir-segmento"]', function () {
        var id = $(this).data('id');
        if (!confirm('Excluir este segmento?')) return;
        post('/admin/email-marketing/segmentos/excluir', { id: id }, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });

    /* =================================================================
       TEMPLATES
    ================================================================= */
    $(document).on('submit', '#em_form_template', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray().reduce(function (o, kv) { o[kv.name] = kv.value; return o; }, {});
        post('/admin/email-marketing/templates/salvar', data, function (r) {
            if (r.ok) {
                flash('Template salvo.', true);
                if (data.id === '0' || data.id === 0) {
                    window.location.href = BASE + '/admin/email-marketing/templates/' + r.id + '/editar';
                }
            } else showError(r);
        });
    });

    $(document).on('click', '[data-em-action="preview-template"]', function () {
        var html = $('#em_form_template [name="html"]').val();
        post('/admin/email-marketing/templates/preview', { html: html }, function (r) {
            if (!r.ok) return showError(r);
            var iframe = document.getElementById('em_preview_iframe');
            if (!iframe) return;
            var doc = iframe.contentDocument || iframe.contentWindow.document;
            doc.open(); doc.write(r.html); doc.close();
        });
    });

    $(document).on('click', '[data-em-action="excluir-template"]', function () {
        var id = $(this).data('id');
        if (!confirm('Excluir este template? Esta ação não pode ser desfeita.')) return;
        post('/admin/email-marketing/templates/excluir', { id: id }, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });

    /* =================================================================
       CAMPANHAS
    ================================================================= */
    $(document).on('submit', '#em_form_campanha', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray().reduce(function (o, kv) { o[kv.name] = kv.value; return o; }, {});
        post('/admin/email-marketing/campanhas/salvar', data, function (r) {
            if (r.ok) {
                flash('Campanha salva.', true);
                if (data.id === '0' || data.id === 0) {
                    window.location.href = BASE + '/admin/email-marketing/campanhas/' + r.id + '/editar';
                }
            } else showError(r);
        });
    });

    $(document).on('click', '[data-em-action="testar-campanha"]', function () {
        var id = $(this).data('id');
        var email = $('#em_email_teste').val();
        if (!email) { flash('Informe um email para teste.', false); return; }
        post('/admin/email-marketing/campanhas/testar', { id: id, email: email }, function (r) {
            if (r.ok) flash('Teste enviado!', true); else showError(r);
        });
    });

    $(document).on('click', '[data-em-action="enfileirar-campanha"]', function () {
        var id = $(this).data('id');
        if (!confirm('Enfileirar destinatários e iniciar o envio? Esta ação não pode ser desfeita facilmente.')) return;
        post('/admin/email-marketing/campanhas/enfileirar', { id: id }, function (r) {
            if (r.ok) {
                var t = r.resultado || {};
                flash('Enfileirados: ' + (t.inseridos || 0) + ' / ignorados: ' + (t.ignorados || 0), true);
                setTimeout(function () { window.location.reload(); }, 1500);
            } else showError(r);
        });
    });

    $(document).on('click', '[data-em-action="pausar-campanha"]', function () {
        var id = $(this).data('id');
        post('/admin/email-marketing/campanhas/pausar', { id: id }, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });
    $(document).on('click', '[data-em-action="continuar-campanha"]', function () {
        var id = $(this).data('id');
        post('/admin/email-marketing/campanhas/continuar', { id: id }, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });
    $(document).on('click', '[data-em-action="cancelar-campanha"]', function () {
        var id = $(this).data('id');
        if (!confirm('Cancelar esta campanha? Destinatários ainda na fila serão ignorados.')) return;
        post('/admin/email-marketing/campanhas/cancelar', { id: id }, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });
    $(document).on('click', '[data-em-action="duplicar-campanha"]', function () {
        var id = $(this).data('id');
        post('/admin/email-marketing/campanhas/duplicar', { id: id }, function (r) {
            if (r.ok) window.location.href = BASE + '/admin/email-marketing/campanhas/' + r.id + '/editar';
            else showError(r);
        });
    });

    /* Polling no relatório */
    if ($w.is('[data-campanha]')) {
        var camp = $w.data('campanha');
        setInterval(function () { /* leve refresh — opcional desabilitar */
            // window.location.reload();
        }, 30000);
    }

    /* =================================================================
       SUPRESSÕES
    ================================================================= */
    $(document).on('click', '[data-em-action="nova-supressao"]', function () {
        $('#em_modal_supressao').show();
    });
    $(document).on('submit', '#em_form_supressao', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray().reduce(function (o, kv) { o[kv.name] = kv.value; return o; }, {});
        post('/admin/email-marketing/supressoes/adicionar', data, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });
    $(document).on('click', '[data-em-action="remover-supressao"]', function () {
        var email = $(this).data('email');
        if (!confirm('Remover supressão de ' + email + '?')) return;
        post('/admin/email-marketing/supressoes/remover', { email: email }, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });

    /* =============================================================================
   PATCH para assets/js/email-marketing.js
   Adicione este bloco ANTES da última linha "})(window.jQuery);"
   ============================================================================= */

    /* =================================================================
       LISTAS - DETALHES (gestão de contatos)
    ================================================================= */

    var $listaWrapper = $('.em_wrapper[data-lista]');
    var listaId = $listaWrapper.data('lista');
    var contatosSelecionados = {};

    /* --- Abrir modal de adicionar --- */
    $(document).on('click', '[data-em-action="lista-add-modal"]', function () {
        $('#em_modal_lista_add').show();
        // reset
        contatosSelecionados = {};
        $('#em_busca_contato').val('').focus();
        $('#em_busca_resultados').empty();
        $('#em_busca_add_btn').prop('disabled', true).text('Adicionar selecionados');
        $('#em_form_lote')[0] && $('#em_form_lote')[0].reset();
        $('#em_form_csv')[0]  && $('#em_form_csv')[0].reset();
        $('#em_csv_resultado').hide().empty();
        // Volta para aba "busca"
        $('.em_tab').removeClass('em_tab_ativa');
        $('.em_tabs .em_tab[data-tab="busca"]').addClass('em_tab_ativa');
        $('.em_tab_painel').removeClass('em_tab_painel_ativa');
        $('.em_tab_painel[data-painel="busca"]').addClass('em_tab_painel_ativa');
    });

    /* --- Trocar de aba --- */
    $(document).on('click', '.em_tab', function () {
        var tab = $(this).data('tab');
        $('.em_tab').removeClass('em_tab_ativa');
        $(this).addClass('em_tab_ativa');
        $('.em_tab_painel').removeClass('em_tab_painel_ativa');
        $('.em_tab_painel[data-painel="' + tab + '"]').addClass('em_tab_painel_ativa');
    });

    /* --- Busca com debounce --- */
    var buscaTimer = null;
    $(document).on('input', '#em_busca_contato', function () {
        var q = $(this).val().trim();
        clearTimeout(buscaTimer);
        if (q.length < 2) {
            $('#em_busca_resultados').empty();
            return;
        }
        buscaTimer = setTimeout(function () {
            post('/admin/email-marketing/listas/buscar-contatos', {
                lista_id: listaId,
                busca: q
            }, function (r) {
                if (!r.ok) { showError(r); return; }
                renderResultadosBusca(r.itens);
            });
        }, 280);
    });

    function renderResultadosBusca(itens) {
        var $box = $('#em_busca_resultados').empty();
        if (!itens.length) {
            $box.append('<p class="em_meta" style="margin:0;">Nenhum contato disponível encontrado.</p>');
            return;
        }
        itens.forEach(function (c) {
            var checked = contatosSelecionados[c.id] ? 'checked' : '';
            var $row = $(
                '<label class="em_busca_item">' +
                    '<input type="checkbox" value="' + c.id + '" ' + checked + '>' +
                    '<span class="em_busca_email">' + $('<div/>').text(c.email).html() + '</span>' +
                    (c.nome ? '<span class="em_busca_nome">' + $('<div/>').text(c.nome).html() + '</span>' : '') +
                    '<span class="em_badge em_or_' + c.origem + '">' + c.origem + '</span>' +
                '</label>'
            );
            $row.find('input').on('change', function () {
                if (this.checked) contatosSelecionados[c.id] = c;
                else delete contatosSelecionados[c.id];
                atualizarBotaoAdd();
            });
            $box.append($row);
        });
        atualizarBotaoAdd();
    }

    function atualizarBotaoAdd() {
        var n = Object.keys(contatosSelecionados).length;
        var $btn = $('#em_busca_add_btn');
        $btn.prop('disabled', n === 0);
        $btn.text(n === 0 ? 'Adicionar selecionados' : 'Adicionar ' + n + ' contato' + (n > 1 ? 's' : ''));
    }

    /* --- Botão "Adicionar selecionados" --- */
    $(document).on('click', '#em_busca_add_btn', function () {
        var ids = Object.keys(contatosSelecionados);
        if (!ids.length) return;
        var $btn = $(this).prop('disabled', true).text('Adicionando...');

        var concluidos = 0, falhas = 0;
        function next() {
            if (concluidos + falhas >= ids.length) {
                flash('Adicionados: ' + concluidos + (falhas ? ' / falhas: ' + falhas : ''), true);
                setTimeout(function () { window.location.reload(); }, 800);
                return;
            }
            var id = ids[concluidos + falhas];
            post('/admin/email-marketing/listas/adicionar-contato', {
                lista_id: listaId,
                contato_id: id
            }, function (r) {
                if (r.ok) concluidos++; else falhas++;
                next();
            });
        }
        next();
    });

    /* --- Submeter lote (textarea) --- */
    $(document).on('submit', '#em_form_lote', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray().reduce(function (o, kv) { o[kv.name] = kv.value; return o; }, {});
        var $btn = $(this).find('button[type="submit"]').prop('disabled', true).text('Processando...');
        post('/admin/email-marketing/listas/adicionar-em-lote', data, function (r) {
            $btn.prop('disabled', false).text('Adicionar');
            if (!r.ok) { showError(r); return; }
            var s = r.resultado;
            var msg = 'Total emails: ' + s.total_emails +
                      ' | Adicionados: ' + s.adicionados +
                      ' | Já estavam: ' + s.ja_estavam +
                      (s.contatos_criados ? ' | Novos contatos: ' + s.contatos_criados : '') +
                      (s.suprimidos ? ' | Suprimidos: ' + s.suprimidos : '');
            flash(msg, true);
            setTimeout(function () { window.location.reload(); }, 1500);
        });
    });

    /* --- Upload de CSV --- */
    $(document).on('submit', '#em_form_csv', function (e) {
        e.preventDefault();
        var formData = new FormData(this);
        var $btn = $(this).find('button[type="submit"]').prop('disabled', true).text('Enviando...');

        $.ajax({
            url: BASE + '/admin/email-marketing/listas/importar-csv',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function (r) {
            $btn.prop('disabled', false).text('Importar');
            if (!r.ok) { showError(r); return; }
            var s = r.resultado;
            var html =
                '<div class="em_form_meta" style="flex-direction:column; align-items:flex-start; gap:6px;">' +
                    '<strong style="font-size:15px;">Importação concluída</strong>' +
                    '<div>Total de linhas processadas: <strong>' + s.total_linhas + '</strong></div>' +
                    '<div>Adicionados à lista: <strong>' + s.adicionados_lista + '</strong></div>' +
                    '<div>Contatos novos criados: <strong>' + s.contatos_criados + '</strong></div>' +
                    '<div>Já estavam na lista: <strong>' + s.ja_estavam_lista + '</strong></div>' +
                    (s.duplicados      ? '<div>Duplicados no arquivo: <strong>' + s.duplicados + '</strong></div>' : '') +
                    (s.emails_invalidos ? '<div>Emails inválidos: <strong>' + s.emails_invalidos + '</strong></div>' : '') +
                    (s.suprimidos      ? '<div>Pulados (suprimidos): <strong>' + s.suprimidos + '</strong></div>' : '') +
                '</div>';
            $('#em_csv_resultado').html(html).show();
            setTimeout(function () { window.location.reload(); }, 3000);
        }).fail(function () {
            $btn.prop('disabled', false).text('Importar');
            showError({ erro: 'Erro de comunicação ao enviar o arquivo.' });
        });
    });

    /* --- Remover contato da lista --- */
    $(document).on('click', '[data-em-action="lista-remover-contato"]', function () {
        var contatoId = $(this).data('contato-id');
        var email = $(this).data('email');
        if (!confirm('Remover "' + email + '" desta lista?\n\nO contato continuará existindo no banco.')) return;
        post('/admin/email-marketing/listas/remover-contato', {
            lista_id: listaId,
            contato_id: contatoId
        }, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });

    /* =============================================================================
   PATCH para assets/js/email-marketing.js — Templates visuais
   Adicione antes da última linha "})(window.jQuery);"
   ============================================================================= */

    /* =================================================================
       TEMPLATES VISUAIS (GrapesJS)
    ================================================================= */

    var $tplVisual = $('.em_template_visual');
    var grapesEditor = null;

    function initGrapesEditor() {
        if (!$tplVisual.length) return;
        if (typeof grapesjs === 'undefined') {
            $('#em_grapes_warn').show();
            return;
        }

        var initialHtml = $('#em_source_html').val() || '';
        var initialCss  = $('#em_source_css').val() || '';
        var initialJson = $('#em_source_json').val();
        var components = initialJson || initialHtml || '<table style="margin:auto; max-width:600px; width:100%;"><tr><td><h1>Bem-vindo, {{primeiro_nome}}!</h1><p>Edite este conteúdo arrastando blocos da barra lateral.</p></td></tr></table>';

        grapesEditor = grapesjs.init({
            container: '#em_grapes_editor',
            height: '800px',
            width: 'auto',
            storageManager: false,
            plugins: ['grapesjs-preset-newsletter'],
            pluginsOpts: {
                'grapesjs-preset-newsletter': {
                    inlineCss: true,
                    showBlocksOnLoad: true,
                    showStylesOnChange: true,
                    modalLabelImport: 'Cole seu HTML aqui',
                    modalLabelExport: 'HTML pronto para envio',
                    codeViewerTheme: 'material',
                    importPlaceholder: '<table><tr><td>...</td></tr></table>',
                    cellStyle: {
                        'font-size': '14px', 'font-weight': 400, 'vertical-align': 'top',
                        color: '#000', margin: 0, padding: 0
                    },
                    
                }
            },
            components: components,
            style: initialCss
        });

        // Adiciona bloco "variável" pra inserir {{cupom}} etc
        var bm = grapesEditor.BlockManager;
        bm.add('emkt-var-nome', {
            label: '{{primeiro_nome}}',
            category: 'Variáveis',
            content: '{{primeiro_nome}}'
        });
        bm.add('emkt-var-cupom', {
            label: '{{cupom}}',
            category: 'Variáveis',
            content: '{{cupom}}'
        });
        bm.add('emkt-var-email', {
            label: '{{email}}',
            category: 'Variáveis',
            content: '{{email}}'
        });
        bm.add('emkt-var-unsub', {
            label: 'Link cancelar inscrição',
            category: 'Variáveis',
            content: '<a href="{{url_descadastro}}" style="color:#999;">Cancelar inscrição</a>'
        });
    }

    /* Inicia o editor quando a view abre */
    if ($tplVisual.length) {
        // Aguarda assets do GrapesJS
        if (typeof grapesjs !== 'undefined') {
            initGrapesEditor();
        } else {
            // Aguarda um pouco e tenta de novo (caso o script ainda carregando)
            setTimeout(function () {
                if (typeof grapesjs !== 'undefined') initGrapesEditor();
                else $('#em_grapes_warn').show();
            }, 800);
        }
    }

    function getGrapesData() {
        if (!grapesEditor) return null;
        return {
            html: grapesEditor.getHtml(),
            css:  grapesEditor.getCss(),
            json: JSON.stringify(grapesEditor.getComponents())
        };
    }

    /* Preview do editor visual */
    $(document).on('click', '[data-em-action="tpl-preview-visual"]', function () {
        var data = getGrapesData();
        if (!data) { flash('Editor não inicializado.', false); return; }

        var w = window.open('', '_blank', 'width=800,height=900');
        var html = '<!doctype html><html><head><meta charset="utf-8"><title>Preview</title>' +
                   '<style>' + data.css + '</style></head><body>' + data.html + '</body></html>';
        w.document.open(); w.document.write(html); w.document.close();
    });

    /* Salvar template visual */
    $(document).on('click', '[data-em-action="tpl-salvar-visual"]', function () {
        var data = getGrapesData();
        if (!data) { flash('Editor não inicializado.', false); return; }

        var $btn = $(this).prop('disabled', true).text('Salvando...');

        var payload = {
            csrf_token: $('#em_csrf').val(),
            id:         $('#em_tpl_id').val(),
            nome:       $('#em_tpl_nome').val(),
            tipo:       $('#em_tpl_tipo').val(),
            status:     $('#em_tpl_status').val(),
            assunto:    $('#em_tpl_assunto').val(),
            preheader:  $('#em_tpl_preheader').val(),
            html:        data.html,
            source_json: data.json,
            source_css:  data.css
        };

        if (!payload.nome || !payload.assunto) {
            flash('Nome e assunto são obrigatórios.', false);
            $btn.prop('disabled', false).text('Salvar');
            return;
        }

        post('/admin/email-marketing/templates/salvar-visual', payload, function (r) {
            $btn.prop('disabled', false).text('Salvar');
            if (!r.ok) { showError(r); return; }

            if (r.avisos && r.avisos.length) {
                flash('Salvo com avisos: ' + r.avisos.join(', '), true);
            } else {
                flash('Template salvo.', true);
            }

            if (payload.id === '0' || payload.id === 0) {
                window.location.href = BASE + '/admin/email-marketing/templates/' + r.id + '/editar';
            }
        });
    });

    /* Duplicar template */
    $(document).on('click', '[data-em-action="tpl-duplicar"]', function () {
        var id = $(this).data('id');
        post('/admin/email-marketing/templates/duplicar', { id: id }, function (r) {
            if (!r.ok) { showError(r); return; }
            window.location.href = BASE + '/admin/email-marketing/templates/' + r.id + '/editar';
        });
    });

    /* Restaurar versão */
    $(document).on('click', '[data-em-action="tpl-restaurar"]', function () {
        var versaoId = $(this).data('versao-id');
        var versao = $(this).data('versao');
        if (!confirm('Restaurar a versão v' + versao + '?\n\nUm snapshot da versão atual será criado antes da restauração.')) return;
        post('/admin/email-marketing/templates/restaurar-versao', { versao_id: versaoId }, function (r) {
            if (r.ok) { flash('Versão restaurada.', true); setTimeout(function(){ window.location.reload(); }, 600); }
            else showError(r);
        });
    });

    /* =============================================================================
   PATCH para assets/js/email-marketing.js — Teste A/B
   Adicione antes da última linha "})(window.jQuery);"
   ============================================================================= */

    /* =================================================================
       AB TESTING
    ================================================================= */

    var $abForm = $('.em_ab_form');
    var $abRel  = $('.em_ab_relatorio');

    /* Auto-atualiza o campo rollout (100 - A - B) */
    function recalcRollout() {
        var a = parseInt($('#em_ab_pct_a').val(), 10) || 0;
        var b = parseInt($('#em_ab_pct_b').val(), 10) || 0;
        var rollout = 100 - a - b;
        $('#em_ab_rollout').val(rollout);
        if (rollout < 20) $('#em_ab_rollout').css('color', 'var(--em-warn-text)');
        else $('#em_ab_rollout').css('color', '');
    }
    $(document).on('input', '#em_ab_pct_a, #em_ab_pct_b', recalcRollout);

    /* Salvar configuração A/B */
    $(document).on('click', '[data-em-action="ab-salvar"]', function () {
        var campanhaId = $abForm.data('campanha');
        var $btn = $(this).prop('disabled', true).text('Salvando...');

        var data = {
            csrf_token: $('#em_csrf').val(),
            campanha_id: campanhaId,
            ab_amostra_pct_a: $('#em_ab_pct_a').val(),
            ab_amostra_pct_b: $('#em_ab_pct_b').val(),
            ab_metrica: $('#em_ab_metrica').val(),
            ab_tempo_analise_min: $('#em_ab_tempo').val(),
            ab_min_eventos: $('#em_ab_min').val(),
            ab_em_empate: $('#em_ab_empate').val(),
            ab_envio_automatico: $('#em_ab_auto').is(':checked') ? 1 : 0
        };

        // Serializa variações A e B
        ['a','b'].forEach(function (letra) {
            ['template_id','assunto','preheader','remetente_nome','remetente_email'].forEach(function (campo) {
                data[letra + '[' + campo + ']'] = $('input[name="' + letra + '[' + campo + ']"], select[name="' + letra + '[' + campo + ']"]').val();
            });
        });

        post('/admin/email-marketing/ab/salvar-variacoes', data, function (r) {
            $btn.prop('disabled', false).text('Salvar configuração');
            if (r.ok) flash('Configuração A/B salva.', true);
            else showError(r);
        });
    });

    /* Desativar A/B */
    $(document).on('click', '[data-em-action="ab-desativar"]', function () {
        if (!confirm('Desativar o teste A/B? As variações salvas serão mantidas.')) return;
        var id = $(this).data('id');
        post('/admin/email-marketing/ab/desativar', { campanha_id: id }, function (r) {
            if (r.ok) {
                flash('A/B desativado.', true);
                setTimeout(function(){
                    window.location.href = BASE + '/admin/email-marketing/campanhas/' + id + '/editar';
                }, 500);
            } else showError(r);
        });
    });

    /* Escolha manual de vencedor */
    $(document).on('click', '[data-em-action="ab-escolher"]', function () {
        var campanhaId = $abRel.data('campanha');
        var vencedor = $(this).data('vencedor');
        if (!confirm('Aplicar variação ' + vencedor.toUpperCase() + ' aos destinatários restantes?\n\nEsta ação não pode ser desfeita.')) return;

        var $btn = $(this).prop('disabled', true).text('Aplicando...');
        post('/admin/email-marketing/ab/escolher-vencedor', {
            campanha_id: campanhaId, vencedor: vencedor
        }, function (r) {
            $btn.prop('disabled', false).text('Aplicar variação ' + vencedor.toUpperCase());
            if (r.ok) {
                flash('Vencedor aplicado. ' + r.qtd_rollout + ' destinatários no rollout.', true);
                setTimeout(function(){ window.location.reload(); }, 800);
            } else showError(r);
        });
    });

    /* Inicializa rollout se a tela for da variação */
    if ($abForm.length) recalcRollout();

    /* =============================================================================
   PATCH para assets/js/email-marketing.js — CSV import
   Adicione este bloco antes da última linha "})(window.jQuery);"
   ============================================================================= */

    /* =================================================================
       CSV IMPORT WIZARD
    ================================================================= */

    var $csvWizard = $('.em_csv_wizard');
    var csvState = {
        importacaoId: null,
        header: [],
        preview: [],
        totalEstimado: 0
    };

    /* --- Navegação entre steps --- */
    function csvIrPara(step) {
        $('.em_step').removeClass('em_step_ativo em_step_completo');
        for (var i = 1; i <= 4; i++) {
            var $s = $('.em_step[data-step="' + i + '"]');
            if (i < step) $s.addClass('em_step_completo');
            if (i === step) $s.addClass('em_step_ativo');
        }
        $('.em_step_painel').removeClass('em_step_ativo');
        $('.em_step_painel[data-painel="' + step + '"]').addClass('em_step_ativo');
    }

    $(document).on('click', '[data-em-action="csv-voltar"]', function () {
        csvIrPara(parseInt($(this).data('para'), 10));
    });
    $(document).on('click', '[data-em-action="csv-ir"]', function () {
        csvIrPara(parseInt($(this).data('para'), 10));
    });

    /* --- Step 1: upload --- */
    $(document).on('submit', '#em_form_upload', function (e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]').prop('disabled', true).text('Enviando...');

        var fd = new FormData(this);
        $.ajax({
            url: BASE + '/admin/email-marketing/csv/upload',
            type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json'
        }).done(function (r) {
            $btn.prop('disabled', false).text('Enviar e analisar');
            if (!r.ok) { showError(r); return; }
            csvState.importacaoId = r.importacao_id;
            csvState.header = r.header || [];
            csvState.preview = r.preview || [];
            csvState.totalEstimado = r.total_estimado;
            $('#em_importacao_id').val(r.importacao_id);
            renderPreview();
            renderMapeamento();
            csvIrPara(2);
        }).fail(function () {
            $btn.prop('disabled', false).text('Enviar e analisar');
            showError({ erro: 'Erro de comunicação ao enviar o arquivo.' });
        });
    });

    function renderPreview() {
        var html = '<div class="em_meta">Total estimado: <strong>' +
                   csvState.totalEstimado.toLocaleString('pt-BR') + '</strong> linhas. ' +
                   'Pré-visualização das primeiras linhas:</div>';
        html += '<table class="em_table" style="font-size:12px;"><thead><tr>';

        var headers = csvState.header.length
            ? csvState.header
            : (csvState.preview[0] || []).map(function (_, i) { return 'col ' + (i + 1); });

        headers.forEach(function (h, i) {
            html += '<th>' + (i+1) + '. ' + $('<div/>').text(h).html() + '</th>';
        });
        html += '</tr></thead><tbody>';
        csvState.preview.forEach(function (row) {
            html += '<tr>';
            headers.forEach(function (_, i) {
                html += '<td>' + $('<div/>').text(row[i] || '').html() + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table>';
        $('#em_preview_box').html(html);
    }

    function renderMapeamento() {
        var headers = csvState.header.length
            ? csvState.header
            : (csvState.preview[0] || []).map(function (_, i) { return 'col ' + (i + 1); });

        $('.em_map_select').each(function () {
            var $sel = $(this).empty();
            $sel.append('<option value="">— não mapear —</option>');
            headers.forEach(function (h, i) {
                $sel.append('<option value="' + i + '">' + $('<div/>').text(h).html() + '</option>');
            });
            // Auto-detecção por nome
            var campo = $sel.data('campo');
            var idxAuto = headers.findIndex(function (h) {
                var hn = (h || '').toString().toLowerCase().trim();
                if (campo === 'email')           return hn === 'email' || hn === 'e-mail';
                if (campo === 'nome')            return hn === 'nome' || hn === 'name';
                if (campo === 'primeiro_nome')   return hn === 'primeiro_nome' || hn === 'first_name' || hn === 'nome_primeiro';
                if (campo === 'telefone')        return hn === 'telefone' || hn === 'phone' || hn === 'tel';
                if (campo === 'celular')         return hn === 'celular' || hn === 'cellphone' || hn === 'mobile';
                if (campo === 'documento')       return hn === 'documento' || hn === 'cpf' || hn === 'doc';
                if (campo === 'data_nascimento') return hn === 'data_nascimento' || hn === 'birthdate' || hn === 'nascimento';
                if (campo === 'genero')          return hn === 'genero' || hn === 'gender' || hn === 'sexo';
                if (campo === 'tags')            return hn === 'tags';
                if (campo === 'origem')          return hn === 'origem' || hn === 'origin';
                return false;
            });
            if (idxAuto > -1) $sel.val(idxAuto);
        });
    }

    /* --- Step 3: opções (toggles) --- */
    $(document).on('change', '#em_op_criar_lista', function () {
        $('#em_op_nome_lista_wrap').toggle(this.checked);
        if (this.checked) $('#em_op_lista_id').val('');
    });
    $(document).on('change', '#em_op_lista_id', function () {
        if ($(this).val()) {
            $('#em_op_criar_lista').prop('checked', false);
            $('#em_op_nome_lista_wrap').hide();
        }
    });

    /* --- Step 3: confirmar e enfileirar --- */
    $(document).on('click', '[data-em-action="csv-confirmar"]', function () {
        // valida mapeamento mínimo
        var mapEmail = $('select[name="mapeamento[email]"]').val();
        if (mapEmail === '' || mapEmail === null) {
            flash('Você precisa mapear a coluna de email.', false);
            csvIrPara(2);
            return;
        }

        var data = {
            csrf_token: getCsrf(),
            importacao_id: csvState.importacaoId,
            origem: $('#em_op_origem').val(),
            base_legal: $('#em_op_base_legal').val(),
            lista_id: $('#em_op_lista_id').val(),
            criar_lista: $('#em_op_criar_lista').is(':checked') ? 1 : 0,
            nome_nova_lista: $('#em_op_nome_nova_lista').val(),
            atualizar_existentes: $('#em_op_atualizar').is(':checked') ? 1 : 0,
            ignorar_suprimidos: $('#em_op_supressoes').is(':checked') ? 1 : 0,
            registrar_consentimento: $('#em_op_consent').is(':checked') ? 1 : 0
        };

        // serializa o mapeamento
        $('.em_map_select').each(function () {
            data['mapeamento[' + $(this).data('campo') + ']'] = $(this).val();
        });

        post('/admin/email-marketing/csv/confirmar', data, function (r) {
            if (!r.ok) { showError(r); return; }
            $('#em_link_detalhes').attr('href',
                BASE + '/admin/email-marketing/csv/' + csvState.importacaoId);
            csvIrPara(4);
            iniciarPolling(csvState.importacaoId);
        });
    });

    /* --- Polling de progresso --- */
    var pollTimer = null;
    function iniciarPolling(id) {
        if (pollTimer) clearInterval(pollTimer);
        function tick() {
            $.get(BASE + '/admin/email-marketing/csv/' + id + '/progresso', function (r) {
                if (!r.ok) return;
                var p = r.progresso;
                $('#em_progress_bar').css('width', p.progresso_pct + '%');
                $('#em_progress_text').text(
                    (p.linhas_processadas || 0).toLocaleString('pt-BR') + ' / ' +
                    (p.total_linhas || 0).toLocaleString('pt-BR') +
                    ' linhas (' + p.progresso_pct + '%) — status: ' + p.status
                );
                $('#em_cnt_proc').text((p.linhas_processadas||0).toLocaleString('pt-BR'));
                $('#em_cnt_ins').text((p.inseridos||0).toLocaleString('pt-BR'));
                $('#em_cnt_upd').text((p.atualizados||0).toLocaleString('pt-BR'));
                $('#em_cnt_dup').text((p.duplicados||0).toLocaleString('pt-BR'));
                $('#em_cnt_inv').text((p.invalidos||0).toLocaleString('pt-BR'));
                $('#em_cnt_sup').text((p.suprimidos||0).toLocaleString('pt-BR'));

                if (['concluido','cancelada','erro'].indexOf(p.status) > -1) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                }
            }, 'json');
        }
        tick();
        pollTimer = setInterval(tick, 2500);
    }

    /* --- Tela de detalhes — auto-polling se em processamento --- */
    var $detalhes = $('.em_wrapper[data-importacao]');
    if ($detalhes.length && $detalhes.data('em-processamento') === 1) {
        iniciarPolling($detalhes.data('importacao'));
    }

    /* --- Cancelar --- */
    $(document).on('click', '[data-em-action="csv-cancelar"]', function () {
        var id = $(this).data('id');
        if (!confirm('Cancelar esta importação? As linhas já processadas serão mantidas.')) return;
        post('/admin/email-marketing/csv/cancelar', { id: id }, function (r) {
            if (r.ok) window.location.reload(); else showError(r);
        });
    });

    /* =============================================================================
   PATCH para assets/js/email-marketing.js — Automações
   Adicione antes da última linha "})(window.jQuery);"
   ============================================================================= */

    /* =================================================================
       AUTOMAÇÕES
    ================================================================= */

    /* Toggle ativo/inativo */
    $(document).on('click', '[data-em-action="auto-toggle"]', function () {
        var id    = $(this).data('id');
        var ativo = $(this).data('ativo');
        post('/admin/email-marketing/automacoes/toggle', { id: id, ativo: ativo }, function (r) {
            if (r.ok) window.location.reload();
            else showError(r);
        });
    });

    /* Salvar configuração do fluxo */
    $(document).on('click', '[data-em-action="auto-salvar"]', function () {
        var $btn = $(this).prop('disabled', true).text('Salvando...');

        var data = {
            csrf_token:       $('#em_csrf').val(),
            id:               $('#em_fluxo_id').val(),
            nome:             $('#em_auto_nome').val(),
            ativo:            $('#em_auto_ativo').is(':checked') ? 1 : 0,
            cupom_pct:        $('#em_auto_cupom_pct').val(),
            cupom_dias_validade: $('#em_auto_cupom_dias').val(),
            min_visitas:      $('#em_auto_min_visitas').val(),
            dias_sem_compra:  $('#em_auto_dias_sem_compra').val(),
            delay_dias:       $('#em_auto_delay_dias').val()
        };

        // Delays como arrays
        var delaysRaw = $('#em_auto_delays').val();
        if (delaysRaw) {
            delaysRaw.split(',').forEach(function (v, i) {
                data['delays_horas[' + i + ']'] = parseInt(v.trim(), 10);
            });
        }

        // Templates por passo
        $('.em_passo_template').each(function () {
            var passoId = $(this).data('passo');
            data['passo[' + passoId + '][template_id]'] = $(this).val();
        });

        post('/admin/email-marketing/automacoes/salvar', data, function (r) {
            $btn.prop('disabled', false).text('Salvar configuração');
            if (r.ok) flash('Automação salva.', true);
            else showError(r);
        });
    });

    /* Abre modal de teste */
    $(document).on('click', '[data-em-action="transacional-testar"]', function () {
        var tipo = $(this).data('tipo');
        $('#em_teste_tipo').val(tipo);
        $('#em_teste_tipo_label').text(tipo);
        $('#em_teste_email').val('');
        $('#em_modal_transacional_teste').show();
    });

    /* Envia teste */
    $(document).on('click', '[data-em-action="transacional-testar-enviar"]', function () {
        var tipo  = $('#em_teste_tipo').val();
        var email = $('#em_teste_email').val();
        if (!email) { alert('Informe um email.'); return; }
        var $btn = $(this).prop('disabled', true).text('Enviando...');
        post('/admin/email-marketing/transacional/testar', {
            tipo: tipo, email: email
        }, function (r) {
            $btn.prop('disabled', false).text('Enviar teste');
            $('#em_modal_transacional_teste').hide();
            flash(r.msg || r.erro, r.ok);
        });
    });

})(window.jQuery);
