(function () {
  'use strict';

  var cfg = window.LANDING_CONFIG || {};
  var claims = cfg.approvedClaims || {};
  var isDraft = cfg.mode !== 'production';
  var isDemo = !!cfg.demo || location.protocol === 'file:';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function store(kind) {
    try { return window[kind]; } catch (e) { return null; }
  }
  function storeGet(kind, k) { try { var s = store(kind); return s ? s.getItem(k) : null; } catch (e) { return null; } }
  function storeSet(kind, k, v) { try { var s = store(kind); if (s) s.setItem(k, v); } catch (e) { /* приватный режим */ } }

  /* ---------- Аналитика (без персональных данных) ---------- */
  window.dataLayer = window.dataLayer || [];
  function track(name, params) {
    var data = { event: name };
    for (var k in params || {}) data[k] = params[k];
    window.dataLayer.push(data);
  }

  // Пиксель Meta загружается сразу (Meta его «видит»), но в режиме согласия:
  // до «Accetta» события не отправляются — Meta держит их, пока не придёт consent 'grant'.
  function loadPixel(id, granted) {
    /* eslint-disable */
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
    document,'script','https://connect.facebook.net/en_US/fbevents.js');
    /* eslint-enable */
    if (!granted) window.fbq('consent', 'revoke');
    window.fbq('init', id);
    window.fbq('track', 'PageView');
  }

  function initCookies() {
    var a = cfg.analytics || {};
    if (!a.metaPixelId || isDemo) return;
    var choice = storeGet('localStorage', 'cookie_consent');
    var granted = !a.requireCookieConsent || choice === 'accept';
    loadPixel(a.metaPixelId, granted);
    if (granted || choice === 'deny') return;
    var box = $('#cookie');
    box.hidden = false;
    $$('[data-cookie]', box).forEach(function (b) {
      b.addEventListener('click', function () {
        var v = b.getAttribute('data-cookie');
        storeSet('localStorage', 'cookie_consent', v);
        box.hidden = true;
        if (v === 'accept' && window.fbq) window.fbq('consent', 'grant');
      });
    });
  }

  /* ---------- UTM и рекламные параметры ---------- */
  var TRACK_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
    'campaign_id', 'adset_id', 'ad_id', 'fbclid'];
  function captureParams() {
    var q = new URLSearchParams(location.search);
    var saved = {};
    try { saved = JSON.parse(storeGet('sessionStorage', 'lp_params') || '{}') || {}; } catch (e) { saved = {}; }
    TRACK_PARAMS.forEach(function (p) {
      var v = q.get(p);
      if (v) saved[p] = v.slice(0, 200);
    });
    storeSet('sessionStorage', 'lp_params', JSON.stringify(saved));
    return saved;
  }

  /* ---------- Тексты и утверждения ---------- */
  function applyTexts() {
    var t = cfg.text || {};
    $$('[data-t]').forEach(function (el) {
      var v = t[el.getAttribute('data-t')];
      if (v != null && v !== '') el.textContent = v;
      if (el.classList.contains('ph') && v && v.indexOf('[') === -1) el.classList.remove('ph');
    });
  }

  function applyClaims() {
    document.documentElement.classList.toggle('mode-draft', isDraft);
    $$('[data-claim]').forEach(function (el) {
      var ok = !!claims[el.getAttribute('data-claim')];
      if (ok || isDraft) {
        el.classList.add('claim-on');
        if (!ok) el.classList.add('claim-draft');
      }
    });
    $$('[data-claim-alt]').forEach(function (el) {
      var ok = !!claims[el.getAttribute('data-claim-alt')];
      if (ok || isDraft) el.classList.add('claim-off');
    });
  }

  /* ---------- Цены ---------- */
  var eur = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });
  function fmt(v) { return typeof v === 'number' ? eur.format(v) : '€ —'; }

  function applyPrices(expired) {
    var p = cfg.price || {};
    var current = expired ? p.afterExpiry : p.current;
    var parts = [current, p.shipping, p.extraFees];
    var total = typeof current === 'number'
      ? parts.reduce(function (s, v) { return s + (typeof v === 'number' ? v : 0); }, 0)
      : null;
    var values = {
      old: fmt(p.old),
      current: fmt(current),
      shipping: p.shipping === 0 ? 'Gratuita' : fmt(p.shipping),
      extraFees: fmt(p.extraFees),
      total: fmt(total),
      discountPercent: String(p.discountPercent != null ? p.discountPercent : '')
    };
    $$('[data-price]').forEach(function (el) {
      el.textContent = values[el.getAttribute('data-price')];
    });
    $$('[data-extra-fees]').forEach(function (el) { el.hidden = typeof p.extraFees !== 'number'; });

    if (typeof p.old === 'number' && typeof p.current === 'number' && p.discountPercent) {
      var real = Math.round((1 - p.current / p.old) * 100);
      if (real !== p.discountPercent) {
        console.warn('[landing] discountPercent=' + p.discountPercent + '%, фактическая скидка ' + real + '%');
      }
    }
    // После окончания акции без новой цены — скрываем строки цен целиком
    if (expired && typeof current !== 'number') {
      $$('.price-box__now, .price-box__lines, .final__price').forEach(function (el) { el.hidden = true; });
    }
  }

  /* ---------- Таймер и остаток ---------- */
  var clockOffset = 0; // серверное время − локальное
  function setPromoState(expired) {
    $$('[data-offer-promo]').forEach(function (el) { el.classList.toggle('promo-off', expired); });
    $$('[data-offer-ended]').forEach(function (el) { el.hidden = !expired; });
    applyPrices(expired);
  }

  function startTimer(endsAt) {
    var end = new Date(endsAt).getTime();
    if (isNaN(end)) return;
    var dateFmt = new Intl.DateTimeFormat('it-IT', {
      timeZone: 'Europe/Rome', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit'
    });
    $$('[data-offer-end-date]').forEach(function (el) { el.textContent = dateFmt.format(new Date(end)); });
    var cells = {};
    ['d', 'h', 'm', 's', 'H'].forEach(function (k) { cells[k] = $$('[data-timer="' + k + '"]'); });
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var timerId;
    function tick() {
      var left = Math.floor((end - (Date.now() + clockOffset)) / 1000);
      if (left <= 0) {
        clearInterval(timerId);
        setPromoState(true);
        return;
      }
      var set = function (k, v) { cells[k].forEach(function (el) { el.textContent = v; }); };
      set('d', Math.floor(left / 86400));
      set('h', pad(Math.floor(left % 86400 / 3600)));
      set('H', pad(Math.floor(left / 3600))); // всего часов — для компактного таймера на первом экране
      set('m', pad(Math.floor(left % 3600 / 60)));
      set('s', pad(left % 60));
    }
    tick();
    timerId = setInterval(tick, 1000);
  }

  // Персональная акция: начало фиксируется при первом заходе и не сбрасывается при повторных
  var personalEnd = null;
  function personalEndsAt(hours) {
    var key = 'lp_offer_start_' + (cfg.offerId || 'default');
    var start = parseInt(storeGet('localStorage', key), 10);
    if (!start || isNaN(start)) {
      start = Date.now() + clockOffset;
      storeSet('localStorage', key, String(start));
    }
    personalEnd = new Date(start + hours * 3600 * 1000);
    return personalEnd.toISOString();
  }

  function initOffer() {
    var o = cfg.offer || {};
    var hours = o.personalHours || 0;
    var fallback = function () {
      setStock(o.fallbackStock);
      startTimer(hours ? personalEndsAt(hours) : o.fallbackEndsAt);
    };
    applyPrices(false);
    if (isDemo || !cfg.form || !cfg.form.offerEndpoint || !window.fetch) { fallback(); return; }
    fetch(cfg.form.offerEndpoint, { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        if (d.now) clockOffset = new Date(d.now).getTime() - Date.now();
        setStock(d.stock != null ? d.stock : o.fallbackStock);
        if (d.active === false) { setPromoState(true); return; }
        startTimer(hours ? personalEndsAt(hours) : (d.ends_at || o.fallbackEndsAt));
      })
      .catch(fallback);
  }

  function setStock(n) {
    $$('[data-offer-stock]').forEach(function (el) { el.textContent = n; });
    if (n === 0) setPromoState(true);
  }

  /* ---------- Отзывы из редактора (admin/) ---------- */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function safeUrl(u) { return /^(assets\/[\w./-]+|https:\/\/[^\s"'<>]+)$/.test(u || '') ? u : ''; }

  function renderReviews(list) {
    var track = $('[data-reviews-track]');
    if (!track) return;
    if (!list.length) {
      // Нет опубликованных: в черновике остаются заглушки, в рабочем режиме блок скрывается
      if (!isDraft) $('#reviews').hidden = true;
      return;
    }
    track.innerHTML = list.map(function (r) {
      var photo = safeUrl(r.photo), video = safeUrl(r.video), subs = safeUrl(r.subtitles);
      var initials = String(r.name || '?').trim().split(/\s+/).map(function (w) { return w.charAt(0); }).join('').slice(0, 2).toUpperCase();
      var meta = [r.age ? esc(r.age) + ' anni' : '', esc(r.city || '')].filter(Boolean).join(' · ');
      return '<article class="review">' +
        '<blockquote class="review__text">' + esc(r.text) + '</blockquote>' +
        (video ? '<video class="review__video" controls preload="none" playsinline' + (photo ? ' poster="' + esc(photo) + '"' : '') + '>' +
          '<source src="' + esc(video) + '" type="video/mp4">' +
          (subs ? '<track kind="subtitles" srclang="it" label="Italiano" src="' + esc(subs) + '" default>' : '') +
        '</video>' : '') +
        '<footer class="review__head">' +
          (photo
            ? '<img class="review__photo" src="' + esc(photo) + '" width="64" height="64" loading="lazy" alt="">'
            : '<span class="review__avatar" aria-hidden="true">' + esc(initials) + '</span>') +
          '<p class="review__who"><strong>' + esc(r.name) + '</strong>' + (meta ? '<span>' + meta + '</span>' : '') + '</p>' +
        '</footer>' +
      '</article>';
    }).join('');
  }

  /* ---------- Мнения специалистов из редактора (admin/experts.html) ---------- */
  function renderExperts(list) {
    var track = $('[data-experts-track]');
    if (!track) return;
    if (!list.length) {
      if (!isDraft) $('#experts').hidden = true;
      return;
    }
    track.innerHTML = list.map(function (r) {
      var photo = safeUrl(r.photo), video = safeUrl(r.video), subs = safeUrl(r.subtitles);
      var initials = String(r.name || '?').replace(/^(dott\.?|dr\.?|prof\.?)\s+/i, '').trim().split(/\s+/)
        .map(function (w) { return w.charAt(0); }).join('').slice(0, 2).toUpperCase();
      var role = [esc(r.specialty || ''), esc(r.qualification || '')].filter(Boolean).join(' · ');
      var meta = [];
      if (r.source) meta.push('Fonte: ' + esc(r.source));
      if (r.relation) meta.push('Rapporto con il prodotto: ' + esc(r.relation));
      return '<article class="review expert-card">' +
        '<header class="expert-card__head">' +
          (photo
            ? '<img class="expert-card__photo" src="' + esc(photo) + '" width="72" height="72" loading="lazy" alt="' + esc(r.name) + '">'
            : '<span class="review__avatar expert-card__avatar" aria-hidden="true">' + esc(initials) + '</span>') +
          '<div><h3 class="expert-card__name">' + esc(r.name) + '</h3>' + (role ? '<p class="expert-card__role">' + role + '</p>' : '') + '</div>' +
        '</header>' +
        (r.quote ? '<blockquote class="review__text expert-card__quote">' + esc(r.quote) + '</blockquote>' : '') +
        (video ? '<video class="review__video" controls preload="none" playsinline' + (photo ? ' poster="' + esc(photo) + '"' : '') + '>' +
          '<source src="' + esc(video) + '" type="video/mp4">' +
          (subs ? '<track kind="subtitles" srclang="it" label="Italiano" src="' + esc(subs) + '" default>' : '') +
        '</video>' : '') +
        (meta.length ? '<p class="expert-card__meta">' + meta.join('<br>') + '</p>' : '') +
      '</article>';
    }).join('');
  }

  function initReviews() {
    var ep = cfg.form && cfg.form.reviewsEndpoint;
    if (isDemo || !ep || !window.fetch) return Promise.resolve();
    var get = function (url) { return fetch(url).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }); };
    return Promise.all([
      get(ep).then(function (d) { renderReviews(d.reviews || []); })
        .catch(function () { /* сервер недоступен — остаются отзывы из HTML */ }),
      get(ep + (ep.indexOf('?') > -1 ? '&' : '?') + 'c=experts').then(function (d) { renderExperts(d.reviews || []); })
        .catch(function () {})
    ]);
  }

  /* ---------- Карусель отзывов ----------
     Листается свайпом, стрелками (по кругу), точками и клавишами ← →.
     Автопрокрутки нет: аудитории 60+ нужно время дочитать (WCAG 2.2.2). */
  function initSlider() {
    $$('[data-slider]').forEach(function (root) {
      var track = $('.slider__track', root);
      var prev = $('[data-slider-prev]', root);
      var next = $('[data-slider-next]', root);
      var dotsBox = $('[data-slider-dots]', root);
      var count = $('[data-slider-count]', root);
      var nav = $('.slider__nav', root);
      var items = Array.prototype.slice.call(track.children);
      var dots = [];

      // На телефоне отзывы идут списком: первые 3 видны сразу, остальные — по кнопке
      var SHOW_FIRST = 3;
      if (items.length > SHOW_FIRST) {
        var more = document.createElement('button');
        more.type = 'button';
        more.className = 'btn reviews-more';
        more.setAttribute('data-track', track.hasAttribute('data-experts-track') ? 'btn-experts-more' : 'btn-reviews-more');
        more.textContent = (root.getAttribute('data-more-label') || 'Mostra altri {n}').replace('{n}', items.length - SHOW_FIRST);
        more.addEventListener('click', function () {
          root.classList.add('is-expanded');
          more.hidden = true;
          if (items[SHOW_FIRST]) items[SHOW_FIRST].setAttribute('tabindex', '-1'), items[SHOW_FIRST].focus({ preventScroll: true });
          track.dispatchEvent(new Event('scroll'));
        });
        root.appendChild(more);
      }

      function gap() { return parseFloat(getComputedStyle(track).columnGap) || 16; }
      function stepW() { return items[0] ? items[0].getBoundingClientRect().width + gap() : track.clientWidth; }
      function perView() { return Math.max(1, Math.round((track.clientWidth + gap()) / stepW())); }
      function pages() { return Math.max(1, items.length - perView() + 1); }
      function current() { return Math.min(pages() - 1, Math.round(track.scrollLeft / stepW())); }
      function go(i) {
        var n = pages();
        i = (i + n) % n; // по кругу
        track.scrollTo({ left: i * stepW() });
        setTimeout(update, 450); // на случай, если событие scroll не придёт
      }

      function buildDots() {
        var n = pages();
        if (dots.length === n) return;
        dotsBox.innerHTML = '';
        dots = [];
        for (var i = 0; i < n; i++) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'slider__dot';
          b.setAttribute('aria-label', 'Recensione ' + (i + 1) + ' di ' + n);
          b.addEventListener('click', (function (k) { return function () { go(k); }; })(i));
          dotsBox.appendChild(b);
          dots.push(b);
        }
        // Много отзывов — точки заменяет счётчик
        dotsBox.hidden = n > 8;
        count.hidden = n <= 8;
      }

      function update() {
        buildDots();
        var n = pages(), i = current();
        nav.hidden = n <= 1;
        dots.forEach(function (d, k) { d.setAttribute('aria-current', k === i ? 'true' : 'false'); });
        count.textContent = (i + 1) + ' / ' + n;
      }

      prev.addEventListener('click', function () { go(current() - 1); });
      next.addEventListener('click', function () { go(current() + 1); });
      track.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowLeft') { e.preventDefault(); go(current() - 1); }
        if (e.key === 'ArrowRight') { e.preventDefault(); go(current() + 1); }
      });
      var tmr = 0;
      track.addEventListener('scroll', function () {
        clearTimeout(tmr); tmr = setTimeout(update, 60);
      }, { passive: true });
      window.addEventListener('resize', update);
      update();
    });
  }

  /* ---------- Видео без звука: играет только на экране ---------- */
  function initVideos() {
    if (!('IntersectionObserver' in window)) return;
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    $$('video[data-autoplay-visible]').forEach(function (v) {
      v.muted = true;
      new IntersectionObserver(function (en) {
        if (en[0].isIntersecting) { var p = v.play(); if (p && p.catch) p.catch(function () {}); }
        else v.pause();
      }, { threshold: 0.4 }).observe(v);
    });
  }

  /* ---------- FAQ ---------- */
  function initFaq() {
    var btn = $('[data-faq-toggle]');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var open = btn.getAttribute('aria-pressed') !== 'true';
      $$('.faq details').forEach(function (d) { d.open = open; });
      btn.setAttribute('aria-pressed', String(open));
      btn.textContent = open ? 'Nascondi le risposte' : 'Mostra tutte le risposte';
    });
  }

  /* ---------- CTA и липкая кнопка ---------- */
  function initCta() {
    $$('[data-cta]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        track('cta_click', { position: a.getAttribute('data-cta') });
        var target = $('#richiesta');
        if (!target) return;
        e.preventDefault();
        target.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
        history.replaceState(null, '', '#richiesta');
        setTimeout(function () {
          var f = $('#f-name');
          if (f && !$('#form-card').hidden) f.focus({ preventScroll: true });
        }, 500);
      });
    });

    var sticky = $('#sticky-cta');
    if (!sticky || !('IntersectionObserver' in window)) return;
    var heroVisible = true, formVisible = false;
    function update() { sticky.hidden = heroVisible || formVisible || done; }
    new IntersectionObserver(function (en) { heroVisible = en[0].isIntersecting; update(); })
      .observe($('.hero .btn--cta'));
    new IntersectionObserver(function (en) { formVisible = en[0].isIntersecting; update(); }, { rootMargin: '0px 0px -20% 0px' })
      .observe($('#richiesta'));
    updateSticky = update;
  }
  var done = false;
  var updateSticky = function () {};

  /* ---------- Имя и телефон: правила как в it2 ---------- */
  // Имя: 2–60 символов после обрезки и схлопывания пробелов.
  var NAME_MIN = 2, NAME_MAX = 60;
  function cleanName(v) { return String(v || '').trim().replace(/\s+/g, ' '); }
  function validName(v) { var l = cleanName(v).length; return l >= NAME_MIN && l <= NAME_MAX; }

  // Телефон: префикс +39 фиксирован, вводится национальный номер.
  //  • проверяется только длина: 10–11 цифр;
  //  • 0 у стационарных (+39 06…) остаётся, лишний 0 перед мобильным (0 + 3xx…, 11 цифр) убирается;
  //  • если вставили номер с +39 / 0039 / 39 — префикс отрезается.
  var IT_MIN = 10, IT_MAX = 11, IT_VALID = /^\d{10,11}$/;
  function cleanPhone(v) {
    var raw = String(v || '').trim();
    var d = raw.replace(/\D/g, '');
    if (raw.charAt(0) === '+') { if (d.indexOf('39') === 0) d = d.slice(2); }
    else if (d.indexOf('0039') === 0) d = d.slice(4);
    else if (d.indexOf('39') === 0 && d.length > 10 && /^[03]/.test(d.slice(2))) d = d.slice(2);
    if (/^03\d{9}$/.test(d)) d = d.slice(1);
    return d.slice(0, IT_MAX);
  }
  function normPhone(v) { var d = cleanPhone(v); return IT_VALID.test(d) ? d : null; }
  function prettyPhone(d) {
    if (/^3\d{9}$/.test(d)) return d.replace(/^(\d{3})(\d{3})(\d{4})$/, '$1 $2 $3');
    if (/^3\d{8}$/.test(d)) return d.replace(/^(\d{3})(\d{3})(\d{3})$/, '$1 $2 $3');
    if (/^0[26]\d+$/.test(d)) return d.replace(/^(\d{2})(\d{1,4})(\d*)$/, function (m, a, b, c) { return [a, b, c].filter(Boolean).join(' '); });
    return d.replace(/^(\d{3})(\d{1,3})(\d*)$/, function (m, a, b, c) { return [a, b, c].filter(Boolean).join(' '); });
  }

  /* ---------- Формы заявки (на первом экране и внизу страницы) ---------- */
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  function sendLead(payload) {
    if (isDemo) {
      return new Promise(function (res, rej) {
        setTimeout(function () {
          if (/^errore$/i.test(payload.name)) rej(new Error('demo'));
          else res({ ok: true, lead_id: 'demo-' + payload.request_id.slice(0, 8) });
        }, 900);
      });
    }
    var ctrl = window.AbortController ? new AbortController() : null;
    var t = ctrl && setTimeout(function () { ctrl.abort(); }, (cfg.form && cfg.form.timeoutMs) || 15000);
    return fetch(cfg.form.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload),
      signal: ctrl ? ctrl.signal : undefined,
      credentials: 'same-origin'
    }).then(function (r) {
      if (t) clearTimeout(t);
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok || d.ok !== true || !d.lead_id) throw new Error(d.error || ('http_' + r.status));
        return d;
      });
    }, function (e) { if (t) clearTimeout(t); throw e; });
  }

  var formStarted = false, leadSent = false;

  function initForm(form, params) {
    var q = function (s) { return form.querySelector(s); };
    var kind = form.getAttribute('data-lead-form');
    var name = q('[name="name"]'), phone = q('[name="phone"]'), consent = q('[name="consent"]');
    var btn = q('[type="submit"]'), formErr = q('[data-form-error]');
    var err = { name: q('[data-err="name"]'), phone: q('[data-err="phone"]'), consent: q('[data-err="consent"]') };
    var requireConsent = !(cfg.form && cfg.form.requireConsent === false);
    if (!requireConsent) { var cf = q('[data-consent-field]'); if (cf) cf.remove(); consent = null; }
    var busy = false, pending = null;

    form.addEventListener('input', function () {
      if (!formStarted) { formStarted = true; track('form_start', { form: kind }); }
    });

    function setErr(input, errEl, show) {
      if (!input || !errEl) return;
      errEl.hidden = !show;
      input.setAttribute('aria-invalid', show ? 'true' : 'false');
      var fld = input.closest('.field');
      if (fld) { fld.classList.toggle('is-invalid', show); if (show) fld.classList.remove('is-valid'); }
    }
    var phoneField = phone.closest('.field');

    name.addEventListener('input', function () { setErr(name, err.name, false); });
    name.addEventListener('blur', function () { if (name.value) setErr(name, err.name, !validName(name.value)); });

    phone.addEventListener('input', function () {
      // Человек набирает префикс вручную («+», «+3», «+39», «0», «00», «003») — ждём следующую цифру
      if (/^(\+|\+3|\+39|0|00|003)$/.test(phone.value.replace(/\s/g, ''))) {
        phoneField.classList.remove('is-valid'); setErr(phone, err.phone, false); return;
      }
      var d = cleanPhone(phone.value);
      if (phone.value.replace(/\s/g, '') !== d) phone.value = d;
      var ok = !!normPhone(d);
      phoneField.classList.toggle('is-valid', ok);
      setErr(phone, err.phone, !ok && d.length >= IT_MAX);
    });
    phone.addEventListener('blur', function () {
      var d = cleanPhone(phone.value);
      if (d) phone.value = prettyPhone(d);
      var ok = !!normPhone(d);
      phoneField.classList.toggle('is-valid', ok);
      setErr(phone, err.phone, !!d && !ok);
    });
    if (consent) consent.addEventListener('change', function () { if (consent.checked) setErr(consent, err.consent, false); });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy || leadSent) return; // защита от двойной отправки (в том числе из второй формы)
      formErr.hidden = true;

      var n = cleanName(name.value);
      var digits = normPhone(phone.value);
      var p = digits ? '+39' + digits : null;   // отправляем в формате +39…, как it2
      var badName = !validName(n);
      var badPhone = !p;
      var badConsent = !!consent && !consent.checked;
      setErr(name, err.name, badName);
      setErr(phone, err.phone, badPhone);
      if (consent) setErr(consent, err.consent, badConsent);
      if (badName || badPhone || badConsent) {
        (badName ? name : badPhone ? phone : consent).focus();
        return;
      }

      // Один и тот же request_id для повторов с теми же данными → сервер не создаст дубль
      var key = n + '|' + p;
      if (!pending || pending.key !== key) pending = { key: key, id: uuid() };

      var website = q('[name="website"]');
      var payload = {
        request_id: pending.id,
        name: n,
        phone: p,
        consent: consent ? consent.checked : null,
        privacy_version: cfg.form && cfg.form.privacyVersion,
        offer_id: cfg.offerId,
        landing_version: cfg.landingVersion,
        offer_expires_at: personalEnd ? personalEnd.toISOString() : null,
        form: kind,
        params: params,
        website: website ? website.value : ''
      };

      busy = true;
      btn.setAttribute('aria-busy', 'true');
      btn.disabled = true;
      track('form_submit', { form: kind });

      sendLead(payload).then(function (d) {
        leadSent = true;
        track('lead_accepted', { lead_id: d.lead_id, duplicate: !!d.duplicate, form: kind });
        document.dispatchEvent(new CustomEvent('lp:lead-ok', { detail: { form: kind } }));
        var shown = '+39 ' + prettyPhone(digits);
        if (kind === 'main') {
          $('#success-phone').textContent = shown;
          $('#form-card').hidden = true;
          var s = $('#success');
          s.hidden = false;
          s.focus();
        } else {
          var okMsg = q('[data-form-ok]');
          if (okMsg) okMsg.hidden = false;
        }
        done = true;
        updateSticky();
        // Переход на страницу благодарности: там пиксель Meta отправляет Lead (только для принятых заявок).
        // Небольшая пауза — чтобы аналитика успела записать «заявка принята».
        try { sessionStorage.setItem('lp_last_phone', shown); } catch (e2) {}
        if (!isDemo && cfg.thanksPage !== false) {
          setTimeout(function () {
            location.href = 'grazie.html?lid=' + encodeURIComponent(d.lead_id) + (location.search.indexOf('notrack=1') > -1 ? '&notrack=1' : '');
          }, 400);
        }
      }).catch(function (err2) {
        track('lead_error', { reason: String(err2 && err2.message || err2).slice(0, 40), form: kind });
        formErr.textContent = 'Non è stato possibile inviare la richiesta. Riprova.';
        formErr.hidden = false;
        formErr.scrollIntoView({ block: 'center' });
      }).then(function () {
        busy = false;
        btn.removeAttribute('aria-busy');
        btn.disabled = leadSent;
      });
    });
  }

  function initForms(params) {
    $$('form[data-lead-form]').forEach(function (f) { initForm(f, params); });
  }

  /* ---------- Старт ---------- */
  if (isDemo) $('#demo-banner').hidden = false;
  applyTexts();
  applyClaims();
  initOffer();
  initReviews().then(initSlider);
  initVideos();
  initFaq();
  initCta();
  initForms(captureParams());
  initCookies();
  track('page_view', { landing_version: cfg.landingVersion });
})();
