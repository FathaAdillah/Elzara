<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class PreorderBatchContractTest extends TestCase
{
    public function test_public_routes_and_detail_schema(): void
    {
        $spec = Yaml::parseFile(base_path('../../packages/api-contracts/openapi.yaml'));
        foreach (['/preorder-batches' => 'listOpenPreorderBatches', '/preorder-batches/{id}' => 'getPreorderBatch'] as $path => $operation) {
            $route = Route::getRoutes()->match(Request::create('/api/v1'.str_replace('{id}', 'batch_example', $path), 'GET'));
            $this->assertContains('rate:public', $route->gatherMiddleware());
            $this->assertSame([], $spec['paths'][$path]['get']['security']);
            $this->assertSame($operation, $spec['paths'][$path]['get']['operationId']);
        }
        $this->assertSame('#/components/schemas/PreorderBatchDetail', $spec['paths']['/preorder-batches/{id}']['get']['responses'][200]['content']['application/json']['schema']['$ref']);
        foreach (['price', 'remaining', 'max_per_account', 'opens_at', 'closes_at'] as $field) {
            $this->assertContains($field, $spec['components']['schemas']['PreorderBatchItem']['required']);
        }
    }
}
