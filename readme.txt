=== Convoca Gateway ===
Contributors: josecarlosnietoramos
Tags: payments, redsys, TPV, donations, fees, associations
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.6.2
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
* Integration with Convoca Members (automatic membership updates)

= External Services =

This plugin connects to the Redsys payment gateway to process transactions. Payment data is sent to Redsys servers following industry security standards. It may also contact getconvoca.app to validate PRO licenses.

== Installation ==

1. Make sure Convoca Core is active
2. Upload the `convoca-gateway` folder to `/wp-content/plugins/`
3. Activate the plugin from the Plugins menu
4. Configure your Redsys account in Settings > Convoca Gateway

== Changelog ==

= 2.6.2 =
* Improvement: 57 unit tests, 124 assertions
* New: HMAC signature, amounts and notifications tests
