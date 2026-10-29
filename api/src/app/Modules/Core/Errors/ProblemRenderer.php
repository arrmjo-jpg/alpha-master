<?php

namespace App\Modules\Core\Errors;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every API failure as RFC 9457 Problem Details (ADR-0006):
 * { type, title, status, code, detail?, errors?, trace_id }.
 * Production responses never contain exception classes, messages, SQL or traces.
 */
final class ProblemRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if ($e instanceof HttpResponseException) {
            return null;
        }

        $headers = [];
        $errors = null;
        $detail = null;

        if ($e instanceof DomainError) {
            $code = $e->errorCode;
            $detail = $e->detail;
        } elseif ($e instanceof ValidationException) {
            $code = ErrorCode::ValidationError;
            $errors = self::validationErrors($e);
        } elseif ($e instanceof AuthenticationException) {
            $code = ErrorCode::Unauthenticated;
        } elseif ($e instanceof HttpExceptionInterface) {
            $code = ErrorCode::forStatus($e->getStatusCode());
            $headers = array_intersect_key($e->getHeaders(), array_flip([
                'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'Allow',
            ]));
        } else {
            $code = ErrorCode::InternalServerError;
        }

        $status = $e instanceof HttpExceptionInterface && $code === ErrorCode::HttpError
            ? $e->getStatusCode()
            : $code->status();

        $body = array_filter([
            'type' => $code->type(),
            'title' => $code->title(),
            'status' => $status,
            'code' => $code->value,
            'detail' => $detail,
            'errors' => $errors,
            'trace_id' => $request->attributes->get('request_id'),
        ], fn ($value) => $value !== null);

        if ($code === ErrorCode::InternalServerError && config('app.debug')) {
            // Local debugging only; APP_DEBUG is false in production (tested).
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        return new JsonResponse($body, $status, $headers + ['Content-Type' => 'application/problem+json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, list<array{code: string, message: string}>>
     */
    private static function validationErrors(ValidationException $e): array
    {
        $failed = $e->validator->failed();
        $result = [];

        foreach ($e->errors() as $field => $messages) {
            $rules = array_keys($failed[$field] ?? []);
            foreach (array_values($messages) as $i => $message) {
                $rule = $rules[$i] ?? 'invalid';
                $result[$field][] = [
                    'code' => str_contains($rule, '\\') ? 'invalid' : strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $rule)),
                    'message' => $message,
                ];
            }
        }

        return $result;
    }
}
