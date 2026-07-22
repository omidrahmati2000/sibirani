<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'price_rials' => fake()->numberBetween(500, 20000),
            'stock' => fake()->numberBetween(0, 50),
        ];
    }
}
