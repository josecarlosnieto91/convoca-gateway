# Manual de Instalación y Puesta en Marcha: Biodevas Gateway

Guía para la configuración de la pasarela de pagos Redsys y Bizum.

## 📥 1. Instalación del Plugin

1. **Requisito previo:** `biodevas-common` activo.
2. Sube la carpeta `biodevas-gateway` a `/wp-content/plugins/`.
3. Activa el plugin.

## 🛠 2. Configuración de Redsys

1. Ve a **Biodevas Gateway > Ajustes**.
2. Introduce las credenciales proporcionadas por el banco:
   - **FUC (Comercio)**
   - **Clave Secreta SHA-256**
   - **Terminal** (por defecto `001`)
3. **Páginas de Retorno:** Crea tres páginas con sus respectivos shortcodes y selecciónalas en los ajustes:
   - `[biodevas_pago]` (Página de proceso)
   - `[biodevas_pago_ok]` (Éxito)
   - `[biodevas_pago_ko]` (Fallo)

## ⚙️ 3. Seguridad Recomendada

Para una seguridad máxima, **no guardes la Clave Secreta en la base de datos**. Añade esta línea a tu archivo `wp-config.php`:
```php
define('BDG_SECRET_KEY', 'TU_CLAVE_RED_SYS_EN_BASE64');
```

---

## 🔍 Checklist de Verificación Final

**IMPORTANTE:** Realiza siempre pruebas en el entorno de "Sandbox/Test" antes de pasar a producción.

- [ ] **Entorno de Pruebas:** Configura el entorno en "Test" y usa las tarjetas de prueba de Redsys.
- [ ] **Generación de Firma:** Realiza un pago de 1€. Si Redsys devuelve "Error en datos enviados (SIS0042)", revisa la Clave Secreta y el FUC.
- [ ] **Notificación HTTP:** Tras un pago exitoso en el simulador, verifica en el admin si el estado del Pago pasa de "Pendiente" a "Pagado". Si no cambia, tu servidor puede estar bloqueando la petición POST de Redsys (revisa firewalls o Wordfence).
- [ ] **Retorno al Sitio:** Verifica que tras pagar, el usuario vuelve a la página configurada con `[biodevas_pago_ok]`.
- [ ] **Bizum:** (Si está contratado) Prueba la opción de Bizum en el selector de métodos.
- [ ] **Origen del Pago:** Verifica que el pago queda vinculado correctamente a la inscripción o socio correspondiente (columna "Origen" en el listado de pagos).
- [ ] **Logs de Error:** En caso de fallo, revisa los logs en `bdv_logs` (vía Biodevas Common) para ver el código de respuesta específico de Redsys.

¡Pasarela lista para recaudar aportaciones!
