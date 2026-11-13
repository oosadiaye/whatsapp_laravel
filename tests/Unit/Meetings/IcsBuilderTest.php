<?php

declare(strict_types=1);

namespace Tests\Unit\Meetings;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\User;
use App\Services\Meetings\IcsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class IcsBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function meeting(array $overrides = []): Meeting
    {
        $organizer = User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada@example.com']);

        return Meeting::factory()->create(array_merge([
            'organizer_id' => $organizer->id,
            'title' => 'Plan, review; ship',
            'starts_at' => Carbon::parse('2026-12-01 09:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-12-01 10:30:00', 'UTC'),
            'uid' => 'fixed-uid-1',
            'sequence' => 2,
        ], $overrides));
    }

    public function test_request_contains_identity_times_and_escaped_text(): void
    {
        $ics = IcsBuilder::build($this->meeting());

        $this->assertStringContainsString("METHOD:REQUEST\r\n", $ics);
        $this->assertStringContainsString("UID:fixed-uid-1\r\n", $ics);
        $this->assertStringContainsString("SEQUENCE:2\r\n", $ics);
        $this->assertStringContainsString("DTSTART:20261201T090000Z\r\n", $ics);
        $this->assertStringContainsString("DTEND:20261201T103000Z\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:Plan\\, review\\; ship', $ics);
        $this->assertStringContainsString('ORGANIZER;CN="Ada Obi":mailto:ada@example.com', $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
    }

    public function test_cancel_method_marks_event_cancelled(): void
    {
        $ics = IcsBuilder::build($this->meeting(), IcsBuilder::METHOD_CANCEL);

        $this->assertStringContainsString("METHOD:CANCEL\r\n", $ics);
        $this->assertStringContainsString("STATUS:CANCELLED\r\n", $ics);
    }

    public function test_video_link_goes_into_description_location_and_url(): void
    {
        $ics = IcsBuilder::build($this->meeting(['video_url' => 'https://meet.google.com/abc-defg-hij']));

        $this->assertStringContainsString('URL:https://meet.google.com/abc-defg-hij', $ics);
        $this->assertStringContainsString('LOCATION:https://meet.google.com/abc-defg-hij', $ics);
        $this->assertStringContainsString('Join: https://meet.google.com/abc-defg-hij', str_replace("\r\n ", '', $ics));
    }

    public function test_attendees_are_listed_and_hostile_names_cannot_inject_lines(): void
    {
        $meeting = $this->meeting();
        MeetingAttendee::factory()->create([
            'meeting_id' => $meeting->id,
            'email' => 'bob@example.com',
            'name' => "Bob\r\nATTENDEE:mailto:evil@example.com\"",
        ]);

        $ics = IcsBuilder::build($meeting->fresh());

        $this->assertStringContainsString('mailto:bob@example.com', $ics);
        $this->assertSame(1, preg_match_all('/^ATTENDEE;/m', $ics));
        $this->assertStringNotContainsString("\r\nATTENDEE:mailto:evil", $ics);
    }

    public function test_long_lines_fold_at_75_octets_without_breaking_multibyte_characters(): void
    {
        $ics = IcsBuilder::build($this->meeting(['title' => str_repeat('Réunion équipe ', 12)]));

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'));
        }
        $this->assertStringContainsString("\r\n ", $ics);
    }
}
