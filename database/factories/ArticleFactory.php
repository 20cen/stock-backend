<?php

namespace Database\Factories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => strtoupper(fake()->unique()->bothify('ART-####??')),
            'barcode' => fake()->unique()->ean13(),
            'designation' => fake()->words(3, true),
            'unit' => 'pièce',
            'purchase_price_reference' => '1000.00',
            'sale_price' => '1500.00',
            'alert_threshold' => 2,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
