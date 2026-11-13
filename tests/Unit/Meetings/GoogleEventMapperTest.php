<?php

declare(strict_types=1);

namespace Tests\Unit\Meetings;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Services\Google\GoogleEventMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GoogleEventMapperTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $over */
    private function event(array $over = []): array
    {
        return array_merge([
            'id' => 'evt1',
            'status' => 'confirmed',
            'summary' => 'Planning',
            'start' => ['dateTime' => '2030-03-01T10:00:00+01:00'],
            'end' => ['dateTime' => '2030-03-01T11:00:00+01:00'],
            'htmlLink' => 'https://www.google.com/calendar/event?eid=abc',
            'organizer' => ['email' => 'boss@corp.test', 'displayName' => 'The Boss'],
        ], $over);
    }

    public function test_converts_times_to_utc_and_basic_fields(): void
    {
        $m = GoogleEventMapper::fromGoogle($this->event(['description' => 'Agenda', 'location' => 'Room 2']));

        $this->assertSame('2030-03-01 09:00:00', $m['starts_at']->toDateTimeString());
        $this->assertSame('2030-03-01 10:00:00', $m['ends_at']->toDateTimeString());
        $this->assertSame('Planning', $m['title']);
        $this->assertSame('Room 2', $m['location']);
        $this->assertSame('The Boss', $m['organizer']);
        $this->assertFalse($m['cancelled']);
    }

    public function test_all_day_events_are_skipped_and_cancelled_ones_are_flagged(): void
    {
        $this->assertNull(GoogleEventMapper::fromGoogle($this->event(['start' => ['date' => '2030-03-01'], 'end' => ['date' => '2030-03-02']])));
        $this->assertNull(GoogleEventMapper::fromGoogle(['summary' => 'no id']));

        $cancelled = GoogleEventMapper::fromGoogle(['id' => 'gone', 'status' => 'cancelled']);
        $this->assertTrue($cancelled['cancelled']);
    }

    public function test_video_link_comes_from_meet_then_teams_or_zoom_in_the_notes(): void
    {
        $meet = GoogleEventMapper::fromGoogle($this->event(['hangoutLink' => 'https://meet.google.com/abc-defg-hij']));
        $this->assertSame('https://meet.google.com/abc-defg-hij', $meet['video_url']);

        $viaEntry = GoogleEventMapper::fromGoogle($this->event(['conferenceData' => ['entryPoints' => [
            ['entryPointType' => 'phone', 'uri' => 'tel:+123'], ['entryPointType' => 'video', 'uri' => 'https://meet.google.com/xyz-xxxx-yyy'],
        ]]]));
        $this->assertSame('https://meet.google.com/xyz-xxxx-yyy', $viaEntry['video_url']);

        $teams = GoogleEventMapper::fromGoogle($this->event(['description' => '<p>Join: <a href="https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc/0?context=%7b%7d">link</a></p>']));
        $this->assertStringStartsWith('https://teams.microsoft.com/l/meetup-join/', $teams['video_url']);
        $this->assertSame('Join: link', $teams['description']);

        $zoom = GoogleEventMapper::fromGoogle($this->event(['location' => 'https://acme.zoom.us/j/123456789']));
        $this->assertSame('https://acme.zoom.us/j/123456789', $zoom['video_url']);

        $none = GoogleEventMapper::fromGoogle($this->event(['description' => 'see http://insecure.example.com/join and https://evil.example.com/teams.microsoft.com/x']));
        $this->assertNull($none['video_url']);
    }

    public function test_guests_exclude_the_organiser_self_and_rooms_and_map_answers(): void
    {
        $m = GoogleEventMapper::fromGoogle($this->event(['attendees' => [
            ['email' => 'boss@corp.test', 'organizer' => true, 'responseStatus' => 'accepted'],
            ['email' => 'me@corp.test', 'self' => true, 'responseStatus' => 'accepted'],
            ['email' => 'room@resource.calendar.google.com', 'resource' => true],
            ['email' => 'Ann@Client.test', 'displayName' => 'Ann', 'responseStatus' => 'accepted'],
            ['email' => 'bob@client.test', 'responseStatus' => 'declined'],
            ['email' => 'cat@client.test', 'responseStatus' => 'tentative'],
            ['email' => 'dan@client.test', 'responseStatus' => 'needsAction'],
        ]]));

        $this->assertSame(['ann@client.test', 'bob@client.test', 'cat@client.test', 'dan@client.test'], array_column($m['attendees'], 'email'));
        $this->assertSame(['accepted', 'declined', 'pending', 'pending'], array_column($m['attendees'], 'response'));
        $this->assertFalse($m['self_declined']);
    }

    public function test_a_declined_by_me_event_is_flagged(): void
    {
        $m = GoogleEventMapper::fromGoogle($this->event(['attendees' => [['email' => 'me@corp.test', 'self' => true, 'responseStatus' => 'declined']]]));

        $this->assertTrue($m['self_declined']);
    }

    public function test_to_google_requests_a_meet_room_only_when_no_link_was_pasted(): void
    {
        $meeting = Meeting::factory()->create(['title' => 'Kickoff']);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'email' => 'a@x.test', 'name' => 'Ann']);

        $withMeet = GoogleEventMapper::toGoogle($meeting->fresh(), true);
        $this->assertSame('hangoutsMeet', $withMeet['conferenceData']['createRequest']['conferenceSolutionKey']['type']);
        $this->assertSame([['email' => 'a@x.test', 'displayName' => 'Ann']], $withMeet['attendees']);
        $this->assertSame('Kickoff', $withMeet['summary']);
        $this->assertFalse($withMeet['guestsCanModify']);

        $meeting->update(['video_url' => 'https://teams.microsoft.com/l/meetup-join/x']);
        $pasted = GoogleEventMapper::toGoogle($meeting->fresh(), true);
        $this->assertArrayNotHasKey('conferenceData', $pasted);
        $this->assertSame('https://teams.microsoft.com/l/meetup-join/x', $pasted['location']);
    }
}
