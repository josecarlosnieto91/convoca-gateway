# Constitución del ecosistema Convoca

Versión 1.0.0 · Ratificada el 21-10-2026 · Autoridad: Jose Carlos Nieto Ramos

Esta constitución manda sobre cualquier spec, plan o tarea. Si una spec la contradice, se corrige la spec.
La versión legible por personas vive en la skill `flujo-desarrollo`
(`references/constitucion-convoca.md`); si cambia una, cambia la otra el mismo día.

## Principios fundamentales

### I. Estabilidad primero

No se rompe lo que funciona. Todo cambio con riesgo lleva copia previa verificada y rollback escrito antes
de tocar nada. Producción se toca después de la demo, nunca antes. Un cambio por paso: jamás nginx y
WordPress en la misma tanda.

### II. Una sola vara por medida

Un solo estándar de PHPCS por repo, **copiado de un repo hermano**, nunca reinventado. Un solo config de
PHPStan, y el mismo que usa el CI. Una sola definición de versión: cabecera del plugin, `Stable tag` de
readme.txt, primera entrada del CHANGELOG y la constante (solo si es literal) coinciden, y se comprueba con
`bin/verificar-version.sh`.

### III. La evidencia manda

Ningún «funciona» sin prueba: comando, código de salida, URL, `md5`, captura. Un `200` no prueba nada si hay
caché. Un test que no cae al romper el código no es un test. El CI se juzga por su código de salida, nunca
por el texto de su salida.

### IV. La medición se hace sobre lo que viaja

El paquete, el zip, el directorio desplegado. Nunca el árbol de trabajo, que engaña: la higiene medida sobre
el árbol dio 335 hallazgos de los que casi ninguno viajaba.

### V. YAGNI y simplicidad

Una herramienta pequeña antes que una plataforma. Un script que se entiende antes que una librería que no.
Lo que no se va a usar, no se instala. La funcionalidad va en plugins; el tema solo presenta.

### VI. Nada irreversible sin autorización

Borrar histórico, reiniciar servicios del sistema, tocar el backup diario, cambiar DNS o una cerradura: se
propone y se espera. La única excepción es la limpieza en producción con copia previa ya verificada.

### VII. Todo problema detectado, a su issue

Cada defecto encontrado en un plugin vive en el repo de ese plugin, con la evidencia y su estado. No en un
informe suelto, no en la memoria de nadie, no en un chat que se pierde.

### VIII. Si no se sabe, se dice

La incertidumbre se declara con su nivel de confianza y con cómo se verifica. Inventar un resultado o
maquillar un fallo propio es la única falta imperdonable.

## Flujo obligatorio (6 puertas)

P0 Intención → P1 Spec → P2 Plan de tareas → P3 Ejecución TDD → P4 QA → P5 Entrega y documentación.

Paradas duras: sin spec no se planifica, no se revisa lo que el CI local no ha visto, y sin definición de
terminado no se publica. La definición de terminado depende del tipo de repo (plugin Convoca, web WordPress
en producción, tooling). El desarrollo completo está en la skill `flujo-desarrollo`.

## Puertas automatizadas

- `pre-commit` en cada commit: sintaxis, YAML, espacios y coherencia de versión.
- `commitlint` en cada mensaje: Conventional Commits, que es lo que permite escribir el CHANGELOG solo.
- `ci-local.sh` en cada push (hook global `pre-push`): lo mismo que el CI, en local.
- CI en GitHub: PHPCS, PHPStan, PHPUnit, PCP (si aplica) y el job de cobertura con su suelo en
  `.coverage-floor`.

Un hook que no bloquea es decoración: nada de `|| true`.

## Gobernanza

- **Enmiendas**: las propone cualquiera, las aprueba JC, y entran en la misma pasada en la constitución, en
  la skill y en los repos.
- **Versión**: semver en esta cabecera. Un cambio de principio sube la mayor; una aclaración, la menor.
- **Cumplimiento**: una spec que no cite la parte de esta constitución que la sostiene se devuelve sin
  planificar.
- **Excepción**: se escribe en la spec con su motivo y su fecha de caducidad. Una excepción sin fecha es una
  violación aplazada.

**Versión**: 1.0.0 | **Ratificada**: 2026-10-21 | **Última enmienda**: 2026-10-21
