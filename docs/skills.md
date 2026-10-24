# Skills y desarrollo asistido

El repositorio distribuye cuatro skills (formato `SKILL.md` con metadatos `name` y
`description`) para agentes de desarrollo compatibles:

| Skill | Para qué |
| --- | --- |
| [`install-starter`](../skills/install-starter/SKILL.md) | instalar con `laravel-starter`, configurar, Docker y diagnóstico |
| [`extend-starter`](../skills/extend-starter/SKILL.md) | módulo completo con `starter:make-module`, desde la migración y la Policy hasta Vue/Inertia y E2E |
| [`configure-ai`](../skills/configure-ai/SKILL.md) | conexiones, modelos, tarifas, presupuestos, versiones de agentes y pruebas |
| [`manage-rag`](../skills/manage-rag/SKILL.md) | activación, ingesta, indexación, evaluación y mantenimiento del conocimiento |

Cada una indica entradas, prerrequisitos, pasos, archivos, comprobaciones y errores frecuentes.

## Instalación en agentes

Que las skills estén en el repositorio **no** hace que un agente las cargue: hay que
instalarlas donde cada herramienta las busca.

- **Claude Code**: copia o enlaza cada carpeta en `.claude/skills/` del proyecto (o en
  `~/.claude/skills/` para todos tus proyectos):

  ```bash
  mkdir -p .claude/skills
  for skill in skills/*/; do ln -s "../../$skill" ".claude/skills/$(basename "$skill")"; done
  ```

- **Otros agentes con soporte de skills/`SKILL.md`**: copia las carpetas en el directorio que
  indique su documentación.
- **Agentes sin skills**: `AGENTS.md` y `llms.txt` resumen las reglas y enlazan esta
  documentación; puedes pedirle al agente que lea el `SKILL.md` correspondiente.

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
