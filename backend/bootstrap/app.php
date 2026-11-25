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
        $middleware->trustProxies(at: '*');

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

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
