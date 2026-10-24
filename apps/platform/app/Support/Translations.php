<?php

namespace App\Support;

/**
 * Exposes the JSON translation files (lang/{locale}.json) to the frontend.
 *
 * Strings are written in English in the code and translated through
 * these files, both in PHP (__()) and in Vue (t()).
 */
class Translations
{
    /**
     * @var array<string, array<string, string>>
     */
    protected static array $loaded = [];

    /**
     * Get the translations of the given locale.
     *
     * @return array<string, string>
     */
    public static function for(string $locale): array
    {
        if (isset(static::$loaded[$locale])) {
            return static::$loaded[$locale];
        }

        $path = lang_path("{$locale}.json");

        return static::$loaded[$locale] = is_file($path)
            ? (array) json_decode((string) file_get_contents($path), true)
            : [];
    }

    /**
     * Get a version string that changes whenever the file changes.
     */
    public static function version(string $locale): string
    {
        $path = lang_path("{$locale}.json");

        return $locale.'-'.(is_file($path) ? filemtime($path) : 0);
    }
}
