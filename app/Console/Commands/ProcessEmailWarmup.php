<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EmailWarmupLog;
use App\Models\EmailWarmupSetting;
use App\Services\MailClient\OutboundEmail;
use App\Jobs\SendUserEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessEmailWarmup extends Command
{
    protected $signature = 'email:warmup';

    protected $description = 'Send warmup emails from configured accounts to keep sender reputation healthy.';

    public function handle(): int
    {
        $settings = EmailWarmupSetting::query()
            ->where('enabled', true)
            ->whereNotNull('target_email')
            ->with('account')
            ->get();

        $sent = 0;

        foreach ($settings as $setting) {
            if ($setting->account === null || ! $setting->account->is_active) {
                continue;
            }

            $today = now()->startOfDay();
            $alreadySent = $setting->account->warmupLogs()
                ->where('created_at', '>=', $today)
                ->count();

            $remaining = max(0, $setting->daily_target - $alreadySent);

            if ($remaining === 0) {
                continue;
            }

            $toSend = min($remaining, 3);

            $words = ['update', 'check', 'status', 'followup', 'note', 'reminder', 'info', 'request', 'confirm', 'review', 'summary', 'outreach', 'intro', 'feedback', 'proposal'];

            for ($i = 0; $i < $toSend; $i++) {
                shuffle($words);
                $subject = 'Re: '.implode(' ', array_slice($words, 0, random_int(2, 4)));
                $body = 'Hi,
'.PHP_EOL.PHP_EOL.'Just following up on our conversation. '.implode(' ', array_slice($words, 0, random_int(3, 6))).'.'.PHP_EOL.PHP_EOL.'Let me know if you have any questions.'.PHP_EOL.PHP_EOL.'Best regards';

                $outbound = new OutboundEmail(
                    to: [$setting->target_email],
                    subject: $subject,
                    bodyHtml: null,
                    bodyText: $body,
                    cc: [],
                    inReplyTo: null,
                    references: null,
                    threadId: null,
                    attachments: [],
                );

                SendUserEmail::dispatch($setting->account->id, $outbound);

                // The send is queued, not yet delivered — log it as 'queued', not
                // 'sent' (L3). Daily-quota accounting counts all rows for the day,
                // so this stays conservative (a queued-but-failed send still counts
                // against the cap, erring toward under-sending, which is safe for
                // warmup) without falsely claiming a delivery that hasn't happened.
                EmailWarmupLog::create([
                    'email_account_id' => $setting->account->id,
                    'recipient_email' => $setting->target_email,
                    'status' => 'queued',
                    'sent_at' => null,
                ]);

                $sent++;
            }

            $setting->update(['last_warmup_at' => now()]);
        }

        if ($sent > 0) {
            Log::info("Email warmup: sent {$sent} warmup email(s).");
        }

        $this->info("Sent {$sent} warmup email(s).");

        return self::SUCCESS;
    }
}
