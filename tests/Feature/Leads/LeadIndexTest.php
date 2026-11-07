<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LeadIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function as(string $role): static
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $this->actingAs($user);
    }

    public function test_managers_see_leads_and_agents_are_forbidden(): void
    {
        Lead::factory()->create(['name' => 'Ada Ob***i', 'company' => 'Acme Pay']);

        $this->as('manager')->get(route('leads.index'))->assertOk()->assertSee('Ada Ob***i')->assertSee('Acme Pay');
        $this->as('agent')->get(route('leads.index'))->assertForbidden();
    }

    public function test_unrevealed_emails_are_not_shown(): void
    {
        Lead::factory()->create(['name' => 'Hidden Person', 'email' => null]);
        Lead::factory()->create(['name' => 'Known Person', 'email' => 'known@acme.test', 'email_status' => 'verified']);

        $this->as('manager')->get(route('leads.index'))
            ->assertSee('known@acme.test')
            ->assertSee('Email hidden until qualified');
    }

    public function test_filters_by_status_and_profile(): void
    {
        $profile = LeadProfile::factory()->create(['name' => 'Fintech']);
        Lead::factory()->create(['name' => 'In Profile', 'lead_profile_id' => $profile->id, 'status' => Lead::STATUS_QUALIFIED]);
        Lead::factory()->create(['name' => 'Other One', 'status' => Lead::STATUS_NEW]);

        $this->as('manager')->get(route('leads.index', ['status' => 'qualified']))
            ->assertSee('In Profile')->assertDontSee('Other One');

        $this->get(route('leads.index', ['profile' => $profile->id]))
            ->assertSee('In Profile')->assertDontSee('Other One');
    }

    public function test_an_unknown_status_filter_is_ignored(): void
    {
        Lead::factory()->create(['name' => 'Visible Lead']);

        $this->as('manager')->get(route('leads.index', ['status' => 'bogus']))->assertOk()->assertSee('Visible Lead');
    }

    public function test_empty_state(): void
    {
        $this->as('manager')->get(route('leads.index'))->assertOk()->assertSee('No leads yet');
    }

    public function test_the_list_is_paginated(): void
    {
        Lead::factory()->count(60)->create();

        $this->as('manager')->get(route('leads.index'))->assertOk()->assertSee('page=2', false);
    }

    public function test_sidebar_shows_the_leads_section_to_managers_only(): void
    {
        $this->as('manager')->get(route('dashboard'))
            ->assertSee(route('leads.index'), false)
            ->assertSee(route('lead-profiles.index'), false)
            ->assertSee(route('lead-sources.index'), false);

        $this->as('agent')->get(route('dashboard'))
            ->assertDontSee(route('leads.index'), false)
            ->assertDontSee(route('lead-profiles.index'), false);
    }
}
