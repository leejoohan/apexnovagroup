<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaginationRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\SupplierResource;
use App\Models\Category;
use App\Models\Supplier;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LookupController extends Controller
{
    public function categories(PaginationRequest $request): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->orderBy('id')->paginate($request->pageSize())
                ->appends($request->safe()->only(['per_page']))
        );
    }

    public function suppliers(PaginationRequest $request): AnonymousResourceCollection
    {
        return SupplierResource::collection(
            Supplier::query()->orderBy('id')->paginate($request->pageSize())
                ->appends($request->safe()->only(['per_page']))
        );
    }
}
