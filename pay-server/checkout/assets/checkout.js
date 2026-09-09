(function () {
  'use strict';

  // Section 10.A: "detecting device type to decide 'show QR' versus 'show
  // tap-to-pay button' on the same page". A coarse pointer (touch, no
  // hover) is treated as mobile; this also covers tablets, which is fine
  // for this page's purpose (both a scan and a tap work equally well on a
  // device with its own wallet installed).
  var isMobile = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  if (isMobile) {
    document.body.classList.add('is-mobile');
  }

  function renderQrCode(text) {
    if (isMobile || typeof qrcode === 'undefined') {
      return;
    }
    var qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    document.getElementById('qrcode').innerHTML = qr.createSvgTag({ scalable: true, margin: 2 });
  }

  function setupCopyButtons() {
    var buttons = document.querySelectorAll('.copy-btn');
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var targetId = btn.getAttribute('data-copy-target');
        var input = document.getElementById(targetId);
        input.select();
        input.setSelectionRange(0, 99999);
        var restore = btn.textContent;
        var done = function () {
          btn.textContent = 'Copied';
          btn.classList.add('copied');
          setTimeout(function () {
            btn.textContent = restore;
            btn.classList.remove('copied');
          }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(input.value).then(done, function () {
            document.execCommand('copy');
            done();
          });
        } else {
          document.execCommand('copy');
          done();
        }
      });
    });
  }

  function startCountdown(expiresAtIso) {
    var el = document.getElementById('countdown');
    var expiresAt = new Date(expiresAtIso).getTime();

    function tick() {
      var status = document.documentElement.getAttribute('data-status');
      if (status !== 'new' && status !== 'processing') {
        el.textContent = '';
        return;
      }
      var remainingMs = expiresAt - Date.now();
      if (remainingMs <= 0) {
        el.textContent = 'This payment window has expired.';
        el.classList.add('is-urgent');
        return;
      }
      var totalSeconds = Math.floor(remainingMs / 1000);
      var minutes = Math.floor(totalSeconds / 60);
      var seconds = totalSeconds % 60;
      el.textContent = 'Expires in ' + minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
      el.classList.toggle('is-urgent', remainingMs < 60000);
      setTimeout(tick, 1000);
    }
    tick();
  }

  var STATUS_LABELS = {
    new: 'Waiting for payment',
    processing: 'Payment detected, confirming',
    settled: 'Paid',
    expired: 'Expired',
    invalid: 'Payment problem',
  };

  // Section 17: "success_url and cancel_url let the merchant's own site
  // regain control of the buyer's browser once an order finishes or is
  // abandoned." A short delay so the buyer actually sees the final
  // status (e.g. "Paid") before being sent away, rather than an
  // instant, jarring redirect.
  var REDIRECT_DELAY_MS = 4000;

  function redirectAfterDelay(url) {
    if (!url) {
      return;
    }
    setTimeout(function () {
      window.location.href = url;
    }, REDIRECT_DELAY_MS);
  }

  function applyStatus(status) {
    document.documentElement.setAttribute('data-status', status);
    var badge = document.getElementById('status-badge');
    badge.setAttribute('data-status', status);
    badge.textContent = STATUS_LABELS[status] || status;

    var payArea = document.getElementById('pay-area');
    if (status !== 'new' && status !== 'processing') {
      payArea.style.display = 'none';
      var countdownEl = document.getElementById('countdown');
      if (countdownEl) {
        countdownEl.textContent = '';
      }

      if (status === 'settled') {
        redirectAfterDelay(window.SUCCESS_URL);
      } else if (status === 'expired' || status === 'invalid') {
        redirectAfterDelay(window.CANCEL_URL);
      }
    }
  }

  function startLiveStatus(orderId) {
    var statusUrl = '/v1/orders/' + encodeURIComponent(orderId) + '/public';

    function poll() {
      fetch(statusUrl, { headers: { Accept: 'application/json' } })
        .then(function (r) {
          return r.ok ? r.json() : null;
        })
        .then(function (data) {
          if (data && data.status) {
            applyStatus(data.status);
          }
        })
        .catch(function () {
          /* network hiccup: next poll/SSE event will catch up */
        });
    }

    // Section 10.C: SSE is the primary mechanism, plain polling is the
    // fallback for any client or network that blocks it.
    if (typeof EventSource === 'undefined') {
      setInterval(poll, 5000);
      return;
    }

    var source = new EventSource('/v1/orders/' + encodeURIComponent(orderId) + '/events');
    var pollTimer = null;

    source.addEventListener('status', function (event) {
      try {
        var data = JSON.parse(event.data);
        if (data && data.status) {
          applyStatus(data.status);
        }
      } catch (e) {
        /* ignore malformed event */
      }
    });

    source.onerror = function () {
      source.close();
      if (!pollTimer) {
        pollTimer = setInterval(poll, 5000);
      }
    };
  }

  document.addEventListener('DOMContentLoaded', function () {
    renderQrCode(window.PAYMENT_URI);
    setupCopyButtons();
    var countdownEl = document.getElementById('countdown');
    if (countdownEl) {
      startCountdown(countdownEl.getAttribute('data-expires-at'));
    }
    if (window.ORDER_STATUS === 'new' || window.ORDER_STATUS === 'processing') {
      startLiveStatus(window.ORDER_ID);
    } else {
      // Landed directly on an already-terminal order (e.g. reopening an
      // old link after paying) -- still honor the redirect rather than
      // only doing so on a live transition.
      applyStatus(window.ORDER_STATUS);
    }
  });
})();
