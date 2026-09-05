=== Convoca Gateway ===
Contributors: josecarlosnietoramos
Tags: payments, redsys, donations, fees, tpv
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.6.6
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Redsys payment gateway for membership fees, donations and registrations.

== Description ==

Process payments through Redsys (Sermepa), the most widely used virtual POS in Spain. Allows creating manual payments, generating unique payment links, and receiving automatic notifications.

* Integration with Redsys/Sermepa (SHA-256)
* Unique payment links for sharing
* Payment panel with filters and CSV export
* Automatic notifications with HMAC validation
* `[convoca_pago]` shortcode for public form
* `[convoca_pago_ok]` shortcode for the payment success page
* `[convoca_pago_ko]` shortcode for the payment failure page
* Integration with Convoca Members (automatic membership updates)

= External Services =

This plugin connects to the Redsys payment gateway to process transactions. Payment data is sent to Redsys servers following industry security standards. It may also contact getconvoca.app to validate PRO licenses.

== Installation ==

1. Make sure Convoca Core is active
2. Upload the `convoca-gateway` folder to `/wp-content/plugins/`
3. Activate the plugin from the Plugins menu
4. Configure your Redsys account in Settings > Convoca Gateway

== Changelog ==

= 2.6.6 =
* Fix: la notificación con firma Ds_Signature inválida ahora se registra en el log (antes retornaba error sin rastro) — el Security Monitor puede alertar de intentos de firma falsificada.
* Observabilidad: contexto Gateway/Notification + Ds_Order decodificado del payload para trazabilidad.

= 2.6.5 =
* Security: la versión de firma Redsys ya no la decide el input del atacante — solo se acepta la configurada por el comercio.
* Security: la notificación valida importe (Ds_Amount), moneda (978) y merchant code contra el pago esperado antes de marcar pagado.
* Security: is_approved exige Ds_Response === '0000' (autorización de compra), no todo el rango 0-99.
* Security: eliminado bypass de IP por WP_DEBUG en el webhook; loopback solo en entornos no productivos.
* Security: rate limit (30/min/IP) y límite de payload en /notify (anti-DoS).
* Fix: decodificación base64url (strtr) de Ds_MerchantParameters — notificaciones legítimas con '-'/'_' ya no se rechazan.

= 2.6.4 =
* Fix: capacidades del listado de pagos — usa convoca_view_payments del core (antes exigía convoca_gateway_view_payments no registrada → 403 en el admin).

= 2.6.3 =
* Fix: build_merchant_params defaults (is_bizum, amount_cents, url_notify)
* Improvement: Redsys TEST credentials verified end-to-end

= 2.6.2 =
* Improvement: 57 unit tests, 124 assertions
* New: HMAC signature, amounts and notifications tests

== Screenshots ==

1. Payment panel with filters
2. Create manual payment
3. Public payment form (shortcode)
4. Payment detail with notification log

== Frequently Asked Questions ==

= Does it require Convoca Core? =

Yes. Convoca Gateway requires Convoca Core to be active.

= Which payment gateway does it support? =

Redsys/Sermepa, the most widely used virtual POS in Spain, with SHA-256 signature validation.

= Can I generate payment links? =

Yes. You can create unique payment links for sharing with members.

== Upgrade Notice ==

= 2.6.2 =
* Compatibility and security improvements. Recommended update.
