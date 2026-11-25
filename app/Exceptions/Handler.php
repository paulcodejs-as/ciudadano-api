<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    private const POST_TOO_LARGE = 'El archivo supera el límite de subida de PHP (post_max_size / upload_max_filesize en php.ini).';

    public function register(): void
    {
        // Panel admin: si el CSV supera post_max_size, PHP descarta todo el formulario.
        $this->renderable(function (PostTooLargeException $e, Request $request) {
            if ($request->is('admin/*')) {
                return redirect()->route('admin.imports.index')->withErrors(['file' => self::POST_TOO_LARGE]);
            }

            return null;
        });

        // Todas las rutas /api/* responden errores con el formato estándar.
        $this->renderable(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            [$status, $message, $errors] = match (true) {
                $e instanceof ValidationException => [422, 'Los datos enviados no son válidos.', $e->errors()],
                $e instanceof AuthenticationException => [401, 'No autenticado.', []],
                $e instanceof AuthorizationException => [403, 'No tienes permiso para esta acción.', []],
                $e instanceof PostTooLargeException => [413, self::POST_TOO_LARGE, []],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [404, 'Recurso no encontrado.', []],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getMessage() ?: 'Error en la solicitud.', []],
                default => [500, config('app.debug') ? $e->getMessage() : 'Error interno del servidor.', []],
            };

            $body = ['success' => false, 'message' => $message, 'data' => null, 'meta' => (object) []];
            if ($errors) {
                $body['errors'] = $errors;
            }

            $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

            return response()->json($body, $status, $headers);
        });
    }
}
