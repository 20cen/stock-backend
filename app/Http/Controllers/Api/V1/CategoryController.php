<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Catégories d'articles. Pas de suppression : une catégorie inutilisée est désactivée.
 */
class CategoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::withCount('articles')->orderBy('name')->get());
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = DB::transaction(function () use ($request) {
            $category = Category::create($request->validated())->refresh();
            $this->audit->log('category.created', $category, after: $request->validated());

            return $category;
        });

        return (new CategoryResource($category->loadCount('articles')))->response()->setStatusCode(201);
    }

    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        DB::transaction(function () use ($request, $category) {
            $before = Arr::only($category->toArray(), ['name', 'description', 'active']);
            $category->update($request->validated());
            $this->audit->log('category.updated', $category, before: $before, after: $request->validated());
        });

        return new CategoryResource($category->loadCount('articles'));
    }
}
