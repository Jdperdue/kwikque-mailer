<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
@if ($preheader !== '')
<span style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $preheader }}</span>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:6px;">
<tr><td style="background:{{ $accent }};padding:20px 24px;color:#ffffff;font-size:20px;font-weight:bold;">
@if ($logoUrl)<img src="{{ $logoUrl }}" alt="{{ $tenantName }}" style="max-height:48px;max-width:100%;border:0;">@else{{ $tenantName }}@endif
</td></tr>
@if ($intro !== '')
<tr><td style="padding:24px 24px 0 24px;font-size:15px;line-height:1.5;">{!! nl2br(e($intro)) !!}</td></tr>
@endif
@foreach ($items as $item)
<tr><td style="padding:24px;border-bottom:1px solid #e5e7eb;">
@if ($item['image_url'])
@if ($item['url'])<a href="{{ $item['url'] }}"><img src="{{ $item['image_url'] }}" alt="{{ $item['title'] }}" width="552" style="display:block;max-width:100%;height:auto;border:0;margin-bottom:12px;"></a>@else<img src="{{ $item['image_url'] }}" alt="{{ $item['title'] }}" width="552" style="display:block;max-width:100%;height:auto;border:0;margin-bottom:12px;">@endif
@endif
<div style="font-size:18px;font-weight:bold;margin-bottom:6px;">
@if ($item['url'])<a href="{{ $item['url'] }}" style="color:{{ $accent }};text-decoration:none;">{{ $item['title'] }}</a>@else{{ $item['title'] }}@endif
</div>
@if ($item['starts'] || $item['ends'])
<div style="font-size:13px;color:#4b5563;margin-bottom:8px;">
@if ($item['starts']){{ $item['starts'] }}@endif
@if ($item['starts'] && $item['ends']) &ndash; @endif
@if ($item['ends']){{ $item['ends'] }}@endif
</div>
@endif
@if ($item['description'] !== '')
<div style="font-size:14px;line-height:1.5;">{!! nl2br(e($item['description'])) !!}</div>
@endif
@if ($item['url'])
<div style="margin-top:12px;"><a href="{{ $item['url'] }}" style="display:inline-block;background:{{ $accent }};color:#ffffff;padding:10px 18px;border-radius:4px;text-decoration:none;font-size:14px;">View details</a></div>
@endif
</td></tr>
@endforeach
<tr><td style="padding:20px 24px;font-size:12px;color:#6b7280;line-height:1.5;">
<div>{{ $fromName }}</div>
@if ($postalAddress !== '')<div>{!! nl2br(e($postalAddress)) !!}</div>@endif
<div style="margin-top:8px;">You are receiving this because you are on our mailing list. <a href="{{ $unsubscribeUrl }}" style="color:#6b7280;">Unsubscribe</a></div>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
