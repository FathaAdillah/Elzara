<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\ProductCsv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class AdminCatalogCsvTest extends TestCase
{
    use AdminCatalogSession;
    use DatabaseTransactions;

    private const IMPORT = '/api/v1/admin/catalog/products/import';

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedTaxonomy();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    public function test_dry_run_reports_without_writing_then_import_upserts_atomically(): void
    {
        $this->loginAdmin('catalog.view', 'catalog.create', 'catalog.update');
        $csv = $this->csv([
            ['aurora-hoodie', 'Aurora Hoodie', 'apparel', 'snowego', 'Aurora', 'active', 'ID', '450', 'rts|po', 'Hoodie hangat', 'AUR-M', 'M', '350000', 'IDR', 'true', 'true'],
            ['aurora-hoodie', 'Aurora Hoodie', 'apparel', 'snowego', 'Aurora', 'active', 'ID', '450', 'rts|po', 'Hoodie hangat', 'AUR-L', 'L', '365000', '', '', ''],
            ['plain-tote', 'Plain Tote', 'apparel', '', '', '', 'JP', '', '', '', '', '', '', '', '', ''],
        ]);
        $this->api('POST', self::IMPORT, ['dry_run' => 'true'], file: $csv)->assertOk()
            ->assertJsonPath('data', ['dry_run' => true, 'rows' => 3, 'products_created' => 2, 'products_updated' => 0, 'variants_created' => 2, 'variants_updated' => 0]);
        $this->assertDatabaseCount('products', 0);

        $this->api('POST', self::IMPORT, [], file: $csv)->assertCreated()->assertJsonPath('data.products_created', 2)->assertJsonPath('data.variants_created', 2);
        $hoodie = DB::table('products')->where('slug', 'aurora-hoodie')->first();
        $this->assertSame('{rts,po}', $hoodie->fulfillment_modes);
        $this->assertSame(450, (int) $hoodie->unit_weight_g);
        $this->assertSame(1, (int) $hoodie->version);
        $this->assertDatabaseHas('product_variants', ['sku' => 'AUR-L', 'base_price' => 365000, 'currency_code' => 'IDR', 'track_stock' => true]);
        $this->assertDatabaseHas('products', ['slug' => 'plain-tote', 'status' => 'draft', 'brand_id' => null, 'unit_weight_g' => null]);
        $this->assertSame(2, DB::table('outbox_events')->where('event_name', 'ProductUpdated')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'catalog.import')->count());

        $update = $this->csv([['aurora-hoodie', 'Aurora Hoodie II', 'apparel', 'snowego', 'Aurora', 'active', 'ID', '520', 'po', '', 'AUR-M', 'M', '355000', 'IDR', 'true', 'false']]);
        $this->api('POST', self::IMPORT, [], file: $update)->assertCreated()->assertJsonPath('data.products_updated', 1)->assertJsonPath('data.variants_updated', 1);
        $this->assertDatabaseHas('products', ['slug' => 'aurora-hoodie', 'name' => 'Aurora Hoodie II', 'unit_weight_g' => 520, 'version' => 2]);
        $this->assertDatabaseHas('product_variants', ['sku' => 'AUR-M', 'base_price' => 355000, 'is_active' => false, 'version' => 2]);
    }

    public function test_invalid_rows_reject_the_whole_file_with_row_pointers(): void
    {
        $this->loginAdmin('catalog.create', 'catalog.update');
        $this->api('POST', self::IMPORT, [], file: UploadedFile::fake()->createWithContent('bad.csv', "slug,name\nx,y\n"))
            ->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/rows/1/*')->assertJsonPath('errors.0.code', 'invalid_header');

        $csv = $this->csv([
            ['ok-product', 'OK', 'apparel', '', '', '', 'ID', '', '', '', 'SKU-1', 'One', '1000', '', '', ''],
            ['ok-product', 'Different name', 'apparel', '', '', '', 'ID', '', '', '', 'SKU-2', 'Two', '1000', '', '', ''],
            ['bad-cat', 'Bad', 'missing', 'ghost', '', 'live', 'id', '0', 'ship', '', 'SKU-1', '', '-5', 'rupiah', 'yes', ''],
        ]);
        $response = $this->api('POST', self::IMPORT, [], file: $csv)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $pointers = array_column($response->json('errors'), 'pointer');
        foreach (['/rows/3/product_slug', '/rows/4/category_slug', '/rows/4/brand_slug', '/rows/4/status', '/rows/4/origin_country', '/rows/4/unit_weight_g', '/rows/4/fulfillment_modes',
            '/rows/4/variant_label', '/rows/4/base_price', '/rows/4/currency_code', '/rows/4/track_stock', '/rows/4/sku'] as $pointer) {
            $this->assertContains($pointer, $pointers);
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('outbox_events', 0);

        DB::table('products')->insert(['slug' => 'owner', 'name' => 'Owner', 'category_id' => DB::table('categories')->value('id'), 'origin_country' => 'ID']);
        DB::table('product_variants')->insert(['product_id' => DB::table('products')->value('id'), 'sku' => 'TAKEN', 'label' => 'x', 'base_price' => 1]);
        $this->api('POST', self::IMPORT, [], file: $this->csv([['thief', 'T', 'apparel', '', '', '', 'ID', '', '', '', 'TAKEN', 'x', '1', '', '', '']]))
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'sku_taken');
    }

    public function test_import_requires_create_and_update_permissions_and_csrf(): void
    {
        $user = $this->loginAdmin('catalog.create');
        $csv = $this->csv([['p', 'P', 'apparel', '', '', '', 'ID', '', '', '', '', '', '', '', '', '']]);
        $this->api('POST', self::IMPORT, [], file: $csv)->assertForbidden();
        $this->grant($user, 'catalog.update');
        $this->api('POST', self::IMPORT, [], csrf: false, file: $csv)->assertStatus(419);
    }

    public function test_export_round_trips_and_neutralises_spreadsheet_formulas(): void
    {
        $category = DB::table('categories')->value('id');
        $id = DB::table('products')->insertGetId(['slug' => 'formula', 'name' => '=HYPERLINK("x")', 'category_id' => $category, 'origin_country' => 'ID', 'status' => 'active', 'unit_weight_g' => 250]);
        DB::update("UPDATE products SET fulfillment_modes = '{rts,request}' WHERE id = ?", [$id]);
        DB::table('product_variants')->insert(['product_id' => $id, 'sku' => 'F-1', 'label' => '+1', 'base_price' => 1000]);
        DB::table('products')->insert(['slug' => 'no-variant', 'name' => 'Bare', 'category_id' => $category, 'origin_country' => 'ID']);
        $this->api('GET', '/api/v1/admin/catalog/products/export')->assertUnauthorized();
        $this->loginAdmin('catalog.view');
        $response = $this->api('GET', '/api/v1/admin/catalog/products/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim($response->streamedContent())));
        $this->assertSame(ProductCsv::COLUMNS, $lines[0]);
        $this->assertSame(['formula', '\'=HYPERLINK("x")', 'apparel', '', '', 'active', 'ID', '250', 'rts|request', '', 'F-1', "'+1", '1000', 'IDR', 'true', 'true'], $lines[1]);
        $this->assertSame(['no-variant', 'Bare', 'apparel', '', '', 'draft', 'ID', '', '', '', '', '', '', '', '', ''], $lines[2]);
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'w+b');
        fputcsv($handle, ProductCsv::COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);

        return UploadedFile::fake()->createWithContent('catalog.csv', (string) stream_get_contents($handle));
    }
}
