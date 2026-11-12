<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\TenantRole;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // scoped: reset per request / per queued job, never shared across them.
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Operators and admins (and super-admins) may change audience and content; viewers read only.
        Gate::define('manage-audience', function (User $user): bool {
            $context = app(TenantContext::class);

            return $context->has() && ($user->roleIn($context->current())?->atLeast(TenantRole::Operator) ?? false);
        });

        // Lifting an opt-out re-enables mail to someone who was blocked: tenant admins only.
        Gate::define('lift-suppressions', function (User $user): bool {
            $context = app(TenantContext::class);

            return $context->has() && ($user->roleIn($context->current())?->atLeast(TenantRole::Admin) ?? false);
        });
    }
}
