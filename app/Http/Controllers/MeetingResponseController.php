<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MeetingAttendee;
use App\Services\Meetings\MeetingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public accept / decline page reached from the link in an invitation email. No login: the unguessable
 * per-person token is the credential.
 *
 * GET only *shows* the meeting and asks for confirmation; POST records the answer. Mail scanners and link
 * previewers fetch GET links automatically, so a GET that changed state would answer for the recipient.
 */
class MeetingResponseController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $attendee = $this->attendee($token);
        $answer = $request->query('answer');

        return view('meetings.respond', [
            'attendee' => $attendee,
            'meeting' => $attendee->meeting->loadMissing('organizer'),
            'preselected' => in_array($answer, [MeetingAttendee::RESPONSE_ACCEPTED, MeetingAttendee::RESPONSE_DECLINED], true) ? $answer : null,
            'done' => false,
            'closed' => $this->isClosed($attendee),
            'tz' => (string) config('app.business_timezone'),
        ]);
    }

    public function store(Request $request, string $token, MeetingService $meetings): View
    {
        $attendee = $this->attendee($token);
        $answer = $request->validate([
            'answer' => ['required', 'in:'.MeetingAttendee::RESPONSE_ACCEPTED.','.MeetingAttendee::RESPONSE_DECLINED],
        ])['answer'];

        $recorded = $meetings->respond($attendee, $answer);

        return view('meetings.respond', [
            'attendee' => $attendee->refresh(),
            'meeting' => $attendee->meeting->loadMissing('organizer'),
            'preselected' => null,
            'done' => $recorded,
            'closed' => ! $recorded,
            'tz' => (string) config('app.business_timezone'),
        ]);
    }

    private function attendee(string $token): MeetingAttendee
    {
        // A malformed token is a 404, not a database lookup.
        abort_unless(strlen($token) === 40 && ctype_alnum($token), 404);

        return MeetingAttendee::query()->with('meeting')->where('response_token', $token)->firstOrFail();
    }

    private function isClosed(MeetingAttendee $attendee): bool
    {
        return $attendee->meeting->isCancelled() || $attendee->meeting->ends_at->isPast();
    }
}
