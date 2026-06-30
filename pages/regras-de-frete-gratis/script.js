(function ($) {
  var checkoutBaseUrl = 'https://www.sportmoto.com.br/carrinho';
  var $window = $(window);
  var $header = $('#siteHeader');
  var $mobileToggle = $('#mobileToggle');
  var $mobileMenu = $('#mobileMenu');
  var $navLinks = $('.js-scroll-link');
  var $sections = $('main section[id]');
  var $feedback = $('#formFeedback');

  function setHeaderState() {
    $header.toggleClass('is-scrolled', $window.scrollTop() > 20);
  }

  function updateActiveNav() {
    var scrollPos = $window.scrollTop() + 120;
    var currentId = '#hero';

    $sections.each(function () {
      var $section = $(this);
      if ($section.offset().top <= scrollPos) {
        currentId = '#' + $section.attr('id');
      }
    });

    $navLinks.removeClass('is-active');
    $('.js-scroll-link[href="' + currentId + '"]').addClass('is-active');
  }

  function closeMobileMenu() {
    $mobileToggle.removeClass('is-open').attr('aria-expanded', 'false');
    $mobileMenu.stop(true, true).slideUp(180);
  }

  function openMobileMenu() {
    $mobileToggle.addClass('is-open').attr('aria-expanded', 'true');
    $mobileMenu.stop(true, true).slideDown(180);
  }

  function maskCep(value) {
    return value.replace(/\D/g, '').replace(/(\d{5})(\d)/, '$1-$2').slice(0, 9);
  }

  function revealOnScroll() {
    $('.reveal').each(function () {
      var rect = this.getBoundingClientRect();
      if (rect.top < window.innerHeight - 70) {
        this.classList.add('is-visible');
      }
    });
  }

  $mobileMenu.hide();
  setHeaderState();
  updateActiveNav();
  revealOnScroll();
  $('#year').text(new Date().getFullYear());

  $window.on('scroll', function () {
    setHeaderState();
    updateActiveNav();
    revealOnScroll();
  });

  $mobileToggle.on('click', function () {
    if ($mobileToggle.hasClass('is-open')) {
      closeMobileMenu();
      return;
    }
    openMobileMenu();
  });

  $navLinks.on('click', function (e) {
    var target = $(this).attr('href');
    if (!target || target.charAt(0) !== '#') return;

    var $target = $(target);
    if (!$target.length) return;

    e.preventDefault();
    $('html, body').animate({
      scrollTop: $target.offset().top - 70
    }, 500);

    closeMobileMenu();
  });

  $('#cepInput').on('input', function () {
    this.value = maskCep(this.value);
    $feedback.removeClass('is-error is-success').html('Personalize a URL do botão no arquivo <strong>js/script.js</strong>.');
  });

  $('#cepForm').on('submit', function (e) {
    e.preventDefault();

    var cep = $('#cepInput').val().toString().replace(/\D/g, '');

    if (cep.length !== 8) {
      $feedback.removeClass('is-success').addClass('is-error').text('Informe um CEP válido com 8 números.');
      return;
    }

    $feedback.removeClass('is-error').addClass('is-success').text('Redirecionando para consulta de frete...');

    var redirectUrl = checkoutBaseUrl + '?cep=' + cep;
    window.location.href = redirectUrl;
  });
})(jQuery);
