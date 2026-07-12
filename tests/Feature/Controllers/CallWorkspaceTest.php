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
