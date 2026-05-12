# Changelog - Biodevas Gateway

## 2.6.1
- **Nuevo:** `verify_notification()` acepta ahora `HMAC_SHA256_V2` además de V1 (Redsys migra a V2). Añadido método `sign_v2()` con derivación de clave vía HMAC-SHA256.
- **Mantenimiento:** Limpieza de errores de firma falsos en logs de desarrollo.

## 2.6.0
- Rendimiento: Dashboard reescrito con $wpdb JOIN + GROUP BY (eliminado posts_per_page => -1)
- Rendimiento: Widget de escritorio reescrito con agregación SQL directa
- Cache de dashboard reducido a 5 minutos
- Actualización: Documentación sincronizada (versión 2.6.0)

## 2.5.0
- **Nuevo:** Sistema de **subida de justificantes** para transferencias bancarias. Los usuarios pueden adjuntar PDFs o imágenes directamente desde las instrucciones de pago.
- **Nuevo:** Vista administrativa de justificantes. Los administradores pueden visualizar el documento adjunto antes de confirmar el pago manualmente.
- **Mejora:** Flujo de pago unificado. Redirección automática al Gateway para todos los métodos de pago (incluyendo transferencia) desde todos los plugins de origen.
- **Mejora:** UI de detalles de pago en administración más informativa.

## 2.4.0
- **Nuevo:** Soporte para **Transferencia Bancaria** con instrucciones automáticas (IBAN, Beneficiario, Concepto).
- **Nuevo:** Gestión manual de pagos. Los administradores pueden confirmar pagos recibidos por transferencia.
- **Nuevo:** Sistema de **Diagnóstico y Salud** en los ajustes para verificar Redsys y dependencias.
- **Mejora:** Filtros avanzados por método (Tarjeta, Bizum, Transferencia) en el listado de pagos.
- **Mejora:** UX de copiado de enlaces mejorada con feedback visual.

## 2.3.0
- **Seguridad:** Implementada lógica de Check-in restringida al día del evento en integraciones.
- **UI:** Mejoras estéticas en el selector de métodos del frontend.

## 1.2.1
- **Fix:** Sincronizada constante BDG_VERSION con versión del header (1.1.0 → 1.2.1).

## 1.2.0
- **Nuevo:** Sistema de versionado de base de datos con Gateway_Upgrade_Manager.
- **Nuevo:** Integración con Upgrade_Manager base de biodevas-common.
- **Actualización:** Documentación técnica completa.

## 1.1.0
- **Actualización:** Añadido logging para IDs de origen huérfanos en pagos.

## 1.0.0
- Primera versión: CPTs, Redsys client, payment handler, REST API, admin.
