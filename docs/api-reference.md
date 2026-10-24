# Referencia de la API

<!-- Generado por `php artisan starter:api-docs` desde tooling/contracts/openapi.json. No editar a mano. -->

**Laravel Starter API** · versión 1.0.0 · base `/api`

REST API secured with Laravel Sanctum. Create a token from Settings → API tokens or with the one-time code endpoints (POST /v1/auth/otp and /v1/auth/otp/verify) and send it as `Authorization: Bearer <token>`.

El contrato completo (para clientes y validación) está en `tooling/contracts/openapi.json` y el cliente tipado en `@starter/api-client`. Con la aplicación en marcha, `/docs/api` muestra la documentación interactiva.

## Índice

- [Authentication](#authentication) (5)
- [Users](#users) (6)
- [Roles and permissions](#roles-and-permissions) (6)
- [Invitations](#invitations) (4)
- [AI](#ai) (7)
- [AI connections and models](#ai-connections-and-models) (14)
- [AI agents](#ai-agents) (8)
- [AI catalog](#ai-catalog) (5)
- [AI usage and budgets](#ai-usage-and-budgets) (6)
- [Knowledge (RAG)](#knowledge-rag) (21)
- [Retrieval API (contract v1)](#retrieval-api-contract-v1) (4)
- [PostgREST](#postgrest) (1)
- [Transcription](#transcription) (1)

## Authentication

| Método | Ruta | Resumen |
| --- | --- | --- |
| `POST` | `/v1/auth/otp` | Request a one-time code |
| `POST` | `/v1/auth/otp/verify` | Exchange a one-time code for a token |
| `POST` | `/v1/auth/token` | Create a token with email and password |
| `GET` | `/v1/me` | Get the authenticated user |
| `POST` | `/v1/auth/logout` | Revoke the token used for the request |

### POST /v1/auth/otp

**Request a one-time code**

Always answers 202 with a `challenge_id`, whether the account exists or not, so the endpoint cannot be used to discover accounts. Send the challenge id with the code received by email to `/v1/auth/otp/verify`.

_Sin autenticación._

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `email` | string (email) | sí |  |

Respuestas: `202` · `422` Validation error.

### POST /v1/auth/otp/verify

**Exchange a one-time code for a token**

Requires the `api.tokens.create` permission. Users with two-factor authentication must also send `two_factor_code` or `recovery_code`.

_Sin autenticación._

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `challenge_id` | string (uuid) | sí |  |
| `code` | string | sí |  |
| `device_name` | string | sí | A name to identify the device or integration using the token. |
| `two_factor_code` | string \| null | no | Six digit code of the authenticator app (only when 2FA is enabled). |
| `recovery_code` | string \| null | no | Recovery code (only when 2FA is enabled). |
| `abilities` | `read` \| `write` \| `ai`[] | no |  |

Respuestas: `201` · `422` Validation error.

### POST /v1/auth/token

**Create a token with email and password**

Only available when the deployment enables passwords (STARTER_AUTH_METHOD=email-otp+password).

_Sin autenticación._

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `email` | string (email) | sí |  |
| `password` | string | sí |  |
| `device_name` | string | sí |  |
| `two_factor_code` | string \| null | no |  |
| `recovery_code` | string \| null | no |  |
| `abilities` | `read` \| `write` \| `ai`[] | no |  |

Respuestas: `201` · `404` Not found · `422` Validation error.

### GET /v1/me

**Get the authenticated user**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `401` Unauthenticated.

### POST /v1/auth/logout

**Revoke the token used for the request**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `401` Unauthenticated.

## Users

| Método | Ruta | Resumen |
| --- | --- | --- |
| `GET` | `/v1/users` | List users |
| `POST` | `/v1/users` | Create a user |
| `GET` | `/v1/users/{user}` | Get a user |
| `PATCH` | `/v1/users/{user}` | Update a user |
| `DELETE` | `/v1/users/{user}` | Delete a user |
| `PATCH` | `/v1/users/{user}/status` | Disable or enable a user |

### GET /v1/users

**List users**

Requires the `users.read` permission.

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `search` | query | string \| null | no | Search by name or email. |
| `role` | query | string \| null | no | Filter by role name. |
| `status` | query | `active` \| `disabled` \| null | no |  |
| `per_page` | query | integer \| null | no |  |

Respuestas: `200` Paginated set of `UserResource` · `403` Authorization error · `422` Validation error · `401` Unauthenticated.

### POST /v1/users

**Create a user**

Requires `users.create`; only roles the caller can grant are accepted. Users log in with one-time codes (passwords are only accepted when the deployment enables them).

_Requiere `Authorization: Bearer <token>`._

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `name` | string | sí |  |
| `email` | string (email) | sí |  |
| `password` | string | no |  |
| `locale` | `es` \| `en` \| null | no |  |
| `email_verified` | boolean | no |  |
| `roles` | string[] | no |  |

Respuestas: `201` `UserResource` · `401` Unauthenticated · `422` Validation error.

### GET /v1/users/{user}

**Get a user**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `user` | path | integer | sí | The user ID |

Respuestas: `200` `UserResource` · `403` Authorization error · `404` Not found · `401` Unauthenticated.

### PATCH /v1/users/{user}

**Update a user**

Requires `users.update`. Sending `roles` replaces the roles of the user (not allowed on your own account; the last owner keeps the owner role).

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `user` | path | integer | sí | The user ID |

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `name` | string | no |  |
| `email` | string (email) | no |  |
| `password` | string | no |  |
| `locale` | `es` \| `en` \| null | no |  |
| `email_verified` | boolean | no |  |
| `roles` | string[] | no |  |

Respuestas: `200` `UserResource` · `404` Not found · `401` Unauthenticated · `422` Validation error.

### DELETE /v1/users/{user}

**Delete a user**

Requires `users.delete`. Personal data is anonymized.

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `user` | path | integer | sí | The user ID |

Respuestas: `200` · `404` Not found · `401` Unauthenticated.

### PATCH /v1/users/{user}/status

**Disable or enable a user**

Requires `users.disable`. Disabling revokes the sessions and tokens.

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `user` | path | integer | sí | The user ID |

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `active` | boolean | sí |  |

Respuestas: `200` `UserResource` · `422` Validation error · `404` Not found · `401` Unauthenticated.

## Roles and permissions

| Método | Ruta | Resumen |
| --- | --- | --- |
| `GET` | `/v1/roles` | List roles with their permissions |
| `POST` | `/v1/roles` | Create a role |
| `GET` | `/v1/roles/{role}` | Get a role |
| `PATCH` | `/v1/roles/{role}` | Update a role |
| `DELETE` | `/v1/roles/{role}` | Delete a role |
| `GET` | `/v1/permissions` | List the permission catalog |

### GET /v1/roles

**List roles with their permissions**

Requires the `roles.read` permission.

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` Array of `RoleResource` · `403` Authorization error · `401` Unauthenticated.

### POST /v1/roles

**Create a role**

Requires `roles.manage`; you can only grant permissions you hold.

_Requiere `Authorization: Bearer <token>`._

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `name` | string | sí |  |
| `permissions` | `users.read` \| `users.create` \| `users.invite` \| `users.update` \| `users.disable` \| `users.delete` \| `roles.read` \| `roles.manage` \| `permissions.read` \| `permissions.manage` \| `settings.read` \| `settings.update` \| `ai.chat` \| `ai.providers.manage` \| `ai.models.manage` \| `ai.agents.manage` \| `ai.usage.read` \| `ai.budgets.manage` \| `rag.collections.read` \| `rag.collections.manage` \| `rag.sources.manage` \| `rag.documents.read` \| `rag.documents.delete` \| `rag.jobs.manage` \| `rag.playground.use` \| `rag.analytics.read` \| `audit.read` \| `api.docs.read` \| `api.tokens.create`[] | no |  |

Respuestas: `201` `RoleResource` · `401` Unauthenticated · `422` Validation error.

### GET /v1/roles/{role}

**Get a role**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `role` | path | integer | sí | The role ID |

Respuestas: `200` `RoleResource` · `403` Authorization error · `404` Not found · `401` Unauthenticated.

### PATCH /v1/roles/{role}

**Update a role**

Requires `roles.manage`. The owner role cannot be modified and system roles cannot be renamed.

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `role` | path | integer | sí | The role ID |

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `name` | string | sí |  |
| `permissions` | `users.read` \| `users.create` \| `users.invite` \| `users.update` \| `users.disable` \| `users.delete` \| `roles.read` \| `roles.manage` \| `permissions.read` \| `permissions.manage` \| `settings.read` \| `settings.update` \| `ai.chat` \| `ai.providers.manage` \| `ai.models.manage` \| `ai.agents.manage` \| `ai.usage.read` \| `ai.budgets.manage` \| `rag.collections.read` \| `rag.collections.manage` \| `rag.sources.manage` \| `rag.documents.read` \| `rag.documents.delete` \| `rag.jobs.manage` \| `rag.playground.use` \| `rag.analytics.read` \| `audit.read` \| `api.docs.read` \| `api.tokens.create`[] | no |  |

Respuestas: `200` `RoleResource` · `404` Not found · `401` Unauthenticated · `422` Validation error.

### DELETE /v1/roles/{role}

**Delete a role**

Requires `roles.manage`. System roles cannot be deleted.

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `role` | path | integer | sí | The role ID |

Respuestas: `200` · `404` Not found · `401` Unauthenticated.

### GET /v1/permissions

**List the permission catalog**

Requires the `permissions.read` permission.

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `403` An error · `401` Unauthenticated.

## Invitations

| Método | Ruta | Resumen |
| --- | --- | --- |
| `GET` | `/v1/invitations` | List invitations |
| `POST` | `/v1/invitations` | Invite a user |
| `POST` | `/v1/invitations/{invitation}/resend` | Resend an invitation with a new link |
| `DELETE` | `/v1/invitations/{invitation}` | Revoke an invitation |

### GET /v1/invitations

**List invitations**

Requires `users.invite` or `users.read`.

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `per_page` | query | integer \| null | no |  |

Respuestas: `200` Paginated set of `InvitationResource` · `403` Authorization error · `422` Validation error · `401` Unauthenticated.

### POST /v1/invitations

**Invite a user**

Requires `users.invite`; the role must be one you can grant. The link is emailed and expires after STARTER_INVITATION_TTL_DAYS days.

_Requiere `Authorization: Bearer <token>`._

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `email` | string (email) | sí |  |
| `role` | string | sí |  |

Respuestas: `201` `InvitationResource` · `401` Unauthenticated · `422` Validation error.

### POST /v1/invitations/{invitation}/resend

**Resend an invitation with a new link**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `invitation` | path | integer | sí | The invitation ID |

Respuestas: `200` `InvitationResource` · `404` Not found · `401` Unauthenticated.

### DELETE /v1/invitations/{invitation}

**Revoke an invitation**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `invitation` | path | integer | sí | The invitation ID |

Respuestas: `200` `InvitationResource` · `404` Not found · `401` Unauthenticated.

## AI

| Método | Ruta | Resumen |
| --- | --- | --- |
| `GET` | `/v1/ai/chat/agents` | List the agents available to the authenticated user through the API |
| `POST` | `/v1/ai/chat` | Send a message to the assistant |
| `GET` | `/v1/ai/me/usage` | Get the AI usage of the authenticated user for the current month |
| `GET` | `/v1/admin/action-proposals` | List your proposals, newest first |
| `GET` | `/v1/admin/action-proposals/{proposal}` | Show one of your proposals: target, before/after values and effects |
| `POST` | `/v1/admin/action-proposals/{proposal}/confirm` | Confirm a pending proposal and let the agent finish its answer |
| `POST` | `/v1/admin/action-proposals/{proposal}/reject` | Cancel a pending proposal. Nothing is changed |

### GET /v1/ai/chat/agents

**List the agents available to the authenticated user through the API**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `403` Authorization error · `401` Unauthenticated.

### POST /v1/ai/chat

**Send a message to the assistant**

Pass the returned `conversation_id` in the next request to keep the context. Requires the `ai.chat` permission and a token with the `ai` ability.

_Requiere `Authorization: Bearer <token>`._

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `message` | string | sí |  |
| `agent` | string \| null | no | Slug of the agent to use. Defaults to the default agent available to you. |
| `conversation_id` | string \| null | no | Conversation to continue. |
| `model_id` | integer \| null | no | Catalog model id (requires the `ai.models.manage` permission). |

Respuestas: `200` · `503` · `429` · `404` · `422` Validation error · `403` Authorization error · `401` Unauthenticated.

### GET /v1/ai/me/usage

**Get the AI usage of the authenticated user for the current month**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `403` Authorization error · `401` Unauthenticated.

### GET /v1/admin/action-proposals

**List your proposals, newest first**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `status` | query | object | no |  |
| `per_page` | query | integer \| null | no |  |

Respuestas: `200` · `422` Validation error · `403` Authorization error · `401` Unauthenticated.

### GET /v1/admin/action-proposals/{proposal}

**Show one of your proposals: target, before/after values and effects**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `proposal` | path | string | sí |  |

Respuestas: `200` · `403` Authorization error · `401` Unauthenticated.

### POST /v1/admin/action-proposals/{proposal}/confirm

**Confirm a pending proposal and let the agent finish its answer**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `proposal` | path | string | sí |  |

Respuestas: `200` · `503` · `429` · `404` · `409` · `403` Authorization error · `401` Unauthenticated.

### POST /v1/admin/action-proposals/{proposal}/reject

**Cancel a pending proposal. Nothing is changed**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `proposal` | path | string | sí |  |

Respuestas: `200` · `503` · `429` · `404` · `409` · `403` Authorization error · `401` Unauthenticated.

## AI connections and models

| Método | Ruta | Resumen |
| --- | --- | --- |
| `GET` | `/v1/ai/connections` | List connections |
| `POST` | `/v1/ai/connections` | Create a connection |
| `GET` | `/v1/ai/connections/{connection}` | Get a connection |
| `PATCH` | `/v1/ai/connections/{connection}` | Update a connection. Blank secrets keep the stored ones (rotation sets new values) |
| `DELETE` | `/v1/ai/connections/{connection}` | Delete a connection and its catalog (the usage ledger is kept) |
| `GET` | `/v1/ai/models` | Search the catalog by name, function, capability or connection |
| `POST` | `/v1/ai/models` | Import a model manually |
| `GET` | `/v1/ai/models/{model}` | Get a model with its capabilities and current prices |
| `PATCH` | `/v1/ai/models/{model}` | Update a model (enable, default, functions, limits, capabilities) |
| `GET` | `/v1/ai/models/{model}/prices` | Price history of a model (newest first) |
| `POST` | `/v1/ai/models/{model}/prices` | Set a manual price. A new version is created; recorded costs keep their price |
| `POST` | `/v1/ai/connections/{connection}/test` | Test a connection |
| `POST` | `/v1/ai/connections/{connection}/discover` | Discover the provider models into the catalog |
| `POST` | `/v1/ai/models/{model}/verify` | Verify a model with a minimal explicit call (recorded in the ledger) |

### GET /v1/ai/connections

**List connections**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `401` Unauthenticated.

### POST /v1/ai/connections

**Create a connection**

Use `credential_mode: env` with `secret_ref: env:OPENAI_API_KEY` to read the key from the deployment instead of storing it.

_Requiere `Authorization: Bearer <token>`._

Respuestas: `201` · `422` Validation error · `401` Unauthenticated.

### GET /v1/ai/connections/{connection}

**Get a connection**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `connection` | path | integer | sí | The connection ID |

Respuestas: `200` · `404` Not found · `401` Unauthenticated.

### PATCH /v1/ai/connections/{connection}

**Update a connection. Blank secrets keep the stored ones (rotation sets new values)**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `connection` | path | integer | sí | The connection ID |

Respuestas: `200` · `422` Validation error · `404` Not found · `401` Unauthenticated.

### DELETE /v1/ai/connections/{connection}

**Delete a connection and its catalog (the usage ledger is kept)**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `connection` | path | integer | sí | The connection ID |

Respuestas: `200` · `404` Not found · `401` Unauthenticated.

### GET /v1/ai/models

**Search the catalog by name, function, capability or connection**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `search` | query | string \| null | no |  |
| `connection_id` | query | integer \| null | no |  |
| `function` | query | `text` \| `embeddings` \| `image` \| `audio` \| `transcription` \| `reranking` \| null | no | text, embeddings, image, audio, transcription or reranking. |
| `capability` | query | object | no | Only models whose capability is verified or declared as supported. |
| `enabled` | query | boolean \| null | no |  |
| `per_page` | query | integer \| null | no |  |

Respuestas: `200` · `422` Validation error · `401` Unauthenticated.

### POST /v1/ai/models

**Import a model manually**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `201` · `422` Validation error · `404` Not found · `401` Unauthenticated.

### GET /v1/ai/models/{model}

**Get a model with its capabilities and current prices**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `model` | path | integer | sí | The model ID |

Respuestas: `200` · `404` Not found · `401` Unauthenticated.

### PATCH /v1/ai/models/{model}

**Update a model (enable, default, functions, limits, capabilities)**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `model` | path | integer | sí | The model ID |

Respuestas: `200` · `422` Validation error · `404` Not found · `401` Unauthenticated.

### GET /v1/ai/models/{model}/prices

**Price history of a model (newest first)**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `model` | path | integer | sí | The model ID |

Respuestas: `200` · `404` Not found · `401` Unauthenticated.

### POST /v1/ai/models/{model}/prices

**Set a manual price. A new version is created; recorded costs keep their price**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `model` | path | integer | sí | The model ID |

Respuestas: `200` · `422` Validation error · `404` Not found · `401` Unauthenticated.

### POST /v1/ai/connections/{connection}/test

**Test a connection**

Distinguishes `authentication`, `quota`, `rate_limit`, `network` and `model_not_found` failures.

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `connection` | path | integer | sí | The connection ID |

Respuestas: `200` · `404` Not found · `401` Unauthenticated.

### POST /v1/ai/connections/{connection}/discover

**Discover the provider models into the catalog**

New models start disabled and with unknown availability.

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `connection` | path | integer | sí | The connection ID |

Respuestas: `200` · `403` An error · `404` Not found · `401` Unauthenticated.

### POST /v1/ai/models/{model}/verify

**Verify a model with a minimal explicit call (recorded in the ledger)**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `model` | path | integer | sí | The model ID |

Respuestas: `200` · `404` Not found · `401` Unauthenticated.

## AI agents

| Método | Ruta | Resumen |
| --- | --- | --- |
| `GET` | `/v1/ai/agents` | List agents with their published version and draft |
| `POST` | `/v1/ai/agents` | Create an agent with a first draft (default settings when omitted) |
| `GET` | `/v1/ai/agents/{agent}` | Show an agent: published settings, draft, validation and history |
| `PATCH` | `/v1/ai/agents/{agent}` | Update the name, description and flags of an agent |
| `PUT` | `/v1/ai/agents/{agent}/draft` | Save the draft settings. The complete settings object is required (see the agent defaults in the published version) |
| `POST` | `/v1/ai/agents/{agent}/publish` | Publish the draft. Validation errors (unusable model, unsupported capabilities, unknown tools) block the publication |
| `POST` | `/v1/ai/agents/{agent}/versions/{version}/restore` | Restore a previous version: its settings are published as a new version |
| `POST` | `/v1/ai/agents/{agent}/test` | Try the draft (or the published version). Recorded as a test in the ledger, not remembered, and only read-only tools are offered |

### GET /v1/ai/agents

**List agents with their published version and draft**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `401` Unauthenticated.

### POST /v1/ai/agents

**Create an agent with a first draft (default settings when omitted)**

_Requiere `Authorization: Bearer <token>`._

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `name` | string | sí |  |
| `slug` | string | sí | Lowercase letters, numbers and dashes. |
| `description` | string \| null | no |  |

Respuestas: `201` · `422` Validation error · `401` Unauthenticated.

### GET /v1/ai/agents/{agent}

**Show an agent: published settings, draft, validation and history**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `agent` | path | integer | sí | The agent ID |

Respuestas: `200` · `404` Not found · `401` Unauthenticated.

### PATCH /v1/ai/agents/{agent}

**Update the name, description and flags of an agent**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `agent` | path | integer | sí | The agent ID |

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `name` | string | no |  |
| `description` | string \| null | no |  |
| `is_active` | boolean | no |  |
| `is_default` | boolean | no |  |

Respuestas: `200` · `422` Validation error · `404` Not found · `401` Unauthenticated.

### PUT /v1/ai/agents/{agent}/draft

**Save the draft settings. The complete settings object is required
(see the agent defaults in the published version)**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `agent` | path | integer | sí | The agent ID |

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `config` | object | sí |  |
| `notes` | string \| null | no |  |

Respuestas: `200` · `422` Validation error · `404` Not found · `401` Unauthenticated.

### POST /v1/ai/agents/{agent}/publish

**Publish the draft. Validation errors (unusable model, unsupported
capabilities, unknown tools) block the publication**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `agent` | path | integer | sí | The agent ID |

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `notes` | string \| null | no |  |

Respuestas: `200` · `422` Validation error · `404` Not found · `401` Unauthenticated.

### POST /v1/ai/agents/{agent}/versions/{version}/restore

**Restore a previous version: its settings are published as a new version**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `agent` | path | integer | sí | The agent ID |
| `version` | path | integer | sí | The version ID |

Respuestas: `200` · `422` Validation error · `404` Not found · `401` Unauthenticated.

### POST /v1/ai/agents/{agent}/test

**Try the draft (or the published version). Recorded as a test in the
ledger, not remembered, and only read-only tools are offered**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `agent` | path | integer | sí | The agent ID |

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `message` | string | sí |  |

Respuestas: `200` · `502` · `422` Validation error · `404` Not found · `401` Unauthenticated.

## AI catalog

| Método | Ruta | Resumen |
| --- | --- | --- |
| `GET` | `/v1/ai/lab-runs` | List the latest lab runs |
| `POST` | `/v1/ai/lab-runs` | Start a lab run. Tasks run in the queue; poll the run to follow them |
| `GET` | `/v1/ai/lab-runs/{run}` | Show a run with every result (output, error, latency, time to first token, tokens and cost) |
| `POST` | `/v1/ai/recommendations` | Recommend models for a use case |
| `POST` | `/v1/ai/lab-runs/{run}/cancel` | Cancel the pending tasks of a run |

### GET /v1/ai/lab-runs

**List the latest lab runs**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `403` An error · `401` Unauthenticated.

### POST /v1/ai/lab-runs

**Start a lab run. Tasks run in the queue; poll the run to follow them**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `202` · `422` Validation error · `401` Unauthenticated.

### GET /v1/ai/lab-runs/{run}

**Show a run with every result (output, error, latency, time to first
token, tokens and cost)**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `run` | path | integer | sí | The run ID |

Respuestas: `200` · `403` An error · `404` Not found · `401` Unauthenticated.

### POST /v1/ai/recommendations

**Recommend models for a use case**

Candidates are filtered by verifiable requirements and ranked deterministically; an evaluator model, when given, compares only those candidates and unknown ids are discarded. Its cost is recorded.

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `422` Validation error · `401` Unauthenticated.

### POST /v1/ai/lab-runs/{run}/cancel

**Cancel the pending tasks of a run**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `run` | path | integer | sí | The run ID |

Respuestas: `200` · `403` An error · `404` Not found · `401` Unauthenticated.

## AI usage and budgets

| Método | Ruta | Resumen |
| --- | --- | --- |
| `GET` | `/v1/ai/usage` | Usage summary of a date range |
| `GET` | `/v1/ai/usage/requests` | Ledger requests (newest first) |
| `GET` | `/v1/ai/budgets` | List budgets with the status of their current period |
| `POST` | `/v1/ai/budgets` | Create a budget |
| `PATCH` | `/v1/ai/budgets/{budget}` | Update a budget |
| `DELETE` | `/v1/ai/budgets/{budget}` | Delete a budget |

### GET /v1/ai/usage

**Usage summary of a date range**

`cost` sums calculated costs in USD; requests with unknown prices are counted in `unknown_cost_requests` and never added as zero.

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `from` | query | string \| null (date-time) | no |  |
| `to` | query | string \| null (date-time) | no |  |

Respuestas: `200` · `403` An error · `422` Validation error · `401` Unauthenticated.

### GET /v1/ai/usage/requests

**Ledger requests (newest first)**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `from` | query | string \| null (date-time) | no |  |
| `to` | query | string \| null (date-time) | no |  |
| `feature` | query | object | no |  |
| `user_id` | query | integer \| null | no |  |
| `per_page` | query | integer \| null | no |  |

Respuestas: `200` · `403` An error · `422` Validation error · `401` Unauthenticated.

### GET /v1/ai/budgets

**List budgets with the status of their current period**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `403` An error · `401` Unauthenticated.

### POST /v1/ai/budgets

**Create a budget**

Hard limits block calls whose estimated cost would exceed the limit (atomic reservation); soft limits only alert.

_Requiere `Authorization: Bearer <token>`._

Respuestas: `201` · `422` Validation error · `401` Unauthenticated.

### PATCH /v1/ai/budgets/{budget}

**Update a budget**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `budget` | path | integer | sí | The budget ID |

Respuestas: `200` · `422` Validation error · `404` Not found · `401` Unauthenticated.

### DELETE /v1/ai/budgets/{budget}

**Delete a budget**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `budget` | path | integer | sí | The budget ID |

Respuestas: `200` · `403` An error · `404` Not found · `401` Unauthenticated.

## Knowledge (RAG)

| Método | Ruta | Resumen |
| --- | --- | --- |
| `GET` | `/v1/rag/status` | Knowledge status: mode, connection, capabilities and diagnostics |
| `GET` | `/v1/rag/collections` | Collections you can see |
| `GET` | `/v1/rag/collections/{collection}` | A collection with its sources and index versions |
| `GET` | `/v1/rag/collections/{collection}/documents` | Documents of a collection |
| `GET` | `/v1/rag/documents/{document}` | A document with its chunks in the active index version |
| `DELETE` | `/v1/rag/documents/{document}` | Delete a document: it leaves the searches at once and a job purges it |
| `GET` | `/v1/rag/jobs` | Indexing jobs (latest first) |
| `GET` | `/v1/rag/jobs/{job}` | Progress of a job: stage, known progress, units, timings and cost |
| `POST` | `/v1/rag/search` | Search the authorized knowledge |
| `GET` | `/v1/rag/analytics` | Knowledge analytics derived from real events: documents by status, ingestion, queue and stage durations, errors, embedding and query costs, retrieval latencies, outcomes, citations, feedback, freshness and the latest evaluations. Requires `rag.analytics.read` |
| `GET` | `/v1/rag/evaluation-sets` | Evaluation sets with their latest run |
| `GET` | `/v1/rag/evaluations/{run}` | An evaluation run with its metrics and per question results |
| `GET` | `/v1/rag/connections` | Knowledge connections (internal, external PostgreSQL and retrieval APIs) with their capabilities and last diagnostics. Credentials are never returned. Requires `settings.update` |
| `POST` | `/v1/rag/collections/{collection}/sources` | Create a source and start its sync |
| `POST` | `/v1/rag/sources/{source}/sync` | Start a sync of a source (an open sync is returned instead of a new one) |
| `POST` | `/v1/rag/jobs/{job}/cancel` | Request the cooperative cancellation of a job |
| `POST` | `/v1/rag/jobs/{job}/retry` | Retry a failed or cancelled job |
| `POST` | `/v1/rag/jobs/{job}/resume` | Resume a paused ingestion with a confirmed maximum cost (`max_cost`, USD) |
| `POST` | `/v1/rag/evaluation-sets/{set}/evaluate` | Evaluate a set with retrieval settings (method, top_k, min_score, max_context_tokens). Responds 202 with the run id; metrics: recall@k, MRR, hit rate, no result rate, latency and cost |
| `POST` | `/v1/rag/connections/{connection}/diagnose` | Run the diagnostics of a connection. Nothing is created or written on an external database |
| `POST` | `/v1/rag/connections/{connection}/activate` | Activate knowledge on a connection: diagnostics, schema provisioning (managed and writable connections only) and the mode switch, only if every check passes. Responds 422 with the report otherwise |

### GET /v1/rag/status

**Knowledge status: mode, connection, capabilities and diagnostics**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `403` Authorization error · `401` Unauthenticated.

### GET /v1/rag/collections

**Collections you can see**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `403` Authorization error · `401` Unauthenticated.

### GET /v1/rag/collections/{collection}

**A collection with its sources and index versions**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `collection` | path | integer | sí | The collection ID |

Respuestas: `200` · `403` An error · `404` Not found · `401` Unauthenticated.

### GET /v1/rag/collections/{collection}/documents

**Documents of a collection**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `collection` | path | integer | sí | The collection ID |
| `status` | query | string \| null | no | pending, indexing, indexed, failed, empty, duplicate or deleted. |
| `source_id` | query | integer \| null | no |  |
| `per_page` | query | integer \| null | no |  |

Respuestas: `200` · `403` An error · `422` Validation error · `404` Not found · `401` Unauthenticated.

### GET /v1/rag/documents/{document}

**A document with its chunks in the active index version**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `document` | path | integer | sí | The document ID |

Respuestas: `200` · `403` An error · `404` Not found · `401` Unauthenticated.

### DELETE /v1/rag/documents/{document}

**Delete a document: it leaves the searches at once and a job purges it**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `document` | path | integer | sí | The document ID |

Respuestas: `202` · `404` Not found · `401` Unauthenticated.

### GET /v1/rag/jobs

**Indexing jobs (latest first)**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `collection_id` | query | integer \| null | no |  |
| `status` | query | string \| null | no | queued, running, retrying, paused, succeeded, failed or cancelled. |
| `limit` | query | integer \| null | no |  |

Respuestas: `200` · `403` Authorization error · `422` Validation error · `401` Unauthenticated.

### GET /v1/rag/jobs/{job}

**Progress of a job: stage, known progress, units, timings and cost**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `job` | path | string (uuid) | sí | The job ID |

Respuestas: `200` · `403` Authorization error · `404` Not found · `401` Unauthenticated.

### POST /v1/rag/search

**Search the authorized knowledge**

Returns the ranked passages (only from collections and documents your access lists allow), the method, latency and the cost of embedding the query (null when its price is unknown). Scores are rankings within a method (cosine similarity, text rank or reciprocal rank fusion), not probabilities. Requires `rag.playground.use`.

_Requiere `Authorization: Bearer <token>`._

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `query` | string | sí |  |
| `collection_ids` | integer[] \| null | no | Collections to search (default: every collection you can access). |
| `method` | object | no |  |
| `top_k` | integer \| null | no |  |
| `min_score` | number \| null | no |  |
| `min_text_rank` | number \| null | no |  |
| `max_context_tokens` | integer \| null | no |  |
| `source_ids` | integer[] \| null | no |  |
| `exact` | boolean \| null | no | Force exact (true) or approximate (false) vector search. |
| `principals` | string[] \| null | no | Only return what these principals may also read (a service searching for its own end user); never widens your access. |

Respuestas: `200` · `503` · `401` Unauthenticated.

### GET /v1/rag/analytics

**Knowledge analytics derived from real events: documents by status,
ingestion, queue and stage durations, errors, embedding and query
costs, retrieval latencies, outcomes, citations, feedback, freshness
and the latest evaluations. Requires `rag.analytics.read`**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `days` | query | `7` \| `30` \| `90` \| null | no | Window in days: 7, 30 or 90. |
| `collection_id` | query | integer \| null | no |  |

Respuestas: `200` · `403` Authorization error · `422` Validation error · `401` Unauthenticated.

### GET /v1/rag/evaluation-sets

**Evaluation sets with their latest run**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `403` Authorization error · `401` Unauthenticated.

### GET /v1/rag/evaluations/{run}

**An evaluation run with its metrics and per question results**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `run` | path | string (uuid) | sí | The run ID |

Respuestas: `200` · `403` Authorization error · `404` Not found · `401` Unauthenticated.

### GET /v1/rag/connections

**Knowledge connections (internal, external PostgreSQL and retrieval
APIs) with their capabilities and last diagnostics. Credentials are
never returned. Requires `settings.update`**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `403` Authorization error · `401` Unauthenticated.

### POST /v1/rag/collections/{collection}/sources

**Create a source and start its sync**

Types url, sitemap, adapter and disk take a JSON `config`; files are uploaded with multipart/form-data (`files[]`). Responds 202 with the sync `job_id`.

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `collection` | path | integer | sí | The collection ID |

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `type` | `upload` \| `url` \| `sitemap` \| `adapter` \| `disk` | sí |  |
| `name` | string | sí |  |
| `config` | string[] \| null | no |  |
| `acl` | string[] \| null | no |  |
| `schedule` | `hourly` \| `daily` \| `weekly` \| null | no | Automatic sync: hourly, daily or weekly (empty = manual). |

Respuestas: `202` · `503` · `404` Not found · `401` Unauthenticated.

### POST /v1/rag/sources/{source}/sync

**Start a sync of a source (an open sync is returned instead of a new one)**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `source` | path | integer | sí | The source ID |

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `force` | boolean | no |  |

Respuestas: `202` · `503` · `404` Not found · `401` Unauthenticated.

### POST /v1/rag/jobs/{job}/cancel

**Request the cooperative cancellation of a job**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `job` | path | string (uuid) | sí | The job ID |

Respuestas: `200` · `404` Not found · `401` Unauthenticated.

### POST /v1/rag/jobs/{job}/retry

**Retry a failed or cancelled job**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `job` | path | string (uuid) | sí | The job ID |

Respuestas: `202` · `404` Not found · `401` Unauthenticated.

### POST /v1/rag/jobs/{job}/resume

**Resume a paused ingestion with a confirmed maximum cost (`max_cost`, USD)**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `job` | path | string (uuid) | sí | The job ID |

Respuestas: `202` · `404` Not found · `401` Unauthenticated.

### POST /v1/rag/evaluation-sets/{set}/evaluate

**Evaluate a set with retrieval settings (method, top_k, min_score,
max_context_tokens). Responds 202 with the run id; metrics: recall@k,
MRR, hit rate, no result rate, latency and cost**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `set` | path | integer | sí | The set ID |

Respuestas: `202` · `404` Not found · `401` Unauthenticated.

### POST /v1/rag/connections/{connection}/diagnose

**Run the diagnostics of a connection. Nothing is created or written
on an external database**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `connection` | path | integer | sí | The connection ID |

Respuestas: `200` · `403` Authorization error · `404` Not found · `401` Unauthenticated.

### POST /v1/rag/connections/{connection}/activate

**Activate knowledge on a connection: diagnostics, schema provisioning
(managed and writable connections only) and the mode switch, only if
every check passes. Responds 422 with the report otherwise**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `connection` | path | integer | sí | The connection ID |

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `create_extension` | boolean | no | Try CREATE EXTENSION vector (requires a privileged role). |

Respuestas: `200` · `403` Authorization error · `422` Validation error · `404` Not found · `401` Unauthenticated.

## Retrieval API (contract v1)

| Método | Ruta | Resumen |
| --- | --- | --- |
| `GET` | `/retrieval/v1/capabilities` | Contract version and capabilities |
| `GET` | `/retrieval/v1/collections` | Collections of this installation the token user can search (remote collections are never re-exported) |
| `GET` | `/retrieval/v1/collections/{collection}/documents` | Indexed documents of a collection the token user can read (50 per page) |
| `POST` | `/retrieval/v1/search` | Search with the same rules as `POST /api/v1/rag/search`: the access lists of the token user apply inside the query, and `principals` (the end user of the calling installation) must also match |

### GET /retrieval/v1/capabilities

**Contract version and capabilities**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `403` Authorization error · `401` Unauthenticated.

### GET /retrieval/v1/collections

**Collections of this installation the token user can search (remote
collections are never re-exported)**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `403` Authorization error · `401` Unauthenticated.

### GET /retrieval/v1/collections/{collection}/documents

**Indexed documents of a collection the token user can read (50 per page)**

_Requiere `Authorization: Bearer <token>`._

| Parámetro | En | Tipo | Requerido | Descripción |
| --- | --- | --- | --- | --- |
| `collection` | path | string | sí |  |

Respuestas: `200` · `403` Authorization error · `404` Not found · `401` Unauthenticated.

### POST /retrieval/v1/search

**Search with the same rules as `POST /api/v1/rag/search`: the access
lists of the token user apply inside the query, and `principals`
(the end user of the calling installation) must also match**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `422` Validation error · `401` Unauthenticated.

## PostgREST

| Método | Ruta | Resumen |
| --- | --- | --- |
| `POST` | `/v1/postgrest/token` | Exchange the session or token for a short PostgREST JWT (direct client access). Only when POSTGREST_DIRECT_TOKENS=true; the JWT carries the role of the published objects and the principals of the user, and expires in POSTGREST_JWT_TTL seconds |

### POST /v1/postgrest/token

**Exchange the session or token for a short PostgREST JWT (direct
client access). Only when POSTGREST_DIRECT_TOKENS=true; the JWT
carries the role of the published objects and the principals of the
user, and expires in POSTGREST_JWT_TTL seconds**

_Requiere `Authorization: Bearer <token>`._

Respuestas: `200` · `404` Not found · `401` Unauthenticated.

## Transcription

| Método | Ruta | Resumen |
| --- | --- | --- |
| `POST` | `/v1/ai/transcriptions` |  |

### POST /v1/ai/transcriptions

_Requiere `Authorization: Bearer <token>`._

Cuerpo (JSON):

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `agent_id` | string \| null | no |  |
| `surface` | `page` \| `floating` \| `api` \| null | no |  |

Respuestas: `200` · `502` · `503` · `429` · `422` Validation error · `404` · `403` Authorization error · `401` Unauthenticated.
