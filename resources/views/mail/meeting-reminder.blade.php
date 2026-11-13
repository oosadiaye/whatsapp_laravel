<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting reminder</title>
</head>
<body style="margin:0;padding:0;background:#f9fafb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">
                <tr>
                    <td style="padding:24px;">
                        <p style="margin:0 0 4px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;">Starts in {{ $leadLabel }}</p>
                        <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;font-weight:600;">{{ $meeting->title }}</h1>
                        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                            @if($recipientName) Hello {{ $recipientName }}, @endif
                            this is a reminder of your meeting with <strong>{{ $meeting->organizerName() }}</strong>.
                        </p>
                        <p style="margin:0 0 16px;padding:14px 16px;background:#f3f4f6;border-radius:6px;font-size:14px;"><strong>{{ $when }}</strong>
                            @if(filled($meeting->location))<br>{{ $meeting->location }}@endif
                        </p>
                        @if(filled($meeting->video_url))
                            <a href="{{ $meeting->video_url }}" style="display:inline-block;padding:10px 18px;background:#111827;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:500;">Join with {{ $meeting->videoProvider() }}</a>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
