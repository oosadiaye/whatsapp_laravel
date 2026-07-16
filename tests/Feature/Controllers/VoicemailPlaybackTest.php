<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Conversation;
use App\Models\User;
use App\Models\Voicemail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoicemailPlaybackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('admin');

        return $u;
    }

    public function test_playback_streams_through_the_app_not_the_raw_at_url(): void
    {
        Http::fake(['voice.africastalking.com/*' => Http::response('AUDIO-BYTES', 200, ['Content-Type' => 'audio/mpeg'])]);
        $vm = Voicemail::factory()->create(['recording_url' => 'https://voice.africastalking.com/rec/x.mp3']);

        $res = $this->actingAs($this->admin())->get(route('voicemails.download', $vm));

        $res->assertOk();
        $this->assertSame('AUDIO-BYTES', $res->getContent());
        $this->assertStringContainsString('audio/mpeg', $res->headers->get('Content-Type'));
    }

    public function test_playback_requires_conversation_visibility(): void
    {
        $vm = Voicemail::factory()->create();
        $noRole = User::factory()->create(['is_active' => true]);

        $this->actingAs($noRole)->get(route('voicemails.download', $vm))->assertForbidden();
    }

    public function test_inbox_links_the_proxy_not_the_raw_url(): void
    {
        $vm = Voicemail::factory()->create(['recording_url' => 'https://voice.africastalking.com/rec/secret.mp3']);

        $res = $this->actingAs($this->admin())->get(route('voicemails.index'));

        $res->assertSee(route('voicemails.download', $vm), false);
        $res->assertDontSee('voice.africastalking.com/rec/secret.mp3', false);
    }

    public function test_ssrf_a_non_allowlisted_host_is_rejected_and_never_fetched(): void
    {
        Http::fake(); // must not be hit
        $vm = Voicemail::factory()->create(['recording_url' => 'https://evil.example.com/x.mp3']);

        $this->actingAs($this->admin())->get(route('voicemails.download', $vm))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_agent_cannot_play_a_voicemail_not_assigned_to_them(): void
    {
        Http::fake();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole('agent'); // has conversations.view_assigned

        $someoneElse = User::factory()->create(['is_active' => true]);
        $conv = Conversation::factory()->create(['assigned_to_user_id' => $someoneElse->id]);
        $vm = Voicemail::factory()->create([
            'conversation_id' => $conv->id,
            'recording_url' => 'https://voice.africastalking.com/r.mp3',
        ]);

        $this->actingAs($agent)->get(route('voicemails.download', $vm))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_agent_can_play_a_voicemail_assigned_to_them(): void
    {
        Http::fake(['voice.africastalking.com/*' => Http::response('A', 200, ['Content-Type' => 'audio/mpeg'])]);
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole('agent');

        $conv = Conversation::factory()->create(['assigned_to_user_id' => $agent->id]);
        $vm = Voicemail::factory()->create([
            'conversation_id' => $conv->id,
            'recording_url' => 'https://voice.africastalking.com/r.mp3',
        ]);

        $this->actingAs($agent)->get(route('voicemails.download', $vm))->assertOk();
    }
}
