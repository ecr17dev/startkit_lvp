<?php

namespace App\Providers;

use App\Ai\Knowledge\KnowledgeGrounding;
use App\Ai\OperatorTools;
use App\Knowledge\ProjectDocsAdapter;
use App\Models\User;
use App\Policies\InvitationPolicy;
use App\Policies\RolePolicy;
use App\PostgRest\PostgRest;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Starter\Administration\Health\HealthChecker;
use Starter\Ai\Agents\ToolRegistry;
use Starter\Identity\Models\Invitation;
use Starter\Knowledge\Ingestion\Sources\AdapterRegistry;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->configureApiDocs();

        // Operator tools that agents may be allowed to use...
        $this->callAfterResolving(ToolRegistry::class, fn (ToolRegistry $registry) => OperatorTools::register($registry));

        // Publication checks of the RAG settings of agents...
        KnowledgeGrounding::registerChecks();

        // PostgREST is checked (and required) only while its feature is on...
        $this->callAfterResolving(HealthChecker::class, fn (HealthChecker $health) => $health->register(
            'postgrest',
            fn () => app(PostgRest::class)->health(),
            fn () => (bool) config('starter.features.postgrest'),
        ));

        // Internal content adapters offered as knowledge sources...
        $this->callAfterResolving(AdapterRegistry::class, fn (AdapterRegistry $adapters) => $adapters->register(new ProjectDocsAdapter));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Behind a reverse proxy (TRUSTED_PROXIES), HTTPS and the client IP
        // come from its X-Forwarded-* headers...
        if (filled($proxies = config('starter.trusted_proxies'))) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', (string) $proxies)));
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Deny by default: there is no Gate::before bypass, owners hold every
     * permission explicitly (synced by `starter:permissions`) and policies
     * apply the delegation rules to everybody.
     */
    protected function configureAuthorization(): void
    {
        // Short, stable names in polymorphic columns (audit trail, roles)...
        Relation::morphMap([
            'user' => User::class,
            'role' => Role::class,
            'invitation' => Invitation::class,
        ]);

        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Invitation::class, InvitationPolicy::class);

        Gate::define('viewApiDocs', fn (?User $user) => app()->environment('local')
            || ($user?->can('api.docs.read') ?? false));
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(20)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // One-time codes: the broker already enforces a resend cooldown per
        // email; these limits stop floods per address and per client...
        RateLimiter::for('otp-request', fn (Request $request) => [
            Limit::perMinute((int) config('starter.auth.otp.requests_per_minute', 6))->by('ip:'.$request->ip()),
            Limit::perHour((int) config('starter.auth.otp.requests_per_hour', 10))->by('email:'.Str::lower((string) ($request->input('email')
                ?? $request->user()?->getAttribute('email')
                ?? ($request->hasSession() ? $request->session()->getId() : $request->ip())))),
        ]);

        RateLimiter::for('otp-verify', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
        ]);
    }

    /**
     * Configure the OpenAPI (Swagger) documentation generated by Scramble.
     */
    protected function configureApiDocs(): void
    {
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $description = 'REST API secured with Laravel Sanctum. Create a token from Settings → API tokens or with the one-time code endpoints (POST /v1/auth/otp and /v1/auth/otp/verify) and send it as `Authorization: Bearer <token>`.';

                // The exported contract is stable (no branding, locale or host)...
                if (app()->runningInConsole()) {
                    $openApi->info->title = (string) config('starter.api.title');
                    $openApi->info->description = $description;
                    $openApi->servers = [Server::make('/api')];
                } else {
                    $openApi->info->title = config('app.name').' API';
                    $openApi->info->description = (string) __($description);
                }

                $openApi->secure(SecurityScheme::http('bearer'));
            });
    }
}
