<!DOCTYPE html>
<html><body style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#111827;">
<p>Someone (hopefully you) tried to subscribe this address to email from {{ $tenantName }}. You are already subscribed, so there is nothing more to do.</p>
<p><a href="{{ $preferencesUrl }}">Manage my preferences</a> &middot; <a href="{{ $unsubscribeUrl }}">Unsubscribe</a></p>
<p style="font-size:12px;color:#6b7280;">If you didn't ask for this, you can ignore this message.@if ($postalAddress !== '')<br>{!! nl2br(e($postalAddress)) !!}@endif</p>
</body></html>
