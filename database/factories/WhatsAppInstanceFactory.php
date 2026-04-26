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
            'driver' => WhatsAppInstance::DRIVER_CLOUD,
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

    /** Configure as a Cloud API instance with realistic-looking credentials. */
    public function cloud(): self
    {
        return $this->state(['driver' => WhatsAppInstance::DRIVER_CLOUD]);
    }

    /** Configure as a legacy Evolution/Baileys instance with QR-code setup. */
    public function evolution(): self
    {
        return $this->state([
            'driver' => WhatsAppInstance::DRIVER_EVOLUTION,
            'waba_id' => null,
            'phone_number_id' => null,
            'access_token' => null,
            'app_secret' => null,
            'webhook_verify_token' => null,
            'api_token' => $this->faker->regexify('[A-Z0-9]{40}'),
        ]);
    }
}
