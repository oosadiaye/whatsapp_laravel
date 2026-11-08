<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Unsubscribe</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#f8fafc; color:#1f2937; margin:0; }
        .wrap { max-width: 480px; margin: 12vh auto; padding: 2.5rem; background:#fff; border:1px solid #e5e7eb; border-radius:16px; box-shadow:0 1px 3px rgba(0,0,0,.06); text-align:center; }
        h1 { font-size:1.25rem; margin:0 0 .5rem; }
        p { color:#6b7280; font-size:.95rem; line-height:1.5; }
        code { background:#f3f4f6; padding:.1rem .4rem; border-radius:6px; font-size:.85rem; }
        button { margin-top:1rem; background:#111827; color:#fff; border:0; border-radius:10px; padding:.7rem 1.4rem; font-size:.95rem; cursor:pointer; }
        button:hover, button:focus-visible { background:#374151; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Stop these emails?</h1>
        @if($email !== '')
            <p>Confirm to stop all further emails to <code>{{ $email }}</code>.</p>
        @else
            <p>Confirm to stop all further emails to this address.</p>
        @endif
        <form method="POST" action="{{ $action }}">
            <button type="submit">Confirm unsubscribe</button>
        </form>
    </div>
</body>
</html>
