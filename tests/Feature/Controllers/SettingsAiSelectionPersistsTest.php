<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\User;
use App\Support\AiConfig;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an admin saves on Settings must be what the AI pipeline actually uses.
 * The form stores provider/model as plain settings while AiConfig used to read
 * them as encrypted, so the choice was silently ignored.
 */
final class SettingsAiSelectionPersistsTest extends TestCase
{
    use RefreshDatabase;

    private function saveAiSettings(array $fields): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->put(route('settings.update'), $fields)
            ->assertSessionHasNoErrors();
    }

    public function test_saved_provider_is_what_ai_config_uses(): void
    {
        config(['ai.provider' => 'gemini']);

        $this->saveAiSettings(['ai_provider' => 'openrouter']);

        $this->assertSame('openrouter', AiConfig::provider());
    }

    public function test_saved_models_are_what_ai_config_uses(): void
    {
        $this->saveAiSettings([
            'ai_provider' => 'openrouter',
            'ai_model_draft' => 'openai/gpt-4o',
            'ai_model_classify' => 'meta-llama/llama-3.3-70b-instruct:free',
            'ai_model_summarise' => 'google/gemma-3-27b-it:free',
        ]);

        $this->assertSame('openai/gpt-4o', AiConfig::model('draft'));
        $this->assertSame('meta-llama/llama-3.3-70b-instruct:free', AiConfig::model('classify'));
        $this->assertSame('google/gemma-3-27b-it:free', AiConfig::model('summarise'));
    }

    public function test_settings_page_shows_the_saved_selection(): void
    {
        $this->saveAiSettings(['ai_provider' => 'openrouter', 'ai_model_draft' => 'openai/gpt-4o']);

        $this->get(route('settings.index'))
            ->assertSee('data-value="openai/gpt-4o"', false);
    }

    public function test_openrouter_never_gets_a_non_openrouter_model_id(): void
    {
        // Provider switched to OpenRouter but no model picked: config still holds the Gemini default.
        $this->saveAiSettings(['ai_provider' => 'openrouter']);

        foreach (['draft', 'classify', 'summarise'] as $tier) {
            $this->assertStringContainsString('/', AiConfig::model($tier));
        }
    }

    public function test_gemini_keeps_its_own_model_ids(): void
    {
        $this->saveAiSettings(['ai_provider' => 'gemini']);

        $this->assertSame('gemini-2.0-flash', AiConfig::model('draft'));
    }
}
