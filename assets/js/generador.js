/**
 * Biodevas Gateway — Generador JS
 */
(function (bdvAdmin) {
    'use strict';

    if (!bdvAdmin) return;

    // Helper to copy the generated link
    window.bdg_copy_link = function () {
        const copyText = document.getElementById('bdg_generated_link');
        if (!copyText) return;

        bdvAdmin.copyToClipboard(copyText.value, () => {
            alert('Enlace copiado al portapapeles');
        });
    };

})(window.convocaAdmin);
