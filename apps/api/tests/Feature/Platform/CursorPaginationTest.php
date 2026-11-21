<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Http\CursorPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class CursorPaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE TEMP TABLE cursor_fixtures (id bigint PRIMARY KEY, created_at timestamptz NOT NULL, category text NOT NULL)');
        Route::get('api/v1/cursor-fixture', function (Request $request) {
            $category = $request->query('category', 'a');

            return CursorPagination::paginate($request, DB::table('cursor_fixtures')->where('category', $category),
                $request->boolean('ascending') ? ['created_at' => 'asc', 'id' => 'asc'] : ['created_at' => 'desc', 'id' => 'desc'], ['category' => $category]);
        });
    }

    protected function tearDown(): void
    {
        DB::statement('DROP TABLE cursor_fixtures');
        parent::tearDown();
    }

    public function test_keyset_pagination_is_stable_across_inserts_and_supports_previous_page_without_count(): void
    {
        foreach (range(1, 5) as $id) {
            DB::table('cursor_fixtures')->insert(['id' => $id, 'created_at' => '2026-11-17 00:00:00+00', 'category' => 'a']);
        }
        DB::enableQueryLog();
        $first = $this->getJson('/api/v1/cursor-fixture?limit=2')->assertOk()
            ->assertJsonPath('data.0.id', 5)->assertJsonPath('data.1.id', 4)->assertJsonPath('meta.prev_cursor', null)->assertJsonPath('meta.has_more', true);
        DB::table('cursor_fixtures')->insert(['id' => 6, 'created_at' => '2026-11-18 00:00:00+00', 'category' => 'a']);
        $second = $this->getJson('/api/v1/cursor-fixture?limit=2&cursor='.$first->json('meta.next_cursor'))->assertOk()
            ->assertJsonPath('data.0.id', 3)->assertJsonPath('data.1.id', 2);
        $this->getJson('/api/v1/cursor-fixture?limit=2&cursor='.$second->json('meta.prev_cursor'))->assertOk()
            ->assertJsonPath('data.0.id', 5)->assertJsonPath('data.1.id', 4);
        $this->getJson('/api/v1/cursor-fixture?limit=2&cursor='.$second->json('meta.next_cursor'))->assertOk()
            ->assertJsonPath('data.0.id', 1)->assertJsonPath('meta.has_more', false)->assertJsonPath('meta.next_cursor', null);
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('count(', strtolower($query['query']));
        }
        DB::disableQueryLog();
    }

    public function test_empty_default_limit_and_custom_ascending_sort(): void
    {
        $this->getJson('/api/v1/cursor-fixture')->assertExactJson(['data' => [], 'meta' => ['limit' => 20, 'has_more' => false, 'next_cursor' => null, 'prev_cursor' => null]]);
        foreach (range(1, 3) as $id) {
            DB::table('cursor_fixtures')->insert(['id' => $id, 'created_at' => '2026-11-17 00:00:00+00', 'category' => 'a']);
        }
        $first = $this->getJson('/api/v1/cursor-fixture?limit=1&ascending=1')->assertJsonPath('data.0.id', 1);
        $this->getJson('/api/v1/cursor-fixture?limit=1&ascending=1&cursor='.$first->json('meta.next_cursor'))->assertJsonPath('data.0.id', 2);
    }

    public function test_invalid_limits_legacy_parameters_malformed_cursor_and_changed_filters(): void
    {
        foreach (['limit=0', 'limit=-1', 'limit=101', 'limit=1.5', 'limit=abc', 'limit[]=1', 'limit=', 'page=1', 'offset=0'] as $query) {
            $field = str_starts_with($query, 'limit') ? 'limit' : explode('=', $query)[0];
            $this->getJson('/api/v1/cursor-fixture?'.$query)->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/'.$field);
        }
        foreach (['invalid', 'e30', '', '[]'] as $cursor) {
            $this->getJson('/api/v1/cursor-fixture?cursor='.urlencode($cursor))->assertBadRequest()
                ->assertJsonPath('type', 'urn:elzara:problem:pagination:cursor-invalid');
        }
        foreach ([1, 2] as $id) {
            DB::table('cursor_fixtures')->insert(['id' => $id, 'created_at' => '2026-11-17 00:00:00+00', 'category' => 'a']);
        }
        $cursor = $this->getJson('/api/v1/cursor-fixture?limit=1')->json('meta.next_cursor');
        $this->getJson('/api/v1/cursor-fixture?category=b&cursor='.$cursor)->assertBadRequest()
            ->assertJsonPath('type', 'urn:elzara:problem:pagination:cursor-filter-mismatch');
        $this->getJson('/api/v1/cursor-fixture?ascending=1&cursor='.$cursor)->assertBadRequest()
            ->assertJsonPath('type', 'urn:elzara:problem:pagination:cursor-invalid');
    }
}
