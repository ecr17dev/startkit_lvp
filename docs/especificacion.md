# Starter Kit Laravel 13 IA — Especificación integral del proyecto

Versión del documento: 1.0  
Fecha: 23 de octubre de 2026  
Propietario: Esteban Cortés  
Variante: Laravel 13 + Laravel AI SDK + Vue + Inertia + shadcn-vue + PostgreSQL/pgvector + PostgREST opcional.  
Estado: especificación para implementación; comandos, contratos y estructura propuestos todavía deben desarrollarse y verificarse.

Esta es una variante independiente del starter anterior y conserva su alcance funcional. PostgreSQL es la base de datos; PostgREST es un servicio adicional de API de datos, activable. Si “postgrest” se utilizó como abreviatura de PostgreSQL, basta mantener ese servicio desactivado; el resto del diseño no cambia.

## 1. Visión y resultado esperado

Construir un starter kit funcional y reutilizable para comenzar proyectos con autenticación, roles, permisos, administración, inteligencia artificial y RAG opcional ya integrados. Debe permitir que una persona o un agente de desarrollo instale una base consistente y avance directamente hacia las funcionalidades del negocio.

La experiencia objetivo es: instalar el monorepo, iniciar Docker, verificar el correo mediante un código, ingresar al panel, conectar un proveedor IA, sincronizar modelos y precios, configurar un agente y probarlo desde un chat flotante. Si el proyecto necesita conocimiento propio, activar RAG, cargar fuentes y consultar respuestas con evidencia y costos registrados.

El entregable de implementación debe ser una aplicación real y ejecutable, con migraciones, servicios, interfaz, pruebas, instalador y documentación. Una maqueta o un panel que solo guarde configuraciones no satisface el objetivo.

## 2. Stack obligatorio y decisiones técnicas

### 2.1 Tecnologías requeridas

| Capa | Tecnología obligatoria | Responsabilidad |
| --- | --- | --- |
| Backend | Laravel 13 con PHP compatible y soportado | Rutas, controladores, auth, dominio y API |
| Inteligencia artificial | Laravel AI SDK, paquete laravel/ai | Agentes, proveedores, herramientas y modalidades soportadas |
| Frontend | Vue 3 con Composition API y TypeScript estricto | Login, administración y chat |
| Integración frontend/backend | Inertia con adaptadores Laravel y Vue | Navegación, páginas, formularios y props |
| Componentes | shadcn-vue y Tailwind CSS | Panel completo, formularios, tablas y gráficos |
| Build de frontend | Vite y Node.js LTS | Compilación de assets y tooling |
| Datos | PostgreSQL | Datos de aplicación, sesiones, colas y métricas |
| Vectores | Extensión pgvector | RAG interno y conexión vectorial externa |
| API de datos adicional | PostgREST, módulo activable | Vistas/RPC permitidas mediante contrato separado |
| Repositorio | Monorepo Composer + pnpm workspaces | Aplicación, paquetes, frontend, CLI y documentación |
| Ejecución | Docker y Docker Compose | Desarrollo y despliegue reproducibles |
| Documentación | Markdown, OpenAPI, AGENTS.md, SKILL.md y llms.txt | Uso humano y por agentes |

Partir del starter Vue oficial de Laravel como referencia de integración, fijando una versión compatible con Laravel 13. El panel se construye con shadcn-vue directamente. Toda ejecución IA de servidor se realiza con Laravel AI SDK; no introducir Vercel AI SDK, un backend Node, Nuxt, NestJS o un segundo framework de agentes en esta variante.

### 2.2 Selecciones complementarias

| Necesidad | Selección propuesta |
| --- | --- |
| Dependencias PHP | Composer 2, repositorios path para paquetes internos |
| Dependencias frontend | pnpm workspaces, dependencias internas workspace: |
| Autenticación | Sesiones Laravel y flujo OTP por correo propio integrado con su guard |
| Funciones auth complementarias | Fortify solo donde corresponda, sin un segundo sistema de sesiones |
| API para integraciones | Laravel Sanctum cuando se requieran tokens de API |
| Autorización | Policies/Gates y Spatie Laravel Permission compatible con Laravel 13 |
| Datos | Eloquent, Query Builder y migraciones Laravel |
| Validación | Form Requests, DTO PHP y contratos de herramientas del SDK |
| Trabajos | Laravel Queue con driver database sobre PostgreSQL |
| Planificación | Laravel Scheduler con proceso dedicado y locks compartidos |
| Correo | Laravel Mail/Notifications con Resend y SMTP; Mailpit local |
| Archivos | Laravel Filesystem con almacenamiento privado local y S3 compatible |
| Gráficos | Componentes shadcn-vue sobre Unovis, con tabla accesible |
| Documentación API | Scramble para OpenAPI y UI de referencia protegida |
| Pruebas | Pest/PHPUnit, Vitest para lógica Vue y Playwright E2E |
| Calidad | Laravel Pint, PHPStan/Larastan compatible, ESLint y vue-tsc |
| Mantenimiento | Renovate para Composer, pnpm, Node, PHP y Docker |

Verificar soporte y versiones de todos los paquetes al implementar. No agregar Redis, Horizon, Octane, Reverb o un motor vectorial distinto como requisitos de la base. Pueden documentarse como perfiles avanzados; Horizon requiere Redis y no administra la cola database predeterminada.

### 2.3 Versiones reproducibles

- Mantener Laravel dentro de la rama 13.x solicitada, actualizada con sus versiones menores y parches compatibles. Una futura migración a otra versión mayor requiere una decisión y guía explícitas.
- Elegir una versión de PHP soportada, compatible con Laravel 13 y sus extensiones; registrar mínimo y versión de producción en la matriz de compatibilidad.
- Usar Node.js LTS para frontend, tooling y SSR Inertia opcional; el backend y el worker son PHP.
- Fijar resoluciones exactas mediante composer.lock, pnpm-lock.yaml e imágenes Docker versionadas/digests. No usar latest en despliegues productivos.
- Revisar APIs y documentación de la versión instalada de Laravel AI SDK. No trasladar nombres de métodos de otro SDK.
- Registrar Laravel, PHP, Composer, laravel/ai, Vue, Inertia, Tailwind, shadcn-vue, Node, pnpm, PostgreSQL, pgvector y PostgREST en starter.manifest.json.

### 2.4 Actualización continua con comprobaciones

Renovate detectará versiones diariamente y generará PRs para Composer, pnpm, runtimes e imágenes. Agrupar dependencias relacionadas y priorizar seguridad. Los parches y cambios menores elegibles pueden integrarse automáticamente solo tras los controles de CI y la política del repositorio. Laravel mayor, cambios de auth, base de datos, migraciones y otras actualizaciones sensibles requieren revisión.

Toda actualización debe superar composer validate/install, auditoría de dependencias, análisis estático, tests PHP, typecheck Vue, tests frontend, OpenAPI, build Docker y E2E. Probar PHP/Node próximos en una matriz adicional sin promoverlos automáticamente a producción.

El panel muestra versión instalada, última estable detectada, fecha de revisión, soporte y actualizaciones pendientes. No instala dependencias dentro del contenedor en ejecución. El instalador toma la última release del starter validada con Laravel 13 y declara cualquier desfase respecto a upstream.

Los componentes shadcn-vue copiados necesitan comparación de código upstream, revisión de personalizaciones y pruebas visuales/accesibilidad; un bot de dependencias no actualiza por sí solo esos archivos.

## 3. Límites de la primera versión

La V1 debe incluir frontend Vue/Inertia terminado, backend Laravel 13, herramientas administrativas del chat, OpenAPI automático, E2E, mantenimiento de dependencias, auth, permisos, panel, proveedores IA, catálogo de modelos, tarifas, agente configurable, chat flotante, transcripción opcional, costos, RAG interno y externo, indexación administrable, instalador y documentación.

Organizaciones se implementan como módulo activable. Cuando esté desactivado, habrá un único espacio de trabajo interno, manteniendo el mismo contrato de pertenencia de los datos para facilitar una evolución posterior.

Quedan como ampliaciones posteriores: cobros y suscripciones, marketplace de plugins, voz bidireccional en tiempo real, OCR avanzado, generación audiovisual, navegador autónomo y conectores empresariales específicos. La V1 sí permite adjuntar imágenes y documentos soportados y transcribir audio grabado.

## 4. Arquitectura del monorepo

```text
laravel-starter/
  apps/
    platform/                       Aplicación Laravel 13 con Inertia
      app/
        Actions/                    Casos de uso compartidos por panel/API/chat
        Http/Controllers/           Controladores Inertia y API JSON
        Http/Requests/              Validación y autorización de entrada
        Models/
        Policies/
        Jobs/
        Console/Commands/
        Ai/Agents/
        Ai/Tools/
      bootstrap/
      config/
      database/migrations/
      database/factories/
      database/seeders/
      resources/js/
        pages/                      Login, OTP, panel y módulos
        layouts/
        components/
        composables/                Chat, permisos y patrones reutilizables
        types/
      routes/web.php
      routes/api.php
      routes/console.php
      tests/Feature/
      tests/Unit/
      composer.json
      composer.lock
      package.json
      vite.config.ts
  packages/
    php/
      identity/                     OTP, auth, roles y permisos
      ai/                           Laravel AI SDK, modelos, precios y uso
      knowledge/                    RAG, ingesta, retrieval y conectores
      administration/               Configuración, auditoría y herramientas
      starter-console/              Instalador y generadores Artisan
    js/
      ui/                           Componentes shadcn-vue compartidos
      api-client/                   Cliente tipado de API JSON generado
      chat/                         Composable Vue y contrato de streaming
  tooling/
    create-starter/                 Distribución CLI PHP/Composer
    contracts/                     Exportación OpenAPI, tipos y Markdown
  docker/
    app.Dockerfile                  Imagen PHP para app, worker y scheduler
    nginx.conf
    compose.yaml
    compose.dev.yaml
    compose.production.yaml
  docs/
    architecture.md
    installation.md
    authentication.md
    permissions.md
    frontend-components.md
    ai-providers.md
    agents-and-tools.md
    models-and-pricing.md
    audio-and-media.md
    rag.md
    rag-external.md
    indexing.md
    costs.md
    postgrest.md
    api-reference.md
    docker.md
    e2e.md
    upgrades.md
  skills/
    install-starter/SKILL.md
    extend-starter/SKILL.md
    configure-ai/SKILL.md
    manage-rag/SKILL.md
  tests/e2e/
  AGENTS.md
  README.md
  llms.txt
  starter.manifest.json
  starter.config.example.json
  .env.example
  pnpm-workspace.yaml
  pnpm-lock.yaml
  renovate.json
  playwright.config.ts
```

Un monorepo con monolito modular Laravel y frontend Vue dentro de la aplicación Inertia. Los paquetes PHP se cargan mediante Composer path repositories y service providers explícitos; el frontend comparte paquetes mediante pnpm. El único proyecto Composer resoluble es apps/platform, con su composer.json y composer.lock; requiere los paquetes internos mediante rutas ../../packages/php/*. Ejecutar Composer con --working-dir=apps/platform y Artisan desde ese directorio. Los paquetes internos declaran dependencias pero no mantienen locks independientes para el despliegue. pnpm-workspace.yaml y pnpm-lock.yaml viven en la raíz.

No separar Vue en una SPA que deba duplicar auth, routing o reglas del servidor. Inertia sirve páginas y formularios; una API JSON versionada cubre integraciones y operaciones que necesitan un contrato REST. Ambos usan las mismas Actions y Policies.

Worker, scheduler y aplicación reutilizan la misma imagen PHP y código; cambian sus comandos de arranque. No requieren tres aplicaciones Laravel diferentes. Las operaciones largas se despachan a Queue y sobreviven al cierre de la petición web.

Vite compila assets en una etapa Node del build. Inertia SSR es opcional y tiene un proceso Node separado cuando se habilita; no debe ser necesario para usar el panel autenticado. Los secretos y servicios PHP nunca se exponen mediante props Inertia o bundles.

## 5. Autenticación, roles y permisos

### 5.1 Login OTP sin contraseña

Laravel es la única autoridad de autenticación. El starter Vue oficial aporta estructura de interfaz y auth, pero el acceso por código de un solo uso requiere implementar y probar su propio flujo. No afirmar que el starter o Fortify lo resuelven automáticamente.

1. El usuario ingresa su correo en una página Vue/Inertia.
2. Laravel valida la política de registro/invitación, aplica rate limit y crea un desafío criptográficamente seguro.
3. Laravel Mail/Notifications entrega el código mediante el transporte seleccionado.
4. El usuario ingresa el código y el servidor lo verifica y consume de forma atómica.
5. Se autentica mediante el guard Laravel, regenera la sesión y redirige al destino autorizado.

Valores iniciales configurables: seis dígitos, cinco minutos de vigencia, máximo cinco intentos y sesenta segundos entre reenvíos. Al reenviar, invalidar el desafío anterior según política. Proteger el verificador mediante HMAC con secreto del servidor o un mecanismo equivalente contra fuerza bruta offline; nunca guardar ni registrar el código en claro en producción. Vínculo al desafío y controles por identidad/IP; respuestas que no faciliten enumeración.

Usar sesiones persistidas en PostgreSQL, cookie HttpOnly/Secure, CSRF, validación de origen, expiración, logout y revocación. No dejar habilitado un login por contraseña alternativo que el producto no haya autorizado. Acceso por invitación, abierto o cerrado configurable.

El propietario se prepara mediante CLI confiable y debe verificar su correo. Nunca otorgar privilegios al primer visitante público. Reintentos de entrega no crean varias cuentas ni consumen un código prematuramente; el envío de OTP no depende de una cola de indexación saturada.

### 5.2 Autorización

Usar Policies/Gates como puntos de aplicación de reglas y un paquete de permisos compatible para roles/asignaciones. Evitar dos catálogos independientes. Roles iniciales: propietario, administrador, editor y lector; separar privilegios de plataforma y organización si se activa ese módulo.

Permisos de ejemplo:

```text
users.read, users.create, users.invite, users.update, users.disable, users.delete
roles.read, roles.manage, permissions.read, permissions.manage
settings.read, settings.update
ai.chat, ai.providers.manage, ai.models.manage
ai.agents.manage, ai.usage.read, ai.budgets.manage
rag.collections.read, rag.collections.manage
rag.sources.manage, rag.documents.read, rag.documents.delete
rag.jobs.manage, rag.playground.use, rag.analytics.read
audit.read, api.docs.read
```

Las rutas Inertia, API, comandos y herramientas IA invocan las mismas reglas de acceso al recurso y su organización. Las props de permisos solo adaptan la interfaz; el backend siempre valida. Los cambios invalidan cachés/autorizaciones y sesiones cuando corresponda.

Si se habilitan tokens Sanctum para integraciones, sus abilities acotan el acceso pero no sustituyen Policies ni el ámbito del recurso. No usar tokens permanentes en el navegador Inertia del mismo origen.

## 6. Panel administrativo

Secciones obligatorias:

- Inicio y salud del sistema.
- Usuarios, roles, permisos, invitaciones y sesiones.
- Organizaciones, cuando estén activadas.
- Configuración de marca, idioma, zona horaria y correo.
- IA: conexiones, modelos, tarifas, agentes, pruebas, uso y presupuestos.
- Conocimiento: colecciones, fuentes, documentos, trabajos y laboratorio de búsqueda.
- Analítica RAG y costos.
- Auditoría y diagnósticos.

El panel debe ser adaptable, accesible y construido con shadcn-vue. Incluir temas claro/oscuro, navegación por teclado, estados vacíos, errores, validación de formularios, filtros, paginación desde servidor y confirmación de operaciones destructivas. Los gráficos tendrán tabla equivalente y exportación CSV cuando corresponda.

### 6.1 Frontend terminado desde la primera instalación

El frontend debe incluir las páginas de correo, verificación OTP, sesión vencida, acceso denegado, onboarding inicial, dashboard, perfil, usuarios, detalle/edición de usuario, roles, matriz de permisos y configuración. Todas deben funcionar con controladores Laravel y formularios Inertia reales, con persistencia y autorización comprobables. El cliente REST generado se usa para endpoints JSON cuando corresponda; no se exige para cada navegación Inertia.

No dejar pantallas críticas como maquetas, enlaces sin destino ni botones sin acción. El usuario nuevo debe completar el flujo sin editar componentes o conectar formularios manualmente. La configuración de credenciales y marca se realiza con el instalador y los formularios del panel.

Usar de forma consistente los componentes shadcn-vue apropiados: sidebar, breadcrumbs, button, card, form, input, input OTP, label, select, combobox, checkbox, switch, tabs, table/data table, pagination, dropdown menu, dialog, alert dialog, sheet, tooltip, toast, skeleton, avatar, badge, progress y charts. Mantener un catálogo de todos los componentes disponibles en la versión seleccionada con ejemplos, estados y patrones; no es necesario montar componentes sin propósito en cada pantalla.

Proveer layouts de auth y admin; navegación adaptada a permisos; formularios validados; búsqueda y filtros; vista móvil; tema configurable por tokens; componentes compartidos de selección de usuario/rol, permisos, subida de archivos y confirmación de acciones. Una misma acción debe mostrar datos coherentes tanto si se ejecutó desde un formulario como desde el chat.

## 7. Centro de proveedores IA

### 7.1 Conexiones

Una conexión identifica proveedor, alias, adaptador, credencial referenciada, endpoint permitido, estado y opciones compatibles. Permitir varias conexiones al mismo proveedor para separar proyectos o cuentas.

Soportar los drivers del Laravel AI SDK y adaptadores propios compatibles cuando hagan falta. Verificar cada modalidad y conexión contra el SDK instalado; no asumir paridad con proveedores de otro SDK. Incluir soporte configurable para APIs compatibles con OpenAI. La compatibilidad con ese protocolo no garantiza todas las funciones; el sistema debe comprobarlas por conexión y modelo.

Las claves permanecen en el servidor, cifradas con una clave de aplicación gestionada fuera de la base de datos o referenciadas desde secretos del despliegue. Mostrar valores enmascarados y permitir rotación. Probar conexión, listar capacidades conocidas y distinguir errores de autenticación, cuota, red y modelo inexistente.

### 7.2 Catálogo y selección de modelos

Orden de descubrimiento: API del proveedor cuando exista, catálogo estructurado verificable, importación manual con fuente. Conservar identificadores exactos y distinguir modelo, versión, proveedor de ejecución y conexión de facturación.

Ficha de modelo: función, modalidades de entrada/salida, herramientas, salida estructurada, streaming, contexto, límites de salida, parámetros soportados, estado de disponibilidad, fuente y última verificación. Una capacidad no verificada se identifica como desconocida.

Permitir búsqueda por nombre, función, costo y capacidad. Probar disponibilidad con una llamada mínima explícita; aparecer en un catálogo no implica acceso con la credencial configurada.

### 7.3 Recomendación asistida por IA

El administrador describe el caso de uso. El backend filtra candidatos mediante requisitos verificables. Un modelo de evaluación ya configurado compara únicamente esos candidatos, recomienda opciones y explica sus razones. Validar la respuesta con los contratos estructurados del SDK y la validación PHP y rechazar IDs inexistentes.

Si no hay una conexión utilizable, el recomendador funciona con filtros y puntuación determinista. La IA no inventa precios, capacidades ni resultados de benchmarks. Una búsqueda web opcional puede proponer actualizaciones, pero requiere evidencia verificable y aprobación administrativa antes de incorporar datos no estructurados al catálogo.

El laboratorio permite ejecutar el mismo conjunto de prompts contra varios modelos, bajo un presupuesto y concurrencia máximos. Mostrar resultados, errores, latencia, tiempo hasta el primer token, consumo y costo estimado. Registrar costo del evaluador y de todas las pruebas.

### 7.4 Integración efectiva con Laravel AI SDK

Centralizar agentes, drivers y herramientas PHP en el módulo IA. Resolver conexiones dinámicas por petición mediante las capacidades soportadas del SDK o factories propias probadas; no mutar configuración global compartida entre ejecuciones. La elección de proveedor de chat, embeddings y transcripción es independiente.

Instrumentar respuestas, eventos o middleware del SDK compatibles con la versión instalada para capturar uso, herramientas, intentos y errores. El catálogo de modelos, sincronización de tarifas, presupuestos y dashboard son módulos del starter; no asumir que el SDK incluye por sí solo precios vigentes o facturación conciliada. Tratar la memoria de conversaciones del SDK y las tablas del starter como una única estrategia de persistencia, con un adaptador cuando sea necesario; no escribir dos historiales divergentes.

## 8. Tarifas y dashboard de costos

### 8.1 Catálogo de tarifas

Sincronización manual y programada mediante jobs. Cada tarifa conserva fuente, fecha de consulta, vigencia, moneda, unidad, modalidad y ámbito de facturación. Separar precios directos, precios de gateway, acuerdos personalizados y niveles de servicio.

Admitir unidades por millón de tokens, tokens cacheados, audio, minuto, imagen, solicitud o unidad específica. No asumir que todos los modelos o servicios cobran de la misma forma. Las tarifas desconocidas producen costo desconocido; nunca costo cero por defecto.

### 8.2 Libro de consumo

Registrar cada llamada con request_id, usuario, organización, agente, versión de configuración, conversación, función, proveedor, conexión, modelo, estado, tiempos y unidades reportadas. Incluir chat, recomendación, pruebas, herramientas cobradas, embeddings, consultas RAG, transcripción y reintentos facturables.

Persistir una copia de la tarifa aplicada a cada estimación. Un cambio de tarifa no recalcula silenciosamente el historial. Evitar duplicar registros mediante claves idempotentes; distinguir ejecución lógica e intentos reales. Si falta información de una llamada interrumpida o fallida, marcar uso pendiente/desconocido y reconciliar cuando sea posible.

Separar costo calculado, estimación provisional y costo conciliado con proveedor. Nunca presentar una estimación como factura real.

### 8.3 Dashboard y presupuestos

Gráficos diarios y acumulados; desglose por proveedor, modelo, agente, usuario, organización y función; costo de indexación y costo de cada respuesta RAG; tokens, errores, latencias y tendencias. Permitir ocultar el dashboard sin desactivar la medición.

Presupuestos por día/mes y ámbito, alertas configurables y bloqueo al alcanzar límites. Reservar presupuesto de manera atómica antes de ejecutar y liquidar al finalizar para reducir sobrepasos por concurrencia. El costo final puede diferir de la estimación; documentar esa tolerancia y los límites del proveedor.

Moneda base USD. Conversión visual opcional a CLP mediante un adaptador verificable, guardando tasa y fecha. No mezclar conversión monetaria con el consumo original.

## 9. Agentes configurables

Crear un agente inicial editable desde el panel. Sus configuraciones deben tener borrador, validación, prueba, publicación, historial y reversión.

| Grupo | Parámetros |
| --- | --- |
| Identidad | Nombre, avatar, saludo, idioma y tono |
| Instrucciones | System prompt, variables permitidas y versión |
| Ejecución | Conexión, modelo, respaldo, timeout y reintentos |
| Respuestas | Máximo de tokens, estilo y temperatura cuando aplique |
| Contexto | Historial, ventana de tokens, resumen y retención |
| Multimedia | Imágenes, documentos, audio, MIME, tamaños y cantidades |
| Micrófono | Activación, transcriptor, idioma, duración y envío automático |
| Voz de salida | Opcional y separada; requiere capacidad y tarifa propias |
| Herramientas | Lista permitida, máximo de pasos y confirmaciones |
| RAG | Modo, conexión, colecciones, búsqueda y política de evidencia |
| Acceso | Roles, organizaciones y páginas habilitadas |
| Límites | Solicitudes, concurrencia, consumo y presupuesto |

El servidor resuelve la configuración publicada y autorizada. El cliente no puede inyectar libremente un system prompt, un modelo caro o una colección privada. Las conversaciones registran la versión utilizada para permitir diagnóstico.

### 9.1 Herramientas administrativas ejecutables desde el chat

El agente será un operador de la aplicación, con herramientas tipadas para consultar y modificar usuarios, roles y permisos. No basta con redactar instrucciones: la herramienta debe ejecutar el servicio real y devolver su resultado verificable.

| Herramientas propuestas | Comportamiento |
| --- | --- |
| users.list, users.get | Buscar y consultar usuarios autorizados |
| users.create, users.invite | Crear una cuenta o enviar invitación explícitamente solicitada |
| users.update, users.disable | Editar datos o desactivar acceso |
| users.delete | Eliminar conforme a retención y dependencias |
| roles.list, roles.get, roles.create, roles.update, roles.delete | Administrar roles dentro del ámbito permitido |
| permissions.list | Consultar el catálogo implementado |
| roles.setPermissions, users.assignRole, users.removeRole | Cambiar permisos de roles y asignaciones |

Los permisos funcionales deben corresponder a operaciones implementadas. El chat puede gestionar su asignación y, si el sistema admite extensiones registradas, sus metadatos; no puede inventar un identificador que habilite una capacidad inexistente.

Definir herramientas con contratos Tool y esquemas compatibles con Laravel AI SDK, una lista permitida por agente y validación PHP en ejecución. Reutilizar su mecanismo de aprobación humana cuando sea compatible, persistiendo el estado y las propuestas con las garantías aquí especificadas. El contexto del actor, la sesión y la organización se inyectan en el servidor; nunca se aceptan como autoridad desde argumentos generados por el modelo. La disponibilidad de la herramienta no sustituye las comprobaciones dentro del servicio. API, Artisan, panel y chat reutilizan los mismos casos de uso y políticas.

Resolver personas mediante búsqueda antes de modificar. Ante nombres duplicados, mostrar candidatos para que el usuario elija. No crear correos, destinatarios o identidades por inferencia. Una cuenta creada sigue el flujo OTP y la política de verificación de correo.

### 9.2 Vista previa, confirmación y ejecución segura

Las consultas autorizadas y cambios ordinarios explícitamente solicitados pueden ejecutarse directamente según la política del proyecto. Para eliminación, cambios de roles/permisos, acciones masivas y otras operaciones sensibles, presentar una tarjeta con objetivo exacto, valores antes/después, efectos y botón de confirmar/cancelar. No pedir una confirmación por cada operación de lectura.

La propuesta se guarda en el servidor con actor, ámbito, recurso, versión, argumentos normalizados, vencimiento e identificador de uso único. La confirmación está vinculada a esa propuesta; un texto genérico del modelo no autoriza cambios diferentes. Al ejecutar, volver a validar sesión, permiso, estado del recurso y restricciones; rechazar propuestas vencidas o alteradas.

Proteger al último propietario, impedir autoescalamiento y exigir autorización delegable para conceder privilegios. Los cambios de rol deben surtir efecto según una política documentada de invalidación de sesiones/cachés; no continuar operando con permisos revocados.

Persistir cambios en transacción, usar idempotencia en reintentos y registrar auditoría con actor humano, agente, llamada, objetivo, antes/después permitido y resultado. Nunca afirmar éxito si el servicio falló o solo preparó una propuesta. Actualizar las tablas del panel tras una operación confirmada y ofrecer enlace al recurso afectado. Auditar también rechazos y cancelaciones relevantes, sin guardar secretos.

Ejemplos de uso: “Muéstrame los usuarios desactivados”, “Crea a Ana con este correo y rol lector”, “Cambia a editor el rol de esta usuaria” y “Elimina este usuario”. Las dos últimas acciones muestran los cambios concretos y respetan la política de confirmación. La eliminación contempla dependencias, revocación de acceso y anonimización/purga según retención; los registros de auditoría no se borran silenciosamente.

## 10. Chat flotante y multimedia

Incluir un botón flotante por defecto dentro del panel autenticado, vista compacta y vista ampliada, historial, nueva conversación, streaming, detener generación y estados de error. Cuando no exista configuración válida, el administrador verá un acceso a la configuración; los demás usuarios verán un estado explicativo.

El modo de pruebas del administrador muestra conexión, modelo, latencia, tokens, costo y, si hay RAG, fragmentos recuperados y citas. Los usuarios finales solo ven los detalles apropiados a sus permisos.

Las imágenes y archivos se validan en servidor por contenido, tipo, tamaño y capacidad. Mostrar una vista previa y errores concretos. Los adjuntos de una conversación no se incorporan automáticamente a una colección persistente: el usuario autorizado debe solicitarlo o una regla explícita debe habilitarlo.

### 10.1 Transporte de chat Vue/Laravel

Implementar un composable Vue propio para consumir el formato de eventos de streaming elegido del Laravel AI SDK, documentado y probado. Mantener mensajes, estado, cancelación, errores, propuestas de herramientas y consumo. No exigir un SDK JavaScript de servidor. Si el SDK ofrece protocolos frontend alternativos, elegir uno explícitamente y verificar su versión; no mezclar sus eventos.

Conservar sesiones y CSRF en chat/adjuntos/audio. La carga del modelo, claves, herramientas y system prompt se resuelve en Laravel. Inertia gestiona navegación y formularios; el streaming usa una conexión HTTP adecuada al contrato.

### 10.2 Micrófono

Flujo inicial: permiso del navegador, grabar, detener, cargar audio, transcribir, mostrar texto editable y enviar al chat. Configurar proveedor/modelo de transcripción independientemente del modelo conversacional. Permitir whisper-1 cuando esté disponible en la conexión y otras alternativas soportadas por el adaptador.

Establecer duración y tamaño máximos; cancelar grabación y transcripción; permitir envío automático como preferencia explícita. Servir sobre HTTPS en producción y explicar la falta de permiso o compatibilidad del navegador. Validar el formato real de audio y convertirlo solo mediante una ruta de procesamiento controlada cuando sea necesario.

Whisper-1 se utiliza con una grabación completada; no asumir streaming de transcripción. La conversación de voz bidireccional en tiempo real requiere otra implementación y queda fuera de la V1.

No conservar audio por defecto después del procesamiento, salvo política explícita. La transcripción tiene costo independiente y comparte los controles de cuota y trazabilidad.

## 11. RAG opcional: modos y conexiones

RAG significa recuperar contenido autorizado de fuentes propias y utilizarlo como contexto para responder con evidencia. Debe poder activarse por instalación, organización y agente, sin impedir el uso normal del chat cuando esté desactivado.

| Modo | Almacenamiento/recuperación | Administración disponible |
| --- | --- | --- |
| Desactivado | Sin retrieval, sin embeddings nuevos ni indexación automática | Configuración y estado; datos previos conservados según política |
| Interno | PostgreSQL de la instalación, esquema RAG y pgvector | Pipeline y control completos |
| PostgreSQL externo | Otra conexión PostgreSQL con pgvector | Control completo si se otorgan permisos de administración |
| Retrieval externo por API | Servicio que cumple el contrato de búsqueda | Consultas y métricas locales; indexación según capacidades expuestas |

Interno/externo describe dónde se aloja el conocimiento; no significa hacer públicos los documentos. La base transaccional puede estar dentro de Docker o ser PostgreSQL administrado externamente, de forma independiente al modo RAG.

Cada conexión externa especifica TLS, credenciales, timeout, capacidad, alcance y estado. Nunca crear extensiones, esquemas ni índices en una base externa sin una activación administrativa explícita. En conexiones de solo lectura, las acciones de ingesta se ocultan y los cambios se gestionan en el sistema propietario.

El adaptador externo declara capacidades: query, listar colecciones, listar documentos, ingest, delete, jobs y métricas. La UI solo habilita operaciones disponibles. Un proveedor externo puede no exponer fragmentos, precios o estado de trabajos; identificarlos como no disponibles.

## 12. Preparación automatizada de PostgreSQL y pgvector

El instalador distingue la conexión transaccional Laravel (DB_CONNECTION=pgsql, DB_URL o variables DB_HOST/DB_DATABASE equivalentes) de la referencia de conexión RAG. Ofrecer tres ubicaciones para RAG: misma base con esquema separado, otra base del mismo servidor u otro servidor PostgreSQL.

Al habilitar RAG interno o PostgreSQL externo administrable:

1. Probar conectividad y TLS según entorno.
2. Verificar versión de PostgreSQL, disponibilidad de pgvector y permisos.
3. Aplicar migraciones RAG idempotentes mediante credencial de migración.
4. Ejecutar CREATE EXTENSION IF NOT EXISTS vector solo si está autorizado y es posible.
5. Preparar esquema, tablas e índices compatibles con el perfil de embeddings.
6. Ejecutar prueba de inserción y búsqueda en una colección diagnóstica aislada y limpiar la prueba.
7. Mostrar diagnóstico y habilitar el servicio únicamente si las verificaciones pasan.

El rol de ejecución no necesita privilegios de superusuario. Si no se puede instalar la extensión, mostrar el SQL y la instrucción para el administrador de PostgreSQL; no afirmar que RAG quedó operativo.

Deshabilitar RAG detiene nuevos trabajos y retrieval. No borra documentos ni tablas. Los trabajos ya activos deben alcanzar un punto seguro o cancelarse de forma cooperativa. La eliminación completa exige una acción separada y confirmada.

## 13. Colecciones, fuentes y documentos

### 13.1 Colecciones

Una colección agrupa conocimiento con ámbito, permisos, política de retención, idioma, conexión, perfil de embeddings, fragmentación y configuración de búsqueda. Puede asociarse a uno o varios agentes autorizados.

### 13.2 Fuentes iniciales

- Archivos TXT, Markdown y PDF con texto extraíble.
- URLs HTML y sitemap, con profundidad y cantidad máximas.
- Contenido interno mediante un contrato de adaptador que entregue IDs, texto, metadatos y ACL.
- Conector a un prefijo o conjunto de objetos S3 compatible, opcional.

DOCX, hojas de cálculo y OCR se agregan con parsers específicos y pruebas. Un PDF escaneado sin OCR no debe figurar como correctamente indexado si no produjo texto útil.

La lectura de URLs debe impedir SSRF: restringir destinos, validar DNS y redirecciones, bloquear redes privadas y endpoints de metadatos, establecer tiempos, tamaños y tipos permitidos. Las fuentes privadas autorizadas utilizan conectores específicos con lista permitida; no un fetch arbitrario.

### 13.3 Administración

Listado de fuentes con estado, última sincronización, próxima ejecución, documentos, fragmentos, tamaño, errores y costo. Permitir crear, editar, pausar, sincronizar, reintentar, reindexar y eliminar según permisos.

Detalle de documento con original autorizado, contenido extraído, hash, versión, metadatos, permisos, fragmentos, modelo de embeddings, estado y trabajos relacionados. Las vistas y descargas deben mantener el aislamiento del conocimiento.

Antes de indexar, mostrar una estimación de volumen y costo; para fuentes grandes, solicitar confirmación del presupuesto de ingesta.

## 14. Pipeline de indexación durable

Etapas: descubrir, descargar/leer, validar, extraer, normalizar, deduplicar, fragmentar, generar embeddings, persistir, publicar y verificar.

Cada trabajo conserva etapa, progreso conocido, intentos, error sanitizado, tiempos, unidades y costo. Estados: queued, running, retrying, paused, succeeded, failed y cancelled. Si no se conoce el total, mostrar progreso indeterminado.

Requisitos:

- Jobs persistentes en PostgreSQL, workers separados y recuperación tras reinicios.
- Reservas de jobs, locks, idempotencia y backoff para evitar procesamiento duplicado innecesario.
- Límites de concurrencia por proveedor y fuente; soporte de Retry-After.
- Sincronización incremental mediante hashes, ETag o marcas disponibles.
- Idempotencia de documentos y fragmentos por fuente, versión y perfil.
- Versionado de conjuntos indexados: publicar la nueva versión solo cuando esté completa.
- Mantener la última versión correcta disponible durante una reindexación.
- Incluir bajas detectadas en la sincronización; no conservar documentos eliminados como recuperables.
- Al borrar, retirar inmediatamente de las consultas, invalidar cachés y purgar originales/embeddings mediante un job trazable.
- Registrar trabajo parcial y costos ya incurridos aunque se cancele.

La planificación periódica se ejecuta mediante Laravel Scheduler con locks compartidos, tareas sin solapamiento y dispatch de jobs idempotentes, no con timers duplicados por réplica. La cola usa PostgreSQL, con intentos, backoff, failed jobs y cancelación cooperativa definidos en Laravel.

## 15. Embeddings, fragmentación y búsqueda

### 15.1 Perfiles de embeddings

Configurar conexión, modelo, dimensión, métrica, versión y normalización. El modelo de embeddings es independiente del modelo de chat.

Un perfil define un espacio vectorial. La consulta debe usar exactamente el perfil compatible con los documentos; ni siquiera dimensiones iguales garantizan compatibilidad entre modelos. Un cambio de perfil requiere reindexar en una nueva versión y luego cambiar el conjunto activo.

Elegir tipo vectorial e índice según dimensiones y límites reales de la versión de pgvector. No crear un índice HNSW incompatible ni truncar vectores para hacerlo funcionar. Separar tablas o particiones/perfiles con dimensión controlada y registrar su esquema; las decisiones exactas se validan con datos y EXPLAIN.

### 15.2 Fragmentación

Configurar tamaño objetivo, solapamiento, separadores y límites del modelo. Conservar página, sección, ruta y offsets para reconstruir citas. Proponer inicialmente fragmentación consciente de títulos y párrafos, con tamaño medido en tokens mediante tokenizador compatible o estimación identificada.

### 15.3 Retrieval

Permitir búsqueda vectorial, textual de PostgreSQL e híbrida con fusión documentada de rankings. Configurar top-k, presupuesto máximo de contexto, umbrales por método y reranking opcional. Las puntuaciones de similitud no equivalen a probabilidades ni son comparables entre todos los métodos.

Aplicar filtros de organización, colección, versión activa y ACL dentro de la recuperación, antes de enviar fragmentos al modelo. No recuperar todo y filtrar únicamente en la interfaz. Si se usa RLS, configurar el contexto por transacción y probarlo con el pool; el rol de aplicación no debe eludir las políticas.

Con índices aproximados, medir el efecto de filtros sobre cobertura. Usar estrategias compatibles con la versión instalada, ajustar parámetros o cambiar a búsqueda exacta para conjuntos pequeños cuando las mediciones lo justifiquen.

## 16. Respuestas con evidencia y laboratorio RAG

Flujo: validar acceso, resolver agente y colecciones, preparar consulta, recuperar fragmentos autorizados, construir contexto limitado, generar respuesta y validar referencias.

Cada cita debe apuntar a una fuente y fragmento recuperados, con título y ubicación cuando existan. Validar que los IDs citados pertenecen al conjunto autorizado; las citas no se inventan a partir del texto del modelo.

Configurar políticas por agente: exigir evidencia para consultas sobre conocimiento propio, admitir respuesta general claramente identificada o abstenerse cuando no haya contexto suficiente. La recuperación puede ejecutarse siempre o por decisión controlada; mostrar si realmente se utilizó RAG.

Los documentos se tratan como datos no confiables, nunca como instrucciones de sistema. Una instrucción dentro de un PDF no concede permisos ni habilita herramientas.

El laboratorio permite escribir una consulta, ver fragmentos, ranking, filtros, método, latencia y costo; comparar ajustes sobre un conjunto fijo de preguntas; y registrar evaluaciones humanas. Guardar trazas sin exponer contenido restringido a observadores sin permiso.

## 17. Dashboard y gráficos de conocimiento

| Visualización | Pregunta que responde |
| --- | --- |
| Documentos por estado y fuente | ¿Qué está listo, pendiente o fallando? |
| Ingesta por día | ¿Cuánto conocimiento se está incorporando? |
| Fragmentos y volumen por colección | ¿Dónde se concentra el contenido? |
| Cola y duración por etapa | ¿Dónde se atasca la indexación? |
| Errores por parser y proveedor | ¿Qué integración necesita atención? |
| Costo de embeddings e indexación | ¿Cuánto cuesta construir y mantener la base? |
| Consultas y latencias de retrieval | ¿Cómo responde el sistema bajo uso real? |
| Consultas sin resultados y abstenciones | ¿Qué conocimiento falta? |
| Citas y feedback por agente | ¿Qué evidencia usa y cómo la evalúan? |
| Antigüedad y cambios por fuente | ¿Qué contenido necesita actualizarse? |

Las métricas deben derivar de eventos reales y distinguir total, procesado y elegible. Separar indicadores operativos de calidad semántica. Para medir calidad, usar un dataset de preguntas con documentos relevantes, métricas como recall@k cuando haya etiquetas y revisión humana de respuestas/citas.

Ofrecer una proyección exploratoria de embeddings como ampliación opcional, etiquetada como aproximación visual; no usar proximidad en un gráfico 2D como prueba de calidad del retrieval.

## 18. Modelo de datos conceptual

Reutilizar identidad, guard y sesiones Laravel; no crear una segunda fuente de verdad de usuarios. Integrar tablas del paquete de roles con las Policies y el ámbito de organización elegido.

| Dominio | Entidades principales |
| --- | --- |
| Identidad | users, sessions, login_challenges, invitations |
| Autorización | roles, permissions, role_permissions y asignaciones |
| Espacios | organizations, memberships, settings |
| IA | provider_connections, model_catalog, model_capabilities |
| Tarifas | price_sources, model_prices, price_sync_runs |
| Agentes | agents, agent_versions, agent_collection_bindings |
| Chat | conversations, messages, attachments, tool_executions |
| Uso | ai_requests, ai_attempts, usage_events, cost_snapshots |
| Presupuestos | budgets, reservations, budget_alerts |
| RAG | rag_connections, collections, sources, documents, document_versions |
| Vectores | embedding_profiles, index_versions, chunks, embeddings |
| Evaluación | retrieval_runs, retrieval_hits, evaluation_sets, evaluation_results |
| Operación | jobs, job_batches, failed_jobs del driver Laravel y registros de ejecución propios, sync_runs y audit_events |

Agregar claves foráneas, índices para accesos reales, fechas UTC, organización/ámbito donde corresponda y políticas explícitas de borrado. Usar tipos monetarios decimales o unidades enteras de precisión definida; no floats binarios para acumular costos.

En PostgreSQL externo administrado por el starter, sincronizar de forma segura el ámbito y ACL mínimos necesarios. No depender de joins imposibles entre bases separadas. Para API externa, exigir que el adaptador implemente el aislamiento; si no puede garantizarlo, no habilitar colecciones privadas en esa conexión.

## 19. Rutas, API documentada y PostgREST

### 19.1 Rutas de aplicación y API JSON

Separar rutas web Inertia, API JSON /api/v1 y endpoints de streaming/audio. La sesión web y CSRF se configuran explícitamente; para consumidores externos, Sanctum y los permisos equivalentes. Las rutas siguientes son propuestas que se implementan con controladores Laravel, Form Requests, Actions y API Resources.

| Grupo | Operaciones |
| --- | --- |
| /login y /login/verify | Solicitar y verificar OTP |
| /admin/users, roles y permissions | Páginas y formularios Inertia funcionales |
| /api/v1/users, roles y permissions | Administración JSON autorizada |
| /api/v1/admin/action-proposals | Preparación, confirmación y ejecución de cambios sensibles |
| /api/v1/ai/connections | CRUD, prueba y diagnóstico de proveedor |
| /api/v1/ai/models y prices | Catálogo, capacidades, tarifas e historial |
| /api/v1/ai/agents | Borrador, prueba, publicación y versiones |
| /api/v1/ai/chat | Respuesta progresiva autenticada |
| /api/v1/ai/transcriptions | Audio grabado a texto |
| /api/v1/ai/usage y budgets | Consumo, alertas y presupuestos |
| /api/v1/rag/connections | Diagnóstico y activación |
| /api/v1/rag/collections, sources y documents | Conocimiento autorizado |
| /api/v1/rag/jobs | Progreso, cancelación y reintentos |
| /api/v1/rag/search, evaluate y analytics | Laboratorio y gráficos |
| /up, /health/live y /health/ready | Salud y dependencias requeridas |

Las sincronizaciones e ingestas responden con job_id. Los listados tienen paginación y límites; el servidor no confía en modelos, colecciones ni actores elegidos por el cliente.

RetrievalAdapter define search con consulta, ámbito, colecciones, filtros y límites, devolviendo hits con ID, texto, fuente, ubicación y tipo de puntuación. Operaciones de ingestión y métricas externas se declaran mediante capacidades adicionales.

### 19.2 OpenAPI autogenerado

Scramble genera OpenAPI a partir de las rutas, validaciones y respuestas Laravel compatibles. Añadir extensiones/atributos documentados para aquello que no pueda inferir. Evaluar y fijar una versión compatible con Laravel 13 antes de implementar; no afirmar que toda respuesta dinámica queda documentada sin intervención.

Cada operación JSON declara operationId estable, tag, propósito, entradas/salidas, ejemplos, errores, permisos y autenticación. Publicar openapi.json y una UI interactiva protegida por api.docs.read, o desactivada en producción. Generar también referencia Markdown y cliente TypeScript versionado para consumidores REST.

Documentar uploads, descargas, streams y transcripción con sus formatos reales. Las páginas Inertia se documentan como rutas/componentes/props y formularios; no fingir que son endpoints REST convencionales. El generador de módulos mantiene ambas referencias según lo que crea.

CI exporta y valida OpenAPI, detecta cambios incompatibles, regenera cliente/tipos y comprueba compilación. Las excepciones de inferencia se enumeran y tienen pruebas. Comandos objetivo propios: php artisan starter:api-docs, php artisan starter:contracts-check y pnpm api:client:generate; no son comandos existentes hasta implementarlos.

### 19.3 PostgREST activable y protegido

PostgREST no es PostgreSQL ni pgvector. Es un servicio adicional que publica una API sobre los objetos de PostgreSQL que se le permiten. El core de negocio, auth, herramientas administrativas y auditoría permanecen en Laravel.

Ofrecer un perfil Docker postgrest y feature flag independiente. Por defecto queda desactivado y privado. Exponer únicamente un esquema dedicado con vistas/RPC explícitas y roles mínimos; excluir sesiones, usuarios sensibles, OTP, claves, presupuestos y tablas internas de jobs.

La integración inicial recomendada es server-to-server desde Laravel para consultas/RPC permitidas, con identidad y ámbito propagados de forma verificable. Si se requiere acceso directo del cliente, diseñar un intercambio de sesión por JWT corto, con firma, claims, expiración, rol no privilegiado y RLS; una cookie Laravel no autentica automáticamente contra PostgREST.

No permitir que una escritura PostgREST evite Policies, eventos Eloquent o auditoría. Operaciones sensibles deben pasar por Laravel o una RPC controlada con invariantes y auditoría equivalentes probadas. Los filtros del navegador nunca son la única separación entre organizaciones.

Mantener una especificación independiente del contrato PostgREST generado/soportado por su versión y documentar cualquier conversión para publicarlo junto a OpenAPI de Laravel. No mezclar esquemas incompatibles sin validación. Tras migraciones, refrescar su caché de esquema según la versión y comprobar permisos/contrato.

## 20. Docker, servicios y persistencia

### 20.1 Servicios Compose

| Servicio | Función | Activación |
| --- | --- | --- |
| web | Nginx, assets y proxy a PHP-FPM | Siempre |
| app | Laravel 13 sobre PHP-FPM | Siempre |
| queue | Misma imagen Laravel, proceso queue:work | Siempre |
| scheduler | Misma imagen, schedule:work o cron operativo equivalente | Siempre |
| postgres | Datos, sesiones, caché/locks y cola, imagen compatible con pgvector | Perfil local-db |
| rag-postgres | Base vectorial separada opcional | Perfil rag-db |
| postgrest | API de esquema de datos permitido | Perfil postgrest |
| mailpit | Correo capturado de desarrollo | Perfil dev-mail |
| ssr | SSR Inertia Node, opcional | Perfil inertia-ssr |
| migrate | Comando Laravel de migración de una sola ejecución | Operación explícita |

La imagen app multi-stage instala PHP/extensiones —incluido pdo_pgsql— y Composer, compila assets con Node/pnpm y copia solo artefactos necesarios al runtime. Queue y scheduler reutilizan la imagen app con otro entrypoint. PostgreSQL externo elimina la necesidad de levantar el servicio local; Queue y sesiones siguen necesitando la base transaccional.

Usar Laravel Queue con driver database y colas separadas para correos, ingesta, sincronización y mantenimiento. Concurrencia, timeout, retry_after y reintentos deben evitar reentregas prematuras. Procesos de cola se reinician ordenadamente al desplegar una nueva versión. El scheduler usa exclusión mutua y locks compartidos para impedir tareas duplicadas entre réplicas.

Los perfiles Compose seleccionan infraestructura; los flags de Laravel controlan capacidades. Activar postgrest o rag-postgres no publica automáticamente datos o agentes. Validar todas las combinaciones sin depends_on obligatorios hacia servicios desactivados.

### 20.2 Operación y seguridad

- Versiones/digests fijos, procesos de aplicación no root y secretos en runtime.
- Volúmenes persistentes para PostgreSQL y storage privado local; storage/logs y bootstrap/cache con permisos correctos.
- APP_KEY persistente y protegida; su rotación requiere un procedimiento por datos cifrados y sesiones.
- No incrustar secretos en assets Vite, imagen o props; revisar qué configuración se serializa al ejecutar config:cache.
- Ejecutar migraciones una vez por despliegue con lock; seeders idempotentes sin recrear usuarios o borrar datos.
- Configurar caches Laravel con variables definitivas del despliegue, no con secretos temporales del build.
- Readiness de app, cola y conexiones requeridas; no exigir pgvector ni PostgREST si están apagados.
- Nginx sin buffering para streaming y timeouts alineados con PHP-FPM/proveedor.
- Cancelación del cliente y cierre de streams tratados explícitamente; no prometer que abortar HTTP elimina siempre un cobro ya iniciado.
- HTTPS y proxy confiable, CSRF, URL pública y dominio de sesión configurados.
- Base, Mailpit y PostgREST privados en producción, salvo publicación explícitamente diseñada.
- Backups/restauración de base, storage, configuración y claves; pruebas periódicas de recuperación.

Comandos objetivo del repositorio, pendientes de implementar:

```bash
# Entorno local completo
docker compose -f docker/compose.yaml -f docker/compose.dev.yaml --profile local-db --profile dev-mail up -d

# Migraciones de despliegue
docker compose -f docker/compose.yaml run --rm migrate

# Producción con PostgreSQL externo configurado
docker compose -f docker/compose.yaml -f docker/compose.production.yaml up -d web app queue scheduler
```

Incluir healthchecks, reintentos acotados y limpieza de entorno de pruebas. Verificar Docker Compose config y arranque de cada perfil soportado.

## 21. Configuración y feature flags

Cargar la configuración mediante config/starter.php y servicios de configuración persistida, con validación y versionado. Mantener las variables de despliegue en el entorno; las opciones editables del agente se resuelven por petición sin alterar globalmente config en workers de larga vida.

La configuración exportable no contiene claves, cadenas de conexión ni credenciales. Usar referencias a secretos y validar el documento con un esquema versionado.

Ejemplo conceptual:

```json
{
  "schemaVersion": 1,
  "project": { "name": "Mi proyecto", "locale": "es-CL" },
  "features": { "organizations": false, "ai": true, "rag": false, "postgrest": false },
  "auth": { "method": "email-otp", "registration": "invite-only" },
  "ai": {
    "connectionRef": "primary-ai",
    "agent": {
      "name": "Asistente",
      "systemPrompt": "Ayuda al usuario con las funciones autorizadas de esta aplicación.",
      "modelRef": null,
      "maxOutputTokens": 1200,
      "media": { "images": false, "documents": false },
      "microphone": {
        "enabled": false,
        "connectionRef": null,
        "modelRef": null,
        "maxSeconds": 60,
        "autoSend": false
      }
    },
    "usage": { "record": true, "dashboard": true }
  },
  "rag": {
    "mode": "disabled",
    "connectionRef": null,
    "embeddingProfileRef": null,
    "retrieval": { "strategy": "hybrid", "topK": 6 },
    "indexing": { "automatic": false, "concurrency": 2 }
  }
}
```

Un modelRef nulo significa pendiente de selección, no permiso para elegir un modelo arbitrario. La validación cruzada debe impedir publicar un agente con requisitos incompatibles o activar RAG sin conexión y perfil válidos. Los valores iniciales de límites son configurables y no garantías de costo.

## 22. Instalador y generador para agentes

Distribuir un instalador PHP/Composer y comandos Artisan dentro del monorepo. Nombre provisional: ecortes/laravel-starter-installer. No asumir que existe en Packagist. Debe poder instalarse de una release verificable y crear un proyecto desde una plantilla versionada.

Contrato objetivo de la CLI después de publicarse:

```bash
composer global require ecortes/laravel-starter-installer
laravel-starter new mi-proyecto --config=starter.config.json --no-interaction
```

El instalador soporta modo interactivo/no interactivo, validación JSON, dry-run, salida JSON y códigos de error estables. Comprueba PHP/extensiones, Composer, Node/pnpm y Docker según el modo; aplica nombre, marca y módulos; configura PostgreSQL interno/externo, pgvector, PostgREST y RAG; genera secretos locales; instala con locks; prepara propietario; levanta servicios autorizados; migra y ejecuta diagnóstico.

Recibir credenciales mediante variables o archivos privados, sin argumentos que se impriman ni secretos en JSON exportable. El modo no interactivo rechaza prerrequisitos incompletos y no otorga permisos externos por omisión.

Incluir comandos propios objetivo:

```text
php artisan starter:doctor
php artisan starter:config-validate
php artisan starter:ai-models-sync
php artisan starter:ai-prices-sync
php artisan starter:rag-doctor
php artisan starter:rag-reindex
php artisan starter:make-module
php artisan starter:api-docs
php artisan starter:verify
```

Las operaciones respetan ámbito, presupuesto y permisos del contexto operativo. Comandos de administración privilegiada requieren entorno y autorización de despliegue definidos; no se ofrecen como ejecución de shell libre al agente de chat.

El generador de módulos crea migración, modelo Eloquent, Factory, Policy, permisos, Action, Form Requests, controlador/rutas Inertia, páginas Vue, API Resource y rutas JSON cuando se pidan, documentación, tipos, pruebas y herramientas IA optativas. Incluir un módulo Proyectos con CRUD real como patrón completo.

El objetivo verificable es instalar y usar el proyecto sin conectar manualmente login, formularios, permisos, chat, colas y costos. Al faltar credenciales externas, el entorno local funciona con Mailpit y proveedor IA simulado claramente identificado.

## 23. Skills, Markdown y desarrollo asistido

AGENTS.md fija Laravel 13, Laravel AI SDK, Vue/Inertia, shadcn-vue, PostgreSQL/pgvector y Docker, además de límites entre paquetes, comandos, convenciones, autorización, costos, migraciones y verificaciones. No introducir otro stack por conveniencia del agente.

Skills distribuibles:

- install-starter: instalación, configuración, Docker y diagnóstico.
- extend-starter: módulo completo desde migración/Policy hasta Vue/Inertia y E2E.
- configure-ai: conexiones, modelos, tarifas, versiones de agente y pruebas.
- manage-rag: activación, ingesta, indexación, evaluación y mantenimiento.

Cada SKILL.md incluye entradas, prerrequisitos, pasos, archivos, comprobaciones y errores. Documentar su instalación en agentes compatibles; no prometer carga automática por estar presentes en el repo.

Laravel Boost puede ser una ayuda de desarrollo opcional si resulta compatible, sin convertirse en requisito de runtime ni reemplazar la documentación del starter.

Publicar Markdown versionado y llms.txt pequeño como índice. Generar referencia API desde el código y mantener ejemplos verificables. llms-full.txt es opcional; no debe contener secretos, documentos privados ni prompts de producción. CI valida enlaces, comandos y ejemplos.

Distinguir el agente de desarrollo que usa las skills para construir proyectos del agente de chat que administra la aplicación mediante herramientas. Ninguno recibe permisos de shell, base de datos o secretos por la mera presencia de una instrucción documental.

## 24. Seguridad, privacidad y trazabilidad

- Denegar por defecto permisos y acceso a recursos.
- Separar autorización de usuario y políticas técnicas del agente.
- Proteger secretos, redactar logs y limitar quién cambia proveedores y prompts.
- Validar archivos, URL y respuestas de servicios externos.
- Considerar texto recuperado, adjuntos y salida de herramientas como contenido no confiable.
- Aplicar límites efectivos a mensajes, tokens, pasos, cargas, audio y concurrencia.
- Establecer retención de conversaciones, originales, audio, trazas y eventos.
- Auditar cambios de roles, conexiones, tarifas, prompts, RAG y borrados.
- Invalidar cachés de retrieval y contenido cuando cambien ACL, versiones o bajas.
- Controlar descargas mediante autorización o URLs firmadas breves.
- Mantener correlación entre petición web, ejecución IA, job y evento de costo.

Los logs operativos no deben almacenar por defecto prompts completos ni contenido documental sensible. La inspección de contenido requiere permiso, retención y finalidad explícitos.

## 25. Pruebas y criterios de aceptación

### 25.1 Base funcional

- Una instalación limpia levanta Laravel, frontend Vue/Inertia, cola, scheduler, PostgreSQL y Mailpit.
- El usuario recibe un OTP, lo verifica y entra al panel; vencimiento, uso único y rate limit se aplican desde servidor.
- Sesión, CSRF, recarga, logout y revocación funcionan.
- CRUD de usuarios, roles y permisos opera desde formularios y herramientas de chat, con las mismas Policies.
- No hay autoescalamiento ni eliminación accidental del último propietario.
- El generador crea un módulo que compila, se autoriza, se documenta y pasa sus pruebas.

### 25.2 IA y costos

- Conexión, descubrimiento verificado, selección/prueba de modelo y publicación de agente funcionan.
- El chat muestra respuestas progresivas y registra versión, proveedor, uso y costo.
- El recomendador devuelve únicamente candidatos válidos y registra su propio consumo.
- Adjuntos incompatibles se rechazan; transcripción permite revisar/enviar texto y registra costo independiente.
- Una tarifa desconocida no se registra como gratuita y los cambios de tarifa no alteran historial.
- Presupuestos atómicos, reintentos, modelos de respaldo y ejecuciones interrumpidas quedan correctamente diferenciados.

### 25.3 RAG y PostgREST

- RAG apagado no exige pgvector operativo ni genera embeddings.
- La activación verifica permisos, extensión, esquema y perfil antes de declarar disponibilidad.
- Ingesta de Markdown, PDF con texto y URL permitida produce fragmentos buscables y citas válidas.
- Reindexación conserva la versión anterior hasta publicar la nueva; reinicios/reintentos no duplican documentos lógicos.
- Cambiar de espacio de embeddings requiere reindexación; bajas y ACL se aplican también a cachés.
- Conexiones internas, PostgreSQL externo y retrieval API cumplen su matriz de capacidades.
- PostgREST apagado no afecta al core; activado solo expone esquema/operaciones permitidos y mantiene el aislamiento.
- Gráficos representan eventos y documentos reales.

### 25.4 Estrategia de pruebas

Pest/PHPUnit cubre Actions, Policies, validadores, jobs, integraciones HTTP y eventos del SDK. Vitest cubre lógica frontend relevante. PostgreSQL y pgvector reales en contenedores para integración; no sustituirlos por SQLite para validar vectores, concurrencia o RLS.

Laravel AI SDK utiliza sus mecanismos de pruebas/fakes compatibles o un adaptador propio claramente separado. Mantener CI sin claves pagadas; pruebas de proveedor reales opt-in con presupuesto verifican conectividad, modalidades y tool calling reales.

### 25.5 End to end con Playwright

Navegar Vue/Inertia contra Laravel real, PostgreSQL/pgvector, worker, scheduler y Mailpit. Leer el OTP del correo capturado; no introducir bypass de autenticación en producción. Un proveedor IA determinista de test puede emitir llamadas a herramientas, pero debe recorrer el registro, la Policy y la Action reales.

| Flujo | Verificación |
| --- | --- |
| Instalación | Base limpia, migración, propietario, readiness y configuración |
| OTP/sesión | Entrega, código correcto/incorrecto/vencido, reenvío, logout y revocación |
| Panel | CRUD de usuario, roles y matriz de permisos con persistencia |
| Chat operador | Consulta, creación, edición, propuesta, confirmación/cancelación y resultado real |
| Seguridad | Usuario sin privilegios, nombre ambiguo, último propietario y propuesta caducada/repetida |
| IA | Conexiones, modelo, borrador/publicación, streaming, errores y límites |
| Audio/media | Audio fixture, transcripción y rechazos por tamaño/formato |
| Costos | Consumo persistido, tarifas desconocidas y reservas concurrentes |
| RAG | Fuente, job, fragmento, búsqueda, cita, reindexación, baja e aislamiento |
| PostgREST | Perfil desactivado/activado y lectura/RPC autorizada, sin bypass de políticas |
| Contratos | OpenAPI exportado, cliente generado y correspondencia con API real |
| Interfaz | Móvil, temas, navegación por teclado y componentes críticos |

Aislar datos por prueba/worker, usar fixtures y evitar sleeps arbitrarios. Chromium corre flujos críticos en cada PR; Firefox/WebKit en recorridos críticos programados y releases. Conservar reportes/traces de fallos con retención limitada y sin secretos. Audio fixture no sustituye una comprobación de micrófono real.

CI ejecuta Composer, análisis PHP, tests, frontend, contratos, Docker y E2E con limpieza incluso al fallar. Reintentos acotados y diagnóstico de pruebas inestables. Comandos objetivo: composer test, pnpm test:e2e, pnpm test:e2e:ui y php artisan starter:verify.

## 26. Plan de implementación

| Etapa | Resultado verificable |
| --- | --- |
| 1. Fundamentos | Monorepo Composer/pnpm, Laravel 13, Vue/Inertia, shadcn, PostgreSQL y Docker |
| 2. Identidad | OTP, sesiones, Policies, roles, permisos y panel terminado |
| 3. IA | Laravel AI SDK, conexiones, catálogo, tarifas y registro de consumo |
| 4. Experiencia | Agentes versionados, chat operador, multimedia y transcripción |
| 5. RAG interno | pgvector, fuentes, jobs, fragmentos, retrieval y citas |
| 6. Operación RAG | Reindexación, bajas, programación, evaluación y gráficos |
| 7. Integraciones | PostgreSQL externo, retrieval API y PostgREST opcional protegido |
| 8. Distribución | CLI, generador, OpenAPI, skills, E2E y releases actualizables |

Cada etapa entrega software funcional y pasa sus criterios. La V1 completa incluye las ocho etapas y las funciones acordadas; una maqueta no constituye una release terminada.

## 27. Entregables finales de implementación

1. Monorepo con código PHP/Vue, Composer y pnpm, locks y matriz de compatibilidad.
2. Aplicación Laravel 13 y frontend Vue/Inertia con login y panel shadcn-vue completos.
3. OTP, roles, permisos, sesiones y auditoría funcionando.
4. Laravel AI SDK configurado con proveedores, modelos, tarifas y agentes.
5. Chat flotante operador con herramientas de usuarios/roles/permisos y confirmaciones.
6. Transcripción opcional, multimedia y controles efectivos de consumo.
7. RAG interno y externo PostgreSQL/pgvector y adaptador retrieval API.
8. Fuentes, documentos, fragmentos, indexación, búsqueda, evaluaciones y gráficos.
9. PostgREST opcional con esquema y autorización delimitados.
10. Docker, cola, scheduler, backups y despliegue documentados.
11. CLI, generador, skills, AGENTS.md, Markdown y llms.txt.
12. OpenAPI autogenerado, cliente tipado y referencias del frontend.
13. Pruebas PHP/frontend/E2E y automatización de actualizaciones con CI.

## 28. Fuentes técnicas y verificación

Fuentes oficiales o de los mantenedores consultadas para orientar esta variante. El diseño, comandos propios, endpoints y valores iniciales son propuestas y deben validarse con las versiones instaladas. No se incluyen números de versiones menores que puedan quedar obsoletos al implementar.

- Laravel 13: https://laravel.com/docs/13.x/releases
- Starter Vue/Inertia/shadcn-vue: https://laravel.com/docs/13.x/starter-kits
- Laravel AI SDK: https://laravel.com/docs/13.x/ai-sdk
- Auth Laravel: https://laravel.com/docs/13.x/authentication
- Autorización: https://laravel.com/docs/13.x/authorization
- Queue: https://laravel.com/docs/13.x/queues
- Scheduler: https://laravel.com/docs/13.x/scheduling
- Sanctum: https://laravel.com/docs/13.x/sanctum
- Inertia: https://inertiajs.com/
- shadcn-vue: https://www.shadcn-vue.com/
- Spatie Laravel Permission: https://spatie.be/docs/laravel-permission
- Scramble OpenAPI: https://scramble.dedoc.co/
- PostgreSQL: https://www.postgresql.org/docs/current/
- pgvector: https://github.com/pgvector/pgvector
- PostgREST: https://docs.postgrest.org/en/stable/
- Docker profiles: https://docs.docker.com/compose/how-tos/profiles/
- pnpm workspaces: https://pnpm.io/workspaces
- Playwright: https://playwright.dev/docs/intro
- Renovate: https://docs.renovatebot.com/
- Node LTS: https://nodejs.org/en/about/previous-releases
- llms.txt: https://llmstxt.org/

## 29. Instrucción maestra para el agente implementador

Implementa esta especificación como un monorepo Composer/pnpm con Laravel 13, Laravel AI SDK (laravel/ai), Vue 3, Inertia, TypeScript, shadcn-vue, Tailwind, PostgreSQL, pgvector y Docker. Añade PostgREST como módulo activable con esquema limitado, sin desplazar la autorización y el dominio Laravel. Verifica versiones y documentación antes de instalar y conserva compatibilidad dentro de Laravel 13.

Usa el starter Vue oficial como referencia, pero entrega el flujo OTP por correo completo y probado. Mantén un solo sistema de identidad y sesión. Construye login y panel listos para usar, con roles, permisos, configuración IA, costos y administración del conocimiento.

El agente de chat debe ejecutar herramientas reales sobre las mismas Actions y Policies del panel para gestionar usuarios, roles y permisos; cambios sensibles requieren propuestas verificables y confirmación. Incluye transcripción opcional, multimedia, límites, tarifas trazables y chat flotante configurable.

Implementa RAG activable interno/externo con jobs Laravel durables, pgvector, fuentes, fragmentos, reindexación versionada, citas, evaluación, costos y gráficos. No finjas capacidades que un adaptador externo no expone.

Entrega documentación OpenAPI/Markdown generada, cliente tipado, instalador, generador de módulos, skills, AGENTS.md y llms.txt. Ejecuta pruebas PHP, integración PostgreSQL y Playwright E2E. Automatiza detección de actualizaciones y somete cualquier cambio a controles de compatibilidad antes de publicar. Distingue siempre software implementado, configuración pendiente, simulaciones y verificaciones reales.
