(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var copyButton = event.target.closest('.htp-copy-button');
        if (copyButton) {
            var input = copyButton.closest('td, .htp-panel').querySelector('.htp-copy-source');
            if (input) {
                navigator.clipboard.writeText(input.value).then(function () {
                    var oldText = copyButton.textContent;
                    copyButton.textContent = HTPAdmin.copied;
                    setTimeout(function () { copyButton.textContent = oldText; }, 1400);
                }).catch(function () {
                    input.select();
                    document.execCommand('copy');
                });
            }
        }

        var confirmLink = event.target.closest('.htp-confirm');
        if (confirmLink && !window.confirm(HTPAdmin.confirm)) {
            event.preventDefault();
        }
    });
})();
