# Changelog — convoca-gateway

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
