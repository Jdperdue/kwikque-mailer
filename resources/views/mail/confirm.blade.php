<!DOCTYPE html>
<html><body style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#111827;">
<p>Please confirm that you want to receive email from {{ $tenantName }}.</p>
<p><a href="{{ $url }}">Confirm my subscription</a></p>
<p style="font-size:12px;color:#6b7280;">If you didn't ask for this, ignore this message and you won't be subscribed.@if ($postalAddress !== '')<br>{!! nl2br(e($postalAddress)) !!}@endif</p>
</body></html>
