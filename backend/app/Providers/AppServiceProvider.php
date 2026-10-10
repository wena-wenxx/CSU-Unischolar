<?php

namespace App\Providers;

use Illuminate\Support\Facades\Mail;
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
    }
}
