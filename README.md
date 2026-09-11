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
- Generador de enlaces de pago (con caducidad automática)
- Export PDF de pagos
- Recibos y justificantes con numeración anual
- Método de pago por defecto configurable en Ajustes
- Firma Redsys HMAC_SHA512_V2
- Dashboard con estadísticas agregadas por SQL
- REST API para notificaciones
- Logging centralizado
- Diagnóstico con reparación automática
- CSV export de pagos (columna: ID, ID Pedido, Importe, Método, Estado, Origen, Email, Fechas)
- Filtros de exportación por estado, método, origen
- Protección contra inyección CSV (prefija caracteres peligrosos con comilla)


## 📖 Documentación

La documentación completa (manual de usuario, API REST, hooks, instalación) vive en la wiki:

👉 **[Convoca gateway](https://docs.getconvoca.app/plugins/convoca-gateway/)**

## Dependencies

convoca-core, WordPress 6.4+, PHP 8.1+, Cuenta Redsys activa

## Version

2.13.0

## Changelog

El historial completo de versiones está en [CHANGELOG.md](CHANGELOG.md).

## Hooks

| Hook | Tipo | Descripción |
|------|------|-------------|
| `convoca_gateway_payment_completed` | action | Pago completado (con fallback `convoca_payment_completed`) |
| `convoca_gateway_payment_failed` | action | Pago fallido (con fallback `convoca_payment_failed`) |
| `convoca_gateway_payment_refunded` | action | Pago reembolsado |
| `convoca_gateway_resend_email` | action | Reenvío del email de pago |
| `convoca_gateway_expiry_notice` | action | Aviso de caducidad de enlaces (cron) |
| `convoca_gateway_bank_entity` | filter | Texto de la entidad bancaria |
| `convoca_gateway_redsys_allowed_ips` | filter | IPs permitidas para notificaciones Redsys |

### API pública

- `convoca_gateway_create_payment( array $args )` — crea un pago y devuelve la URL.
- `convoca_get_gateway_settings()` — devuelve la configuración de la pasarela.

### REST API

- `POST /wp-json/convoca-gateway/v1/notify` — notificación de Redsys (rate-limit por IP y límite de payload).

## 🧪 Demo

Prueba Convoca sin instalar nada:

👉 **[demo.getconvoca.app](https://demo.getconvoca.app)**

## 📸 Capturas

| Socios | Actividades | Turnos | Inscripciones |
|--------|-------------|--------|---------------|
| ![Socios](https://getconvoca.app/wp-content/uploads/2026/06/convoca-miembros-v4.png) | ![Actividades](https://getconvoca.app/wp-content/uploads/2026/06/convoca-actividades-v4.png) | ![Turnos](https://getconvoca.app/wp-content/uploads/2026/06/convoca-turnos-v4.png) | ![Inscripciones](https://getconvoca.app/wp-content/uploads/2026/06/convoca-inscripciones-v4.png) |

## 🔗 Ecosistema

- [Convoca Core](https://github.com/josecarlosnieto91/convoca-core)
- [Convoca Members](https://github.com/josecarlosnieto91/convoca-members)
- [Convoca Enroll](https://github.com/josecarlosnieto91/convoca-enroll)
- [Convoca Gateway](https://github.com/josecarlosnieto91/convoca-gateway)
- [Convoca Shifts](https://github.com/josecarlosnieto91/convoca-shifts)
- [Convoca Publisher](https://github.com/josecarlosnieto91/convoca-publisher)

