/**
 * Convoca Gateway — Generador JS
 */
(function (convAdmin) {
    'use strict';

    if (!convAdmin) return;

    // Helper to copy the generated link
    window.conv_copy_link = function () {
        const copyText = document.getElementById('conv_gateway_generated_link');
        if (!copyText) return;

        convAdmin.copyToClipboard(copyText.value, () => {
            alert('Enlace copiado al portapapeles');
        });
    };

})(window.convocaAdmin);
