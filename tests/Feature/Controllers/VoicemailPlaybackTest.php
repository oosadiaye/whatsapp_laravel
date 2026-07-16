<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

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
}
