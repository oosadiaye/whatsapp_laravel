<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\WhatsAppInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppInstance>
 */
class WhatsAppInstanceFactory extends Factory
{
    protected $model = WhatsAppInstance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'instance_name' => $this->faker->unique()->slug(2),
            'display_name' => $this->faker->company(),
            'phone_number' => null,
            'business_phone_number' => '+'.$this->faker->numerify('##############'),
            'status' => 'CONNECTED',
            'is_default' => false,
            'waba_id' => $this->faker->numerify('################'),
            'phone_number_id' => $this->faker->unique()->numerify('################'),
            'access_token' => 'EAA'.$this->faker->regexify('[A-Za-z0-9]{50}'),
            'app_secret' => $this->faker->regexify('[a-f0-9]{32}'),
            'webhook_verify_token' => $this->faker->regexify('[A-Za-z0-9]{32}'),
        ];
    }
}
