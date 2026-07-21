<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database for local/manual testing.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'role' => UserRole::Admin,
        ]);

        User::factory()->create([
            'name' => 'Customer',
            'email' => 'customer@example.com',
        ]);

        Product::factory()->createMany([
            ['name' => 'Apple ID (US Region)', 'slug' => 'apple-id-us', 'price_cents' => 1999, 'stock' => 5],
            ['name' => 'Netflix Premium Subscription (1 Month)', 'slug' => 'netflix-premium-1m', 'price_cents' => 999, 'stock' => 20],
            ['name' => 'Spotify Family Subscription (1 Month)', 'slug' => 'spotify-family-1m', 'price_cents' => 799, 'stock' => 0],
        ]);
    }
}
