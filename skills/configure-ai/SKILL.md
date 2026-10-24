---
name: configure-ai
description: Configura la IA del Laravel Starter con Laravel AI SDK - conexiones de proveedores (OpenAI, Anthropic, Gemini, DeepSeek, OpenRouter, Bedrock, Azure, compatibles), catálogo de modelos, tarifas, presupuestos, versiones de agentes, herramientas y pruebas - sin exponer secretos. Úsala al conectar proveedores, elegir modelos o publicar agentes.
---

# configure-ai

Toda llamada a modelos pasa por `laravel/ai` y queda en el libro de consumo; una tarifa
desconocida produce costo desconocido (`null`), nunca cero.

## Entradas

| Entrada | Ejemplo |
| --- | --- |
| Proveedor y referencia de la clave | `openai` + `env:OPENAI_API_KEY` o `env:STARTER_AI_SECRET_MAIN` |
| Modelo por defecto del agente | `gpt-5-mini` (debe existir y estar habilitado en el catálogo) |
| Prompt de sistema, límites, multimedia, micrófono | sección `ai.agent` de `starter.config.json` |
| Presupuesto | monto y período (diario/mensual) por alcance |

## Prerrequisitos

- Permisos `ai.providers.manage`, `ai.models.manage`, `ai.agents.manage`, `ai.budgets.manage`
  (o rol owner/admin). Sin claves, usa el proveedor **simulado** (`STARTER_AI_SIMULATED=true`).
- La clave existe en el entorno del servidor; nunca en el chat, el JSON exportable ni los logs.

## Pasos

1. Declarar la conexión en `starter.config.json` (`ai.connections`) y aplicar:
   `php artisan starter:config-apply` (o Admin → IA → Conexiones). Solo se aceptan referencias
   a variables de claves de proveedores o `STARTER_AI_SECRET_*`.
2. Probar la conexión (Admin → Conexiones → Probar) y listar modelos:
   `php artisan starter:ai-models-sync --connection=<ref>` (los nuevos quedan deshabilitados).
3. Habilitar los modelos a usar y revisar capacidades (Admin → Catálogo).
4. Tarifas: `php artisan starter:ai-prices-sync` (OpenRouter) o cargarlas en el modelo; sin
   tarifa el costo queda desconocido.
5. Agente: editar el borrador (prompt, modelo, herramientas, RAG, acceso), probarlo en la
   pantalla del agente y **publicar** una versión (el chat solo usa versiones publicadas).
6. Presupuestos (Admin → Presupuestos) y revisión de uso (Admin → Uso y costos).

## Archivos

`starter.config.json` (`ai`), `apps/platform/.env` (claves), `config/starter.php` (`ai.*`),
`packages/php/ai` (gateway, catálogo, libro, agentes), `docs/ai.md`.

## Comprobaciones

- `php artisan starter:config-validate` sin errores; `starter:verify` sin `failed`.
- Probar el agente: respuesta y fila en el libro con modelo, tokens y costo (o `unknown`).
- `GET /api/v1/ai/usage` (token con habilidad `read`) refleja el consumo.

## Errores frecuentes

| Síntoma | Acción |
| --- | --- |
| `looks like a plain secret` | reemplaza el valor por `env:NOMBRE` o `file:/ruta` |
| `is not an allowed AI key reference` | usa la variable del proveedor o `STARTER_AI_SECRET_*` |
| Agente en borrador sin publicar | falta `modelRef` disponible: habilita el modelo y publica |
| Costo `unknown` | carga la tarifa del modelo; no la inventes ni pongas cero |
| 429 / presupuesto excedido | revisa límites del agente y presupuestos; no los desactives sin autorización |
