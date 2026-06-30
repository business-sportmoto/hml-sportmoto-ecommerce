(function ($) {
  var calendarDays = [
    { day: 'Seg', dates: [1, 8, 15, 22] },
    { day: 'Ter', dates: [2, 9, 16, 23] },
    { day: 'Qua', dates: [3, 10, 17, 24] },
    { day: 'Qui', dates: [4, 11, 18, 25] },
    { day: 'Sex', dates: [5, 12, 19, 26] },
    { day: 'Sáb', dates: [6, 13, 20, 27] },
    { day: 'Dom', dates: [7, 14, 21, 28] }
  ];

  var highlightStart = 5;
  var highlightEnd = 23;
  var businessDays = [5, 8, 9, 10, 11, 12, 15, 16, 17, 18, 19, 22, 23];
  var weekendDays = [6, 7, 13, 14, 20, 21, 27, 28];

  function getCalendarClass(date) {
    if (date === highlightStart) return 'start';
    if (date === highlightEnd) return 'end';
    if (businessDays.indexOf(date) > -1 && date > highlightStart && date < highlightEnd) return 'business';
    if (weekendDays.indexOf(date) > -1) return 'weekend';
    return '';
  }

  function buildCalendar() {
    var $grid = $('#calendarGrid');
    if (!$grid.length) return;

    calendarDays.forEach(function (item) {
      $grid.append('<div class="calendar-day-label">' + item.day + '</div>');
    });

    [0, 1, 2, 3].forEach(function (weekIndex) {
      calendarDays.forEach(function (item) {
        var date = item.dates[weekIndex];
        var cls = getCalendarClass(date);
        $grid.append('<div class="calendar-cell ' + cls + '">' + date + '</div>');
      });
    });
  }

  function buildStars() {
    $('.stars').each(function () {
      var rating = parseInt($(this).attr('data-rating'), 10) || 0;
      var starsHtml = '';
      for (var i = 0; i < 5; i++) {
        starsHtml += (i < rating ? '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="star" aria-hidden="true" class="lucide lucide-star is-filled"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"></path></svg>' : 
          '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="star" aria-hidden="true" class="lucide lucide-star is-empty"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"></path></svg>');
      }
      $(this).html(starsHtml);
    });
  }

  function initFaq() {
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

  function initScrollAnimation() {
    var elements = document.querySelectorAll('.scroll-fade-in, .scroll-slide-left, .scroll-slide-right');

    if (!('IntersectionObserver' in window)) {
      elements.forEach(function (el) { el.classList.add('visible'); });
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
        }
      });
    }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

    elements.forEach(function (el) { observer.observe(el); });
  }

  $(function () {
    buildCalendar();
    buildStars();
    initFaq();
    initScrollAnimation();
    $('#currentYear').text(new Date().getFullYear());

    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }
  });
})(jQuery);
