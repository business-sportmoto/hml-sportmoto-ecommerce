(function ($) {
  function startHeroAnimation() {
    $('.js-hero-animate').each(function (index) {
      var $el = $(this);
      setTimeout(function () {
        $el.addClass('is-visible');
      }, index * 160);
    });
  }

  function startCountdown() {
    var $countdown = $('.countdown');
    if (!$countdown.length) return;

    var targetDate = new Date($countdown.data('target-date')).getTime();

    function pad(value) {
      return String(value).padStart(2, '0');
    }

    function tick() {
      var diff = Math.max(0, targetDate - Date.now());
      var days = Math.floor(diff / 86400000);
      var hours = Math.floor((diff % 86400000) / 3600000);
      var minutes = Math.floor((diff % 3600000) / 60000);
      var seconds = Math.floor((diff % 60000) / 1000);

      $countdown.find('[data-unit="days"]').text(pad(days));
      $countdown.find('[data-unit="hours"]').text(pad(hours));
      $countdown.find('[data-unit="minutes"]').text(pad(minutes));
      $countdown.find('[data-unit="seconds"]').text(pad(seconds));
    }

    tick();
    setInterval(tick, 1000);
  }

  function faqAccordion() {
    $('.faq-question').on('click', function () {
      var $item = $(this).closest('.faq-item');
      var $answer = $item.find('.faq-answer');

      if ($item.hasClass('is-open')) {
        $item.removeClass('is-open');
        $answer.stop(true, true).slideUp(250);
        return;
      }

      $('.faq-item.is-open').removeClass('is-open').find('.faq-answer').stop(true, true).slideUp(250);
      $item.addClass('is-open');
      $answer.stop(true, true).slideDown(250);
    });
  }

  function revealOnScroll() {
    var $items = $('.reveal-item');
    if (!$items.length) return;

    function checkReveal() {
      var viewportBottom = $(window).scrollTop() + $(window).height();

      $items.each(function () {
        var $el = $(this);
        if ($el.hasClass('revealed')) return;

        var offsetTop = $el.offset().top;
        var delay = parseInt($el.attr('data-delay'), 10) || 0;

        if (viewportBottom > offsetTop + 40) {
          setTimeout(function () {
            $el.addClass('revealed');
          }, delay);
        }
      });
    }

    checkReveal();
    $(window).on('scroll resize', checkReveal);
  }

  function handleForms() {
    $('.newsletter-form').on('submit', function (e) {
      e.preventDefault();
      this.reset();
    });
  }

  function faqSearch() {
    $('#faqSearch').on('input', function () {
      var term = $.trim($(this).val()).toLowerCase();

      $('.faq-item').each(function () {
        var text = $(this).text().toLowerCase();
        var match = !term || text.indexOf(term) !== -1;
        $(this).toggle(match);
      });
    });
  }

  $(function () {
    startHeroAnimation();
    startCountdown();
    faqAccordion();
    revealOnScroll();
    handleForms();
    faqSearch();
  });
})(jQuery);
