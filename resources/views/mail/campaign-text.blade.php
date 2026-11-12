{!! $tenantName !!}
{!! str_repeat('=', max(3, mb_strlen($tenantName))) !!}
@if ($intro !== '')

{!! $intro !!}
@endif
@foreach ($items as $item)

{!! $item['title'] !!}
@if ($item['starts'] || $item['ends'])
{!! trim(($item['starts'] ?? '').($item['starts'] && $item['ends'] ? ' - ' : '').($item['ends'] ?? '')) !!}
@endif
@if ($item['description'] !== '')
{!! $item['description'] !!}
@endif
@if ($item['url'])
{!! $item['url'] !!}
@endif
@endforeach

--
{!! $fromName !!}
@if ($postalAddress !== '')
{!! $postalAddress !!}
@endif
You are receiving this because you are on our mailing list.
Unsubscribe: {!! $unsubscribeUrl !!}
