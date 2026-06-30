(function($){
  'use strict';

  var trackerFreeShipping = {
    "São Paulo":199,"Rio de Janeiro":249,"Minas Gerais":249,"Espírito Santo":279,"Paraná":249,
    "Santa Catarina":249,"Rio Grande do Sul":279,"Goiás":299,"Distrito Federal":299,"Mato Grosso":349,
    "Mato Grosso do Sul":329,"Bahia":329,"Sergipe":349,"Alagoas":349,"Pernambuco":349,
    "Paraíba":369,"Rio Grande do Norte":369,"Ceará":369,"Piauí":379,"Maranhão":379,"Pará":399,
    "Amapá":449,"Amazonas":449,"Roraima":499,"Acre":449,"Rondônia":399,"Tocantins":379
  };

  var trackerIcons = {
    truck:'<svg class="tracker_icon" viewBox="0 0 24 24"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>',
    mail:'<svg class="tracker_icon" viewBox="0 0 24 24"><path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect x="2" y="4" width="20" height="16" rx="2"/></svg>',
    message:'<svg class="tracker_icon" viewBox="0 0 24 24"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>',
    calendar:'<svg class="tracker_icon" viewBox="0 0 24 24"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>',
    shield:'<svg class="tracker_icon" viewBox="0 0 24 24"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>',
    clock:'<svg class="tracker_icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
    package:'<svg class="tracker_icon" viewBox="0 0 24 24"><path d="m16 16 2 2 4-4"/><path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/><path d="m7.5 4.27 9 5.15"/><path d="M3.29 7 12 12l8.71-5"/><path d="M12 22V12"/></svg>',
    alert:'<svg class="tracker_icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>',
    pin:'<svg class="tracker_icon" viewBox="0 0 24 24"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg>'
  };

  function trackerCurrency(value){
    return value.toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
  }

  function trackerBuildIcons(){
    $('[data-tracker-icon]').each(function(){
      var name = $(this).attr('data-tracker-icon');
      if(trackerIcons[name]) $(this).html(trackerIcons[name]);
    });
  }

  function trackerBuildCalendar(){
    var $days = $('#tracker_calendar_days');
    for(var d=1; d<=30; d++){
      var idx = (d - 1) % 7;
      var cls = '';
      if(idx === 5 || idx === 6) cls = 'tracker_weekend';
      if(d === 5) cls = 'tracker_collected';
      if(d === 23) cls = 'tracker_delivered';
      $days.append('<span class="'+cls+'">'+d+'</span>');
    }
    
  }

  function trackerReveal(){
    var observer = new IntersectionObserver(function(entries){
      entries.forEach(function(entry){
        if(entry.isIntersecting){
          entry.target.classList.add('tracker_visible');
          observer.unobserve(entry.target);
        }
      });
    },{threshold:.14,rootMargin:'0px 0px -40px 0px'});
    document.querySelectorAll('.tracker_reveal').forEach(function(el){observer.observe(el);});
  }

  function trackerSmoothScroll(){
    $('a[href^="#"]').on('click',function(e){
      var href = $(this).attr('href');
      if(!href || href === '#') return;
      var $target = $(href);
      if(!$target.length) return;
      e.preventDefault();
      $('html, body').animate({scrollTop:$target.offset().top - 20},500);
    });
  }

  function trackerFaq(){
    $('.tracker_faq_item button').on('click',function(){
      var $item = $(this).closest('.tracker_faq_item');
      var isOpen = $item.hasClass('tracker_open');
      $('.tracker_faq_item.tracker_open').removeClass('tracker_open').children('div').stop(true,true).slideUp(220);
      if(!isOpen){
        $item.addClass('tracker_open').children('div').stop(true,true).slideDown(220);
      }
    });
  }

  function trackerStateLabel(stateName){
    var value = trackerFreeShipping[stateName];
    return typeof value === 'number' ? 'Frete grátis a partir de ' + trackerCurrency(value) : 'Condição sob consulta';
  }

  function trackerSelectState(stateName){
    if(!stateName) return;

    $('#tracker_selected_state').text(stateName);
    $('#tracker_selected_value').text(trackerStateLabel(stateName));
    $('#tracker_state_select').val(stateName);

    $('.tracker_quick_states button').removeClass('tracker_active')
      .filter('[data-state="'+stateName+'"]').addClass('tracker_active');

    $('#tracker_brazil_map path[data-state]').removeClass('tracker_selected')
      .filter('[data-state="'+stateName+'"]').addClass('tracker_selected');
  }

  function trackerBuildStateSelect(){
    var $select = $('#tracker_state_select');
    if(!$select.length) return;

    Object.keys(trackerFreeShipping).sort(function(a,b){
      return a.localeCompare(b,'pt-BR');
    }).forEach(function(stateName){
      $select.append('<option value="'+stateName+'">'+stateName+' — '+trackerCurrency(trackerFreeShipping[stateName])+'</option>');
    });

    $select.on('change',function(){
      trackerSelectState($(this).val());
    });

    $('.tracker_quick_states button').on('click',function(){
      trackerSelectState($(this).attr('data-state'));
    });
  }

  function trackerMap(){
    var $wrap = $('#tracker_brazil_map');
    var $tip = $('#tracker_map_tooltip');
    if(!window.trackerBrazilSvg || !$wrap.length) return;
    $wrap.html(window.trackerBrazilSvg);

    $wrap.find('path[data-state]')
      .attr('tabindex','0')
      .attr('role','button')
      .on('mouseenter focus',function(){
        var stateName = $(this).attr('data-state') || '';
        $tip.html('<strong>'+stateName+'</strong>'+trackerStateLabel(stateName)).addClass('tracker_show');
      }).on('mousemove',function(e){
        $tip.css({left:e.clientX + 14, top:e.clientY + 14});
      }).on('mouseleave blur',function(){
        $tip.removeClass('tracker_show');
      }).on('click touchstart',function(e){
        e.preventDefault();
        var stateName = $(this).attr('data-state') || '';
        trackerSelectState(stateName);
        $tip.html('<strong>'+stateName+'</strong>'+trackerStateLabel(stateName)).addClass('tracker_show');

        if(e.originalEvent && e.originalEvent.touches && e.originalEvent.touches.length){
          var touch = e.originalEvent.touches[0];
          $tip.css({left:Math.min(touch.clientX + 10, window.innerWidth - 190), top:touch.clientY + 10});
          setTimeout(function(){ $tip.removeClass('tracker_show'); }, 1600);
        }
      }).on('keydown',function(e){
        if(e.key === 'Enter' || e.key === ' '){
          e.preventDefault();
          trackerSelectState($(this).attr('data-state'));
        }
      });
  }

  $(function(){
    trackerBuildIcons();
    trackerBuildCalendar();
    trackerReveal();
    trackerSmoothScroll();
    trackerFaq();
    trackerBuildStateSelect();
    trackerMap();
    $('#tracker_current_year').text(new Date().getFullYear());
  });
})(jQuery);
