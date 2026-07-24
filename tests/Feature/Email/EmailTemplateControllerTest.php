<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The team-authored email-template library (CRUD). Distinct from the Meta-synced
 * WhatsApp templates — these are built, saved, edited and reused here.
 */
class EmailTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'My newsletter',
            'subject' => 'Hello from us',
            'body_html' => '<p>Hi {{name}}</p>',
        ], $overrides);
    }

    public function test_index_requires_email_view_permission(): void
    {
        $this->actingAs($this->admin)->get(route('email-templates.index'))->assertOk();

        $noRole = User::factory()->create(['is_active' => true]);
        $this->actingAs($noRole)->get(route('email-templates.index'))->assertForbidden();
    }

    public function test_store_creates_a_template_recorded_to_the_author(): void
    {
        $this->actingAs($this->admin)
            ->post(route('email-templates.store'), $this->payload())
            ->assertRedirect(route('email-templates.index'));

        $template = EmailTemplate::first();
        $this->assertNotNull($template);
        $this->assertSame('My newsletter', $template->name);
        $this->assertSame($this->admin->id, $template->user_id);
    }

    public function test_store_requires_name_and_body(): void
    {
        $this->actingAs($this->admin)
            ->post(route('email-templates.store'), ['name' => '', 'body_html' => ''])
            ->assertSessionHasErrors(['name', 'body_html']);
    }

    public function test_create_page_can_preload_a_starter_design(): void
    {
        $this->actingAs($this->admin)
            ->get(route('email-templates.create', ['starter' => 'promotion']))
            ->assertOk()
            ->assertSee('SAVE25', false); // the promotion starter's discount code
    }

    public function test_update_edits_a_template(): void
    {
        $template = EmailTemplate::factory()->create(['name' => 'Old name']);

        $this->actingAs($this->admin)
            ->put(route('email-templates.update', $template), $this->payload(['name' => 'New name']))
            ->assertRedirect(route('email-templates.index'));

        $this->assertSame('New name', $template->fresh()->name);
    }

    public function test_destroy_soft_deletes_a_template(): void
    {
        $template = EmailTemplate::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('email-templates.destroy', $template))
            ->assertRedirect(route('email-templates.index'));

        $this->assertSoftDeleted($template);
    }

    public function test_saved_templates_appear_in_the_campaign_composer(): void
    {
        EmailTemplate::factory()->create(['name' => 'Quarterly promo']);

        $this->actingAs($this->admin)
            ->get(route('email-campaigns.create'))
            ->assertOk()
            ->assertSee('Your saved templates')
            ->assertSee('Quarterly promo');
    }

    public function test_campaign_create_preloads_a_saved_template(): void
    {
        $template = EmailTemplate::factory()->create(['body_html' => '<p>Unique-marker-XYZ</p>']);

        $this->actingAs($this->admin)
            ->get(route('email-campaigns.create', ['email_template' => $template->id]))
            ->assertOk()
            ->assertSee('Unique-marker-XYZ', false);
    }
}
