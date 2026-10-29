## [2.16.0] — 2026-10-29

### Cambiado

- **devoluciones por Redsys y datos fiscales en el recibo**

## [2.15.0] — 2026-10-28

### Añadido

- **filtro `convoca_gateway_settings_locked`**: cuando un sitio lo pone a `true`, los datos del TPV
  (comercio, terminal, clave secreta, comercio de Bizum y entorno) salen deshabilitados en el panel y
  **no se pueden sobrescribir al guardar**. El resto de ajustes sigue editable.
- Aviso en la pantalla de ajustes explicando que esos datos están fijados.

### Por qué

Una demo pública con las claves del TPV cargadas necesita que nadie pueda cambiar el comercio ni saltar
a `production`: el visitante acabaría en un cobro real que no existe. El candado es del sitio, no del
plugin: una instalación normal no nota nada.

## [2.14.0] — 2026-10-28

### Añadido

- **el cobro pasa a ser de pago**, con **prueba de 14 días**. Se cuenta desde el primer pago que se
  crea, no desde que se instala el plugin.
- Aviso en el panel a falta de tres días y aviso bloqueante al terminar, con enlace a la licencia.

### Importante

- La **notificación de Redsys** y las **páginas OK/KO** quedan **fuera** del candado a propósito: son el
  retorno del banco. Un pago lanzado antes de que caduque la licencia se cobra y se registra igual.
- Los pagos ya creados se pueden consultar, exportar y completar sin licencia.
- Reinstalar no reinicia la prueba: además de la fecha se guarda una huella del sitio.

## [2.13.23] — 2026-10-26

### Cambiado

- **importes y cache de ajustes**
- El importe de un pago deja de reventar cuando la meta llega como cadena.
- La cache de ajustes se puede soltar entre pruebas.

## [2.13.22] — 2026-10-21

### Añadido

- **El formulario de pago informa del tratamiento de datos**, antes del botón: responsable, finalidad, base
  jurídica, destinatarios y derechos, con enlace a la política de privacidad. Es el deber de información del
  art. 13 RGPD, en el momento de recoger los datos.
- **El texto y el enlace son de cada instalación** (Gateway → Privacidad). Por defecto se usa un texto con el
  nombre del sitio y la página de privacidad de WordPress; si no se ha configurado, **el diagnóstico avisa**:
  un texto genérico cumple el expediente, pero el responsable del tratamiento es de cada entidad.

### Por qué no hay casilla de «acepto»

- El pago se apoya en la **ejecución de un contrato** (art. 6.1.b RGPD), no en el consentimiento. Una casilla
  de «he leído y acepto la política de privacidad» no añade base jurídica y la AEPD la ha señalado como el
  patrón que sustituye la información por finalidad: la política no se acepta, se informa. Donde sí hace falta
  casilla es para lo que el contrato no cubre (boletín, cesión a terceros no necesarios): ahí va aparte,
  opcional y sin premarcar.

## [2.13.21] — 2026-10-21

### Añadido

- **Se registra como backend de cobros de `Core\Payments`** por los filtros `convoca_payment_backend`,
  `convoca_payment_reader` y `convoca_payment_receipt`: el núcleo ya no nombra sus clases, y el día que
  haya otra pasarela se sustituye sin tocarlo.
- **Aporta su diagnóstico** al núcleo por el filtro `convoca_system_checks`.

## [2.13.20] — 2026-10-20

### Cambiado

- **El CSS del front sale de siete bloques `<style>` a una hoja.** Estaba repartido en
  `includes/Payment_Handler.php`, uno por vista (formulario de enlace, tarjetas de método, donación,
  transferencia, selector de método, pago correcto y pago fallido): 44 selectores impresos en el
  cuerpo de cada página, sin caché y sin un sitio donde mirarlos. Ahora viven en
  `assets/css/gateway.css`, registrada con dependencia de `convoca-core`.
- **Las dos vistas que compartían nombres de clase ya no se pisan.** El formulario de enlace y el
  selector de método definían `.conv-payment-wrapper`, `.conv-payment-summary`, `.conv-amount` y
  `.conv-desc` con valores distintos; al juntar las hojas, la tarjeta de 600 px se habría quedado en
  480 px sin fondo. El selector de método lleva el modificador `conv-payment-wrapper--compacto` y sus
  reglas van acotadas a él. El bloque de «pago correcto» y el de «pago fallido» eran idénticos: va una
  vez.
- **La pantalla del generador encolaba `convoca-common.css` dos veces** (handle `convoca-common-admin`
  y el `convoca-core` del núcleo). Fuera el duplicado.
- «Convoca Common» → «Convoca Core» en textos y catálogos.

## [2.13.19] — 2026-10-20

### Añadido
- Origen de pago `courses` y filtro `convoca_gateway_valid_origins`: la lista de orígenes estaba
  cerrada a `enroll` y `members`, así que cualquier módulo nuevo que quisiera cobrar recibía
  `invalid_origin` sin salida. Ahora el módulo que cobra añade su origen por filtro.

## [2.13.18] — 2026-10-16

### Arreglado
- **El método elegido viaja en el enlace de pago (issue #9).** Quien elegía «Transferencia bancaria» en el alta
  aterrizaba en `/pago/?convoca_gateway_pago=…&convoca_gateway_key=…` —**sin el método**—, así que la página caía al
  selector genérico y le ofrecía tarjeta y Bizum; con el IBAN sin configurar no veía ni la transferencia ni una
  explicación: el camino moría ahí y el dato había que darlo a mano por WhatsApp. El alta **ya** pasaba el método al
  crear el pago; lo que faltaba era que la URL lo llevara. Con eso la página entra por las instrucciones de
  transferencia que ya existían (IBAN, beneficiario, concepto) —o por el aviso de que falta el IBAN— y el
  justificante se puede subir ahí mismo. El método «any» de un enlace de donación se queda sin parámetro: ahí elige
  quien paga.
- **Doble de `add_query_arg()` del arnés de pruebas, arreglado**: solo entendía la forma de dos argumentos y con la
  de tres (que usa medio plugin) devolvía el **valor** en lugar de la URL. Se destapó al estrenar este arreglo.

## [2.13.14] — 2026-10-15

## [2.13.15] — 2026-10-15

### Cambiado
- **La pantalla de ajustes usa el andamiaje del núcleo (issue convoca-core#16).** `Admin_Settings` suelta su
  constructor con los cinco `add_action` y sus dos `add_submenu_page` (la página visible y la oculta del detalle) y
  pasa a `Convoca\Core\Admin\Pantalla_Admin`. Los ganchos, las capacidades y la prioridad no cambian.
- **Contrato `AndamiajeAdminTest`** (probado en negativo): la pantalla declarada tiene que usar el andamiaje y no
  declarar constructor propio.
- El ignore de PHPStan de `add_submenu_page( null )` se retira: la llamada vive ya en el andamiaje del núcleo y el
  ignore dejó de casar (un ignore que no casa es un error).

### Corregido
- **El exportador de pagos escapaba las fórmulas con una regla propia y más corta (issue convoca-core#16).**
  Tenía la protección escrita a mano —`=`, `+`, `-`, `@`— en vez del ayudante del ecosistema, y **le faltaban
  el tabulador, el retorno de carro y el punto y coma**: justo el separador que usa el CSV que genera. `enroll`
  y `members` ya usaban `Core\Utils::escape_csv_field()`; la pasarela era el único que decidía por su cuenta
  qué es peligroso. Ahora escapa con el mismo ayudante, así que las tres partes aplican la misma regla.

### Añadido
- Contrato (`tests/Unit/EscapadoDelCsvTest.php`) que impide que vuelva a haber aquí una segunda
  implementación de la regla. **Probado en negativo**: contra el fichero anterior caen las dos aserciones.

## [2.13.13] — 2026-10-15

### Cambiado
- **`Payment_Handler::get_payment_link()` se llama `build_payment_url()` (issue convoca-core#16).** El nombre no
  decía lo que hace y era **el mismo** que `Core\Utils::get_payment_link()`, que hace lo contrario: aquella crea
  el pago, esta construye la URL de uno que ya existe. Dentro de la pasarela había nueve llamadas al nombre
  viejo en cuatro ficheros; ahora llaman al nuevo, y `get_payment_link()` queda como **alias de compatibilidad**
  que delega (lo llaman también members, enroll y cualquier integración publicada: se cambia el nombre, no el
  comportamiento).
  - **La URL que genera es la misma byte a byte.** Verificado contra la URL firmada de pagos reales de la demo,
    antes y después del cambio: misma cadena, carácter a carácter.
  - Contrato con prueba, en positivo y en negativo: `build_payment_url()` es el nombre declarado, el alias
    delega, y **no queda ninguna llamada interna al nombre viejo** (la aserción cae contra la versión anterior,
    que tenía ocho).

## [2.13.12] — 2026-10-15

### Cambiado
- **La sal de firma de la pasarela se llama `get_payment_salt()` (issue convoca-core#16).** Se llamaba
  `get_persistent_salt()`, igual que `Convoca\Core\Utils::get_persistent_salt()`, pero **no son la misma**:
  core lee `convoca_persistent_salt` y la pasarela `convoca_gateway_persistent_salt`. Eran **dos secretos con
  el mismo nombre**, y quien leyera el código creería que eran el mismo — con el riesgo de que alguien los
  unificara «para no duplicar», cambiando la sal de un lado y dejando de validar las firmas del otro sin que
  se note hasta que falle un cobro.
  - **El valor NO se toca**: la opción sigue siendo la misma, así que las firmas ya emitidas siguen validando.
    Es un cambio de nombre, no de secreto.

### Pruebas
- `tests/Unit/PaymentSaltTest.php` (tres casos): la firma se calcula con la sal de la pasarela, la sal vive en
  su propia opción y no en la del ecosistema, y no vuelve el nombre compartido. **Negativos probados**: leyendo
  la opción del ecosistema, cae; volviendo el nombre viejo, cae.
- Suite **219** en verde, PHPCS 0.

## [2.13.11] — 2026-10-15

### Arreglado
- **La desinstalación dejaba atrás la sal de firma (issue #10).** `convoca_gateway_persistent_salt` —la sal que
  firma los avisos del TPV— y `convoca_gateway_secret_needs_reentry` se quedaban en la base **para siempre** tras
  desinstalar. La del informe era la más delicada de todo el bloque: una sal que sobrevive al plugin que la creó.
  Las ha encontrado la **prueba de contrato** que lee las claves del propio código.

### Pruebas
- `tests/Unit/OpcionesSeBorranAlDesinstalarTest.php`, con dos comprobaciones: que estén, y que el borrado esté **a
  nivel de fichero** (una opción borrada dentro de un bucle solo se borra a veces — pasó en shifts en este mismo
  arreglo). **Negativo probado**.

# Changelog — convoca-gateway

## [2.13.10] - 2026-10-15

### Corregido

- **El diagnóstico del cobro cacheaba su veredicto sin atarlo a los ajustes** (encontrado al verificar el
  arreglo anterior): el resultado se guardaba una hora y no se invalidaba al cambiar la configuración, así
  que quien acababa de arreglar la clave secreta seguía leyendo «no se puede descifrar» —medido—. Ahora el
  veredicto lleva la huella de los ajustes con los que se calculó y se recalcula en cuanto cambian.

## [2.13.9] - 2026-10-15

### Corregido

- **El diagnóstico decía «clave configurada» sin comprobar que la clave SIRVA** (auditoría de
  navegador): con una clave que no se puede descifrar, `Redsys_Client::secret_key()` devuelve `''`, no hay
  tarjeta ni Bizum y la página de pago se queda sin métodos —y el diagnóstico sonreía igual, porque solo
  miraba que hubiera texto. Ahora comprueba el descifrado y, si falla, lo dice con la severidad de error.

## [2.13.8] — 2026-10-14

### Cambiado
- **El gateway deja de fijar su propio remitente y usa el del núcleo.** Hacía
  `$settings['email_sender_name'] ?? get_bloginfo('name')`, y el `??` **no cae al respaldo cuando la
  clave existe vacía**: con el ajuste guardado en blanco el correo salía como `From:  <correo>`
  (medido por la auditoría de la pasada 10). El `Mailer` de Core ya lee **este mismo ajuste** y resuelve
  el respaldo, así que aquí solo queda el tipo de contenido.

## [2.13.7] - 2026-10-14

### Arreglado

- **El último paso del alta ya no es un callejón sin salida** (recorrido a ciegas con usuario nuevo,
  criterio 16 de la pasada 9 del gauntlet). Cuando no hay ningún método de pago configurado, la
  pantalla decía «No hay ningún método de pago disponible. Contacta con la entidad.» y ahí se
  acababa todo: sin decir qué faltaba, sin decir si el alta se había registrado y **sin un solo
  botón**. Quien rellenaba cuatro pasos se quedaba mirando un aviso.

  Ahora el aviso dice tres cosas:
  1. **Qué ha pasado**: el alta queda registrada y pendiente de pago; no se ha perdido nada.
  2. **Qué falta y dónde se arregla** (credenciales de la pasarela y/o cuenta bancaria para las
     transferencias) con enlace a los ajustes — **solo a quien puede arreglarlo** (`manage_options`);
     a un socio o un visitante esa lista no le sirve y se le mantiene el «contacta con la entidad».
  3. **Por dónde seguir**: un enlace de vuelta (filtro `convoca_gateway_volver_url`).

### Pruebas

- 4 pruebas nuevas de las que **tres fallan** al restaurar el aviso de una línea.
- La primera versión de la prueba invocaba el método nuevo por reflexión y **pasaba con el defecto
  puesto**: el aviso vivía en el punto de llamada y la prueba no lo tocaba. Ahora llama a
  `render_method_cards()`, que es la puerta por la que entra la pantalla de pago.
- Y sin `ReflectionMethod::setAccessible()`: desde PHP 8.1 no hace nada y en 8.5 suelta un
  `Deprecated` que el CI cuenta como incidencia.

## [2.13.6] - 2026-10-13

### Arreglado

- **Contraste AA de la descripción del método de pago**: el texto usaba `#777`, que sobre el fondo
  claro de la tarjeta da **4,29:1** (por debajo del 4,5:1 de AA en texto normal; peor aún sobre
  `#f7f7f7`: 4,18:1). Ahora usa `#6b6b6b`: **5,33:1** sobre blanco y 5,11:1 sobre `#fafafa`, y sigue
  pasando en los cuatro fondos donde aparece el componente.

## v2.13.5 (2026-10-12)

### Arreglado
- **El filtro de IP rechazaba al propio sitio.** En produccion no estaban admitidos ni el
  loopback ni la IP del servidor, asi que cualquier flujo que saliera de la propia maquina
  (la bateria del ecosistema, un `wp` que dispare una notificacion) se descartaba con
  `Notificacion rechazada: IP de origen no autorizada` y dejaba una linea de ERROR en el log
  de produccion en cada pasada. Al reves tambien fallaba: un sitio detras de un proxy podia
  dejar de aceptar notificaciones legitimas sin que nada lo avisara.
- La decision se extrae a `is_allowed_origin_ip()`, publica y con las entradas explicitas,
  para poder probarla sin montar una peticion. Se admiten el loopback, las direcciones del
  propio sitio (nuevo filtro `convoca_gateway_site_ips`) y los rangos de Redsys; un tercero
  desconocido sigue fuera.
- Lo que protege de verdad contra una notificacion inventada es la FIRMA de Redsys, que se
  verifica aparte: esta lista es defensa en profundidad y no puede rechazar a quien sale del
  propio sitio.
- Prueba `RedsysOriginGuardTest`, probada en negativo: con el loopback rechazado vuelve a
  fallar.

## v2.13.4 (2026-10-12)

### Arreglado
- **PHPCS a 0 avisos y 0 errores** en todo el ecosistema, sin el silenciador
  `ignore_warnings_on_exit` en el CI. Entre los avisos habia defectos reales:
  un `var_dump` alcanzable por URL (`?debug=1`) que volcaba los metadatos de una
  inscripcion sin escapar, una clase `CLI_Webhooks` declarada en dos ficheros
  (fatal si se cargaban ambos) y 30 `wp_redirect` que pasan a `wp_safe_redirect`.
- 11 `@` que ocultaban errores de PHP, sustituidos por comprobaciones reales: un `@`
  no arregla nada, esconde el sintoma y deja el registro de avisos mintiendo.
- 14 `error_log()` que solo escribian en el log del servidor pasan al `Logger` del
  ecosistema, que ademas los muestra en el panel.
- `urlencode()` -> `rawurlencode()` en 4 sitios (en una query string, `urlencode`
  codifica el espacio como `+`), `in_array` con comparacion estricta, comparaciones
  `===`, `current_time('timestamp')` y `serialize()` sustituido por `wp_json_encode`.

### Notas
- Sin cambios de comportamiento en los flujos de usuario: lo arreglado son avisos
  del analizador, seguridad de redirecciones y registro de errores.

## v2.13.3 (2026-10-11)

### Corregido
- **Cada cobro dejaba un aviso de deprecación en el log** (cierra #2). `Email_Notifications`
  escuchaba `convoca_payment_completed` y `convoca_payment_failed`, nombres retirados en la
  1.1.0. Ahora escucha `convoca_gateway_payment_completed` y `convoca_gateway_payment_failed`.
  Medido en la demo: eran 28 líneas `PHP Deprecated` de este par en `debug.log`; el correo de
  confirmación del pago no cambia de comportamiento (mismos argumentos y misma prioridad).

## v2.13.2 (2026-10-09)

### ✨ Features
- Las notificaciones de pago pasan por el punto único `Convoca\Core\Mailer` (envoltorio y copia), respetando el remitente propio que ya ponía el plugin.

### 🐛 Fixes
- Enlaces muertos del panel de pagos: «Ver todos los pagos» apuntaba a `edit.php?post_type=pago` (un tipo de contenido con `show_ui=false`, la URL da error) y el botón de Configuración a `options-general.php` cuando la página es un submenú de Pagos. Los dos van ya a `admin.php`, donde están registrados de verdad.

### 🧪 Tests
- Doble fiel de `Convoca\Core\Mailer` para la suite. Un solo bootstrap: se elimina `tests/bootstrap-unit.php`, que duplicaba el arranque con otro juego de dobles y hacía fallar la suite en cualquier runner que lo prefiriera.

### 📦 Infrastructure
- Higiene del producto: retirados el nombre del cliente y el de la asociación de textos y muestras; el changelog deja de nombrar el dominio del cliente.

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
- Corregido email inconsistente en pago-error (usaba el dominio de la tienda → ahora el del propio sitio)

### ✨ Improvements
- Mejoras en notificaciones automáticas de pago

### 📦 Infrastructure
- Updated release ZIPs on getconvoca.app
- Demo environment synchronized

---

*Las versiones desde 2.7.0 se reconstruyeron desde el historial de git (2026-09-11).*
