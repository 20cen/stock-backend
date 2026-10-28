<?php

namespace Tests\Feature\Catalog;

use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_renames_and_deactivates_a_category(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());

        $id = $this->postJson('/api/v1/categories', ['name' => '  Câbles  '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Câbles')
            ->json('data.id');

        $this->putJson("/api/v1/categories/{$id}", ['name' => 'Câbles réseau', 'active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Câbles réseau')
            ->assertJsonPath('data.active', false);

        $this->assertSame(2, AuditLog::where('action', 'like', 'category.%')->count());
    }

    public function test_a_category_name_is_unique_regardless_of_case(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());
        Category::create(['name' => 'Papeterie']);

        $this->postJson('/api/v1/categories', ['name' => 'PAPETERIE'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Cette catégorie existe déjà.');
    }

    public function test_the_list_counts_articles_and_cashier_can_read_but_not_write(): void
    {
        $category = Category::create(['name' => 'Papeterie']);
        Article::factory()->count(2)->create(['category_id' => $category->id]);
        Sanctum::actingAs(User::factory()->cashier()->create());

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0.articles_count', 2);

        $this->postJson('/api/v1/categories', ['name' => 'Autre'])->assertForbidden();
    }
}
