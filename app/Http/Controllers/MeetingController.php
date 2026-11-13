<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Services\Meetings\IcsBuilder;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Thin transport for the calendar page (the page itself is the Livewire calendar) and the .ics download. */
class MeetingController extends Controller
{
    public function index(): View
    {
        return view('meetings.index');
    }

    public function ics(Meeting $meeting): Response
    {
        abort_unless($meeting->canBeViewedBy(auth()->user()), 403);

        $method = $meeting->isCancelled() ? IcsBuilder::METHOD_CANCEL : IcsBuilder::METHOD_REQUEST;

        // Managers get the full guest list; an invited colleague gets only their own line.
        $user = auth()->user();
        $only = $meeting->canBeManagedBy($user) ? null : $user->email;

        return response(IcsBuilder::build($meeting, $method, $only), 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="meeting-'.$meeting->id.'.ics"',
        ]);
    }
}
