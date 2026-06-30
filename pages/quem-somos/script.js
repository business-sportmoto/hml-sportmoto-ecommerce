
(function ($) {
  function revealOnScroll() {
    var windowBottom = $(window).scrollTop() + $(window).height() - 40;
    jQuery('.reveal').each(function () {
      var $el = jQuery(this);
      if ($el.hasClass('is-visible')) return;
      var elementTop = $el.offset().top;
      if (elementTop < windowBottom) {
        $el.addClass('is-visible');
      }
    });
  }

  $(function () {
    jQuery('#currentYear').text(new Date().getFullYear());

    revealOnScroll();
    jQuery(window).on('scroll resize', revealOnScroll);

    $('.faq-question').on('click', function () {
      var $item = jQuery(this).closest('.faq-item');
      var $answer = $item.find('.faq-answer');

      if ($item.hasClass('is-open')) {
        $item.removeClass('is-open');
        $answer.stop(true, true).slideUp(200);
        return;
      }

      jQuery('.faq-item.is-open').removeClass('is-open').find('.faq-answer').stop(true, true).slideUp(200);
      $item.addClass('is-open');
      $answer.stop(true, true).slideDown(200);
    });
  });
})(jQuery);
