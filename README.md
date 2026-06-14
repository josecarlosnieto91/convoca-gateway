# Convoca Gateway

Pasarela de pago Redsys (tarjeta + Bizum) para la Asociación Convoca.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- convoca-core plugin active
- Cuenta Redsys activa (TPV virtual)

## Main Features

- Pago con tarjeta
- Pago con Bizum
- Generador de enlaces de pago
- Dashboard con estadísticas agregadas por SQL
- REST API para notificaciones
- Logging centralizado
- CSV export de pagos (columna: ID, ID Pedido, Importe, Método, Estado, Origen, Email, Fechas)
- Filtros de exportación por estado, método, origen
- Protección contra inyección CSV (prefija caracteres peligrosos con comilla)

## Dependencies

convoca-core, WordPress 6.4+, PHP 8.1+, Cuenta Redsys activa

## Version

2.6.1

## Changelog

### 2.6.1
- **Nuevo:** Soporte HMAC_SHA256_V2 en verify_notification() + método sign_v2().

### 2.6.0
- Dashboard reescrito con $wpdb JOIN + GROUP BY (eliminado posts_per_page => -1)
- Widget de escritorio reescrito con agregación SQL directa
- Cache de dashboard reducido a 5 minutos

### 2.5.0
- Added payment link generator
- Added Bizum payment support
- Dashboard with monthly statistics
- Webhook events for payments
- Improved security with AES-256-CBC key encryption

### 2.3.0
- Added CPT pago with full meta
- Payment pages with shortcodes
- Diagnosis health panel
