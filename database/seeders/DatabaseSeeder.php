<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // los permisos de Scoring van aqui porque `RoleAndPermissionSeeder` usa
        // Permission::create() y re-sembrarlo revienta contra el unico de spatie;
        // este seeder es idempotente (firstOrCreate) y tambien se puede correr
        // solo: php artisan db:seed --class=ScoringPermissionSeeder
        $this->call(ScoringPermissionSeeder::class);
    }
}
