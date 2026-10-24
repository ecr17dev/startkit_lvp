<?php

namespace App\Http\Controllers;

use App\Http\Requests\UiKitWizardRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catalog of the UI kit (resources/js/components/kit) with live examples.
 * Only available with STARTER_FEATURE_UI_KIT (on by default in local);
 * it shows demo data only and never stores anything.
 */
class UiKitController extends Controller
{
    /**
     * Sections of the catalog (page ui-kit/{Studly}).
     */
    public const SECTIONS = [
        'buttons', 'forms', 'wizard', 'tables', 'charts', 'carousels',
        'navigation', 'feedback', 'overlays', 'icons',
    ];

    /**
     * Display the overview of the kit.
     */
    public function index(): Response
    {
        $this->ensureEnabled();

        return Inertia::render('ui-kit/Index', ['sections' => self::SECTIONS]);
    }

    /**
     * Display one section of the catalog.
     */
    public function show(string $section): Response
    {
        $this->ensureEnabled();

        abort_unless(in_array($section, self::SECTIONS, true), 404);

        return Inertia::render('ui-kit/'.Str::studly($section));
    }

    /**
     * Validate the demo wizard on the server (nothing is saved), to show how
     * server errors take the user back to the step of the invalid field.
     */
    public function wizard(UiKitWizardRequest $request): RedirectResponse
    {
        $this->ensureEnabled();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The server accepted the form (demo: nothing was saved).'),
        ]);

        return back();
    }

    protected function ensureEnabled(): void
    {
        abort_unless((bool) config('starter.features.ui_kit'), 404);
    }
}
