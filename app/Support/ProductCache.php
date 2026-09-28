<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductCache
{
    private const TTL_SECONDS = 60;

    private const REVISION_KEY = 'catalog:revision';

    public static function invalidateAfterCommit(): void
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::afterCommit(fn () => self::invalidate());

            return;
        }

        self::invalidate();
    }

    public static function invalidate(): void
    {
        Cache::forever(self::REVISION_KEY, (string) Str::uuid());
    }

    public function products(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $normalized = collect($filters)->sortKeys()->all();
        $key = $this->key('list', [$normalized, $page, $perPage]);
        $data = Cache::remember($key, self::TTL_SECONDS, function () use ($filters, $page, $perPage) {
            $result = Product::query()->with(['category', 'suppliers'])
                ->filter($filters)->orderBy('id')->paginate($perPage, ['*'], 'page', $page);

            return ['items' => $result->items(), 'total' => $result->total()];
        });

        return new LengthAwarePaginator($data['items'], $data['total'], $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    public function product(Product $product): Product
    {
        return Cache::remember($this->key('detail', [$product->id]), self::TTL_SECONDS,
            fn () => Product::query()->with(['category', 'suppliers'])->findOrFail($product->id));
    }

    private function key(string $type, array $parts): string
    {
        $revision = Cache::get(self::REVISION_KEY, 'initial');

        return 'catalog:'.$revision.':'.$type.':'.hash('sha256', json_encode($parts));
    }
}
