<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CallLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Retention sweeper for call recordings. Deletes the raw audio file once it's
 * older than voice.recording_retention_days, keeping the (far less sensitive)
 * transcript + summary as the record of the call.
 *
 * Recording customer audio carries privacy/retention obligations; this command
 * is how the deployment honours a "delete after N days" policy and stops audio
 * from accumulating on disk forever. Disabled (no-op) when retention is 0.
 *
 * Scheduled daily in routes/console.php.
 */
class PruneCallRecordings extends Command
{
    protected $signature = 'calls:prune-recordings';

    protected $description = 'Delete call recording audio older than the configured retention window (keeps transcripts)';

    public function handle(): int
    {
        $days = (int) config('voice.recording_retention_days', 0);

        if ($days <= 0) {
            $this->info('Recording retention is disabled (voice.recording_retention_days = 0).');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        $stale = CallLog::query()
            ->whereNotNull('recording_path')
            ->where('recording_uploaded_at', '<', $cutoff)
            ->get();

        $deleted = 0;
        foreach ($stale as $call) {
            if ($call->recording_path && Storage::exists($call->recording_path)) {
                Storage::delete($call->recording_path);
            }

            // Drop the audio pointer but keep transcript / ai_summary / key points
            // — those are the useful, low-sensitivity record. hasRecording() is
            // false afterward, so the panel's player hides and re-analyse is off.
            $call->update([
                'recording_path' => null,
                'recording_mime' => null,
                'recording_uploaded_at' => null,
            ]);

            $deleted++;
        }

        $this->info(sprintf('Pruned %d call recording(s) older than %d day(s).', $deleted, $days));

        return self::SUCCESS;
    }
}
