<?php

namespace App\Providers;

use App\Auth\AuthenticatedActor;
use App\Auth\CompanyActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

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
        Auth::viaRequest('remote-identity', fn (Request $request): ?AuthenticatedActor => $request->attributes->get('authenticated_actor'));
        Auth::viaRequest('company-identity', fn (Request $request): ?CompanyActor => $request->attributes->get('company_actor'));
    }
}
