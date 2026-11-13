<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting declined</title>
</head>
<body style="margin:0;padding:0;background:#f9fafb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">
                <tr>
                    <td style="padding:24px;">
                        <h1 style="margin:0 0 12px;font-size:18px;line-height:1.3;font-weight:600;">{{ $personName }} declined</h1>
                        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                            <strong>{{ $personName }}</strong> cannot come to <strong>{{ $meeting->title }}</strong> on {{ $when }}.
                        </p>
                        <a href="{{ $meetingsUrl }}" style="display:inline-block;padding:10px 18px;background:#111827;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:500;">Open the calendar</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
