# Skills y desarrollo asistido

El repositorio distribuye cinco skills (formato `SKILL.md` con metadatos `name` y
`description`) para agentes de desarrollo compatibles:

| Skill | Para qué |
| --- | --- |
| [`install-starter`](../skills/install-starter/SKILL.md) | instalar con `laravel-starter`, configurar, Docker y diagnóstico |
| [`extend-starter`](../skills/extend-starter/SKILL.md) | módulo completo con `starter:make-module`, desde la migración y la Policy hasta Vue/Inertia y E2E |
| [`build-ui`](../skills/build-ui/SKILL.md) | pantallas con el kit de UI: listados, formularios, wizards, paneles con gráficos, modales y menús |
| [`configure-ai`](../skills/configure-ai/SKILL.md) | conexiones, modelos, tarifas, presupuestos, versiones de agentes y pruebas |
| [`manage-rag`](../skills/manage-rag/SKILL.md) | activación, ingesta, indexación, evaluación y mantenimiento del conocimiento |

Cada una indica entradas, prerrequisitos, pasos, archivos, comprobaciones y errores frecuentes.

## Instalación en agentes

Cada herramienta busca las skills en su propio directorio:

- **Claude Code**: el repositorio ya trae `.claude/skills/<nombre>` como enlaces simbólicos a
  `skills/<nombre>` y un `CLAUDE.md` que importa `AGENTS.md`, así que al abrir el proyecto
  carga las reglas y las skills sin pasos extra. Al agregar una skill, crea su enlace (una
  prueba lo exige):

  ```bash
  ln -s ../../skills/mi-skill .claude/skills/mi-skill
  ```

  En Windows los enlaces requieren `git config core.symlinks true` (modo desarrollador); sin
  ellos, `AGENTS.md` sigue indicando qué `SKILL.md` leer.

- **Otros agentes con soporte de skills/`SKILL.md`**: copia las carpetas en el directorio que
  indique su documentación.
- **Agentes sin skills**: `AGENTS.md` (que Codex, Cursor, Copilot y otros leen al iniciar;
  `GEMINI.md` lo importa para Gemini CLI) trae la tabla «Qué leer según la tarea» con el
  `SKILL.md` de cada caso; `llms.txt` resume toda la documentación.

Laravel Boost puede usarse como ayuda opcional de desarrollo; no es requisito del runtime ni
reemplaza esta documentación.

## Dos agentes distintos

- El **agente de desarrollo** (Claude Code u otro) usa estas skills para construir o modificar
  el proyecto con las herramientas de su entorno.
- El **agente del chat** de la aplicación administra datos mediante herramientas registradas
  (`ToolRegistry`) que llaman a las mismas Actions y Policies que el panel, con propuestas y
  confirmación para cambios sensibles.

Ninguno recibe permisos de shell, base de datos o secretos por la mera presencia de una
instrucción en un documento: los permisos los define el entorno de cada agente y, en la
aplicación, los roles del usuario.
