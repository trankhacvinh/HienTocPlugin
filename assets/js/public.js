(function () {
  'use strict';

  var CORE_REQUIRED_FIELDS = ['full_name', 'phone', 'consent'];

  function fieldKeyFromName(name) {
    return String(name || '').replace(/\[\]$/, '');
  }

  function normalizeRequiredFields(form) {
    form.querySelectorAll('input, select, textarea').forEach(function (control) {
      var key = fieldKeyFromName(control.getAttribute('name'));
      if (!key) return;

      if (CORE_REQUIRED_FIELDS.indexOf(key) !== -1) {
        control.required = true;
      } else {
        control.required = false;
      }
    });

    form.querySelectorAll('.htp-field, .htp-consent, .htp-choice-group').forEach(function (wrapper) {
      var control = wrapper.querySelector('input[name], select[name], textarea[name]');
      if (!control) return;
      var key = fieldKeyFromName(control.getAttribute('name'));
      if (CORE_REQUIRED_FIELDS.indexOf(key) === -1) {
        wrapper.querySelectorAll('b').forEach(function (mark) {
          mark.remove();
        });
      }
    });
  }

  function ensureSubmitMarker(form) {
    if (form.querySelector('input[type="hidden"][name="htp_form_submit"]')) return;
    var marker = document.createElement('input');
    marker.type = 'hidden';
    marker.name = 'htp_form_submit';
    marker.value = '1';
    form.appendChild(marker);
  }

  function validDateParts(day, month, year) {
    var date = new Date(year, month - 1, day);
    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
  }

  function isoToVietnamese(value) {
    var match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return String(value || '');
    var year = parseInt(match[1], 10);
    var month = parseInt(match[2], 10);
    var day = parseInt(match[3], 10);
    if (!validDateParts(day, month, year)) return '';
    return match[3] + '/' + match[2] + '/' + match[1];
  }

  function vietnameseToIso(value) {
    var match = String(value || '').trim().match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (!match) return '';
    var day = parseInt(match[1], 10);
    var month = parseInt(match[2], 10);
    var year = parseInt(match[3], 10);
    if (year < 1900 || year > 2100 || !validDateParts(day, month, year)) return '';
    return match[3] + '-' + match[2] + '-' + match[1];
  }

  function maskVietnameseDate(value) {
    var digits = String(value || '').replace(/\D/g, '').slice(0, 8);
    if (digits.length <= 2) return digits;
    if (digits.length <= 4) return digits.slice(0, 2) + '/' + digits.slice(2);
    return digits.slice(0, 2) + '/' + digits.slice(2, 4) + '/' + digits.slice(4);
  }

  function initVietnameseDateField(nativeInput) {
    if (!nativeInput || nativeInput.dataset.htpDateReady === '1') return;
    nativeInput.dataset.htpDateReady = '1';

    var fieldName = nativeInput.getAttribute('name') || '';
    var required = nativeInput.required;
    var initialValue = nativeInput.value || '';
    var wrapper = document.createElement('div');
    wrapper.className = 'htp-date-control';

    var display = document.createElement('input');
    display.type = 'text';
    display.name = fieldName;
    display.value = isoToVietnamese(initialValue);
    display.placeholder = 'dd/mm/yyyy';
    display.inputMode = 'numeric';
    display.autocomplete = 'bday';
    display.maxLength = 10;
    display.required = required;
    display.setAttribute('aria-label', 'Ngày sinh, định dạng ngày/tháng/năm');
    display.setAttribute('data-htp-date-display', '1');

    var icon = document.createElement('span');
    icon.className = 'htp-date-calendar-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg>';

    nativeInput.removeAttribute('name');
    nativeInput.required = false;
    nativeInput.tabIndex = -1;
    nativeInput.classList.add('htp-native-date-picker');
    nativeInput.setAttribute('aria-label', 'Mở lịch chọn ngày sinh');

    nativeInput.parentNode.insertBefore(wrapper, nativeInput);
    wrapper.appendChild(display);
    wrapper.appendChild(icon);
    wrapper.appendChild(nativeInput);

    display.addEventListener('input', function () {
      var caretAtEnd = display.selectionStart === display.value.length;
      display.value = maskVietnameseDate(display.value);
      if (caretAtEnd) display.setSelectionRange(display.value.length, display.value.length);
      display.setCustomValidity('');
      var iso = vietnameseToIso(display.value);
      nativeInput.value = iso || '';
    });

    display.addEventListener('blur', function () {
      if (display.value && !vietnameseToIso(display.value)) {
        display.setCustomValidity('Vui lòng nhập ngày theo định dạng dd/mm/yyyy.');
        display.setAttribute('aria-invalid', 'true');
      } else {
        display.setCustomValidity('');
        display.removeAttribute('aria-invalid');
      }
    });

    nativeInput.addEventListener('change', function () {
      display.value = isoToVietnamese(nativeInput.value);
      display.setCustomValidity('');
      display.removeAttribute('aria-invalid');
    });
  }

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
    normalizeRequiredFields(form);
    ensureSubmitMarker(form);
  });

  document.querySelectorAll('[data-htp-public-form] input[type="date"]').forEach(initVietnameseDateField);

  document.querySelectorAll('[data-htp-public-form]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      var dateInvalid = false;
      form.querySelectorAll('[data-htp-date-display]').forEach(function (display) {
        var value = display.value.trim();
        if (!value) {
          if (display.required) {
            display.setCustomValidity('Vui lòng nhập ngày sinh.');
            dateInvalid = true;
          }
          return;
        }
        var iso = vietnameseToIso(value);
        if (!iso) {
          display.setCustomValidity('Vui lòng nhập ngày theo định dạng dd/mm/yyyy.');
          display.setAttribute('aria-invalid', 'true');
          dateInvalid = true;
          return;
        }
        display.setCustomValidity('');
        display.removeAttribute('aria-invalid');
        display.value = iso;
      });

      if (dateInvalid) {
        event.preventDefault();
        var firstInvalid = form.querySelector('[data-htp-date-display]:invalid');
        if (firstInvalid) firstInvalid.reportValidity();
        return;
      }

      ensureSubmitMarker(form);

      var button = form.querySelector('.htp-submit');
      if (!button || button.disabled) return;

      // Do not disable the submit button inside the submit event itself.
      // A disabled submit button is excluded from the browser POST payload,
      // which previously caused WordPress to reload the form without saving it.
      window.setTimeout(function () {
        button.disabled = true;
        button.classList.add('is-loading');
        var span = button.querySelector('span');
        if (span) span.textContent = (window.HTPPublic && HTPPublic.submitting) || 'Đang gửi...';
      }, 0);
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
