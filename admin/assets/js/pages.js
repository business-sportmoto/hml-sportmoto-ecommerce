$(function () {

    // Configurações - Status de pedidos
  (function () {
    var BASE   = BASE_URL + '/admin/configuracoes/status-pedidos';
    var CSRF   = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // ── Novo status ────────────────────────────────────────
    $('#btn-novo-status').on('click', function () {
        resetarForm(null, false);
        $('#modal-status-titulo').text('Novo status');
        abrirModal('modal-status');
    });

    // ── Editar status ──────────────────────────────────────
    document.querySelectorAll('.sp-btn-edit').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(this.dataset.id, 10);
        carregarParaEdicao(id);
      });
    });

    // ── Excluir status ─────────────────────────────────────
    document.querySelectorAll('.sp-btn-del').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id    = this.dataset.id;
        var label = this.dataset.label;
        if (!confirm('Excluir o status "' + label + '"?\nEsta ação não pode ser desfeita.')) return;

        CK.post(BASE + '/excluir', { id: id })
          .done(function (res) {
            if (res.ok) {
              document.querySelector('.sp-row[data-id="' + id + '"]').remove();
              Toast.success('Status excluído.');
            } else {
              Toast.error(res.msg);
            }
          });
      });
    });

    // ── Cor picker ─────────────────────────────────────────
    document.querySelectorAll('.sp-cor-opt').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.querySelectorAll('.sp-cor-opt').forEach(function (b) {
          b.classList.remove('is-selected');
        });
        this.classList.add('is-selected');
        document.getElementById('sp-cor').value = this.dataset.cor;
      });
    });

    // ── Slug auto-gerado ───────────────────────────────────
    $(document).on('input', '#sp-label', function () {
        var isPadrao = $('#sp-is-padrao').val() === '1';
        var hasId    = $('#sp-edit-id').val();

        if (!isPadrao) {
            $('#sp-slug').val(slugify($(this).val()));
        }
    });

    // ── Salvar ─────────────────────────────────────────────
    $(document).on('click', '#btn-sp-salvar', function () {
      var $btn = $(this);
      var $msg = $('#sp-form-msg');

      $msg.hide();

      CK.btnLoading(this);

      var dados = {
          id:                    $('#sp-edit-id').val() || '',
          label:                 $('#sp-label').val(),
          slug:                  $('#sp-slug').val(),
          cor:                   $('#sp-cor').val(),
          icone_key:             $('#sp-icone-key').val(),
          ordenacao:             $('#sp-ordenacao').val(),
          ativo:                 $('#sp-ativo').is(':checked') ? 1 : 0,
          estorna_estoque:       $('#sp-estorna-estoque').is(':checked') ? 1 : 0,
          cancela_cupom:         $('#sp-cancela-cupom').is(':checked') ? 1 : 0,
          bloqueia_edicao_itens: $('#sp-bloqueia-edicao').is(':checked') ? 1 : 0,
          notifica_cliente:      $('#sp-notifica-cliente').is(':checked') ? 1 : 0
      };

      CK.post(BASE + '/salvar', dados)
          .done(function (res) {
              CK.btnLoading($btn[0], false);

              if (res.ok) {
                  Toast.success(res.msg);
                  fecharModal('modal-status');

                  setTimeout(function () {
                      location.reload();
                  }, 500);

                  return;
              }

              $msg
                  .text(res.msg)
                  .attr('class', 'form-alert form-alert--error')
                  .show();
          })
          .fail(function () {
              CK.btnLoading($btn[0], false);

              $msg
                  .text('Erro de conexão.')
                  .attr('class', 'form-alert form-alert--error')
                  .show();
          });
  });

    // ── Helpers ─────────────────────────────────────────────
    function carregarParaEdicao(id) {
      var row   = document.querySelector('.sp-row[data-id="' + id + '"]');
      if (!row) return;

      // Busca dados do servidor (mais seguro que ler do DOM)
      $.get(BASE + '/dados/' + id, function (res) {
        if (!res.ok) { Toast.error('Erro ao carregar status.'); return; }
        var s = res.status;
        resetarForm(s, !!s.padrao);
        document.getElementById('modal-status-titulo').textContent = 'Editar: ' + s.label;
        abrirModal('modal-status');
      });
    }

    function resetarForm(s, isPadrao) {
      document.getElementById('sp-edit-id').value    = s ? s.id : '';
      document.getElementById('sp-is-padrao').value  = isPadrao ? '1' : '0';
      document.getElementById('sp-label').value      = s ? s.label : '';
      document.getElementById('sp-ordenacao').value  = s ? s.ordenacao : '50';
      document.getElementById('sp-icone-key').value  = s ? (s.icone_key || '') : '';
      document.getElementById('sp-ativo').checked    = s ? !!s.ativo : true;
      document.getElementById('sp-estorna-estoque').checked       = s ? !!s.estorna_estoque : false;
      document.getElementById('sp-cancela-cupom').checked          = s ? !!s.cancela_cupom : false;
      document.getElementById('sp-bloqueia-edicao').checked        = s ? !!s.bloqueia_edicao_itens : true;
      document.getElementById('sp-notifica-cliente').checked       = s ? !!s.notifica_cliente : true;

      // Slug
      var slugInput = document.getElementById('sp-slug');
      slugInput.value    = s ? s.slug : '';
      slugInput.readOnly = isPadrao;
      slugInput.style.background = isPadrao ? '#f8fafc' : '';
      document.getElementById('sp-slug-lock').textContent = isPadrao
        ? '— protegido (status do sistema)' : '— gerado automaticamente';

      // Cor
      var cor = s ? s.cor : 'info';
      document.getElementById('sp-cor').value = cor;
      document.querySelectorAll('.sp-cor-opt').forEach(function (b) {
        b.classList.toggle('is-selected', b.dataset.cor === cor);
      });

      document.getElementById('sp-form-msg').style.display = 'none';
    }

    function slugify(str) {
      var map = {'à':'a','á':'a','â':'a','ã':'a','ä':'a','å':'a','è':'e','é':'e',
                'ê':'e','ë':'e','ì':'i','í':'i','î':'i','ï':'i','ò':'o','ó':'o',
                'ô':'o','õ':'o','ö':'o','ù':'u','ú':'u','û':'u','ü':'u','ç':'c'};
      return str.toLowerCase()
        .replace(/[àáâãäåèéêëìíîïòóôõöùúûüç]/g, function (c) { return map[c] || c; })
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
    }

  })();

  // Banner Slots (Zonas)
  (function () {
      
      //  console.log(isEditZonaBanner);
      // ── Auto-gera chave ao digitar o nome ────────────────
      const $nome  = $('#zona-nome');
      const $chave = $('#zona-chave');
      let isEdit = false;

      if (typeof isEditZonaBanner !== 'undefined' && isEditZonaBanner) {
          isEdit = isEditZonaBanner;
      }
      // console.log(isEditZonaBanner);
      

      if (!isEdit) {
          $nome.on('input', function () {
          const slug = $(this).val()
              .toLowerCase()
              .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // remove acentos
              .replace(/[^a-z0-9\s_]/g, '')
              .trim()
              .replace(/\s+/g, '_');
          $chave.val(slug);
          atualizarPreview(slug);
          });
      }

      $chave.on('input', function () {
          atualizarPreview($(this).val());
      });

      function atualizarPreview(chave) {
          const clean = chave || 'minha_zona';
          $('#bz-code-preview').text(`<?php View::banner('${clean}') ?>`);
      }

      // ── Seletor de formato ───────────────────────────────
      $('input[name="formato"]').on('change', function () {
          $('.bz-formato-option').removeClass('is-selected');
          $(this).closest('.bz-formato-option').addClass('is-selected');
      });
      // Garante estado inicial
      $('input[name="formato"]:checked').closest('.bz-formato-option').addClass('is-selected');

      // ── Salvar ───────────────────────────────────────────
      $('#btn-salvar-zona').on('click', async function () {
          const $btn  = $(this);
          const nome  = $('#zona-nome').val().trim();
          const chave = $('#zona-chave').val().trim();

          if (!nome) { adminToast('Informe o nome da zona.', 'error'); return; }
          if (!chave) { adminToast('Chave inválida.', 'error'); return; }

          $btn.prop('disabled', true).text('Salvando…');

          $.post(BASE_URL + '/admin/banner-zonas/salvar', $('#form-zona').serialize(), function (res) {
          $btn.prop('disabled', false).html(
              '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg> Salvar zona'
          );
          if (res.ok) {
              adminToast(res.msg || 'Zona salva!', 'success');
              setTimeout(() => {
              window.location.href = BASE_URL + '/admin/banner-zonas';
              }, 700);
          } else {
              adminToast(res.msg || 'Erro ao salvar.', 'error');
          }
          }, 'json').fail(() => {
          $btn.prop('disabled', false).text('Salvar zona');
          adminToast('Erro de conexão.', 'error');
          });
      });
  })();

  // Lista de Zonas - 
  $(function () {
      
      // ── Toggle ativo ──────────────────────────────────────
      $(document).on('click', '.bz-btn-toggle', function () {
          const id   = $(this).data('id');
          const $btn = $(this);
          const $card= $(`#bz-card-${id}`);

          $.post(BASE_URL + '/admin/banner-zonas/toggle-ativo', {
          id, _csrf_token: CSRF_TOKEN,
          }, function (res) {
          if (!res.ok) return;
          $card.toggleClass('is-inativo', !res.ativo);
          const label = res.ativo ? 'Pausar' : 'Ativar';
          adminToast(res.ativo ? 'Zona ativada.' : 'Zona pausada.', 'success');
          setTimeout(() => location.reload(), 600);
          }, 'json');
      });

      // ── Excluir ───────────────────────────────────────────
      $(document).on('click', '.bz-btn-excluir', async function () {
          const id      = $(this).data('id');
          const nome    = $(this).data('nome');
          const banners = parseInt($(this).data('banners')) || 0;

          if (banners > 0) {
          adminToast(`Remove os ${banners} banner(s) desta zona antes de excluí-la.`, 'error');
          return;
          }

          const ok = window.adminConfirm
          ? await window.adminConfirm({
              titulo:    'Excluir zona?',
              mensagem:  `A zona "${nome}" será removida permanentemente. Os banners vinculados a ela também serão excluídos.`,
              tipo:      'danger',
              confirmar: 'Excluir zona',
              })
          : confirm(`Excluir zona "${nome}"?`);

          if (!ok) return;

          $.post(BASE_URL + '/admin/banner-zonas/excluir', {
          id, _csrf_token: CSRF_TOKEN,
          }, function (res) {
          if (res.ok) {
              $(`#bz-card-${id}`).fadeOut(280, function () { $(this).remove(); });
              adminToast('Zona excluída.', 'success');
          } else {
              adminToast(res.msg || 'Erro.', 'error');
          }
          }, 'json');
      });

  });

  $(function () {
  // Toggle pausar/ativar
      $(document).on('click', '.btn-icon--toggle', function () {
          const id    = $(this).data('id');
          const ativo = $(this).data('ativo');
          const label = ativo ? 'pausar' : 'ativar';
          if (!confirm(`Deseja ${label} este cupom?`)) return;

          CK.post('/admin/cupons/toggle-ativo', { id })
          .done(res => {
              if (res.ok) adminToast(`Cupom ${label === 'pausar' ? 'pausado' : 'ativado'}.`, 'success');
              setTimeout(() => window.location.reload(), 600);
          })
          .fail(() => adminToast('Erro ao alterar status.', 'error'));
      });

      // Deletar
      $(document).on('click', '.btn-delete', function () {
          const id   = $(this).data('id');
          const nome = $(this).data('nome');
          if (!confirm(`Excluir o cupom "${nome}"? Esta ação não pode ser desfeita.`)) return;

          CK.post('/admin/cupons/excluir', { id })
          .done(res => {
              if (res.ok) { adminToast('Cupom excluído.', 'success'); setTimeout(() => window.location.reload(), 600); }
              else adminToast(res.msg || 'Erro ao excluir.', 'error');
          });
      });
  
      // Atualiza símbolo e mostra/esconde valor com base no tipo
      function syncTipo() {
          const tipo = $('#campo-tipo').val();
          const semValor = ['frete_gratis','automatico','recuperacao_carrinho'];
          const pct      = ['percentual','primeira_compra','exclusivo'];

          if (semValor.includes(tipo)) {
          $('#campo-valor-wrap').hide();
          } else {
          $('#campo-valor-wrap').show();
          $('#valor-symbol').text(pct.includes(tipo) ? '%' : 'R$');
          }
          $('#progressivo-section').toggle(tipo === 'progressivo');
      }
      $('#campo-tipo').on('change', syncTipo);
      syncTipo();

      // Atualiza código do vendedor no hidden
      $('select[name="vendedor_id"]').on('change', function () {
          const codigo = $(this).find(':selected').data('codigo') || '';
          $('#codigo_vendedor_hidden').val(codigo);
      });

      // Adicionar faixa progressiva
      $('#btn-add-progressivo').on('click', function () {
          const row = `<div class="progressivo-row">
          <input type="number" name="prog_min[]"   class="form-control" placeholder="Min">
          <input type="number" name="prog_max[]"   class="form-control" placeholder="Max">
          <input type="number" name="prog_valor[]" class="form-control" placeholder="Valor">
          <select name="prog_tipo[]" class="form-control">
              <option value="percentual">%</option>
              <option value="fixo">R$</option>
          </select>
          <button type="button" class="btn-icon btn-icon--danger btn-remove-row">×</button>
          </div>`;
          $('#progressivo-table').append(row);
      });
      $(document).on('click', '.btn-remove-row', function () { $(this).closest('.progressivo-row').remove(); });

      // Submit
      $('#form-cupom').on('submit', function (e) {
          e.preventDefault();
          const $btn = $('#btn-salvar');
          const $err = $('#form-error');
          CK.formAlertClear($err);

          // Monta regras progressivas como JSON
          const rows = [];
          $('.progressivo-row').each(function () {
          rows.push({
              min:   $(this).find('[name="prog_min[]"]').val(),
              max:   $(this).find('[name="prog_max[]"]').val(),
              valor: $(this).find('[name="prog_valor[]"]').val(),
              tipo:  $(this).find('[name="prog_tipo[]"]').val(),
          });
          });

          const data = $(this).serializeArray().reduce((a, x) => (a[x.name] = x.value, a), {});
          if (rows.length) data.regras_progressivas = JSON.stringify(rows);

          // Checkboxes não marcados
          ['ativo','apenas_primeira_compra','permite_produto_promo','acumula_desconto'].forEach(n => {
          if (!data[n]) data[n] = '0';
          });

          
          CK.btnLoading($btn);
          CK.post('/admin/cupons/salvar', data)
          .done(res => {
              if (res.ok) {
                  CK.toast(res.msg || 'Salvo!', 'success');
                  setTimeout(() => window.location.href = res.redirect || BASE_URL + '/admin/cupons', 600);
              } else {
                  CK.btnLoading($btn, false);
                  // CK.formAlertSet($err, res.errors ? res.errors.join(' ') : (res.msg || 'Erro ao salvar.'));
                    CK.toast(res.errors ? res.errors.join(' ') : (res.msg || 'Erro ao salvar.'), 'error')
              }
          })
          .fail(() => { 
              CK.btnLoading($btn, false); 
              // CK.formAlertSet($err, 'Erro de conexão.'); 
              CK.toast('Erro de conexão.', 'error')
          });
      });
  
      $(document).on('click', '.admin-tab', function () {
          const tab = $(this).data('tab');
          $('.admin-tab').removeClass('is-active');
          $(this).addClass('is-active');
          $('.admin-tab-panel').attr('hidden', true).removeClass('is-active');
          $('#tab-' + tab).removeAttr('hidden').addClass('is-active');
      });
  
      // Gráfico de linha: usos por dia
      if (typeof usosDia !== 'undefined' && usosDia.length) {
          new Chart(document.getElementById('chart-usos-dia'), {
          type: 'line',
          data: {
              labels:   usosDia.map(r => r.dia),
              datasets: [{
              label: 'Usos confirmados',
              data:  usosDia.map(r => r.total),
              borderColor: '#2563eb',
              backgroundColor: 'rgba(37,99,235,.08)',
              tension: .4, fill: true, pointRadius: 3,
              }],
          },
          options: {
              responsive: true,
              plugins: { legend: { display: false } },
              scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
          },
          });
      }

      // Gráfico de rosca: tipos
      if (typeof tiposData !== 'undefined' && tiposData.length) {
          new Chart(document.getElementById('chart-tipo'), {
          type: 'doughnut',
          data: {
              labels: tiposData.map(r => r.tipo),
              datasets: [{
              data:  tiposData.map(r => r.total),
              backgroundColor: ['#2563eb','#7c3aed','#16a34a','#d97706','#dc2626','#0891b2','#be185d','#4f46e5','#065f46'],
              }],
          },
          options: {
              responsive: true,
              plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
              cutout: '65%',
          },
          });
      }
  });

  // Pagamento - Transação Detalhe
  (function () {
    var BASE = (BASE_URL + '/admin');
    // Recalcular score
    $('#btn-recalcular-score').on('click', function () {
      var $btn = $(this); CK.btnLoading($btn);
      CK.post(BASE_SCORE_CLIENTE + '/score/recalcular', {}).done(function (res) {
        CK.btnLoading($btn, false);
        if (res.ok) {
          $('#sc-valor').text(res.score);
          $('#sc-tier-badge').text(res.tier_label);
          var pct = Math.min(100, Math.round(res.score / 600 * 100));
          $('#sc-barra').css('width', pct + '%');
          Toast.success('Score recalculado: ' + res.score + ' — ' + res.tier_label);
        }
      });
    });

    // Override modal
    $('#btn-override-score').on('click', function () { abrirModal('modal-override'); });
    $('#btn-confirmar-override').on('click', function () {
      var $btn = $(this); CK.btnLoading($btn);
      CK.post(BASE_SCORE_CLIENTE + '/score/override', {
        score: $('#ov-score').val(), motivo: $('#ov-motivo').val()
      }).done(function (res) {
        CK.btnLoading($btn, false);
        if (res.ok) { Toast.success(res.msg); fecharModal('modal-override'); setTimeout(() => location.reload(), 600); }
        else Toast.error(res.msg);
      });
    });

    // Remover override
    $('#btn-remover-override') && $('#btn-remover-override').on('click', function () {
      if (!confirm('Remover o override e recalcular o score automaticamente?')) return;
      CK.post(BASE_SCORE_CLIENTE + '/score/remover-override', {}).done(function (res) {
        if (res.ok) { Toast.success(res.msg); setTimeout(() => location.reload(), 600); }
      });
    });

    // Lançar crédito
    $('#btn-lancar-credito').on('click', function () {
      var $btn = $(this); CK.btnLoading($btn);
      CK.post(BASE_SCORE_CLIENTE + '/credito/lancar', {
        valor: $('#cr-valor').val(), descricao: $('#cr-desc').val(), dias_expiracao: $('#cr-dias').val()
      }).done(function (res) {
        CK.btnLoading($btn, false);
        if (res.ok) {
          $('#saldo-display').text(res.saldo_fmt);
          $('#cr-valor, #cr-desc, #cr-dias').val('');
          Toast.success('Crédito lançado! Novo saldo: ' + res.saldo_fmt);
          setTimeout(() => location.reload(), 1000);
        } else Toast.error(res.msg);
      });
    });

    // Modal débito
    $('#btn-debitar').on('click', function () { abrirModal('modal-debito'); });
    $('#btn-confirmar-debito').on('click', function () {
      var $btn = $(this); var $msg = $('#debito-msg'); CK.btnLoading($btn); CK.formAlertClear($msg);
      CK.post(BASE_SCORE_CLIENTE + '/credito/debitar', {
        valor: $('#db-valor').val(), descricao: $('#db-desc').val()
      }).done(function (res) {
        CK.btnLoading($btn, false);
        if (res.ok) { Toast.success('Débito realizado.'); fecharModal('modal-debito'); setTimeout(() => location.reload(), 600); }
        else CK.formAlertSet($msg, res.msg);
      });
    });

      $(function () {

        // ─────────────────────────────────────────────
        // Consultar gateway
        // ─────────────────────────────────────────────
        var $btnConsultar = $('#btn-consultar-gateway');
        var $outConsulta  = $('#consulta-resultado');

        if ($btnConsultar.length && $outConsulta.length) {
            $(document).on('click', '#btn-consultar-gateway', function () {
                var $btn = $(this);

                $btn.prop('disabled', true);
                $btn.html($btn.html().replace(/Consultar gateway/, 'Consultando…'));

                var form = new FormData();
                form.append('_csrf_token', CSRF_TOKEN);

                $.ajax({
                    url: BASE_TRANSACAO_DETALHE + '/consultar',
                    type: 'POST',
                    data: form,
                    dataType: 'json',
                    processData: false,
                    contentType: false,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .done(function (resp) {
                    $btn.prop('disabled', false);
                    $btn.html($btn.html().replace(/Consultando…/, 'Consultar gateway'));

                    $outConsulta
                        .show()
                        .attr(
                            'class',
                            resp.ok
                                ? 'pgto_alerta ' + (resp.mudou ? 'pgto_alerta_aviso' : 'pgto_alerta_ok')
                                : 'pgto_alerta pgto_alerta_erro'
                        )
                        .text(resp.msg || (resp.ok ? 'Consulta concluída.' : 'Falha na consulta.'));

                    // Se o status mudou, atualiza o badge sem recarregar imediatamente
                    if (resp.ok && resp.mudou) {
                        var $badge = $('.pgto_status_big').first();

                        if ($badge.length) {
                            $badge
                                .removeClass(function (index, className) {
                                    return (className.match(/\bpgto_status_\S+/g) || []).join(' ');
                                })
                                .addClass('pgto_status_big pgto_status_' + resp.status)
                                .text(
                                    resp.status.charAt(0).toUpperCase() +
                                    resp.status.slice(1).replace(/_/g, ' ')
                                );
                        }

                        // Recarrega após 3s para atualizar webhooks, timeline etc.
                        setTimeout(function () {
                            window.location.reload();
                        }, 3000);
                    }
                })
                .fail(function () {
                    $btn.prop('disabled', false);
                    $btn.html($btn.html().replace(/Consultando…/, 'Consultar gateway'));

                    $outConsulta
                        .show()
                        .attr('class', 'pgto_alerta pgto_alerta_erro')
                        .text('Erro de comunicação. Tente novamente.');
                });
            });
        }


        // ─────────────────────────────────────────────
        // Modal de estorno
        // ─────────────────────────────────────────────
        var $modal        = $('#modal-estorno');
        var $openBtn      = $('#btn-abrir-estorno');
        var $tipo         = $('#estorno-tipo');
        var $campoParcial = $('#campo-valor-parcial');
        var $form         = $('#form-estorno');
        var $btnEstorno   = $('#btn-confirmar-estorno');
        var $errBox       = $('#estorno-erro');
        var $okBox        = $('#estorno-ok');

        function abrirModalEstorno() {
            $modal.prop('hidden', false);
            $('body').css('overflow', 'hidden');
        }

        function fecharModalEstorno() {
            $modal.prop('hidden', true);
            $('body').css('overflow', '');
        }

        if ($modal.length) {

            $(document).on('click', '#btn-abrir-estorno', function () {
                abrirModalEstorno();
            });

            $(document).on('click', '#modal-estorno [data-close]', function () {
                fecharModalEstorno();
            });

            $(document).on('change', '#estorno-tipo', function () {
                var tipo = $(this).val();

                $campoParcial.prop('hidden', tipo !== 'parcial');
            });

            $(document).on('submit', '#form-estorno', function (e) {
                e.preventDefault();

                $errBox.prop('hidden', true);
                $okBox.prop('hidden', true);

                var motivo = $.trim($('#estorno-motivo').val());

                if (motivo.length < 5) {
                    $errBox
                        .text('Informe um motivo de pelo menos 5 caracteres.')
                        .prop('hidden', false);

                    return;
                }

                var tipo = $('#estorno-tipo').val();
                var valor = '';

                if (tipo === 'parcial') {
                    valor = $.trim($('#estorno-valor').val());

                    if (!valor || parseFloat(valor) <= 0) {
                        $errBox
                            .text('Informe um valor válido para o estorno parcial.')
                            .prop('hidden', false);

                        return;
                    }
                }

                $btnEstorno
                    .prop('disabled', true)
                    .text('Processando…');

                var data = new FormData(this);

                if (tipo === 'total') {
                    data.delete('valor');
                }

                $.ajax({
                    url: BASE_TRANSACAO_DETALHE + '/estornar',
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    processData: false,
                    contentType: false,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .done(function (resp) {
                    $btnEstorno
                        .prop('disabled', false)
                        .text('Confirmar estorno');

                    if (resp.ok) {
                        $okBox
                            .text(resp.msg || 'Estorno solicitado com sucesso.')
                            .prop('hidden', false);

                        setTimeout(function () {
                            window.location.reload();
                        }, 2000);

                        return;
                    }

                    $errBox
                        .text(resp.msg || 'Erro ao processar estorno.')
                        .prop('hidden', false);
                })
                .fail(function () {
                    $btnEstorno
                        .prop('disabled', false)
                        .text('Confirmar estorno');

                    $errBox
                        .text('Erro de comunicação. Tente novamente.')
                        .prop('hidden', false);
                });
            });
        }

    });

  })();    
});

/**
 * admin-pedidos.js
 * AJAX handlers para /admin/pedidos/{id} (show.php)
 * Requer: CK (admin-core.js), BASE_URL, PEDIDO_ID
 */
;(function ($) {
  'use strict';

  if (typeof PEDIDO_ID === 'undefined') return; // só roda na show

  var BASE = (BASE_URL + '/admin');

  // ── Helpers ─────────────────────────────────────────────
  function endpoint(path) {
    return BASE + '/pedidos/' + PEDIDO_ID + path;
  }
  function reloadTotals(totais) {
    if (!totais) return;
    $('#ap-totals').load(location.href + ' #ap-totals > *');
  }
 
  // ════════════════════════════════════════════════════════
  // STATUS
  // ════════════════════════════════════════════════════════
  $('#btn-salvar-status').on('click', function () {
    var $btn   = $(this);
    var $msg   = $('#status-msg');
    CK.formAlertClear($msg);
    CK.btnLoading($btn);
 
    CK.post(endpoint('/status'), {
      status_pedido: $('#sel-novo-status').val(),
      observacao:    $('#obs-status').val(),
      notificar:     $('#chk-notificar-status').is(':checked') ? 1 : 0,
    }).done(function (res) {
      CK.btnLoading($btn, false);
      if (res.ok) {
        Toast.success('Status atualizado!');
        // Aviso: retroação de nível ou estoque insuficiente na reativação
        if (res.aviso) {
          Toast.warning(res.aviso, 6000);
        }
        // Se foi reativado com falha de estoque, atrasa o reload para o admin ler o aviso
        var delay = (res.aviso && res.reativado) ? 4000 : 800;
        setTimeout(function () { location.reload(); }, delay);
      } else {
        CK.formAlertSet($msg, res.msg || 'Erro ao salvar.');
      }
    }).fail(function () {
      CK.btnLoading($btn, false);
      CK.formAlertSet($msg, 'Erro de conexão.');
    });
  });
 
  // ════════════════════════════════════════════════════════
  // RASTREIO
  // ════════════════════════════════════════════════════════
  $('#btn-salvar-rastreio').on('click', function () {
    var $btn = $(this);
    CK.btnLoading($btn);
    CK.post(endpoint('/rastreio'), {
      codigo_rastreio: $('#input-rastreio').val(),
      notificar:       $('#chk-notificar-rastreio').is(':checked') ? 1 : 0,
    }).done(function (res) {
      CK.btnLoading($btn, false);
      res.ok ? Toast.success('Rastreio salvo!') : Toast.error(res.msg);
    }).fail(function () { CK.btnLoading($btn, false); Toast.error('Erro de conexão.'); });
  });
 
  // ════════════════════════════════════════════════════════
  // PAGAMENTO
  // ════════════════════════════════════════════════════════
  $('#sel-forma-pag').on('change', function () {
    $('#campos-cartao').toggle($(this).val() === 'cartao');
  });
 
  $('#btn-salvar-pag').on('click', function () {
    var $btn = $(this);
    CK.btnLoading($btn);
    CK.post(endpoint('/pagamento'), {
      status_pagamento:  $('#sel-status-pag').val(),
      forma_pagamento:   $('#sel-forma-pag').val(),
      cartao_bandeira:   $('#input-bandeira').val(),
      cartao_ultimos_4:  $('#input-ultimos4').val(),
      pago_em:           $('#input-pago-em').val(),
    }).done(function (res) {
      CK.btnLoading($btn, false);
      res.ok ? Toast.success('Pagamento atualizado!') : Toast.error(res.msg);
      if (res.ok) setTimeout(function () { location.reload(); }, 800);
    }).fail(function () { CK.btnLoading($btn, false); });
  });
 
  // ════════════════════════════════════════════════════════
  // ITENS — editar inline
  // ════════════════════════════════════════════════════════
  $(document).on('click', '.btn-save-item', function () {
    var $row   = $(this).closest('.ap-item');
    var itemId = $row.data('item-id');
    var qtd    = parseInt($row.find('.ap-item-qtd').val(), 10);
    var preco  = $row.find('.ap-item-preco').val().replace(',', '.');
    var $btn   = $(this);
    CK.btnLoading($btn);
 
    CK.post(endpoint('/item/' + itemId), { quantidade: qtd, preco_unitario: preco })
      .done(function (res) {
        CK.btnLoading($btn, false);
        if (res.ok) {
          Toast.success('Item atualizado!');
          if (res.totais) atualizarTotais(res.totais);
        } else {
          Toast.error(res.msg || 'Erro ao atualizar.');
        }
      }).fail(function () { CK.btnLoading($btn, false); });
  });
 
  // ── Remover item
  $(document).on('click', '.btn-del-item', function () {
    if (!confirm('Remover este item do pedido?')) return;
    var $row   = $(this).closest('.ap-item');
    var itemId = $row.data('item-id');
 
    CK.post(endpoint('/item/' + itemId + '/del'), {})
      .done(function (res) {
        if (res.ok) {
          $row.fadeOut(200, function () { $(this).remove(); });
          Toast.success('Item removido.');
          if (res.totais) atualizarTotais(res.totais);
        } else {
          Toast.error(res.msg || 'Erro ao remover.');
        }
      });
  });
 
  function atualizarTotais(t) {
    // Recarrega só o bloco de totais
    $('#ap-totals').load(location.href + ' #ap-totals > *');
  }
 
  // ════════════════════════════════════════════════════════
  // ADICIONAR ITEM
  // ════════════════════════════════════════════════════════
  $('#btn-add-item').on('click', function () { abrirModal('modal-add-item'); });
 
  var searchTimer;
  $('#busca-produto').on('input', function () {
    clearTimeout(searchTimer);
    var q = $(this).val().trim();
    if (q.length < 2) { $('#resultados-produto').empty(); return; }
    searchTimer = setTimeout(function () {
      $('#resultados-produto').html('<span style="color:var(--c-text-muted);font-size:13px;">Buscando…</span>');
      CK.get(BASE + '/pedidos/buscar-produto', { q: q })
        .done(function (res) {
          var html = '';
          if (!res.produtos || !res.produtos.length) {
            html = '<span style="color:var(--c-text-muted);font-size:13px;">Nenhum produto encontrado.</span>';
          } else {
            res.produtos.forEach(function (p) {
              html += '<div class="ap-search-item" data-id="' + p.id + '" data-nome="' + escHtml(p.nome) + '"'
                    + ' data-preco="' + p.preco + '" data-tem-var="' + (p.tem_variacao ? 1 : 0) + '"'
                    + ' data-skus=\'' + JSON.stringify(p.skus || []).replace(/'/g, '&#39;') + '\'>'
                    + '<strong>' + escHtml(p.nome) + '</strong>'
                    + '<span style="float:right;font-size:12.5px;color:var(--c-text-muted);">Estoque: ' + p.estoque_total + '</span>'
                    + '</div>';
            });
          }
          $('#resultados-produto').html(html);
        });
    }, 350);
  });
 
  $(document).on('click', '.ap-search-item', function () {
    var $el    = $(this);
    var skus   = JSON.parse($el.attr('data-skus') || '[]');
    var temVar = $el.data('tem-var') == 1;
 
    $('#add-produto-id').val($el.data('id'));
    $('#add-produto-nome').val($el.data('nome'));
    $('#add-preco').val(parseFloat($el.data('preco')).toFixed(2).replace('.', ','));
    $('#add-sku-id').val('');
    $('#form-add-item').show();
 
    // SKUs
    if (temVar && skus.length) {
      var html = '<label class="form-label-xs">Variação</label><select id="add-sku-sel" class="form-control">';
      skus.forEach(function (s) {
        html += '<option value="' + s.id + '" data-preco="' + s.preco + '">'
              + escHtml(s.label || 'SKU ' + s.id) + ' — Estoque: ' + s.estoque + '</option>';
      });
      html += '</select>';
      $('#add-skus-wrap').html(html);
      $('#add-sku-id').val(skus[0].id);
      $('#add-preco').val(parseFloat(skus[0].preco || $el.data('preco')).toFixed(2).replace('.', ','));
    } else {
      $('#add-skus-wrap').empty();
    }
  });
 
  $(document).on('change', '#add-sku-sel', function () {
    var preco = $(this).find(':selected').data('preco');
    if (preco) $('#add-preco').val(parseFloat(preco).toFixed(2).replace('.', ','));
    $('#add-sku-id').val($(this).val());
  });
 
  $('#btn-confirmar-add').on('click', function () {
    var $btn = $(this);
    CK.btnLoading($btn);
    CK.post(endpoint('/item/add'), {
      produto_id:    $('#add-produto-id').val(),
      sku_id:        $('#add-sku-id').val(),
      quantidade:    $('#add-qtd').val(),
      preco_unitario:$('#add-preco').val().replace(',', '.'),
    }).done(function (res) {
      CK.btnLoading($btn, false);
      if (res.ok) {
        Toast.success('Item adicionado!');
        fecharModal('modal-add-item');
        setTimeout(function () { location.reload(); }, 500);
      } else {
        Toast.error(res.msg || 'Erro ao adicionar.');
      }
    }).fail(function () { CK.btnLoading($btn, false); });
  });
 
  // ════════════════════════════════════════════════════════
  // OBSERVAÇÃO INTERNA
  // ════════════════════════════════════════════════════════
  $('#btn-add-obs').on('click', function () {
    var texto = $('#nova-obs').val().trim();
    if (!texto) return;
    var $btn = $(this);
    CK.btnLoading($btn);
    CK.post(endpoint('/observacao'), { observacao: texto })
      .done(function (res) {
        CK.btnLoading($btn, false);
        if (res.ok) {
          Toast.success('Nota adicionada!');
          $('#nova-obs').val('');
          setTimeout(function () { location.reload(); }, 600);
        }
      }).fail(function () { CK.btnLoading($btn, false); });
  });
 
  // ════════════════════════════════════════════════════════
  // NF-e
  // ════════════════════════════════════════════════════════
  $('#btn-salvar-nfe').on('click', function () {
    var $btn = $(this);
    var $msg = $('#nfe-msg');
    CK.formAlertClear($msg);
    CK.btnLoading($btn);
 
    CK.post(endpoint('/nfe'), {
      numero:     $('#nf-numero').val(),
      serie:      $('#nf-serie').val(),
      chaveAcesso:$('#nf-chave').val().replace(/\D/g,''),
      valorNota:  $('#nf-valor').val().replace(',','.'),
      dataEmissao:$('#nf-emissao').val(),
      cnpj:       $('#nf-cnpj').val().replace(/\D/g,''),
      linkPDF:    $('#nf-pdf').val(),
    }).done(function (res) {
      CK.btnLoading($btn, false);
      if (res.ok) {
        Toast.success('NF-e salva com sucesso!');
        $btn.text('Atualizar NF-e');
      } else {
        CK.formAlertSet($msg, res.msg || 'Erro ao salvar NF-e.');
      }
    }).fail(function () {
      CK.btnLoading($btn, false);
      CK.formAlertSet($msg, 'Erro de conexão.');
    });
  });
 
  // ════════════════════════════════════════════════════════
  // MODAIS
  // ════════════════════════════════════════════════════════
  $(document).on('click', '.od-modal-overlay', function (e) {
    if (e.target === this) fecharModal(this.id);
  });
  $(document).on('keydown', function (e) {
    if (e.key !== 'Escape') return;
    $('.od-modal-overlay.is-open').each(function () { fecharModal(this.id); });
  });
 
  // ── Helpers ──────────────────────────────────────────────
  function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // Aviso de flags ao trocar status
  (function () {
    var sel = document.getElementById('sel-novo-status');
    if (!sel) return;
    
    function verificarFlags() {
      var opt    = sel.options[sel.selectedIndex];
      var avisos = [];

      // Flags de entrada no novo status
      if (opt.dataset.estorna == '1') avisos.push('estornará o estoque dos itens');
      if (opt.dataset.cancela == '1') avisos.push('cancelará o cupom vinculado');

      // Reativação: estava cancelado e o novo status reserva estoque
      if (statusAtualEhCancelado && opt.dataset.reserva == '1') {
        avisos.push('re-deduzirá o estoque dos itens (reativação do pedido)');
      }

      var div = document.getElementById('status-flags-aviso');
      if (avisos.length) {
        div.innerHTML = '<strong>⚠ Atenção:</strong> Este status ' + avisos.join(' e ') + '.';
        div.className = 'form-alert form-alert--warning';
        div.style.display = 'block';
      } else {
        div.style.display = 'none';
      }
      // Pré-marca o checkbox de notificação conforme o flag do status
      document.getElementById('chk-notificar-status').checked = opt.dataset.notifica == '1';
    }

    sel.addEventListener('change', verificarFlags);
    verificarFlags(); // roda ao carregar
  })();

  var sel = document.getElementById('sel-novo-status');
  if (!sel) return;  

  function verificarFlags() {
    var opt    = sel.options[sel.selectedIndex];
    var avisos = [];

    // Flags de entrada no novo status
    if (opt.dataset.estorna == '1') avisos.push('estornará o estoque dos itens');
    if (opt.dataset.cancela == '1') avisos.push('cancelará o cupom vinculado');

    // Reativação: estava cancelado e o novo status reserva estoque
    if (statusAtualEhCancelado && opt.dataset.reserva == '1') {
      avisos.push('re-deduzirá o estoque dos itens (reativação do pedido)');
    }

    var div = document.getElementById('status-flags-aviso');
    if (avisos.length) {
      div.innerHTML = '<strong>⚠ Atenção:</strong> Este status ' + avisos.join(' e ') + '.';
      div.className = 'form-alert form-alert--warning';
      div.style.display = 'block';
    } else {
      div.style.display = 'none';
    }
    // Pré-marca o checkbox de notificação conforme o flag do status
    document.getElementById('chk-notificar-status').checked = opt.dataset.notifica == '1';
  }

  sel.addEventListener('change', verificarFlags);
  verificarFlags(); // roda ao carregar

}(jQuery));

// Global modal helpers (usados no PHP inline)
function abrirModal(id) {
  var m = document.getElementById(id);
  if (!m) return;
 
  // 1. Remove hidden → browser aplica display:flex + opacity:0
  m.removeAttribute('hidden');
 
  // 2. Double RAF: garante que o browser PINTOU o estado opacity:0
  //    antes de adicionar .is-open (sem isso o transition pode não disparar)
  requestAnimationFrame(function () {
    requestAnimationFrame(function () {
      m.classList.add('is-open');
    });
  });
 
  document.body.style.overflow = 'hidden';
}
function fecharModal(id) {
  var m = document.getElementById(id);
  if (!m) return;
  m.classList.remove('is-open');
  // Aguarda a transição terminar antes de esconder com [hidden]
  setTimeout(function () {
    m.setAttribute('hidden', '');
  }, 240);
  document.body.style.overflow = '';
}

// ════════════════════════════════════════════════════════
// NOVO PEDIDO — /admin/pedidos/novo
// ════════════════════════════════════════════════════════
;(function ($) {
  'use strict';
 
  if (!document.getElementById('form-novo-pedido')) return;
 
  var BASE    = (BASE_URL + '/admin');
  var itens   = [];   // [ {produto_id, sku_id, nome, preco, qtd, estoque} ]
  var timer;
 
  // ── Helpers ──────────────────────────────────────────
  function fmtBRL(v) {
    return 'R$ ' + parseFloat(v || 0).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }
  function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function recalcularTotais() {
    var sub      = itens.reduce(function (a, i) { return a + (i.preco * i.qtd); }, 0);
    var frete    = parseFloat(($('#np-frete').val() || '0').replace(',', '.')) || 0;
    var desconto = parseFloat(($('#np-desconto').val() || '0').replace(',', '.')) || 0;
    var total    = Math.max(0, sub - desconto + frete);
 
    $('#np-subtotal').text(fmtBRL(sub));
    $('#np-frete-val').text(fmtBRL(frete));
    $('#np-desconto-val').text('− ' + fmtBRL(desconto));
    $('#np-total').text(fmtBRL(total));
    $('#np-desconto-row').toggle(desconto > 0);
    $('#np-totais-block').toggle(itens.length > 0);
    $('#itens-json').val(JSON.stringify(itens.map(function (i) {
      return { produto_id: i.produto_id, sku_id: i.sku_id || null,
               qtd: i.qtd, preco: i.preco };
    })));
  }
 
  function renderItens() {
    var $list = $('#np-itens-list');
    if (itens.length === 0) {
      $list.html('<div id="np-itens-empty" style="padding:24px 20px;text-align:center;color:var(--c-text-muted);font-size:13.5px;">Nenhum item adicionado ainda.</div>');
    } else {
      var html = '';
      itens.forEach(function (item, idx) {
        html += '<div class="ap-item" data-idx="' + idx + '">'
              + '<img src="' + escHtml(item.imagem || (window.BASE_URL + '/assets/img/placeholder.png')) + '" class="ap-item-img">'
              + '<div class="ap-item-info"><div class="ap-item-name">' + escHtml(item.nome) + '</div>'
              + (item.variacao ? '<div class="ap-item-var">' + escHtml(item.variacao) + '</div>' : '')
              + '</div>'
              + '<div class="ap-item-nums"><div class="ap-item-edit-row">'
              + '<label>Qtd</label><input type="number" class="form-control form-control--xs np-item-qtd" value="' + item.qtd + '" min="1" max="' + item.estoque + '" style="width:60px;">'
              + '<label>R$</label><input type="text" class="form-control form-control--xs np-item-preco" value="' + parseFloat(item.preco).toFixed(2).replace('.',',') + '" style="width:85px;">'
              + '<button type="button" class="btn-icon btn-icon--danger np-rm-item" title="Remover"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button>'
              + '</div></div></div>';
      });
      $list.html(html);
    }
    recalcularTotais();
  }
 
  // ── Busca de cliente ─────────────────────────────────
  $('#busca-cliente').on('input', function () {
    clearTimeout(timer);
    var q = $(this).val().trim();
    if (q.length < 2) { $('#dropdown-clientes').hide(); return; }
    timer = setTimeout(function () {
      $.get(BASE + '/pedidos/buscar-cliente', { q: q }, function (res) {
        if (!res.clientes || !res.clientes.length) {
          $('#dropdown-clientes').html('<div class="np-dropdown-item np-no-result">Nenhum cliente encontrado.</div>').show();
          return;
        }
        var html = '';
        res.clientes.forEach(function (c) {
          html += '<div class="np-dropdown-item" data-id="' + c.cliente_id + '" data-uid="' + c.usuario_id + '"'
                + ' data-nome="' + escHtml(c.nome) + '" data-email="' + escHtml(c.email) + '"'
                + ' data-cpf="' + escHtml(c.cpf || '') + '" data-tel="' + escHtml(c.telefone || '') + '">'
                + '<strong>' + escHtml(c.nome) + '</strong>'
                + '<span style="float:right;font-size:12px;color:var(--c-text-muted);">' + escHtml(c.email) + '</span>'
                + (c.cpf ? '<div style="font-size:12px;color:var(--c-text-muted);">CPF ' + escHtml(c.cpf) + '</div>' : '')
                + '</div>';
        });
        $('#dropdown-clientes').html(html).show();
      });
    }, 350);
  });
 
  $(document).on('click', '#dropdown-clientes .np-dropdown-item', function () {
    if ($(this).hasClass('np-no-result')) return;
    var $el = $(this);
    $('#cliente-id').val($el.data('id'));
    $('#busca-cliente').val($el.data('nome')).prop('disabled', true);
    $('#dropdown-clientes').hide();
 
    var html = '<div><strong>' + escHtml($el.data('nome')) + '</strong>'
             + '<div style="font-size:12.5px;color:var(--c-text-muted);">' + escHtml($el.data('email')) + '</div>'
             + ($el.data('cpf') ? '<div style="font-size:12px;color:var(--c-text-muted);">CPF ' + escHtml($el.data('cpf')) + '</div>' : '')
             + ($el.data('tel') ? '<div style="font-size:12px;color:var(--c-text-muted);">Tel ' + escHtml($el.data('tel')) + '</div>' : '')
             + '</div>';
    $('#cliente-info').html(html);
    $('#cliente-selecionado').show();
 
    // Carrega endereços do cliente
    var clienteId = $el.data('id');
    $.get(BASE + '/pedidos/enderecos/' + clienteId, function (res) {
      var endHtml = '';
      (res.enderecos || []).forEach(function (e, idx) {
        endHtml += '<label class="np-endereco-opt">'
                 + '<input type="radio" name="np_end" value="' + e.id + '" ' + (idx===0||e.padrao?'checked':'')+' class="np-end-radio">'
                 + '<div class="np-end-info">'
                 + '<strong>' + escHtml(e.nome_destinatario || '') + '</strong>'
                 + '<span>' + escHtml(e.logradouro + ', ' + e.numero) + (e.complemento?' — '+e.complemento:'') + '</span>'
                 + '<span>' + escHtml(e.bairro+' — '+e.cidade+'/'+e.estado) + ' · CEP '+escHtml(e.cep) + '</span>'
                 + '</div></label>';
      });
      if (!endHtml) endHtml = '<small style="color:var(--c-text-muted);">Nenhum endereço cadastrado.</small>';
      $('#lista-enderecos').html(endHtml);
      $('#bloco-enderecos').show();
      // Marca o default
      if ($('input[name="np_end"]:checked').length) {
        $('#endereco-id').val($('input[name="np_end"]:checked').val());
      }
    });
  });
 
  $(document).on('change', '.np-end-radio', function () {
    $('#endereco-id').val($(this).val());
  });
 
  $('#btn-limpar-cliente').on('click', function () {
    $('#cliente-id').val('');
    $('#busca-cliente').val('').prop('disabled', false).focus();
    $('#cliente-selecionado').hide();
    $('#bloco-enderecos').hide();
  });
 
  // Fecha dropdown ao clicar fora
  $(document).on('click', function (e) {
    if (!$(e.target).closest('#busca-cliente, #dropdown-clientes, #np-busca-produto, #np-dropdown-produto').length) {
      $('#dropdown-clientes, #np-dropdown-produto').hide();
    }
  });
 
  // ── Busca de produto ─────────────────────────────────
  var pTimer;
  $('#np-busca-produto').on('input', function () {
    clearTimeout(pTimer);
    var q = $(this).val().trim();
    if (q.length < 2) { $('#np-dropdown-produto').hide(); return; }
    pTimer = setTimeout(function () {
      $.get(BASE + '/pedidos/buscar-produto', { q: q }, function (res) {
        if (!res.produtos || !res.produtos.length) {
          $('#np-dropdown-produto').html('<div class="np-dropdown-item np-no-result">Nenhum produto encontrado.</div>').show();
          return;
        }
        var html = '';
        res.produtos.forEach(function (p) {
          html += '<div class="np-dropdown-item np-produto-item"'
                + ' data-id="' + p.id + '" data-nome="' + escHtml(p.nome) + '"'
                + ' data-preco="' + p.preco + '" data-estoque="' + p.estoque_total + '"'
                + ' data-imagem="' + escHtml(p.imagem || '') + '"'
                + ' data-tem-var="' + (p.tem_variacao ? 1 : 0) + '"'
                + ' data-skus=\'' + JSON.stringify(p.skus || []).replace(/'/g,'&#39;') + '\'>'
                + '<div style="display:flex;justify-content:space-between;align-items:center;">'
                + '<strong>' + escHtml(p.nome) + '</strong>'
                + '<span style="font-size:12px;color:var(--c-text-muted);">Estoque: ' + p.estoque_total + '</span>'
                + '</div>'
                + '<div style="font-size:12.5px;color:var(--c-text-muted);">' + fmtBRL(p.preco) + '</div>'
                + (p.tem_variacao && p.skus && p.skus.length
                  ? '<div style="font-size:11.5px;color:var(--c-primary);">' + p.skus.length + ' variações disponíveis</div>'
                  : '')
                + '</div>';
        });
        $('#np-dropdown-produto').html(html).show();
      });
    }, 350);
  });
 
  $(document).on('click', '.np-produto-item', function () {
    var $el    = $(this);
    var skus   = JSON.parse($el.attr('data-skus') || '[]');
    var temVar = $el.data('tem-var') == 1;
 
    $('#np-dropdown-produto').hide();
    $('#np-busca-produto').val('').focus();
 
    if (!temVar || !skus.length) {
      // Produto sem variação — adiciona direto
      adicionarItem({
        produto_id: $el.data('id'),
        sku_id: null,
        nome: $el.data('nome'),
        preco: parseFloat($el.data('preco')),
        qtd: 1,
        estoque: parseInt($el.data('estoque'), 10),
        imagem: $el.data('imagem'),
        variacao: null,
      });
    } else {
      // Tem variações — mostra picker
      abrirPickerSku($el, skus);
    }
  });
 
  function abrirPickerSku($el, skus) {
    var html = '<div style="padding:16px;">'
             + '<h4 style="margin:0 0 12px;font-size:14px;font-weight:800;">' + escHtml($el.data('nome')) + '</h4>'
             + '<div style="display:grid;gap:8px;">';
    skus.forEach(function (s) {
      html += '<div class="np-sku-opt" data-sku-id="' + s.id + '" data-nome="' + escHtml($el.data('nome')) + '"'
            + ' data-preco="' + s.preco + '" data-label="' + escHtml(s.label||'') + '"'
            + ' data-estoque="' + s.estoque + '" data-produto-id="' + $el.data('id') + '"'
            + ' data-imagem="' + escHtml($el.data('imagem')||'') + '">'
            + '<div style="display:flex;justify-content:space-between;align-items:center;width:100%;">'
            + '<span style="font-weight:700;">' + escHtml(s.label||'Variação') + '</span>'
            + '<span style="font-size:12.5px;color:var(--c-text-muted);">Estoque: ' + s.estoque + ' · ' + fmtBRL(s.preco) + '</span>'
            + '</div></div>';
    });
    html += '</div></div>';
 
    // Modal temporário
    var $modal = $('<div class="od-modal-overlay" style="z-index:1100;" id="modal-sku-picker">'
                 + '<div class="od-modal-box" style="max-width:420px;">'
                 + '<div class="od-modal-header"><h4>Selecionar variação</h4>'
                 + '<button type="button" class="od-modal-close" onclick="fecharModal(\'modal-sku-picker\')">'
                 + '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>'
                 + '<div class="od-modal-body">' + html + '</div></div></div>');
    $('body').append($modal);
    abrirModal('modal-sku-picker');
  }
 
  $(document).on('click', '.np-sku-opt', function () {
    var $el = $(this);
    adicionarItem({
      produto_id: $el.data('produto-id'),
      sku_id:     $el.data('sku-id'),
      nome:       $el.data('nome'),
      preco:      parseFloat($el.data('preco')),
      qtd:        1,
      estoque:    parseInt($el.data('estoque'), 10),
      imagem:     $el.data('imagem') || '',
      variacao:   $el.data('label') || null,
    });
    fecharModal('modal-sku-picker');
    setTimeout(function () { $('#modal-sku-picker').remove(); }, 300);
  });
 
  function adicionarItem(item) {
    // Se produto+sku já existe, incrementa qtd
    var existente = itens.find(function (i) {
      return i.produto_id == item.produto_id && (i.sku_id == item.sku_id);
    });
    if (existente) {
      existente.qtd = Math.min(existente.qtd + 1, existente.estoque);
    } else {
      itens.push(item);
    }
    renderItens();
    Toast.success('Item adicionado!');
  }
 
  // ── Editar qtd/preco inline ──────────────────────────
  $(document).on('input', '.np-item-qtd, .np-item-preco', function () {
    var idx    = $(this).closest('.ap-item').data('idx');
    var qtd    = parseInt($('[data-idx="' + idx + '"] .np-item-qtd').val(), 10) || 1;
    var preco  = parseFloat(($('[data-idx="' + idx + '"] .np-item-preco').val()||'0').replace(',','.')) || 0;
    itens[idx].qtd   = Math.max(1, Math.min(qtd, itens[idx].estoque));
    itens[idx].preco = preco;
    recalcularTotais();
  });
 
  $(document).on('click', '.np-rm-item', function () {
    var idx = $(this).closest('.ap-item').data('idx');
    itens.splice(idx, 1);
    renderItens();
  });
 
  // ── Frete/desconto atualizam totais ──────────────────
  $('#np-frete, #np-desconto').on('input', recalcularTotais);
 
  // ── Campos de cartão ─────────────────────────────────
  $('#np-forma-pag').on('change', function () {
    $('#np-campos-cartao').toggle($(this).val() === 'cartao');
  });
 
  // ── Submit ───────────────────────────────────────────
  $('#form-novo-pedido').on('submit', function (e) {
    e.preventDefault();
    var $btn = $('#btn-criar-pedido');
    var $err = $('#np-error');
    CK.formAlertClear($err);
 
    if (!$('#cliente-id').val()) {
      CK.formAlertSet($err, 'Selecione um cliente.');
      return;
    }
    if (itens.length === 0) {
      CK.formAlertSet($err, 'Adicione pelo menos 1 produto.');
      return;
    }
 
    CK.btnLoading($btn);
 
    // Monta payload completo
    var data = {};
    $(this).serializeArray().forEach(function (f) { data[f.name] = f.value; });
    data.cliente_id  = $('#cliente-id').val();
    data.endereco_id = $('#endereco-id').val();
    data.itens       = $('#itens-json').val();
 
    CK.post(BASE + '/pedidos/novo', data)
      .done(function (res) {
        if (res.ok) {
          Toast.success('Pedido criado com sucesso!');
          setTimeout(function () { window.location.href = res.redirect; }, 500);
        } else {
          CK.btnLoading($btn, false);
          CK.formAlertSet($err, res.msg || 'Erro ao criar pedido.');
        }
      }).fail(function () {
        CK.btnLoading($btn, false);
        CK.formAlertSet($err, 'Erro de conexão.');
      });
  });
  
 
}(jQuery));


// Pagina de configurações

(function () {
  var BASE    = (BASE_URL + '/admin');
  // ── Scroll spy: marca o grupo ativo no nav ──────────────
  var sections = document.querySelectorAll('.cfg-grupo');
  var navItems = document.querySelectorAll('.cfg-nav-item[data-grupo]');
  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) {
        var id = entry.target.id.replace('cfg-grupo-', '');
        navItems.forEach(function (el) {
          el.classList.toggle('is-active', el.dataset.grupo === id);
        });
      }
    });
  }, { threshold: 0.3, rootMargin: '-60px 0px -60% 0px' });
  sections.forEach(function (s) { observer.observe(s); });

  // ── Scroll suave ao clicar no nav ───────────────────────
  navItems.forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      var target = document.getElementById('cfg-grupo-' + this.dataset.grupo);
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  // ── Abrir drawer de edição ──────────────────────────────
  document.querySelectorAll('.cfg-btn-editar').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var chave  = this.dataset.chave;
      var tipo   = this.dataset.tipo;
      var valor  = this.dataset.valor;
      var label  = this.dataset.label;

      var inputHtml = buildInput(tipo, chave, valor);

      var drawer = window.adminDrawer({
        titulo:  'Editar: ' + label,
        tamanho: tipo === 'text' || tipo === 'json' ? 'md' : 'sm',
        conteudo: `
          <div style="display:flex;flex-direction:column;gap:16px;">
            <div>
              <label class="form-label-xs" style="margin-bottom:6px;">${label}</label>
              <code class="cfg-row-chave" style="display:inline-block;margin-bottom:10px;">${chave}</code>
              ${inputHtml}
            </div>
            <div id="cfg-drawer-msg" class="form-alert" style="display:none;"></div>
            <div style="display:flex;gap:8px;">
              <button type="button" class="btn btn-primary" id="cfg-btn-save">Salvar</button>
              <button type="button" class="btn btn-outline" onclick="this.closest('.admin-drawer') && document.dispatchEvent(new CustomEvent('drawerClose'))">Cancelar</button>
            </div>
          </div>
        `,
      });

      // Handler de salvar
      setTimeout(function () {
        var btnSave = document.getElementById('cfg-btn-save');
        if (!btnSave) return;
        btnSave.addEventListener('click', function () {
          var val = getValorInput(tipo, chave);
          CK.btnLoading($(btnSave));

          $.post(BASE + '/configuracoes/salvar', {
            chave: chave,
            valor: val,
            _token: document.querySelector('meta[name="csrf-token"]')?.content || '',
          }).done(function (res) {
            CK.btnLoading($(btnSave), false);
            if (res.ok) {
              Toast.success('Configuração salva!');
              // Atualiza o valor na linha sem reload
              atualizarLinha(chave, tipo, val, res.valor_exibir);
              drawer.close();
            } else {
              $('#cfg-drawer-msg').text(res.msg).show();
            }
          }).fail(function () {
            CK.btnLoading($(btnSave), false);
            $('#cfg-drawer-msg').text('Erro de conexão.').show();
          });
        });
      }, 100);
    });
  });

  // ── Helpers ─────────────────────────────────────────────
  function buildInput(tipo, chave, valor) {
    if (tipo === 'bool') {
      var checked = valor === '1' ? 'checked' : '';
      return `
        <label class="toggle-field" style="font-size:14px;">
          <input type="checkbox" id="cfg-input-${chave}" ${checked}>
          <span class="toggle-slider"></span>
          <span id="cfg-bool-label-${chave}">${valor === '1' ? 'Ativo' : 'Inativo'}</span>
        </label>
      `;
    }
    if (tipo === 'text') {
      return `<textarea id="cfg-input-${chave}" class="form-control" rows="5"
               style="resize:vertical;font-family:inherit;">${escHtml(valor)}</textarea>`;
    }
    if (tipo === 'json') {
      var pretty = '';
      try { pretty = JSON.stringify(JSON.parse(valor), null, 2); } catch(e) { pretty = valor; }
      return `<textarea id="cfg-input-${chave}" class="form-control" rows="8"
               style="resize:vertical;font-family:'SF Mono',monospace;font-size:12px;">${escHtml(pretty)}</textarea>`;
    }
    // string | int
    return `<input type="${tipo === 'int' ? 'number' : 'text'}"
             id="cfg-input-${chave}" class="form-control"
             value="${escHtml(valor)}" ${tipo === 'int' ? 'step="1"' : ''}>`;
  }

  function getValorInput(tipo, chave) {
    var el = document.getElementById('cfg-input-' + chave);
    if (!el) return '';
    if (tipo === 'bool') return el.checked ? '1' : '0';
    if (tipo === 'json') {
      try { return JSON.stringify(JSON.parse(el.value)); } catch(e) { return el.value; }
    }
    return el.value;
  }

  function atualizarLinha(chave, tipo, val, exibir) {
    var $cell = $('#cfg-val-' + chave);
    if (tipo === 'bool') {
      var isOn = val === '1';
      $cell.html(
        '<span class="cfg-bool cfg-bool--' + (isOn ? 'on' : 'off') + '">' +
        (isOn ? '● Ativo' : '○ Inativo') + '</span>'
      );
    } else {
      $cell.find('.cfg-val-text').html(exibir || val);
    }
    // Pisca para feedback visual
    $cell.addClass('ck-val-updated');
    setTimeout(function () { $cell.removeClass('ck-val-updated'); }, 1500);
    // Atualiza o data-valor do botão de edição
    $('[data-chave="' + chave + '"]').data('valor', val).attr('data-valor', val);
  }

  function escHtml(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // Bool: atualiza label ao toggle
  document.addEventListener('change', function (e) {
    if (e.target && e.target.id && e.target.id.startsWith('cfg-input-') && e.target.type === 'checkbox') {
      var chave = e.target.id.replace('cfg-input-', '');
      var label = document.getElementById('cfg-bool-label-' + chave);
      if (label) label.textContent = e.target.checked ? 'Ativo' : 'Inativo';
    }
  });

  var IMPORT_URL = BASE + '/importar';
  
    // ── Abas ──────────────────────────────────────────────
  $('.admin-tab').on('click', function(){
    var tab=$(this).data('tab');
    $('.admin-tab').removeClass('is-active');
    $('.admin-tab-content').removeClass('is-active');
    $(this).addClass('is-active');
    $('#tab-'+tab).addClass('is-active');
  });

  // ── Upload genérico ───────────────────────────────────
  function setupUpload(tipo) {
    var $input  = $('#file-'+tipo);
    var $area   = $('#upload-area-'+tipo);
    var $nome   = $('#file-'+tipo+'-nome');
    var $preview= $('#preview-'+tipo);

    $input.on('change', function(){
      if (!this.files[0]) return;
      $nome.text(this.files[0].name).show();
      uploadCsv(tipo, this.files[0], $preview);
    });

    // Drag & drop
    $area.on('dragover', function(e){ e.preventDefault(); $(this).addClass('drag-over'); });
    $area.on('dragleave drop', function(e){
      e.preventDefault(); $(this).removeClass('drag-over');
      if (e.type==='drop' && e.originalEvent.dataTransfer.files[0]) {
        var f=e.originalEvent.dataTransfer.files[0];
        $nome.text(f.name).show();
        uploadCsv(tipo, f, $preview);
      }
    });
  }

  function uploadCsv(tipo, file, $preview) {
    var fd = new FormData();
    fd.append('csv', file);
    fd.append('tipo', tipo);
    fd.append('_token', $('meta[name="csrf-token"]').attr('content') || '');

    $.ajax({ url: IMPORT_URL+'/upload', method:'POST', data:fd,
            contentType:false, processData:false })
    .done(function(res){
      if (!res.ok) { Toast.error(res.msg); return; }
      // Busca preview
      $.get(IMPORT_URL+'/preview', { job_id: res.job_id, tipo: tipo })
      .done(function(pres){
        renderPreview(tipo, pres, res.job_id);
        $preview.show();
      });
    })
    .fail(function(){ Toast.error('Erro ao enviar o arquivo.'); });
  }

  function renderPreview(tipo, res, jobId) {
    if (tipo === 'clientes') {
      renderPreviewClientes(res, jobId);
      $('#preview-clientes').show();
      return;
    }
    if (tipo === 'produtos') {
      $('#preview-prod-total').text(res.total + ' produtos');
      var tbody = $('#preview-prod-table tbody').empty();
      res.preview.forEach(function(p){
        tbody.append('<tr><td><code style="font-size:11px;">'+p.tray_id+'</code></td><td>'+p.nome+'</td><td>'+p.marca+'</td><td>'+p.categoria+'</td><td>R$ '+p.preco.toFixed(2).replace('.',',')+'</td><td>'+p.estoque+'</td></tr>');
      });
      $('#btn-importar-produtos').off('click').on('click', function(){ iniciarImport('produtos', jobId); });
    } else {
      $('#preview-var-total').text(res.total + ' variações');
      var tbody = $('#preview-var-table tbody').empty();
      res.preview.forEach(function(v){
        tbody.append('<tr><td><code>'+v.codigo_prod+'</code></td><td>'+v.variacao+'</td><td>'+v.valor+'</td><td><code>'+v.sku+'</code></td><td>R$ '+v.preco.toFixed(2).replace('.',',')+'</td><td>'+v.estoque+'</td></tr>');
      });
      $('#btn-importar-variacoes').off('click').on('click', function(){ iniciarImport('variacoes', jobId); });
    }
  }

  // ── Import em chunks ──────────────────────────────────
  function iniciarImport(tipo, jobId) {
    $('#preview-'+tipo).hide();
    $('#progresso-'+tipo).show();
    processarProximoChunk(tipo, jobId, 0, 0);
  }

  function processarProximoChunk(tipo, jobId, totalCriados, totalAtualizados) {
    $.post(IMPORT_URL+'/chunk', { job_id: jobId, tipo: tipo })
    .done(function(res){
      if (!res.ok) { Toast.error(res.msg); return; }

      var processadas = res.processadas;
      var total       = res.total;
      var pct         = total > 0 ? Math.round(processadas/total*100) : 0;
      var criados     = (totalCriados     + (res.criados     || 0));
      var atualizados = (totalAtualizados + (res.atualizados || 0));

      $('#prog-'+tipo+'-pct').text(pct+'%');
      $('#prog-'+tipo+'-bar').css('width', pct+'%');
      $('#prog-'+tipo+'-processadas').text(processadas);
      $('#prog-'+tipo+'-criados').text(criados);
      $('#prog-'+tipo+'-atualizados').text(atualizados);
      $('#prog-'+tipo+'-ignorados').text(res.ignorados || 0);
      $('#prog-'+tipo+'-msg').text('Processando linha ' + processadas + ' de ' + total + '…');

      if (res.concluido) {
        $('#prog-'+tipo+'-msg').html('✓ Import concluído! <a href="'+ADMIN_URL+'/produtos" class="link-subtle">Ver produtos →</a>');
        Toast.success('Import de ' + tipo + ' concluído!');
        setTimeout(function(){ location.reload(); }, 2000);
      } else {
        setTimeout(function(){
          processarProximoChunk(tipo, jobId, criados, atualizados);
        }, 200);
      }
    })
    .fail(function(){ Toast.error('Erro ao processar chunk.'); });
  }

  setupUpload('produtos');
  setupUpload('variacoes');
  setupUpload('clientes');

  // ── Pedidos: dois inputs separados ────────────────────
  var _pedArq1 = null, _pedArq2 = null;

  function checkPedidosReady() {
    $('#btn-upload-pedidos').prop('disabled', !(_pedArq1 && _pedArq2));
  }

  $('#file-ped-pedidos').on('change', function() {
    _pedArq1 = this.files[0] || null;
    $('#ped-nome-pedidos').text(_pedArq1 ? _pedArq1.name : 'Nenhum arquivo');
    checkPedidosReady();
  });
  $('#file-ped-produtos').on('change', function() {
    _pedArq2 = this.files[0] || null;
    $('#ped-nome-produtos').text(_pedArq2 ? _pedArq2.name : 'Nenhum arquivo');
    checkPedidosReady();
  });
  ['ped-pedidos','ped-produtos'].forEach(function(id) {
    var zone = $('#upload-area-' + id);
    zone.on('click', function() { $('#file-' + id).trigger('click'); });
    zone.on('dragover', function(e) { e.preventDefault(); zone.addClass('drag-over'); });
    zone.on('dragleave drop', function(e) {
      e.preventDefault(); zone.removeClass('drag-over');
      if (e.type === 'drop') {
        var f = e.originalEvent.dataTransfer.files[0];
        if (id === 'ped-pedidos') { _pedArq1 = f; $('#ped-nome-pedidos').text(f.name); }
        else { _pedArq2 = f; $('#ped-nome-produtos').text(f.name); }
        checkPedidosReady();
      }
    });
  });

  $('#btn-upload-pedidos').on('click', function() {
    var $btn = $(this);
    var $st  = $('#ped-upload-status');
    var fd   = new FormData();
    fd.append('tipo', 'pedidos');
    fd.append('_token', CSRF_TOKEN);
    fd.append('csv_pedidos', _pedArq1, _pedArq1.name);
    fd.append('csv_produtos', _pedArq2, _pedArq2.name);

    CK.btnLoading($btn);
    $st.text('Enviando arquivos…');

    $.ajax({ url: IMPORT_URL + '/upload', method: 'POST', data: fd, processData: false, contentType: false })
    .done(function(r) {
      CK.btnLoading($btn, false);
      if (!r.ok) { $st.html('<span style="color:#dc2626;">' + r.msg + '</span>'); return; }
      $st.text('Carregando preview…');
      $.get(IMPORT_URL + '/preview', { job_id: r.job_id, tipo: 'pedidos' })
      .done(function(res) {
        $st.text('');
        renderPreviewPedidos(res, r.job_id);
      });
    })
    .fail(function() { CK.btnLoading($btn, false); $st.text('Erro de conexão.'); });
  });

  function renderPreviewPedidos(res, jobId) {
    $('#preview-ped-total').text(res.total + ' pedidos');
    var tbody = $('#preview-ped-table tbody').empty();
    (res.preview || []).forEach(function(p) {
      var total = 'R$ ' + parseFloat(p.total).toLocaleString('pt-BR', {minimumFractionDigits:2});
      tbody.append('<tr><td><code>'+p.tray_id+'</code></td><td>'+p.data+'</td><td>'+p.cliente+'</td><td><span class="badge">'+p.status+'</span></td><td>'+total+'</td><td>'+p.n_itens+'</td></tr>');
    });
    $('#btn-importar-pedidos').off('click').on('click', function() { iniciarImport('pedidos', jobId); });
    $('#preview-pedidos').show();
  }

  // Preview de clientes
  function renderPreviewClientes(res, jobId) {
    $('#preview-cli-total').text(res.total + ' clientes');
    var tbody = $('#preview-cli-table tbody').empty();
    res.preview.forEach(function(c){
      tbody.append('<tr><td><code style="font-size:11px;">'+c.tray_id+'</code></td><td>'+c.nome+'</td><td>'+c.email+'</td><td><code>'+c.cpf+'</code></td><td>'+c.cidade+'</td><td>'+c.estado+'</td><td><span class="badge badge-'+(c.bloqueado==='Sim'?'danger':'success')+'">'+c.bloqueado+'</span></td></tr>');
    });
    $('#btn-importar-clientes').off('click').on('click', function(){ iniciarImport('clientes', jobId); });
  }

  // ── Download de imagens ───────────────────────────────
  $('#btn-baixar-imagens, #btn-baixar-100').on('click', function(){
    var limite = $(this).is('#btn-baixar-100') ? 100 : 30;
    var $btn = $(this);
    CK.btnLoading($btn);
    $.post(IMPORT_URL+'/processar-imagens', { limite: limite })
    .done(function(res){
      CK.btnLoading($btn, false);
      $('#img-msg').text('✓ Baixadas: '+res.ok+' | Erros: '+res.erro);
      $('#img-pendentes').text(parseInt($('#img-pendentes').text()) - res.ok);
      $('#img-concluidos').text(parseInt($('#img-concluidos').text()) + res.ok);
      if (res.erros) $('#img-erros').text(parseInt($('#img-erros').text()) + res.erro);
    })
    .fail(function(){ CK.btnLoading($btn, false); Toast.error('Erro.'); });
  });

  // ── Erros do histórico ────────────────────────────────
  $(document).on('click', '.btn-show-erros', function(){
    var erros = JSON.parse(this.dataset.erros || '[]');
    var html  = '<div style="max-height:400px;overflow:auto;">';
    erros.forEach(function(e){ html += '<div style="padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px;"><strong>Linha '+e.linha+':</strong> '+e.msg+'</div>'; });
    html += '</div>';
    window.adminDrawer({ titulo: 'Erros do import', conteudo: html, tamanho: 'md' });
  });


  // Devoluções
  // var BASE = ADMIN_URL + '/devolucoes/motivos/salvar';

  $('#btn-novo-motivo').on('click', function () {
    resetarFormMotivo(null);
    document.getElementById('modal-motivo-titulo').textContent = 'Novo motivo';
    abrirModal('modal-motivo');
  });

  $(document).on('click', '.btn-editar-motivo', function () {
    var d = this.dataset;
    resetarFormMotivo({
      id: d.id, label: d.label, tipo: d.tipo,
      exigeFoto: d.exigeFoto == '1', frete: d.frete,
      prazo: d.prazo, ativo: d.ativo == '1', ord: d.ord,
    });
    document.getElementById('modal-motivo-titulo').textContent = 'Editar: ' + d.label;
    abrirModal('modal-motivo');
  });

  function resetarFormMotivo(d) {
    $('#mot-id').val(d ? d.id : '');
    $('#mot-label').val(d ? d.label : '');
    $('#mot-tipo').val(d ? d.tipo : 'ambos');
    $('#mot-frete').val(d ? d.frete : 'loja');
    $('#mot-prazo').val(d ? d.prazo : '');
    $('#mot-ord').val(d ? d.ord : '0');
    $('#mot-exige-foto').prop('checked', d ? d.exigeFoto : false);
    $('#mot-ativo').prop('checked', d ? d.ativo : true);
    $('#mot-msg').hide();
  }

  $('#btn-salvar-motivo').on('click', function () {
    var $btn = $(this); CK.btnLoading($btn);
    var $msg = $('#mot-msg'); CK.formAlertClear($msg);
    CK.post(BASE + '/devolucoes/motivos/salvar', {
      id:               $('#mot-id').val(),
      label:            $('#mot-label').val(),
      tipo:             $('#mot-tipo').val(),
      responsavel_frete:$('#mot-frete').val(),
      prazo_credito_dias:$('#mot-prazo').val(),
      exige_foto:       $('#mot-exige-foto').is(':checked') ? 1 : 0,
      ativo:            $('#mot-ativo').is(':checked') ? 1 : 0,
      ordenacao:        $('#mot-ord').val(),
    }).done(function (res) {
      CK.btnLoading($btn, false);
      if (res.ok) {
        Toast.success('Motivo salvo!');
        fecharModal('modal-motivo');
        setTimeout(() => location.reload(), 500);
      } else {
        CK.formAlertSet($msg, res.msg);
      }
    }).fail(function () { CK.btnLoading($btn, false); });
  });

  if(typeof SOL_DEV_ID !== 'undefined'){
    console.log(SOL_DEV_ID);
    
    var BASE_D = BASE + '/devolucoes/' + SOL_DEV_ID;
    (function () {
      function acao(endpoint, dados, recarregar) {
        CK.post(BASE_D + endpoint, dados).done(function (res) {
          if (res.ok) { Toast.success('Ação realizada!'); if (recarregar !== false) setTimeout(() => location.reload(), 600); }
          else CK.formAlertSet($('#acao-msg'), res.msg);
        });
      }
      // ── Gerar código de postagem PAC reverso ─────────────
      $('#btn-gerar-postagem').on('click', function () {
        var $btn = $(this);
        var $res = $('#postagem-result');
        var id   = window.location.pathname.match(/\/devolucoes\/(\d+)/)?.[1];
    
        $btn.prop('disabled', true).html(
          '<svg class="spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:5px;"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg> Gerando...'
        );
        $res.hide();
    
        $.post(BASE + '/devolucoes/' + id + '/gerar-postagem', {
          _token: CSRF_TOKEN
        })
        .done(function (r) {
          $btn.prop('disabled', false).html(
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:5px;"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg> Gerar código PAC reverso agora'
          );
          if (r.ok) {
            $res.html(
              '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 14px;font-size:13px;color:#166534;">' +
                '<strong>✓ Código gerado com sucesso!</strong><br>' +
                '<code style="font-size:15px;font-weight:900;letter-spacing:.5px;">' + r.cod + '</code>' +
              '</div>'
            ).show();
            // Recarrega a página após 2s para refletir o novo status
            setTimeout(function () { location.reload(); }, 2000);
          } else {
            $res.html(
              '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#dc2626;">' +
                '<strong>Erro:</strong> ' + (r.msg || 'Falha ao gerar o código.') +
              '</div>'
            ).show();
          }
        })
        .fail(function () {
          $btn.prop('disabled', false).text('Gerar código PAC reverso agora');
          $res.html(
            '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#dc2626;">' +
              'Erro de conexão. Tente novamente.' +
            '</div>'
          ).show();
        });
      });
    
      $('#btn-aprovar')   && $('#btn-aprovar').on('click',   function () { acao('/aprovar',   {observacao: $('#obs-aprovacao').val()}); });
      $('#btn-negar')     && $('#btn-negar').on('click',     function () { acao('/negar',     {motivo: $('#obs-negar').val()}); });
      $('#btn-receber')   && $('#btn-receber').on('click',   function () { acao('/receber',   {}); });
      $('#btn-inspecionar')&&$('#btn-inspecionar').on('click',function(){
        var res = $('input[name="insp_resultado"]:checked').val();
        if (!res) { Toast.error('Selecione o resultado da inspeção.'); return; }
        acao('/inspecionar',{resultado:res,observacao:$('#obs-inspecao').val(),valor_aprovado:$('#val-aprovado').val()});
      });
      $('#btn-reembolsar')&&$('#btn-reembolsar').on('click',function(){acao('/reembolsar',{tipo_reembolso:$('#tipo-reembolso-sel').val()});});
    })();
  }


  
  $('#btn-registrar-recebimento').on('click', function () {
    abrirDrawerRecebimento();
  });

  function abrirDrawerRecebimento() {
    var drawer = window.adminDrawer({
      titulo:  'Registrar recebimento manual',
      tamanho: 'sm',
      conteudo: renderPassoBusca(),
    });

    // ── Passo 1: busca ────────────────────────────────
    function renderPassoBusca() {
      return (
        '<p style="font-size:13px;color:#64748b;margin:0 0 14px;line-height:1.6;">' +
          'Informe qualquer referência: CPF, código do pedido, rastreio ou código de postagem.' +
        '</p>' +
        '<div style="display:flex;gap:8px;margin-bottom:12px;">' +
          '<input type="text" id="rec-busca" class="form-control" ' +
                'placeholder="Buscar por CPF, pedido, rastreio..." autofocus style="flex:1;">' +
          '<button type="button" class="btn btn-primary btn-sm" id="rec-buscar-btn">Buscar</button>' +
        '</div>' +
        '<div id="rec-busca-result"></div>'
      );
    }

    $(document).on('keydown', '#rec-busca', function (e) {
      if (e.key === 'Enter') $('#rec-buscar-btn').trigger('click');
    });

    $(document).on('click', '#rec-buscar-btn', function () {
      var q = $('#rec-busca').val().trim();
      if (q.length < 3) {
        $('#rec-busca-result').html(
          '<div style="font-size:13px;color:#dc2626;">Digite ao menos 3 caracteres.</div>'
        );
        return;
      }

      var $btn = $(this);
      CK.btnLoading($btn);
      $('#rec-busca-result').html(
        '<div style="font-size:13px;color:#94a3b8;padding:8px 0;">Buscando…</div>'
      );

      $.get(BASE + '/devolucoes/buscar-para-recebimento', { q: q})
      .done(function (r) {
        CK.btnLoading($btn, false);
        if (!r.ok) {
          $('#rec-busca-result').html(
            '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;' +
                'padding:10px 14px;font-size:13px;color:#dc2626;">' + r.msg + '</div>'
          );
          return;
        }
        renderResultadoBusca(r.sol, drawer);
      })
      .fail(function () {
        CK.btnLoading($btn, false);
        $('#rec-busca-result').html(
          '<div style="color:#dc2626;font-size:13px;">Erro de conexão.</div>'
        );
      });
    });
  }

  // ── Passo 2: confirmação ──────────────────────────────
  function renderResultadoBusca(sol, drawer) {
    var statusMap = {
      aprovado:           'Aprovado',
      aguardando_postagem:'Aguardando postagem',
      em_transito_reverso:'Em trânsito reverso',
    };
    var statusLabel = statusMap[sol.status] || sol.status;

    var html =
      // Card de resumo da solicitação encontrada
      '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;' +
          'padding:14px 16px;margin-bottom:18px;">' +
        '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;' +
                    'color:#94a3b8;margin-bottom:8px;">Solicitação encontrada</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;font-size:13px;">' +
          '<div><span style="color:#94a3b8;">Pedido</span><br>' +
              '<strong>#' + sol.pedido_codigo + '</strong></div>' +
          '<div><span style="color:#94a3b8;">Status</span><br>' +
              '<span style="font-weight:700;">' + statusLabel + '</span></div>' +
          '<div><span style="color:#94a3b8;">Cliente</span><br>' +
              '<span>' + sol.cliente_nome + '</span></div>' +
          '<div><span style="color:#94a3b8;">Tipo</span><br>' +
              '<span style="text-transform:capitalize;">' + sol.tipo + '</span></div>' +
        '</div>' +
      '</div>' +

      '<input type="hidden" id="rec-sol-id" value="' + sol.id + '">' +

      // Campos opcionais
      '<div class="ap-form-group">' +
        '<label class="ap-form-label">Código de postagem reversa</label>' +
        '<input type="text" id="rec-postagem" class="form-control"' +
              ' value="' + (sol.codigo_postagem_reversa || '') + '"' +
              ' placeholder="PAC / Correios"' +
              ' style="text-transform:uppercase;font-family:\'SF Mono\',monospace;">' +
        (sol.codigo_postagem_reversa
          ? '<small class="ap-form-hint" style="color:#16a34a;">✓ Já registrado — deixe para manter.</small>'
          : '<small class="ap-form-hint">Opcional.</small>') +
      '</div>' +

      '<div class="ap-form-group">' +
        '<label class="ap-form-label">Código de rastreamento reverso</label>' +
        '<input type="text" id="rec-rastreio" class="form-control"' +
              ' value="' + (sol.codigo_rastreio_reverso || '') + '"' +
              ' placeholder="Ex: AA123456789BR"' +
              ' style="text-transform:uppercase;font-family:\'SF Mono\',monospace;">' +
        (sol.codigo_rastreio_reverso
          ? '<small class="ap-form-hint" style="color:#16a34a;">✓ Já registrado — deixe para manter.</small>'
          : '') +
      '</div>' +

      '<div class="ap-form-group">' +
        '<label class="ap-form-label">Observação</label>' +
        '<textarea id="rec-obs" class="form-control" rows="2"' +
                ' placeholder="Opcional — ex: Embalagem danificada, produto ok."></textarea>' +
      '</div>' +

      '<div id="rec-confirm-error" style="display:none;background:#fef2f2;border:1px solid #fca5a5;' +
          'border-radius:8px;padding:10px 14px;font-size:13px;color:#dc2626;margin-bottom:12px;"></div>' +

      '<div style="display:flex;gap:8px;justify-content:space-between;">' +
        '<button type="button" class="btn btn-ghost btn-sm" id="rec-voltar">← Voltar</button>' +
        '<button type="button" class="btn btn-primary btn-sm" id="rec-confirmar">Confirmar recebimento</button>' +
      '</div>';

    $('#rec-busca-result').html(html);

    // Voltar
    $(document).on('click', '#rec-voltar', function () {
      $('#rec-busca-result').html('');
      $('#rec-sol-id').remove();
      $('#rec-busca').val('').focus();
    });

    // Confirmar
    $(document).on('click', '#rec-confirmar', function () {
      var $btn = $(this);
      var $err = $('#rec-confirm-error');
      CK.btnLoading($btn);
      $err.hide();

      $.post(BASE + '/devolucoes/receber-manual', {
        _csrf_token          : CSRF_TOKEN,
        sol_id          : $('#rec-sol-id').val(),
        codigo_postagem : $('#rec-postagem').val().trim().toUpperCase(),
        codigo_rastreio : $('#rec-rastreio').val().trim().toUpperCase(),
        observacao      : $('#rec-obs').val().trim(),
      })
      .done(function (r) {
        CK.btnLoading($btn, false);
        if (r.ok) {
          drawer.setConteudo(
            '<div style="text-align:center;padding:32px 20px;">' +
              '<div style="width:52px;height:52px;background:#f0fdf4;border-radius:50%;display:flex;' +
                          'align-items:center;justify-content:center;margin:0 auto 14px;">' +
                '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#16a34a"' +
                    ' stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>' +
              '</div>' +
              '<strong style="font-size:15px;display:block;margin-bottom:6px;">Recebimento registrado!</strong>' +
              '<p style="font-size:13.5px;color:#64748b;margin:0 0 18px;">' +
                'Solicitação #' + r.sol_id + ' atualizada para <strong>Item recebido</strong>.' +
              '</p>' +
              '<a href="' + BASE + '/devolucoes/' + r.sol_id + '" class="btn btn-primary btn-sm">Ver solicitação →</a>' +
            '</div>'
          );
          setTimeout(function () { location.reload(); }, 3500);
        } else {
          $err.text(r.msg || 'Erro desconhecido.').show();
        }
      })
      .fail(function () {
        CK.btnLoading($btn, false);
        $err.text('Erro de conexão. Tente novamente.').show();
      });
    });
  }
})();