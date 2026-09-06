<?php

namespace App\Providers;

use App\Services\Runner\CodeRunner;
use App\Services\Runner\PodmanRunner;
use App\Services\Runner\RunSlots;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swapped for a FakeRunner in HTTP tests, so the routes can be tested
        // without spawning containers.
        $this->app->bind(CodeRunner::class, PodmanRunner::class);

        $this->app->singleton(RunSlots::class, fn (): RunSlots => new RunSlots(
            slots: (int) config('runner.max_concurrent'),
            ttlSeconds: (int) config('runner.timeout') + 5,
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
