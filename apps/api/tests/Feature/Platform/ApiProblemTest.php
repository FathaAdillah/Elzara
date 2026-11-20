<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Domain\Exception\InsufficientStock;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

final class ApiProblemTest extends TestCase
{
    public function test_domain_error_uses_problem_details_and_retains_request_id(): void
    {
        Route::middleware('api')->post('/api/v1/__probe/stock', static function (): never {
            throw new InsufficientStock('Stok tersisa 2.', [
                ['pointer' => '/items/0/quantity', 'code' => 'quantity_exceeds_available', 'detail' => 'Maksimal 2.'],
            ]);
        });

        $this->postJson('/api/v1/__probe/stock', [], ['X-Request-Id' => 'checkout.42'])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('X-Request-Id', 'checkout.42')
            ->assertJsonPath('type', 'urn:elzara:problem:inventory:insufficient-stock')
            ->assertJsonPath('title', 'Stok tidak mencukupi')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('instance', '/api/v1/__probe/stock')
            ->assertJsonPath('request_id', 'checkout.42')
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('errors.0.pointer', '/items/0/quantity');
    }

    public function test_validation_problem_uses_field_errors_and_a_generated_ulid(): void
    {
        Route::middleware('api')->post('/api/v1/__probe/validate', static function (Request $request): void {
            $request->validate(['quantity' => ['required', 'integer']]);
        });

        $response = $this->postJson('/api/v1/__probe/validate', [], ['X-Request-Id' => 'invalid id'])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('errors.0.pointer', '/quantity')
            ->assertJsonStructure(['errors' => [['pointer', 'code', 'detail']]]);

        $requestId = $response->json('request_id');
        $this->assertIsString($requestId);
        $this->assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $requestId);
        $response->assertHeader('X-Request-Id', $requestId);
    }

    public function test_authentication_failure_remains_a_problem_without_accept_header(): void
    {
        Route::middleware('api')->get('/api/v1/__probe/private', static function (): never {
            throw new AuthenticationException;
        });

        $response = $this->get('/api/v1/__probe/private')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('instance', '/api/v1/__probe/private');
        $response->assertHeader('X-Request-Id', $response->json('request_id'));
    }

    public function test_unexpected_api_error_omits_internal_details(): void
    {
        Route::middleware('api')->get('/api/v1/__probe/error', static function (): never {
            throw new RuntimeException('Internal diagnostic message.');
        });

        $response = $this->getJson('/api/v1/__probe/error')
            ->assertInternalServerError()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'urn:elzara:problem:server:internal')
            ->assertDontSee('Internal diagnostic message.')
            ->assertJsonMissingPath('trace');
        $response->assertHeader('X-Request-Id', $response->json('request_id'));
    }

    public function test_unmatched_routes_keep_the_api_boundary(): void
    {
        $this->get('/api/v1/__probe/not-found')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('instance', '/api/v1/__probe/not-found');

        $this->get('/__probe/not-found')
            ->assertNotFound()
            ->assertHeaderMissing('X-Request-Id');
    }
}
