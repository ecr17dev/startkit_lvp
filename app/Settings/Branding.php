<?php

namespace App\Settings;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns the branding settings into data for the frontend and the
 * CSS variables injected in the root Blade template.
 */
class Branding
{
    public function __construct(protected SettingsManager $settings) {}

    /**
     * Get the branding data shared with every Inertia page.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $branding = $this->settings->group('branding');

        return [
            'appName' => $branding['app_name'] ?: config('app.name'),
            'tagline' => $branding['tagline'] ?? null,
            'logoUrl' => $this->url($branding['logo'] ?? null),
            'logoDarkUrl' => $this->url($branding['logo_dark'] ?? null),
            'faviconUrl' => $this->url($branding['favicon'] ?? null),
            'authLayout' => $branding['auth_layout'] ?? 'simple',
            'defaultAppearance' => $branding['default_appearance'] ?? 'system',
        ];
    }

    /**
     * Get the public URL of a stored branding asset.
     */
    public function url(?string $path): ?string
    {
        return filled($path) ? Storage::disk('public')->url((string) $path) : null;
    }

    /**
     * Get the inline CSS overriding the theme variables.
     */
    public function css(): string
    {
        $branding = $this->settings->group('branding');

        $light = $this->colorVariables((string) ($branding['primary_color'] ?? '#171717'));
        $dark = $this->colorVariables((string) ($branding['primary_color_dark'] ?? '#fafafa'));

        $radius = is_numeric($branding['radius'] ?? null) ? (float) $branding['radius'] : 0.5;
        $sans = $this->fontStack($branding['font_sans'] ?? null);
        $heading = filled($branding['font_heading'] ?? null) ? $this->fontStack($branding['font_heading']) : $sans;

        return ":root{{$light}--radius:{$radius}rem;--app-font-sans:{$sans};--app-font-heading:{$heading};}"
            .".dark{{$dark}}";
    }

    /**
     * Get the Bunny Fonts stylesheet URL for the configured fonts, if needed.
     */
    public function fontsUrl(): ?string
    {
        $fonts = collect([
            $this->settings->get('branding.font_sans'),
            $this->settings->get('branding.font_heading'),
        ])
            ->filter(fn ($font) => is_string($font) && $font !== '' && $font !== 'Instrument Sans')
            ->filter(fn ($font) => in_array($font, (array) config('settings.fonts'), true))
            ->unique()
            ->map(fn (string $font) => Str::slug($font).':400,500,600,700');

        return $fonts->isEmpty()
            ? null
            : 'https://fonts.bunny.net/css?family='.$fonts->implode('|').'&display=swap';
    }

    protected function colorVariables(string $color): string
    {
        $color = $this->sanitizeHex($color);
        $foreground = $this->contrastColor($color);

        return "--primary:{$color};--primary-foreground:{$foreground};--ring:{$color};"
            ."--sidebar-primary:{$color};--sidebar-primary-foreground:{$foreground};--sidebar-ring:{$color};";
    }

    protected function fontStack(mixed $font): string
    {
        $font = is_string($font) && in_array($font, (array) config('settings.fonts'), true)
            ? $font
            : 'Instrument Sans';

        return "'{$font}'";
    }

    protected function sanitizeHex(string $color): string
    {
        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) ? strtolower($color) : '#171717';
    }

    /**
     * Pick black or white text depending on the luminance of the color.
     */
    public function contrastColor(string $hex): string
    {
        $hex = ltrim($this->sanitizeHex($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        [$r, $g, $b] = array_map(function (string $channel): float {
            $value = hexdec($channel) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));

        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        return $luminance > 0.179 ? '#0a0a0a' : '#fafafa';
    }
}
