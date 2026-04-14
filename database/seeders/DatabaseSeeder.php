<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@blastiq.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $settings = [
            'evolution_api_url' => 'http://localhost:8080',
            'evolution_api_key' => 'changeme',
            'webhook_secret' => 'changeme-secret',
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
    }
}
