<?php

namespace App\Providers;

use App\Models\Alert;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\Submission;
use App\Observers\DashboardCacheObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureModels();
        $this->configureObservers();
    }

    protected function configureObservers(): void
    {
        Player::observe(DashboardCacheObserver::class);
        Submission::observe(DashboardCacheObserver::class);
        Alert::observe(DashboardCacheObserver::class);
        PlayerRecord::observe(DashboardCacheObserver::class);
    }

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

    protected function configureModels(): void
    {
        // Strict mode off in prod so a missed ->with() doesn't bring down the app;
        // on locally + in tests so we catch N+1 and silently-discarded attributes early.
        Model::shouldBeStrict(! app()->isProduction());

        // Unguard explicitly per model instead of the global pass-through.
        Model::unguard(false);
    }
}
