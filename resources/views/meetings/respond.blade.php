<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Meeting invitation</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#f8fafc; color:#1f2937; margin:0; }
        .wrap { max-width: 480px; margin: 8vh auto; padding: 2rem 1.5rem; background:#fff; border:1px solid #e5e7eb; border-radius:16px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        h1 { font-size:1.25rem; margin:0 0 .25rem; }
        .muted { color:#4b5563; font-size:.95rem; line-height:1.5; }
        .box { background:#f3f4f6; border-radius:10px; padding:.9rem 1rem; margin:1rem 0; font-size:.95rem; }
        .box strong { display:block; }
        .row { display:flex; gap:.6rem; flex-wrap:wrap; margin-top:1rem; }
        button, .btn { border-radius:10px; padding:.7rem 1.3rem; font-size:.95rem; cursor:pointer; text-decoration:none; border:1px solid #d1d5db; background:#fff; color:#111827; }
        button.accept { background:#15803d; border-color:#15803d; color:#fff; }
        button.decline { background:#fff; }
        button:focus-visible, .btn:focus-visible { outline:3px solid #2563eb; outline-offset:2px; }
        .pre { box-shadow:0 0 0 3px #bbf7d0; }
        .ok { color:#15803d; font-weight:600; }
    </style>
</head>
<body>
<main class="wrap">
    <h1>{{ $meeting->title }}</h1>
    <p class="muted">From {{ $meeting->organizerName() }}</p>

    <div class="box">
        <strong>{{ $meeting->starts_at->clone()->setTimezone($tz)->format('l j F Y, H:i') }} – {{ $meeting->ends_at->clone()->setTimezone($tz)->format('H:i') }}</strong>
        <span class="muted">{{ $tz }}</span>
        @if(filled($meeting->location))<br><span class="muted">{{ $meeting->location }}</span>@endif
    </div>

    @if($meeting->isCancelled())
        <p class="muted">This meeting has been cancelled.</p>
    @elseif($done)
        <p class="ok" role="status">
            {{ $attendee->response === 'accepted' ? 'Thank you — you are marked as coming.' : 'Thank you — you are marked as not coming.' }}
        </p>
        @if($attendee->response === 'accepted' && filled($meeting->video_url))
            <p><a class="btn" href="{{ $meeting->video_url }}" target="_blank" rel="noopener noreferrer">Join with {{ $meeting->videoProvider() }}</a></p>
        @endif
        <p class="muted">You can change your answer from the link in your email until the meeting ends.</p>
    @elseif($closed)
        <p class="muted">This meeting is over, so it can no longer be answered.</p>
    @else
        <p class="muted">Will you come to this meeting?
            @if($attendee->response !== 'pending') You currently answered: <strong>{{ $attendee->response }}</strong>. @endif
        </p>
        <form method="POST" action="{{ route('meetings.respond.store', ['token' => $attendee->response_token]) }}" class="row">
            @csrf
            <button type="submit" name="answer" value="accepted" class="accept {{ $preselected === 'accepted' ? 'pre' : '' }}">Yes, I will come</button>
            <button type="submit" name="answer" value="declined" class="decline {{ $preselected === 'declined' ? 'pre' : '' }}">No, I cannot</button>
        </form>
    @endif
</main>
</body>
</html>
