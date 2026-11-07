<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;background:#f4f4f1;font-family:Arial,Helvetica,sans-serif;color:#1c1c1a">
    <div style="max-width:520px;margin:0 auto;padding:24px 16px">
        <div style="background:#ffffff;border:1px solid #e3e3dd;border-radius:12px;padding:24px">
            <p style="margin:0 0 4px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#6b6b63">Lead CRM</p>
            <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3">{{ $headline }}</h1>
            <p style="margin:0 0 4px;font-size:14px"><strong>{{ $lead->name }}</strong>@if($lead->title), {{ $lead->title }}@endif</p>
            @if($lead->company)<p style="margin:0 0 16px;font-size:14px;color:#6b6b63">{{ $lead->company }}</p>@endif
            @if($snippet !== '')
                <blockquote style="margin:0 0 20px;padding:10px 14px;border-left:3px solid #c9c9c0;background:#f8f8f5;font-size:14px;line-height:1.5;white-space:pre-line">{{ $snippet }}</blockquote>
            @endif
            <a href="{{ $url }}" style="display:inline-block;background:#1c1c1a;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;font-size:14px">Open the lead</a>
        </div>
    </div>
</body>
</html>
