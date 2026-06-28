# MANUAL_USUARIO.md — Convoca Gateway v2.6.2

> Guía para administradores: pasarela de pagos con Redsys.

## 1. Introducción

Convoca Gateway procesa pagos a través de Redsys (Sermepa), el TPV virtual más usado en España. Permite crear pagos manuales, generar links de pago para compartir, y recibir notificaciones automáticas cuando un pago se completa.

**Requiere:** convoca-core activo.

**Integración con biodevas.org / lugg.biodevas.org:** Útil para cuotas de socios, donaciones, o inscripciones a actividades de pago. El shortcode `[convoca_pago]` puede incrustarse en cualquier página existente.

## 2. Configuración de Redsys

1. Ve a **Convoca → Gateway → Ajustes**
2. Rellena los datos de tu comercio Redsys:

| Campo | Descripción |
|-------|-------------|
| **Comercio (FUC)** | Número de comercio asignado por el banco |
| **Terminal** | Número de terminal (normalmente 001) |
| **Clave secreta** | Clave SHA-256 proporcionada por Redsys |
| **Titular** | Nombre del titular del comercio |
| **Moneda** | EUR (978) |
| **Entorno** | Real o Pruebas (sandbox) |

3. Usa el botón **Probar conexión** para verificar que los datos son correctos
4. Configura la URL de notificación en el panel de Redsys:
   ```
   https://tudominio.com/wp-json/convoca-gateway/v1/notificacion
   ```

## 3. Crear un pago manual

1. Ve a **Pagos → Añadir nuevo**
2. Rellena:

| Campo | Descripción |
|-------|-------------|
| **Concepto** | Descripción del pago (ej: "Cuota anual 2026") |
| **Importe** | En euros (ej: 30.00) |
| **Socio** | Vincular a un socio (opcional) |
| **Email** | Email del pagador para notificaciones |

3. El sistema genera un **link de pago único** que puedes copiar y enviar
4. El link usa un hash persistente (no caduca si cambia AUTH_SALT)

### Shortcode

```
[convoca_pago]
```

Muestra un formulario de pago simple. Atributos opcionales:

```
[convoca_pago concepto="Donación" importe="10" sugerido="5,10,20,50"]
```

## 4. Panel de pagos

En **Convoca → Gateway → Pagos** verás:

- Listado de todos los pagos con estado (Pendiente, Completado, Fallido)
- Filtro por fecha, estado y socio
- Exportar CSV

## 5. Notificaciones automáticas

Cuando Redsys confirma un pago:

1. El sistema valida la firma HMAC SHA-256
2. Actualiza el estado del pago a "Completado"
3. Si está vinculado a un socio, actualiza su membresía
4. Envía email de confirmación al pagador
5. Dispara el webhook `payment.completed`

## 6. Diagnóstico

El panel **Convoca → Salud del Sistema** incluye chequeos específicos de Gateway:

- ✅ Conectividad con Redsys
- ✅ Certificados SSL válidos
- ✅ Configuración del comercio
- ✅ URL de notificación accesible

## 7. Links de pago directos

Además de crear pagos desde el panel, puedes generar links directos:

```
https://tudominio.com/?convoca_pagar=1&concepto=Cuota&importe=30
```

Parámetros aceptados: `concepto`, `importe`, `socio_id`, `email`.

## 8. Problemas comunes

| Problema | Solución |
|----------|----------|
| **Error "Firma no válida"** | Verifica la clave secreta en Ajustes. Redsys distingue mayúsculas |
| **Pago completado pero no se refleja** | Revisa **Convoca → Registros** para ver la notificación recibida |
| **Link de pago no funciona** | Asegúrate de que el pago no está ya completado. Cada link es de un solo uso |
| **Entorno de pruebas** | Usa el modo "Pruebas" y la tarjeta 4548812049400004 |
