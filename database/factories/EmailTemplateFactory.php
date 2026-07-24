<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => ucfirst($this->faker->words(2, true)),
            'subject' => $this->faker->sentence(4),
            'body_html' => '<p>Hello {{name}}, '.$this->faker->sentence().'</p>',
        ];
    }
}
