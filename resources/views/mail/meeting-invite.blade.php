<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $meeting->title }}</title>
</head>
<body style="margin:0;padding:0;background:#f9fafb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">
                <tr>
                    <td style="padding:24px 24px 0;">
                        <p style="margin:0 0 4px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;">
                            @if($kind === 'cancel') Meeting cancelled @elseif($kind === 'update') Meeting updated @else Meeting invitation @endif
                        </p>
                        <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;font-weight:600;">{{ $meeting->title }}</h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 24px 20px;">
                        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                            @if($recipientName) Hello {{ $recipientName }}, @endif
                            @if($kind === 'cancel')
                                <strong>{{ $meeting->organizerName() }}</strong> cancelled this meeting. It has been removed from your calendar if you add the attached file.
                            @elseif($kind === 'update')
                                <strong>{{ $meeting->organizerName() }}</strong> changed this meeting. The details below are the latest ones.
                            @else
                                <strong>{{ $meeting->organizerName() }}</strong> invited you to a meeting.
                            @endif
                        </p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;padding:14px 16px;background:#f3f4f6;border-radius:6px;">
                            <tr>
                                <td style="font-size:13px;color:#6b7280;padding:0 0 4px;">When</td>
                                <td align="right" style="font-size:14px;font-weight:600;padding:0 0 4px;{{ $kind === 'cancel' ? 'text-decoration:line-through;' : '' }}">{{ $when }}</td>
                            </tr>
                            @if(filled($meeting->location))
                                <tr>
                                    <td style="font-size:13px;color:#6b7280;padding:4px 0 0;">Where</td>
                                    <td align="right" style="font-size:14px;padding:4px 0 0;">{{ $meeting->location }}</td>
                                </tr>
                            @endif
                        </table>

                        @if(filled($meeting->description))
                            <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#374151;white-space:pre-line;">{{ \Illuminate\Support\Str::limit($meeting->description, 800) }}</p>
                        @endif

                        @if($kind !== 'cancel' && filled($meeting->video_url))
                            <p style="margin:0 0 20px;">
                                <a href="{{ $meeting->video_url }}" style="display:inline-block;padding:10px 18px;background:#111827;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:500;">
                                    Join with {{ $meeting->videoProvider() }}
                                </a>
                            </p>
                        @endif

                        @if($acceptUrl)
                            <p style="margin:0 0 6px;font-size:13px;color:#6b7280;">Will you come?</p>
                            <p style="margin:0;">
                                <a href="{{ $acceptUrl }}" style="display:inline-block;padding:9px 16px;border:1px solid #16a34a;color:#15803d;text-decoration:none;border-radius:6px;font-size:14px;font-weight:500;">Accept</a>
                                <a href="{{ $declineUrl }}" style="display:inline-block;margin-left:8px;padding:9px 16px;border:1px solid #d1d5db;color:#374151;text-decoration:none;border-radius:6px;font-size:14px;font-weight:500;">Decline</a>
                            </p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 24px;border-top:1px solid #e5e7eb;">
                        <p style="margin:0;font-size:12px;line-height:1.5;color:#9ca3af;">
                            A calendar file (meeting.ics) is attached: open it to add this to Google, Outlook, Yahoo or Apple Calendar.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
