=== Convoca Gateway — Payment Gateway ===
Contributors: josecarlosnietoramos
Tags: payment, redsys, gateway, bizum, card, transfer
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.6.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pasarela de pago Redsys para el ecosistema Convoca. Soporta tarjeta, Bizum y transferencia bancaria.

== Description ==

Plugin de pasarela de pago integrada con Redsys para el ecosistema Convoca:

* Redsys — Integración completa con tarjeta (Visa, Mastercard) y Bizum
* Soporte HMAC_SHA256_V1 y V2 — Compatible con la migración de Redsys
* Transferencia bancaria — Instrucciones automáticas con IBAN
* Subida de justificantes — Los usuarios pueden adjuntar PDFs o imágenes
* Panel de administración — Listado con filtros por método de pago
* Shortcodes — [convoca_pago], [convoca_pago_ok], [convoca_pago_ko]

== Installation ==

1. Asegúrate de que Convoca Core está activo
2. Sube la carpeta convoca-gateway a /wp-content/plugins/
3. Activa el plugin
4. Configura merchant code y clave secreta en Convoca > Gateway

== Changelog ==

= 2.6.1 =
* Nuevo: Soporte HMAC_SHA256_V2 (Redsys migración)

= 2.6.0 =
* Rendimiento: Dashboard y widget reescritos con SQL directo

= 2.5.0 =
* Nuevo: Subida de justificantes para transferencias bancarias

= 2.4.0 =
* Nuevo: Soporte para Transferencia Bancaria y gestión manual de pagos
* Nuevo: Sistema de Diagnóstico y Salud

= 2.0.0 =
* Primera versión refactorizada para Convoca

== Upgrade Notice ==

= 2.6.1 =
Actualización de seguridad: soporte HMAC_SHA256_V2. Se recomienda actualizar.
