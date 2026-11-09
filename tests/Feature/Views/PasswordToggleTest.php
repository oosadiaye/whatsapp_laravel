<?php

declare(strict_types=1);

namespace Tests\Feature\Views;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class PasswordToggleTest extends TestCase
{
    public function test_password_inputs_get_an_eye_toggle_and_other_inputs_do_not(): void
    {
        $password = Blade::render('<x-text-input name="password" type="password" class="w-full" />');
        $this->assertStringContainsString("shown ? 'text' : 'password'", $password);
        $this->assertStringContainsString('pr-10', $password);

        $text = Blade::render('<x-text-input name="email" type="email" />');
        $this->assertStringNotContainsString('shown', $text);
    }
}
