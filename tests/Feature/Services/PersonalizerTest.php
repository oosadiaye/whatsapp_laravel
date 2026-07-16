<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Contact;
use App\Support\Personalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalizerTest extends TestCase
{
    use RefreshDatabase;

    private function contact(array $attrs = []): Contact
    {
        return Contact::factory()->make(array_merge([
            'name' => 'Ada Lovelace',
            'phone' => '2348011112222',
            'email' => 'ada@example.com',
            'custom_fields' => ['custom_field_1' => 'Lagos', 'custom_field_2' => 'VIP'],
        ], $attrs));
    }

    public function test_field_resolves_each_key(): void
    {
        $p = new Personalizer();
        $c = $this->contact();

        $this->assertSame('Ada Lovelace', $p->field($c, 'name'));
        $this->assertSame('Ada', $p->field($c, 'first_name'));
        $this->assertSame('2348011112222', $p->field($c, 'phone'));
        $this->assertSame('ada@example.com', $p->field($c, 'email'));
        $this->assertSame('Lagos', $p->field($c, 'custom_field_1'));
        $this->assertSame('', $p->field($c, 'nonsense'));
    }

    public function test_positional_uses_the_default_config_order(): void
    {
        $p = new Personalizer();
        $c = $this->contact();

        $this->assertSame('Ada Lovelace', $p->positional($c, 1)); // display_name
        $this->assertSame('2348011112222', $p->positional($c, 2)); // phone
        $this->assertSame('Ada', $p->positional($c, 3)); // first_name
        $this->assertSame('Lagos', $p->positional($c, 4)); // custom_field_1
    }

    public function test_positional_follows_a_non_default_ordering_from_config(): void
    {
        // A template whose {{1}} is the phone and {{2}} is the email — the whole
        // point of the config-driven map (the old hardcode got this wrong).
        config(['personalization.template_variables' => [1 => 'phone', 2 => 'email', 3 => 'custom_field_2']]);

        $p = new Personalizer();
        $c = $this->contact();

        $this->assertSame('2348011112222', $p->positional($c, 1));
        $this->assertSame('ada@example.com', $p->positional($c, 2));
        $this->assertSame('VIP', $p->positional($c, 3));
        $this->assertSame('', $p->positional($c, 4)); // unmapped
    }

    public function test_display_name_falls_back_to_phone(): void
    {
        $p = new Personalizer();
        $this->assertSame('2348011112222', $p->positional($this->contact(['name' => null]), 1));
    }

    public function test_named_replaces_tokens_with_friend_fallback(): void
    {
        $p = new Personalizer();

        $this->assertSame(
            'Hi Ada Lovelace (Ada) at ada@example.com',
            $p->named($this->contact(), 'Hi {{name}} ({{first_name}}) at {{email}}'),
        );
        $this->assertSame('Hi Friend', $p->named($this->contact(['name' => null]), 'Hi {{name}}'));
    }
}
