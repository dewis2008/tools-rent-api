<?php

use App\Enums\ApiErrorCode;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (! $request->is('api/*')) {
                return $response;
            }

            if (! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
                return $response;
            }

            $payload = $response->getData(true);

            if (! is_array($payload) || array_key_exists('code', $payload)) {
                return $response;
            }

            $response->setData([
                'code' => ApiErrorCode::forStatus($response->getStatusCode())->value,
                ...$payload,
            ]);

            return $response;
        });
    })->create();
