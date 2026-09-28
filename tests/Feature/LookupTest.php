<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_categories_and_suppliers_are_paginated_lookup_resources(): void
    {
        $category = Category::factory()->create(['name' => 'Hardware']);
        $supplier = Supplier::factory()->create(['name' => 'Acme']);

        $this->getJson('/api/v1/categories?per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $category->id)
            ->assertJsonPath('data.0.name', 'Hardware')
            ->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/v1/suppliers?per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $supplier->id)
            ->assertJsonPath('data.0.name', 'Acme')
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_lookup_page_size_rejects_malformed_values(): void
    {
        $this->getJson('/api/v1/categories?per_page[]=2')->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/suppliers?per_page=0')->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_lookup_next_links_preserve_requested_page_size(): void
    {
        Category::factory()->count(3)->create();
        Supplier::factory()->count(3)->create();

        foreach (['categories', 'suppliers'] as $lookup) {
            $first = $this->getJson("/api/v1/{$lookup}?per_page=1")->assertOk();
            $next = $first->json('links.next');
            $this->assertStringContainsString('per_page=1', $next);
            $this->getJson($next)->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('meta.current_page', 2)
                ->assertJsonPath('meta.per_page', 1);
        }
    }

    public function test_seeder_populates_related_catalog_and_optional_user(): void
    {
        config()->set('inventory.seed_user_email', 'demo@example.test');
        config()->set('inventory.seed_user_password', 'local-demo-password');

        $this->seed();

        $this->assertGreaterThanOrEqual(3, Category::count());
        $this->assertGreaterThanOrEqual(3, Supplier::count());
        $this->assertTrue(Product::query()->whereHas('suppliers')->exists());
        $this->assertTrue(User::query()->where('email', 'demo@example.test')->exists());
    }

    public function test_reseeding_preserves_deleted_sku_and_normalizes_demo_email(): void
    {
        config()->set('inventory.seed_user_email', '  DEMO@EXAMPLE.TEST  ');
        config()->set('inventory.seed_user_password', 'local-demo-password');
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-LAMP-001')->firstOrFail();
        $product->delete();

        $this->seed();

        $this->assertSoftDeleted($product);
        $this->assertSame(1, Product::withTrashed()->where('sku', 'DEMO-LAMP-001')->count());
        $this->assertTrue(User::query()->where('email', 'demo@example.test')->exists());
    }
}
