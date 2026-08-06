(function () {
  'use strict';

  document.addEventListener('click', function (event) {
    var copyButton = event.target.closest('.htp-copy-button');
    if (copyButton) {
      var source = copyButton.closest('td, .htp-panel').querySelector('.htp-copy-source');
      if (source) {
        navigator.clipboard.writeText(source.value).then(function () {
          copyButton.textContent = (window.HTPAdmin && HTPAdmin.copied) || 'Đã sao chép';
          setTimeout(function () { copyButton.textContent = 'Sao chép'; }, 1500);
        });
      }
    }

    var confirmLink = event.target.closest('.htp-confirm');
    if (confirmLink && !window.confirm((window.HTPAdmin && HTPAdmin.confirm) || 'Bạn có chắc?')) {
      event.preventDefault();
    }
  });

  document.querySelectorAll('[data-htp-sortable]').forEach(function (list) {
    var dragging = null;
    list.querySelectorAll('.htp-field-row').forEach(function (row) {
      row.addEventListener('dragstart', function () {
        dragging = row;
        row.classList.add('is-dragging');
      });
      row.addEventListener('dragend', function () {
        row.classList.remove('is-dragging');
        dragging = null;
      });
      row.addEventListener('dragover', function (event) {
        event.preventDefault();
        if (!dragging || dragging === row) return;
        var rect = row.getBoundingClientRect();
        var after = event.clientY > rect.top + rect.height / 2;
        list.insertBefore(dragging, after ? row.nextSibling : row);
      });
    });
  });
})();
