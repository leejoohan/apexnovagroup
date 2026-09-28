<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ProductCache;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCacheTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_cached_list_data_refreshes_after_api_writes_and_supplier_sync(): void
    {
        $product = Product::factory()->create(['name' => 'Before']);
        $supplier = Supplier::factory()->create();
        $url = '/api/v1/products?per_page=10';
        $this->getJson($url)->assertJsonPath('data.0.name', 'Before');

        DB::table('products')->where('id', $product->id)->update(['name' => 'Changed in database']);
        $this->getJson($url)->assertJsonPath('data.0.name', 'Before');

        $this->patchJson("/api/v1/products/{$product->id}", ['supplier_ids' => [$supplier->id]])
            ->assertOk()->assertJsonPath('data.suppliers.0.id', $supplier->id);
        $this->getJson($url)->assertJsonPath('data.0.name', 'Changed in database')
            ->assertJsonPath('data.0.suppliers.0.id', $supplier->id);

        $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();
        $this->getJson($url)->assertJsonCount(0, 'data');
    }

    public function test_cached_detail_refreshes_after_category_and_supplier_edits(): void
    {
        $category = Category::factory()->create(['name' => 'Old category']);
        $supplier = Supplier::factory()->create(['name' => 'Old supplier']);
        $product = Product::factory()->for($category)->create();
        $product->suppliers()->attach($supplier);
        $url = "/api/v1/products/{$product->id}";

        $this->getJson($url)->assertJsonPath('data.category.name', 'Old category')
            ->assertJsonPath('data.suppliers.0.name', 'Old supplier');
        $category->update(['name' => 'New category']);
        $supplier->update(['name' => 'New supplier']);
        $this->getJson($url)->assertJsonPath('data.category.name', 'New category')
            ->assertJsonPath('data.suppliers.0.name', 'New supplier');
    }

    public function test_create_invalidates_cached_empty_list(): void
    {
        $category = Category::factory()->create();
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/products', [
            'category_id' => $category->id,
            'sku' => 'NEW-CACHED-ITEM',
            'name' => 'New item',
            'price' => '0.00',
            'stock_quantity' => 0,
            'supplier_ids' => [],
        ])->assertCreated();

        $this->getJson('/api/v1/products')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'NEW-CACHED-ITEM');
    }

    public function test_product_attribute_update_refreshes_cached_detail(): void
    {
        $product = Product::factory()->create(['name' => 'Before update']);
        $url = "/api/v1/products/{$product->id}";
        $this->getJson($url)->assertJsonPath('data.name', 'Before update');

        $this->patchJson($url, ['name' => 'After update'])->assertOk();
        $this->getJson($url)->assertJsonPath('data.name', 'After update');
    }

    public function test_detail_cache_reloads_product_after_revision_changes_since_route_binding(): void
    {
        $product = Product::factory()->create(['name' => 'Bound before update']);
        $staleBinding = Product::findOrFail($product->id);
        $product->update(['name' => 'Committed update']);

        $cached = app(ProductCache::class)->product($staleBinding);

        $this->assertSame('Committed update', $cached->name);
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()->assertJsonPath('data.name', 'Committed update');
    }

    public function test_detail_cache_does_not_serve_product_deleted_after_route_binding(): void
    {
        $product = Product::factory()->create();
        $staleBinding = Product::findOrFail($product->id);
        $product->delete();

        $this->expectException(ModelNotFoundException::class);
        app(ProductCache::class)->product($staleBinding);
    }

    public function test_reseeding_invalidates_cached_product_when_pivot_is_unchanged(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-LAMP-001')->firstOrFail();
        $url = "/api/v1/products/{$product->id}";
        $this->getJson($url)->assertJsonPath('data.name', 'Desk Lamp');
        DB::table('products')->where('id', $product->id)->update(['name' => 'Database edit']);
        $this->getJson($url)->assertJsonPath('data.name', 'Desk Lamp');

        $this->seed();

        $this->getJson($url)->assertJsonPath('data.name', 'Database edit');
    }

    public function test_model_edit_invalidates_only_after_transaction_commits(): void
    {
        $category = Category::factory()->create(['name' => 'Before commit']);
        $product = Product::factory()->for($category)->create();
        $url = "/api/v1/products/{$product->id}";
        $this->getJson($url)->assertJsonPath('data.category.name', 'Before commit');

        DB::transaction(function () use ($category, $url) {
            $category->update(['name' => 'After commit']);
            $this->getJson($url)->assertJsonPath('data.category.name', 'Before commit');
        });

        $this->getJson($url)->assertJsonPath('data.category.name', 'After commit');
    }

    public function test_cached_collection_links_are_generated_for_each_request_host(): void
    {
        Product::factory()->count(2)->create();

        $first = $this->getJson('http://first.test/api/v1/products?per_page=1')->assertOk();
        $this->assertStringContainsString('first.test', $first->json('links.next'));
        $second = $this->getJson('http://second.test/api/v1/products?per_page=1')->assertOk();
        $this->assertStringContainsString('second.test', $second->json('links.next'));
        $this->assertStringNotContainsString('first.test', $second->json('links.next'));
    }
}
