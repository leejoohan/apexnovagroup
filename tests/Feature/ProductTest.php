<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_index_paginates_in_id_order_and_serializes_relationships(): void
    {
        $category = Category::factory()->create(['name' => 'Hardware']);
        $supplier = Supplier::factory()->create(['name' => 'Acme']);
        $first = Product::factory()->for($category)->create(['sku' => 'AAA', 'price' => '12.50', 'stock_quantity' => 0]);
        $first->suppliers()->attach($supplier);
        Product::factory()->for($category)->create(['sku' => 'BBB']);

        $this->getJson('/api/v1/products?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.sku', 'AAA')
            ->assertJsonPath('data.0.price', '12.50')
            ->assertJsonPath('data.0.stock_status', 'out_of_stock')
            ->assertJsonPath('data.0.category.name', 'Hardware')
            ->assertJsonPath('data.0.suppliers.0.name', 'Acme')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_filters_are_combined_and_zero_bounds_are_applied(): void
    {
        $category = Category::factory()->create();
        $other = Category::factory()->create();
        $wanted = Product::factory()->for($category)->create(['price' => '0.00', 'stock_quantity' => 0]);
        Product::factory()->for($category)->create(['price' => '1.00', 'stock_quantity' => 0]);
        Product::factory()->for($other)->create(['price' => '0.00', 'stock_quantity' => 0]);
        Product::factory()->for($category)->create(['price' => '0.00', 'stock_quantity' => 1]);

        $this->getJson("/api/v1/products?category_id={$category->id}&min_price=0&max_price=0&min_stock=0&max_stock=0&stock_status=out_of_stock")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id);
    }

    public function test_individual_filters_include_endpoints_and_stock_status_boundaries(): void
    {
        $category = Category::factory()->create();
        $other = Category::factory()->create();
        $zero = Product::factory()->for($category)->create(['price' => '0.00', 'stock_quantity' => 0]);
        $one = Product::factory()->for($category)->create(['price' => '10.00', 'stock_quantity' => 1]);
        $ten = Product::factory()->for($category)->create(['price' => '20.00', 'stock_quantity' => 10]);
        $eleven = Product::factory()->for($other)->create(['price' => '30.00', 'stock_quantity' => 11]);

        $cases = [
            ["category_id={$other->id}", [$eleven->id]],
            ['min_price=10&max_price=20', [$one->id, $ten->id]],
            ['min_stock=1&max_stock=10', [$one->id, $ten->id]],
            ['stock_status=out_of_stock', [$zero->id]],
            ['stock_status=low_stock', [$one->id, $ten->id]],
            ['stock_status=in_stock', [$eleven->id]],
        ];

        foreach ($cases as [$query, $expected]) {
            $actual = collect($this->getJson("/api/v1/products?{$query}")->assertOk()->json('data'))->pluck('id')->all();
            $this->assertSame($expected, $actual, $query);
        }
    }

    public function test_store_normalizes_sku_and_persists_suppliers(): void
    {
        $category = Category::factory()->create();
        $suppliers = Supplier::factory()->count(2)->create();

        $this->postJson('/api/v1/products', [
            'category_id' => $category->id,
            'sku' => '  item-123  ',
            'name' => 'Item',
            'description' => 'Useful',
            'price' => '12.50',
            'stock_quantity' => 10,
            'supplier_ids' => $suppliers->modelKeys(),
        ])->assertCreated()->assertJsonPath('data.sku', 'ITEM-123')
            ->assertJsonPath('data.stock_status', 'low_stock');

        $product = Product::where('sku', 'ITEM-123')->firstOrFail();
        $this->assertEqualsCanonicalizing($suppliers->modelKeys(), $product->suppliers()->pluck('suppliers.id')->all());
    }

    public function test_invalid_writes_return_validation_errors_including_normalized_duplicate_sku(): void
    {
        $existing = Product::factory()->create(['sku' => 'ABC']);
        $supplier = Supplier::factory()->create();

        $this->postJson('/api/v1/products', [
            'category_id' => $existing->category_id,
            'sku' => ' abc ',
            'name' => 'Bad',
            'price' => '1.999',
            'stock_quantity' => -1,
            'supplier_ids' => [$supplier->id, $supplier->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['sku', 'price', 'stock_quantity', 'supplier_ids.1']);

        $this->postJson('/api/v1/products', [
            'category_id' => 999999,
            'sku' => 'NEW',
            'name' => 'Bad',
            'price' => 1,
            'stock_quantity' => 0,
            'supplier_ids' => ['not-an-id'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'supplier_ids.0']);
    }

    public function test_patch_preserves_omitted_fields_and_explicit_empty_suppliers_clear_them(): void
    {
        $product = Product::factory()->create(['name' => 'Original', 'price' => '9.99']);
        $supplier = Supplier::factory()->create();
        $product->suppliers()->attach($supplier);

        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.suppliers.0.id', $supplier->id);
        $this->patchJson("/api/v1/products/{$product->id}", ['supplier_ids' => []])
            ->assertOk()->assertJsonCount(0, 'data.suppliers');
        $this->assertSame('9.99', $product->fresh()->price);
    }

    public function test_put_requires_full_replacement_and_supplier_array(): void
    {
        $product = Product::factory()->create(['description' => 'Old description']);

        $this->putJson("/api/v1/products/{$product->id}", ['name' => 'Only name'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'sku', 'price', 'stock_quantity', 'supplier_ids']);

        $this->putJson("/api/v1/products/{$product->id}", [
            'category_id' => $product->category_id,
            'sku' => $product->sku,
            'name' => 'Replaced',
            'price' => '0.00',
            'stock_quantity' => 0,
            'supplier_ids' => [],
        ])->assertOk()->assertJsonPath('data.name', 'Replaced')
            ->assertJsonPath('data.description', null);
    }

    public function test_delete_soft_deletes_and_hides_product_from_all_endpoints(): void
    {
        $product = Product::factory()->create();

        $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();
        $this->assertSoftDeleted($product);
        $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();
        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Again'])->assertNotFound();
        $this->deleteJson("/api/v1/products/{$product->id}")->assertNotFound();
    }

    public function test_soft_deleted_sku_remains_reserved(): void
    {
        $product = Product::factory()->create(['sku' => 'RESERVED']);
        $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

        $this->postJson('/api/v1/products', [
            'category_id' => $product->category_id,
            'sku' => ' reserved ',
            'name' => 'Replacement',
            'price' => '1.00',
            'stock_quantity' => 0,
            'supplier_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['sku']);
    }

    public function test_all_product_actions_require_authentication(): void
    {
        $product = Product::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        foreach ([
            ['getJson', '/api/v1/products', []],
            ['postJson', '/api/v1/products', []],
            ['getJson', "/api/v1/products/{$product->id}", []],
            ['putJson', "/api/v1/products/{$product->id}", []],
            ['patchJson', "/api/v1/products/{$product->id}", []],
            ['deleteJson', "/api/v1/products/{$product->id}", []],
        ] as [$method, $url, $payload]) {
            $this->{$method}($url, $payload)->assertUnauthorized();
        }
    }

    public function test_invalid_list_ranges_and_malformed_values_return_422(): void
    {
        $this->getJson('/api/v1/products?min_price=10&max_price=0')
            ->assertUnprocessable()->assertJsonValidationErrors(['max_price']);
        $this->getJson('/api/v1/products?min_stock=10&max_stock=0')
            ->assertUnprocessable()->assertJsonValidationErrors(['max_stock']);
        $this->getJson('/api/v1/products?category_id[]=1')
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
        $this->getJson('/api/v1/products?per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/products?stock_status[]=low_stock')
            ->assertUnprocessable()->assertJsonValidationErrors(['stock_status']);
    }
}
