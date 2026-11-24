<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Hosting platforms (Railway, Render, ...) put Laravel behind an HTTPS
        // proxy. Trusting it makes Laravel see the real https:// address.
        $middleware->trustProxies(at: '*');

        // There is no web login page: a signed-out call to the API gets a plain 401 answer
        // ("Unauthenticated.") instead of a redirect to a missing "login" route (a 500 error).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // MySQL is not running (XAMPP stopped it): say so in plain words instead of a long SQL error,
        // so the page can show it instead of loading forever.
        $exceptions->render(function (\Illuminate\Database\QueryException $e, Request $request) {
            $code = (string) ($e->getPrevious()?->getCode() ?? $e->getCode());
            $text = $e->getMessage();
            $down = in_array($code, ['2002', '2006', '1045', 'HY000'], true)
                && preg_match('/\[2002\]|refused|gone away|Connection timed out|No such host/i', $text);
            if ($down && ($request->is('api/*') || $request->expectsJson())) {
                return response()->json([
                    'message' => 'The database (MySQL) is not running. Start MySQL in the XAMPP Control Panel, then try again.',
                    'database_down' => true,
                ], 503);
            }

            return null;
        });
    })->create();
