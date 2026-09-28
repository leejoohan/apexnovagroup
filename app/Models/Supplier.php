<?php

namespace App\Models;

use App\Support\ProductCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'phone'];

    protected static function booted(): void
    {
        static::saved(fn () => ProductCache::invalidateAfterCommit());
        static::deleted(fn () => ProductCache::invalidateAfterCommit());
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
