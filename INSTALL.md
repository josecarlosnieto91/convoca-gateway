# Manual de Instalación y Puesta en Marcha: Convoca Gateway

Guía para la configuración de la pasarela de pagos (Redsys)

## 📥 1. Instalación del Plugin

1. **Requisito previo:** `convoca-core` activo.
2. Sube la carpeta `convoca-gateway` a `/wp-content/plugins/`.
3. **Dependencias:** Ejecuta `composer install` dentro de la carpeta.
4. Activa el plugin.

## 🛠 2. Configuración de Redsys

1. Ve a **Convoca > Gateway**.
2. Introduce las credenciales proporcionadas por el banco:
   - **FUC (Código de comercio)**
   - **Clave Secreta SHA-256**
   - **Terminal** (por defecto `001`)
3. **Páginas de Retorno:** Crea páginas con los shortcodes:
   - `[convoca_pago]` (Página de proceso)
   - `[convoca_pago_ok]` (Éxito)
   - `[convoca_pago_ko]` (Fallo)

## ⚙️ 3. Seguridad Recomendada

Para una seguridad máxima, **no guardes la Clave Secreta en la base de datos**. Añade a `wp-config.php`:
```php
define('CONV_GATEWAY_SECRET_KEY', 'TU_CLAVE_RED_SYS_EN_BASE64');
```

## 🔧 4. Shortcodes

| Shortcode | Descripción |
|-----------|-------------|
| `[convoca_pago]` | Página principal de pago |
| `[convoca_pago_ok]` | Página de éxito tras pago |
| `[convoca_pago_ko]` | Página de error/fallo |

## 🔍 Checklist

- [ ] Entorno de pruebas configurado (test)
- [ ] Página de pago creada con `[convoca_pago]`
- [ ] Merchant code y terminal configurados
- [ ] Clave secreta en wp-config.php (recomendado)
