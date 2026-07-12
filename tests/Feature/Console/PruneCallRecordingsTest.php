<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CallLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneCallRecordingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_deletes_old_audio_but_keeps_the_transcript(): void
    {
        config(['voice.recording_retention_days' => 30]);
        Storage::put('call-recordings/old.webm', 'old-audio');

        $old = CallLog::factory()->create([
            'recording_path' => 'call-recordings/old.webm',
            'recording_mime' => 'audio/webm',
            'recording_uploaded_at' => now()->subDays(40),
            'transcript' => 'Kept transcript.',
            'ai_summary' => 'Kept summary.',
            'ai_status' => CallLog::AI_STATUS_COMPLETED,
        ]);

        $this->artisan('calls:prune-recordings')->assertSuccessful();

        Storage::assertMissing('call-recordings/old.webm');
        $old->refresh();
        $this->assertNull($old->recording_path);
        $this->assertNull($old->recording_uploaded_at);
        // The useful, low-sensitivity record survives.
        $this->assertSame('Kept transcript.', $old->transcript);
        $this->assertSame('Kept summary.', $old->ai_summary);
    }

    public function test_keeps_recordings_inside_the_retention_window(): void
    {
        config(['voice.recording_retention_days' => 30]);
        Storage::put('call-recordings/recent.webm', 'recent-audio');

        $recent = CallLog::factory()->create([
            'recording_path' => 'call-recordings/recent.webm',
            'recording_uploaded_at' => now()->subDays(5),
        ]);

        $this->artisan('calls:prune-recordings')->assertSuccessful();

        Storage::assertExists('call-recordings/recent.webm');
        $this->assertSame('call-recordings/recent.webm', $recent->fresh()->recording_path);
    }

    public function test_disabled_when_retention_is_zero(): void
    {
        config(['voice.recording_retention_days' => 0]);
        Storage::put('call-recordings/old.webm', 'old-audio');

        $old = CallLog::factory()->create([
            'recording_path' => 'call-recordings/old.webm',
            'recording_uploaded_at' => now()->subDays(400),
        ]);

        $this->artisan('calls:prune-recordings')->assertSuccessful();

        Storage::assertExists('call-recordings/old.webm');
        $this->assertNotNull($old->fresh()->recording_path);
    }
}
