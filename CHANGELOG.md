# Changelog — convoca-gateway

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
