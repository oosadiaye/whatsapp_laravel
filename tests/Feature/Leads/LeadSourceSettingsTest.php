<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\User;
use App\Support\LeadConfig;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LeadSourceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH = 'https://api.apollo.io/api/v1/mixed_people/api_search*';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.apollo.key' => null]);
    }

    private function as(string $role): static
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $this->actingAs($user);
    }

    public function test_managers_can_open_the_page_and_agents_cannot(): void
    {
        $this->as('manager')->get(route('lead-sources.index'))->assertOk()->assertSee('Apollo.io');
        $this->as('agent')->get(route('lead-sources.index'))->assertForbidden();
    }

    public function test_the_page_shows_whether_a_key_is_saved_but_never_the_key(): void
    {
        LeadConfig::setKey('apollo', 'super-secret-key-999');

        $this->as('admin')->get(route('lead-sources.index'))
            ->assertOk()
            ->assertSee('Key saved')
            ->assertDontSee('super-secret-key-999');
    }

    public function test_saving_a_key_stores_it_encrypted(): void
    {
        $this->as('admin')->put(route('lead-sources.update', 'apollo'), ['api_key' => 'new-key-1234567890'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('new-key-1234567890', LeadConfig::key('apollo'));
    }

    public function test_a_short_or_missing_key_is_rejected(): void
    {
        $this->as('admin')->put(route('lead-sources.update', 'apollo'), ['api_key' => 'x'])
            ->assertSessionHasErrors('api_key');
    }

    public function test_an_unknown_source_is_a_404(): void
    {
        $this->as('admin')->put(route('lead-sources.update', 'nope'), ['api_key' => 'new-key-1234567890'])->assertNotFound();
    }

    public function test_agents_cannot_save_a_key(): void
    {
        $this->as('agent')->put(route('lead-sources.update', 'apollo'), ['api_key' => 'new-key-1234567890'])->assertForbidden();
    }

    public function test_connection_test_reports_success(): void
    {
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
        Http::fake([self::SEARCH => Http::response(['total_entries' => 1, 'people' => []])]);

        $this->as('admin')->post(route('lead-sources.test', 'apollo'))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_connection_test_reports_the_platform_error(): void
    {
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
        Http::fake([self::SEARCH => Http::response('no', 401)]);

        $this->as('admin')->post(route('lead-sources.test', 'apollo'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'API key'));
    }

    public function test_connection_test_without_a_key_asks_for_one_and_sends_nothing(): void
    {
        Http::fake();

        $this->as('admin')->post(route('lead-sources.test', 'apollo'))->assertSessionHas('warning');

        Http::assertNothingSent();
    }

    public function test_the_kill_switch_can_be_turned_on_and_off(): void
    {
        $this->as('admin')->post(route('lead-sources.kill-switch'), ['on' => 1])->assertRedirect();
        $this->assertTrue(LeadConfig::killSwitchOn());

        $this->post(route('lead-sources.kill-switch'), ['on' => 0])->assertRedirect();
        $this->assertFalse(LeadConfig::killSwitchOn());
    }
}
