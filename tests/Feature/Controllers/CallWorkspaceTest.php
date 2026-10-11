<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Jobs\TranscribeCallRecording;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CallWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_workspace_page_loads_and_lists_calls(): void
    {
        $admin = $this->makeUser('admin');
        $call = CallLog::factory()->create(['to_phone' => '2348011112222']);

        $this->actingAs($admin)
            ->get(route('calls.workspace'))
            ->assertOk()
            ->assertSee('Call Workspace');
    }

    public function test_quick_dial_keypad_is_typeable_from_the_keyboard(): void
    {
        // Agents must be able to TYPE a number instead of mouse-clicking each key.
        // handleKey is bound at WINDOW level so it fires wherever focus sits while
        // the modal is open — a dialog-scoped listener missed every keystroke once
        // focus left the dialog (e.g. after clicking the backdrop or a keypad key).
        $html = $this->actingAs($this->makeUser('admin'))
            ->get(route('calls.workspace'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '@keydown.window="handleKey($event)"',
            $html,
            'Quick Dial must listen at window level so typing works regardless of focus.'
        );

        // A second, dialog-scoped binding would run handleKey twice for any key
        // pressed inside the modal and double every digit entered.
        $this->assertStringNotContainsString(
            '@keydown="handleKey($event)"',
            $html,
            'handleKey must be bound exactly once (window level) — a dialog-scoped copy doubles each digit.'
        );

        // Enter dials and Backspace deletes, so the pad is fully usable mouse-free.
        $this->assertStringContainsString("if (k === 'Enter')", $html);
        $this->assertStringContainsString("if (k === 'Backspace')", $html);
    }

    public function test_quick_dial_x_data_is_not_truncated_by_a_stray_double_quote(): void
    {
        // Regression: the Quick Dial keypad is an inline Alpine `x-data="{ ... }"`
        // object. Because the attribute is double-quoted, ANY double-quote inside
        // the expression (even in a // comment) ends the attribute early — the
        // browser then hands Alpine a truncated, unparseable object, so every
        // press()/number/Start Call binding throws ReferenceError and the dialer
        // silently stops working ("the button and numbers are not dialing").
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('calls.workspace'))
            ->assertOk()
            ->getContent();

        $marker = strpos($html, 'open: false');
        $this->assertNotFalse($marker, 'Quick Dial component (canDial) did not render on the workspace.');

        $attrStart = strrpos(substr($html, 0, $marker), 'x-data="');
        $this->assertNotFalse($attrStart);
        $valueStart = $attrStart + strlen('x-data="');
        // An HTML double-quoted attribute value ends at its FIRST double quote —
        // this is exactly what the browser's parser sees.
        $value = substr($html, $valueStart, strpos($html, '"', $valueStart) - $valueStart);

        $this->assertStringContainsString(
            'placeCall',
            $value,
            'Quick Dial x-data is truncated before placeCall() — a stray double-quote closed the attribute early.'
        );

        $depth = 0;
        foreach (str_split($value) as $ch) {
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
            }
        }
        $this->assertSame(0, $depth, 'Quick Dial x-data has unbalanced braces — a stray double-quote truncated the attribute.');
    }

    public function test_agent_can_log_a_note_on_a_call_they_are_assigned(): void
    {
        $agent = $this->makeUser('agent');
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $agent->id]);
        $call = CallLog::factory()->create(['conversation_id' => $conversation->id]);

        $this->actingAs($agent)
            ->postJson(route('calls.notes.store', $call), ['body' => 'Customer wants a callback tomorrow.'])
            ->assertCreated()
            ->assertJsonFragment(['author' => $agent->name]);

        $this->assertDatabaseHas('call_notes', [
            'call_log_id' => $call->id,
            'user_id' => $agent->id,
            'body' => 'Customer wants a callback tomorrow.',
        ]);
    }

    public function test_note_body_is_required(): void
    {
        $admin = $this->makeUser('admin');
        $call = CallLog::factory()->create();

        $this->actingAs($admin)
            ->postJson(route('calls.notes.store', $call), ['body' => ''])
            ->assertStatus(422);
    }

    public function test_agent_cannot_note_on_a_call_not_assigned_to_them(): void
    {
        // Agent lacks conversations.view_all; the call's conversation is assigned
        // to someone else → authorizeCallAccess denies.
        $agent = $this->makeUser('agent');
        $other = $this->makeUser('agent', 'other@example.com');
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $other->id]);
        $call = CallLog::factory()->create(['conversation_id' => $conversation->id]);

        $this->actingAs($agent)
            ->postJson(route('calls.notes.store', $call), ['body' => 'sneaky'])
            ->assertForbidden();
    }

    public function test_recording_upload_stores_file_and_queues_analysis(): void
    {
        Storage::fake('local');
        Bus::fake();
        config(['voice.call_recording_enabled' => true, 'services.gemini.key' => 'k']);

        $admin = $this->makeUser('admin');
        $call = CallLog::factory()->create();

        $this->actingAs($admin)
            ->post(route('calls.recording.store', $call), [
                'audio' => UploadedFile::fake()->create('call.webm', 200, 'audio/webm'),
            ])
            ->assertOk()
            ->assertJsonFragment(['ai_status' => CallLog::AI_STATUS_PENDING]);

        $call->refresh();
        $this->assertNotNull($call->recording_path);
        Storage::assertExists($call->recording_path);
        Bus::assertDispatched(TranscribeCallRecording::class);
    }

    public function test_recording_upload_without_gemini_key_stores_but_marks_unavailable(): void
    {
        Storage::fake('local');
        Bus::fake();
        config(['voice.call_recording_enabled' => true, 'services.gemini.key' => null]);

        $admin = $this->makeUser('admin');
        $call = CallLog::factory()->create();

        $this->actingAs($admin)
            ->post(route('calls.recording.store', $call), [
                'audio' => UploadedFile::fake()->create('call.webm', 50, 'audio/webm'),
            ])
            ->assertOk()
            ->assertJsonFragment(['ai_status' => CallLog::AI_STATUS_UNAVAILABLE]);

        Bus::assertNotDispatched(TranscribeCallRecording::class);
    }

    public function test_recording_upload_blocked_when_recording_disabled(): void
    {
        config(['voice.call_recording_enabled' => false]);

        $admin = $this->makeUser('admin');
        $call = CallLog::factory()->create();

        $this->actingAs($admin)
            ->post(route('calls.recording.store', $call), [
                'audio' => UploadedFile::fake()->create('call.webm', 50, 'audio/webm'),
            ])
            ->assertForbidden();
    }

    public function test_recording_download_streams_for_authorized_user(): void
    {
        Storage::fake('local');
        Storage::put('call-recordings/rec.webm', 'audio-bytes');

        $admin = $this->makeUser('admin');
        $call = CallLog::factory()->create([
            'recording_path' => 'call-recordings/rec.webm',
            'recording_mime' => 'audio/webm',
        ]);

        $this->actingAs($admin)
            ->get(route('calls.recording.download', $call))
            ->assertOk();
    }

    public function test_recording_download_404_when_no_recording(): void
    {
        $admin = $this->makeUser('admin');
        $call = CallLog::factory()->create(['recording_path' => null]);

        $this->actingAs($admin)
            ->get(route('calls.recording.download', $call))
            ->assertNotFound();
    }

    public function test_workspace_filters_calls_by_direction_and_status(): void
    {
        $admin = $this->makeUser('admin');
        $inboundMissed = CallLog::factory()->create(['direction' => 'inbound', 'status' => CallLog::STATUS_MISSED]);
        $outboundEnded = CallLog::factory()->create(['direction' => 'outbound', 'status' => CallLog::STATUS_ENDED]);

        $response = $this->actingAs($admin)
            ->get(route('calls.workspace', ['dir' => 'inbound', 'status' => CallLog::STATUS_MISSED]));

        $response->assertOk();
        $ids = $response->viewData('calls')->pluck('id');
        $this->assertTrue($ids->contains($inboundMissed->id));
        $this->assertFalse($ids->contains($outboundEnded->id), 'filtered-out call must not appear');
    }

    public function test_wrap_up_prompt_excludes_calls_placed_by_other_users(): void
    {
        // M3: a view_all manager must only be nagged to wrap up calls THEY handled,
        // not any undispositioned call company-wide.
        $admin = $this->makeUser('admin'); // has conversations.view_all
        $other = $this->makeUser('agent', 'other@example.com');

        CallLog::factory()->create([
            'placed_by_user_id' => $other->id,
            'disposition' => null,
            'ended_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($admin)->get(route('calls.workspace'));
        $this->assertNull($response->viewData('activeCall'));

        $mine = CallLog::factory()->create([
            'placed_by_user_id' => $admin->id,
            'disposition' => null,
            'ended_at' => now()->subMinute(),
        ]);

        $response2 = $this->actingAs($admin)->get(route('calls.workspace'));
        $this->assertSame($mine->id, $response2->viewData('activeCall')?->id);
    }

    public function test_wrap_up_prompt_ignores_calls_older_than_the_recency_window(): void
    {
        // M3: a stale undispositioned call must stop nagging, not resurface forever.
        $admin = $this->makeUser('admin');
        CallLog::factory()->create([
            'placed_by_user_id' => $admin->id,
            'disposition' => null,
            'ended_at' => now()->subMinutes(30), // outside the 15-minute window
        ]);

        $response = $this->actingAs($admin)->get(route('calls.workspace'));
        $this->assertNull($response->viewData('activeCall'));
    }

    private function makeUser(string $role, ?string $email = null): User
    {
        $user = User::factory()->create([
            'email' => $email ?? $role.'-'.uniqid().'@example.com',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
