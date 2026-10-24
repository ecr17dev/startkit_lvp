<?php

use Starter\Ai\Agents\ConfiguredAgent;
use Starter\Ai\Agents\PingAgent;
use Starter\Ai\Agents\TitleAgent;

/*
|--------------------------------------------------------------------------
| Starter kit configuration
|--------------------------------------------------------------------------
|
| Deployment level options live in the environment (.env). Options that an
| administrator can change at runtime are persisted in the "settings" table
| (Admin → Settings) and override the defaults declared below.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flags
    |--------------------------------------------------------------------------
    |
    | Compose profiles select infrastructure; these flags enable capabilities.
    | Turning a flag off never deletes data.
    |
    */

    'features' => [
        'organizations' => (bool) env('STARTER_FEATURE_ORGANIZATIONS', false),
        'ai' => (bool) env('STARTER_FEATURE_AI', true),
        'rag' => (bool) env('STARTER_FEATURE_RAG', false),
        'postgrest' => (bool) env('STARTER_FEATURE_POSTGREST', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | method: "email-otp" (one-time codes and passkeys) or "email-otp+password"
    | (also password login, password resets and password confirmation).
    | registration: initial mode, "open", "invite-only" or "closed"; it is
    | changed afterwards from Admin → Settings → Authentication.
    |
    */

    'auth' => [
        'method' => env('STARTER_AUTH_METHOD', 'email-otp'),
        'registration' => env('STARTER_REGISTRATION', 'invite-only'),
        'otp' => [
            // HMAC secret of the code verifiers (defaults to APP_KEY)...
            'secret' => env('STARTER_OTP_SECRET'),
            'length' => (int) env('STARTER_OTP_LENGTH', 6),
            'ttl' => (int) env('STARTER_OTP_TTL', 5),
            'max_attempts' => (int) env('STARTER_OTP_MAX_ATTEMPTS', 5),
            'resend_cooldown' => (int) env('STARTER_OTP_RESEND_COOLDOWN', 60),
        ],
        'invitation_ttl_days' => (int) env('STARTER_INVITATION_TTL_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Artificial intelligence
    |--------------------------------------------------------------------------
    |
    | secret_refs: environment variables that connections may reference as
    | "env:NAME" instead of storing the key (names starting with
    | STARTER_AI_SECRET_ are also allowed). Application secrets can never be
    | referenced. agent_features: ledger feature of agents called directly
    | through the SDK (calls made with AiGateway declare it explicitly).
    | simulated: the seeder installs the simulated provider (no network,
    | clearly labelled) so a local installation works without credentials.
    | proposal_ttl_minutes: time to confirm a change prepared by an agent.
    | generate_titles: name new conversations with the model (ledger: title).
    | attachments: private disk and retention of chat attachments (0 = keep).
    |
    */

    'ai' => [
        'secret_refs' => [
            'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GEMINI_API_KEY', 'DEEPSEEK_API_KEY',
            'OPENROUTER_API_KEY', 'GROQ_API_KEY', 'MISTRAL_API_KEY', 'XAI_API_KEY',
            'AZURE_OPENAI_API_KEY', 'AWS_BEARER_TOKEN_BEDROCK', 'OLLAMA_API_KEY',
            'OPENAI_COMPATIBLE_API_KEY',
        ],
        'agent_features' => [
            ConfiguredAgent::class => 'chat',
            PingAgent::class => 'test',
            TitleAgent::class => 'title',
        ],
        'simulated' => (bool) env('STARTER_AI_SIMULATED', env('APP_ENV', 'production') !== 'production'),
        'proposal_ttl_minutes' => (int) env('STARTER_AI_PROPOSAL_TTL', 10),
        'generate_titles' => (bool) env('STARTER_AI_GENERATE_TITLES', true),
        'attachments' => [
            'disk' => env('STARTER_AI_ATTACHMENTS_DISK', 'local'),
            'retention_days' => (int) env('STARTER_AI_ATTACHMENTS_RETENTION_DAYS', 30),
        ],
        'interrupt_after_minutes' => (int) env('STARTER_AI_INTERRUPT_AFTER_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    |
    | Events older than the retention are removed by `model:prune`.
    |
    */

    'audit' => [
        'retention_days' => (int) env('STARTER_AUDIT_RETENTION_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Persisted settings
    |--------------------------------------------------------------------------
    |
    | Default values of the settings editable from the admin panel. Keys in
    | "encrypted" are stored encrypted with the application key.
    |
    */

    'settings' => [
        'cache_key' => 'starter.settings',

        'defaults' => [
            'general' => [
                'locale' => env('APP_LOCALE', 'es'),
                'timezone' => env('STARTER_DISPLAY_TIMEZONE', 'America/Santiago'),
                'support_email' => null,
            ],

            'branding' => [
                'app_name' => env('APP_NAME', 'Laravel Starter'),
                'tagline' => null,
                'logo' => null,
                'logo_dark' => null,
                'favicon' => null,
                'primary_color' => '#171717',
                'primary_color_dark' => '#fafafa',
                'radius' => '0.5',
                'font_sans' => 'Instrument Sans',
                'font_heading' => null,
                'auth_layout' => 'simple',
                'default_appearance' => 'system',
            ],

            // The authentication method is a deployment decision taken in
            // the environment (STARTER_AUTH_METHOD), not a setting...
            'auth' => [
                'registration' => env('STARTER_REGISTRATION', 'invite-only'),
                'default_role' => 'reader',
            ],

            'mail' => [
                'mailer' => 'env',
                'host' => null,
                'port' => 587,
                'username' => null,
                'password' => null,
                'scheme' => null,
                'from_address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'from_name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel Starter')),
            ],

            'ai' => [
                'dashboard' => true,
                'display_currency' => 'USD',
            ],

            'onboarding' => [
                'completed' => false,
            ],
        ],

        'encrypted' => [
            'mail.password',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Branding catalogs
    |--------------------------------------------------------------------------
    |
    | Fonts offered in the branding settings, served by Bunny Fonts
    | (GDPR friendly drop-in for Google Fonts), and interface languages.
    |
    */

    'fonts' => [
        'Instrument Sans', 'Inter', 'Figtree', 'Geist', 'Roboto', 'Open Sans',
        'Lato', 'Poppins', 'Montserrat', 'Nunito', 'Nunito Sans', 'DM Sans',
        'Manrope', 'Plus Jakarta Sans', 'Outfit', 'Space Grotesk', 'Work Sans',
        'IBM Plex Sans', 'Source Sans 3', 'Raleway', 'Rubik', 'Lexend',
        'Sora', 'Urbanist', 'Merriweather', 'Playfair Display', 'Lora',
    ],

    'locales' => [
        'es' => 'Español',
        'en' => 'English',
    ],

];
