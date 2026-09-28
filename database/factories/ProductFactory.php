<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('ITEM-#####-??')),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomFloat(2, 0, 1000),
            'stock_quantity' => fake()->numberBetween(0, 100),
        ];
    }
}
