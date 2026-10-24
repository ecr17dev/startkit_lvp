<?php

/*
|--------------------------------------------------------------------------
| Application Settings
|--------------------------------------------------------------------------
|
| Default values for the settings that administrators can change from the
| admin panel. Values saved from the UI are stored in the "settings"
| table and override these defaults. Keys listed in "encrypted" are
| stored encrypted with the application key.
|
*/

return [

    'cache_key' => 'app.settings',

    /*
    | Credentials of the super administrator created by `php artisan db:seed`.
    */
    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD', 'password'),
    ],

    'defaults' => [
        'general' => [
            'locale' => env('APP_LOCALE', 'es'),
            'support_email' => null,
        ],

        'branding' => [
            'app_name' => env('APP_NAME', 'Laravel'),
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
            'password_login' => true,
            'code_login' => true,
            'registration' => true,
            'default_role' => 'user',
            'code_length' => 6,
            'code_ttl' => 10,
            'code_max_attempts' => 5,
        ],

        'mail' => [
            'mailer' => 'env',
            'host' => null,
            'port' => 587,
            'username' => null,
            'password' => null,
            'scheme' => null,
            'from_address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
            'from_name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
        ],

        'ai' => [
            'monthly_budget' => null,
            'user_monthly_budget' => null,
            'enforce_budget' => false,
            'generate_titles' => true,
            'currency' => 'USD',
        ],
    ],

    'encrypted' => [
        'mail.password',
    ],

    /*
    | Fonts offered in the branding settings. Every font is served by
    | Bunny Fonts (GDPR-friendly drop-in for Google Fonts).
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
