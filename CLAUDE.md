# openrouter-laravel

**Entidad: Municipalidad de Graneros (muni-graneros), NO KraftDo.** El
composer.json declara `"name": "muni-graneros/openrouter-laravel"`, el
namespace es `Muni\OpenRouter`, el remoto es
`git@github-graneros:muni-graneros/openrouter-laravel.git` y el historial dice
"extraído de web-graneros". Aunque el checkout local vive en
`~/Dev/packages/`, es un paquete municipal. No mezclarlo con código o
convenciones de KraftDo SpA.

**Qué es:** cliente Laravel para OpenRouter (`/chat/completions`
OpenAI-compatible). Por defecto usa `openrouter/free`; si un modelo gratis
responde 429 reintenta una vez en un modelo de pago configurado
(`OPENROUTER_FALLBACK_MODEL`); `OPENROUTER_EXCLUDE_LOGGING=true` restringe el
ruteo a proveedores que no entrenan ni retienen el prompt. No es base legal
por sí solo: el README ya aclara que la Ley 21.719 exige justificación y
minimización aparte.

## Estado del repo

- Rama actual: `fix/auditoria-2026-08-30`, **sin upstream configurado**
  (`git branch -vv` no le muestra remoto de tracking). `develop` y `main`
  locales sí siguen a `origin/develop` y `origin/main` respectivamente.
- Working tree limpio al momento de escribir esto.
- Un commit encima de `develop`: `95cd8c0 fix: defaults seguros para datos
  municipales y el fallback que no se disparaba`.
- No está en Packagist: se consume por `type: vcs` apuntando al repo de GitHub
  (ver README para el bloque de `repositories`).

## Comandos reales

```bash
composer install
./vendor/bin/pint --test        # formato
./vendor/bin/phpstan analyse --memory-limit=1G --no-progress   # nivel 8, sin baseline
composer audit --format=plain   # auditoría de dependencias
./vendor/bin/pest               # suite
```

Estos son exactamente los pasos de `.github/workflows/ci.yml`, que corre en
push/PR a `main` y `develop` (además de un cron semanal solo para el audit).

## Cómo se prueba

Pest sobre Orchestra Testbench: no levanta una app Laravel completa y no
toca la red (`OPENROUTER_KEY=test-key` fijo en `phpunit.xml`, todas las
llamadas HTTP van fakeadas). PHPStan es nivel 8 puro (no Larastan) porque no
hay Eloquent que analizar.

## Consumidores confirmados

Grep de `openrouter-laravel` en los `composer.json` de `~/Dev` (sin
`vendor/`):

- `/home/cesar/Dev/web-graneros/composer.json` — `muni-graneros/openrouter-laravel: ^0.1`,
  repositorio VCS apuntando a `https://github.com/muni-graneros/openrouter-laravel.git`.
- `/home/cesar/Dev/web-graneros-centinela/composer.json` — misma versión y mismo repo VCS.

No aparece en ningún `composer.json` de `~/Dev/kraftdo`. No se buscó consumo
fuera de `~/Dev` (por ejemplo, servidores de producción).

## Comparado con Prism / Laravel AI SDK

No hay documentación en este repo que registre esa comparación (no hay
`docs/`, `CHANGELOG.md` no la menciona). Si la evaluación ya se hizo, no está
volcada acá — no inventar una conclusión sin encontrarla.

## Qué NO hacer

- No tratarlo como paquete de KraftDo ni copiarle convenciones de otro
  proyecto personal: es municipal, y el `CLAUDE.md` global prohíbe mover
  código entre entidades.
- No subir `OPENROUTER_KEY` real ni ningún secreto a commits, tests o config
  de ejemplo — solo el key falso ya presente en `phpunit.xml`.
- No asumir `OPENROUTER_EXCLUDE_LOGGING=true` como base de licitud para Ley
  21.719: solo restringe el proveedor, no reemplaza minimización ni
  trazabilidad.
- No pushear esta rama a un remoto de tracking sin confirmar antes con César
  a qué rama corresponde subir (`fix/auditoria-2026-08-30` no tiene upstream).
