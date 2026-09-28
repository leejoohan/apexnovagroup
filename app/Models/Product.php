<?php

namespace App\Models;

use App\Support\ProductCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['category_id', 'sku', 'name', 'description', 'price', 'stock_quantity'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'stock_quantity' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => ProductCache::invalidateAfterCommit());
        static::deleted(fn () => ProductCache::invalidateAfterCommit());
        static::restored(fn () => ProductCache::invalidateAfterCommit());
    }

    protected function sku(): Attribute
    {
        return Attribute::make(set: fn ($value) => mb_strtoupper(trim($value)));
    }

    protected function stockStatus(): Attribute
    {
        return Attribute::make(get: fn () => match (true) {
            $this->stock_quantity === 0 => 'out_of_stock',
            $this->stock_quantity <= 10 => 'low_stock',
            default => 'in_stock',
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class);
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        foreach (['min_price' => ['price', '>='], 'max_price' => ['price', '<='],
            'min_stock' => ['stock_quantity', '>='], 'max_stock' => ['stock_quantity', '<=']] as $key => [$column, $operator]) {
            if (isset($filters[$key])) {
                $query->where($column, $operator, $filters[$key]);
            }
        }
        if (isset($filters['stock_status'])) {
            match ($filters['stock_status']) {
                'out_of_stock' => $query->where('stock_quantity', 0),
                'low_stock' => $query->whereBetween('stock_quantity', [1, 10]),
                'in_stock' => $query->where('stock_quantity', '>', 10),
            };
        }

        return $query;
    }
}
