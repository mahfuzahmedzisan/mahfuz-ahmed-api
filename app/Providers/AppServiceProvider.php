<?php

namespace App\Providers;

use App\Support\FrontendOrigins;
use Carbon\CarbonInterval;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

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
        Passport::enablePasswordGrant();
        Passport::tokensExpireIn(CarbonInterval::hours(1));
        Passport::refreshTokensExpireIn(CarbonInterval::days(14));

        $this->configureRateLimiting();
        $this->configurePasswordResetUrl();
    }

    /**
     * `login` and `two-factor` limiters live in FortifyServiceProvider
     * alongside the Fortify actions they protect. `register` has no Fortify
     * equivalent, so it stays here.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip().'|'.$request->input('email'));
        });

        RateLimiter::for('reset-password', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('admin-users', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }

    /**
     * This is an API-only app with no `password.reset` web route, so the
     * default notification (which calls `route('password.reset', ...)`)
     * would 500. Point the emailed link at the Next.js BFF's reset-password
     * page instead; it posts the token straight to this API.
     */
    private function configurePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $requested = FrontendOrigins::normalize((string) request()->header('X-Frontend-Origin', ''));
            $frontendUrl = ($requested !== null && FrontendOrigins::contains($requested))
                ? $requested
                : FrontendOrigins::default();

            return sprintf(
                '%s/reset-password?token=%s&email=%s',
                rtrim($frontendUrl, '/'),
                $token,
                urlencode($notifiable->getEmailForPasswordReset()),
            );
        });
    }
}
