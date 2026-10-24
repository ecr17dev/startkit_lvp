<?php

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
    | method: "email-otp" (only one-time codes) or "email-otp+password"
    | (also allows password login, password resets and passkeys).
    | registration: "open", "invite-only" or "closed".
    |
    */

    'auth' => [
        'method' => env('STARTER_AUTH_METHOD', 'email-otp'),
        'registration' => env('STARTER_REGISTRATION', 'invite-only'),
        'otp' => [
            'length' => (int) env('STARTER_OTP_LENGTH', 6),
            'ttl' => (int) env('STARTER_OTP_TTL', 5),
            'max_attempts' => (int) env('STARTER_OTP_MAX_ATTEMPTS', 5),
            'resend_cooldown' => (int) env('STARTER_OTP_RESEND_COOLDOWN', 60),
        ],
        'invitation_ttl_days' => (int) env('STARTER_INVITATION_TTL_DAYS', 7),
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

            'auth' => [
                'password_login' => env('STARTER_AUTH_METHOD', 'email-otp') === 'email-otp+password',
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
                'generate_titles' => true,
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
