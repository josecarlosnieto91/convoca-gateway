# Changelog — convoca-gateway

## v2.13.0 (2026-09-11)

### ✨ Features
- Un enlace de cuota o de inscripción se cierra en cuanto su pago se confirma y es correcto: deja de admitir pagos y no se genera otro enlace para el mismo pago. Un intento erróneo deja el enlace vivo para reintentar.
- Los enlaces de donativo y las plantillas del generador no se cierran al cobrarse: siguen vivos y solo caducan si tienen fecha de caducidad.

### 🧪 Tests
- Pruebas nuevas del cierre del enlace tras un pago cobrado (LinkClosedAfterPaymentTest).

### 📦 Infrastructure
- README y tabla de versiones de SECURITY al día con la realidad del repositorio.

## v2.12.1 (2026-09-11)

### 🐛 Fixes
- La notificación de Redsys no se aplicaba nunca: la transacción no llegaba a ejecutar COMMIT y MySQL descartaba el cambio en silencio. El plugin respondía OK a Redsys pero el pago seguía pendiente en todos los sitios. Los cobros que figuraban pagados lo estaban solo por la vuelta del navegador.

### 🧪 Tests
- Prueba que vigila que la última sentencia de la transacción sea COMMIT.

## v2.12.0 (2026-09-11)

### ✨ Features
- Panel en el escritorio con los pagos empezados que no se terminaron: concepto, importe, método, fecha y, si dejó correo, enlace al pago. Detecta también los pagos antiguos sin marca de tiempo, que llevaban meses colgados e invisibles.

### 📦 Infrastructure
- Traducción al inglés de las cadenas del recibo y del panel (400 cadenas).

## v2.11.1 (2026-09-11)

### ✨ Features
- El recibo es ahora un documento propio y legible: sale fuera del tema (sin cabecera, menú ni pie), con la entidad y sus datos fiscales, el pagador, el método de pago, la referencia y el estado, márgenes de impresión reales y el botón de imprimir fuera del papel.

## v2.11.0 (2026-09-11)

### ✨ Features
- Recordatorio por correo, una sola vez, a quien dejó su email y empezó un pago que no completó: media hora después, con botón para retomarlo.
- El marcador `{producto}` de los correos usa el concepto que ve quien paga, no el nombre interno.

### 📦 Infrastructure
- El cron del recordatorio (cada 15 min) se programa también al arrancar, no solo al activar; la desinstalación limpia los cron vivos.

## v2.10.0 (2026-09-11)

### ✨ Features
- El pago de una cuota o una inscripción guarda el correo de contacto y su URL lleva token con caducidad configurable (7 días por defecto) en vez de la firma de 24 horas.
- Recibo automático de cuotas e inscripciones (funcionalidad PRO, activada por defecto).
- La página de pago de una cuota o inscripción usa las mismas tarjetas de método que el resto.

### 🐛 Fixes
- El listado de enlaces ya no muestra el estado de pago: muestra si el enlace sigue activo y cuántos cobros ha emitido.

### 🧪 Tests
- Fijado que solo una autorización de compra (0000) marca un pago como cobrado, y que el correo lleve el token del pago.

### 📦 Infrastructure
- Traducción al inglés completa del plugin (380 cadenas).

## v2.9.0 (2026-09-11)

### ✨ Features
- Un enlace de pago ya no se convierte en el cobro: cada uso emite un registro de pago propio (origen «Cobro con enlace») y el enlace queda intacto, pudiendo emitir varios cobros mientras no caduque ni se elimine.
- El cobro se emite al enviar el formulario del método de pago, no al abrir la página: una visita o un rastreador no deja registros sueltos.
- El listado de enlaces muestra cuántos cobros ha emitido cada uno.

## v2.8.1 (2026-09-11)

### 🐛 Fixes
- Un pago y un enlace ya no se confunden: los enlaces de pago (plantillas) no aparecen en el listado de pagos, y los cobros del formulario de pago dejan de figurar como enlaces, con origen propio.
- Los orígenes se leen en castellano y el filtro permite ver solo las donaciones.

## v2.8.0 (2026-09-10)

### ✨ Features
- Se pueden eliminar pagos (en cualquier estado) y editar o eliminar enlaces de pago desde el escritorio, con una pantalla de confirmación que avisa de si está pagado, tiene recibo o procede de una inscripción.
- Editar un enlace no toca su token: la URL publicada sigue valiendo.
- El borrado puede hacerse de fila o en bloque, queda registrado en el diario del plugin y no borra los pagos hechos desde un enlace.

### 🐛 Fixes
- El administrador del sitio también puede borrar aunque la capacidad `convoca_manage_payments` no se haya concedido.

### 🧪 Tests
- La comprobación del borrado pasa a ser un comando del repositorio (`composer verify`).

## v2.7.4 (2026-09-10)

### 🐛 Fixes
- El aviso de seguridad habla en primera persona: «No almacenamos tus datos bancarios».
- El resumen del donativo se queda con el concepto: fuera el «Importe libre» redundante.

## v2.7.3 (2026-09-10)

### 🐛 Fixes
- Al enviar un donativo el pago no se creaba: el formulario y el código que lo procesa se habían desacoplado al pasar a dos pantallas. Ahora se despacha por el nonce del propio formulario.

## v2.7.2 (2026-09-10)

### 🐛 Fixes
- En páginas con el editor clásico, las tarjetas de método salían apiladas porque WordPress insertaba un salto de línea entre ellas. El HTML de los bloques de pago se genera ya sin saltos.

## v2.7.1 (2026-09-10)

### ✨ Features
- El pago se pide en dos pasos: primero el método (tarjeta, Bizum o transferencia) y después el importe y los datos, con opción de cambiar de método sin perder lo tecleado.
- Selector de método compartido por el enlace de pago, la página de pago y el donativo.

### 🐛 Fixes
- La página de un enlace de pago ya no falla si el pago no tenía fecha de caducidad guardada.

## v2.7.0 (2026-09-10)

### ✨ Features
- Enlaces de donativo: si la cantidad se deja vacía al generar el enlace, se crea un enlace de importe libre y reutilizable. Quien aporta elige cuánto donar y cada aportación se registra como un pago propio con su recibo.
- Cobro automático por token vía REST de Redsys: la renovación ejecuta un cargo real con el identificador de comercio almacenado.
- Firma HMAC_SHA512_V2 para el canal REST de Redsys, el esquema de firma actual.
- Caducidad de enlaces configurable (7 días por defecto) con aviso previo por correo y botón para solicitar un nuevo enlace que regenera la fecha manteniendo el mismo pago.
- El correo de pago fallido indica el motivo en lenguaje claro (mapa de códigos Redsys) y un botón de «Reintentar pago».
- Recibo HTML imprimible con numeración anual y justificante de donación (Ley 49/2002).
- Exportación a PDF de los pagos.
- Método de pago por defecto configurable en Ajustes (cualquiera, tarjeta, Bizum o transferencia).
- Plantillas de correo con texto por defecto visible y edición en HTML.
- Los checks de diagnóstico enlazan al botón de reparación correspondiente.

### 🐛 Fixes
- El enlace generado nunca se asignaba y el bloque «Enlace generado» era inalcanzable; ahora se muestra la URL.
- La tokenización inicial enviaba un parámetro solo válido para cargos con referencia ya almacenada, y Redsys denegaba el pago presencial; corregida la operativa CIT.
- El identificador de comercio no se capturaba en el retorno síncrono, con lo que el token de tarjeta se perdía.
- La clave HMAC-SHA512 usa la derivada AES en Base64 (antes el binario); sin ello Redsys rechazaba la firma.
- Exportar el CSV de pagos daba error 500 por un tipo erróneo en el email.
- El botón «Reparar» no ejecutaba nada: la acción AJAX no coincidía con el handler registrado.
- «Reparar» ahora también crea y asigna la página principal de pago, no solo las de retorno.
- La desinstalación dejaba tablas, opciones y cron huérfanos y fallaba por una variable global ausente.
- La versión de los archivos (para evitar caché) estaba congelada: el CDN y los navegadores servían JS/CSS antiguos.
- El campo «Email de notificación» se pintaba pero se ignoraba; ahora viaja con el método elegido.
- Eliminado el campo «FUC Bizum», que no existe en la operativa estándar de Redsys (un único FUC para tarjeta y Bizum).

### 🧪 Tests
- Pruebas nuevas de la firma HMAC_SHA512_V2 (vector oficial), el cargo REST por token y la numeración anual de recibos.

### 📦 Infrastructure
- Deuda estática a cero: análisis estático sin baseline y estilo limpio.
- Preparación para WordPress.org: 0 errores de Plugin Check y probado hasta WordPress 7.1.
- Documentación alineada (versiones, seguridad, código de conducta) y email de contacto único.

## v2.6.6 (2026-09-05)

### 🐛 Fixes
- Log de firma `Ds_Signature` inválida en notificación Redsys

### 🧪 Tests
- Tests `verify_notification` (firma válida/inválida, versión fija, base64url, payload vacío)

### 📦 Infrastructure
- CI bloqueante + PHPStan nivel 5 autosuficiente con stub core

## v2.6.5 (2026-09-05)

### 🔐 Security
- Firma por configuración + validación financiera + rate limit

## v2.6.4 (2026-08-08)

### 🐛 Fixes
- Usar capacidades centrales del core (`convoca_view_payments`) en el listado de pagos

## v2.6.3 (2026-08-07)

### 🐛 Fixes
- `build_merchant_params`: defaults para `is_bizum`, `amount_cents` y `url_notify` (eliminados warnings "Undefined array key")

### ✨ Improvements
- Credenciales Redsys TEST configuradas y verificadas en la demo (merchant 161197496, terminal 100)
- Flujo de pago validado end-to-end (notificación → inscripción/membresía confirmada)

## v2.6.2 (2026-06-24)

### 🐛 Fixes
- Corregido email inconsistente en pago-error (usaba getconvoca.app → ahora biodevas.org)

### ✨ Improvements
- Mejoras en notificaciones automáticas de pago

### 📦 Infrastructure
- Updated release ZIPs on getconvoca.app
- Demo environment synchronized

---

*Las versiones desde 2.7.0 se reconstruyeron desde el historial de git (2026-09-11).*
