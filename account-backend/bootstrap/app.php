<?php

use App\Exceptions\BillingException;
use App\Http\Middleware\SuperAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Kept in its own route file, own prefix, and own controller
            // namespace (App\Http\Controllers\Api\Admin) on purpose: this is
            // the RE-Account "company admin" surface, and the boundary is
            // drawn so it can be lifted into its own service later without
            // touching the product-facing API.
            Route::middleware(['api', 'auth:sanctum', 'superadmin'])
                ->prefix('api/admin')
                ->group(__DIR__.'/../routes/admin.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind the AWS load balancer (TLS ends there): trust X-Forwarded-* so
        // URLs/cookies are https and the client IP is right. TRUSTED_PROXIES =
        // '*' (default; tasks only accept traffic from the ALB) or comma-separated IPs/CIDRs.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));

        $middleware->statefulApi();

        // No `login` web route exists: API guests get a JSON 401 (never a
        // redirect, whatever the Accept header); anything else goes to the
        // account-frontend login page.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*')
                ? null
                : rtrim((string) config('app.frontend_url'), '/').'/login',
        );

        // Stripe's server-to-server POST is verified by its signature instead.
        $middleware->preventRequestForgery(except: ['api/stripe/webhook']);

        $middleware->alias([
            'superadmin' => SuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (BillingException $e) => $e->render());

        // Stripe SDK errors that escape the billing services unwrapped.
        $exceptions->render(fn (CardException $e) => BillingException::paymentFailed($e->getMessage())->render());
        $exceptions->render(fn (ApiErrorException $e) => BillingException::stripeError($e)->render());
    })->create();
