=== Convoca Gateway ===
Contributors: josecarlosnietoramos
Tags: payments, redsys, TPV, donations, fees, asociaciones
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.6.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pasarela de pago Redsys para cuotas de socio, donaciones e inscripciones.

== Description ==

Procesa pagos a través de Redsys (Sermepa), el TPV virtual más usado en España. Permite crear pagos manuales, generar links de pago únicos, y recibir notificaciones automáticas.

* Integración con Redsys/Sermepa (SHA-256)
* Links de pago únicos para compartir
* Panel de pagos con filtros y exportación CSV
* Notificaciones automáticas con validación HMAC
* Shortcode `[convoca_pago]` para formulario público
* Integración con Convoca Members (actualización automática de membresías)

= Servicios externos =

Este plugin se conecta con la pasarela de pago Redsys para procesar transacciones. Los datos de pago se envían a los servidores de Redsys siguiendo los estándares de seguridad del sector. También puede contactar con getconvoca.app para validar licencias PRO.

== Changelog ==

= 2.6.2 =
* Mejora: 57 tests unitarios, 124 aserciones
* Nuevo: Tests de firma HMAC, importes y notificaciones
