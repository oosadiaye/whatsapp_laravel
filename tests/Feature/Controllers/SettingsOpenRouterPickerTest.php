<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SettingsOpenRouterPickerTest extends TestCase
{
    use RefreshDatabase;

    private function adminPage(): \Illuminate\Testing\TestResponse
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $this->actingAs($admin)->get(route('settings.index'));
    }

    public function test_page_renders_a_catalogue_picker_for_each_tier(): void
    {
        $html = $this->adminPage()->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'x-data="bqModelPicker()"'));
        foreach (['ai_model_draft', 'ai_model_classify', 'ai_model_summarise'] as $field) {
            $this->assertStringContainsString('data-name="'.$field.'"', $html);
        }
        $this->assertStringContainsString('data-url="'.route('settings.openrouter-models').'"', $html);
        $this->assertStringContainsString('Accepts images', $html);
    }

    public function test_picker_starts_on_the_saved_openrouter_model(): void
    {
        Setting::setEncrypted('ai_model_draft', 'openai/gpt-4o');

        $this->adminPage()->assertSee('data-value="openai/gpt-4o"', false);
    }

    public function test_gemini_select_always_lists_gemini_models_even_when_openrouter_is_saved(): void
    {
        Setting::setEncrypted('ai_provider', 'openrouter');

        $html = $this->adminPage()->getContent();

        $this->assertStringContainsString('value="gemini-2.0-flash"', $html);
        $this->assertStringNotContainsString('value="anthropic/claude-3.5-sonnet"', $html);
    }

    public function test_only_the_active_providers_model_fields_are_submitted(): void
    {
        $html = $this->adminPage()->getContent();

        // Gemini selects are disabled unless Gemini is chosen, picker inputs unless OpenRouter is.
        $this->assertStringContainsString(':disabled="provider !== \'gemini\'"', $html);
        $this->assertStringContainsString(':disabled="provider !== \'openrouter\'"', $html);
    }
}
