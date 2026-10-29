<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Http\Middleware\SetLocaleFromRequest;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Feature\ApiTestCase;

class ProblemDetailsTest extends ApiTestCase
{
    public function test_unknown_routes_return_problem_details_with_request_id(): void
    {
        $response = $this->assertProblem($this->getJson('/api/v1/nope'), 404, 'RESOURCE_NOT_FOUND');

        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('trace_id'));
        $response->assertJsonPath('type', '/docs/problems/resource-not-found');
    }

    public function test_unexpected_errors_never_leak_internals_in_production(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('api/v1/_test/boom', fn () => throw new RuntimeException('SQLSTATE secret-db-password'));

        $response = $this->assertProblem($this->getJson('/api/v1/_test/boom'), 500, 'INTERNAL_SERVER_ERROR');

        $body = $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertStringNotContainsString('trace', strtolower(str_replace('trace_id', '', $body)));
        $response->assertJsonMissingPath('debug');
    }

    public function test_titles_are_localized_but_codes_and_status_are_not(): void
    {
        $ar = $this->withHeader('Accept-Language', 'ar-SA,ar;q=0.9')->getJson('/api/v1/auth/me');
        $en = $this->withHeader('Accept-Language', 'en-US')->getJson('/api/v1/auth/me');

        $this->assertProblem($ar, 401, 'UNAUTHENTICATED')->assertJsonPath('title', 'يجب تسجيل الدخول أولًا.')->assertHeader('Content-Language', 'ar');
        $this->assertProblem($en, 401, 'UNAUTHENTICATED')->assertJsonPath('title', 'Authentication is required.')->assertHeader('Content-Language', 'en');
    }

    public function test_locale_negotiation_falls_back_to_arabic(): void
    {
        $this->assertSame('ar', SetLocaleFromRequest::negotiate(null));
        $this->assertSame('ar', SetLocaleFromRequest::negotiate(''));
        $this->assertSame('ar', SetLocaleFromRequest::negotiate('fr-FR'));
        $this->assertSame('ar', SetLocaleFromRequest::negotiate('garbage;;q=x'));
        $this->assertSame('en', SetLocaleFromRequest::negotiate('fr;q=1, en;q=0.8, ar;q=0.5'));
        $this->assertSame('ar', SetLocaleFromRequest::negotiate('en;q=0.3, ar-EG;q=0.9'));
        $this->assertSame('en', SetLocaleFromRequest::negotiate('EN'));
    }

    public function test_method_not_allowed_is_a_problem(): void
    {
        $this->assertProblem($this->patchJson('/api/v1/auth/me'), 405, 'METHOD_NOT_ALLOWED');
    }
}
