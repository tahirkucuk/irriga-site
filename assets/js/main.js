/* =============================================
   Irriga Sulama Sistemleri — Main JS
   ============================================= */

document.addEventListener('DOMContentLoaded', function () {

  /* --- Sticky header shadow --- */
  var header = document.getElementById('site-header');
  if (header) {
    window.addEventListener('scroll', function () {
      header.classList.toggle('scrolled', window.scrollY > 20);
    }, { passive: true });
  }

  /* --- Mobile hamburger --- */
  var hamburger = document.querySelector('.hamburger');
  var mobileNav = document.querySelector('.mobile-nav');
  if (hamburger && mobileNav) {
    hamburger.addEventListener('click', function () {
      hamburger.classList.toggle('open');
      mobileNav.classList.toggle('open');
      document.body.style.overflow = mobileNav.classList.contains('open') ? 'hidden' : '';
    });
    mobileNav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        hamburger.classList.remove('open');
        mobileNav.classList.remove('open');
        document.body.style.overflow = '';
      });
    });
  }

  /* --- Counter animation --- */
  var counters = document.querySelectorAll('.counter');
  var animated = new Set();
  function animateCounter(el) {
    var target = parseInt(el.getAttribute('data-target'), 10) || 0;
    var suffix = el.getAttribute('data-suffix') || '';
    var duration = 1400;
    var start = null;
    function step(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / duration, 1);
      el.textContent = Math.round(p * target) + suffix;
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  if ('IntersectionObserver' in window && counters.length) {
    var obs = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting && !animated.has(e.target)) {
          animated.add(e.target);
          animateCounter(e.target);
        }
      });
    }, { threshold: 0.4 });
    counters.forEach(function (el) { obs.observe(el); });
  }

  /* --- Back to top --- */
  var backTop = document.getElementById('back-top');
  if (backTop) {
    window.addEventListener('scroll', function () {
      backTop.classList.toggle('visible', window.scrollY > 400);
    }, { passive: true });
    backTop.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  /* --- Cookie banner --- */
  var cookieBanner = document.getElementById('cookie-banner');
  if (cookieBanner) {
    if (!localStorage.getItem('irriga_cookie')) {
      cookieBanner.style.display = 'flex';
    }
    var acceptBtn = document.getElementById('cookie-accept');
    var declineBtn = document.getElementById('cookie-decline');
    function dismissCookie() { cookieBanner.style.display = 'none'; }
    if (acceptBtn) acceptBtn.addEventListener('click', function () {
      localStorage.setItem('irriga_cookie', 'accepted');
      dismissCookie();
    });
    if (declineBtn) declineBtn.addEventListener('click', function () {
      localStorage.setItem('irriga_cookie', 'declined');
      dismissCookie();
    });
  }

  /* --- FAQ accordion --- */
  document.querySelectorAll('.faq-q').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var item = btn.closest('.faq-item');
      var isOpen = item.classList.contains('open');
      document.querySelectorAll('.faq-item.open').forEach(function (el) {
        el.classList.remove('open');
      });
      if (!isOpen) item.classList.add('open');
    });
  });

  /* --- Contact / Quote form (AJAX to contact.php) --- */
  var forms = document.querySelectorAll('.ajax-form');
  forms.forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('button[type=submit]');
      var origText = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Gönderiliyor…';

      var alertEl = form.querySelector('.alert');
      if (alertEl) alertEl.remove();

      fetch('contact.php', {
        method: 'POST',
        body: new FormData(form)
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            var success = form.closest('.form-wrap') && form.closest('.form-wrap').querySelector('.form-success');
            if (success) {
              form.style.display = 'none';
              success.style.display = 'block';
            } else {
              var a = document.createElement('div');
              a.className = 'alert alert-success';
              a.textContent = 'Talebiniz alındı! En kısa sürede sizinle iletişime geçeceğiz. 🌱';
              form.prepend(a);
              form.reset();
            }
          } else {
            var a = document.createElement('div');
            a.className = 'alert alert-error';
            a.textContent = data.msg || 'Bir hata oluştu, lütfen tekrar deneyin.';
            form.prepend(a);
          }
        })
        .catch(function () {
          var a = document.createElement('div');
          a.className = 'alert alert-error';
          a.textContent = 'Bağlantı hatası. Lütfen WhatsApp üzerinden ulaşın.';
          form.prepend(a);
        })
        .finally(function () {
          btn.disabled = false;
          btn.textContent = origText;
        });
    });
  });

  /* --- Active nav link --- */
  var current = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-links a, .mobile-nav a').forEach(function (a) {
    var href = a.getAttribute('href');
    if (href === current || (current === '' && href === 'index.html')) {
      a.classList.add('active');
    }
  });

});
