<?php

namespace Database\Seeders;

use App\Models\AiPrompt;
use Illuminate\Database\Seeder;

class AiSeeder extends Seeder
{
    /**
     * Seed the default system prompts.
     *
     * Connections are not seeded because they require API keys: add them
     * from Admin → AI → Connections.
     */
    public function run(): void
    {
        AiPrompt::query()->firstOrCreate(['slug' => 'assistant'], [
            'name' => 'Asistente general',
            'description' => 'Asistente por defecto del chat.',
            'content' => <<<'PROMPT'
                Eres el asistente de {{app_name}}. Responde de forma clara, precisa y concisa,
                en el mismo idioma que utilice el usuario. Usa Markdown cuando ayude a la lectura.

                Estás hablando con {{user_name}}. Fecha actual: {{date}}.
                PROMPT,
            'is_default' => true,
        ]);

        AiPrompt::query()->firstOrCreate(['slug' => 'translator'], [
            'name' => 'Traductor',
            'description' => 'Traduce textos entre español e inglés.',
            'content' => <<<'PROMPT'
                Eres un traductor profesional. Si el texto está en español, tradúcelo al inglés;
                si está en otro idioma, tradúcelo al español. Devuelve solo la traducción.
                PROMPT,
        ]);

        AiPrompt::query()->firstOrCreate(['slug' => 'summarizer'], [
            'name' => 'Resumidor',
            'description' => 'Resume textos largos en viñetas.',
            'content' => <<<'PROMPT'
                Resume el texto que te entregue el usuario en un máximo de 5 viñetas,
                conservando datos clave como cifras, fechas y nombres.
                PROMPT,
        ]);
    }
}
