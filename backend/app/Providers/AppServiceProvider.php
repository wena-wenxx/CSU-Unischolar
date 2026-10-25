<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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
        // Safety net for the demo: if MAIL_ALWAYS_TO is set, EVERY e-mail goes
        // to that one inbox instead of the (fictional) student addresses.
        // (Read through config so "php artisan config:cache" keeps working.)
        if ($to = config('mail.always_to')) {
            Mail::alwaysTo($to);
        }

        // Login: 10 tries a minute for one e-mail on one computer (stops password guessing).
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)
                ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Too many sign-in attempts. Wait one minute, then try again.',
                ], 429));
        });
    }
}
