<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ModuleAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            if (! $user->isUsable()) {
                return false;
            }
            $module = ModuleAccess::forAbility($ability, $arguments);

            return $module !== null && ! $user->canAccessModule($module) ? false : null;
        });
        Gate::define('view-system-health', fn (User $user) => $user->hasRole(UserRole::Admin));
        Gate::define('view-system-settings', fn (User $user) => $user->hasRole(UserRole::Admin, UserRole::EnergyManager));
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

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
}
