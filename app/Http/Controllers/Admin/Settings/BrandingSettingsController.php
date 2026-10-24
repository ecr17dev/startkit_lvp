<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Settings\Branding;
use App\Settings\SettingsManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BrandingSettingsController extends Controller
{
    /**
     * Uploadable branding assets. SVG is not accepted on purpose: it can
     * embed scripts and the files are served from the application domain.
     *
     * @var array<string, string>
     */
    protected array $assets = [
        'logo' => 'image|mimes:png,jpg,jpeg,webp|max:2048',
        'logo_dark' => 'image|mimes:png,jpg,jpeg,webp|max:2048',
        'favicon' => 'file|mimes:png,ico|max:512',
    ];

    public function __construct(
        protected SettingsManager $settings,
        protected Branding $branding,
    ) {}

    public function edit(): Response
    {
        $settings = $this->settings->group('branding');

        return Inertia::render('admin/settings/Branding', [
            'settings' => [
                ...$settings,
                'logo_url' => $this->branding->url($settings['logo'] ?? null),
                'logo_dark_url' => $this->branding->url($settings['logo_dark'] ?? null),
                'favicon_url' => $this->branding->url($settings['favicon'] ?? null),
            ],
            'fonts' => config('settings.fonts'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_name' => ['required', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'primary_color' => ['required', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'primary_color_dark' => ['required', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'radius' => ['required', 'numeric', 'min:0', 'max:1.5'],
            'font_sans' => ['required', 'string', Rule::in(config('settings.fonts'))],
            'font_heading' => ['nullable', 'string', Rule::in(config('settings.fonts'))],
            'auth_layout' => ['required', Rule::in(['simple', 'card', 'split'])],
            'default_appearance' => ['required', Rule::in(['light', 'dark', 'system'])],
            ...collect($this->assets)->mapWithKeys(fn (string $rules, string $key) => [$key => ['nullable', ...explode('|', $rules)]])->all(),
            ...collect($this->assets)->mapWithKeys(fn ($rules, string $key) => ["remove_{$key}" => ['boolean']])->all(),
        ]);

        $values = Arr::except($validated, [...array_keys($this->assets), ...array_map(fn ($key) => "remove_{$key}", array_keys($this->assets))]);
        $values['radius'] = (string) $values['radius'];

        foreach (array_keys($this->assets) as $key) {
            $current = $this->settings->get("branding.{$key}");

            if ($request->hasFile($key)) {
                $this->deleteAsset($current);
                $values[$key] = $request->file($key)->store('branding', 'public');
            } elseif ($request->boolean("remove_{$key}")) {
                $this->deleteAsset($current);
                $values[$key] = null;
            }
        }

        $this->settings->setGroup('branding', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Branding updated.')]);

        return back();
    }

    protected function deleteAsset(mixed $path): void
    {
        if (is_string($path) && $path !== '') {
            Storage::disk('public')->delete($path);
        }
    }
}
