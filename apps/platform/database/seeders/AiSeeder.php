<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Starter\Ai\Agents\AgentConfig;
use Starter\Ai\Agents\AgentManager;
use Starter\Ai\Agents\ToolRegistry;
use Starter\Ai\Enums\Driver;
use Starter\Ai\Models\Agent;
use Starter\Ai\Models\Connection;
use Starter\Ai\Simulated\SimulatedCatalog;

class AiSeeder extends Seeder
{
    /**
     * Seed the initial agents. Outside production (STARTER_AI_SIMULATED) the
     * simulated provider is installed so the chat works without API keys;
     * real connections are added from Admin → AI → Connections.
     */
    public function run(AgentManager $agents, ToolRegistry $tools, SimulatedCatalog $simulated): void
    {
        // Installed on an empty catalog, and updated (new simulated models)
        // when it is already there; idempotent...
        if (config('starter.ai.simulated') && (! Connection::query()->exists() || Connection::query()->where('driver', Driver::Simulated->value)->exists())) {
            $simulated->install();
        }

        $defaults = AgentConfig::defaults();

        $this->agent($agents, ['slug' => 'assistant', 'name' => 'Asistente', 'description' => 'Asistente y operador de la aplicación.'], [
            'identity' => ['avatar' => '✨', 'greeting' => '¡Hola! ¿En qué te ayudo hoy?', 'language' => 'auto', 'tone' => 'neutral'],
            'instructions' => [
                'system_prompt' => <<<'PROMPT'
                    Eres el asistente de {{app_name}}. Responde de forma clara, precisa y concisa,
                    en el mismo idioma que utilice el usuario. Usa Markdown cuando ayude a la lectura.

                    Estás hablando con {{user_name}} (roles: {{user_roles}}). Fecha actual: {{date}}.

                    Cuando el usuario pida consultar o administrar usuarios, roles o permisos, usa las
                    herramientas disponibles: busca primero a las personas antes de modificarlas, nunca
                    inventes correos ni identificadores y, si hay varias coincidencias, pide que elija.
                    Las acciones sensibles quedan como propuesta hasta que el usuario las confirme: no
                    afirmes que se ejecutaron hasta recibir el resultado de la herramienta.
                    PROMPT,
                'variables' => ['app_name', 'user_name', 'user_roles', 'date'],
            ],
            'tools' => [...$defaults['tools'], 'allowed' => $tools->names()],
        ], default: true);

        $this->agent($agents, ['slug' => 'translator', 'name' => 'Traductor', 'description' => 'Traduce textos entre español e inglés.'], [
            'identity' => ['avatar' => '🌐', 'greeting' => 'Pega el texto que quieres traducir.', 'language' => 'auto', 'tone' => 'neutral'],
            'instructions' => [
                'system_prompt' => <<<'PROMPT'
                    Eres un traductor profesional. Si el texto está en español, tradúcelo al inglés;
                    si está en otro idioma, tradúcelo al español. Devuelve solo la traducción.
                    PROMPT,
                'variables' => [],
            ],
            'context' => ['history_messages' => 6, 'retention_days' => null],
        ]);

        $this->agent($agents, ['slug' => 'summarizer', 'name' => 'Resumidor', 'description' => 'Resume textos largos en viñetas.'], [
            'identity' => ['avatar' => '📝', 'greeting' => 'Pega el texto que quieres resumir.', 'language' => 'auto', 'tone' => 'concise'],
            'instructions' => [
                'system_prompt' => <<<'PROMPT'
                    Resume el texto que te entregue el usuario en un máximo de 5 viñetas,
                    conservando datos clave como cifras, fechas y nombres.
                    PROMPT,
                'variables' => [],
            ],
            'context' => ['history_messages' => 6, 'retention_days' => null],
        ]);
    }

    /**
     * Create and publish an agent unless it already exists.
     *
     * @param  array{slug: string, name: string, description: string}  $attributes
     * @param  array<string, mixed>  $config
     */
    protected function agent(AgentManager $agents, array $attributes, array $config, bool $default = false): void
    {
        if (Agent::query()->where('slug', $attributes['slug'])->exists()) {
            return;
        }

        $agent = $agents->create([...$attributes, 'is_default' => $default], $config);

        $agents->publish($agent, 'Versión inicial');
    }
}
