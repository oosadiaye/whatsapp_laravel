<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the controller actions that talk to Meta:
 *   - sync() — pulls every template Meta has for an instance and upserts.
 *   - submitToMeta() — pushes a local template up for review.
 *
 * Both actions call WhatsAppCloudApiService → graph.facebook.com under the
 * hood; we use Http::fake() to stub responses and assert both the outbound
 * payload AND the resulting DB state.
 */
class MessageTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Phase 11+ permission gates require seeded roles. Phase 15 added
        // permission middleware to /templates/* — every test user needs a
        // role with template permissions to reach the controller actions.
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Helper: factory user + super_admin role so all permission gates pass.
     */
    private function makeAdmin(?string $email = null): User
    {
        $user = User::factory()->create($email ? ['email' => $email] : []);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_sync_creates_local_rows_from_meta_templates(): void
    {
        $user = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $user->id]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [
                    [
                        'id' => '111',
                        'name' => 'order_confirmation',
                        'language' => 'en_US',
                        'status' => 'APPROVED',
                        'category' => 'UTILITY',
                        'components' => [['type' => 'BODY', 'text' => 'Order #{{1}} confirmed']],
                    ],
                    [
                        'id' => '222',
                        'name' => 'welcome_promo',
                        'language' => 'en_US',
                        'status' => 'PENDING',
                        'category' => 'MARKETING',
                        'components' => [['type' => 'BODY', 'text' => 'Welcome {{1}}!']],
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('templates.sync'), ['whatsapp_instance_id' => $instance->id])
            ->assertRedirect(route('templates.index'))
            ->assertSessionHas('success');

        $this->assertSame(2, MessageTemplate::count());

        $approved = MessageTemplate::where('whatsapp_template_id', '111')->first();
        $this->assertSame('APPROVED', $approved->status);
        $this->assertSame('transactional', $approved->category);  // Meta UTILITY → local transactional
        $this->assertSame('Order #{{1}} confirmed', $approved->content);

        $pending = MessageTemplate::where('whatsapp_template_id', '222')->first();
        $this->assertSame('PENDING', $pending->status);
        $this->assertSame('promotional', $pending->category);  // Meta MARKETING → local promotional
    }

    public function test_sync_updates_existing_row_on_re_run(): void
    {
        // Idempotency: running sync twice mustn't create duplicate rows.
        $user = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $user->id]);

        // Pre-existing PENDING row for the same template.
        MessageTemplate::factory()->remote()->create([
            'user_id' => $user->id,
            'whatsapp_instance_id' => $instance->id,
            'whatsapp_template_id' => '111',
            'language' => 'en_US',
            'status' => 'PENDING',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [[
                    'id' => '111',
                    'name' => 'order_confirmation',
                    'language' => 'en_US',
                    'status' => 'APPROVED',  // upgraded since last sync
                    'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']],
                ]],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('templates.sync'), ['whatsapp_instance_id' => $instance->id]);

        // Still one row, status flipped to APPROVED.
        $this->assertSame(1, MessageTemplate::count());
        $this->assertSame('APPROVED', MessageTemplate::first()->status);
    }

    public function test_sync_rejects_unconfigured_cloud_instance(): void
    {
        $user = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $user->id,
            'access_token' => null,  // Missing — instance isn't ready
        ]);

        Http::fake();
        $this->actingAs($user)
            ->post(route('templates.sync'), ['whatsapp_instance_id' => $instance->id])
            ->assertRedirect(route('templates.index'))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_sync_warns_when_meta_returns_empty_list(): void
    {
        $user = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $user->id]);

        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []], 200)]);

        $this->actingAs($user)
            ->post(route('templates.sync'), ['whatsapp_instance_id' => $instance->id])
            ->assertSessionHas('warning');

        $this->assertSame(0, MessageTemplate::count());
    }

    public function test_any_admin_can_sync_any_instance_single_tenant(): void
    {
        // The app is single-tenant — WhatsApp instances are shared, so any
        // admin (or user with the templates.* permission) can sync templates
        // against any instance, including ones first set up by another user.
        // The route is gated by templates.* permissions, not by ownership.
        //
        // This replaces a previous multi-tenant test that asserted 404 when
        // user A targeted user B's instance. That tenancy boundary was
        // removed deliberately when the team flipped to single-tenant mode.
        $userA = $this->makeAdmin();
        $userB = $this->makeAdmin('userb-shared@example.com');
        $instanceB = WhatsAppInstance::factory()->create(['user_id' => $userB->id]);

        // No HTTP fake — the test just verifies the route accepts the call
        // and tries to reach Meta. Whether the Meta probe succeeds (200) or
        // fails-and-warns (302 with warning flash) both prove the controller
        // accepted the cross-user instance ID, which is the contract.
        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => []], 200),
        ]);

        $response = $this->actingAs($userA)
            ->post(route('templates.sync'), ['whatsapp_instance_id' => $instanceB->id]);

        // Either a success redirect or a fail-with-warning redirect is fine
        // — the assertion that matters is "the controller did NOT 404 because
        // the instance belongs to a different user."
        $this->assertNotEquals(404, $response->status());
    }

    public function test_submit_to_meta_pushes_template_and_captures_response(): void
    {
        $user = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $user->id]);
        $template = MessageTemplate::factory()->create([
            'user_id' => $user->id,
            'name' => 'order_shipped',
            'content' => 'Hi {{1}}, your order is shipped',
            'category' => 'transactional',
            'language' => 'en_US',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => '999_meta_id',
                'status' => 'PENDING',
                'category' => 'UTILITY',
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('templates.submit', $template), ['whatsapp_instance_id' => $instance->id])
            ->assertRedirect(route('templates.index'))
            ->assertSessionHas('success');

        $template->refresh();
        $this->assertSame('999_meta_id', $template->whatsapp_template_id);
        $this->assertSame('PENDING', $template->status);
        $this->assertSame($instance->id, $template->whatsapp_instance_id);
        $this->assertNotNull($template->synced_at);

        // Verify outbound payload shape.
        Http::assertSent(function ($request) {
            return $request['name'] === 'order_shipped'
                && $request['language'] === 'en_US'
                && $request['category'] === 'UTILITY'  // local transactional → Meta UTILITY
                && is_array($request['components']);
        });
    }

    public function test_submit_to_meta_blocks_already_remote_template(): void
    {
        $user = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $user->id]);
        $template = MessageTemplate::factory()->remote()->create([
            'user_id' => $user->id,
            'whatsapp_instance_id' => $instance->id,
        ]);

        Http::fake();

        $this->actingAs($user)
            ->post(route('templates.submit', $template), ['whatsapp_instance_id' => $instance->id])
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_destroy_remote_template_also_calls_meta_delete(): void
    {
        $user = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $user->id]);
        $template = MessageTemplate::factory()->remote()->create([
            'user_id' => $user->id,
            'whatsapp_instance_id' => $instance->id,
            'name' => 'old_template',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

        $this->actingAs($user)
            ->delete(route('templates.destroy', $template))
            ->assertRedirect(route('templates.index'));

        // Local row removed (soft-deleted by SoftDeletes trait).
        $this->assertSoftDeleted('message_templates', ['id' => $template->id]);

        // Outbound DELETE call made to Meta.
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), 'message_templates')
            && $request['name'] === 'old_template');
    }

    public function test_destroy_local_template_does_not_call_meta(): void
    {
        $user = $this->makeAdmin();
        $template = MessageTemplate::factory()->create(['user_id' => $user->id]);

        Http::fake();

        $this->actingAs($user)
            ->delete(route('templates.destroy', $template))
            ->assertRedirect(route('templates.index'));

        Http::assertNothingSent();
    }
}
