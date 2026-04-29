<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent: firstOrCreate so re-seeding a populated DB doesn't
        // collide on the unique email index.
        User::firstOrCreate(
            ['email' => 'admin@blastiq.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $settings = [
            'default_rate_per_minute' => '10',
            'default_delay_min' => '2',
            'default_delay_max' => '8',
            'default_country_code' => '234',
            'app_name' => 'BlastIQ',
            'timezone' => 'Africa/Lagos',
        ];

        foreach ($settings as $key => $value) {
            Setting::set($key, $value);
        }

        // Roles + permissions + admin role assignment.
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
