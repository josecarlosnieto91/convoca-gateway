( function( blocks, element ) {
    var el = element.createElement;

    function registerGatewayBlock( name, title, icon, desc, color ) {
        blocks.registerBlockType( 'biodevas-gateway/' + name, {
            apiVersion: 3,
            title: title,
            icon: icon,
            category: 'biodevas-gateway',
            edit: function() {
                return el( 'div', {
                    style: {
                        padding: '20px',
                        background: color,
                        border: '2px dashed #6366f1',
                        borderRadius: '8px',
                        textAlign: 'center'
                    }
                },
                    el( 'span', { className: 'dashicons dashicons-' + icon, style: { fontSize: '36px', color: '#6366f1' } } ),
                    el( 'p', { style: { fontWeight: 600, marginTop: '10px' } }, title ),
                    el( 'p', { style: { fontSize: '12px', color: '#6b7280' } }, desc )
                );
            },
            save: function() { return null; }
        } );
    }

    registerGatewayBlock(
        'pagina-pago',
        'Página de Pago',
        'money-alt',
        'Procesamiento de pago con selección de método (tarjeta/bizum/transferencia).',
        '#eef2ff'
    );
    registerGatewayBlock(
        'pago-ok',
        'Pago Correcto',
        'yes-alt',
        'Página de confirmación tras un pago exitoso.',
        '#f0fdf4'
    );
    registerGatewayBlock(
        'pago-ko',
        'Pago Fallido',
        'dismiss',
        'Página de error cuando el pago no se completa.',
        '#fef2f2'
    );
} )( window.wp.blocks, window.wp.element );
