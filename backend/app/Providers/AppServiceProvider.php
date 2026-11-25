<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        if ($to = config('mail.always_to')) {
            Mail::alwaysTo($to);
        }

        \Illuminate\Support\Facades\Queue::looping(function ($event) {
            if (($event->connectionName ?? null) === 'database') {
                try {
                    \App\Support\DocumentChecker::markWorkerAlive();
                } catch (\Throwable $e) {
                }
            }
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)
                ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Too many sign-in attempts. Wait one minute, then try again.',
                ], 429));
        });
    }
}
