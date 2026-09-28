<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ProductCache;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $categories = collect([
            ['name' => 'Electronics', 'slug' => 'electronics'],
            ['name' => 'Office Supplies', 'slug' => 'office-supplies'],
            ['name' => 'Home Goods', 'slug' => 'home-goods'],
        ])->mapWithKeys(fn (array $attributes) => [
            $attributes['slug'] => Category::firstOrCreate(['slug' => $attributes['slug']], $attributes),
        ]);

        $suppliers = collect([
            ['name' => 'Northstar Supply', 'email' => 'orders@northstar.example', 'phone' => '+1-555-0101'],
            ['name' => 'Clearline Wholesale', 'email' => 'sales@clearline.example', 'phone' => '+1-555-0102'],
            ['name' => 'Harbor Goods', 'email' => 'hello@harborgoods.example', 'phone' => null],
        ])->mapWithKeys(fn (array $attributes) => [
            $attributes['email'] => Supplier::firstOrCreate(['email' => $attributes['email']], $attributes),
        ]);

        $products = [
            ['sku' => 'DEMO-LAMP-001', 'name' => 'Desk Lamp', 'description' => 'Adjustable LED lamp', 'category' => 'home-goods', 'price' => '29.99', 'stock_quantity' => 25, 'suppliers' => ['orders@northstar.example']],
            ['sku' => 'DEMO-CABLE-001', 'name' => 'USB-C Cable', 'description' => 'Two-meter cable', 'category' => 'electronics', 'price' => '8.50', 'stock_quantity' => 8, 'suppliers' => ['sales@clearline.example', 'orders@northstar.example']],
            ['sku' => 'DEMO-NOTE-001', 'name' => 'Notebook', 'description' => 'A5 ruled notebook', 'category' => 'office-supplies', 'price' => '4.25', 'stock_quantity' => 0, 'suppliers' => ['hello@harborgoods.example']],
            ['sku' => 'DEMO-STAND-001', 'name' => 'Monitor Stand', 'description' => null, 'category' => 'office-supplies', 'price' => '49.00', 'stock_quantity' => 42, 'suppliers' => ['sales@clearline.example']],
        ];

        foreach ($products as $data) {
            $product = Product::withTrashed()->firstOrCreate(['sku' => $data['sku']], [
                'category_id' => $categories[$data['category']]->id,
                'name' => $data['name'],
                'description' => $data['description'],
                'price' => $data['price'],
                'stock_quantity' => $data['stock_quantity'],
            ]);
            if (! $product->trashed()) {
                $product->suppliers()->sync(collect($data['suppliers'])->map(fn (string $email) => $suppliers[$email]->id)->all());
            }
        }
        ProductCache::invalidateAfterCommit();

        $email = config('inventory.seed_user_email');
        $password = config('inventory.seed_user_password');
        $email = is_string($email) ? strtolower(trim($email)) : null;
        if (is_string($email) && $email !== '' && is_string($password) && $password !== '') {
            User::firstOrCreate(['email' => $email], ['name' => 'Inventory Demo', 'password' => $password]);
        }
    }
}
