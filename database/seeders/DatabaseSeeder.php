<?php

namespace Database\Seeders;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Local development data. Never runs in production. */
    public function run(): void
    {
        \Illuminate\Support\Facades\Artisan::call('t2t:locations:import');
        User::factory()->create(['first_name' => 'Jordan', 'last_name' => 'Hale', 'email' => 'jordan@example.com']);
    }
}
