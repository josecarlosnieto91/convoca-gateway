# Atajos de verificación. Los targets se llaman como espera cualquier
# herramienta (test, lint): es el comando único del repo, sin recordar las
# opciones de composer.
.PHONY: test lint

# Estándar de código, análisis estático y pruebas, en ese orden.
test:
	composer test

# Solo el estándar de código.
lint:
	composer phpcs
