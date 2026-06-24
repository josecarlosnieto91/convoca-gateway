# CONTRIBUTING.md — Ecosistema Convoca

> Guía para contribuir a los plugins y temas de Convoca.

## Primeros pasos

1. Clona el repositorio que quieras modificar
2. Instala dependencias: `composer install`
3. Ejecuta los tests: `composer test`
4. Asegúrate de que todo pasa antes de hacer cambios

## Entorno de desarrollo

El entorno local de desarrollo está en **Podman** y se levanta con:

```bash
cd ~/.openclaw/workspace/convoca-dev
podman compose up -d
```

- WordPress: `http://localhost:8080`
- Admin: `http://localhost:8080/wp-admin/` (admin / admin123)
- WP_DEBUG activado

Los plugins se montan por bind mount: los cambios en `workspace/convoca-*` se reflejan al instante.

## Estándares de código

- **PHP 8.1+** obligatorio
- **WordPress 6.4+** requerido
- **Namespaces PSR-4**: `Convoca\PluginName\ClassName`
- **WordPress Coding Standards**: `phpcs --standard=WordPress`
- **PHPStan nivel 5**: `phpstan analyse`
- **Prefijos**: `conv_` para opciones, `wp_conv_` para tablas

### Lo que SÍ hacer

- Usar `wp_die()` para errores críticos
- Usar `current_user_can()` antes de operaciones sensibles
- Usar nonces en formularios admin
- Escapar outputs: `esc_html()`, `esc_url()`, `esc_attr()`
- Sanitizar inputs: `sanitize_text_field()`, `sanitize_email()`
- Usar `$wpdb->prepare()` para queries SQL
- Internacionalizar strings: `__()`, `_e()`

### Lo que NO hacer

- No modificar WordPress core
- No usar `extract()` ni `eval()`
- No confiar en `$_GET`/`$_POST` sin sanitizar
- No hardcodear URLs ni paths
- No usar `md5()` para contraseñas
- No dejar `var_dump()` o `console.log()` en producción

## Tests

Cada plugin tiene PHPUnit. Para ejecutar:

```bash
cd workspace/convoca-enroll
composer test
```

### E2E con Playwright

```bash
npx playwright install chromium
npx playwright test
```

El plan de tests E2E está en `convoca-e2e-plan.md`.

## Pull Requests

1. Crea una rama: `git checkout -b feat/mi-cambio`
2. Haz tus cambios con commits descriptivos
3. Ejecuta tests y phpstan: `composer test && phpstan analyse`
4. Push y abre PR en GitHub
5. El CI ejecutará tests automáticamente

### Convención de commits

```
feat: descripción breve
fix: descripción del bug corregido
refactor: qué se reorganizó
docs: qué se documentó
chore: tarea de mantenimiento
```

## Versionado

Seguimos **SemVer**:

- **MAJOR**: Cambios que rompen compatibilidad (2.0 → 3.0)
- **MINOR**: Nuevas funcionalidades compatibles (2.5 → 2.6)
- **PATCH**: Correcciones de bugs (2.6.0 → 2.6.1)

Cada plugin tiene su propio versionado independiente.

## Releases

1. Actualiza `CHANGELOG.md`
2. Actualiza la versión en el header del plugin
3. Crea un tag: `git tag v2.6.1`
4. Push: `git push --tags`
5. El ZIP se genera automáticamente en `convoca-dist/`

## Contacto

- **Autor**: José Carlos Nieto Ramos
- **GitHub**: [josecarlosnieto91](https://github.com/josecarlosnieto91)
- **Web**: [getconvoca.app](https://getconvoca.app)
