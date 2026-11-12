@props(['tenant', 'title' => null])
@php
    $branding = (array) ($tenant->branding ?? []);
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($branding['accent_color'] ?? '')) === 1 ? $branding['accent_color'] : '#1f2937';
    $logo = preg_match('#^https?://#i', (string) ($branding['logo_url'] ?? '')) === 1 ? $branding['logo_url'] : null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? $tenant->name }}</title>
    <style>
        body{margin:0;background:#f7f9fc;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#111827}
        .card{max-width:30rem;margin:2rem auto;background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.12);padding:1.5rem}
        h1{font-size:1.25rem;margin:.5rem 0 1rem} label{display:block;margin:.75rem 0 .25rem;font-size:.9rem}
        input[type=email],input[type=text]{width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #d1d5db;border-radius:4px}
        .check{display:flex;gap:.5rem;align-items:center;margin:.4rem 0}
        button{margin-top:1rem;background:{{ $accent }};color:#fff;border:0;border-radius:4px;padding:.6rem 1.1rem;font-size:1rem;cursor:pointer}
        .muted{color:#6b7280;font-size:.8rem;margin-top:1.25rem} .err{color:#b91c1c;font-size:.85rem} a{color:{{ $accent }}}
        .hp{position:absolute;left:-9999px;height:0;overflow:hidden}
    </style>
</head>
<body>
<div class="card">
    @if ($logo)<img src="{{ $logo }}" alt="{{ $tenant->name }}" style="max-height:60px;max-width:100%">@else<strong>{{ $tenant->name }}</strong>@endif
    {{ $slot }}
    @if (trim((string) $tenant->postal_address) !== '')<div class="muted">{!! nl2br(e($tenant->postal_address)) !!}</div>@endif
</div>
</body>
</html>
