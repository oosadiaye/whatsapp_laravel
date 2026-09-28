<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Card activity</title>
</head>
<body style="margin:0;padding:0;background:#f9fafb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">
                <tr>
                    <td style="padding:24px 24px 0;">
                        <p style="margin:0 0 4px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;">
                            {{ $board?->name ?? 'Task Board' }}
                        </p>
                        <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;font-weight:600;">
                            Card activity
                        </h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 24px 20px;">
                        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                            <strong style="font-weight:600;">{{ $actorName }}</strong>
                            {{ $summary }}:
                            <span style="font-weight:600;">{{ $task->title }}</span>
                        </p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                            <tr>
                                <td style="padding:0;font-size:13px;color:#6b7280;">Status</td>
                                <td align="right" style="padding:0;font-size:13px;color:#111827;font-weight:500;">{{ $statusLabel }}</td>
                            </tr>
                            <tr>
                                <td style="padding:6px 0 0;font-size:13px;color:#6b7280;">Updated</td>
                                <td align="right" style="padding:6px 0 0;font-size:13px;color:#111827;font-weight:500;">{{ $task->updated_at?->toDayDateTimeString() }}</td>
                            </tr>
                        </table>

                        <p style="margin:0;">
                            <a href="{{ $boardUrl }}"
                               style="display:inline-block;padding:10px 18px;background:#111827;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:500;">
                                Open board
                            </a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 24px;border-top:1px solid #e5e7eb;">
                        <p style="margin:0 0 6px;font-size:12px;line-height:1.5;color:#9ca3af;">
                            You are receiving this because you asked for all card activity on cards you are on.
                        </p>
                        <p style="margin:0;font-size:12px;line-height:1.5;color:#9ca3af;">
                            <a href="{{ route('profile.edit') }}" style="color:#6b7280;">Get only the column moves instead</a>
                            from your profile.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
