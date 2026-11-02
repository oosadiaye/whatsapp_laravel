<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\User;
use App\Services\Ai\OpenRouterModelCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenRouterModelCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://openrouter.ai/api/v1/models';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function fakeModels(): void
    {
        Http::fake([
            self::URL => Http::response(['data' => [
                [
                    'id' => 'openai/gpt-4o',
                    'name' => 'OpenAI: GPT-4o',
                    'context_length' => 128000,
                    'pricing' => ['prompt' => '0.0000025', 'completion' => '0.00001'],
                    'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']],
                ],
                [
                    'id' => 'meta-llama/llama-3.3-70b-instruct:free',
                    'name' => 'Meta: Llama 3.3 70B Instruct (free)',
                    'context_length' => 65536,
                    'pricing' => ['prompt' => '0', 'completion' => '0'],
                    'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
                ],
                [
                    'id' => 'google/gemma-3-27b-it:free',
                    'name' => 'Google: Gemma 3 27B (free)',
                    'context_length' => 96000,
                    'pricing' => ['prompt' => '0', 'completion' => '0'],
                    'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']],
                ],
                [
                    // Router with variable pricing: negative price means "depends", not free.
                    'id' => 'openrouter/auto',
                    'name' => 'Auto Router',
                    'context_length' => 2000000,
                    'pricing' => ['prompt' => '-1', 'completion' => '-1'],
                    'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
                ],
                [
                    // Image generator: cannot answer a text prompt, so not useful here.
                    'id' => 'black-forest-labs/flux',
                    'name' => 'Flux',
                    'context_length' => 4096,
                    'pricing' => ['prompt' => '0.00001', 'completion' => '0.00001'],
                    'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['image']],
                ],
            ]]),
        ]);
    }

    public function test_normalises_free_and_image_capable_models(): void
    {
        $this->fakeModels();

        $byId = collect(app(OpenRouterModelCatalog::class)->all())->keyBy('id');

        $this->assertTrue($byId['openai/gpt-4o']['vision']);
        $this->assertFalse($byId['openai/gpt-4o']['free']);
        $this->assertSame(128000, $byId['openai/gpt-4o']['context']);

        $this->assertTrue($byId['meta-llama/llama-3.3-70b-instruct:free']['free']);
        $this->assertFalse($byId['meta-llama/llama-3.3-70b-instruct:free']['vision']);

        $this->assertTrue($byId['google/gemma-3-27b-it:free']['free']);
        $this->assertTrue($byId['google/gemma-3-27b-it:free']['vision']);
    }

    public function test_variable_priced_router_is_not_marked_free(): void
    {
        $this->fakeModels();

        $auto = collect(app(OpenRouterModelCatalog::class)->all())->firstWhere('id', 'openrouter/auto');

        $this->assertFalse($auto['free']);
    }

    public function test_models_that_cannot_output_text_are_dropped(): void
    {
        $this->fakeModels();

        $ids = collect(app(OpenRouterModelCatalog::class)->all())->pluck('id');

        $this->assertNotContains('black-forest-labs/flux', $ids);
    }

    public function test_result_is_cached_so_the_api_is_hit_once(): void
    {
        $this->fakeModels();

        $catalog = app(OpenRouterModelCatalog::class);
        $catalog->all();
        $catalog->all();

        Http::assertSentCount(1);
    }

    public function test_failure_returns_empty_and_is_not_cached(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push('boom', 500)
            ->push(['data' => [['id' => 'a/b', 'name' => 'A B', 'pricing' => ['prompt' => '0', 'completion' => '0']]]])]);

        $catalog = app(OpenRouterModelCatalog::class);
        $this->assertSame([], $catalog->all());
        $this->assertCount(1, $catalog->all());
    }

    public function test_endpoint_returns_models_for_settings_editors(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fakeModels();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->getJson(route('settings.openrouter-models'))
            ->assertOk()
            ->assertJsonPath('models.0.id', fn ($id) => is_string($id))
            ->assertJsonCount(4, 'models');
    }

    public function test_endpoint_reports_unavailable_catalogue(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::fake([self::URL => Http::response('boom', 500)]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->getJson(route('settings.openrouter-models'))
            ->assertStatus(503)
            ->assertJsonPath('models', []);
    }

    public function test_endpoint_is_forbidden_without_settings_edit(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agent = User::factory()->create();
        $agent->assignRole('agent');

        $this->actingAs($agent)->getJson(route('settings.openrouter-models'))->assertForbidden();
    }
}
