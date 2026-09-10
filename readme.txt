=== Convoca Gateway ===
Contributors: josecarlosnietoramos
Tags: payments, redsys, donations, fees, tpv
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.9.0
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

= 2.9.0 =
* Un enlace de pago ya no se convierte en el cobro: cada vez que se usa emite un registro de pago propio, que aparece en «Todos los Pagos» con el origen «Cobro con enlace». Un enlace puede emitir uno o más cobros mientras no haya caducado ni se haya eliminado.
* El enlace no se gasta al usarse: mantiene su estado, su caducidad y su dirección. El listado de enlaces muestra cuántos cobros ha emitido.
* El cobro se emite al enviar el formulario del método de pago, no al abrir la página: una visita o un rastreador no deja registros sueltos.

= 2.8.1 =
* Los enlaces de pago ya no aparecen en el listado de pagos: son plantillas y su sitio es «Enlaces de Pago». Así no se confunden con un pago pendiente.
* Los cobros del formulario de pago dejan de figurar como enlaces: tienen su propio origen.
* Los orígenes se leen en castellano (inscripción, socio/a, donación, formulario web), el filtro permite ver solo las donaciones y un enlace de importe libre figura como «Importe libre» en vez de 0,00 €.

= 2.8.0 =
* Ya se pueden eliminar pagos, sea cual sea su estado, con una pantalla de confirmación que avisa de si está pagado, de si tiene recibo y de si procede de una inscripción.
* Los enlaces de pago generados ya se pueden editar (concepto, importe o importe libre, método, caducidad y email) y eliminar. La dirección del enlace no cambia al editarlo.
* El borrado también va en bloque desde los dos listados, con la misma confirmación.
* El borrado es definitivo y queda registrado en el diario del plugin; los pagos hechos desde un enlace no se borran al borrar el enlace.

= 2.7.4 =
* El aviso de seguridad habla en primera persona: «No almacenamos tus datos bancarios».
* El formulario de donativo ya no repite «Importe libre» ni la explicación de que cada aportación se recibe por separado: basta con el concepto.

= 2.7.3 =
* Corregido: al enviar un donativo el pago no se creaba (el formulario y el código que lo procesa se habían desacoplado). Se añade prueba de regresión.

= 2.7.2 =
* Corregido: en páginas con el editor clásico las tarjetas de método salían apiladas (WordPress insertaba un <br> entre ellas).

= 2.7.1 =
* El pago se pide en dos pasos: primero el método (tarjeta, Bizum o transferencia) y después el importe y el correo, tanto en el enlace de donativo como en la página de pago.
* Las tarjetas de método son más grandes: tarjeta y Bizum en paralelo y transferencia a lo ancho debajo, con el mismo diseño en todos los flujos.
* Se puede cambiar de método sin perder lo tecleado.
* Corregido: la página de un enlace de pago fallaba si el pago no tenía fecha de caducidad guardada.

= 2.7.0 =
* Nuevo: enlaces de donativo. Si se deja la cantidad vacía al generar el enlace, se crea un enlace de importe libre y reutilizable: quien aporta elige cuánto donar y cada aportación se registra como un pago propio (con su orden de Redsys y su recibo).
* Nuevo: el importe del donativo se introduce en la página pública del enlace (mínimo 0,50 €), junto al método de pago y un email opcional.
* Nuevo: recibo para quien paga. El email indicado durante el pago se guarda y se le envía la confirmación con el enlace a su recibo; en los donativos se envía siempre que haya email, sin depender del aviso general de confirmaciones.
* Fix: el campo «Email de notificación» del formulario de pago se pintaba pero no se usaba en ningún sitio; ahora viaja con el método elegido y permite enviar el recibo. En los pagos sin email de pagador, el recibo sigue dependiendo del ajuste general.
* Admin: los enlaces de donativo muestran «Importe libre» en el listado y el email de quien paga cuando no hay destinatario.

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
