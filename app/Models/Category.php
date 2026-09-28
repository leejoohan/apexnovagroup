<?php

namespace App\Models;

use App\Support\ProductCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    protected static function booted(): void
    {
        static::saved(fn () => ProductCache::invalidateAfterCommit());
        static::deleted(fn () => ProductCache::invalidateAfterCommit());
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
