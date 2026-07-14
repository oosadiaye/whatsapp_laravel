<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unsubscribed</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#f8fafc; color:#1f2937; margin:0; }
        .wrap { max-width: 480px; margin: 12vh auto; padding: 2.5rem; background:#fff; border:1px solid #e5e7eb; border-radius:16px; box-shadow:0 1px 3px rgba(0,0,0,.06); text-align:center; }
        .badge { width:56px; height:56px; border-radius:50%; background:#ecfdf5; color:#059669; display:grid; place-items:center; margin:0 auto 1rem; }
        h1 { font-size:1.25rem; margin:0 0 .5rem; }
        p { color:#6b7280; font-size:.95rem; line-height:1.5; }
        code { background:#f3f4f6; padding:.1rem .4rem; border-radius:6px; font-size:.85rem; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="badge">
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <h1>You've been unsubscribed</h1>
        @if($email !== '')
            <p><code>{{ $email }}</code> has been removed from our mailing list. You won't receive further marketing emails from us.</p>
        @else
            <p>Your email has been removed from our mailing list.</p>
        @endif
    </div>
</body>
</html>
