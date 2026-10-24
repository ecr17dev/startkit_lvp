<?php

use App\Settings\SettingsManager;

if (! function_exists('settings')) {
    /**
     * Get the settings manager or a setting value using "dot" notation.
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $settings = app(SettingsManager::class);

        return $key === null ? $settings : $settings->get($key, $default);
    }
}
