# Inteligencia artificial: conexiones, catálogo, tarifas, consumo y presupuestos

Este documento describe lo implementado en la etapa 3. El dominio vive en
`packages/php/ai` (`Starter\Ai`); la aplicación aporta el panel, la API y el chat
(`apps/platform/app/Http/Controllers/{Admin/Ai,Api/V1/Ai,Ai}`,
`app/Actions/Ai`, `app/Ai/AiService.php`).

Toda llamada a modelos pasa por `laravel/ai`. No se modifica la configuración
global del SDK: cada conexión se materializa bajo demanda con
`AiManager::build()` (`Starter\Ai\Connections\ConnectionResolver`).

## Permisos

| Permiso | Uso |
| --- | --- |
| `ai.chat` | Usar el chat y `POST /api/v1/ai/chat` |
| `ai.providers.manage` | Conexiones con proveedores |
| `ai.models.manage` | Catálogo de modelos, tarifas y elección de modelo en el chat |
| `ai.agents.manage` | Prompts de sistema (agentes versionados: etapa 4) |
| `ai.usage.read` | Panel de consumo, costos y exportación CSV |
| `ai.budgets.manage` | Presupuestos y avisos de umbral |

## Conexiones

`ai_provider_connections` guarda una o varias conexiones por proveedor (por
ejemplo, dos cuentas de OpenAI). Controladores soportados (`Enums\Driver`):
OpenAI, Anthropic, Gemini, DeepSeek, OpenRouter, Amazon Bedrock, Azure OpenAI,
Groq, Mistral, xAI, Ollama y cualquier API compatible con OpenAI (`custom`).

| Tema | Implementación |
| --- | --- |
| Credenciales | Cifradas (`encrypted:array`), ocultas en JSON y props; el panel solo muestra un valor enmascarado |
| Referencias a secretos | `env:NOMBRE` en lugar de la clave; solo se aceptan los nombres de `starter.ai.secret_refs` o con prefijo `STARTER_AI_SECRET_` (nunca `APP_KEY`, `DB_PASSWORD`, etc.) |
| Protección de endpoints | `Support\EndpointGuard`: los proveedores en la nube exigen HTTPS público; se bloquean IPs de metadatos (169.254.169.254, etc.); Ollama y `custom` pueden apuntar a redes privadas |
| Prueba | `POST …/connections/{id}/test` hace una llamada mínima y clasifica el error (`Connections\ErrorClassifier`: autenticación, cuota, modelo inexistente, red, tiempo de espera…) sin filtrar secretos |
| Rotación | Cambiar la credencial cambia el nombre interno del proveedor (`starter_c{id}_{versión}`), así no se reutiliza un cliente con la clave anterior |

## Catálogo de modelos

`ai_models` + `ai_model_capabilities`. Cada modelo pertenece a una conexión y
declara funciones (`text`, `embeddings`, `image`, `audio`, `transcription`, `reranking`),
ventana de contexto, salida máxima y capacidades (`streaming`, `tools`,
`structured_output`, `vision`, `documents`, `audio_input`, `reasoning`,
`prompt_caching`, `temperature`, `web_search`), cada una con estado
`unknown`, `declared`, `verified` o `unsupported` y su origen.

- **Descubrimiento** (`Catalog\ModelDiscovery`): `/models` de OpenAI y
  compatibles, Anthropic, Gemini, `/api/tags` de Ollama y el catálogo de
  OpenRouter. Los modelos nuevos llegan **deshabilitados** y con capacidades
  desconocidas; un administrador decide cuáles habilitar.
- **Verificación** (`Catalog\ModelVerifier`): una llamada real de prueba pasa por
  el gateway (queda en el libro de consumo con la función `test`). Si el
  proveedor responde "modelo inexistente" o "autenticación", el modelo queda
  `unavailable`. Las capacidades marcadas a mano no se sobrescriben.
- Un modelo es **utilizable** si está habilitado, su conexión está activa y no
  está `unavailable`/`deprecated`. Solo uno puede ser el predeterminado por
  función.

## Tarifas

`ai_model_prices` es versionado: cada cambio cierra la tarifa vigente
(`effective_to`) y abre una nueva; nunca se edita una tarifa usada en un costo.

| Componente | Unidad habitual |
| --- | --- |
| `input`, `output`, `cache_read`, `cache_write`, `reasoning`, `audio_input` | por millón de tokens (también por mil o por token) |
| `audio_seconds` | por segundo o por minuto |
| `image` | por imagen |
| `request`, `web_search` | por solicitud |

Admite niveles (`tier`), moneda y origen (`ai_price_sources`: manual u
OpenRouter). `starter:ai-sync-prices` (diario, 03:17) importa las tarifas de
OpenRouter con registro de ejecución (`ai_price_sync_runs`); las manuales no se
tocan.

`Pricing\CostCalculator` usa `brick/math` (decimales exactos, sin floats):
entrada sin caché + lectura/escritura de caché + salida (los tokens de
razonamiento se descuentan de la salida solo si el modelo tiene tarifa de
razonamiento) + audio + imágenes + cargo por solicitud. **Si falta una tarifa
necesaria, el costo es desconocido (`null`, estado `unknown`), nunca cero.**

## Libro de consumo

| Tabla | Contenido |
| --- | --- |
| `ai_requests` | Solicitud lógica (UUID): función, usuario, conexión, modelo, agente, conversación, estado, tokens, latencia, costos y clave de idempotencia |
| `ai_attempts` | Intentos reales (reintentos y *failover* del SDK), cada uno con su uso, error y si es facturable |
| `ai_cost_snapshots` | Instantáneas inmutables: `estimate`, `calculated`, `reconciled`, con la tarifa usada |

Estados de costo: `pending` → `calculated` | `unknown` | `estimated`
(llamadas interrumpidas: se conserva la estimación previa) → `reconciled`
(conciliación posterior con la factura, guardada aparte del calculado).

Cómo se registra:

- **Gateway** (`Starter\Ai\AiGateway::run()` / `::stream()`): verifica que el
  modelo sea utilizable, estima, reserva presupuesto, ejecuta y cierra la
  solicitud. Es la vía recomendada para código propio.
- **Llamadas directas al SDK**: `Listeners\RecordSdkUsage` escucha los eventos de
  `laravel/ai` (`PromptingAgent`, `StreamingAgent`, `AgentPrompted`,
  `AgentStreamed`, `AgentFailedOver`, `AgentFailed`, `EmbeddingsGenerated`,
  `TranscriptionGenerated`) y crea solicitudes implícitas, así nada escapa del
  libro. La generación de títulos del SDK está deshabilitada
  (`ai.conversations.generate_title = false`) porque no emite eventos de uso.
- **Fallos**: un fallo sin intentos facturables libera la reserva; un *stream*
  cortado queda `interrupted` y liquida la estimación.
- **Mantenimiento** (`starter:ai-maintain`, cada 5 minutos): marca como
  interrumpidas las solicitudes `running` más antiguas que
  `STARTER_AI_INTERRUPT_AFTER_MINUTES` (15) y libera reservas vencidas.

## Presupuestos

`ai_budgets` define límites por ámbito (`global`, `user`, `role`, `feature`,
`connection`) y período (`daily`, `weekly`, `monthly`); `ai_budget_periods`
acumula `spent` y `reserved` por período.

- **Reserva atómica**: antes de llamar se reserva el costo estimado con
  `UPDATE … SET reserved = reserved + x WHERE spent + reserved + x <= limit`. Si
  algún presupuesto aplicable no alcanza, no se reserva nada y la llamada se
  bloquea (`BudgetExceededException`, solicitud `blocked`). El desvío máximo
  posible es la diferencia entre estimación y costo real.
- **Liquidación**: al terminar, la reserva se reemplaza por el costo real.
- **Llamadas directas al SDK**: no pasan por la reserva (solo se observan por
  eventos), así que no pueden bloquearse antes, pero su costo se suma igual a
  los presupuestos aplicables al cerrarse la solicitud.
- `hard_limit = false` solo avisa; `block_unknown_cost` impide llamadas cuyo
  costo no se puede estimar.
- **Avisos**: umbrales configurables (por defecto 80 % y 100 %), una sola vez por
  umbral y período (`ai_budget_alerts`, índice único), con correo en cola a los
  usuarios con `ai.budgets.manage` y evento de auditoría `ai.budget.threshold`.

## Panel de consumo y moneda

**IA → Consumo y costos** muestra totales, gasto diario acumulado, desgloses por
función, modelo, usuario y conexión, errores, latencias (p50/p95), presupuestos y
el detalle de cada solicitud con sus intentos e instantáneas. Exporta CSV
(`ai.usage.read`).

Los costos se guardan en USD. Para mostrar CLP se usa un tipo de cambio
trazable (`ai_exchange_rates`: fecha, fuente y valor), obtenido de
mindicador.cl a pedido o ingresado a mano; nunca se recalculan costos guardados.

## API REST

Prefijo `/api/v1/ai`, autenticación Sanctum. Lectura con la habilidad `read`,
escritura con `write`, además del permiso correspondiente.

| Método y ruta | Descripción |
| --- | --- |
| `POST chat`, `GET prompts`, `GET me/usage` | Chat y consumo propio (habilidad `ai`) |
| `GET/POST connections`, `GET/PATCH/DELETE connections/{id}` | Conexiones |
| `POST connections/{id}/test`, `POST connections/{id}/discover` | Prueba y descubrimiento |
| `GET/POST models`, `GET/PATCH models/{id}`, `POST models/{id}/verify` | Catálogo |
| `GET/POST models/{id}/prices` | Tarifas versionadas |
| `GET usage`, `GET usage/requests` | Resumen y solicitudes |
| `GET/POST budgets`, `PATCH/DELETE budgets/{id}` | Presupuestos |

El contrato completo está en `tooling/contracts/openapi.json` y en `/docs/api`.

## Comandos programados

| Comando | Frecuencia |
| --- | --- |
| `starter:ai-maintain` | Cada 5 minutos |
| `starter:ai-sync-prices` | Diario a las 03:17 |

## Pendiente (etapa 4 en adelante)

- Agentes versionados (borrador/publicación/reversión), chat flotante,
  herramientas del operador con propuestas de acción, adjuntos y transcripción.
- Recomendador de modelos (7.3 de la especificación) y laboratorio de pruebas.
- Proveedor simulado para demos sin claves y títulos de conversación generados a
  través del gateway.
