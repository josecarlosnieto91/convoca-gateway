# Changelog — convoca-gateway

## v2.6.2 (2026-06-24)

### 🔐 Security
- Renamed `conv_` → `convoca_` (options, hooks, constants, meta keys)
- Renamed `Assoc` → `Convoca` in autoloader and webhook headers
- Mitigated 12 security vulnerabilities in licensing infrastructure
- License key used as HMAC secret for anti-replay protection

### ✨ Improvements
- PSR-4/classmap autoloading without legacy SPL fallbacks
- i18n: `wp_set_script_translations` for JS translations
- i18n: wrapped `wp_die`, `wp_send_json_error`, and REST messages with `__()`
- Added `wp_enqueue_scripts` hook for script translations

### 🧪 Tests
- Added unit/integration tests covering critical zones

### 📦 Infrastructure
- Updated release ZIPs on getconvoca.app
- Added JSON metadata with SHA256 checksums
- Demo environment synchronized

---
