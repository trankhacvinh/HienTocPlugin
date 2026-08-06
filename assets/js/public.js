(function () {
  'use strict';

  document.querySelectorAll('[data-htp-tabs]').forEach(function (root) {
    var tabs = root.querySelectorAll('[data-htp-tab]');
    var panels = root.querySelectorAll('[data-htp-panel]');

    function activate(key, focus) {
      tabs.forEach(function (tab) {
        var active = tab.getAttribute('data-htp-tab') === key;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.setAttribute('tabindex', active ? '0' : '-1');
        if (active && focus) tab.focus();
      });
      panels.forEach(function (panel) {
        panel.classList.toggle('is-active', panel.getAttribute('data-htp-panel') === key);
      });
      try {
        var url = new URL(window.location.href);
        url.searchParams.set('form', key);
        window.history.replaceState({}, '', url.toString());
      } catch (e) {}
    }

    tabs.forEach(function (tab, index) {
      tab.addEventListener('click', function () {
        activate(tab.getAttribute('data-htp-tab'), false);
      });
      tab.addEventListener('keydown', function (event) {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        var next = event.key === 'ArrowRight' ? index + 1 : index - 1;
        if (next < 0) next = tabs.length - 1;
        if (next >= tabs.length) next = 0;
        activate(tabs[next].getAttribute('data-htp-tab'), true);
      });
    });
  });

  document.querySelectorAll('[data-htp-public-form]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var button = form.querySelector('.htp-submit');
      if (!button || button.disabled) return;
      button.disabled = true;
      button.classList.add('is-loading');
      var span = button.querySelector('span');
      if (span) span.textContent = (window.HTPPublic && HTPPublic.submitting) || 'Đang gửi...';
    });
  });

  document.querySelectorAll('[data-htp-image-input]').forEach(function (input) {
    input.addEventListener('change', function () {
      var preview = input.parentElement.querySelector('[data-htp-image-preview]');
      if (!preview) return;
      preview.innerHTML = '';
      Array.from(input.files || []).slice(0, 10).forEach(function (file) {
        if (!file.type || file.type.indexOf('image/') !== 0) return;
        var url = URL.createObjectURL(file);
        var item = document.createElement('div');
        item.className = 'htp-image-preview__item';
        var img = document.createElement('img');
        img.src = url;
        img.alt = file.name;
        img.onload = function () { URL.revokeObjectURL(url); };
        item.appendChild(img);
        preview.appendChild(item);
      });
    });
  });
})();
