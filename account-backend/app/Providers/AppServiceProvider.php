<?php

namespace App\Providers;

use App\Services\Billing\StripeGateway;
use App\Services\Billing\StripeSdkGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Tests swap this for Tests\Fakes\FakeStripeGateway.
        $this->app->singleton(StripeGateway::class, StripeSdkGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
