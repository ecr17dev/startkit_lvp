# Actualizaciones

- Laravel se mantiene en la rama **13.x**. Renovate no propone versiones mayores de
  `laravel/framework`; migrar a otra versión mayor requiere una decisión y una guía.
- Renovate (`renovate.json`) agrupa el ecosistema Laravel y el stack frontend,
  marca para revisión auth, base de datos, `laravel/ai` e imágenes Docker, y solo
  integra automáticamente parches de herramientas de desarrollo con CI en verde.
- Toda actualización debe pasar `composer validate`, `composer audit`, Pint,
  PHPStan, tests PHP (sobre PostgreSQL), `pnpm check`, `vue-tsc`, tests frontend,
  el contrato OpenAPI, `docker compose config` y la construcción de la imagen.
- `starter.manifest.json` registra las versiones validadas; regenéralo con
  `php artisan starter:manifest` y verifica con `--check`.
- Los componentes shadcn-vue son código copiado en `packages/js/ui`: actualízalos
  comparando con upstream (registro `new-york-v4`) y revisando personalizaciones,
  accesibilidad y apariencia; ningún bot los actualiza solo.
- Las dependencias nunca se instalan dentro de un contenedor en ejecución: se
  construye y despliega una imagen nueva.
